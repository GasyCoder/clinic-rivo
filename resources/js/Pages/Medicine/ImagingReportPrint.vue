<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArrowLeft, Printer } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import ImagingReportDocument from '@/Components/Medicine/ImagingReportDocument.vue';

defineOptions({ layout: AppLayout });

/**
 * Le compte rendu d'imagerie, sur papier (ADR-108).
 *
 * Impression navigateur, jamais un PDF produit côté serveur : c'est la limite
 * déjà actée par l'ADR-070. La mise en page est celle de la feuille de la
 * clinique, portée par `ImagingReportDocument` — le même composant que
 * l'affichage « Voir le résultat ».
 */
defineProps({
    document: { type: Object, required: true },
});

const printDocument = () => window.print();
</script>

<template>
    <Head :title="`Compte rendu ${document.exam} — ${document.episode_number}`" />

    <div class="ir-page mx-auto w-full max-w-[52rem] space-y-3">
        <div class="ir-actions flex flex-wrap items-center justify-between gap-3">
            <Button :as="Link" href="/medicine/demandes-examens" size="sm" variant="white-outline">
                <ArrowLeft class="h-4 w-4" />Retour aux demandes
            </Button>
            <Button type="button" size="sm" @click="printDocument">
                <Printer class="h-4 w-4" />Imprimer
            </Button>
        </div>

        <div class="ir-paper rounded-lg border border-border shadow-sm">
            <ImagingReportDocument :document="document" />
        </div>
    </div>
</template>

<style>
@page {
    size: A4;
    margin: 12mm;
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

    .ir-paper {
        border: 0 !important;
        box-shadow: none !important;
    }
}
</style>
