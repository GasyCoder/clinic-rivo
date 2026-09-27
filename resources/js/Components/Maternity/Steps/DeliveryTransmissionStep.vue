<script setup>
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { cn } from '@/lib/cn';

/**
 * ADR-204 — accouchement, étape 6 : la transmission.
 *
 * Les soins de la mère restent une seule note (il n'y a qu'une mère) ; ceux de
 * chaque bébé se notent sur sa fiche (ADR-139). Une ancienne note « Soins bébé »
 * commune est conservée telle quelle, jamais effacée. Les actes réalisés et le
 * matériel utilisé se déclarent ici (`#acts`).
 */
defineProps({
    form: { type: Object, required: true },
    readOnly: { type: Boolean, default: false },
    /** Une note « Soins bébé » d'avant la saisie par nouveau-né. */
    legacyBabyCare: { type: Boolean, default: false },
});
</script>

<template>
    <div class="space-y-5">
        <fieldset class="space-y-4" :disabled="readOnly">
            <div :class="cn('grid gap-4', legacyBabyCare && 'lg:grid-cols-2')">
                <FormField as="div" label="Soins mère" :error="form.errors.maternal_care_notes">
                    <Textarea v-model="form.maternal_care_notes" :rows="4" />
                </FormField>
                <FormField v-if="legacyBabyCare" as="div" label="Soins bébé — note générale" hint="Saisie avant les soins par nouveau-né" :error="form.errors.baby_care_notes">
                    <Textarea v-model="form.baby_care_notes" :rows="4" />
                </FormField>
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                <FormField as="div" label="Observations" :error="form.errors.observations">
                    <Textarea v-model="form.observations" :rows="5" />
                </FormField>
                <FormField as="div" label="Transmission / suites" :error="form.errors.transmission_notes">
                    <Textarea v-model="form.transmission_notes" :rows="5" />
                </FormField>
            </div>
        </fieldset>

        <slot name="acts" />
    </div>
</template>
