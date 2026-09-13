<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import FormError from '@/Components/UI/FormError.vue';

/**
 * The footer every wizard step shares, with three deliberately distinct
 * gestures:
 *
 *   Enregistrer            keep the work, stay on the step (IN_PROGRESS)
 *   Enregistrer et continuer  declare the step done and move on (COMPLETED)
 *   Passer cette étape     declare the step unnecessary (SKIPPED)
 *
 * Saving is never the same act as validating: a step is only finished when
 * the doctor says so. A step with no form of its own (paraclinique,
 * diagnostic…) posts directly to the step endpoint instead of emitting.
 */
const props = defineProps({
    orientationUuid: { type: String, required: true },
    stepKey: { type: String, required: true },
    /** Server step state: status, skippable, resolved, blocker, skip_reason. */
    state: { type: Object, default: () => ({}) },
    previous: { type: Object, default: null },
    next: { type: Object, default: null },
    /** Whether this step owns a form the parent submits. */
    hasForm: { type: Boolean, default: false },
    dirty: { type: Boolean, default: false },
    /** Content requirement satisfied — enables "Enregistrer et continuer". */
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
});

const emit = defineEmits(['save', 'save-continue']);

const askSkip = ref(false);
const skipForm = useForm({ step: props.stepKey, intent: 'SKIP', skip_reason: '' });
const completeForm = useForm({ step: props.stepKey, intent: 'COMPLETE' });

const endpoint = computed(() => `/medicine/orientations/${props.orientationUuid}/steps`);
const blocker = computed(() => props.localBlocker ?? props.state?.blocker ?? null);
const isResolved = computed(() => Boolean(props.state?.resolved));
const isSkippable = computed(() => Boolean(props.state?.skippable));

const STATUS_NOTES = {
    COMPLETED: { icon: 'check-circle', text: 'Étape validée', class: 'text-emerald-600 dark:text-emerald-300' },
    SKIPPED: { icon: 'forward-arrow', text: 'Étape déclarée non nécessaire', class: 'text-slate-400' },
    IN_PROGRESS: { icon: 'clock', text: 'Enregistrée, pas encore validée', class: 'text-amber-600 dark:text-amber-300' },
};
const statusNote = computed(() => STATUS_NOTES[props.state?.status] ?? null);

const submitSkip = () => skipForm.post(endpoint.value, {
    preserveScroll: true,
    onSuccess: () => { askSkip.value = false; skipForm.reset('skip_reason'); },
});
const submitComplete = () => completeForm.post(endpoint.value, { preserveScroll: true });
</script>

<template>
    <div class="border-t border-gray-200 bg-gray-50/50 dark:border-gray-900 dark:bg-gray-1000/30">
        <div class="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
            <Button v-if="previous" :as="Link" :href="`/medicine/orientations/${orientationUuid}/${previous.key}`" size="rg" variant="white-outline">
                <Icon class="me-2 text-lg" name="arrow-left" />{{ previous.label }}
            </Button>
            <span v-else />

            <div class="min-w-0 text-center">
                <FormError :message="error" />
                <FormError :message="completeForm.errors.consultation" />
                <FormError :message="skipForm.errors.consultation" />
                <slot name="note">
                    <p v-if="dirty" class="flex items-center justify-center gap-1.5 text-xs font-semibold text-amber-600 dark:text-amber-300">
                        <Icon name="alert-circle" class="text-sm" />Modifications non enregistrées
                    </p>
                    <p v-else-if="statusNote" :class="['flex items-center justify-center gap-1.5 text-xs', statusNote.class]">
                        <Icon :name="statusNote.icon" class="text-sm" />{{ statusNote.text }}
                    </p>
                    <p v-else-if="blocker" class="text-xs text-slate-400">{{ blocker }}</p>
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
                        @click="askSkip = !askSkip"
                    >
                        <Icon class="me-2 text-lg" name="forward-arrow" />Passer cette étape
                    </Button>

                    <Button v-if="hasForm" type="button" size="rg" variant="white-outline" :disabled="processing" @click="emit('save')">
                        <Icon class="me-2 text-lg" name="save" />Enregistrer
                    </Button>

                    <Button
                        v-if="hasForm"
                        type="button"
                        size="rg"
                        :disabled="!canContinue || processing"
                        :title="canContinue ? undefined : (blocker ?? undefined)"
                        @click="emit('save-continue')"
                    >
                        <Icon class="me-2 text-lg" name="check" />{{ processing ? 'Enregistrement…' : 'Enregistrer et continuer' }}
                        <Icon class="ms-2 text-lg" name="arrow-right" />
                    </Button>
                    <Button
                        v-else
                        type="button"
                        size="rg"
                        :disabled="Boolean(blocker) || completeForm.processing"
                        :title="blocker ?? undefined"
                        @click="submitComplete"
                    >
                        <Icon class="me-2 text-lg" name="check" />{{ completeForm.processing ? 'Validation…' : 'Valider et continuer' }}
                        <Icon class="ms-2 text-lg" name="arrow-right" />
                    </Button>
                </template>

                <Button v-else-if="next" :as="Link" :href="`/medicine/orientations/${orientationUuid}/${next.key}`" size="rg">
                    {{ next.label }}<Icon class="ms-2 text-lg" name="arrow-right" />
                </Button>
            </div>
        </div>

        <!-- Why the step was not needed. Optional on purpose: "aucun examen
             complémentaire" is a complete statement, and demanding prose
             would only push the doctor to type filler. -->
        <form v-if="askSkip" class="border-t border-gray-200 px-5 py-3 dark:border-gray-900" @submit.prevent="submitSkip">
            <label :for="`skip-${stepKey}`" class="block text-xs font-bold text-slate-700 dark:text-white">Motif (facultatif)</label>
            <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                <input
                    :id="`skip-${stepKey}`"
                    v-model="skipForm.skip_reason"
                    type="text"
                    maxlength="500"
                    placeholder="Ex. aucun examen complémentaire indiqué"
                    class="block h-9 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white"
                >
                <div class="flex shrink-0 gap-2">
                    <Button type="button" size="rg" variant="white-outline" @click="askSkip = false">Annuler</Button>
                    <Button type="submit" size="rg" variant="secondary" :disabled="skipForm.processing">
                        {{ skipForm.processing ? 'Enregistrement…' : 'Confirmer' }}
                    </Button>
                </div>
            </div>
            <FormError class="mt-1" :message="skipForm.errors.skip_reason" />
        </form>
    </div>
</template>
