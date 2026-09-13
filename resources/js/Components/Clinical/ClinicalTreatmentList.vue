<script setup>
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';

/**
 * Treatments the patient declares taking, line by line.
 *
 * Declarative only: no Pharmacy catalogue, no lot, no price — exactly like a
 * manual prescription line (ADR-036/037). A patient may name a medicine
 * bought elsewhere or absent from the référentiel.
 */
defineProps({
    treatments: { type: Array, required: true },
    disabled: { type: Boolean, default: false },
    /** Server errors keyed `current_treatments.<index>.<field>`. */
    errorFor: { type: Function, default: () => null },
    addLabel: { type: String, default: 'Ajouter un traitement' },
});

const emit = defineEmits(['add', 'remove', 'update']);

const patch = (index, field, value) => emit('update', { index, field, value });
</script>

<template>
    <div class="space-y-2">
        <div
            v-for="(treatment, index) in treatments"
            :key="index"
            class="rounded-md border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-950"
        >
            <div class="flex items-start gap-2">
                <div class="min-w-0 flex-1 space-y-2">
                    <div>
                        <Input
                            :model-value="treatment.medication_name"
                            :disabled="disabled"
                            maxlength="255"
                            placeholder="Nom du médicament"
                            :aria-label="`Traitement ${index + 1} — nom`"
                            @update:model-value="patch(index, 'medication_name', $event)"
                        />
                        <FormError :message="errorFor(index, 'medication_name')" />
                    </div>

                    <!-- Dosage, rythme et ancienneté sur une seule ligne :
                         trois champs courts qui se lisent ensemble. -->
                    <div class="grid gap-2 sm:grid-cols-3">
                        <Input
                            :model-value="treatment.dosage"
                            :disabled="disabled"
                            maxlength="150"
                            placeholder="500 mg"
                            :aria-label="`Traitement ${index + 1} — dosage`"
                            @update:model-value="patch(index, 'dosage', $event)"
                        />
                        <Input
                            :model-value="treatment.frequency"
                            :disabled="disabled"
                            maxlength="150"
                            placeholder="2 fois/jour"
                            :aria-label="`Traitement ${index + 1} — fréquence`"
                            @update:model-value="patch(index, 'frequency', $event)"
                        />
                        <Input
                            :model-value="treatment.duration"
                            :disabled="disabled"
                            maxlength="150"
                            placeholder="Depuis 3 jours"
                            :aria-label="`Traitement ${index + 1} — durée`"
                            @update:model-value="patch(index, 'duration', $event)"
                        />
                    </div>

                    <Input
                        :model-value="treatment.notes"
                        :disabled="disabled"
                        maxlength="500"
                        placeholder="Remarque (facultatif)"
                        :aria-label="`Traitement ${index + 1} — remarque`"
                        @update:model-value="patch(index, 'notes', $event)"
                    />
                </div>

                <button
                    v-if="!disabled"
                    type="button"
                    class="mt-1 flex size-7 shrink-0 items-center justify-center rounded border border-gray-200 text-slate-400 transition-colors hover:border-red-200 hover:bg-red-50 hover:text-red-600 dark:border-gray-800 dark:hover:border-red-900 dark:hover:bg-red-950/30"
                    :aria-label="`Retirer le traitement ${index + 1}`"
                    @click="emit('remove', index)"
                >
                    <Icon name="trash" class="text-sm" />
                </button>
            </div>
        </div>

        <Button v-if="!disabled" type="button" size="sm" variant="white-outline" class="w-full" @click="emit('add')">
            <Icon class="me-1.5 text-sm" name="plus" />{{ addLabel }}
        </Button>
    </div>
</template>
