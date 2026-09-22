<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PaperSheet from '@/Components/Clinical/PaperSheet.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { FileDown, Info, Layers } from 'lucide-vue-next';
import { formatDateTime } from '@/utilities/date';
import { pdfFileName, printAsPdf } from '@/utilities/pdfDownload';

defineOptions({ layout: AppLayout });

/**
 * ADR-172 — le « Dossier chirurgical » de la clinique, généré depuis les
 * données du bloc et de l'anesthésie.
 *
 * Quatre feuilles, chacune sur sa page, comme le papier : Entrée du patient au
 * bloc, Sortie du patient au bloc, Consultation pré-anesthésique, Examen
 * paraclinique. La page n'interprète rien : les libellés, les valeurs et les
 * droits sont composés par le serveur (`SurgicalDossierSheet`). Une feuille
 * refusée se nomme, jamais servie vide ; une case sans donnée reste vide.
 *
 * Le PDF est celui du navigateur (ADR-070) : une impression, un seul fichier.
 */
const props = defineProps({
    dossier: { type: Object, required: true },
    back: { type: Object, required: true },
});

const header = computed(() => props.dossier.header ?? {});
const patient = computed(() => header.value.patient ?? {});
const sheets = computed(() => props.dossier.sheets ?? []);
const single = computed(() => props.dossier.sheet ?? null);

const sexLabel = computed(() => ({ M: 'Masculin', F: 'Féminin' }[patient.value.sex] ?? ''));
const ageLabel = computed(() => (patient.value.age !== null && patient.value.age !== undefined
    ? `${patient.value.age} ans${patient.value.birth_date_is_approximate ? ' (âge déclaré)' : ''}`
    : ''));

const restricted = (label) => `Non visible avec vos droits (${label})`;

const fileName = computed(() => pdfFileName(
    single.value ? sheets.value[0]?.title ?? 'Dossier chirurgical' : 'Dossier chirurgical',
    header.value.episode?.episode_number,
    patient.value.name,
));
const downloadPdf = () => printAsPdf(fileName.value);

/** Les liens « une seule feuille » gardent l'origine (bloc / anesthésie) du retour. */
const from = computed(() => (props.back.href.startsWith('/anesthesia/') ? 'anesthesia' : 'surgery'));
const sheetHref = (key) => `/surgery/${props.dossier.uuid}/dossier${key ? `?feuille=${key}&from=${from.value}` : `?from=${from.value}`}`;

const toneClass = (tone) => ({ blue: 'ps-section-blue', green: 'ps-section-green', yellow: 'ps-section-yellow' }[tone] ?? 'ps-section-blue');
const labelClass = (tone) => ({ blue: 'ps-label-blue-soft', green: 'ps-label-green', yellow: 'ps-label-yellow' }[tone] ?? 'ps-label-blue-soft');

/** Un tableau sans aucune ligne s'écrit « Aucune ligne consignée » plutôt que de disparaître : l'absence se lit. */
const hasRows = (section) => Array.isArray(section.rows) && section.rows.length > 0;
</script>

