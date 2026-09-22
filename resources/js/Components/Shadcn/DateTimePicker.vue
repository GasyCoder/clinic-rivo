<script setup>
import { computed, ref, useAttrs, watch } from 'vue';
import { getLocalTimeZone, today } from '@internationalized/date';
import Button from '@/Components/Shadcn/Button.vue';
import Calendar from '@/Components/Shadcn/Calendar.vue';
import NativeSelect from '@/Components/Shadcn/NativeSelect.vue';
import Popover from '@/Components/Shadcn/Popover.vue';
import { cn } from '@/lib/cn';
import { formatDayLabel, formatDayNumeric, pad, toCalendarDate, triggerClass } from '@/utilities/datePickerField';
import { CalendarDays, Clock, X } from 'lucide-vue-next';

/**
 * ADR-099 — date et heure, à la manière du sélecteur shadcn.
 *
 * Un déclencheur à l'allure d'un champ, puis un panneau compact : le
 * calendrier, une ligne Heure : Minutes en dessous, et les actions. Le panneau
 * n'est pas plus large que le champ qui l'ouvre. La valeur échangée reste celle
 * d'un champ `datetime-local` (« AAAA-MM-JJTHH:mm », heure locale) : le serveur
 * et les formulaires existants n'ont rien à changer.
 *
 * Aucune heure n'est inventée : choisir un jour sans heure ne produit pas de
 * valeur, la ligne Heure se signale. « Maintenant » est un geste explicite de
 * l'utilisateur (date et heure courantes, lues au clic, jamais au rendu). Heure
 * et minutes sont des listes natives (`NativeSelect`) — une liste dans un
 * second portail fermerait le panneau au premier clic.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: String, default: '' },
    id: { type: String, default: undefined },
    /** Nom d'un champ caché, pour un formulaire envoyé tel quel. */
    name: { type: String, default: undefined },
    placeholder: { type: String, default: 'jj/mm/aaaa hh:mm' },
    /** numeric (« 25/09/2026 09:30 », tient partout) · long (« ven. 25 sept. 2026 · 09:30 », champs larges) */
    format: { type: String, default: 'numeric' },
    /** Bornes « AAAA-MM-JJ[THH:mm] » : les jours hors bornes sont grisés ; le serveur valide toujours. */
    min: { type: String, default: undefined },
    max: { type: String, default: undefined },
    minuteStep: { type: Number, default: 5 },
    disabled: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    size: { type: String, default: 'default' },
});
const emit = defineEmits(['update:modelValue']);
const attrs = useAttrs();
const forwarded = computed(() => {
    const { class: _class, ...rest } = attrs;

    return rest;
});
const minValue = computed(() => toCalendarDate(props.min) ?? undefined);
const maxValue = computed(() => toCalendarDate(props.max) ?? undefined);

const PATTERN = /^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})/;

const parsed = computed(() => {
    const match = PATTERN.exec(props.modelValue ?? '');

    return match ? { date: match[1], hour: Number(match[2]), minute: Number(match[3]) } : null;
});

const open = ref(false);
const draftDate = ref(null);
const draftHour = ref(null);
const draftMinute = ref(null);

const syncDraft = () => {
    draftDate.value = parsed.value?.date ?? null;
    draftHour.value = parsed.value?.hour ?? null;
    draftMinute.value = parsed.value?.minute ?? null;
};
syncDraft();
watch(() => props.modelValue, syncDraft);

const hours = Array.from({ length: 24 }, (_, hour) => hour);
const minutes = computed(() => {
    const list = Array.from({ length: Math.ceil(60 / props.minuteStep) }, (_, index) => index * props.minuteStep);
    // Une minute déjà enregistrée hors du pas reste choisissable.
    if (draftMinute.value !== null && !list.includes(draftMinute.value)) list.push(draftMinute.value);

    return list.sort((a, b) => a - b);
});

const hourOptions = hours.map((hour) => ({ value: hour, label: pad(hour) }));
const minuteOptions = computed(() => minutes.value.map((minute) => ({ value: minute, label: pad(minute) })));

const complete = computed(() => draftDate.value !== null && draftHour.value !== null && draftMinute.value !== null);
const emitIfComplete = () => {
    if (complete.value) emit('update:modelValue', `${draftDate.value}T${pad(draftHour.value)}:${pad(draftMinute.value)}`);
};

