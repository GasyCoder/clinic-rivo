<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { ArrowLeft, ClipboardList, ExternalLink, Printer, Siren } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';
import { LAB_STATUS_TONES } from '@/utilities/labWorkbench';
import { labUrl } from '@/utilities/labUrl';

defineOptions({ layout: AppLayout });

/**
 * ADR-214 — la feuille de paillasse : ce qui reste à analyser ici, une feuille
 * par discipline, urgences d'abord. On l'imprime pour travailler au poste, puis
 * on saisit à l'écran. Seules les demandes reçues y figurent ; une analyse
 * confiée à l'extérieur se suit sur son bon d'envoi.
 */
const props = defineProps({
    sheets: { type: Array, default: () => [] },
    disciplines: { type: Array, default: () => [] },
    discipline: { type: String, default: null },
    printedAt: { type: String, default: null },
});

const total = computed(() => props.disciplines.reduce((sum, row) => sum + row.count, 0));
const select = (name) => router.get(labUrl('/laboratory/paillasse'), name ? { discipline: name } : {}, { preserveScroll: true, preserveState: true, replace: true });
const printSheets = () => window.print();

/*
 * Cinq colonnes, dont la place laissée aux résultats, tiennent en paysage. La
 * règle est injectée au montage et retirée en quittant : une règle `@page`
 * écrite dans le style resterait chargée et mettrait toutes les autres feuilles
 * en paysage (même idiome que le tour de salle, ADR-165).
 */
const PAGE_STYLE_ID = 'rivo-lab-worklist-page';
onMounted(() => {
    const style = document.getElementById(PAGE_STYLE_ID) ?? document.createElement('style');
    style.id = PAGE_STYLE_ID;
    style.textContent = '@page { size: A4 landscape; margin: 10mm; }';
    document.head.appendChild(style);
});
onBeforeUnmount(() => document.getElementById(PAGE_STYLE_ID)?.remove());

const identity = (patient) => [
    patient.patient_number,
    patient.sex ? (patient.sex === 'M' ? 'H' : 'F') : null,
    patient.age !== null && patient.age !== undefined ? `${patient.age} ans` : null,
].filter(Boolean).join(' · ');
</script>

