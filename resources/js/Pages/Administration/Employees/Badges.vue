<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft, Camera, CircleAlert, FileStack, IdCard, IdCardLanyard, LayoutGrid, Palette, Printer, QrCode, RectangleHorizontal,
    RectangleVertical, Ruler, Scissors, Shapes, SlidersHorizontal, TriangleAlert, UserX, Users,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Separator from '@/Components/Shadcn/Separator.vue';
import Switch from '@/Components/Shadcn/Switch.vue';
import SegmentedField from '@/Components/Settings/SegmentedField.vue';
import BadgeHolderMockup from '@/Components/Administration/BadgeHolderMockup.vue';
import EmployeeBadge from '@/Components/Administration/EmployeeBadge.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { hrContext, hrUrl } from '@/utilities/hrUrl';
import { settingsUrl } from '@/utilities/settingsSections';
import {
    BADGE_CHOICES, BADGE_DEFAULT_DESIGN, BADGE_FORMATS, BADGE_PAPER_LABELS, MM_TO_PX, badgeFormatName, badgeSheetLayout, chunk, formatMm,
} from '@/utilities/employeeBadge';

defineOptions({ layout: AppLayout });

/**
 * ADR-209 — les badges à imprimer : celui d'une personne, ou une planche pour
 * les employés (ou stagiaires) affichés ou cochés dans leur liste.
 *
 * Trois aperçus : le badge dans son porte-badge, au tour de cou, tel qu'on le
 * portera ; les cartes, grandes, pour relire chaque badge ; les pages, telles
 * qu'elles sortiront de l'imprimante — le papier (A4, A5, A3, Lettre US,
 * ou une carte par page pour une imprimante à badges), son orientation, les
 * marges et l'espacement réglés pour le site. Quel que soit l'aperçu, ce sont
 * les pages qui s'impriment. Le RH peut changer le papier pour cette impression
 * sans toucher au réglage. Les badges sont au format réel : imprimer à 100 %,
 * sans « ajuster à la page ».
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

const { can } = usePermissions();

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
    custom: { width: props.design?.card_width, height: props.design?.card_height },
}));
/** Une carte qui ne tient pas sur la page part seule sur sa page, plutôt que de disparaître. */
const perPage = computed(() => Math.max(1, sheet.value.perPage));
const pages = computed(() => chunk(props.badges, perPage.value));
const count = computed(() => props.badges.length);
const single = computed(() => props.source?.kind === 'employee' && count.value === 1);
const cardLandscape = computed(() => props.design?.orientation === 'LANDSCAPE');

/** L'aperçu à l'écran — ce sont toujours les pages qui s'impriment. Un badge seul se voit porté. */
const view = ref(single.value ? 'holder' : 'pages');
const VIEWS = [
    { value: 'holder', label: 'Porte-badge', icon: IdCardLanyard, hint: 'Le badge dans son porte-badge, au tour de cou' },
    { value: 'cards', label: 'Cartes', icon: LayoutGrid, hint: 'Chaque badge en grand, pour le relire' },
    { value: 'pages', label: 'Pages', icon: FileStack, hint: 'Les pages telles qu’elles s’impriment' },
];
const format = computed(() => BADGE_FORMATS[props.design?.card_size] ?? BADGE_FORMATS.STANDARD);
const showsQr = computed(() => props.design?.show_qr !== false);

const paperOptions = BADGE_CHOICES.badge_paper.map((value) => ({
    value,
    label: value === 'CARD' ? 'Carte seule — une par page' : BADGE_PAPER_LABELS[value],
}));
const ORIENTATIONS = [
    { value: 'PORTRAIT', label: 'Portrait', icon: RectangleVertical },
    { value: 'LANDSCAPE', label: 'Paysage', icon: RectangleHorizontal },
];

