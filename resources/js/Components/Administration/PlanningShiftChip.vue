<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Clock, Moon, Sun } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { PERIOD_LABELS, durationMinutes, formatDuration, shiftPeriod, shiftTime, shortName } from '@/utilities/planningCalendar';

/*
 * ADR-194 — un créneau dans le calendrier. La couleur dit le planning
 * (service : bleu ciel, garde : violet), l'icône dit jour, nuit ou 24 h —
 * et le texte le redit : la couleur ne porte jamais seule l'information.
 */
const props = defineProps({
    shift: { type: Object, required: true },
    showName: { type: Boolean, default: false },
    editable: { type: Boolean, default: false },
    class: { type: String, default: '' },
});

const period = computed(() => shiftPeriod(props.shift));
const icon = computed(() => ({ DAY: Sun, NIGHT: Moon, LONG: Clock }[period.value]));
const onCall = computed(() => props.shift.kind === 'ON_CALL');
const label = computed(() => [
    props.shift.kind_label ?? (onCall.value ? 'Garde' : 'Service'),
    PERIOD_LABELS[period.value],
    `${shiftTime(props.shift.starts_at)} → ${shiftTime(props.shift.ends_at)}`,
    `(${formatDuration(durationMinutes(props.shift))})`,
    props.shift.employee?.name,
    props.shift.title,
    props.shift.department,
].filter(Boolean).join(' · '));
const chipClass = computed(() => cn(
    'flex w-full min-w-0 items-center gap-1.5 rounded-md border px-1.5 py-1 text-start text-[11px] font-semibold leading-tight transition',
    onCall.value
        ? 'border-violet-200 bg-violet-50 text-violet-800 dark:border-violet-900 dark:bg-violet-950/40 dark:text-violet-200'
        : 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200',
    props.editable && (onCall.value ? 'hover:border-violet-400' : 'hover:border-sky-400'),
    props.class,
));
</script>

<template>
    <component
        :is="editable ? Link : 'div'"
        :href="editable ? hrUrl(`/administration/planning/${shift.uuid}/edit`) : undefined"
        :class="chipClass"
        :title="label"
        :aria-label="label"
    >
        <component :is="icon" class="h-3 w-3 shrink-0" aria-hidden="true" />
        <span class="shrink-0 tabular-nums">{{ shiftTime(shift.starts_at) }}–{{ shiftTime(shift.ends_at) }}</span>
        <span v-if="showName" class="min-w-0 truncate font-medium opacity-90">{{ shortName(shift.employee?.name) }}</span>
        <span v-else-if="shift.title" class="min-w-0 truncate font-medium opacity-80">{{ shift.title }}</span>
    </component>
</template>
