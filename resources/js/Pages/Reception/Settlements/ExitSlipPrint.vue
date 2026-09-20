<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PaperSheet from '@/Components/Clinical/PaperSheet.vue';
import ExitSlipBody from '@/Components/Clinical/ExitSlipBody.vue';

defineOptions({ layout: AppLayout });

/**
 * ADR-116 — la « FICHE DE SORTIE » d'un passage. Le contenu vit dans
 * `ExitSlipBody`, partagé avec l'impression groupée.
 */
defineProps({
    episode: { type: Object, required: true },
    patient: { type: Object, required: true },
    medical_discharge: { type: Object, default: null },
    administrative_exit: { type: Object, required: true },
});
</script>

<template>
    <PaperSheet
        :page-title="`Fiche de sortie — ${patient.name}`"
        document-title="Fiche de sortie"
        :back-href="'/reception/sorties?tab=discharged'"
        back-label="Retour aux sorties"
        max-width-class="max-w-[46rem]"
    >
        <ExitSlipBody
            :episode="episode"
            :patient="patient"
            :medical_discharge="medical_discharge"
            :administrative_exit="administrative_exit"
        />
    </PaperSheet>
</template>