const title = computed(() => {
    if (single.value) return `Badge · ${props.badges[0]?.name ?? ''}`;

    return props.source?.kind === 'interns' ? 'Badges des stagiaires' : 'Badges du personnel';
});
const scopeLabel = computed(() => (props.source?.label === 'selection' ? 'cochés dans la liste' : 'affichés par la liste et ses filtres'));
const paperLabel = computed(() => (sheet.value.mode === 'card'
    ? `une carte de ${formatMm(sheet.value.card)} par page`
    : `${BADGE_PAPER_LABELS[paper.value]} ${paperOrientation.value === 'LANDSCAPE' ? 'paysage' : 'portrait'}`));

/* --- Ce qu'il faut regarder avant d'imprimer --------------------------- */

const showsPhoto = computed(() => props.design?.show_photo !== false);
const withoutPhoto = computed(() => (showsPhoto.value ? props.badges.filter((person) => ! person.photo_url) : []));
const inactive = computed(() => props.badges.filter((person) => person.active === false));
const issueOf = (person) => ({ noPhoto: showsPhoto.value && ! person.photo_url, inactive: person.active === false });
const PREVIEW_NAMES = 4;
const dossierUrl = (person) => hrUrl(`/administration/employees/${person.uuid}`);
const canEdit = computed(() => can('employees.update'));

