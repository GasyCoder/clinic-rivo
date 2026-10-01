<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Barcode from '@/Components/Laboratory/Barcode.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { ArrowLeft, Printer, Tag, TestTube } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';
import { labUrl } from '@/utilities/labUrl';

defineOptions({ layout: AppLayout });

/**
 * ADR-214 — les étiquettes des prélèvements d'une demande : une par tube, avec
 * son code-barres (celui que la paillasse scanne), le patient, le type de
 * prélèvement et le tube. Deux supports : le rouleau d'une imprimante
 * d'étiquettes (50 × 25 mm, une étiquette par page) ou une planche A4 de
 * 24 étiquettes. Rien ne se saisit ici.
 */
const props = defineProps({
    labRequest: { type: Object, required: true },
    samples: { type: Array, default: () => [] },
});

const FORMATS = {
    roll: { label: 'Rouleau 50 × 25 mm', page: { width: 50, height: 25 }, cell: { width: 50, height: 25 }, columns: 1, perPage: 1, margin: 0, gap: 0 },
    a4: { label: 'Planche A4 · 24 étiquettes', page: { width: 210, height: 297 }, cell: { width: 63.5, height: 33.9 }, columns: 3, perPage: 24, margin: 13, gap: 2.5 },
};
const format = ref('roll');
const copies = ref(1);
const current = computed(() => FORMATS[format.value]);

const patient = computed(() => props.labRequest.patient);
const identity = computed(() => [
    patient.value.patient_number,
    patient.value.sex ? (patient.value.sex === 'M' ? 'H' : 'F') : null,
    patient.value.age !== null && patient.value.age !== undefined ? `${patient.value.age} ans` : null,
].filter(Boolean).join(' · '));

// « 28/09 20:57 » : l'année ne sert à rien sur un tube et ne tient pas sur 50 mm.
const shortDate = (value) => formatDateTime(value).replace(/^(\d{2}\/\d{2})\/\d{4}/, '$1');

const labels = computed(() => props.samples.flatMap((sample) => Array.from({ length: copies.value }, (_, copy) => ({ key: `${sample.uuid}-${copy}`, sample }))));
const pages = computed(() => {
    const size = current.value.perPage;
    const chunks = [];
    for (let index = 0; index < labels.value.length; index += size) chunks.push(labels.value.slice(index, index + size));
    return chunks;
});

const PAGE_STYLE_ID = 'rivo-lab-labels-page';
const applyPageRule = () => {
    if (typeof document === 'undefined') return;
    const style = document.getElementById(PAGE_STYLE_ID) ?? document.createElement('style');
    style.id = PAGE_STYLE_ID;
    style.textContent = `@page { size: ${current.value.page.width}mm ${current.value.page.height}mm; margin: 0; }`;
    document.head.appendChild(style);
};
onMounted(applyPageRule);
watch(format, applyPageRule);
onBeforeUnmount(() => document.getElementById(PAGE_STYLE_ID)?.remove());

const paperStyle = computed(() => ({
    width: `${current.value.page.width}mm`,
    height: `${current.value.page.height - 0.4}mm`,
    padding: `${current.value.margin}mm ${format.value === 'a4' ? 7.2 : 0}mm`,
}));
const gridStyle = computed(() => ({
    gridTemplateColumns: `repeat(${current.value.columns}, ${current.value.cell.width}mm)`,
    gridAutoRows: `${current.value.cell.height}mm`,
    columnGap: `${current.value.gap}mm`,
}));
const printLabels = () => window.print();
</script>

