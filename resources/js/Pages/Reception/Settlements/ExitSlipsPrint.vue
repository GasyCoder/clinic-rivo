<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PaperSheet from '@/Components/Clinical/PaperSheet.vue';
import ExitSlipBody from '@/Components/Clinical/ExitSlipBody.vue';

defineOptions({ layout: AppLayout });

/**
 * Les fiches de sortie de plusieurs passages dans un seul document, chacune sur
 * sa page — même mécanique que les journaux de traitement réunis (ADR-118) :
 * la première feuille porte la barre d'actions, les suivantes ne la répètent
 * pas, et une seule impression donne un seul fichier.
 *
 * Un passage sans sortie prononcée n'a rien à signer : le serveur l'écarte et
 * en donne le nombre.
 */
defineProps({
    slips: { type: Array, default: () => [] },
    skipped: { type: Number, default: 0 },
});
</script>

<template>
    <div class="ess-doc space-y-4">
        <aside v-if="skipped > 0" class="ess-hint mx-auto max-w-[46rem] rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
            {{ skipped }} passage{{ skipped > 1 ? 's' : '' }} sans sortie prononcée n’{{ skipped > 1 ? 'ont' : 'a' }} pas de fiche : {{ skipped > 1 ? 'ils sont' : 'il est' }} écarté{{ skipped > 1 ? 's' : '' }}.
        </aside>

        <p v-if="slips.length === 0" class="ess-hint mx-auto max-w-[46rem] rounded-lg border border-border bg-card px-4 py-8 text-center text-sm text-muted-foreground">
            Aucune fiche à imprimer pour cette sélection. <a href="/reception/sorties?tab=discharged" class="font-semibold text-primary hover:underline">Retour aux sorties</a>
        </p>

        <PaperSheet
            v-for="(slip, index) in slips"
            :key="slip.episode.uuid"
            :page-title="`Fiches de sortie (${slips.length})`"
            document-title="Fiche de sortie"
            :back-href="'/reception/sorties?tab=discharged'"
            back-label="Retour aux sorties"
            max-width-class="max-w-[46rem]"
            :show-actions="index === 0"
        >
            <ExitSlipBody
                :episode="slip.episode"
                :patient="slip.patient"
                :medical_discharge="slip.medical_discharge"
                :administrative_exit="slip.administrative_exit"
            />
        </PaperSheet>
    </div>
</template>

<style>
@media print {
    /* Une seule impression, un seul PDF : chaque fiche commence sa page. */
    .ess-doc > .ps-page + .ps-page {
        break-before: page;
        page-break-before: always;
    }

    .ess-hint {
        display: none !important;
    }
}
</style>
