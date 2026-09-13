<script setup>
import { ref } from 'vue';
import ClinicalSegmentedChoice from '@/Components/Clinical/ClinicalSegmentedChoice.vue';
import FormError from '@/Components/UI/FormError.vue';
import Input from '@/Components/UI/Input.vue';

/**
 * "État général" — the doctor's overall impression, in three clicks.
 *
 * Carries no vital sign on purpose: blood pressure, heart rate, SpO2,
 * temperature, weight, height and BMI were measured once by Soins and are
 * read from "Contexte clinique" (ADR-054). Asking for them again here would
 * create a second version of a measurement nobody took twice.
 */
const props = defineProps({
    generalCondition: { type: [String, null], default: null },
    consciousnessStatus: { type: [String, null], default: null },
    consciousnessDetails: { type: String, default: '' },
    generalObservation: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits([
    'update:generalCondition',
    'update:consciousnessStatus',
    'update:consciousnessDetails',
    'update:generalObservation',
]);

const CONDITIONS = [
    { value: 'GOOD', label: 'Bon', tone: 'positive' },
    { value: 'FAIR', label: 'Moyen', tone: 'warning' },
    { value: 'ALTERED', label: 'Altéré', tone: 'warning' },
];
const CONSCIOUSNESS = [
    { value: 'NORMAL', label: 'Normale', description: 'Conscient et orienté', tone: 'positive' },
    { value: 'ALTERED', label: 'Altérée', tone: 'warning' },
    { value: 'OTHER', label: 'Autre', tone: 'neutral' },
];

/** The precision only exists for "Autre"; it is never asked otherwise. */
const setConsciousness = (value) => {
    emit('update:consciousnessStatus', value);

    if (value !== 'OTHER') {
        emit('update:consciousnessDetails', '');
    }
};

/**
 * Whether the overall condition was assessed at all — same Oui/Non gate as
 * "Examen par appareil".
 *
 * "Non" means *it was not assessed*, never *the patient looks well*. The
 * block is hidden and the four values go back to empty, which is the truth
 * and exactly what the record will show. A doctor who did not look at the
 * patient's general state must not leave a file that reads "Bon".
 *
 * Opens by itself when something is already recorded, so re-opening an
 * existing examination never hides the doctor's own work.
 */
const assessed = ref(Boolean(
    props.generalCondition
    || props.consciousnessStatus
    || String(props.generalObservation ?? '').trim(),
));

/**
 * What was entered before answering "Non", kept in memory only. An
 * accidental click would otherwise discard it; coming back to "Oui" restores
 * it instead of demanding a re-entry. Nothing is persisted until the form is
 * saved.
 */
const stashed = ref(null);

const setAssessed = (value) => {
    if (props.disabled || value === assessed.value) return;

    assessed.value = value;

    if (!value) {
        stashed.value = {
            generalCondition: props.generalCondition,
            consciousnessStatus: props.consciousnessStatus,
            consciousnessDetails: props.consciousnessDetails,
            generalObservation: props.generalObservation,
        };
        emit('update:generalCondition', null);
        emit('update:consciousnessStatus', null);
        emit('update:consciousnessDetails', '');
        emit('update:generalObservation', '');

        return;
    }

    const restored = stashed.value;
    stashed.value = null;

    if (!restored) return;

    emit('update:generalCondition', restored.generalCondition);
    emit('update:consciousnessStatus', restored.consciousnessStatus);
    emit('update:consciousnessDetails', restored.consciousnessDetails);
    emit('update:generalObservation', restored.generalObservation);
};
</script>

<template>
    <section class="rounded-lg border border-gray-200 p-4 dark:border-gray-900 sm:p-5">
        <h3 class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">État général</h3>

        <div class="mt-2 flex flex-wrap items-center gap-2.5">
            <span class="text-xs font-semibold text-slate-600 dark:text-slate-300">État général évalué ?</span>
            <span class="inline-flex rounded border border-gray-200 bg-white p-0.5 dark:border-gray-800 dark:bg-gray-950" role="radiogroup" aria-label="L’état général a-t-il été évalué ?">
                <button
                    type="button"
                    role="radio"
                    :aria-checked="assessed"
                    :disabled="disabled"
                    :class="['rounded px-3 py-1 text-xs font-semibold transition-colors', assessed ? 'bg-primary-600 text-white' : 'text-slate-500 hover:bg-gray-50 dark:hover:bg-gray-1000']"
                    @click="setAssessed(true)"
                >Oui</button>
                <button
                    type="button"
                    role="radio"
                    :aria-checked="!assessed"
                    :disabled="disabled"
                    :class="['rounded px-3 py-1 text-xs font-semibold transition-colors', !assessed ? 'bg-gray-200 text-slate-700 dark:bg-gray-800 dark:text-white' : 'text-slate-500 hover:bg-gray-50 dark:hover:bg-gray-1000']"
                    @click="setAssessed(false)"
                >Non</button>
            </span>
        </div>

        <!-- Dit explicitement pour que « Non » ne se lise jamais « état
             général normal » : une absence d'évaluation n'est pas un
             constat. -->
        <p v-if="!assessed" class="mt-2.5 text-[11px] leading-4 text-slate-400">
            État général non évalué pour cette consultation. Rien ne sera enregistré — ce n’est pas un état général normal.
        </p>

        <!-- Les deux groupes côte à côte à leur largeur naturelle, séparés
             par un écart fixe : une grille 50/50 laissait un grand vide
             entre trois petits boutons et le groupe suivant. -->
        <div v-if="assessed" class="mt-3 flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:gap-x-10">
            <ClinicalSegmentedChoice
                name="general_condition"
                label="État"
                hint="Aucune valeur par défaut : sélectionnez ce que vous constatez."
                :model-value="generalCondition"
                :options="CONDITIONS"
                :disabled="disabled"
                @update:model-value="emit('update:generalCondition', $event)"
            />

            <div class="min-w-0">
                <ClinicalSegmentedChoice
                    name="consciousness_status"
                    label="Conscience"
                    :model-value="consciousnessStatus"
                    :options="CONSCIOUSNESS"
                    :disabled="disabled"
                    @update:model-value="setConsciousness"
                />
                <div v-if="consciousnessStatus === 'OTHER'" class="mt-2">
                    <label for="consciousness_details" class="mb-1 block text-[11px] font-semibold text-slate-600 dark:text-slate-300">
                        Précisez <span class="text-red-500">*</span>
                    </label>
                    <Input
                        id="consciousness_details"
                        :model-value="consciousnessDetails"
                        :disabled="disabled"
                        maxlength="500"
                        placeholder="Ex. obnubilé, agité, somnolent…"
                        @update:model-value="emit('update:consciousnessDetails', $event)"
                    />
                    <FormError :message="errors.consciousness_details" />
                </div>
            </div>
        </div>

        <div v-if="assessed" class="mt-4">
            <label for="general_observation" class="mb-1 block text-xs font-bold text-slate-700 dark:text-white">
                Observation générale <span class="font-normal text-slate-400">· facultatif</span>
            </label>
            <textarea
                id="general_observation"
                :value="generalObservation"
                :disabled="disabled"
                rows="2"
                maxlength="2000"
                class="block w-full resize-y rounded border border-gray-200 bg-white px-3 py-2 text-sm leading-5 text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-1000"
                placeholder="Impression générale, attitude, état nutritionnel…"
                @input="emit('update:generalObservation', $event.target.value)"
            />
            <FormError :message="errors.general_observation" />
        </div>
    </section>
</template>