<template>
    <div class="sd-doc">
        <aside class="sd-hint mx-auto flex w-full max-w-[64rem] flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card px-4 py-3 text-sm shadow-sm">
            <p class="flex items-start gap-2 text-muted-foreground">
                <Info class="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                <span>
                    <span class="font-semibold text-foreground">{{ single ? 'Une seule feuille.' : 'Quatre feuilles, chacune sur sa page.' }}</span>
                    Généré depuis ce que le bloc et l’anesthésie ont consigné : rien n’est ressaisi, une case vide est une case non renseignée.
                </span>
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <Button v-if="single" :as="Link" :href="sheetHref(null)" size="sm" variant="white-outline">
                    <Layers class="h-4 w-4" aria-hidden="true" />Tout le dossier
                </Button>
                <template v-else>
                    <Button
                        v-for="option in dossier.sheet_options"
                        :key="option.key"
                        :as="Link"
                        :href="sheetHref(option.key)"
                        size="sm"
                        variant="white-outline"
                    >
                        {{ option.title }}
                    </Button>
                </template>
                <Button type="button" size="sm" @click="downloadPdf">
                    <FileDown class="h-4 w-4" aria-hidden="true" />Télécharger le PDF
                </Button>
            </div>
        </aside>

        <PaperSheet
            v-for="(sheet, index) in sheets"
            :key="sheet.key"
            :page-title="`Dossier chirurgical — ${patient.name ?? ''}`"
            :document-title="`Dossier chirurgical — ${sheet.title}`"
            max-width-class="max-w-[64rem]"
            :back-href="index === 0 ? back.href : null"
            :back-label="back.label"
            :show-actions="index === 0"
        >
            <!-- En-tête du papier : identité, chirurgien, anesthésiste, hospitalisation — lus du dossier. -->
            <table class="ps-table sd-head">
                <tbody>
                    <tr>
                        <th class="ps-strong">N° DE DOSSIER</th>
                        <td>{{ patient.patient_number }}</td>
                        <th class="ps-label ps-label-blue-soft">Passage</th>
                        <td>{{ header.episode?.episode_number }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Nom et prénom</th>
                        <td>{{ patient.name }}</td>
                        <th class="ps-label ps-label-blue-soft">Âge · Sexe</th>
                        <td>{{ [ageLabel, sexLabel].filter(Boolean).join(' · ') }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Situation maritale</th>
                        <td>{{ patient.marital_status }}</td>
                        <th class="ps-label ps-label-blue-soft">Nombre d’enfants</th>
                        <td>{{ patient.children_count }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Intervention</th>
                        <td>{{ header.procedure }}<span v-if="header.procedure_details" class="ps-muted"> — {{ header.procedure_details }}</span></td>
                        <th class="ps-label ps-label-blue-soft">État du dossier</th>
                        <td>{{ header.status }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Chirurgien</th>
                        <td>{{ header.surgeon }}</td>
                        <th class="ps-label ps-label-blue-soft">Anesthésiste</th>
                        <td :class="header.anesthetist_visible ? undefined : 'ps-muted'">
                            {{ header.anesthetist_visible ? header.anesthetist : restricted('anesthesia.view') }}
                        </td>
                    </tr>
                    <tr v-if="!header.stay_visible">
                        <th class="ps-label ps-label-blue-soft">Hospitalisation</th>
                        <td colspan="3" class="ps-muted">{{ restricted('hospitalization.view') }}</td>
                    </tr>
                    <tr v-else>
                        <th class="ps-label ps-label-blue-soft">Entrée hospitalisation</th>
                        <td>{{ header.hospitalization?.admitted_at }}<span v-if="header.hospitalization?.service" class="ps-muted"> · {{ [header.hospitalization.service, header.hospitalization.room_bed].filter(Boolean).join(' · ') }}</span></td>
                        <th class="ps-label ps-label-blue-soft">Sortie</th>
                        <td>{{ header.hospitalization?.discharged_at }}</td>
                    </tr>
                </tbody>
            </table>

            <p v-if="sheet.restricted" class="ps-muted sd-restricted">{{ restricted(sheet.restricted) }}</p>

            <template v-for="section in sheet.sections" :key="`${sheet.key}-${section.title}`">
                <table v-if="section.type === 'rows'" class="ps-table sd-rows">
                    <thead>
                        <tr><th colspan="2" :class="['ps-section', toneClass(section.tone)]">{{ section.title.toLocaleUpperCase('fr') }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in section.rows" :key="row.label">
                            <th :class="['ps-label', labelClass(section.tone)]">{{ row.label }}</th>
                            <td class="sd-value">{{ row.value }}</td>
                        </tr>
                    </tbody>
                </table>

                <table v-else-if="section.type === 'table'" class="ps-table sd-grid">
                    <thead>
                        <tr><th :colspan="section.headers.length" :class="['ps-section', toneClass(section.tone)]">{{ section.title.toLocaleUpperCase('fr') }}</th></tr>
                        <tr v-if="hasRows(section)">
                            <th v-for="(head, headIndex) in section.headers" :key="headIndex" :class="['ps-label', labelClass(section.tone), 'sd-th']">{{ head }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, rowIndex) in section.rows" :key="rowIndex">
                            <td v-for="(cell, cellIndex) in row" :key="cellIndex" class="sd-value">{{ cell }}</td>
                        </tr>
                        <tr v-if="!hasRows(section)">
                            <td :colspan="section.headers.length" class="ps-muted sd-empty">Aucune ligne consignée.</td>
                        </tr>
                    </tbody>
                </table>
            </template>

            <p class="ps-muted sd-generated">Document édité le {{ formatDateTime(dossier.generated_at) }}.</p>
        </PaperSheet>
    </div>
</template>

<style>
.sd-doc {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.sd-head th.ps-label {
    width: 18%;
}

.sd-rows th.ps-label {
    width: 32%;
    white-space: normal;
}

.sd-grid .sd-th {
    text-align: left;
    white-space: normal;
}

.sd-value {
    min-height: 1.6em;
    white-space: pre-wrap;
}

.sd-empty {
    text-align: center;
}

.sd-restricted {
    margin: 16px 0 0;
    padding: 8px;
    border: 1px dashed #555;
    text-align: center;
}

.sd-generated {
    margin: 12px 0 0;
    text-align: right;
}

@media print {
    .sd-doc {
        display: block;
    }

    /* Une seule impression, un seul PDF : chaque feuille commence sa page. */
    .sd-doc > .ps-page + .ps-page {
        break-before: page;
        page-break-before: always;
    }

    .sd-hint {
        display: none !important;
    }
}
</style>