/** L'apparence se règle sur le portail : le RH d'un site le lit, le Super Admin y va. */
const onPortal = computed(() => Boolean(hrContext()));
const settingsHref = computed(() => settingsUrl('badges', hrContext()?.site?.code ?? ''));

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
const zoomPercent = computed(() => Math.round(zoom.value * 100));

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

    <div class="badge-page w-full space-y-5">
        <!-- En-tête : d'où l'on vient, ce que l'on imprime, le geste principal. -->
        <header class="badge-actions space-y-4">
            <Button v-if="source.back_url" :as="Link" :href="source.back_url" variant="ghost" size="sm" class="-ms-2 text-muted-foreground hover:text-foreground">
                <ArrowLeft class="h-4 w-4" aria-hidden="true" />{{ source.back_label || 'Retour' }}
            </Button>

            <Card class="flex flex-wrap items-start gap-4 p-4 sm:p-5">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                    <IdCard class="h-6 w-6" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1 space-y-2">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ single ? 'Badge du personnel' : 'Planche de badges' }}</p>
                        <h1 class="mt-0.5 text-xl font-semibold tracking-tight text-foreground sm:text-2xl">{{ title }}</h1>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        <template v-if="source.kind === 'employee'">Tout est lu dans le dossier : photo, nom, service et fonction. Rien ne se saisit ici.</template>
                        <template v-else>Les dossiers {{ scopeLabel }} ; les dossiers archivés n’ont pas de badge.</template>
                    </p>
                    <div class="flex flex-wrap gap-1.5">
                        <Badge v-if="! single" variant="secondary"><Users class="h-3.5 w-3.5" aria-hidden="true" />{{ count }} badge{{ count > 1 ? 's' : '' }}</Badge>
                        <Badge variant="outline"><Ruler class="h-3.5 w-3.5" aria-hidden="true" />{{ badgeFormatName(design?.card_size, sheet.card) }}</Badge>
                        <Badge variant="outline">
                            <component :is="cardLandscape ? RectangleHorizontal : RectangleVertical" class="h-3.5 w-3.5" aria-hidden="true" />Badge en {{ cardLandscape ? 'paysage' : 'portrait' }}
                        </Badge>
                        <Badge v-if="showsQr" variant="outline"><QrCode class="h-3.5 w-3.5" aria-hidden="true" />QR code du numéro</Badge>
                    </div>
                </div>
                <div class="flex w-full flex-col gap-2 sm:ms-auto sm:w-auto sm:flex-row sm:items-center">
                    <Button v-if="onPortal" :as="Link" :href="settingsHref" variant="outline" size="lg" title="Couleurs, textes, format du porte-badge, QR code… pour tout le personnel du site">
                        <SlidersHorizontal class="h-4 w-4" aria-hidden="true" />Modifier le modèle
                    </Button>
                    <Button size="lg" :disabled="count === 0" @click="printBadges">
                        <Printer class="h-4 w-4" aria-hidden="true" />{{ count > 1 ? `Imprimer les ${count} badges` : 'Imprimer' }}
                    </Button>
                </div>
            </Card>
        </header>

        <div class="badge-layout grid gap-5 lg:grid-cols-[minmax(0,1fr)_21rem] lg:items-start">
            <!-- L'aperçu. En impression, seules les pages restent. -->
            <Card class="badge-preview min-w-0 overflow-hidden">
                <div class="badge-actions flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3">
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-foreground">Aperçu</h2>
                        <p class="text-xs text-muted-foreground">
                            <template v-if="count === 0">Rien à afficher.</template>
                            <template v-else-if="view === 'holder'">Tel{{ count > 1 ? 's' : '' }} qu’on le{{ count > 1 ? 's' : '' }} portera : dans un porte-badge {{ format.group === 'CARD' ? 'au format carte' : formatMm(sheet.card) }}, au tour de cou.</template>
                            <template v-else-if="view === 'cards'">{{ count }} badge{{ count > 1 ? 's' : '' }}, agrandi{{ count > 1 ? 's' : '' }} pour la relecture.</template>
                            <template v-else>{{ pages.length }} page{{ pages.length > 1 ? 's' : '' }}<template v-if="zoom < 1"> · réduite{{ pages.length > 1 ? 's' : '' }} à {{ zoomPercent }} % à l’écran, imprimée{{ pages.length > 1 ? 's' : '' }} à 100 %</template>.</template>
                        </p>
                    </div>
                    <SegmentedField v-if="count > 0" v-model="view" :options="VIEWS" aria-label="Aperçu" />
                </div>

                <div class="badge-stage bg-muted/40 p-4 sm:p-6">
                    <div v-if="refusal" class="badge-actions mx-auto flex max-w-lg flex-col items-center gap-3 py-10 text-center" role="alert">
                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300">
                            <TriangleAlert class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <p class="font-medium text-foreground">Trop de badges à la fois</p>
                        <p class="text-sm text-muted-foreground">{{ refusal }}</p>
                        <Button v-if="source.back_url" :as="Link" :href="source.back_url" variant="outline" size="sm">
                            <ArrowLeft class="h-4 w-4" aria-hidden="true" />{{ source.back_label || 'Retour' }}
                        </Button>
                    </div>

                    <div v-else-if="count === 0" class="badge-actions mx-auto flex max-w-lg flex-col items-center gap-3 py-10 text-center">
                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-background text-muted-foreground shadow-sm">
                            <IdCard class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <p class="font-medium text-foreground">Aucun badge à imprimer</p>
                        <p class="text-sm text-muted-foreground">La liste ne contient aucun dossier en service avec ces filtres.</p>
                        <Button v-if="source.back_url" :as="Link" :href="source.back_url" variant="outline" size="sm">
                            <ArrowLeft class="h-4 w-4" aria-hidden="true" />{{ source.back_label || 'Retour' }}
                        </Button>
                    </div>

                    <template v-else>
                        <!-- Porte-badge : le badge tel qu'on le portera, au tour de cou. Jamais imprimé. -->
                        <ul
                            v-if="view === 'holder'"
                            :class="cn('badge-gallery grid justify-center gap-8', single ? 'grid-cols-1 justify-items-center py-2' : 'grid-cols-[repeat(auto-fill,minmax(13rem,1fr))]')"
                            aria-label="Badges dans leur porte-badge"
                        >
                            <li v-for="person in badges" :key="person.uuid" class="flex w-full flex-col items-center gap-2">
                                <BadgeHolderMockup :person="person" :design="design" :height="single ? '38rem' : '24rem'" />
                                <div v-if="issueOf(person).noPhoto || issueOf(person).inactive" class="flex flex-wrap justify-center gap-1">
                                    <Badge v-if="issueOf(person).noPhoto" variant="warning"><Camera class="h-3 w-3" aria-hidden="true" />Sans photo</Badge>
                                    <Badge v-if="issueOf(person).inactive" variant="outline"><UserX class="h-3 w-3" aria-hidden="true" />Inactif</Badge>
                                </div>
                            </li>
                        </ul>

                        <!-- Cartes : chaque badge en grand, et ce qui lui manque. Jamais imprimé. -->
                        <ul
                            v-else-if="view === 'cards'"
                            :class="cn(
                                'badge-gallery grid justify-center gap-6',
                                single ? 'grid-cols-1 justify-items-center py-4'
                                : cardLandscape ? 'grid-cols-[repeat(auto-fill,minmax(15rem,1fr))]' : 'grid-cols-[repeat(auto-fill,minmax(11rem,1fr))]',
                            )"
                            aria-label="Badges"
                        >
                            <li v-for="person in badges" :key="person.uuid" class="flex w-full flex-col items-center gap-2">
                                <div
                                    :class="cn(
                                        'w-full overflow-hidden shadow-[0_14px_34px_-16px_rgb(15_23_42/0.5)]',
                                        single ? (cardLandscape ? 'max-w-[28rem]' : 'max-w-[19rem]') : (cardLandscape ? 'max-w-[20rem]' : 'max-w-[13rem]'),
                                        design?.corners === 'SQUARE' ? 'rounded-none' : 'rounded-xl',
                                    )"
                                >
                                    <EmployeeBadge :person="person" :design="design" />
                                </div>
                                <div v-if="issueOf(person).noPhoto || issueOf(person).inactive" class="flex flex-wrap justify-center gap-1">
                                    <Badge v-if="issueOf(person).noPhoto" variant="warning"><Camera class="h-3 w-3" aria-hidden="true" />Sans photo</Badge>
                                    <Badge v-if="issueOf(person).inactive" variant="outline"><UserX class="h-3 w-3" aria-hidden="true" />Inactif</Badge>
                                </div>
                            </li>
                        </ul>

                        <!-- Pages : au millimètre, réduites à l'écran. Toujours là : ce sont elles qui s'impriment. -->
                        <div
                            ref="pagesBox"
                            :class="cn('badge-pages', view !== 'pages' && 'badge-pages--offscreen')"
                            :style="{ '--badge-zoom': zoom }"
                        >
                            <div v-for="(page, pageIndex) in pages" :key="pageIndex" class="badge-sheet">
                                <p class="badge-sheet-label text-xs font-medium text-muted-foreground">
                                    Page {{ pageIndex + 1 }} sur {{ pages.length }}
                                    <span aria-hidden="true">·</span>
                                    {{ page.length }} badge{{ page.length > 1 ? 's' : '' }}
                                </p>
                                <section
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
                </div>
            </Card>

            <!-- L'impression : le papier de cette fois, ce qu'il faut régler dans la fenêtre d'impression. -->
            <aside class="badge-actions space-y-4 lg:sticky lg:top-20" aria-label="Impression">
                <Card class="p-4">
                    <div class="flex items-center gap-2">
                        <Printer class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                        <h2 class="text-sm font-semibold text-foreground">Impression</h2>
                    </div>

                    <dl class="mt-3 grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-lg border border-border bg-muted/40 px-2 py-2">
                            <dt class="text-[0.7rem] font-medium uppercase tracking-wide text-muted-foreground">Badges</dt>
                            <dd class="text-lg font-semibold tabular-nums text-foreground">{{ count }}</dd>
                        </div>
                        <div class="rounded-lg border border-border bg-muted/40 px-2 py-2">
                            <dt class="text-[0.7rem] font-medium uppercase tracking-wide text-muted-foreground">Par page</dt>
                            <dd class="text-lg font-semibold tabular-nums text-foreground">{{ perPage }}</dd>
                        </div>
                        <div class="rounded-lg border border-border bg-muted/40 px-2 py-2">
                            <dt class="text-[0.7rem] font-medium uppercase tracking-wide text-muted-foreground">Pages</dt>
                            <dd class="text-lg font-semibold tabular-nums text-foreground">{{ pages.length }}</dd>
                        </div>
                    </dl>
                    <p class="mt-2 text-xs text-muted-foreground">
                        <template v-if="sheet.mode === 'card'">Une carte par page, pour une imprimante à badges.</template>
                        <template v-else>{{ sheet.columns }} × {{ sheet.rows }} carte{{ perPage > 1 ? 's' : '' }} sur {{ paperLabel }}, marges {{ String(sheet.margin).replace('.', ',') }} mm.</template>
                    </p>

                    <Separator class="my-4" />

                    <div class="space-y-4">
                        <div class="space-y-1.5">
                            <p id="badges-paper-label" class="text-sm font-medium text-foreground">Papier</p>
                            <Select v-model="paper" :options="paperOptions" :icon="Shapes" aria-labelledby="badges-paper-label" class="w-full" />
                        </div>
                        <div class="space-y-1.5">
                            <p class="text-sm font-medium text-foreground">Orientation de la page</p>
                            <SegmentedField v-model="paperOrientation" :options="ORIENTATIONS" :disabled="paper === 'CARD'" aria-label="Orientation de la page" />
                            <p v-if="paper === 'CARD'" class="text-xs text-muted-foreground">La page prend la forme de la carte.</p>
                        </div>
                        <label for="badges-cut-marks" class="flex cursor-pointer items-center justify-between gap-3 rounded-lg border border-border px-3 py-2.5">
                            <span class="flex items-center gap-2 text-sm font-medium text-foreground">
                                <Scissors class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                                <span>
                                    Traits de coupe
                                    <span class="block text-xs font-normal text-muted-foreground">Un pointillé autour de chaque carte.</span>
                                </span>
                            </span>
                            <Switch id="badges-cut-marks" v-model="cutMarks" />
                        </label>

                        <p v-if="sheet.mode === 'sheet' && ! sheet.fits" class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200" role="alert">
                            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                            <span>La carte ne tient pas sur ce papier avec ces marges : chaque badge part seul sur sa page, rogné. Choisissez un papier plus grand ou l’autre orientation.</span>
                        </p>

                        <p class="text-xs text-muted-foreground">Proposé d’après les réglages du site. Ce choix ne vaut que pour cette impression.</p>
                    </div>

                    <Separator class="my-4" />

                    <p class="text-sm font-medium text-foreground">Dans la fenêtre d’impression</p>
                    <ol class="mt-2 space-y-1.5 text-sm text-muted-foreground">
                        <li class="flex gap-2"><span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-muted text-[0.7rem] font-semibold text-foreground">1</span><span>Papier : <strong class="font-medium text-foreground">{{ sheet.mode === 'card' ? `carte ${formatMm(sheet.card)}` : paperLabel }}</strong>.</span></li>
                        <li class="flex gap-2"><span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-muted text-[0.7rem] font-semibold text-foreground">2</span><span>Échelle <strong class="font-medium text-foreground">100 %</strong>, jamais « ajuster à la page ».</span></li>
                        <li class="flex gap-2"><span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-muted text-[0.7rem] font-semibold text-foreground">3</span><span>Imprimante <strong class="font-medium text-foreground">en couleur</strong>, marges par défaut.</span></li>
                    </ol>

                    <Button class="mt-4 w-full" :disabled="count === 0" @click="printBadges">
                        <Printer class="h-4 w-4" aria-hidden="true" />Imprimer
                    </Button>
                </Card>

                <!-- Ce que le badge ne dit pas de lui-même : il s'imprime quand même, mais on le sait avant. -->
                <Card v-if="withoutPhoto.length || inactive.length" class="p-4">
                    <div class="flex items-center gap-2">
                        <CircleAlert class="h-4 w-4 text-amber-600 dark:text-amber-400" aria-hidden="true" />
                        <h2 class="text-sm font-semibold text-foreground">À vérifier</h2>
                    </div>

                    <div v-if="withoutPhoto.length" class="mt-3 space-y-1.5">
                        <p class="flex items-center gap-2 text-sm text-foreground">
                            <Camera class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <template v-if="single">Pas de photo : le badge affiche les initiales.</template>
                            <template v-else>{{ withoutPhoto.length }} sans photo : leur badge affiche les initiales.</template>
                        </p>
                        <ul v-if="! single" class="ms-6 space-y-0.5 text-sm">
                            <li v-for="person in withoutPhoto.slice(0, PREVIEW_NAMES)" :key="person.uuid">
                                <Link :href="dossierUrl(person)" class="text-primary hover:underline">{{ person.name }}</Link>
                            </li>
                            <li v-if="withoutPhoto.length > PREVIEW_NAMES" class="text-muted-foreground">et {{ withoutPhoto.length - PREVIEW_NAMES }} autre{{ withoutPhoto.length - PREVIEW_NAMES > 1 ? 's' : '' }}</li>
                        </ul>
                        <Button
                            v-else-if="canEdit"
                            :as="Link"
                            :href="hrUrl(`/administration/employees/${badges[0].uuid}/edit`)"
                            variant="outline"
                            size="sm"
                            class="ms-6"
                        >
                            <Camera class="h-4 w-4" aria-hidden="true" />Ajouter la photo
                        </Button>
                    </div>

                    <div v-if="inactive.length" class="mt-3 space-y-1.5">
                        <p class="flex items-center gap-2 text-sm text-foreground">
                            <UserX class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <template v-if="single">Dossier inactif : le badge ne le dit pas.</template>
                            <template v-else>{{ inactive.length }} dossier{{ inactive.length > 1 ? 's' : '' }} inactif{{ inactive.length > 1 ? 's' : '' }} : le badge ne le dit pas.</template>
                        </p>
                        <ul v-if="! single" class="ms-6 space-y-0.5 text-sm">
                            <li v-for="person in inactive.slice(0, PREVIEW_NAMES)" :key="person.uuid">
                                <Link :href="dossierUrl(person)" class="text-primary hover:underline">{{ person.name }}</Link>
                            </li>
                            <li v-if="inactive.length > PREVIEW_NAMES" class="text-muted-foreground">et {{ inactive.length - PREVIEW_NAMES }} autre{{ inactive.length - PREVIEW_NAMES > 1 ? 's' : '' }}</li>
                        </ul>
                    </div>
                </Card>

                <p class="flex items-start gap-2 px-1 text-xs leading-5 text-muted-foreground">
                    <Palette class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span>
                        Couleurs, textes, polices, format du porte-badge, QR code et papier par défaut se règlent pour le site dans
                        <Link v-if="onPortal" :href="settingsHref" class="font-medium text-primary hover:underline">Paramètres › Badge du personnel</Link>
                        <span v-else class="font-medium text-foreground">Paramètres › Badge du personnel</span>
                        (Super Admin).
                    </span>
                </p>
            </aside>
        </div>
    </div>
