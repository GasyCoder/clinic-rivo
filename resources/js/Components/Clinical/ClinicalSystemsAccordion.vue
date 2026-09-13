<script setup>
import { computed, ref } from 'vue';
import ClinicalSystemExam from '@/Components/Clinical/ClinicalSystemExam.vue';

/**
 * The nine body systems as compact accordions.
 *
 * Only one opens at a time: nine expanded blocks would make the step a wall
 * of textareas, and the doctor examines one system at a time anyway. The
 * closed rows still state their status, so the whole examination is readable
 * without opening anything.
 */
const props = defineProps({
    /** [{ system_code, label, hint, status, findings }] — always all systems. */
    systems: { type: Array, required: true },
    disabled: { type: Boolean, default: false },
    /** Server errors keyed by system_code. */
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update', 'reset']);

/** Anomalies start open: they are the rows that still need something. */
const openSystem = ref(props.systems.find((system) => system.status === 'ABNORMAL')?.system_code ?? null);
const toggle = (code) => { openSystem.value = openSystem.value === code ? null : code; };

const examinedCount = computed(() => props.systems.filter((system) => system.status !== 'NOT_EXAMINED').length);
const abnormalCount = computed(() => props.systems.filter((system) => system.status === 'ABNORMAL').length);

const update = (code, patch) => emit('update', { system_code: code, ...patch });

/**
 * Whether a per-system examination was carried out at all.
 *
 * "Non" means *nothing was examined* — never *everything is normal*. The
 * grid is simply hidden and every system stays NOT_EXAMINED, which is the
 * truth and exactly what the record will show.
 *
 * Opens by itself when systems are already recorded, so re-opening an
 * existing examination never hides the doctor's own work.
 */
const performed = ref(examinedCount.value > 0);

/**
 * What was typed before answering "Non", kept in memory only. An accidental
 * click would otherwise throw away findings the doctor had written; coming
 * back to "Oui" restores them instead of demanding a re-entry. Nothing is
 * persisted until the form is saved.
 */
const stashed = ref(null);

const setPerformed = (value) => {
    if (props.disabled || value === performed.value) return;

    performed.value = value;

    if (!value) {
        stashed.value = props.systems.map((system) => ({ ...system }));
        emit('reset', { restore: null });

        return;
    }

    emit('reset', { restore: stashed.value });
    stashed.value = null;
};
</script>

<template>
    <section class="rounded-lg border border-gray-200 p-4 dark:border-gray-900 sm:p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">Examen par appareil</h3>
            <p v-if="performed" class="text-[11px] text-slate-400">
                {{ examinedCount }}/{{ systems.length }} examiné(s)<template v-if="abnormalCount"> · {{ abnormalCount }} anomalie(s)</template>
            </p>
        </div>

        <div class="mt-2 flex flex-wrap items-center gap-2.5">
            <span class="text-xs font-semibold text-slate-600 dark:text-slate-300">Examen par appareil réalisé ?</span>
            <span class="inline-flex rounded border border-gray-200 bg-white p-0.5 dark:border-gray-800 dark:bg-gray-950" role="radiogroup" aria-label="Un examen par appareil a-t-il été réalisé ?">
                <button
                    type="button"
                    role="radio"
                    :aria-checked="performed"
                    :disabled="disabled"
                    :class="['rounded px-3 py-1 text-xs font-semibold transition-colors', performed ? 'bg-primary-600 text-white' : 'text-slate-500 hover:bg-gray-50 dark:hover:bg-gray-1000']"
                    @click="setPerformed(true)"
                >Oui</button>
                <button
                    type="button"
                    role="radio"
                    :aria-checked="!performed"
                    :disabled="disabled"
                    :class="['rounded px-3 py-1 text-xs font-semibold transition-colors', !performed ? 'bg-gray-200 text-slate-700 dark:bg-gray-800 dark:text-white' : 'text-slate-500 hover:bg-gray-50 dark:hover:bg-gray-1000']"
                    @click="setPerformed(false)"
                >Non</button>
            </span>
        </div>

        <!-- Dit explicitement pour que « Non » ne se lise jamais « tout est
             normal » : aucun appareil n'a été examiné, et c'est ce que le
             dossier montrera. -->
        <p v-if="!performed" class="mt-3 text-[11px] leading-4 text-slate-400">
            Aucun appareil examiné pour cette consultation. Les neuf appareils resteront « Non examiné » — ce n’est pas un examen normal.
        </p>

        <template v-else>
            <p class="mt-2 text-[11px] leading-4 text-slate-400">
                Chaque appareil reste « Non examiné » tant que vous ne l’avez pas renseigné. Une absence de saisie n’est jamais un examen normal.
            </p>

            <div class="mt-3 space-y-1.5">
            <ClinicalSystemExam
                v-for="system in systems"
                :key="system.system_code"
                :label="system.label"
                :system-code="system.system_code"
                :status="system.status"
                :findings="system.findings ?? ''"
                :hint="system.hint"
                :open="openSystem === system.system_code"
                :disabled="disabled"
                :error="errors[system.system_code]"
                @toggle="toggle(system.system_code)"
                @update:status="update(system.system_code, { status: $event })"
                @update:findings="update(system.system_code, { findings: $event })"
            />
            </div>
        </template>
    </section>
</template>
