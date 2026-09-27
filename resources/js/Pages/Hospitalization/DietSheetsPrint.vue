<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import DietSheetBody from '@/Components/Hospitalization/DietSheetBody.vue';
import { ArrowLeft, Printer, Utensils } from 'lucide-vue-next';

defineOptions({ layout: AppLayout });

/**
 * ADR-165 — les fiches de régime des patients cochés, chacune sur sa page :
 * une seule impression, un seul document, pour la cuisine ou le tour de salle.
 * Le même corps que la fiche d'un séjour (ADR-113).
 */
defineProps({
    sheets: { type: Array, default: () => [] },
});

const printSheets = () => window.print();
</script>

<template>
    <Head :title="`Fiches de régime (${sheets.length})`" />

    <div class="ds-page ds-many mx-auto w-full max-w-[52rem] space-y-4">
        <div class="ds-actions flex flex-wrap items-center justify-between gap-3">
            <Button :as="Link" href="/hospitalisation" size="sm" variant="white-outline">
                <ArrowLeft class="h-4 w-4" />Retour à l’hospitalisation
            </Button>
            <p class="flex items-center gap-2 text-sm text-muted-foreground">
                <Utensils class="h-4 w-4" aria-hidden="true" />
                {{ sheets.length }} fiche{{ sheets.length > 1 ? 's' : '' }} de régime, une par page
            </p>
            <Button type="button" size="sm" :disabled="sheets.length === 0" @click="printSheets">
                <Printer class="h-4 w-4" />Imprimer
            </Button>
        </div>

        <p v-if="sheets.length === 0" class="ds-actions rounded-lg border border-border bg-card px-4 py-8 text-center text-sm text-muted-foreground">
            Aucun séjour à imprimer dans cette sélection.
        </p>

        <DietSheetBody v-for="sheet in sheets" :key="sheet.uuid" :stay="sheet" />
    </div>
</template>

<style>
@media print {
    /* Une seule impression, un seul PDF : chaque fiche commence sa page. */
    .ds-many > .ds-sheet + .ds-sheet {
        break-before: page;
        page-break-before: always;
    }
}
</style>
