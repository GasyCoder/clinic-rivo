<script setup>
import { computed, ref } from 'vue';
import { ClipboardList, Pill, ShieldAlert, Stethoscope } from 'lucide-vue-next';
import ClinicalSection from '@/Components/Clinical/ClinicalSection.vue';
import ClinicalSegmentedChoice from '@/Components/Clinical/ClinicalSegmentedChoice.vue';
import ClinicalTreatmentList from '@/Components/Clinical/ClinicalTreatmentList.vue';
import ClinicalReportedInformation from '@/Components/Clinical/ClinicalReportedInformation.vue';
import FormError from '@/Components/UI/FormError.vue';

/**
 * Ce que le dossier sait déjà du patient, à côté de la saisie.
 *
 * Le panneau distingue partout deux registres, et c'est sa raison d'être :
 *
 *   dossier permanent   allergies, antécédents et traitements déjà connus
 *   cette consultation  ce que le patient déclare ou révèle aujourd'hui
 *
 * Les mélanger ferait lire une information rapportée ce matin comme un fait
 * établi du dossier. Porter une information rapportée au dossier permanent
 * reste un second geste explicite, soumis à
 * `patients.medical_history.manage` et revérifié côté serveur (ADR-078) :
 * ce composant ne promeut jamais rien tout seul.
 */
const props = defineProps({
    /** Dossier permanent, en lecture seule ici. */
    allergies: { type: Array, default: () => [] },
    antecedents: { type: Array, default: () => [] },
    familialAntecedents: { type: Array, default: () => [] },
    habitualTreatments: { type: Array, default: () => [] },

    /** Saisie de la consultation en cours. */
    hasCurrentTreatments: { type: Boolean, default: false },
    currentTreatments: { type: Array, default: () => [] },
    knownTreatmentChange: { type: String, default: '' },
    knownTreatmentChangeNotes: { type: String, default: '' },
    reportedAllergies: { type: Array, default: () => [] },
    reportedAntecedents: { type: Array, default: () => [] },
    reportedHabitualTreatments: { type: Array, default: () => [] },

    treatmentChangeOptions: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    treatmentError: { type: Function, default: () => null },
    disabled: { type: Boolean, default: false },
    canPromote: { type: Boolean, default: false },
    textareaClass: { type: String, default: '' },
});

const emit = defineEmits([
    'update:hasCurrentTreatments',
    'update:knownTreatmentChange',
    'update:knownTreatmentChangeNotes',
    'add-treatment', 'remove-treatment', 'update-treatment',
    'add-reported', 'remove-reported', 'update-reported',
]);

/** Une liste d'antécédents peut être longue ; l'écran n'est pas un dossier. */
const ANTECEDENT_PREVIEW = 4;
const showAllAntecedents = ref(false);

const allAntecedents = computed(() => [
    ...props.antecedents.map((item) => ({ ...item, scope: item.type_label ?? 'Personnel' })),
    ...props.familialAntecedents.map((item) => ({ ...item, scope: item.type_label ?? 'Familial' })),
]);
const visibleAntecedents = computed(() => (showAllAntecedents.value
    ? allAntecedents.value
    : allAntecedents.value.slice(0, ANTECEDENT_PREVIEW)));
const hiddenAntecedentCount = computed(() => Math.max(0, allAntecedents.value.length - ANTECEDENT_PREVIEW));

const treatmentSummary = (treatment) => [treatment.dosage, treatment.frequency]
    .filter(Boolean)
    .join(' · ');
</script>

