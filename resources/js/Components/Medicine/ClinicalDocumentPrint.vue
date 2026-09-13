<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { formatDate, formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';

/**
 * The clinic letterhead a document leaves the building with.
 *
 * Extracted rather than copied: the admission slip and the referral letter
 * are the same sheet of paper as the prescription — same masthead, same
 * identity block, same signature box — and three copies of that stylesheet
 * would drift apart the first time an address changes.
 *
 * It renders only what it is given. No document here computes a clinical
 * value of its own.
 */
defineProps({
    title: { type: String, required: true },
    documentTitle: { type: String, required: true },
    backHref: { type: String, required: true },
    backLabel: { type: String, default: 'Retour à la consultation' },
    episode: { type: Object, required: true },
    patient: { type: Object, required: true },
    author: { type: String, default: null },
    issuedAt: { type: [String, Object], default: null },
    priorityLabel: { type: String, default: null },
    isUrgent: { type: Boolean, default: false },
    /** A withdrawn request stays printable — a sheet that left must stay reproducible. */
    cancelledLabel: { type: String, default: null },
});

const page = usePage();
const brandName = computed(() => page.props.site?.brand ?? 'Clinique Saint Georges');
const siteName = computed(() => page.props.site?.name ?? brandName.value);
const legalDetails = computed(() => page.props.site?.documents ?? {});

const printDocument = () => window.print();
</script>

<template>
    <Head :title="title" />

    <div class="rx-page mx-auto w-full max-w-3xl space-y-3">
        <div class="rx-actions flex flex-wrap items-center justify-between gap-3">
            <Button :as="Link" :href="backHref" size="rg" variant="white-outline">
                <Icon class="text-lg" name="arrow-left" />
                <span class="ms-2">{{ backLabel }}</span>
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
                <p class="rx-title-text">{{ documentTitle }}</p>
            </header>

            <p v-if="cancelledLabel" class="rx-cancelled">{{ cancelledLabel }}</p>

            <section class="rx-prescriber-row">
                <p><strong>Dr {{ author || 'N/R' }}</strong></p>
                <p>Fait à {{ siteName }}, le {{ formatDateTime(issuedAt ?? new Date()) }}</p>
            </section>

            <section class="rx-identity">
                <div>
                    <p class="rx-identity-name"><strong>{{ formatPatientName(patient) }}</strong></p>
                    <p>
                        {{ patient.sex_label }},
                        {{ patient.birth_date && !patient.birth_date_is_approximate
                            ? formatDate(patient.birth_date)
                            : (patient.age !== null && patient.age !== undefined
                                ? `${patient.age} ans${patient.birth_date_is_approximate ? ' (déclaré)' : ''}`
                                : 'N/R') }}
                    </p>
                </div>
                <div class="rx-identity-numbers">
                    <p>N° patient : <strong>{{ patient.patient_number }}</strong></p>
                    <p>
                        N° passage : <strong>{{ episode.episode_number }}</strong>
                        <span v-if="episode.priority === 'EMERGENCY'" class="rx-emergency-tag">Urgence</span>
                    </p>
                    <p v-if="priorityLabel">
                        Priorité : <strong :class="isUrgent ? 'rx-urgent' : ''">{{ priorityLabel }}</strong>
                    </p>
                </div>
            </section>

            <section class="rx-content">
                <slot />
            </section>

            <section class="rx-signature">
                <div class="rx-signature-box">
                    <p class="rx-signature-label">Signature et cachet du médecin</p>
                    <div class="rx-signature-line" />
                </div>
            </section>

            <footer class="rx-footer">
                <p>Document imprimé le {{ formatDateTime(new Date()) }}.</p>
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

.rx-cancelled {
    margin: 12px 0 0;
    padding: 6px 10px;
    border: 1px solid #000;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
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

.rx-emergency-tag,
.rx-urgent {
    margin-inline-start: 8px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
}

.rx-content {
    margin-top: 18px;
}

.rx-block {
    margin-top: 14px;
    break-inside: avoid;
}

.rx-block-label {
    margin: 0 0 3px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.rx-block-value {
    margin: 0;
    font-size: 12px;
    line-height: 1.6;
    white-space: pre-line;
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
}
</style>
