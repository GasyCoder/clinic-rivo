<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, IdCard, Printer, RectangleHorizontal, RectangleVertical, Scissors, Shapes, TriangleAlert, Users } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Switch from '@/Components/Shadcn/Switch.vue';
import SegmentedField from '@/Components/Settings/SegmentedField.vue';
import EmployeeBadge from '@/Components/Administration/EmployeeBadge.vue';
import { cn } from '@/lib/cn';
import {
    BADGE_CHOICES, BADGE_DEFAULT_DESIGN, BADGE_PAPER_LABELS, MM_TO_PX, badgeSheetLayout, chunk, formatMm,
} from '@/utilities/employeeBadge';

defineOptions({ layout: AppLayout });

/**
 * ADR-209 — les badges à imprimer : celui d'une personne, ou une planche pour
 * les employés (ou stagiaires) affichés ou cochés dans leur liste.
 *
 * Les pages se voient telles qu'elles sortiront de l'imprimante : le papier
 * (A4, A5, A3, Lettre US, ou une carte par page pour une imprimante à badges),
 * son orientation, les marges et l'espacement réglés pour le site. Le RH peut
 * changer le papier pour cette impression sans toucher au réglage. Les badges
 * sont au format réel : imprimer à 100 %, sans « ajuster à la page ».
 */
const props = defineProps({
    badges: { type: Array, default: () => [] },
    design: { type: Object, default: () => ({}) },
    source: { type: Object, default: () => ({}) },
    /** `card` : une carte par page d'emblée ; sinon, le papier réglé pour le site. */
    layout: { type: String, default: null },
    refusal: { type: String, default: null },
    limit: { type: Number, default: 300 },
});

const defaults = computed(() => ({ ...BADGE_DEFAULT_DESIGN.print, ...(props.design?.print ?? {}) }));
const paper = ref(props.layout === 'card' ? 'CARD' : defaults.value.paper);
const paperOrientation = ref(defaults.value.orientation);
const cutMarks = ref(defaults.value.cut_marks !== false);

const sheet = computed(() => badgeSheetLayout({
    paper: paper.value,
    paperOrientation: paperOrientation.value,
    margin: Number(defaults.value.margin) || 0,
    gap: Number(defaults.value.gap) || 0,
    cardSize: props.design?.card_size,
    orientation: props.design?.orientation,
}));
/** Une carte qui ne tient pas sur la page part seule sur sa page, plutôt que de disparaître. */
const perPage = computed(() => Math.max(1, sheet.value.perPage));
const pages = computed(() => chunk(props.badges, perPage.value));
const count = computed(() => props.badges.length);

const paperOptions = BADGE_CHOICES.badge_paper.map((value) => ({
    value,
    label: value === 'CARD' ? 'Carte seule — une par page' : BADGE_PAPER_LABELS[value],
}));
const ORIENTATIONS = [
    { value: 'PORTRAIT', label: 'Portrait', icon: RectangleVertical },
    { value: 'LANDSCAPE', label: 'Paysage', icon: RectangleHorizontal },
];

const title = computed(() => {
    if (props.source?.kind === 'employee' && count.value === 1) return `Badge · ${props.badges[0]?.name ?? ''}`;

    return props.source?.kind === 'interns' ? 'Badges des stagiaires' : 'Badges du personnel';
});
const scopeLabel = computed(() => (props.source?.label === 'selection' ? 'cochés dans la liste' : 'affichés par la liste et ses filtres'));

/* --- La page, au millimètre ---------------------------------------- */

/** Un rien de moins que la page : un arrondi du navigateur ne pousse jamais une page blanche. */
const paperStyle = computed(() => ({
    width: `${sheet.value.page.width}mm`,
    height: `${sheet.value.page.height - 0.6}mm`,
    padding: `${sheet.value.margin}mm`,
}));
const gridStyle = computed(() => ({
    gridTemplateColumns: `repeat(${Math.max(1, sheet.value.columns)}, ${sheet.value.card.width}mm)`,
    gridAutoRows: `${sheet.value.card.height}mm`,
    gap: `${sheet.value.gap}mm`,
}));
const cellStyle = computed(() => ({ width: `${sheet.value.card.width}mm`, height: `${sheet.value.card.height}mm` }));

