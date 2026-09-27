<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PaperSheet from '@/Components/Clinical/PaperSheet.vue';
import TreatmentJournalTable from '@/Components/Clinical/TreatmentJournalTable.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { FileDown, Info } from 'lucide-vue-next';
import { formatDateTime } from '@/utilities/date';
import { pdfFileName, printAsPdf } from '@/utilities/pdfDownload';

defineOptions({ layout: AppLayout });

/**
 * ADR-118 — tous les « Dossier médical – Traitement » d'un patient, réunis en
 * un seul document.
 *
 * Chaque feuille est celle que le journal d'un passage sert déjà
 * (`TreatmentJournal`, ADR-116) : la page ne recompose rien et ne permet
 * aucune saisie. Une couverture ouvre le document — identité et liste des
 * passages —, puis vient une feuille par passage qui a au moins une ligne,
 * chacune sur sa page. Un passage sans ligne figure dans la liste, dit « aucune
 * ligne », et n'occupe pas une page blanche.
 *
 * Le PDF est produit par le navigateur (« Enregistrer au format PDF »,
 * ADR-070) : une seule impression donne un seul fichier.
 */
const props = defineProps({
    patient: { type: Object, required: true },
    passages: { type: Array, required: true },
    totals: { type: Object, required: true },
    generated_at: { type: String, required: true },
});

const statusLabels = { OPEN: 'Ouvert', CLOSED: 'Clos', CANCELLED: 'Annulé' };

/** Un passage sans aucune ligne ne mérite pas une page. */
const sheets = computed(() => props.passages.filter((passage) => passage.rows_count > 0));

const fileName = computed(() => pdfFileName('Journaux de traitement', props.patient.patient_number, props.patient.name));
const downloadPdf = () => printAsPdf(fileName.value);

const sexLabel = computed(() => ({ M: 'Masculin', F: 'Féminin' }[props.patient.sex] ?? null));
const ageLabel = computed(() => (props.patient.age !== null && props.patient.age !== undefined
    ? `${props.patient.age} ans${props.patient.birth_date_is_approximate ? ' (âge déclaré)' : ''}`
    : null));
const identityDetails = computed(() => [sexLabel.value, ageLabel.value].filter(Boolean).join(' · ') || 'Non renseigné');

const linesLabel = (count) => (count === 0 ? 'Aucune ligne' : `${count} ligne${count > 1 ? 's' : ''}`);
</script>

<template>
    <div class="pj-doc">
        <!-- Ce qu'il faut savoir avant d'imprimer : à l'écran seulement. -->
        <aside class="pj-hint mx-auto flex w-full max-w-[64rem] items-start gap-3 rounded-xl border border-border bg-card px-4 py-3 text-sm shadow-sm">
            <Info class="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
            <p class="text-muted-foreground">
                <span class="font-semibold text-foreground">Un seul PDF pour tout le dossier.</span>
                {{ totals.with_rows }} passage{{ totals.with_rows > 1 ? 's' : '' }} sur {{ totals.passages }}
                {{ totals.with_rows > 1 ? 'portent' : 'porte' }} des lignes de traitement ({{ totals.rows }} au total),
                chacun sur sa page. Cliquez sur « Télécharger le PDF », puis choisissez
                <span class="font-semibold text-foreground">« Enregistrer au format PDF »</span>
                dans la fenêtre d’impression.
            </p>
        </aside>

        <PaperSheet
            :page-title="`Journaux de traitement — ${patient.name}`"
            document-title="Dossier médical — Journaux de traitement"
            :back-href="`/patients/${patient.uuid}`"
            back-label="Retour au dossier"
            max-width-class="max-w-[64rem]"
        >
            <template #actions>
                <Button type="button" size="sm" @click="downloadPdf">
                    <FileDown class="h-4 w-4" />Télécharger le PDF
                </Button>
            </template>

            <table class="ps-table">
                <tbody>
                    <tr>
                        <th class="ps-strong">N° DE DOSSIER</th>
                        <td>{{ patient.patient_number }}</td>
                    </tr>
                    <tr>
                        <th class="ps-strong">NOM ET PRÉNOM</th>
                        <td>{{ patient.name }}</td>
                    </tr>
                    <tr>
                        <th class="ps-strong">SEXE · ÂGE</th>
                        <td>{{ identityDetails }}</td>
                    </tr>
                </tbody>
            </table>

            <table class="ps-table tjs-grid pj-index">
                <thead>
                    <tr>
                        <th>PASSAGE</th>
                        <th>DÉMARRÉ LE</th>
                        <th>STATUT</th>
                        <th>JOURNAL</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="passage in passages" :key="passage.uuid">
                        <td>
                            <strong>{{ passage.episode_number }}</strong>
                            <span v-if="passage.priority === 'EMERGENCY'" class="ps-muted"> · Urgence</span>
                        </td>
                        <td>{{ formatDateTime(passage.started_at) }}</td>
                        <td>{{ statusLabels[passage.status] ?? passage.status }}</td>
                        <td>{{ linesLabel(passage.rows_count) }}</td>
                    </tr>
                    <tr v-if="passages.length === 0">
                        <td colspan="4" class="tjs-empty">Aucun passage enregistré pour ce patient.</td>
                    </tr>
                </tbody>
            </table>

            <p class="ps-muted pj-generated">Document édité le {{ formatDateTime(generated_at) }}.</p>
        </PaperSheet>

        <PaperSheet
            v-for="passage in sheets"
            :key="passage.uuid"
            :page-title="`Journaux de traitement — ${patient.name}`"
            document-title="Dossier médical — Traitement"
            max-width-class="max-w-[64rem]"
            :show-actions="false"
        >
            <table class="ps-table">
                <tbody>
                    <tr>
                        <th class="ps-strong">N° DE DOSSIER</th>
                        <td>
                            {{ patient.patient_number }}
                            <span class="ps-muted">· Passage {{ passage.episode_number }} · démarré le {{ formatDateTime(passage.started_at) }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <TreatmentJournalTable :rows="passage.rows" />
        </PaperSheet>
    </div>
</template>

<style>
.pj-doc {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.pj-index td:nth-child(n + 2) {
    text-align: center;
}

.pj-generated {
    margin: 12px 0 0;
    text-align: right;
}

@media print {
    .pj-doc {
        display: block;
    }

    /* Une seule impression, un seul PDF : chaque passage commence sa page. */
    .pj-doc > .ps-page + .ps-page {
        break-before: page;
        page-break-before: always;
    }

    .pj-hint {
        display: none !important;
    }
}
</style>
