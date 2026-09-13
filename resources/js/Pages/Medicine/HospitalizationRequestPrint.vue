<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClinicalDocumentPrint from '@/Components/Medicine/ClinicalDocumentPrint.vue';
import { formatDateTime } from '@/utilities/date';

defineOptions({ layout: AppLayout });

/**
 * Le billet d'hospitalisation : ce que le médecin demande au service
 * d'accueil, sur une seule feuille.
 *
 * Il ne prétend rien de plus. Aucune admission n'y figure, aucun lit,
 * aucune date d'entrée réelle — seulement la demande, sa date souhaitée et
 * ce que le service doit savoir en recevant le patient.
 */
const props = defineProps({
    orientation: Object,
    episode: Object,
    patient: Object,
    request: Object,
});

const blocks = computed(() => [
    { label: 'Motif d’hospitalisation', value: props.request.reason },
    { label: 'Diagnostic d’entrée', value: props.request.admission_diagnosis },
    { label: 'Résumé clinique', value: props.request.clinical_summary },
    { label: 'Traitement prévu', value: props.request.planned_treatment },
    { label: 'Service souhaité', value: props.request.requested_service },
    {
        label: 'Admission souhaitée',
        value: props.request.requested_admission_at ? formatDateTime(props.request.requested_admission_at) : null,
    },
    { label: 'Instructions', value: props.request.instructions },
].filter((block) => Boolean(block.value)));
</script>

<template>
    <ClinicalDocumentPrint
        :title="`Billet d’hospitalisation ${episode.episode_number}`"
        document-title="Billet d’hospitalisation"
        :back-href="`/medicine/orientations/${orientation.uuid}/examen`"
        :episode="episode"
        :patient="patient"
        :author="request.requested_by"
        :issued-at="request.requested_at"
        :priority-label="request.priority_label"
        :is-urgent="request.priority === 'URGENT'"
        :cancelled-label="request.status === 'CANCELLED' ? 'Demande annulée — document conservé pour l’historique' : null"
    >
        <div v-for="block in blocks" :key="block.label" class="rx-block">
            <p class="rx-block-label">{{ block.label }}</p>
            <p class="rx-block-value">{{ block.value }}</p>
        </div>
    </ClinicalDocumentPrint>
</template>
