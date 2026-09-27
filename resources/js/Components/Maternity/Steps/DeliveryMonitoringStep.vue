<script setup>
import { Activity, NotebookPen } from 'lucide-vue-next';
import MaternityVitalsPanel from '@/Components/Maternity/MaternityVitalsPanel.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';

/**
 * ADR-204 — accouchement, étape 3 : la surveillance du travail.
 *
 * Les constantes générales restent celles des Soins (ADR-054) : elles se lisent
 * ici, elles ne se ressaisissent pas. L'étape sert aux observations
 * obstétricales propres au travail, en texte — aucun nouveau modèle de
 * surveillance n'est inventé tant que la clinique n'en a pas défini un.
 */
defineProps({
    form: { type: Object, required: true },
    readOnly: { type: Boolean, default: false },
    careRecord: { type: Object, default: null },
    careRecordUrl: { type: String, default: null },
    allergies: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="space-y-5">
        <section class="space-y-2" aria-labelledby="monitoring-vitals-title">
            <h3 id="monitoring-vitals-title" class="flex items-center gap-2 text-sm font-bold text-foreground"><Activity class="h-4 w-4 text-muted-foreground" aria-hidden="true" />Constantes (fiche de soins)</h3>
            <MaternityVitalsPanel :care-record="careRecord" :care-record-url="careRecordUrl" :allergies="allergies" />
        </section>

        <FormField as="div" label="Surveillance du travail" hint="observations obstétricales" :error="form.errors['labor_data.surveillance_notes']">
            <Textarea v-model="form.labor_data.surveillance_notes" :rows="8" :disabled="readOnly" placeholder="Heure, dilatation, BCF, contractions, état de la patiente…" />
        </FormField>
        <p class="flex items-center gap-2 text-[11px] text-muted-foreground"><NotebookPen class="h-3.5 w-3.5" aria-hidden="true" />Un relevé de constantes se corrige ou se complète sur la fiche de soins.</p>
    </div>
</template>
