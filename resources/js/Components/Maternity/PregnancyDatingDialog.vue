<script setup>
import { Save } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';

/**
 * Corriger la datation de la grossesse (ADR-201) : un geste audité, distinct de
 * l'enregistrement du dossier. Les termes déjà figés sur les consultations
 * précédentes ne sont pas réécrits.
 */
defineProps({
    open: { type: Boolean, default: false },
    /** `useForm({ dating_method, last_menstrual_period, estimated_due_date, reason })`, tenu par la page. */
    form: { type: Object, required: true },
});
defineEmits(['update:open', 'save']);

const METHODS = [
    { value: 'LMP', label: 'Dernières règles' },
    { value: 'ULTRASOUND', label: 'Échographie' },
    { value: 'MANUAL_CORRECTION', label: 'Correction manuelle' },
];
</script>

<template>
    <Dialog
        :open="open"
        title="Corriger la datation de la grossesse"
        description="Cette correction est auditée. Les snapshots de terme déjà enregistrés sur les consultations précédentes ne sont pas réécrits."
        :dismissible="false"
        @update:open="$emit('update:open', $event)"
    >
        <div class="space-y-4">
            <FormField label="Méthode de datation" required :error="form.errors.dating_method">
                <Select v-model="form.dating_method" class="h-10 w-full" :options="METHODS" />
            </FormField>
            <FormField v-if="form.dating_method === 'LMP'" label="Dernières règles" required :error="form.errors.last_menstrual_period">
                <DatePicker v-model="form.last_menstrual_period" />
                <p class="mt-1 text-xs text-muted-foreground">La DPA sera recalculée côté Laravel depuis cette DDR.</p>
            </FormField>
            <template v-else>
                <FormField label="Dernières règles" hint="Facultatif" :error="form.errors.last_menstrual_period">
                    <DatePicker v-model="form.last_menstrual_period" />
                </FormField>
                <FormField label="DPA retenue" required :error="form.errors.estimated_due_date">
                    <DatePicker v-model="form.estimated_due_date" />
                </FormField>
            </template>
            <FormField label="Motif de la correction" hint="Facultatif" :error="form.errors.reason">
                <Textarea v-model="form.reason" :rows="3" maxlength="1000" />
            </FormField>
            <FormError v-if="form.errors.pregnancy">{{ form.errors.pregnancy }}</FormError>
        </div>
        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="$emit('update:open', false)">Annuler</Button>
            <Button type="button" variant="primary" :disabled="form.processing" @click="$emit('save')">
                <Save class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : 'Enregistrer la correction' }}
            </Button>
        </template>
    </Dialog>
</template>
