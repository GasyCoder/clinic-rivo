<script setup>
import { computed } from 'vue';
import ClinicalSegmentedChoice from '@/Components/Clinical/ClinicalSegmentedChoice.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';

/**
 * One body system: its three-state status and, for an anomaly, its findings.
 *
 * The default is NOT_EXAMINED and it is never NORMAL. A system reads normal
 * only because the doctor said so — an absence of entry is not a normal
 * examination, and the badge on the closed row states which of the three it
 * is rather than leaving a gap the reader has to interpret.
 */
const props = defineProps({
    label: { type: String, required: true },
    systemCode: { type: String, required: true },
    status: { type: String, default: 'NOT_EXAMINED' },
    findings: { type: String, default: '' },
    hint: { type: String, default: '' },
    open: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    error: { type: String, default: null },
});

const emit = defineEmits(['update:status', 'update:findings', 'toggle']);

const OPTIONS = [
    { value: 'NOT_EXAMINED', label: 'Non examiné' },
    { value: 'NORMAL', label: 'Normal', tone: 'positive' },
    { value: 'ABNORMAL', label: 'Anormal', tone: 'warning' },
];

/** Discreet, never alarming: this is a record, not an alert. */
const BADGES = {
    NORMAL: { text: 'Normal', icon: 'check', class: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300' },
    ABNORMAL: { text: 'Anormal', icon: 'alert-circle', class: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300' },
    NOT_EXAMINED: { text: 'Non examiné', icon: 'minus', class: 'border-gray-200 bg-gray-50 text-slate-500 dark:border-gray-800 dark:bg-gray-1000 dark:text-slate-400' },
};
const badge = computed(() => BADGES[props.status] ?? BADGES.NOT_EXAMINED);
const isAbnormal = computed(() => props.status === 'ABNORMAL');

/**
 * Choosing a status opens the row when findings are now expected, so the
 * doctor never has to click twice to reach a field that just became
 * mandatory.
 */
const setStatus = (value) => {
    const next = value ?? 'NOT_EXAMINED';
    emit('update:status', next);

    if (next === 'ABNORMAL' && !props.open) {
        emit('toggle');
    }
};
</script>

<template>
    <section :class="[
        'overflow-hidden rounded-md border transition-colors',
        open
            ? 'border-primary-200 bg-white shadow-sm dark:border-primary-900 dark:bg-gray-950'
            : 'border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950',
    ]">
        <button
            type="button"
            class="flex w-full items-center gap-3 px-3 py-2.5 text-start sm:px-4"
            :aria-expanded="open"
            :aria-controls="`system-${systemCode}`"
            @click="emit('toggle')"
        >
            <Icon :class="['shrink-0 text-slate-400 transition-transform', open ? 'rotate-180' : '']" name="chevron-down" />
            <strong class="min-w-0 flex-1 truncate text-sm font-bold text-slate-700 dark:text-white">{{ label }}</strong>
            <span :class="['inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-bold', badge.class]">
                <Icon :name="badge.icon" class="text-[10px]" />
                <span class="hidden sm:inline">{{ badge.text }}</span>
            </span>
        </button>

        <div v-show="open" :id="`system-${systemCode}`" class="border-t border-gray-100 px-3 py-3 dark:border-gray-900 sm:px-4">
            <ClinicalSegmentedChoice
                :name="`status_${systemCode}`"
                :model-value="status === 'NOT_EXAMINED' ? null : status"
                :options="OPTIONS.filter((option) => option.value !== 'NOT_EXAMINED')"
                :disabled="disabled"
                @update:model-value="setStatus"
            />

            <div v-if="isAbnormal" class="mt-3">
                <label :for="`findings-${systemCode}`" class="mb-1 block text-xs font-bold text-slate-700 dark:text-white">
                    Constatations <span class="text-red-500">*</span>
                </label>
                <textarea
                    :id="`findings-${systemCode}`"
                    :value="findings"
                    :disabled="disabled"
                    rows="3"
                    maxlength="2000"
                    class="block w-full resize-y rounded border border-gray-200 bg-white px-3 py-2 text-sm leading-5 text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-1000"
                    :placeholder="hint"
                    @input="emit('update:findings', $event.target.value)"
                />
                <FormError :message="error" />
            </div>

            <p v-else-if="status === 'NORMAL'" class="mt-3 text-[11px] leading-4 text-slate-400">
                Examiné et sans anomalie. Aucune description n’est demandée.
            </p>
            <p v-else class="mt-3 text-[11px] leading-4 text-slate-400">
                Non examiné — ce n’est pas un examen normal. Choisissez « Normal » ou « Anormal » si vous l’avez examiné.
            </p>
        </div>
    </section>
</template>
