<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Check, CircleCheck, Clock, NotebookPen, Save, SkipForward } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import ClinicalSaveStatus from '@/Components/Clinical/ClinicalSaveStatus.vue';

/**
 * The footer every wizard step shares, with three deliberately distinct
 * gestures:
 *
 *   Enregistrer            keep the work, stay on the step (IN_PROGRESS)
 *   Suivant                declare the step done and move on (COMPLETED)
 *   Passer cette étape     declare the step unnecessary (SKIPPED)
 *
 * Saving is never the same act as validating: a step is only finished when
 * the doctor says so. A step with no form of its own (paraclinique,
 * diagnostic…) posts directly to the step endpoint instead of emitting.
 */
const props = defineProps({
    /** Colle au bas de l'écran. `false` quand le parent fournit déjà sa propre carte flottante. */
    floating: { type: Boolean, default: true },
    orientationUuid: { type: String, required: true },
    stepKey: { type: String, required: true },
    /** Server step state: status, skippable, resolved, blocker, skip_reason. */
    state: { type: Object, default: () => ({}) },
    previous: { type: Object, default: null },
    next: { type: Object, default: null },
    /** Whether this step owns a form the parent submits. */
    hasForm: { type: Boolean, default: false },
    dirty: { type: Boolean, default: false },
    /** Content requirement satisfied — enables « Suivant ». */
    canContinue: { type: Boolean, default: true },
    processing: { type: Boolean, default: false },
    canEdit: { type: Boolean, default: false },
    error: { type: String, default: null },
    /**
     * A reason this step cannot be validated *yet* that only the browser
     * knows — typically a selection the doctor has not submitted. Merged with
     * the server blocker; the server one always stays authoritative.
     */
    localBlocker: { type: String, default: null },
    /**
     * `{ saving, savedAt }` du brouillon serveur (ADR-073). Absent, la barre
     * n'affiche aucun statut : elle ne prétend jamais un enregistrement que
     * personne n'a confirmé.
     */
    saveState: { type: Object, default: null },
    /**
     * `false` quand l'étape porte déjà sa propre façon de se déclarer non
     * nécessaire. La Paraclinique pose la question en tête d'écran
     * (ADR-079), et c'est ce chemin-là qui annule proprement les demandes
     * déjà transmises — le raccourci du pied, lui, n'annulait rien.
     */
    allowSkip: { type: Boolean, default: true },
});

const emit = defineEmits(['save', 'save-continue', 'blocked']);

const skipForm = useForm({ step: props.stepKey, intent: 'SKIP', skip_reason: '' });
const completeForm = useForm({ step: props.stepKey, intent: 'COMPLETE' });

const endpoint = computed(() => `/medicine/orientations/${props.orientationUuid}/steps`);
const blocker = computed(() => props.localBlocker ?? props.state?.blocker ?? null);
const isResolved = computed(() => Boolean(props.state?.resolved));
const isSkippable = computed(() => props.allowSkip && Boolean(props.state?.skippable));

const STATUS_NOTES = {
    COMPLETED: { icon: CircleCheck, text: 'Étape validée', class: 'text-emerald-600 dark:text-emerald-300' },
    SKIPPED: { icon: SkipForward, text: 'Étape déclarée non nécessaire', class: 'text-muted-foreground' },
    IN_PROGRESS: { icon: Clock, text: 'Enregistrée, pas encore validée', class: 'text-amber-600 dark:text-amber-300' },
};
const statusNote = computed(() => STATUS_NOTES[props.state?.status] ?? null);

/**
 * L'action principale nomme sa destination. « Continuer » obligeait le
 * médecin à deviner l'écran suivant, alors que le parcours la connaît.
 */
const continueLabel = computed(() => (props.next?.label
    ? `Suivant : ${props.next.label.toLocaleLowerCase('fr')}`
    : 'Valider l’étape'));

/**
 * « Passer cette étape » agit immédiatement.
 *
 * Elle ouvrait auparavant un champ « Motif (facultatif) » avec Annuler et
 * Confirmer. Confirmer un geste déjà explicite, pour remplir un champ que
 * l'ADR-076 déclare facultatif — « aucun examen complémentaire » est un
 * énoncé complet — ajoutait deux clics sans rien garantir.
 *
 * `skip_reason` reste accepté par le serveur : la question en tête de
 * l'étape Paraclinique (ADR-079) continue d'en fournir un.
 */
const submitSkip = () => skipForm.post(endpoint.value, { preserveScroll: true });
/**
 * Un obstacle local n'éteint plus le bouton : il l'explique.
 *
 * `localBlocker` décrit une saisie encore en préparation dans le navigateur —
 * des lignes d'ordonnance jamais envoyées, par exemple. Griser le bouton
 * laissait le médecin devant une commande muette, sans savoir ce qui manquait.
 * Le clic passe donc, et l'écran répond.
 *
 * L'obstacle venant du serveur (`state.blocker`) continue, lui, de désactiver :
 * c'est une règle métier, pas une saisie à finir.
 */
