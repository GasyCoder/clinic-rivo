<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { formatDate } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';

/**
 * Le compte rendu d'imagerie, tel que la clinique le remet (ADR-108).
 *
 * Il reproduit la feuille papier « RÉSULTATS D'ÉCHOGRAPHIE » : bandeau au
 * logo, N° de dossier, identité, titre de l'examen, compte rendu en deux
 * colonnes, N.B., « Fait le » et « Le médecin responsable », coordonnées de la
 * clinique. Un seul composant pour l'impression et pour l'affichage à
 * l'écran : ce que le médecin relit est ce que la famille emporte.
 *
 * Les couleurs sont celles du papier, écrites en dur : elles décrivent une
 * feuille imprimée, pas l'interface — le mode sombre n'a rien à y changer.
 *
 * Rien n'est composé ici : le document reprend ce que le serveur a préparé
 * (`App\Support\ImagingReportDocument`).
 */
const props = defineProps({
    document: { type: Object, required: true },
});

const page = usePage();
const brand = computed(() => page.props.site?.brand ?? 'Clinique Saint Georges');
const legal = computed(() => page.props.site?.documents ?? {});

const patient = computed(() => props.document.patient);

/** Une date déclarée n'est pas une date de naissance : on dit l'âge. */
const birthLabel = computed(() => {
    if (patient.value.birth_date && !patient.value.birth_date_is_approximate) {
        return formatDate(patient.value.birth_date);
    }

    return patient.value.age !== null && patient.value.age !== undefined
        ? `${patient.value.age} ans (âge déclaré)`
        : 'Non renseignée';
});

/**
 * Les feuilles de la clinique : deux colonnes, puis des cases pleine largeur
 * (Conclusion, N.B.). Le serveur les a déjà découpées ; un texte libre n'a
 * qu'une région et garde l'ancien flux en colonnes.
 */
const regions = computed(() => props.document.regions ?? [props.document.value ?? '']);
const wideRegions = computed(() => regions.value.slice(2).filter((region) => region !== ''));

const footerParts = computed(() => [
    brand.value.toLocaleUpperCase('fr'),
    legal.value.email,
    legal.value.phone ? `Tél : ${legal.value.phone}` : null,
].filter(Boolean));
</script>

<template>
    <article class="rd-sheet">
        <header class="rd-head">
            <div class="rd-band" />
            <div class="rd-logo">
                <img v-if="legal.logo_url" :src="legal.logo_url" :alt="`Logo ${brand}`" />
                <span v-else class="rd-logo-text">{{ brand }}</span>
            </div>
            <div class="rd-band" />
        </header>
        <p class="rd-title">{{ document.title }}</p>

        <table class="rd-grid rd-file">
            <tbody>
                <tr>
                    <th class="rd-label-strong">N° DE DOSSIER</th>
                    <td>
                        {{ patient.patient_number }}
                        <span class="rd-muted">· Passage {{ document.episode_number }}</span>
                    </td>
                </tr>
            </tbody>
        </table>

        <table class="rd-grid rd-identity">
            <tbody>
                <tr>
                    <th>Nom et Prénom</th>
                    <td colspan="3">{{ formatPatientName(patient) }}</td>
                </tr>
                <tr>
                    <th>Date de Naissance</th>
                    <td>{{ birthLabel }}</td>
                    <th class="rd-narrow">Sexe (M/F)</th>
                    <td class="rd-narrow-value">{{ patient.sex }}</td>
                </tr>
                <tr>
                    <th>Adresse</th>
                    <td colspan="3">{{ patient.address || '—' }}</td>
                </tr>
            </tbody>
        </table>

        <section class="rd-exam">
            <!-- Le titre de la feuille est celui du papier, casse comprise (« 1er ») ;
                 le nom d'un examen du catalogue, lui, se met en capitales. -->
            <p :class="['rd-exam-title', { 'rd-upper': !document.sheet_title }]">{{ document.sheet_title || document.exam }}</p>
            <!-- Déjà assaini côté serveur (ClinicalRichTextSanitizer) : mise en
                 forme seulement, ni lien, ni média, ni script. -->
            <div v-if="regions.length < 2" class="rd-exam-body rd-cell" v-html="document.value" />
            <template v-else>
                <div class="rd-cols">
                    <div class="rd-cell" v-html="regions[0]" />
                    <div class="rd-cell rd-cell-right" v-html="regions[1]" />
                </div>
                <div v-for="(region, index) in wideRegions" :key="index" class="rd-cell rd-wide" v-html="region" />
            </template>
        </section>

        <section v-if="document.notes" class="rd-box">
            <p class="rd-box-label">Observations complémentaires :</p>
            <div class="rd-rich" v-html="document.notes" />
        </section>

        <table class="rd-grid rd-sign">
            <tbody>
                <tr>
                    <td>Fait le {{ formatDate(document.resulted_at) }}</td>
                    <td>
                        Le médecin responsable :
                        <strong v-if="document.resulted_by">Dr {{ document.resulted_by }}</strong>
                    </td>
                </tr>
            </tbody>
        </table>

        <footer class="rd-foot">{{ footerParts.join(' – ') }}</footer>
    </article>
