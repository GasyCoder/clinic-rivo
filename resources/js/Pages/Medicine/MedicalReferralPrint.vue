<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClinicalDocumentPrint from '@/Components/Medicine/ClinicalDocumentPrint.vue';
import ClinicalRichTextDisplay from '@/Components/Clinical/ClinicalRichTextDisplay.vue';

defineOptions({ layout: AppLayout });

/**
 * La lettre de référence : ce que l'équipe destinataire doit lire avant de
 * recevoir le patient.
 *
 * Tout son contenu vient de ce qui a déjà été consigné pendant la
 * consultation — motif, diagnostic, examen, résultats, traitements. Le
 * médecin l'a corrigé au moment de la demande ; il ne l'a jamais ressaisi.
 */
const props = defineProps({
    orientation: Object,
    episode: Object,
    patient: Object,
    referral: Object,
    // ADR-114 — imprimée aussi depuis le module Transferts, qui y ramène.
    backHref: { type: String, default: null },
});

// ADR-114 — les champs rédigés sont en texte riche : la lettre imprime le
// HTML assaini par le serveur, jamais les balises en clair.
const blocks = computed(() => [
    { label: 'Motif de la référence', html: props.referral.reason_html },
    { label: 'Diagnostic', html: props.referral.diagnosis_html },
    { label: 'Résumé clinique et examens réalisés', html: props.referral.clinical_summary_html },
    { label: 'Traitements administrés ou prescrits', html: props.referral.treatments_given_html },
    { label: 'Recommandations', html: props.referral.recommendations_html },
    { label: 'Observations', html: props.referral.notes_html },
].filter((block) => Boolean(block.html)));
</script>

<template>
    <ClinicalDocumentPrint
        :title="`Lettre de référence ${episode.episode_number}`"
        document-title="Lettre de référence"
        :back-href="backHref ?? `/medicine/orientations/${orientation.uuid}/examen`"
        :episode="episode"
        :patient="patient"
        :author="referral.referred_by"
        :issued-at="referral.referred_at"
        :priority-label="referral.priority_label"
        :is-urgent="referral.priority === 'URGENT'"
        :cancelled-label="referral.status === 'CANCELLED' ? 'Référence annulée — document conservé pour l’historique' : null"
    >
        <div class="rx-block">
            <p class="rx-block-label">Établissement / service destinataire</p>
            <p class="rx-block-value"><strong>{{ referral.facility || 'À préciser' }}</strong></p>
        </div>

        <div v-for="block in blocks" :key="block.label" class="rx-block">
            <p class="rx-block-label">{{ block.label }}</p>
            <ClinicalRichTextDisplay class="rx-block-value" :html="block.html" />
        </div>

        <div class="rx-block">
            <p class="rx-block-label">Confraternellement</p>
            <p class="rx-block-value">Nous vous remercions de bien vouloir prendre en charge ce patient et restons à votre disposition pour tout complément d’information.</p>
        </div>
    </ClinicalDocumentPrint>
</template>
