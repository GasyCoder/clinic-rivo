<script setup>
import DateTimePicker from '@/Components/Shadcn/DateTimePicker.vue';
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PaperSheet from '@/Components/Clinical/PaperSheet.vue';
import ClinicalRichTextEditor from '@/Components/Clinical/ClinicalRichTextEditor.vue';
import TreatmentJournalTable from '@/Components/Clinical/TreatmentJournalTable.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Button from '@/Components/Shadcn/Button.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import { Plus } from 'lucide-vue-next';

defineOptions({ layout: AppLayout });

/**
 * ADR-116 — le « DOSSIER MÉDICAL – TRAITEMENT » de la clinique.
 *
 * La chronologie vient de deux sources (`App\Services\Medicine\TreatmentJournal`) :
 * ce qui est déjà consigné ailleurs dans le passage (actes, ordonnances,
 * demandes), et les lignes que Médecine/Soins ajoutent ici pour ce que
 * l'application n'enregistre pas encore. Les deux se lisent sur la même
 * feuille, chronologiquement, sans jamais être recopiées l'une dans
 * l'autre.
 */
const props = defineProps({
    episode: { type: Object, required: true },
    patient: { type: Object, required: true },
    rows: { type: Array, required: true },
    can_record: { type: Boolean, required: true },
});

/** `datetime-local` n'accepte ni fuseau ni secondes. */
const toLocalInput = (date) => new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);

const showForm = ref(false);
const form = useForm({
    occurred_at: toLocalInput(new Date()),
    description: '',
});

const openForm = () => {
    form.reset();
    form.clearErrors();
    form.occurred_at = toLocalInput(new Date());
    showForm.value = true;
};

const submit = () => {
    form.post(`/passages/${props.episode.uuid}/journal`, {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
            form.reset();
        },
    });
};
</script>

<template>
    <Card v-if="can_record" class="tjs-panel p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-heading text-sm font-bold text-foreground">Journal de traitement</h2>
                <p class="text-xs text-muted-foreground">Ajoutez ce que l’application n’enregistre pas déjà (un traitement administré au lit, par exemple).</p>
            </div>
            <Button v-if="!showForm" type="button" size="sm" @click="openForm"><Plus class="h-4 w-4" />Ajouter une ligne</Button>
        </div>

        <form v-if="showForm" class="mt-4 space-y-3 border-t border-border pt-4" @submit.prevent="submit">
            <FormField label="Date et heure" required :error="form.errors.occurred_at" class="max-w-xs">
                <DateTimePicker v-model="form.occurred_at" :max="toLocalInput(new Date())" />
            </FormField>
            <FormField label="Description" required :error="form.errors.description">
                <ClinicalRichTextEditor
                    v-model="form.description"
                    :max-length="3000"
                    min-height-class="min-h-28"
                    toolbar-label="Mise en forme de la ligne de traitement"
                />
            </FormField>
            <div class="flex justify-end gap-2">
                <Button type="button" size="sm" variant="white-outline" @click="showForm = false">Annuler</Button>
                <Button type="submit" size="sm" :disabled="form.processing">Enregistrer</Button>
            </div>
        </form>
    </Card>

    <PaperSheet
        :page-title="`Journal de traitement — ${patient.name}`"
        document-title="Dossier médical — Traitement"
        :back-href="`/passages/${episode.uuid}`"
        back-label="Retour au passage"
        max-width-class="max-w-[64rem]"
    >
        <table class="ps-table">
            <tbody>
                <tr>
                    <th class="ps-strong">N° DE DOSSIER</th>
                    <td>{{ patient.patient_number }} <span class="ps-muted">· Passage {{ episode.episode_number }}</span></td>
                </tr>
            </tbody>
        </table>

        <TreatmentJournalTable :rows="rows" />
    </PaperSheet>
</template>

<style>
.tjs-panel {
    max-width: 64rem;
    margin: 0 auto 1rem;
}

@media print {
    .tjs-panel {
        display: none !important;
    }
}
</style>