<template>
    <Head title="Feuille de paillasse" />

    <div class="lab-worklist w-full space-y-4">
        <div class="lab-worklist-actions space-y-4">
            <Button :as="Link" :href="labUrl('/laboratory')" variant="ghost" size="sm"><ArrowLeft class="h-4 w-4" /> File du laboratoire</Button>

            <header class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><ClipboardList class="h-6 w-6" /></span>
                    <div>
                        <h1 class="font-heading text-2xl font-bold -tracking-snug text-foreground">Feuille de paillasse</h1>
                        <p class="mt-1 text-sm text-muted-foreground">{{ total }} analyse(s) à faire au laboratoire, rangées par discipline — urgences d’abord.</p>
                    </div>
                </div>
                <Button type="button" size="sm" :disabled="!sheets.length" @click="printSheets"><Printer class="h-4 w-4" /> Imprimer {{ discipline ? 'cette feuille' : 'les feuilles' }}</Button>
            </header>

            <div class="flex flex-wrap gap-1.5" role="group" aria-label="Discipline">
                <Button type="button" size="xs" :variant="!discipline ? 'secondary' : 'outline'" :aria-pressed="!discipline" class="rounded-full" @click="select(null)">
                    Toutes <strong class="tabular-nums">{{ total }}</strong>
                </Button>
                <Button
                    v-for="row in disciplines"
                    :key="row.name"
                    type="button"
                    size="xs"
                    :variant="discipline === row.name ? 'secondary' : 'outline'"
                    :aria-pressed="discipline === row.name"
                    class="rounded-full"
                    @click="select(row.name)"
                >
                    {{ row.name }} <strong class="tabular-nums">{{ row.count }}</strong>
                </Button>
            </div>
        </div>

        <Card v-if="!sheets.length" class="p-10 text-center">
            <ClipboardList class="mx-auto h-8 w-8 text-muted-foreground/60" />
            <p class="mt-2 text-sm text-muted-foreground">Rien à analyser au laboratoire pour l’instant.</p>
        </Card>

        <section v-for="sheet in sheets" :key="sheet.discipline" class="lab-worksheet">
            <Card class="overflow-hidden">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3">
                    <h2 class="text-base font-bold text-foreground">{{ sheet.discipline }}</h2>
                    <p class="text-xs text-muted-foreground">{{ sheet.count }} analyse(s) · {{ sheet.rows.length }} demande(s)<template v-if="printedAt"> · établie le {{ formatDateTime(printedAt) }}</template></p>
                </header>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[46rem] border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-border bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <th class="w-10 px-3 py-2">N°</th>
                                <th class="w-56 px-3 py-2">Patient</th>
                                <th class="w-44 px-3 py-2">Tubes</th>
                                <th class="px-3 py-2">Analyses</th>
                                <th class="lab-worklist-print w-48 px-3 py-2">Résultats · notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in sheet.rows" :key="row.request_uuid" :class="cn('border-b border-border align-top last:border-0', row.emergency && 'bg-destructive/5')">
                                <td class="px-3 py-2.5 font-semibold tabular-nums text-muted-foreground">{{ index + 1 }}</td>
                                <td class="px-3 py-2.5">
                                    <p class="flex items-center gap-1.5 font-bold text-foreground">
                                        <Siren v-if="row.emergency" class="h-3.5 w-3.5 shrink-0 text-destructive" aria-label="Urgence" />
                                        {{ formatPatientName(row.patient) }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">{{ identity(row.patient) }}</p>
                                    <p class="font-mono text-xs font-semibold text-foreground">{{ row.lab_number }}</p>
                                    <p v-if="row.notes" class="mt-1 text-xs italic text-muted-foreground">{{ row.notes }}</p>
                                </td>
                                <td class="px-3 py-2.5">
                                    <ul class="space-y-0.5">
                                        <li v-for="sample in row.samples" :key="sample.barcode" class="flex items-center gap-1.5 font-mono text-xs">
                                            <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full border border-border" :style="{ backgroundColor: sample.hex || 'transparent' }" aria-hidden="true" />
                                            {{ sample.barcode }}<template v-if="sample.tube"> · {{ sample.tube }}</template>
                                        </li>
                                        <li v-if="!row.samples.length" class="text-xs text-amber-700 dark:text-amber-300">Aucun prélèvement</li>
                                    </ul>
                                </td>
                                <td class="px-3 py-2.5">
                                    <div v-for="item in row.items" :key="item.uuid" class="mb-1.5 last:mb-0">
                                        <p class="flex flex-wrap items-center gap-1.5">
                                            <span class="font-semibold text-foreground">{{ item.name }}</span>
                                            <Badge :tone="LAB_STATUS_TONES[item.status]" class="lab-worklist-screen">{{ item.status_label }}</Badge>
                                        </p>
                                        <p v-if="item.analyses.length" class="text-[11px] text-muted-foreground">{{ item.analyses.join(' · ') }}</p>
                                    </div>
                                    <Link :href="labUrl(`/laboratory/requests/${row.request_uuid}`)" class="lab-worklist-screen mt-1 inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
                                        Saisir <ExternalLink class="h-3 w-3" />
                                    </Link>
                                </td>
                                <td class="lab-worklist-print px-3 py-2.5" />
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </section>
    </div>
</template>

<style>
.lab-worklist-print {
    display: none;
}

@media print {
    .nk-sidebar,
    .nk-header,
    .nk-footer,
    .lab-worklist-actions,
    .lab-worklist-screen {
        display: none !important;
    }

    .nk-wrap,
    .nk-content,
    .lab-worklist {
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .lab-worklist-print {
        display: table-cell;
        border-left: 1px solid #000;
    }

    .lab-worksheet {
        break-after: page;
    }

    .lab-worksheet:last-child {
        break-after: auto;
    }

    .lab-worksheet table {
        min-width: 0 !important;
    }

    .lab-worksheet td,
    .lab-worksheet th {
        border: 1px solid #000 !important;
        color: #000 !important;
    }
}
</style>