</template>

<style>
.badge-pages {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1.75rem;
    overflow-x: auto;
}

/* L'aperçu « Cartes » est ouvert : les pages restent montées pour l'impression, mais hors de la vue. */
.badge-pages--offscreen {
    display: none;
}

.badge-sheet {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
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
    .badge-actions,
    .badge-gallery,
    .badge-sheet-label {
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
    .badge-page > *,
    .badge-layout > * {
        margin-top: 0 !important;
    }

    /* La carte d'aperçu s'efface : il ne reste que les pages, bord à bord. */
    .badge-layout {
        display: block !important;
    }

    .badge-preview,
    .badge-stage {
        overflow: visible !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: none !important;
        padding: 0 !important;
    }

    /* Les pages s'impriment quel que soit l'aperçu ouvert à l'écran. */
    .badge-pages,
    .badge-pages--offscreen {
        display: block !important;
        gap: 0;
        overflow: visible;
    }

    .badge-sheet {
        display: block;
        break-after: page;
        page-break-after: always;
    }

    .badge-sheet:last-child {
        break-after: auto;
        page-break-after: auto;
    }

    /* Le format réel : jamais réduit, une feuille par page. */
    .badge-paper {
        zoom: 1 !important;
        box-shadow: none !important;
    }
}
</style>
