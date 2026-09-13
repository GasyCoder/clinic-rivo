<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClinicalDocumentPrint from '@/Components/Medicine/ClinicalDocumentPrint.vue';

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
});

const blocks = computed(() => [
    { label: 'Motif de la référence', value: props.referral.reason },
    { label: 'Diagnostic', value: props.referral.diagnosis },
    { label: 'Résumé clinique et examens réalisés', value: props.referral.clinical_summary },
    { label: 'Traitements administrés ou prescrits', value: props.referral.treatments_given },
    { label: 'Recommandations', value: props.referral.recommendations },
    { label: 'Observations', value: props.referral.notes },
].filter((block) => Boolean(block.value)));
</script>

<template>
    <ClinicalDocumentPrint
        :title="`Lettre de référence ${episode.episode_number}`"
        document-title="Lettre de référence"
        :back-href="`/medicine/orientations/${orientation.uuid}/examen`"
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
            <p class="rx-block-value"><strong>{{ referral.facility }}</strong></p>
        </div>

        <div v-for="block in blocks" :key="block.label" class="rx-block">
            <p class="rx-block-label">{{ block.label }}</p>
            <p class="rx-block-value">{{ block.value }}</p>
        </div>

        <div class="rx-block">
            <p class="rx-block-label">Confraternellement</p>
            <p class="rx-block-value">Nous vous remercions de bien vouloir prendre en charge ce patient et restons à votre disposition pour tout complément d’information.</p>
        </div>
    </ClinicalDocumentPrint>
</template>
