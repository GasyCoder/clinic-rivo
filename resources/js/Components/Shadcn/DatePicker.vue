<script setup>
import { computed, ref, useAttrs } from 'vue';
import { getLocalTimeZone, today } from '@internationalized/date';
import Button from '@/Components/Shadcn/Button.vue';
import Calendar from '@/Components/Shadcn/Calendar.vue';
import Popover from '@/Components/Shadcn/Popover.vue';
import { cn } from '@/lib/cn';
import { formatDayLabel, formatDayNumeric, toCalendarDate, triggerClass } from '@/utilities/datePickerField';
import { CalendarDays, X } from 'lucide-vue-next';

/**
 * ADR-099 — une date seule, à la manière du sélecteur shadcn.
 *
 * Un déclencheur qui affiche la date en toutes lettres, un calendrier avec les
 * listes Mois / Année (une date de naissance ne se cherche pas mois par mois).
 * Choisir un jour ferme le panneau. La valeur échangée reste celle d'un champ
 * date natif (« AAAA-MM-JJ ») : serveurs et formulaires n'ont rien
 * à changer. `min` / `max` grisent les jours hors bornes ; le serveur valide
 * toujours.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: String, default: '' },
    id: { type: String, default: undefined },
    /** Nom d'un champ caché, pour un formulaire envoyé tel quel. */
    name: { type: String, default: undefined },
    placeholder: { type: String, default: 'jj/mm/aaaa' },
    /** numeric (« 25/09/2026 », tient partout) · long (« ven. 25 sept. 2026 », champs larges) */
    format: { type: String, default: 'numeric' },
    min: { type: String, default: undefined },
    max: { type: String, default: undefined },
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

const open = ref(false);
const value = computed(() => toCalendarDate(props.modelValue));
const minValue = computed(() => toCalendarDate(props.min) ?? undefined);
const maxValue = computed(() => toCalendarDate(props.max) ?? undefined);
const longLabel = computed(() => (value.value ? formatDayLabel(value.value.toString()) : null));
const label = computed(() => (value.value && props.format !== 'long' ? formatDayNumeric(value.value.toString()) : longLabel.value));

const inBounds = (date) => (!minValue.value || date.compare(minValue.value) >= 0) && (!maxValue.value || date.compare(maxValue.value) <= 0);
const todayValue = today(getLocalTimeZone());

const select = (date) => {
    if (!date) return;
    emit('update:modelValue', date.toString());
    open.value = false;
};
const clear = () => {
    emit('update:modelValue', '');
    open.value = false;
};
</script>

<template>
    <Popover v-model:open="open" align="start" :side-offset="6" width-class="w-auto max-w-[calc(100vw-2rem)]">
        <template #trigger>
            <button
                :id="id"
                type="button"
                v-bind="forwarded"
                :disabled="disabled || readonly"
                :aria-invalid="invalid || forwarded['aria-invalid'] || undefined"
                :aria-required="required || undefined"
                :aria-readonly="readonly || undefined"
                :aria-label="forwarded['aria-label'] ?? (longLabel ? `Date : ${longLabel}` : 'Choisir une date')"
                :title="longLabel ?? undefined"
                :class="triggerClass({ size, invalid, readonly, extra: attrs.class })"
            >
                <CalendarDays class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <span :class="cn('min-w-0 flex-1 truncate', label ? 'text-foreground' : 'text-muted-foreground')">{{ label ?? placeholder }}</span>
            </button>
        </template>

        <Calendar :model-value="value" :min-value="minValue" :max-value="maxValue" caption="dropdown" @update:model-value="select" />

        <footer class="flex items-center gap-2 border-t border-border px-2 py-1.5">
            <Button size="xs" variant="ghost" type="button" :disabled="!inBounds(todayValue)" @click="select(todayValue)">Aujourd’hui</Button>
            <span class="flex-1" />
            <Button v-if="modelValue && !required" size="xs" variant="ghost" type="button" @click="clear"><X class="h-3 w-3" />Effacer</Button>
        </footer>
    </Popover>
    <input v-if="name" type="hidden" :name="name" :value="modelValue ?? ''">
</template>
