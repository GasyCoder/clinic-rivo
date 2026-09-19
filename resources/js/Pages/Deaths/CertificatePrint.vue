<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { ArrowLeft, Printer } from 'lucide-vue-next';
import { formatDate } from '@/utilities/date';

defineOptions({ layout: AppLayout });

/**
 * Le « Certificat médical de constatation de décès » de la clinique
 * (ADR-107, amendement du 2026-09-18).
 *
 * Il reproduit la feuille papier, case pour case : N° de dossier, médecin
 * traitant, informations relatives au défunt, date, heure et lieu du décès,
 * causes, puis les signatures. Il atteste ce que le médecin a constaté ; ce
 * n'est pas l'acte d'état civil, qui relève de la commune.
 *
 * Les couleurs sont celles du papier, écrites en dur : elles décrivent une
 * feuille imprimée, pas l'interface (même choix que l'ADR-108).
 */
const props = defineProps({
    episode: { type: Object, required: true },
    patient: { type: Object, required: true },
    record: { type: Object, required: true },
});

const page = usePage();
const brand = computed(() => page.props.site?.brand ?? 'Clinique Saint Georges');
const legal = computed(() => page.props.site?.documents ?? {});

const fullName = computed(() => `${props.patient.last_name ?? ''} ${props.patient.first_name ?? ''}`.trim());

/** Une date déclarée n'est pas une date de naissance : on dit l'âge. */
const birthLabel = computed(() => {
    if (props.patient.birth_date && !props.patient.birth_date_is_approximate) {
        return formatDate(props.patient.birth_date);
    }

    return props.patient.age !== null && props.patient.age !== undefined
        ? `${props.patient.age} ans (âge déclaré)`
        : '';
});

const sexLabel = computed(() => ({ M: 'M', F: 'F' })[props.patient.sex] ?? '');

const deathDate = computed(() => (props.record.death_occurred_at ? formatDate(props.record.death_occurred_at) : ''));
const deathTime = computed(() => {
    if (!props.record.death_occurred_at) return '';
    const date = new Date(props.record.death_occurred_at);

    return Number.isNaN(date.getTime())
        ? ''
        : date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }).replace(':', ' h ');
});

const signedOn = computed(() => (props.record.constated_at ? formatDate(props.record.constated_at) : ''));

const footerParts = computed(() => [
    brand.value.toLocaleUpperCase('fr'),
    legal.value.email,
    legal.value.phone ? `Tél : ${legal.value.phone}` : null,
].filter(Boolean));

const printSheet = () => window.print();
</script>

