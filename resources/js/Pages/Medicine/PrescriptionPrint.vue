<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { formatDate, formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    orientation: Object,
    episode: Object,
    patient: Object,
    prescription: Object,
});

const page = usePage();
const brandName = computed(() => page.props.site?.brand ?? 'Clinique Saint Georges');
const siteName = computed(() => page.props.site?.name ?? brandName.value);
const legalDetails = computed(() => page.props.site?.documents ?? {});

const patientAge = computed(() => (props.patient.age !== null && props.patient.age !== undefined
    ? `${props.patient.age} ans${props.patient.birth_date_is_approximate ? ' (déclaré)' : ''}`
    : 'N/R'));
const birthLabel = computed(() => (props.patient.birth_date && !props.patient.birth_date_is_approximate
    ? formatDate(props.patient.birth_date)
    : patientAge.value));

/** Reads like "500 mg, 3 fois par jour, pendant 7 jours", the way a prescriber actually writes posology. */
const posologyLine = (line) => {
    const parts = [line.dosage, line.frequency].filter(Boolean).join(', ');

    if (!line.duration) return parts;

    return parts ? `${parts}, pendant ${line.duration}` : `Pendant ${line.duration}`;
};

const printDocument = () => window.print();
</script>

<template>
    <Head :title="`Ordonnance ${episode.episode_number}`" />

    <div class="rx-page mx-auto w-full max-w-3xl space-y-3">
        <div class="rx-actions flex flex-wrap items-center justify-between gap-3">
            <Button :as="Link" :href="`/medicine/orientations/${orientation.uuid}/ordonnance`" size="rg" variant="white-outline">
                <Icon class="text-lg" name="arrow-left" />
                <span class="ms-2">Retour à l’ordonnance</span>
            </Button>
            <Button size="rg" variant="primary" type="button" @click="printDocument">
                <Icon class="text-lg" name="printer" />
                <span class="ms-2">Imprimer</span>
            </Button>
        </div>

        <article class="rx-document">
            <header class="rx-letterhead">
                <div class="rx-letterhead-brand">
                    <img v-if="legalDetails.logo_url" :src="legalDetails.logo_url" :alt="`Logo ${brandName}`" class="rx-logo-img" />
                    <div>
                        <p class="rx-brand-name">{{ brandName }}</p>
                        <p v-if="siteName !== brandName" class="rx-brand-site">Site {{ siteName }}</p>
                        <p v-if="legalDetails.address" class="rx-brand-address">{{ legalDetails.address }}</p>
                        <p v-if="legalDetails.phone" class="rx-brand-address">Tél. {{ legalDetails.phone }}</p>
                    </div>
                </div>
                <p class="rx-title-text">Ordonnance médicale</p>
            </header>

            <section class="rx-prescriber-row">
                <p><strong>Dr {{ prescription.prescribed_by || 'N/R' }}</strong></p>
                <p>Fait à {{ siteName }}, le {{ formatDateTime(prescription.prescribed_at) }}</p>
            </section>

            <section class="rx-identity">
                <div>
                    <p class="rx-identity-name"><strong>{{ formatPatientName(patient) }}</strong></p>
                    <p>{{ patient.sex_label }}, {{ birthLabel }}</p>
                </div>
                <div class="rx-identity-numbers">
                    <p>N° patient : <strong>{{ patient.patient_number }}</strong></p>
                    <p>N° passage : <strong>{{ episode.episode_number }}</strong><span v-if="episode.priority === 'EMERGENCY'" class="rx-emergency-tag">Urgence</span></p>
                </div>
            </section>

            <section class="rx-body">
                <p class="rx-rx-symbol">℞</p>
                <ol class="rx-lines">
                    <li v-for="(line, index) in prescription.lines" :key="line.id" class="rx-line">
                        <p class="rx-line-name">{{ index + 1 }}. {{ line.medication_name }} ({{ line.quantity }} {{ line.unit || 'unité(s)' }})</p>
                        <p v-if="posologyLine(line)" class="rx-line-posology">{{ posologyLine(line) }}</p>
                        <p v-else class="rx-line-posology rx-line-posology-missing">Posologie à préciser</p>
                        <p v-if="line.instructions" class="rx-line-instructions">{{ line.instructions }}</p>
                    </li>
                </ol>
            </section>

            <section class="rx-signature">
                <div class="rx-signature-box">
                    <p class="rx-signature-label">Signature et cachet du médecin</p>
                    <div class="rx-signature-line" />
                </div>
            </section>

            <footer class="rx-footer">
                <p>Ordonnance imprimée le {{ formatDateTime(new Date()) }}.</p>
            </footer>
        </article>
    </div>
