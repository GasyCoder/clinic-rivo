<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import MedicalRecordPrint from '@/Pages/Medicine/MedicalRecordPrint.vue';

defineOptions({ layout: AppLayout });

/**
 * ADR-165 — les dossiers médicaux des patients cochés, chacun sur sa page.
 *
 * Chaque dossier est la feuille du passage elle-même (ADR-116), avec les mêmes
 * gardes par section : une impression groupée ne montre rien que la feuille
 * seule ne montrerait pas. Le premier porte la barre d'actions, les suivants ne
 * la répètent pas ; une seule impression donne un seul fichier.
 */
defineProps({
    records: { type: Array, default: () => [] },
    back: { type: Object, required: true },
});
</script>

<template>
    <div class="mrs-doc space-y-4">
        <p v-if="records.length === 0" class="mrs-hint mx-auto max-w-[52rem] rounded-lg border border-border bg-card px-4 py-8 text-center text-sm text-muted-foreground">
            Aucun dossier à imprimer dans cette sélection.
            <Link :href="back.href" class="font-semibold text-primary hover:underline">{{ back.label }}</Link>
        </p>

        <MedicalRecordPrint
            v-for="(record, index) in records"
            :key="record.episode?.uuid ?? index"
            v-bind="record"
            :page-title="`Dossiers médicaux (${records.length})`"
            :show-actions="index === 0"
        />
    </div>
</template>

<style>
@media print {
    /* Chaque dossier commence sa page. */
    .mrs-doc > .ps-page + .ps-page {
        break-before: page;
        page-break-before: always;
    }

    .mrs-hint {
        display: none !important;
    }
}
</style>