<template>
    <Head :title="`Étiquettes · ${labRequest.lab_number ?? formatPatientName(patient)}`" />

    <div class="lab-labels mx-auto w-full max-w-screen-lg space-y-4">
        <div class="lab-labels-actions space-y-4">
            <Button :as="Link" :href="labUrl(`/laboratory/requests/${labRequest.uuid}`)" variant="ghost" size="sm"><ArrowLeft class="h-4 w-4" /> Retour à la demande</Button>

            <Card class="flex flex-wrap items-start justify-between gap-4 p-4">
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><Tag class="h-6 w-6" /></span>
                    <div>
                        <h1 class="text-xl font-bold text-foreground">Étiquettes des prélèvements</h1>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            {{ formatPatientName(patient) }} · <span class="font-mono">{{ labRequest.lab_number }}</span> · {{ samples.length }} prélèvement(s)
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="inline-flex rounded-lg bg-muted p-1" role="group" aria-label="Support">
                        <Button
                            v-for="(option, key) in FORMATS"
                            :key="key"
                            type="button"
                            size="xs"
                            :variant="format === key ? 'secondary' : 'ghost'"
                            :aria-pressed="format === key"
                            class="shadow-none"
                            @click="format = key"
                        >{{ option.label }}</Button>
                    </div>
                    <div class="inline-flex items-center rounded-lg bg-muted p-1" role="group" aria-label="Exemplaires par prélèvement">
                        <span class="px-2 text-xs text-muted-foreground">Exemplaires</span>
                        <Button v-for="count in [1, 2, 3]" :key="count" type="button" size="xs" :variant="copies === count ? 'secondary' : 'ghost'" :aria-pressed="copies === count" class="shadow-none" @click="copies = count">{{ count }}</Button>
                    </div>
                    <Button type="button" size="sm" :disabled="!samples.length" @click="printLabels"><Printer class="h-4 w-4" /> Imprimer</Button>
                </div>
            </Card>
            <p class="text-xs text-muted-foreground">
                Dans la fenêtre d’impression, choisissez l’imprimante d’étiquettes, marges « Aucune » et échelle 100 %.
                Un prélèvement déclaré non conforme n’a plus d’étiquette.
            </p>
        </div>

        <Card v-if="!samples.length" class="p-8 text-center">
            <TestTube class="mx-auto h-8 w-8 text-muted-foreground/60" />
            <p class="mt-2 text-sm text-muted-foreground">Aucun prélèvement conforme à étiqueter : enregistrez-en un depuis la demande.</p>
        </Card>

        <div v-else class="lab-label-pages">
            <div v-for="(page, index) in pages" :key="index" class="lab-label-paper" :style="paperStyle">
                <div class="lab-label-grid" :style="gridStyle">
                    <div
                        v-for="label in page"
                        :key="label.key"
                        :class="cn('lab-label', format === 'a4' && 'lab-label--sheet')"
                        :style="{ width: `${current.cell.width}mm`, height: `${current.cell.height}mm` }"
                    >
                        <div class="lab-label-head">
                            <strong class="lab-label-name">{{ formatPatientName(patient) }}</strong>
                            <span v-if="label.sample.tube" class="lab-label-tube">
                                <span class="lab-label-dot" :style="{ backgroundColor: label.sample.tube.hex || '#fff' }" />
                                {{ label.sample.tube.code }}
                            </span>
                        </div>
                        <p class="lab-label-line">{{ identity }} · {{ shortDate(label.sample.collected_at) }}</p>
                        <p class="lab-label-line"><strong>{{ label.sample.sample_type }}</strong></p>
                        <Barcode :value="label.sample.barcode" :height="format === 'a4' ? 44 : 34" class="lab-label-code" />
                    </div>
                </div>
            </div>
        </div>

        <div class="lab-labels-actions flex flex-wrap gap-2">
            <Badge v-for="sample in samples" :key="sample.uuid" variant="outline" class="font-mono">{{ sample.barcode }} · {{ sample.sample_type }}</Badge>
        </div>
    </div>
</template>

<style>
.lab-label-pages {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1rem;
    overflow-x: auto;
}

.lab-label-paper {
    box-sizing: border-box;
    flex: none;
    overflow: hidden;
    background: #fff;
    color: #000;
    box-shadow: 0 8px 24px -12px rgb(15 23 42 / 0.35);
    print-color-adjust: exact;
    -webkit-print-color-adjust: exact;
}

.lab-label-grid {
    display: grid;
    align-content: start;
}

.lab-label {
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    overflow: hidden;
    padding: 1.2mm 2mm 0.8mm;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 6.5pt;
    line-height: 1.15;
    break-inside: avoid;
}

.lab-label--sheet {
    outline: 0.2mm dashed #cbd5e1;
    font-size: 7.5pt;
    padding: 2mm 3mm 1mm;
}

.lab-label-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1mm;
}

.lab-label-name {
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    font-size: 7.5pt;
}

.lab-label--sheet .lab-label-name {
    font-size: 8.5pt;
}

.lab-label-tube {
    display: inline-flex;
    flex: none;
    align-items: center;
    gap: 0.8mm;
    font-weight: 700;
}

.lab-label-dot {
    display: inline-block;
    width: 2.4mm;
    height: 2.4mm;
    border: 0.2mm solid #000;
    border-radius: 999px;
}

.lab-label-line {
    margin: 0;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.lab-label-code figcaption {
    font-size: 6.5pt !important;
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
    .lab-labels-actions {
        display: none !important;
    }

    .nk-wrap,
    .nk-content,
    .lab-labels {
        min-height: 0 !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .lab-labels > * {
        margin-top: 0 !important;
    }

    .lab-label-pages {
        display: block;
        overflow: visible;
    }

    .lab-label-paper {
        box-shadow: none;
        break-after: page;
    }

    .lab-label-paper:last-child {
        break-after: auto;
    }

    .lab-label--sheet {
        outline: none;
    }
}
</style>