const calendarValue = computed(() => toCalendarDate(draftDate.value));
const selectDay = (value) => {
    if (!value) return;
    draftDate.value = value.toString();
    emitIfComplete();
};
const selectHour = (hour) => {
    draftHour.value = Number(hour);
    if (draftMinute.value === null) draftMinute.value = 0;
    emitIfComplete();
};
const selectMinute = (minute) => {
    draftMinute.value = Number(minute);
    emitIfComplete();
};
const todayValue = today(getLocalTimeZone());
const todayAllowed = computed(() => (!minValue.value || todayValue.compare(minValue.value) >= 0) && (!maxValue.value || todayValue.compare(maxValue.value) <= 0));
/** Date et heure courantes, à la minute près — lues au clic, jamais au rendu. */
const pickNow = () => {
    const now = new Date();
    draftHour.value = now.getHours();
    draftMinute.value = now.getMinutes();
    selectDay(today(getLocalTimeZone()));
};
const clear = () => {
    draftDate.value = null;
    draftHour.value = null;
    draftMinute.value = null;
    emit('update:modelValue', '');
};

watch(open, (isOpen) => { if (isOpen) syncDraft(); });
/** Un jour choisi sans heure : la ligne Heure le dit. */
const awaitingTime = computed(() => draftDate.value !== null && draftHour.value === null);

const dateLabel = (isoDate) => formatDayLabel(isoDate);
const longLabel = computed(() => (parsed.value
    ? `${dateLabel(parsed.value.date)} · ${pad(parsed.value.hour)}:${pad(parsed.value.minute)}`
    : null));
const label = computed(() => (parsed.value && props.format !== 'long'
    ? `${formatDayNumeric(parsed.value.date)} ${pad(parsed.value.hour)}:${pad(parsed.value.minute)}`
    : longLabel.value));
const draftSummary = computed(() => {
    if (!draftDate.value) return 'Choisissez un jour';
    if (draftHour.value === null) return `${dateLabel(draftDate.value)} · choisissez l’heure`;

    return `${dateLabel(draftDate.value)} · ${pad(draftHour.value)}:${pad(draftMinute.value ?? 0)}`;
});
</script>

<template>
    <Popover v-model:open="open" align="start" :side-offset="4" width-class="w-auto max-w-[calc(100vw-2rem)]">
        <template #trigger>
            <button
                :id="id"
                type="button"
                v-bind="forwarded"
                :disabled="disabled || readonly"
                :aria-invalid="invalid || forwarded['aria-invalid'] || undefined"
                :aria-required="required || undefined"
                :aria-readonly="readonly || undefined"
                :aria-label="forwarded['aria-label'] ?? (longLabel ? `Date et heure : ${longLabel}` : 'Choisir la date et l’heure')"
                :title="longLabel ?? undefined"
                :class="triggerClass({ size, invalid, readonly, extra: attrs.class })"
            >
                <CalendarDays class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <span :class="cn('min-w-0 flex-1 truncate tabular-nums', label ? 'font-medium text-foreground' : 'text-muted-foreground')">{{ label ?? placeholder }}</span>
            </button>
        </template>

        <Calendar :model-value="calendarValue" :min-value="minValue" :max-value="maxValue" caption="dropdown" @update:model-value="selectDay" />

        <!-- Heure : minutes, sous le calendrier : le panneau garde la largeur du champ. -->
        <div :class="cn('flex items-center gap-1.5 border-t border-border px-2.5 py-1.5', awaitingTime && 'bg-primary/5')">
            <Clock :class="cn('h-3.5 w-3.5 shrink-0', awaitingTime ? 'text-primary' : 'text-muted-foreground')" aria-hidden="true" />
            <span :class="cn('flex-1 text-xs font-medium', awaitingTime ? 'text-primary' : 'text-muted-foreground')">{{ awaitingTime ? 'Choisissez l’heure' : 'Heure' }}</span>
            <NativeSelect :model-value="draftHour" :options="hourOptions" placeholder="--" aria-label="Heure" :highlighted="awaitingTime" class="w-[3.25rem]" @update:model-value="selectHour" />
            <span class="text-xs font-semibold text-muted-foreground" aria-hidden="true">:</span>
            <NativeSelect :model-value="draftMinute" :options="minuteOptions" placeholder="--" aria-label="Minutes" class="w-[3.25rem]" @update:model-value="selectMinute" />
        </div>

        <footer class="flex items-center gap-1 border-t border-border px-1.5 py-1.5">
            <Button size="xs" variant="ghost" type="button" :disabled="!todayAllowed" @click="pickNow">Maintenant</Button>
            <span class="flex-1" />
            <Button v-if="modelValue && !required" size="icon-xs" variant="ghost" type="button" aria-label="Effacer la date" title="Effacer" @click="clear"><X class="h-3.5 w-3.5" /></Button>
            <Button size="xs" type="button" :disabled="!complete" @click="open = false">Valider</Button>
        </footer>
        <p class="sr-only" aria-live="polite">{{ draftSummary }}</p>
    </Popover>
    <input v-if="name" type="hidden" :name="name" :value="modelValue ?? ''">
</template>