<template>
    <Head :title="`Certificat de décès — ${fullName}`" />

    <div class="dc-page mx-auto w-full max-w-[52rem] space-y-3">
        <div class="dc-actions flex flex-wrap items-center justify-between gap-3">
            <Button :as="Link" href="/deces" size="sm" variant="white-outline">
                <ArrowLeft class="h-4 w-4" />Retour au registre des décès
            </Button>
            <Button type="button" size="sm" @click="printSheet">
                <Printer class="h-4 w-4" />Imprimer
            </Button>
        </div>

        <article class="dc-sheet rounded-lg border border-border shadow-sm">
            <header class="dc-head">
                <div class="dc-band" />
                <div class="dc-logo">
                    <img v-if="legal.logo_url" :src="legal.logo_url" :alt="`Logo ${brand}`" />
                    <span v-else class="dc-logo-text">{{ brand }}</span>
                </div>
                <div class="dc-band" />
            </header>
            <p class="dc-title">CERTIFICAT MÉDICAL DE CONSTATATION DE DÉCÈS</p>

            <table class="dc-table dc-file">
                <tbody>
                    <tr>
                        <th class="dc-blue">N° DE DOSSIER</th>
                        <td>{{ patient.patient_number }} <span class="dc-muted">· Passage {{ episode.episode_number }}</span></td>
                    </tr>
                </tbody>
            </table>

            <table class="dc-table">
                <thead>
                    <tr><th colspan="2" class="dc-section dc-section-blue">MÉDECIN TRAITANT</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <th class="dc-label-blue">Nom et Prénom</th>
                        <td>Dr {{ record.constated_by }}</td>
                    </tr>
                </tbody>
            </table>

            <!-- Douze colonnes égales : chaque ligne de la feuille découpe la
                 largeur à sa façon (le lieu de décès est large, le sexe
                 étroit), et une grille commune le permet sans rogner une case. -->
            <table class="dc-table">
                <colgroup>
                    <col v-for="n in 12" :key="n" class="dc-col-12" />
                </colgroup>
                <thead>
                    <tr><th colspan="12" class="dc-section dc-section-green">INFORMATIONS RELATIVES AU DÉFUNT</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <th colspan="2" class="dc-label">Nom et Prénom</th>
                        <td colspan="10">{{ fullName }}</td>
                    </tr>
                    <tr>
                        <th colspan="2" class="dc-label">Date de Naissance</th>
                        <td colspan="2">{{ birthLabel }}</td>
                        <th class="dc-label">Lieu</th>
                        <td colspan="4">{{ patient.birth_place }}</td>
                        <th colspan="2" class="dc-label">Sexe (M/F)</th>
                        <td class="dc-center">{{ sexLabel }}</td>
                    </tr>
                    <tr>
                        <th colspan="2" class="dc-label">Adresse</th>
                        <td colspan="10">{{ patient.address }}</td>
                    </tr>
                    <tr>
                        <th colspan="2" class="dc-label">Fils / Fille de</th>
                        <td colspan="4">{{ record.father_name }}</td>
                        <th class="dc-label">et de</th>
                        <td colspan="5">{{ record.mother_name }}</td>
                    </tr>
                    <tr>
                        <th colspan="2" class="dc-label">CNI N°</th>
                        <td colspan="3">{{ record.identity_document_number }}</td>
                        <th colspan="2" class="dc-label">délivrée le</th>
                        <td colspan="2">{{ record.identity_document_issued_on ? formatDate(record.identity_document_issued_on) : '' }}</td>
                        <th class="dc-label">à</th>
                        <td colspan="2">{{ record.identity_document_issued_place }}</td>
                    </tr>
                    <tr>
                        <th colspan="2" class="dc-label">Date de décès</th>
                        <td colspan="2">{{ deathDate }}</td>
                        <th class="dc-label">vers</th>
                        <td colspan="2">{{ deathTime }}</td>
                        <th class="dc-label">à</th>
                        <td colspan="4">{{ record.death_place }}</td>
                    </tr>
                    <tr class="dc-tall">
                        <th colspan="2" class="dc-label">Causes du décès</th>
                        <!-- HTML assaini par le serveur (même liste blanche que
                             l'interrogatoire) : la mise en forme s'imprime,
                             jamais une balise en clair. -->
                        <td colspan="10" class="dc-rich" v-html="record.death_causes_html" />
                    </tr>
                    <tr v-if="record.observations_html">
                        <th colspan="2" class="dc-label">Observations</th>
                        <td colspan="10" class="dc-rich" v-html="record.observations_html" />
                    </tr>
                </tbody>
            </table>

            <p class="dc-statement">
                Le présent certificat est délivré à la famille du défunt pour servir et valoir ce que de droit.
            </p>

            <table class="dc-table dc-signatures">
                <colgroup>
                    <col class="dc-col-label" />
                    <col />
                    <col class="dc-col-sign" />
                </colgroup>
                <thead>
                    <tr><th colspan="3" class="dc-section dc-section-yellow">SIGNATURES</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <th class="dc-label-yellow">Date</th>
                        <td>{{ signedOn }}</td>
                        <td rowspan="2" class="dc-sign">
                            Le médecin traitant,
                            <span class="dc-sign-name">Dr {{ record.constated_by }}</span>
                        </td>
                    </tr>
                    <tr>
                        <th class="dc-label-yellow">Lieu</th>
                        <td>{{ record.signed_place }}</td>
                    </tr>
                </tbody>
            </table>

            <footer class="dc-foot">{{ footerParts.join(' – ') }}</footer>
        </article>
    </div>
</template>

<style>
.dc-sheet {
    --dc-blue: #00aeef;
    --dc-blue-soft: #b4c6e7;
    --dc-green: #c6e0b4;
    --dc-yellow: #ffe699;
    background: #fff;
    color: #000;
    font-family: Calibri, Carlito, Arial, Helvetica, sans-serif;
    font-size: 12px;
    line-height: 1.35;
    padding: 24px 26px 16px;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

.dc-head {
    display: grid;
    grid-template-columns: 1fr 2.2fr 1fr;
    border: 1px solid #000;
}

.dc-band {
    background: var(--dc-blue);
}

.dc-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 84px;
    padding: 6px;
    border-inline: 1px solid #000;
}

.dc-logo img {
    max-height: 96px;
    max-width: 100%;
    object-fit: contain;
}

.dc-logo-text {
    font-size: 20px;
    font-weight: 800;
}

.dc-title {
    margin: 0;
    padding: 5px;
    border: 1px solid #000;
    border-top: 0;
    font-size: 14px;
    font-weight: 700;
    text-align: center;
}

.dc-table {
    width: 100%;
    margin-top: 16px;
    border-collapse: collapse;
    table-layout: fixed;
}

.dc-table th,
.dc-table td {
    border: 1px solid #000;
    padding: 4px 8px;
    vertical-align: middle;
    word-wrap: break-word;
}

.dc-table td {
    min-height: 22px;
}

.dc-file th {
    width: 22%;
}

.dc-section {
    font-weight: 700;
    text-align: center;
}

.dc-section-blue { background: var(--dc-blue-soft); }
.dc-section-green { background: var(--dc-green); }
.dc-section-yellow { background: var(--dc-yellow); }

.dc-blue {
    background: var(--dc-blue);
    font-weight: 700;
    text-align: center;
}

.dc-label,
.dc-label-blue,
.dc-label-yellow {
    font-weight: 400;
    text-align: right;
}

.dc-label { background: var(--dc-green); }
.dc-label-blue { width: 22%; background: var(--dc-blue-soft); }
.dc-label-yellow { background: var(--dc-yellow); font-weight: 700; text-align: left; }

.dc-col-12 { width: 8.333%; }
.dc-col-label { width: 18%; }
.dc-col-sign { width: 40%; }

.dc-center { text-align: center; }

.dc-muted {
    color: #555;
    font-size: 10.5px;
}

.dc-tall td {
    height: 96px;
    vertical-align: top;
}

.dc-rich {
    white-space: pre-wrap;
    vertical-align: top;
}

.dc-rich p { margin: 0 0 2px; }
.dc-rich ul { list-style: disc; margin: 2px 0; padding-inline-start: 18px; }
.dc-rich ol { list-style: decimal; margin: 2px 0; padding-inline-start: 18px; }
.dc-rich mark { background: #fef08a; }

.dc-statement {
    margin: 18px 0 0;
}

.dc-signatures tbody tr {
    height: 44px;
}

.dc-sign {
    vertical-align: top !important;
}

.dc-sign-name {
    display: block;
    margin-top: 44px;
    font-weight: 700;
}

.dc-foot {
    margin-top: 28px;
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
    .dc-actions {
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

    .dc-page {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .dc-sheet {
        padding: 0;
        border: 0 !important;
        box-shadow: none !important;
    }
}
</style>