/*
 * Le format de la page suit le papier choisi : une règle injectée au montage et
 * retirée en quittant, comme la feuille de tour de salle — une page nommée CSS
 * ne s'applique pas dans les conteneurs de la mise en page. La marge est dans
 * la page elle-même (son `padding`), pour que l'écran et le papier coïncident.
 */
const PAGE_STYLE_ID = 'rivo-badges-page';
const applyPageRule = () => {
    if (typeof document === 'undefined') return;
    const style = document.getElementById(PAGE_STYLE_ID) ?? document.createElement('style');
    style.id = PAGE_STYLE_ID;
    style.textContent = `@page { size: ${sheet.value.page.width}mm ${sheet.value.page.height}mm; margin: 0; }`;
    document.head.appendChild(style);
};

/* L'aperçu à l'écran : la page réduite pour tenir dans la largeur, jamais agrandie. */
const pagesBox = ref(null);
const zoom = ref(1);
let observer = null;
const fitZoom = () => {
    const width = pagesBox.value?.clientWidth ?? 0;
    const pagePx = sheet.value.page.width * MM_TO_PX;
    zoom.value = width > 0 ? Math.min(1, width / pagePx) : 1;
};

onMounted(() => {
    applyPageRule();
    fitZoom();
    if (typeof ResizeObserver !== 'undefined' && pagesBox.value) {
        observer = new ResizeObserver(fitZoom);
        observer.observe(pagesBox.value);
    }
});
watch(sheet, () => { applyPageRule(); fitZoom(); });
onBeforeUnmount(() => {
    observer?.disconnect();
    document.getElementById(PAGE_STYLE_ID)?.remove();
});

const printBadges = () => window.print();
</script>

