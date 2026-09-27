<script setup>
import { computed } from 'vue';
import ClinicalFieldHints from '@/Components/Clinical/ClinicalFieldHints.vue';
import DateTimePicker from '@/Components/Shadcn/DateTimePicker.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { dilationHints, futureDateHints, laborTimingHints } from '@/utilities/maternityChecks';

/**
 * ADR-204 — accouchement, étape 2 : le travail. Les mêmes données
 * (`labor_data`) et les mêmes repères qu'avant (ADR-137) ; rien n'est exigé.
 */
const props = defineProps({
    form: { type: Object, required: true },
    reference: { type: Object, required: true },
    readOnly: { type: Boolean, default: false },
});

const MEMBRANES = [
    { value: 'UNKNOWN', label: 'Non précisé' },
    { value: 'INTACT', label: 'Intactes' },
    { value: 'RUPTURED', label: 'Rompues' },
];
const startHints = computed(() => [
    ...futureDateHints(props.form.labor_data.started_at, 'Début du travail'),
    ...laborTimingHints(props.form.labor_data.started_at, props.form.delivery_data.occurred_at),
]);
const dilation = computed(() => dilationHints(props.form.labor_data.cervical_dilation_cm, props.reference));
</script>

<template>
    <fieldset class="space-y-5" :disabled="readOnly">
        <div class="grid gap-4 md:grid-cols-3">
            <FormField label="Début du travail" :error="form.errors['labor_data.started_at']">
                <DateTimePicker v-model="form.labor_data.started_at" />
                <ClinicalFieldHints :hints="startHints" label="Repères sur le début du travail" />
            </FormField>
            <FormField label="Membranes" :error="form.errors['labor_data.membranes_status']">
                <Select v-model="form.labor_data.membranes_status" class="h-10 w-full min-w-0" :options="MEMBRANES" />
            </FormField>
            <FormField label="Dilatation (cm)" :error="form.errors['labor_data.cervical_dilation_cm']">
                <Input v-model="form.labor_data.cervical_dilation_cm" type="number" min="0" :max="reference.dilation.max" step="0.1" inputmode="decimal" />
                <ClinicalFieldHints :hints="dilation" label="Repères sur la dilatation" />
            </FormField>
        </div>
        <FormField as="div" label="Contractions" :error="form.errors['labor_data.contractions']">
            <Textarea v-model="form.labor_data.contractions" :rows="3" placeholder="Fréquence, durée, intensité…" />
        </FormField>
    </fieldset>
</template>
