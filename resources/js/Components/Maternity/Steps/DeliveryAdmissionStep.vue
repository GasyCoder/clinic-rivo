<script setup>
import { Activity } from 'lucide-vue-next';
import MaternityVitalsPanel from '@/Components/Maternity/MaternityVitalsPanel.vue';
import PregnancyLinkSection from '@/Components/Maternity/PregnancyLinkSection.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';

/**
 * ADR-204 — accouchement, étape 1 : l'admission.
 *
 * La patiente arrive le Jour J : sa grossesse (terme, DDR, DPA, facteurs de
 * risque) se lit dans l'en-tête du parcours, ses constantes relevées par les
 * Soins ici — jamais ressaisies. Seuls le motif d'admission et le contexte s'écrivent.
 */
defineProps({
    form: { type: Object, required: true },
    pregnancy: { type: Object, default: null },
    activePregnancies: { type: Array, default: () => [] },
    selectionRequired: { type: Boolean, default: false },
    capabilities: { type: Object, required: true },
    readOnly: { type: Boolean, default: false },
    reference: { type: Object, required: true },
    careRecord: { type: Object, default: null },
    careRecordUrl: { type: String, default: null },
    allergies: { type: Array, default: () => [] },
});
defineEmits(['continue', 'create']);
</script>

<template>
    <div class="space-y-5">
        <PregnancyLinkSection
            :form="form"
            :pregnancy="pregnancy"
            :active-pregnancies="activePregnancies"
            :selection-required="selectionRequired"
            :can-prenatal="Boolean(capabilities.can_prenatal)"
            :read-only="readOnly"
            :reference="reference"
            @continue="$emit('continue', $event)"
            @create="$emit('create')"
        />

        <section class="space-y-2" aria-labelledby="delivery-vitals-title">
            <h3 id="delivery-vitals-title" class="flex items-center gap-2 text-sm font-bold text-foreground"><Activity class="h-4 w-4 text-muted-foreground" aria-hidden="true" />Constantes et allergies à l’admission</h3>
            <MaternityVitalsPanel :care-record="careRecord" :care-record-url="careRecordUrl" :allergies="allergies" />
        </section>

        <FormField as="div" label="Motif d’admission et contexte obstétrical" :error="form.errors.obstetric_context">
            <Textarea v-model="form.obstetric_context" :rows="5" :disabled="readOnly" placeholder="Pourquoi la patiente est admise, ce qu’elle rapporte, le contexte utile…" />
        </FormField>
    </div>
</template>