<template>
    <Head :title="title" />

    <div class="badge-page mx-auto w-full max-w-6xl space-y-5">
        <div class="badge-actions space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Button v-if="source.back_url" :as="Link" :href="source.back_url" variant="outline" size="sm">
                    <ArrowLeft class="h-4 w-4" aria-hidden="true" />{{ source.back_label || 'Retour' }}
                </Button>
                <Button class="ms-auto" :disabled="count === 0" @click="printBadges"><Printer class="h-4 w-4" aria-hidden="true" />Imprimer</Button>
            </div>

            <div class="flex flex-wrap items-start gap-4 rounded-xl border border-border bg-card p-4 text-card-foreground shadow-sm sm:p-5">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <IdCard class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1 space-y-1">
                    <h1 class="text-xl font-semibold tracking-tight text-foreground">{{ title }}</h1>
                    <p class="text-sm text-muted-foreground">
                        <template v-if="source.kind === 'employee'">Le badge se lit dans le dossier : photo, nom, service et fonction. Rien ne se saisit ici.</template>
                        <template v-else>
                            <Users class="me-1 inline h-4 w-4 align-[-2px]" aria-hidden="true" />{{ count }} badge{{ count > 1 ? 's' : '' }} · les dossiers {{ scopeLabel }}, dossiers archivés exclus.
                        </template>
                    </p>
                    <p class="text-[0.8rem] text-muted-foreground">
                        Carte {{ formatMm(sheet.card) }}
                        · <template v-if="sheet.mode === 'card'">une carte par page</template><template v-else>{{ perPage }} par page ({{ sheet.columns }} × {{ sheet.rows }}) sur {{ BADGE_PAPER_LABELS[paper] }} {{ paperOrientation === 'LANDSCAPE' ? 'paysage' : 'portrait' }}</template>
                        · {{ pages.length }} page{{ pages.length > 1 ? 's' : '' }} à imprimer
                        · imprimez en couleur, à 100 % (sans « ajuster à la page »).
                    </p>
                </div>
            </div>

            <!-- La mise en page de cette impression : celle du site, modifiable ici sans la changer. -->
            <div class="flex flex-wrap items-end gap-x-6 gap-y-4 rounded-xl border border-border bg-card px-4 py-3 text-card-foreground shadow-sm">
                <div class="space-y-1.5">
                    <p class="text-sm font-medium text-foreground">Papier</p>
                    <Select v-model="paper" :options="paperOptions" :icon="Shapes" aria-label="Papier" class="w-60" />
                </div>
                <div class="space-y-1.5">
                    <p class="text-sm font-medium text-foreground">Orientation de la page</p>
                    <SegmentedField v-model="paperOrientation" :options="ORIENTATIONS" :disabled="paper === 'CARD'" aria-label="Orientation de la page" />
                </div>
                <label for="badges-cut-marks" class="flex cursor-pointer items-center gap-2.5 pb-1.5">
                    <Switch id="badges-cut-marks" v-model="cutMarks" />
                    <span class="flex items-center gap-1.5 text-sm font-medium text-foreground"><Scissors class="h-4 w-4 text-muted-foreground" aria-hidden="true" />Traits de coupe</span>
                </label>
                <p class="basis-full text-[0.8rem] text-muted-foreground">
                    Proposé d’après les réglages du site (Paramètres › Badge du personnel). Ce choix ne vaut que pour cette impression.
                </p>
            </div>

            <p v-if="sheet.mode === 'sheet' && ! sheet.fits" class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200" role="alert">
                <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />La carte ne tient pas sur ce papier avec ces marges : chaque badge part seul sur sa page, rogné. Choisissez un papier plus grand ou l’autre orientation.
            </p>
            <p v-if="refusal" class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200" role="alert">
                <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ refusal }}
            </p>
            <div v-else-if="count === 0" class="rounded-xl border border-dashed border-border px-6 py-12 text-center">
                <IdCard class="mx-auto h-8 w-8 text-muted-foreground" aria-hidden="true" />
                <p class="mt-3 font-medium text-foreground">Aucun badge à imprimer</p>
                <p class="mt-1 text-sm text-muted-foreground">La liste ne contient aucun dossier en service avec ces filtres.</p>
            </div>
        </div>

        <div v-if="count > 0" ref="pagesBox" class="badge-pages" :style="{ '--badge-zoom': zoom }">
            <section
                v-for="(page, pageIndex) in pages"
                :key="pageIndex"
                class="badge-paper"
                :style="paperStyle"
                :aria-label="`Page ${pageIndex + 1} sur ${pages.length}`"
            >
                <div class="badge-grid" :style="gridStyle">
                    <div v-for="person in page" :key="person.uuid" :class="cn('badge-cell', cutMarks && 'badge-cell--cut')" :style="cellStyle">
                        <EmployeeBadge :person="person" :design="design" />
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>

<style>
.badge-pages {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1.5rem;
    overflow-x: auto;
}

/* La page, au millimètre ; réduite à l'écran pour tenir dans la largeur. */
.badge-paper {
    box-sizing: border-box;
    flex: none;
    overflow: hidden;
    background: #fff;
    box-shadow: 0 10px 30px -12px rgb(15 23 42 / 0.35);
    zoom: var(--badge-zoom, 1);
    print-color-adjust: exact;
    -webkit-print-color-adjust: exact;
}

.badge-grid {
    display: grid;
    justify-content: center;
    align-content: start;
}

.badge-cell {
    box-sizing: border-box;
    break-inside: avoid;
}

.badge-cell .employee-badge {
    width: 100% !important;
    height: 100% !important;
}

/* Les traits de coupe : un pointillé autour de chaque carte, hors de la carte. */
.badge-cell--cut {
    outline: 0.2mm dashed #9ca3af;
    outline-offset: 0.6mm;
}

.badge-page .employee-badge {
    print-color-adjust: exact;
    -webkit-print-color-adjust: exact;
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
    .badge-actions {
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

    .badge-page {
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    /* Aucun espace avant la première page : il pousserait tout sur la page suivante. */
    .badge-page > * {
        margin-top: 0 !important;
    }

    .badge-pages {
        display: block;
        gap: 0;
        overflow: visible;
    }

    /* Le format réel : jamais réduit, une feuille par page. */
    .badge-paper {
        zoom: 1 !important;
        box-shadow: none !important;
        break-after: page;
        page-break-after: always;
    }

    .badge-paper:last-child {
        break-after: auto;
        page-break-after: auto;
    }
}
</style>