</template>

<style>
.rd-sheet {
    --rd-blue: #00aeef;
    --rd-blue-soft: #b4c6e7;
    background: #fff;
    color: #000;
    font-family: Calibri, Carlito, Arial, Helvetica, sans-serif;
    font-size: 12.5px;
    line-height: 1.45;
    padding: 28px 30px 18px;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

.rd-head {
    display: grid;
    grid-template-columns: 1fr 2.2fr 1fr;
    border: 1px solid #000;
}

.rd-band {
    background: var(--rd-blue);
}

.rd-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 92px;
    padding: 6px;
    border-inline: 1px solid #000;
}

.rd-logo img {
    max-height: 104px;
    max-width: 100%;
    object-fit: contain;
}

.rd-logo-text {
    font-size: 22px;
    font-weight: 800;
}

.rd-title {
    margin: 0;
    padding: 6px;
    border: 1px solid #000;
    border-top: 0;
    font-size: 15px;
    font-weight: 700;
    text-align: center;
    text-transform: uppercase;
}

.rd-grid {
    width: 100%;
    border-collapse: collapse;
}

.rd-grid th,
.rd-grid td {
    border: 1px solid #000;
    padding: 3px 8px;
    vertical-align: middle;
}

.rd-grid th {
    width: 22%;
    background: var(--rd-blue-soft);
    font-weight: 700;
    text-align: right;
    white-space: nowrap;
}

.rd-file {
    margin-top: 20px;
}

.rd-file .rd-label-strong {
    background: var(--rd-blue);
    text-align: center;
    padding: 5px 8px;
}

.rd-identity {
    margin-top: 20px;
}

.rd-identity .rd-narrow {
    width: 14%;
    text-align: center;
}

.rd-identity .rd-narrow-value {
    width: 18%;
}

.rd-muted {
    color: #555;
    font-size: 11px;
}

.rd-exam {
    margin-top: 20px;
    border: 1px solid #000;
}

.rd-exam-title {
    margin: 0;
    padding: 5px 8px;
    background: var(--rd-blue);
    border-bottom: 1px solid #000;
    font-size: 13.5px;
    font-weight: 700;
    text-align: center;
}

.rd-upper {
    text-transform: uppercase;
}

/* Saisie libre : deux colonnes séparées d'un filet, comme la feuille papier.
   Une rubrique ne se sépare jamais de ses lignes. */
.rd-exam-body {
    column-count: 2;
    column-gap: 0;
    column-rule: 1px solid #000;
    min-height: 120px;
}

/* Feuille de la clinique : la colonne de gauche et celle de droite sont
   deux cases de même hauteur, et le contenu de chacune est celui du papier. */
.rd-cols {
    display: grid;
    grid-template-columns: 1fr 1fr;
}

.rd-cell-right {
    border-inline-start: 1px solid #000;
}

.rd-wide {
    border-top: 1px solid #000;
    min-height: 64px;
}

.rd-cell {
    padding: 6px 9px;
}

.rd-exam-body.rd-cell {
    padding: 0;
}

.rd-cell > *,
.rd-exam-body > * {
    margin: 0;
}

.rd-exam-body > * {
    padding-inline: 9px;
}

.rd-exam-body > :first-child {
    padding-top: 6px;
}

.rd-cell p {
    margin: 0;
}

.rd-cell p:has(> strong:only-child) {
    margin-top: 10px;
    break-after: avoid-column;
}

.rd-cell > p:first-child:has(> strong:only-child) {
    margin-top: 0;
}

.rd-cell ul,
.rd-cell ol {
    margin: 0;
    padding-inline-start: 26px;
    break-inside: avoid-column;
}

/* Le reset de l'application retire les puces ; la feuille papier les porte. */
.rd-cell ul {
    list-style-type: disc;
}

.rd-cell ol {
    list-style-type: decimal;
}

.rd-cell ul ul {
    list-style-type: circle;
    padding-inline-start: 22px;
}

.rd-cell li {
    margin: 0;
}

.rd-box {
    min-height: 70px;
    padding: 6px 8px;
    border: 1px solid #000;
    border-top: 0;
}

.rd-box-label {
    margin: 0 0 2px;
    font-weight: 700;
}

.rd-rich p {
    margin: 0 0 4px;
}

.rd-sign td {
    width: 50%;
    height: 72px;
    vertical-align: top;
    padding-top: 6px;
}

.rd-sign {
    border-top: 0;
}

.rd-exam + .rd-sign td,
.rd-box + .rd-sign td {
    border-top: 0;
}

.rd-foot {
    margin-top: 10px;
    padding-top: 4px;
    border-top: 1px solid #000;
    font-size: 11px;
    text-align: center;
}

@media print {
    .rd-sheet {
        padding: 0;
    }

    .rd-exam,
    .rd-cols,
    .rd-wide,
    .rd-box,
    .rd-sign {
        break-inside: avoid;
    }
}
</style>
