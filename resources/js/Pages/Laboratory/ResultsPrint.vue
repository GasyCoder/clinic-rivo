<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PaperSheet from '@/Components/Clinical/PaperSheet.vue';
import SealedLabResult from '@/Components/Laboratory/SealedLabResult.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { formatDate, formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';
import { INTERPRETATION_LABELS, resultText } from '@/utilities/labWorkbench';
import { labUrl } from '@/utilities/labUrl';

/**
 * ADR-213 / ADR-214 — la feuille de résultats : seules les analyses rendues
 * s'impriment, et une analyse pas encore envoyée le dit sur la feuille. Elle
 * porte le n° de laboratoire, les prélèvements, le laboratoire extérieur qui a
 * réalisé une analyse qui lui a été confiée, et la conclusion générale.
 *
 * ADR-216 — la même feuille est la page de lecture du médecin
 * (`context.mode = physician`) : seulement ce qui lui a été envoyé, et, adressée
 * à un confrère, rien avant une confirmation tracée (`sealed`).
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    labRequest: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    samples: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
    context: { type: Object, default: () => ({ mode: 'lab' }) },
    sealed: { type: Object, default: null },
    pending: { type: Array, default: () => [] },
});

const physician = computed(() => props.context?.mode === 'physician');
const backHref = computed(() => (physician.value ? props.context.back_href : labUrl(`/laboratory/requests/${props.labRequest.uuid}`)));
const backLabel = computed(() => (physician.value ? props.context.back_label : 'Retour à la saisie'));

const ANTIBIOGRAM_LABELS = { S: 'Sensible', I: 'Intermédiaire', R: 'Résistant' };
const patient = computed(() => props.labRequest.patient);
const samplesText = computed(() => props.samples
    .map((sample) => `${sample.sample_type}${sample.tube ? ` (${sample.tube.code})` : ''} — ${formatDateTime(sample.collected_at)}`)
    .join(' · '));
const rows = (item) => (item.nodes ?? []).filter((node) => !node.takes_result || node.result);
// Un résultat saisi « en un bloc » sur une analyse qui a des sous-analyses n'a
// aucune valeur par ligne : la feuille montre alors le texte saisi plutôt que
// des lignes vides.
const structured = (item) => item.has_definitions && (item.nodes ?? []).some((node) => node.takes_result && node.result);
</script>

<template>
    <!-- ADR-216 — adressés à un confrère : rien n'est servi avant la confirmation. -->
    <div v-if="sealed" class="mx-auto w-full max-w-[52rem] space-y-4">
        <Head :title="`Résultats · ${formatPatientName(patient)}`" />
        <Button v-if="backHref" :as="Link" :href="backHref" size="sm" variant="ghost"><ArrowLeft class="h-4 w-4" /> {{ backLabel }}</Button>
        <Card class="space-y-4 p-5">
            <div>
                <h1 class="text-lg font-bold text-foreground">Résultats d’analyses · {{ formatPatientName(patient) }}</h1>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    {{ patient.patient_number }} · passage {{ labRequest.episode_number }}<template v-if="labRequest.lab_number"> · n° {{ labRequest.lab_number }}</template>
                </p>
            </div>
            <SealedLabResult :seal="sealed" auto-open />
        </Card>
    </div>

    <PaperSheet
        v-else
        :page-title="`Résultats · ${formatPatientName(patient)}`"
        document-title="Résultats d’analyses de laboratoire"
        :back-href="backHref"
        :back-label="backLabel"
    >
        <table class="ps-table">
            <tbody>
                <tr>
                    <th class="ps-label ps-label-blue-soft" style="width: 18%">Patient</th>
                    <td style="width: 32%"><strong>{{ formatPatientName(patient) }}</strong></td>
                    <th class="ps-label ps-label-blue-soft" style="width: 18%">N° dossier</th>
                    <td>{{ patient.patient_number }} · {{ labRequest.episode_number }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Âge / sexe</th>
                    <td>
                        <template v-if="patient.birth_date">{{ formatDate(patient.birth_date) }} — </template>
                        <template v-if="patient.age !== null && patient.age !== undefined">{{ patient.age }} ans</template>
                        <template v-if="patient.sex"> · {{ patient.sex === 'M' ? 'Masculin' : 'Féminin' }}</template>
                    </td>
                    <th class="ps-label ps-label-blue-soft">Demandée le</th>
                    <td>{{ formatDateTime(labRequest.requested_at) }}<template v-if="labRequest.requested_by"> — {{ labRequest.requested_by }}</template></td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">N° laboratoire</th>
                    <td><strong>{{ labRequest.lab_number ?? '—' }}</strong></td>
                    <th class="ps-label ps-label-blue-soft">Reçue le</th>
                    <td>{{ labRequest.received_at ? formatDateTime(labRequest.received_at) : '—' }}</td>
                </tr>
                <tr v-if="samples.length">
                    <th class="ps-label ps-label-blue-soft">Prélèvements</th>
                    <td colspan="3">{{ samplesText }}</td>
                </tr>
                <tr v-if="labRequest.addressed_at">
                    <th class="ps-label ps-label-blue-soft">Adressés à</th>
                    <td colspan="3">
                        {{ labRequest.recipient ?? 'Aucun médecin (patient externe)' }}
                        — envoyés le {{ formatDateTime(labRequest.addressed_at) }}<template v-if="labRequest.addressed_by"> par {{ labRequest.addressed_by }}</template>
                    </td>
                </tr>
            </tbody>
        </table>

        <p v-if="!items.length" class="ps-muted" style="margin-top: 16px">{{ physician ? 'Aucun résultat envoyé par le laboratoire pour l’instant.' : 'Aucune analyse rendue pour cette demande.' }}</p>
        <p v-if="physician && pending.length" class="ps-muted" style="margin-top: 8px">En cours au laboratoire : {{ pending.join(', ') }}.</p>

        <section v-for="item in items" :key="item.uuid" style="break-inside: avoid">
            <table class="ps-table">
                <thead>
                    <tr>
                        <th colspan="4" class="ps-section ps-section-blue">
                            {{ item.name }}
                            <template v-if="item.sent_out"> — réalisée par {{ item.sent_out.laboratory }}<template v-if="item.sent_out.reference"> (réf. {{ item.sent_out.reference }})</template></template>
                        </th>
                    </tr>
                    <tr>
                        <th style="width: 34%; text-align: left">Analyse</th>
                        <th style="width: 30%; text-align: left">Résultat</th>
                        <th style="width: 22%; text-align: left">Valeurs de référence</th>
                        <th style="text-align: left">Interprétation</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-if="structured(item)">
                        <template v-for="node in rows(item)" :key="node.uuid">
                            <tr v-if="!node.takes_result">
                                <td colspan="4" :style="{ paddingLeft: `${8 + node.depth * 14}px`, fontWeight: 700 }">{{ node.designation }}</td>
                            </tr>
                            <tr v-else>
                                <td :style="{ paddingLeft: `${8 + node.depth * 14}px`, fontWeight: node.is_bold ? 700 : 400 }">{{ node.designation }}</td>
                                <td :style="{ fontWeight: node.result.interpretation === 'PATHOLOGICAL' ? 700 : 400 }">
                                    {{ resultText(node, options) }}<template v-if="node.unit && node.entry_mode === 'NUMERIC'"> {{ node.unit }}</template>
                                    <template v-if="node.result.is_critical"> — CRITIQUE<template v-if="node.result.critical_snapshot"> ({{ node.result.critical_snapshot }})</template></template>
                                </td>
                                <td>{{ node.reference ?? '' }}</td>
                                <td>{{ INTERPRETATION_LABELS[node.result.interpretation] ?? '' }}</td>
                            </tr>
                            <tr v-for="antibiogram in node.antibiograms" :key="antibiogram.uuid">
                                <td colspan="4" style="padding: 6px 8px">
                                    <strong>Antibiogramme — <em>{{ antibiogram.bacterium }}</em></strong>
                                    <table class="ps-table" style="margin-top: 4px">
                                        <tbody>
                                            <tr v-for="line in antibiogram.lines" :key="line.antibiotic_uuid">
                                                <td style="width: 55%">{{ line.antibiotic }}</td>
                                                <td style="width: 25%; font-weight: 700">{{ ANTIBIOGRAM_LABELS[line.interpretation] ?? line.interpretation }}</td>
                                                <td>{{ line.measure !== null && line.measure !== undefined ? `${line.measure} ${line.measure_unit}` : '' }}</td>
                                            </tr>
                                            <tr v-if="!antibiogram.lines.length"><td colspan="3" class="ps-muted">Aucun antibiotique testé.</td></tr>
                                        </tbody>
                                    </table>
                                    <p v-if="antibiogram.notes" class="ps-muted" style="margin: 4px 0 0">{{ antibiogram.notes }}</p>
                                </td>
                            </tr>
                        </template>
                    </template>
                    <tr v-else>
                        <td colspan="4" style="white-space: pre-line">{{ item.result_value }}<template v-if="item.result_notes">&#10;{{ item.result_notes }}</template></td>
                    </tr>
                    <tr v-if="item.conclusion">
                        <td colspan="4"><strong>Conclusion : </strong>{{ item.conclusion }}</td>
                    </tr>
                    <tr>
                        <td colspan="4" class="ps-muted">
                            <template v-if="item.in_correction">
                                <strong>Repris par le laboratoire pour être refait<template v-if="item.return_reason"> ({{ item.return_reason }})</template> : ne vous fiez pas à cette valeur, un nouvel envoi suivra.</strong>
                            </template>
                            <template v-else-if="item.status === 'VALIDATED'">Envoyé au médecin<template v-if="item.validated_by"> par {{ item.validated_by }}</template> le {{ formatDateTime(item.validated_at) }}.</template>
                            <template v-else><strong>Résultat pas encore envoyé au médecin.</strong></template>
                            <template v-if="item.resulted_at && !item.in_correction"> Rendu le {{ formatDateTime(item.resulted_at) }}<template v-if="item.resulted_by"> par {{ item.resulted_by }}</template>.</template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <table v-if="labRequest.conclusion" class="ps-table" style="break-inside: avoid">
            <tbody>
                <tr><th class="ps-section ps-section-green">Conclusion générale</th></tr>
                <tr>
                    <td style="white-space: pre-line">
                        {{ labRequest.conclusion }}
                        <template v-if="labRequest.conclusion_by">&#10;— {{ labRequest.conclusion_by }}, le {{ formatDateTime(labRequest.conclusion_at) }}</template>
                    </td>
                </tr>
            </tbody>
        </table>
    </PaperSheet>
</template>
