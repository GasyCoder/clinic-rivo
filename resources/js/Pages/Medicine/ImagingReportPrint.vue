<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArrowLeft, Printer } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import { formatDate, formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

/**
 * Le compte rendu d'imagerie, sur papier.
 *
 * Impression navigateur, jamais un PDF produit côté serveur : c'est la
 * limite déjà actée par l'ADR-070, et aucune dépendance n'est ajoutée pour
 * ce document.
 *
 * L'en-tête vient de `page.props.site` — le site réellement déployé. Chaque
 * clinique imprime donc le sien sans une seule condition dans ce fichier.
 *
 * Rien n'est composé ici : la page réimprime ce que le médecin a enregistré.
 */
const props = defineProps({
    report: Object,
    episode: Object,
    patient: Object,
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

const printDocument = () => window.print();
</script>

<template>
    <Head :title="`Compte rendu ${report.exam} — ${episode.episode_number}`" />

    <div class="ir-page mx-auto w-full max-w-3xl space-y-3">
        <div class="ir-actions flex flex-wrap items-center justify-between gap-3">
            <Button :as="Link" href="/medicine/demandes-examens" size="sm" variant="white-outline">
                <ArrowLeft class="h-4 w-4" />Retour aux demandes
            </Button>
            <Button type="button" size="sm" @click="printDocument">
                <Printer class="h-4 w-4" />Imprimer
            </Button>
        </div>

        <article class="ir-document">
            <header class="ir-letterhead">
                <div class="ir-letterhead-brand">
                    <img v-if="legalDetails.logo_url" :src="legalDetails.logo_url" :alt="`Logo ${brandName}`" class="ir-logo-img" />
                    <div>
                        <p class="ir-brand-name">{{ brandName }}</p>
                        <p v-if="siteName !== brandName" class="ir-brand-site">Site {{ siteName }}</p>
                        <p v-if="legalDetails.address" class="ir-brand-address">{{ legalDetails.address }}</p>
                        <p v-if="legalDetails.phone" class="ir-brand-address">Tél. {{ legalDetails.phone }}</p>
                    </div>
                </div>
                <p class="ir-title-text">Compte rendu d’imagerie</p>
            </header>

            <section class="ir-identity">
                <div>
                    <p class="ir-identity-name"><strong>{{ formatPatientName(patient) }}</strong></p>
                    <p>{{ patient.sex_label }}, {{ birthLabel }}</p>
                </div>
                <div class="ir-identity-numbers">
                    <p>N° patient : <strong>{{ patient.patient_number }}</strong></p>
                    <p>
                        N° passage : <strong>{{ episode.episode_number }}</strong>
                        <span v-if="episode.priority === 'EMERGENCY'" class="ir-emergency-tag">Urgence</span>
                    </p>
                </div>
            </section>

            <section class="ir-exam">
                <p class="ir-exam-name">
                    {{ report.exam }}<span v-if="report.code" class="ir-exam-code"> · {{ report.code }}</span>
                </p>
                <p class="ir-exam-meta">
                    Demandé le {{ formatDateTime(report.requested_at) }}<template v-if="report.requested_by"> par Dr {{ report.requested_by }}</template>
                </p>
                <p v-if="report.indication" class="ir-exam-meta">Indication : {{ report.indication }}</p>
            </section>

            <section class="ir-body">
                <h2 class="ir-section-title">Compte rendu</h2>
                <!-- Déjà assaini côté serveur par ClinicalRichTextSanitizer :
                     mise en forme seulement, ni lien, ni média, ni script. -->
                <div class="ir-rich" v-html="report.value" />
            </section>

            <section v-if="report.notes" class="ir-body">
                <h2 class="ir-section-title">Observations complémentaires</h2>
                <div class="ir-rich" v-html="report.notes" />
            </section>

            <section class="ir-signature">
                <div class="ir-signature-box">
                    <p class="ir-signature-label">
                        <template v-if="report.resulted_by">Dr {{ report.resulted_by }}</template>
                        <template v-else>Signature et cachet du médecin</template>
                    </p>
                    <p class="ir-signature-date">Fait à {{ siteName }}, le {{ formatDateTime(report.resulted_at) }}</p>
                    <div class="ir-signature-line" />
                </div>
            </section>

            <footer class="ir-footer">
                <p>Document imprimé le {{ formatDateTime(new Date()) }}.</p>
            </footer>
        </article>
    </div>
</template>

<style>
.ir-document {
    background: #fff;
    color: #000;
    font-family: Arial, Helvetica, sans-serif;
    padding: 28px 32px;
}

.ir-letterhead {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding-bottom: 12px;
    border-bottom: 2px solid #000;
}

.ir-letterhead-brand {
    display: flex;
    align-items: center;
    gap: 10px;
}

.ir-logo-img {
    max-height: 44px;
    max-width: 90px;
    object-fit: contain;
}

.ir-brand-name {
    margin: 0;
    font-size: 15px;
    font-weight: 800;
    letter-spacing: 0.01em;
}

.ir-brand-site {
    margin: 1px 0 0;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.ir-brand-address {
    margin: 1px 0 0;
    font-size: 10px;
}

.ir-title-text {
    margin: 0;
    align-self: center;
    font-size: 15px;
    font-weight: 800;
    text-align: end;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.ir-identity {
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

.ir-identity p {
    margin: 0;
}

.ir-identity-name {
    font-size: 13px;
}

.ir-identity-numbers {
    text-align: end;
}

.ir-emergency-tag {
    margin-inline-start: 8px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
}

.ir-exam {
    margin-top: 16px;
}

.ir-exam-name {
    margin: 0;
    font-size: 14px;
    font-weight: 800;
}

.ir-exam-code {
    font-weight: 400;
}

.ir-exam-meta {
    margin: 2px 0 0;
    font-size: 11px;
}

.ir-body {
    margin-top: 18px;
}

.ir-section-title {
    margin: 0 0 6px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    border-bottom: 1px solid #000;
    padding-bottom: 3px;
}

.ir-rich {
    font-size: 12px;
    line-height: 1.65;
}

.ir-rich p {
    margin: 0 0 6px;
}

.ir-rich ul,
.ir-rich ol {
    margin: 0 0 6px;
    padding-inline-start: 20px;
}

.ir-signature {
    display: flex;
    justify-content: flex-end;
    margin-top: 28px;
}

.ir-signature-box {
    width: 240px;
    text-align: center;
}

.ir-signature-label {
    margin: 0;
    font-size: 12px;
    font-weight: 700;
}

.ir-signature-date {
    margin: 2px 0 0;
    font-size: 10px;
}

.ir-signature-line {
    margin-top: 42px;
    border-top: 1px solid #000;
}

.ir-footer {
    margin-top: 22px;
    padding-top: 8px;
    border-top: 1px solid #000;
    font-size: 10px;
}

.ir-footer p {
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
    .ir-actions {
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

    .ir-page {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .ir-body,
    .ir-signature {
        break-inside: avoid;
    }
}
</style>