const submitComplete = () => {
    if (props.localBlocker) {
        emit('blocked', props.localBlocker);

        return;
    }

    completeForm.post(endpoint.value, { preserveScroll: true });
};
</script>

<template>
    <!-- Flottante par défaut : Retour et Continuer restent sous la main
         pendant tout le défilement, comme à l'étape Prescription. Elle colle
         au bas de l'écran tant que sa carte est visible, puis reprend sa
         place en fin de carte — jamais `position: fixed`, qui recouvrirait
         le pied de page. -->
    <div
        :class="floating
            ? 'sticky bottom-3 z-20 m-3 rounded-lg border border-border bg-card shadow-lg'
            : 'border-t border-border bg-muted/35'"
    >
        <div class="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
            <Button v-if="previous" :as="Link" :href="`/medicine/orientations/${orientationUuid}/${previous.key}`" size="rg" variant="white-outline">
                <ArrowLeft class="me-2 h-4 w-4" aria-hidden="true" />{{ previous.label }}
            </Button>
            <span v-else />

            <div class="min-w-0 text-center">
                <ClinicalSaveStatus
                    v-if="saveState"
                    :saving="saveState.saving"
                    :saved-at="saveState.savedAt"
                    :dirty="dirty"
                    :failed="Boolean(error)"
                />
                <FormError :message="error" />
                <!-- `step` est la clé sous laquelle
                     ResolveConsultationStepAction refuse : minimum de l'étape
                     non atteint, ou saut d'une Paraclinique dont une demande
                     est encore active. Elle n'était pas affichée — le clic
                     restait sans effet ni explication. -->
                <FormError :message="completeForm.errors.step" />
                <FormError :message="skipForm.errors.step" />
                <FormError :message="completeForm.errors.consultation" />
                <FormError :message="skipForm.errors.consultation" />
                <slot name="note">
                    <!-- L'état « non enregistré » appartient à
                         ClinicalSaveStatus ci-dessus. Il vivait aussi ici, si
                         bien que « Modifications non enregistrées » s'affichait
                         deux fois. Ne reste que ce qu'il ne dit pas : le statut
                         de l'étape et l'obstacle éventuel. -->
                    <p v-if="!dirty && statusNote" :class="['flex items-center justify-center gap-1.5 text-xs', statusNote.class]">
                        <component :is="statusNote.icon" class="h-3.5 w-3.5" aria-hidden="true" />{{ statusNote.text }}
                    </p>
                    <p v-else-if="blocker" class="text-xs text-muted-foreground">{{ blocker }}</p>
                </slot>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <slot name="actions" />

                <template v-if="canEdit">
                    <Button
                        v-if="isSkippable && !isResolved"
                        type="button"
                        size="rg"
                        variant="white-outline"
                        :disabled="skipForm.processing"
                        @click="submitSkip"
                    >
                        <SkipForward class="me-2 h-4 w-4" aria-hidden="true" />{{ skipForm.processing ? 'Enregistrement…' : 'Passer cette étape' }}
                    </Button>

                    <Button v-if="hasForm" type="button" size="rg" variant="white-outline" :disabled="processing" @click="emit('save')">
                        <Save class="me-2 h-4 w-4" aria-hidden="true" />Enregistrer
                    </Button>

                    <Button
                        v-if="hasForm"
                        type="button"
                        size="rg"
                        :disabled="!canContinue || processing"
                        :title="canContinue ? undefined : (blocker ?? undefined)"
                        @click="emit('save-continue')"
                    >
                        <Check class="me-2 h-4 w-4" aria-hidden="true" />{{ processing ? 'Enregistrement…' : continueLabel }}
                        <ArrowRight class="ms-2 h-4 w-4" aria-hidden="true" />
                    </Button>
                    <!-- Déjà résolue (validée ou déclarée non nécessaire) : il n'y
                         a rien à revalider. Proposer « Valider » ici butait sur le
                         minimum de l'étape — « aucun examen demandé » — alors que
                         le médecin avait justement dit qu'il n'en fallait aucun. -->
                    <Button
                        v-else-if="isResolved && next"
                        :as="Link"
                        :href="`/medicine/orientations/${orientationUuid}/${next.key}`"
                        size="rg"
                    >
                        Continuer · {{ next.label }}<ArrowRight class="ms-2 h-4 w-4" aria-hidden="true" />
                    </Button>
                    <Button
                        v-else
                        type="button"
                        size="rg"
                        :disabled="Boolean(state?.blocker) || completeForm.processing"
                        :title="blocker ?? undefined"
                        @click="submitComplete"
                    >
                        <Check class="me-2 h-4 w-4" aria-hidden="true" />{{ completeForm.processing ? 'Validation…' : 'Valider et continuer' }}
                        <ArrowRight class="ms-2 h-4 w-4" aria-hidden="true" />
                    </Button>
                </template>

                <Button v-else-if="next" :as="Link" :href="`/medicine/orientations/${orientationUuid}/${next.key}`" size="rg">
                    {{ next.label }}<ArrowRight class="ms-2 h-4 w-4" aria-hidden="true" />
                </Button>
            </div>
        </div>

    </div>
</template>
