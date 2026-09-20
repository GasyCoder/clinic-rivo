<script setup>
import { computed, onMounted, ref } from 'vue';
import QRCode from 'qrcode';
import { formatDate, formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';

/**
 * ADR-116 — le corps de la « FICHE DE SORTIE » de la clinique.
 *
 * Le papier ne connaissait qu'une seule date de sortie et un seul jeu de
 * cases (sortie normale / transfert / sur sa demande / décédé), signées par
 * la Caisse. Le dossier informatisé distingue deux faits que le papier
 * confondait — la sortie médicale (ADR-035) et la sortie administrative
 * (ADR-090) — et cette feuille montre les deux plutôt que de forcer l'un
 * des deux dans l'unique case du papier.
 *
 * C'est ce document que le poste de gardiennage contrôle ensuite : le QR
 * porte la référence du passage, comme le ticket Pharmacie porte la
 * référence de sa facture (ADR-050).
 *
 * Extrait de la page d'impression pour servir aussi l'impression groupée de
 * plusieurs fiches (sélection multiple de « Sorties & règlements ») : une
 * seule mise en page, jamais deux qui divergent.
 */
const props = defineProps({
    episode: { type: Object, required: true },
    patient: { type: Object, required: true },
    medical_discharge: { type: Object, default: null },
    administrative_exit: { type: Object, required: true },
});

// Les quatre cases du papier, complétées des types que le dossier connaît
// en plus (ADR-035) : une divergence signalée à l'écran, jamais masquée.
const dischargeChoices = [
    { value: 'NORMAL', label: 'Sortie normale' },
    { value: 'TRANSFER', label: 'Transfert' },
    { value: 'AT_PATIENT_REQUEST', label: 'Sortie sur sa demande' },
    { value: 'MEDICAL_DECISION_REFUSAL', label: 'Refus de la décision médicale' },
    { value: 'DECEASED', label: 'Décédé(e)' },
];

const hasBalance = computed(() => Number(props.administrative_exit.balance_amount) > 0);

// Scanné au poste de gardiennage (ADR-116) : la référence humaine du
// passage, comme le ticket Pharmacie encode sa référence de facture
// (ADR-050) — jamais l'UUID brut, illisible s'il faut le saisir à la main.
const qrCodeDataUrl = ref('');

onMounted(async () => {
    try {
        qrCodeDataUrl.value = await QRCode.toDataURL(props.episode.episode_number, { margin: 1, width: 160 });
    } catch {
        qrCodeDataUrl.value = '';
    }
});
</script>

<template>
    <div>
    <table class="ps-table">
        <tbody>
            <tr>
                <th class="ps-strong">N° DE DOSSIER</th>
                <td>{{ patient.patient_number }} <span class="ps-muted">· Passage {{ episode.episode_number }}</span></td>
            </tr>
            <tr>
                <th class="ps-label ps-label-green">Nom et Prénom</th>
                <td>{{ patient.name }}</td>
            </tr>
            <tr>
                <th class="ps-label ps-label-green">Date d’entrée</th>
                <td>{{ formatDateTime(episode.started_at) }}</td>
            </tr>
            <tr v-if="medical_discharge">
                <th class="ps-label ps-label-green">Date de sortie (médicale)</th>
                <td>{{ formatDateTime(medical_discharge.discharged_at) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="ps-table">
        <thead>
            <tr><th colspan="5" class="ps-section ps-section-blue">TYPE DE SORTIE</th></tr>
        </thead>
        <tbody>
            <tr>
                <td v-for="choice in dischargeChoices" :key="choice.value" class="esp-choice">
                    <span class="esp-box">{{ medical_discharge?.type === choice.value ? '☑' : '☐' }}</span>
                    {{ choice.label }}
                </td>
            </tr>
        </tbody>
    </table>

    <table class="ps-table">
        <thead>
            <tr><th colspan="2" class="ps-section ps-section-green">SORTIE ADMINISTRATIVE (CDC §33.3)</th></tr>
        </thead>
        <tbody>
            <tr>
                <th class="ps-label ps-label-green">Décision</th>
                <td>{{ administrative_exit.type_label }}</td>
            </tr>
            <tr>
                <th class="ps-label ps-label-green">Date</th>
                <td>{{ formatDateTime(administrative_exit.exited_at) }}</td>
            </tr>
            <tr v-if="hasBalance">
                <th class="ps-label ps-label-green">Reste dû</th>
                <td>
                    {{ formatMoney(administrative_exit.balance_amount) }}
                    <span v-if="administrative_exit.debt_number" class="ps-muted">· Créance {{ administrative_exit.debt_number }}</span>
                </td>
            </tr>
        </tbody>
    </table>

    <table class="ps-table esp-signatures">
        <thead>
            <tr><th colspan="3" class="ps-section ps-section-yellow">SIGNATURES</th></tr>
        </thead>
        <tbody>
            <tr>
                <th class="ps-label ps-label-yellow">Date</th>
                <td>{{ formatDate(administrative_exit.exited_at) }}</td>
                <td rowspan="3" class="esp-qr">
                    <img v-if="qrCodeDataUrl" :src="qrCodeDataUrl" alt="Code de contrôle" />
                    <p class="ps-muted">À scanner au poste de gardiennage</p>
                </td>
            </tr>
            <tr>
                <th class="ps-label ps-label-yellow">Le service caisse,</th>
                <td>{{ administrative_exit.author }}</td>
            </tr>
            <tr>
                <th class="ps-label ps-label-yellow">Le service sécurité,</th>
                <td class="esp-guard">Contrôle à effectuer au poste de gardiennage.</td>
            </tr>
        </tbody>
    </table>
    </div>
</template>

<style>
.esp-choice {
    text-align: center;
    font-size: 11px;
}

.esp-box {
    font-size: 14px;
    margin-inline-end: 3px;
}

.esp-signatures th {
    width: 26%;
}

.esp-qr {
    width: 30%;
    text-align: center;
    vertical-align: middle;
}

.esp-qr img {
    max-width: 110px;
}

.esp-qr p {
    margin: 4px 0 0;
}

.esp-guard {
    font-style: italic;
    color: #555;
}
</style>