<template>
    <aside class="space-y-5" aria-label="Contexte patient">
        <div>
            <h2 class="text-sm font-bold text-foreground">Contexte patient</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">Informations utiles pour cette consultation.</p>
        </div>

        <!-- TRAITEMENTS ------------------------------------------------- -->
        <ClinicalSection
            title="Traitements actuels"
            hint="Déclarés par le patient. Aucune action sur le stock Pharmacie."
            :icon="Pill"
        >
            <div v-if="habitualTreatments.length" class="rounded-md border border-border bg-muted/30 p-3">
                <p class="text-xs font-semibold text-muted-foreground">Connus du dossier permanent</p>
                <ul class="mt-1.5 space-y-1">
                    <li v-for="treatment in habitualTreatments" :key="treatment.uuid" class="text-xs leading-5 text-muted-foreground">
                        <strong class="font-semibold text-foreground">{{ treatment.medication_name }}</strong>
                        <template v-if="treatmentSummary(treatment)"> · {{ treatmentSummary(treatment) }}</template>
                    </li>
                </ul>

                <div class="mt-3 border-t border-border pt-3">
                    <p class="text-xs font-medium text-foreground">Le patient signale-t-il un changement ?</p>
                    <ClinicalSegmentedChoice
                        class="mt-2"
                        name="known_treatment_change"
                        :model-value="knownTreatmentChange"
                        :options="treatmentChangeOptions"
                        :disabled="disabled"
                        @update:model-value="emit('update:knownTreatmentChange', $event)"
                    />
                    <div v-if="knownTreatmentChange === 'YES'" class="mt-2">
                        <label for="known_treatment_change_notes" class="mb-1 block text-xs font-medium text-foreground">
                            Modification rapportée <span class="text-destructive" aria-hidden="true">*</span>
                        </label>
                        <textarea
                            id="known_treatment_change_notes"
                            :value="knownTreatmentChangeNotes"
                            rows="3"
                            maxlength="2000"
                            :disabled="disabled"
                            :class="textareaClass"
                            placeholder="Ex. le patient indique avoir arrêté l’Amlodipine depuis 2 semaines…"
                            @input="emit('update:knownTreatmentChangeNotes', $event.target.value)"
                        />
                        <FormError :message="errors.known_treatment_change_notes" />
                    </div>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2.5">
                <span class="text-xs font-medium text-foreground">Traitements en cours ?</span>
                <span class="inline-flex rounded-md border border-border bg-card p-0.5" role="radiogroup" aria-label="Le patient a-t-il des traitements en cours ?">
                    <button
                        type="button"
                        role="radio"
                        :aria-checked="hasCurrentTreatments"
                        :disabled="disabled"
                        :class="['rounded px-3 py-1 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40', hasCurrentTreatments ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted']"
                        @click="emit('update:hasCurrentTreatments', true)"
                    >Oui</button>
                    <button
                        type="button"
                        role="radio"
                        :aria-checked="!hasCurrentTreatments"
                        :disabled="disabled"
                        :class="['rounded px-3 py-1 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40', !hasCurrentTreatments ? 'bg-muted text-foreground' : 'text-muted-foreground hover:bg-muted']"
                        @click="emit('update:hasCurrentTreatments', false)"
                    >Non</button>
                </span>
            </div>

            <div v-if="hasCurrentTreatments" class="mt-3">
                <ClinicalTreatmentList
                    :treatments="currentTreatments"
                    :disabled="disabled"
                    :error-for="treatmentError"
                    @add="emit('add-treatment')"
                    @remove="emit('remove-treatment', $event)"
                    @update="emit('update-treatment', $event)"
                />
            </div>
            <p v-else class="mt-3 text-xs leading-5 text-muted-foreground">
                Aucun traitement déclaré par le patient pour cette consultation.
            </p>
        </ClinicalSection>

        <!-- ALLERGIES -------------------------------------------------- -->
        <ClinicalSection title="Allergies" :icon="ShieldAlert">
            <ul v-if="allergies.length" class="space-y-1.5">
                <li
                    v-for="allergy in allergies"
                    :key="allergy.uuid"
                    class="flex items-start gap-2 rounded-md border border-red-200 bg-red-50/60 px-3 py-2 dark:border-red-900 dark:bg-red-950/20"
                >
                    <ShieldAlert class="mt-0.5 h-3.5 w-3.5 shrink-0 text-red-600 dark:text-red-400" aria-hidden="true" />
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-foreground">{{ allergy.substance }}</p>
                        <p v-if="allergy.reaction || allergy.severity" class="text-xs leading-5 text-muted-foreground">
                            {{ [allergy.reaction, allergy.severity].filter(Boolean).join(' · ') }}
                        </p>
                    </div>
                </li>
            </ul>
            <p v-else class="text-xs leading-5 text-muted-foreground">Aucune allergie connue au dossier.</p>
        </ClinicalSection>

        <!-- ANTÉCÉDENTS ------------------------------------------------ -->
        <ClinicalSection title="Antécédents" :icon="Stethoscope">
            <template v-if="hiddenAntecedentCount" #actions>
                <button
                    type="button"
                    class="rounded px-1.5 py-0.5 text-xs font-semibold text-primary transition-colors hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                    :aria-expanded="showAllAntecedents"
                    @click="showAllAntecedents = !showAllAntecedents"
                >
                    {{ showAllAntecedents ? 'Réduire' : `Voir tous (${allAntecedents.length})` }}
                </button>
            </template>

            <ul v-if="allAntecedents.length" class="space-y-1.5">
                <li v-for="antecedent in visibleAntecedents" :key="antecedent.uuid" class="flex items-start gap-2 text-xs leading-5">
                    <span class="mt-0.5 shrink-0 rounded bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground">{{ antecedent.scope }}</span>
                    <span class="min-w-0 text-foreground">{{ antecedent.description }}</span>
                </li>
            </ul>
            <p v-else class="text-xs leading-5 text-muted-foreground">Aucun antécédent enregistré au dossier.</p>
        </ClinicalSection>

        <!-- SIGNALÉ AUJOURD'HUI ---------------------------------------- -->
        <ClinicalSection
            title="Signalé pendant cet entretien"
            hint="Conservé sur la consultation. Le porter au dossier permanent est un geste distinct."
            :icon="ClipboardList"
        >
            <ClinicalReportedInformation
                :allergies="reportedAllergies"
                :antecedents="reportedAntecedents"
                :habitual-treatments="reportedHabitualTreatments"
                :disabled="disabled"
                :can-promote="canPromote"
                :errors="errors"
                @add="emit('add-reported', $event)"
                @remove="emit('remove-reported', $event)"
                @update="emit('update-reported', $event)"
            />
        </ClinicalSection>
    </aside>
</template>
