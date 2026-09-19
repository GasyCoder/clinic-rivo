<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { ArrowLeft, Printer } from 'lucide-vue-next';
import { formatDate } from '@/utilities/date';

defineOptions({ layout: AppLayout });

/**
 * ADR-113 — la fiche de régime, sur papier.
 *
 * Elle reproduit la feuille de la clinique : bandeau au logo, N° de dossier,
 * nom, allergie, tabac, motif d'hospitalisation, puis la grille jour / heure
 * et ses quatre colonnes « Régime ». Des lignes vides complètent la grille :
 * la feuille imprimée reste utilisable au lit du patient.
 *
 * Les couleurs sont celles du papier, écrites en dur : elles décrivent une
 * feuille imprimée, pas l'interface (même choix que l'ADR-108).
 */
const props = defineProps({
    stay: { type: Object, required: true },
});

const page = usePage();
const brand = computed(() => page.props.site?.brand ?? 'Clinique Saint Georges');
const legal = computed(() => page.props.site?.documents ?? {});

const MIN_ROWS = 13;
const rows = computed(() => {
    const filled = props.stay.diet_entries;
    const blanks = Math.max(MIN_ROWS - filled.length, 0);

    return [...filled, ...Array.from({ length: blanks }, (_, index) => ({ uuid: `blank-${index}`, blank: true }))];
});

const smokerLabel = computed(() => (props.stay.smoker === null ? '' : (props.stay.smoker ? 'Oui' : 'Non')));

const footerParts = computed(() => [
    brand.value.toLocaleUpperCase('fr'),
    legal.value.email,
    legal.value.phone ? `Tél : ${legal.value.phone}` : null,
].filter(Boolean));

const printSheet = () => window.print();
</script>

<template>
    <Head :title="`Fiche de régime — ${stay.patient.name}`" />

    <div class="ds-page mx-auto w-full max-w-[52rem] space-y-3">
        <div class="ds-actions flex flex-wrap items-center justify-between gap-3">
            <Button :as="Link" :href="`/hospitalisation/${stay.uuid}`" size="sm" variant="white-outline">
                <ArrowLeft class="h-4 w-4" />Retour au séjour
            </Button>
            <Button type="button" size="sm" @click="printSheet">
                <Printer class="h-4 w-4" />Imprimer
            </Button>
        </div>

        <article class="ds-sheet rounded-lg border border-border shadow-sm">
            <header class="ds-head">
                <div class="ds-band" />
                <div class="ds-logo">
                    <img v-if="legal.logo_url" :src="legal.logo_url" :alt="`Logo ${brand}`" />
                    <span v-else class="ds-logo-text">{{ brand }}</span>
                </div>
                <div class="ds-band" />
            </header>
            <p class="ds-title">FICHE DE RÉGIME</p>

            <table class="ds-info">
                <tbody>
                    <tr>
                        <th class="ds-strong">N° DE DOSSIER</th>
                        <td colspan="3">{{ stay.patient.patient_number }} <span class="ds-muted">· Passage {{ stay.episode.episode_number }}</span></td>
                    </tr>
                    <tr>
                        <th class="ds-name">Nom et Prénom</th>
                        <td colspan="3">{{ stay.patient.name }}</td>
                    </tr>
                    <tr>
                        <th class="ds-green">Allergie</th>
                        <td>{{ stay.allergies.join(', ') }}</td>
                        <th class="ds-green ds-narrow">Tabac (Oui/Non)</th>
                        <td class="ds-narrow-value">{{ smokerLabel }}</td>
                    </tr>
                    <tr>
                        <th>Motif d’hospitalisation</th>
                        <td colspan="3">{{ stay.request.reason }}</td>
                    </tr>
                </tbody>
            </table>

            <table class="ds-grid">
                <thead>
                    <tr>
                        <th rowspan="2" class="ds-col-day">Jour</th>
                        <th rowspan="2" class="ds-col-time">Heure</th>
                        <th colspan="4">Régime</th>
                        <th rowspan="2">Observation</th>
                    </tr>
                    <tr>
                        <th>Thé<br />Pain</th>
                        <th>Sosoa<br />Brochette</th>
                        <th>Yaourt</th>
                        <th>Puré</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.uuid">
                        <template v-if="row.blank">
                            <td v-for="n in 7" :key="n" />
                        </template>
                        <template v-else>
                            <td>{{ formatDate(row.served_on) }}</td>
                            <td>{{ row.served_time }}</td>
                            <td>{{ row.tea_bread }}</td>
                            <td>{{ row.sosoa_brochette }}</td>
                            <td>{{ row.yogurt }}</td>
                            <td>{{ row.puree }}</td>
                            <td>{{ row.observation }}</td>
                        </template>
                    </tr>
                </tbody>
            </table>

            <footer class="ds-foot">{{ footerParts.join(' – ') }}</footer>
        </article>
    </div>
</template>

<style>
.ds-sheet {
    --ds-blue: #00aeef;
    --ds-blue-soft: #b4c6e7;
    --ds-green: #c6e0b4;
    --ds-head: #bdd7ee;
    background: #fff;
    color: #000;
    font-family: Calibri, Carlito, Arial, Helvetica, sans-serif;
    font-size: 12px;
    line-height: 1.35;
    padding: 24px 26px 16px;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

.ds-head {
    display: grid;
    grid-template-columns: 1fr 2.2fr 1fr;
    border: 1px solid #000;
}

.ds-band {
    background: var(--ds-blue);
}

.ds-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 84px;
    padding: 6px;
    border-inline: 1px solid #000;
}

.ds-logo img {
    max-height: 96px;
    max-width: 100%;
    object-fit: contain;
}

.ds-logo-text {
    font-size: 20px;
    font-weight: 800;
}

.ds-title {
    margin: 0;
    padding: 5px;
    border: 1px solid #000;
    border-top: 0;
    font-size: 14px;
    font-weight: 700;
    text-align: center;
}

.ds-info,
.ds-grid {
    width: 100%;
    border-collapse: collapse;
}

.ds-info {
    margin-top: 18px;
}

.ds-info th,
.ds-info td {
    border: 1px solid #000;
    padding: 3px 8px;
}

.ds-info th {
    width: 22%;
    background: var(--ds-green);
    font-weight: 400;
    text-align: right;
    white-space: nowrap;
}

.ds-info .ds-strong {
    background: var(--ds-blue);
    font-weight: 700;
}

.ds-info .ds-name {
    background: var(--ds-blue-soft);
}

.ds-info .ds-narrow {
    width: 16%;
    text-align: center;
}

.ds-info .ds-narrow-value {
    width: 12%;
}

.ds-muted {
    color: #555;
    font-size: 10.5px;
}

.ds-grid {
    margin-top: 18px;
    table-layout: fixed;
}

.ds-grid th,
.ds-grid td {
    border: 1px solid #000;
    padding: 3px 5px;
    vertical-align: middle;
    word-wrap: break-word;
}

.ds-grid th {
    background: var(--ds-head);
    font-weight: 700;
    text-align: center;
}

.ds-grid .ds-col-day {
    width: 12%;
}

.ds-grid .ds-col-time {
    width: 10%;
}

.ds-grid tbody td {
    height: 34px;
}

.ds-foot {
    margin-top: 12px;
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
    .ds-actions {
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

    .ds-page {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .ds-sheet {
        padding: 0;
        border: 0 !important;
        box-shadow: none !important;
    }

    .ds-grid tr {
        break-inside: avoid;
    }
}
</style>
