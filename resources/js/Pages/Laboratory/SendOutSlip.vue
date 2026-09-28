<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PaperSheet from '@/Components/Clinical/PaperSheet.vue';
import { formatDate, formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

/**
 * ADR-214 — le bon qui accompagne les prélèvements confiés à un laboratoire
 * extérieur : une feuille par laboratoire, avec le patient, les analyses
 * demandées, les tubes joints et la place pour la signature de qui les reçoit.
 * Aucun montant : le Laboratoire n'encaisse rien et ne facture pas (ADR-014).
 */
const props = defineProps({
    labRequest: { type: Object, required: true },
    samples: { type: Array, default: () => [] },
    groups: { type: Array, default: () => [] },
});

const patient = computed(() => props.labRequest.patient);
</script>

<template>
    <div class="space-y-6">
        <div v-for="(group, index) in groups" :key="group.laboratory" :class="index > 0 ? 'send-out-next' : undefined">
            <PaperSheet
                :page-title="`Bon d’envoi · ${labRequest.lab_number ?? formatPatientName(patient)}`"
                document-title="Bon d’envoi au laboratoire extérieur"
                :back-href="`/laboratory/requests/${labRequest.uuid}`"
                back-label="Retour à la demande"
                :show-actions="index === 0"
            >
                <table class="ps-table">
                    <tbody>
                        <tr>
                            <th class="ps-label ps-label-blue-soft" style="width: 20%">Destinataire</th>
                            <td colspan="3"><strong>{{ group.laboratory }}</strong></td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-blue-soft">Patient</th>
                            <td style="width: 32%"><strong>{{ formatPatientName(patient) }}</strong></td>
                            <th class="ps-label ps-label-blue-soft" style="width: 18%">N° laboratoire</th>
                            <td>{{ labRequest.lab_number }}</td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-blue-soft">Âge / sexe</th>
                            <td>
                                <template v-if="patient.birth_date">{{ formatDate(patient.birth_date) }} — </template>
                                <template v-if="patient.age !== null && patient.age !== undefined">{{ patient.age }} ans</template>
                                <template v-if="patient.sex"> · {{ patient.sex === 'M' ? 'Masculin' : 'Féminin' }}</template>
                            </td>
                            <th class="ps-label ps-label-blue-soft">N° dossier</th>
                            <td>{{ patient.patient_number }} · {{ labRequest.episode_number }}</td>
                        </tr>
                        <tr v-if="labRequest.notes">
                            <th class="ps-label ps-label-blue-soft">Renseignements</th>
                            <td colspan="3">{{ labRequest.notes }}</td>
                        </tr>
                    </tbody>
                </table>

                <table class="ps-table">
                    <thead>
                        <tr><th colspan="4" class="ps-section ps-section-blue">Analyses demandées</th></tr>
                        <tr>
                            <th style="width: 40%; text-align: left">Analyse</th>
                            <th style="width: 18%; text-align: left">Code</th>
                            <th style="width: 20%; text-align: left">Réf. du laboratoire</th>
                            <th style="text-align: left">Remarque</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in group.items" :key="item.uuid">
                            <td><strong>{{ item.name }}</strong></td>
                            <td>{{ item.code }}</td>
                            <td>{{ item.reference }}</td>
                            <td>{{ item.notes }}</td>
                        </tr>
                    </tbody>
                </table>

                <table class="ps-table">
                    <thead>
                        <tr><th colspan="3" class="ps-section ps-section-green">Prélèvements joints</th></tr>
                        <tr>
                            <th style="width: 30%; text-align: left">Code-barres</th>
                            <th style="width: 40%; text-align: left">Prélèvement · tube</th>
                            <th style="text-align: left">Prélevé le</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="sample in samples" :key="sample.uuid">
                            <td style="font-family: monospace">{{ sample.barcode }}</td>
                            <td>{{ sample.sample_type }}<template v-if="sample.tube"> · {{ sample.tube.code }}<template v-if="sample.tube.color"> ({{ sample.tube.color }})</template></template></td>
                            <td>{{ formatDateTime(sample.collected_at) }}</td>
                        </tr>
                        <tr v-if="!samples.length"><td colspan="3" class="ps-muted">Aucun prélèvement enregistré.</td></tr>
                    </tbody>
                </table>

                <table class="ps-table">
                    <tbody>
                        <tr>
                            <td style="width: 50%; height: 70px; vertical-align: top">
                                <strong>Envoyé par</strong><br />
                                {{ group.items[0]?.sent_out_by }}<template v-if="group.items[0]?.sent_out_at"> — le {{ formatDateTime(group.items[0].sent_out_at) }}</template>
                            </td>
                            <td style="vertical-align: top"><strong>Reçu par (nom, date, signature)</strong></td>
                        </tr>
                    </tbody>
                </table>
                <p class="ps-muted" style="margin-top: 8px">Merci de retourner les résultats en rappelant le n° de laboratoire {{ labRequest.lab_number }}.</p>
            </PaperSheet>
        </div>
    </div>
</template>

<style>
@media print {
    .send-out-next {
        break-before: page;
    }
}
</style>
