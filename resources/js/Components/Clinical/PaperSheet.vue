<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import { ArrowLeft, Printer } from 'lucide-vue-next';

/**
 * ADR-116 — le bandeau, le titre et le pied communs à toutes les feuilles
 * papier de la clinique (dossier médical, journal de traitement, fiche de
 * sortie).
 *
 * Avant cette page, chacune des trois feuilles précédentes (ADR-107, 108,
 * 113) recopiait le même bandeau au logo et la même règle d'impression sous
 * un préfixe de classe différent (`dc-*`, `rd-*`, `ds-*`). Une quatrième
 * feuille recopiée de plus aurait été la redondance que l'ADR-098 corrige
 * ailleurs pour la même raison ; celles déjà publiées ne sont pas touchées
 * pour autant, un changement de chrome partagé n'ayant rien à leur apporter
 * qui justifie d'y retoucher (ADR-091).
 *
 * Chaque appelant garde son propre tableau de contenu et ses propres
 * couleurs de section : seuls le bandeau, le titre et le pied sont
 * communs. Les couleurs restent écrites en dur — elles décrivent du papier
 * imprimé, jamais un thème d'interface.
 */
const props = defineProps({
    /** Titre de l'onglet navigateur. */
    pageTitle: { type: String, required: true },
    /** Le titre encadré, tel qu'il apparaît sur la feuille papier. */
    documentTitle: { type: String, required: true },
    backHref: { type: String, default: null },
    backLabel: { type: String, default: 'Retour' },
    maxWidthClass: { type: String, default: 'max-w-[52rem]' },
    /**
     * Plusieurs feuilles réunies dans un seul document (ADR-118) n'ont
     * besoin que d'une barre d'actions : la première la porte, les suivantes
     * ne la répètent pas.
     */
    showActions: { type: Boolean, default: true },
});

const page = usePage();
const brand = computed(() => page.props.site?.brand ?? 'Clinique Saint Georges');
const legal = computed(() => page.props.site?.documents ?? {});

const footerParts = computed(() => [
    brand.value.toLocaleUpperCase('fr'),
    legal.value.email,
    legal.value.phone ? `Tél : ${legal.value.phone}` : null,
].filter(Boolean));

const printSheet = () => window.print();
</script>

<template>
    <Head :title="pageTitle" />

    <div :class="['ps-page mx-auto w-full space-y-3', maxWidthClass]">
        <div v-if="showActions" class="ps-actions flex flex-wrap items-center justify-between gap-3">
            <Button v-if="backHref" :as="Link" :href="backHref" size="sm" variant="white-outline">
                <ArrowLeft class="h-4 w-4" />{{ backLabel }}
            </Button>
            <span v-else />
            <div class="flex flex-wrap items-center gap-2">
                <slot name="actions" />
                <Button type="button" size="sm" @click="printSheet">
                    <Printer class="h-4 w-4" />Imprimer
                </Button>
            </div>
        </div>

        <!-- Écran seulement : des onglets pour passer d'un dossier à l'autre (ADR-145). -->
        <div v-if="$slots.tabs" class="ps-actions">
            <slot name="tabs" />
        </div>

        <article class="ps-sheet rounded-lg border border-border shadow-sm">
            <header class="ps-head">
                <div class="ps-band" />
                <div class="ps-logo">
                    <img v-if="legal.logo_url" :src="legal.logo_url" :alt="`Logo ${brand}`" />
                    <span v-else class="ps-logo-text">{{ brand }}</span>
                </div>
                <div class="ps-band" />
            </header>
            <p class="ps-title">{{ documentTitle }}</p>

            <slot />

            <footer class="ps-foot">{{ footerParts.join(' – ') }}</footer>
        </article>
    </div>
</template>

<style>
.ps-sheet {
    --ps-blue: #00aeef;
    --ps-blue-soft: #b4c6e7;
    --ps-green: #c6e0b4;
    --ps-yellow: #ffe699;
    background: #fff;
    color: #000;
    font-family: Calibri, Carlito, Arial, Helvetica, sans-serif;
    font-size: 12px;
    line-height: 1.35;
    padding: 24px 26px 16px;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

.ps-head {
    display: grid;
    grid-template-columns: 1fr 2.2fr 1fr;
    border: 1px solid #000;
}

.ps-band {
    background: var(--ps-blue);
}

.ps-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 84px;
    padding: 6px;
    border-inline: 1px solid #000;
}

.ps-logo img {
    max-height: 96px;
    max-width: 100%;
    object-fit: contain;
}

.ps-logo-text {
    font-size: 20px;
    font-weight: 800;
}

.ps-title {
    margin: 0;
    padding: 5px;
    border: 1px solid #000;
    border-top: 0;
    font-size: 14px;
    font-weight: 700;
    text-align: center;
    text-transform: uppercase;
}

.ps-table {
    width: 100%;
    margin-top: 16px;
    border-collapse: collapse;
    table-layout: fixed;
}

.ps-table th,
.ps-table td {
    border: 1px solid #000;
    padding: 4px 8px;
    vertical-align: middle;
    word-wrap: break-word;
}

.ps-section {
    font-weight: 700;
    text-align: center;
}

.ps-section-blue { background: var(--ps-blue-soft); }
.ps-section-green { background: var(--ps-green); }
.ps-section-yellow { background: var(--ps-yellow); }

.ps-strong {
    background: var(--ps-blue);
    font-weight: 700;
    text-align: center;
}

.ps-label {
    font-weight: 400;
    text-align: right;
    white-space: nowrap;
}

.ps-label-green { background: var(--ps-green); }
.ps-label-blue-soft { background: var(--ps-blue-soft); }
.ps-label-yellow { background: var(--ps-yellow); font-weight: 700; text-align: left; }

.ps-muted {
    color: #555;
    font-size: 10.5px;
}

.ps-foot {
    margin-top: 16px;
    padding-top: 4px;
    border-top: 1px solid #000;
    font-size: 10.5px;
    text-align: center;
}

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
    .ps-actions {
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

    .ps-page {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .ps-sheet {
        padding: 0;
        border: 0 !important;
        box-shadow: none !important;
    }
}
</style>