</template>

<style>
.rx-document {
    background: #fff;
    color: #000;
    font-family: Arial, Helvetica, sans-serif;
    padding: 28px 32px;
}

.rx-letterhead {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding-bottom: 12px;
    border-bottom: 2px solid #000;
}

.rx-letterhead-brand {
    display: flex;
    align-items: center;
    gap: 10px;
}

.rx-logo-img {
    max-height: 44px;
    max-width: 90px;
    object-fit: contain;
}

.rx-brand-name {
    margin: 0;
    font-size: 15px;
    font-weight: 800;
    letter-spacing: 0.01em;
}

.rx-brand-site {
    margin: 1px 0 0;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.rx-brand-address {
    margin: 1px 0 0;
    font-size: 10px;
}

.rx-title-text {
    margin: 0;
    align-self: center;
    font-size: 15px;
    font-weight: 800;
    text-align: end;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.rx-prescriber-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 16px;
    margin-top: 14px;
    font-size: 12px;
}

.rx-prescriber-row p {
    margin: 0;
}

.rx-identity {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-top: 14px;
    padding: 10px 0;
    border-top: 1px solid #000;
    border-bottom: 1px solid #000;
    font-size: 12px;
    line-height: 1.6;
}

.rx-identity p {
    margin: 0;
}

.rx-identity-name {
    font-size: 13px;
}

.rx-identity-numbers {
    text-align: end;
}

.rx-emergency-tag {
    margin-inline-start: 8px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
}

.rx-body {
    display: flex;
    gap: 14px;
    margin-top: 20px;
}

.rx-rx-symbol {
    margin: 0;
    font-size: 26px;
    font-weight: 700;
    line-height: 1;
}

.rx-lines {
    flex: 1;
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    column-gap: 20px;
    row-gap: 16px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.rx-line {
    min-width: 0;
    padding: 10px 12px;
    border: 1px dotted #999;
    border-radius: 4px;
}

.rx-line-name {
    margin: 0;
    font-size: 13px;
    font-weight: 700;
}

.rx-line-posology {
    margin: 3px 0 0;
    padding-inline-start: 18px;
    font-size: 12px;
}

.rx-line-posology-missing {
    font-style: italic;
}

.rx-line-instructions {
    margin: 2px 0 0;
    padding-inline-start: 18px;
    font-size: 12px;
    font-style: italic;
}

.rx-signature {
    display: flex;
    justify-content: flex-end;
    margin-top: 32px;
}

.rx-signature-box {
    width: 220px;
    text-align: center;
}

.rx-signature-label {
    margin: 0 0 44px;
    font-size: 10px;
}

.rx-signature-line {
    border-top: 1px solid #000;
}

.rx-footer {
    margin-top: 24px;
    padding-top: 10px;
    border-top: 1px solid #000;
    font-size: 9px;
}

.rx-footer p {
    margin: 0;
}

@media print {
    html,
    body {
        min-width: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }

    .nk-sidebar,
    .nk-header,
    .nk-footer,
    .rx-actions {
        display: none !important;
    }

    .nk-wrap {
        min-height: 0 !important;
        padding: 0 !important;
    }

    .nk-content {
        margin: 0 !important;
        padding: 0 !important;
    }

    .rx-page {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .rx-line {
        break-inside: avoid;
    }
}
</style>
