<script setup>
import { computed, ref, watch } from 'vue';
import {
    CalendarCell,
    CalendarCellTrigger,
    CalendarGrid,
    CalendarGridBody,
    CalendarGridHead,
    CalendarGridRow,
    CalendarHeadCell,
    CalendarHeader,
    CalendarHeading,
    CalendarNext,
    CalendarPrev,
    CalendarRoot,
} from 'reka-ui';
import { getLocalTimeZone, today } from '@internationalized/date';
import NativeSelect from '@/Components/Shadcn/NativeSelect.vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

/**
 * ADR-099 — le calendrier shadcn-vue, sur les primitives `reka-ui`.
 *
 * Accessibilité fournie par la primitive : grille ARIA, flèches pour changer
 * de jour, Page préc./suiv. pour changer de mois, Entrée pour choisir. Semaine
 * commençant le lundi, noms en français. La valeur est une `DateValue` de
 * `@internationalized/date` : une date de calendrier, jamais un instant UTC —
 * aucun décalage de fuseau possible sur le jour choisi.
 *
 * `caption="dropdown"` remplace le titre par deux listes Mois / Année : une date
 * de naissance ne se cherche pas mois par mois. Ce sont des listes natives
 * (`NativeSelect`) — une liste dans un second portail fermerait le panneau au
 * premier clic.
 *
 * Compact : cases de 28 px de haut, 224 px de large en tout — le panneau n'est
 * pas plus large qu'un champ de formulaire.
 */
const props = defineProps({
    modelValue: { type: Object, default: null },
    /** Mois affiché à l'ouverture quand rien n'est choisi. */
    placeholder: { type: Object, default: undefined },
    minValue: { type: Object, default: undefined },
    maxValue: { type: Object, default: undefined },
    /** label · dropdown */
    caption: { type: String, default: 'label' },
});
const emit = defineEmits(['update:modelValue']);

const now = today(getLocalTimeZone());
const displayed = ref(props.modelValue ?? props.placeholder ?? now);
watch(() => props.modelValue, (value) => { if (value) displayed.value = value; });

const capitalize = (text) => text.charAt(0).toUpperCase() + text.slice(1);
const MONTHS = Array.from({ length: 12 }, (_, index) => ({
    value: index + 1,
    label: capitalize(new Intl.DateTimeFormat('fr-FR', { month: 'long' }).format(new Date(2024, index, 1))),
}));
const years = computed(() => {
    const from = props.minValue?.year ?? now.year - 110;
    const to = props.maxValue?.year ?? now.year + 10;
    const list = [];
    for (let year = to; year >= from; year -= 1) list.push(year);
    if (!list.includes(displayed.value.year)) list.push(displayed.value.year);

    return list.sort((a, b) => b - a).map((year) => ({ value: year, label: String(year) }));
});
const setMonth = (month) => { displayed.value = displayed.value.set({ month: Number(month) }); };
const setYear = (year) => { displayed.value = displayed.value.set({ year: Number(year) }); };

const NAV = 'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30 disabled:opacity-40';
/** « lun. » → « Lu » : deux lettres suffisent et gardent la grille étroite. */
const shortDay = (day) => day.replace('.', '').slice(0, 2);
</script>

<template>
    <CalendarRoot
        v-slot="{ grid, weekDays }"
        v-model:placeholder="displayed"
        :model-value="modelValue"
        :min-value="minValue"
        :max-value="maxValue"
        locale="fr-FR"
        :week-starts-on="1"
        weekday-format="short"
        class="p-2"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <CalendarHeader class="flex items-center justify-between gap-0.5 pb-1">
            <CalendarPrev :class="NAV" aria-label="Mois précédent"><ChevronLeft class="h-4 w-4" /></CalendarPrev>
            <div v-if="caption === 'dropdown'" class="flex min-w-0 flex-1 items-center justify-center gap-1">
                <NativeSelect :model-value="displayed.month" :options="MONTHS" aria-label="Mois" class="min-w-0 flex-1" @update:model-value="setMonth" />
                <NativeSelect :model-value="displayed.year" :options="years" aria-label="Année" class="w-[3.75rem] shrink-0" @update:model-value="setYear" />
            </div>
            <CalendarHeading v-else class="text-[13px] font-semibold capitalize text-foreground" />
            <CalendarNext :class="NAV" aria-label="Mois suivant"><ChevronRight class="h-4 w-4" /></CalendarNext>
        </CalendarHeader>

        <CalendarGrid v-for="month in grid" :key="month.value.toString()" class="w-full border-collapse select-none">
            <CalendarGridHead>
                <CalendarGridRow class="flex">
                    <CalendarHeadCell v-for="day in weekDays" :key="day" class="w-8 pb-0.5 text-[10px] font-medium capitalize text-muted-foreground">{{ shortDay(day) }}</CalendarHeadCell>
                </CalendarGridRow>
            </CalendarGridHead>
            <CalendarGridBody>
                <CalendarGridRow v-for="(weekDates, index) in month.rows" :key="`week-${index}`" class="mt-px flex w-full">
                    <CalendarCell v-for="weekDate in weekDates" :key="weekDate.toString()" :date="weekDate" class="relative p-0 text-center">
                        <CalendarCellTrigger
                            :day="weekDate"
                            :month="month.value"
                            class="inline-flex h-7 w-8 items-center justify-center rounded-md text-xs tabular-nums text-foreground outline-none transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring/30 data-[today]:font-bold data-[today]:text-primary data-[outside-view]:text-muted-foreground/45 data-[disabled]:pointer-events-none data-[disabled]:opacity-30 data-[selected]:bg-primary data-[selected]:font-semibold data-[selected]:text-primary-foreground data-[selected]:hover:bg-primary"
                        />
                    </CalendarCell>
                </CalendarGridRow>
            </CalendarGridBody>
        </CalendarGrid>
    </CalendarRoot>
</template>
