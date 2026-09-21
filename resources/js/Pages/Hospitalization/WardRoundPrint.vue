<script setup>
import { onBeforeUnmount, onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PaperSheet from '@/Components/Clinical/PaperSheet.vue';
import { formatDateTime } from '@/utilities/date';
import { bloodPressure, stayDays } from '@/utilities/hospitalStay';

defineOptions({ layout: AppLayout });

/**
 * ADR-165 — la feuille de tour de salle : une ligne par patient coché, classée
 * par service puis par chambre, pour la visite.
 *
 * Tout est lu dans le dossier (lit, motif, allergies, dernier relevé) : rien
 * n'est ressaisi. La colonne « Notes de visite » est laissée vide pour la
 * main du médecin — la feuille sert au lit du patient, pas à l'écran.
 *
 * Le dernier relevé n'existe qu'avec `vitals.view` : sans ce droit la colonne
 * disparaît, elle ne s'affiche jamais vide (une case vide se lirait « aucun
 * relevé »).
 */
defineProps({
    stays: { type: Array, default: () => [] },
    vitals_visible: { type: Boolean, default: false },
    generated_at: { type: String, default: null },
});

/*
 * Seule cette feuille passe en paysage : huit colonnes, dont un motif en texte
 * libre, ne tiennent pas dans un A4 portrait. Une page nommée CSS ne s'applique
 * pas à un élément imbriqué dans les conteneurs flex de la mise en page, d'où la
 * règle injectée au montage et retirée en quittant la page — le même idiome que
 * la facture et le reçu. Rien n'est lu pendant le rendu serveur.
 */
const PAGE_STYLE_ID = 'rivo-ward-round-page';

onMounted(() => {
    const style = document.getElementById(PAGE_STYLE_ID) ?? document.createElement('style');
    style.id = PAGE_STYLE_ID;
    style.textContent = '@page { size: A4 landscape; margin: 10mm; }';
    document.head.appendChild(style);
});

onBeforeUnmount(() => document.getElementById(PAGE_STYLE_ID)?.remove());

const temperature = (reading) => (reading?.temperature_celsius ?? null) === null
    ? '—'
    : `${String(reading.temperature_celsius).replace('.', ',')} °C`;
</script>

<template>
    <PaperSheet
        :page-title="`Tour de salle (${stays.length})`"
        document-title="Liste de tour de salle"
        back-href="/hospitalisation"
        back-label="Retour à l’hospitalisation"
        max-width-class="max-w-[72rem]"
    >
        <p class="wr-meta">
            {{ stays.length }} patient{{ stays.length > 1 ? 's' : '' }}
            <template v-if="generated_at"> · établie le {{ formatDateTime(generated_at) }}</template>
        </p>

        <table class="ps-table wr-table">
            <thead>
                <tr>
                    <th class="ps-section ps-section-blue wr-col-rank">N°</th>
                    <th class="ps-section ps-section-blue wr-col-bed">Service · lit</th>
                    <th class="ps-section ps-section-blue wr-col-patient">Patient</th>
                    <th class="ps-section ps-section-blue">Motif</th>
                    <th class="ps-section ps-section-blue wr-col-stay">Séjour</th>
                    <th class="ps-section ps-section-blue wr-col-allergy">Allergies</th>
                    <th v-if="vitals_visible" class="ps-section ps-section-blue wr-col-vitals">Dernier relevé</th>
                    <th class="ps-section ps-section-blue wr-col-notes">Notes de visite</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(stay, index) in stays" :key="stay.uuid">
                    <td class="wr-center">{{ index + 1 }}</td>
                    <td>
                        <strong>{{ stay.room_bed || 'Lit non renseigné' }}</strong>
                        <span class="ps-muted wr-block">{{ stay.service || 'Service non précisé' }}</span>
                        <span v-if="stay.care_level && stay.care_level !== 'STANDARD'" class="wr-flag">{{ stay.care_level_label }}</span>
                    </td>
                    <td>
                        <strong>{{ stay.patient.name }}</strong>
                        <span class="ps-muted wr-block">
                            {{ stay.patient.patient_number }}<template v-if="stay.patient.age !== null"> · {{ stay.patient.age }} ans</template><template v-if="stay.patient.sex"> · {{ stay.patient.sex }}</template>
                        </span>
                        <span class="ps-muted wr-block">Passage {{ stay.episode_number }}</span>
                        <span v-if="stay.priority === 'URGENT'" class="wr-flag">Urgent</span>
                    </td>
                    <td class="wr-text">{{ stay.reason }}</td>
                    <td>
                        {{ stayDays(stay) }}
                        <span class="ps-muted wr-block">Entré le {{ formatDateTime(stay.admitted_at) }}</span>
                    </td>
                    <td :class="stay.allergies.length ? 'wr-allergy' : ''">{{ stay.allergies.join(', ') }}</td>
                    <td v-if="vitals_visible">
                        <template v-if="stay.latest_reading">
                            TA {{ bloodPressure(stay.latest_reading) }} · FC {{ stay.latest_reading.heart_rate ?? '—' }}
                            <span class="wr-block">SpO₂ {{ stay.latest_reading.spo2 ?? '—' }}<template v-if="stay.latest_reading.spo2 !== null"> %</template> · T° {{ temperature(stay.latest_reading) }}</span>
                            <span class="ps-muted wr-block">{{ formatDateTime(stay.latest_reading.measured_at) }}</span>
                            <span v-for="alert in stay.latest_reading.alerts" :key="alert.label" class="wr-flag">{{ alert.label }}</span>
                        </template>
                        <span v-else class="ps-muted">Aucun relevé pendant le séjour</span>
                    </td>
                    <td />
                </tr>
                <tr v-if="stays.length === 0">
                    <td :colspan="vitals_visible ? 8 : 7" class="wr-center ps-muted">Aucun patient dans cette sélection.</td>
                </tr>
            </tbody>
        </table>
    </PaperSheet>
</template>

<style>
.wr-meta {
    margin: 12px 0 0;
    font-size: 11px;
    color: #555;
}

.wr-table {
    font-size: 11px;
}

.wr-table th,
.wr-table td {
    vertical-align: top;
}

/* Le motif, seul texte libre de la feuille, prend ce qui reste. */
.wr-col-rank { width: 4%; }
.wr-col-bed { width: 12%; }
.wr-col-patient { width: 15%; }
.wr-col-stay { width: 10%; }
.wr-col-allergy { width: 10%; }
.wr-col-vitals { width: 14%; }
.wr-col-notes { width: 16%; }

.wr-center {
    text-align: center;
}

.wr-block {
    display: block;
}

.wr-text {
    white-space: pre-line;
}

/* Une allergie ou un signal se voit sur le papier, même en noir et blanc. */
.wr-allergy {
    font-weight: 700;
}

.wr-flag {
    display: inline-block;
    margin-top: 2px;
    margin-inline-end: 3px;
    padding: 0 4px;
    border: 1px solid #000;
    font-size: 10px;
    font-weight: 700;
}

.wr-table tbody tr {
    break-inside: avoid;
}

</style>
