<script setup>
import ClinicalRichTextDisplay from '@/Components/Clinical/ClinicalRichTextDisplay.vue';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PaperSheet from '@/Components/Clinical/PaperSheet.vue';
import { formatDate } from '@/utilities/date';

defineOptions({ layout: AppLayout });

/**
 * ADR-116 — le « DOSSIER MÉDICAL » de la clinique.
 *
 * Chaque case reprend ce qui est déjà consigné ailleurs dans le dossier
 * (identité, fiche Soins, séjour, consultation, antécédents) : rien n'est
 * ressaisi ici, et une case que personne n'a remplie reste vide plutôt que
 * « Non » ou « Normal » (ADR-074, ADR-077).
 */
const props = defineProps({
    episode: { type: Object, required: true },
    patient: { type: Object, required: true },
    vitals_visible: { type: Boolean, required: true },
    vitals: { type: Object, default: null },
    history_visible: { type: Boolean, required: true },
    allergies: { type: Array, default: () => [] },
    familial_antecedents: { type: Array, default: () => [] },
    current_treatments: { type: Array, default: () => [] },
    hospitalization: { type: Object, default: null },
    diagnosis: { type: String, default: null },
});

const sexLabel = computed(() => ({ M: 'M', F: 'F' })[props.patient.sex] ?? '');

const birthLabel = computed(() => {
    if (props.patient.birth_date && !props.patient.birth_date_is_approximate) {
        return formatDate(props.patient.birth_date);
    }

    return props.patient.age !== null && props.patient.age !== undefined
        ? `${props.patient.age} ans (âge déclaré)`
        : '';
});

const smokerLabel = computed(() => (props.vitals?.smoker === null || props.vitals?.smoker === undefined
    ? ''
    : (props.vitals.smoker ? 'Oui' : 'Non')));

/** Une permission manquante se nomme ; elle ne se lit jamais comme « rien à signaler ». */
const restricted = (label) => `Non visible avec vos droits (${label})`;
</script>

<template>
    <PaperSheet
        :page-title="`Dossier médical — ${patient.name}`"
        document-title="Dossier médical"
        :back-href="`/passages/${episode.uuid}`"
        back-label="Retour au passage"
    >
        <table class="ps-table">
            <tbody>
                <tr>
                    <th class="ps-strong">N° DE DOSSIER</th>
                    <td colspan="3">{{ patient.patient_number }} <span class="ps-muted">· Passage {{ episode.episode_number }}</span></td>
                </tr>
            </tbody>
        </table>

        <table class="ps-table">
            <tbody>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Nom et Prénom</th>
                    <td colspan="3">{{ patient.name }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Date de Naissance</th>
                    <td>{{ birthLabel }}</td>
                    <th class="ps-label ps-label-blue-soft">Lieu</th>
                    <td>{{ patient.birth_place }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Sexe (M/F)</th>
                    <td colspan="3">{{ sexLabel }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Situation Maritale</th>
                    <td>{{ patient.marital_status }}</td>
                    <th class="ps-label ps-label-blue-soft">Nombre d’enfants</th>
                    <td>{{ patient.children_count }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Profession</th>
                    <td colspan="3">{{ patient.profession }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Adresse</th>
                    <td colspan="3">{{ patient.address }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Téléphone</th>
                    <td colspan="3">{{ patient.phone }}</td>
                </tr>
            </tbody>
        </table>

        <table class="ps-table">
            <tbody v-if="vitals_visible">
                <tr>
                    <th class="ps-label ps-label-green">Groupe sanguin</th>
                    <td colspan="3">{{ vitals?.blood_group }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-green">Taille (cm)</th>
                    <td>{{ vitals?.height_cm }}</td>
                    <th class="ps-label ps-label-green">Poids (Kg)</th>
                    <td>{{ vitals?.weight_kg }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-green">IMC</th>
                    <td colspan="3">{{ vitals?.bmi }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-green">Allergie</th>
                    <td>{{ history_visible ? allergies.join(', ') : restricted('patients.medical_history.view') }}</td>
                    <th class="ps-label ps-label-green">Tabac (Oui/Non)</th>
                    <td>{{ smokerLabel }}</td>
                </tr>
                <tr v-if="hospitalization">
                    <th class="ps-label ps-label-green">Motif d’hospitalisation</th>
                    <td colspan="3">{{ hospitalization.reason }}</td>
                </tr>
                <tr v-if="hospitalization">
                    <th class="ps-label ps-label-green">Entrée hospitalisation</th>
                    <td>{{ hospitalization.admitted_at ? formatDate(hospitalization.admitted_at) : '' }}</td>
                    <th class="ps-label ps-label-green">Sortie</th>
                    <td>{{ hospitalization.discharged_at ? formatDate(hospitalization.discharged_at) : '' }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-green">Diagnostic</th>
                    <td colspan="3">{{ diagnosis }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-green">Motif de transmission</th>
                    <td colspan="3"><ClinicalRichTextDisplay v-if="vitals?.transmission_reason_html" :html="vitals.transmission_reason_html" /></td>
                </tr>
            </tbody>
            <tbody v-else>
                <tr><td colspan="4" class="ps-muted p-2">{{ restricted('vitals.view') }}</td></tr>
            </tbody>
        </table>

        <table class="ps-table mrp-two-col">
            <thead>
                <tr>
                    <th class="ps-section ps-section-yellow">TRAITEMENTS ACTUELS</th>
                    <th class="ps-section ps-section-yellow">ANTÉCÉDENTS FAMILIAUX</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="mrp-freetext">
                        <p v-for="(treatment, index) in current_treatments" :key="index">{{ treatment }}</p>
                    </td>
                    <td class="mrp-freetext">
                        <template v-if="history_visible">
                            <p v-for="(antecedent, index) in familial_antecedents" :key="index">{{ antecedent }}</p>
                        </template>
                        <p v-else class="ps-muted">{{ restricted('patients.medical_history.view') }}</p>
                    </td>
                </tr>
            </tbody>
        </table>
    </PaperSheet>
</template>

<style>
.mrp-two-col th,
.mrp-two-col td {
    width: 50%;
}

.mrp-freetext {
    height: 140px;
    vertical-align: top;
}

.mrp-freetext p {
    margin: 0 0 3px;
}
</style>
