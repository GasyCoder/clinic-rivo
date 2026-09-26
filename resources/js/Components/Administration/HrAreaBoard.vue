<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowUpDown, Check, ChevronDown, ChevronLeft, ChevronRight, ChevronUp, GripVertical, Move, RotateCcw } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { copyLayout, locate, moveCard, neighbourColumn, normalizeLayout, sameLayout } from '@/utilities/boardLayout';
import { HR_FIGURE_TONES } from '@/utilities/hrFigures';
import { HR_SITE_BASE } from '@/utilities/hrPath';
import { HR_SECTION_GROUPS, hrSections } from '@/utilities/hrSections';

/**
 * ADR-066 / ADR-187 / ADR-194 — « Que voulez-vous faire ? » : les rubriques RH
 * d'un site en cartes. Une seule grille pour l'accueil RH du site et pour la
 * page Ressources humaines du portail : même dessin, mêmes rubriques, mêmes
 * droits ; seule change la base des adresses (le site, ou un site vu du portail).
 */
const props = defineProps({
    // Les chiffres du site : une carte dit ce qui y attend une décision.
    summary: { type: Object, default: () => ({}) },
    // `/administration` sur le site, `/super-admin/sites/A/rh` sur le portail.
    base: { type: String, default: HR_SITE_BASE },
});

const { can } = usePermissions();

/**
 * Les rubriques RH, rangées comme on travaille : le personnel, son temps de
 * travail, puis le pilotage. Libellés, icônes, adresses et droits viennent du
 * menu RH du site (`hrSections`) ; cette page ajoute seulement leur phrase et
 * ce qui y attend une décision.
 */
const DETAILS = {
    'hr-employees': { description: 'Dossiers du personnel', tone: 'primary' },
    'hr-contracts': { description: 'CDI, CDD, stages…', tone: 'sky', pending: 'contracts_ending_soon', pendingLabel: 'finissent ≤ 30 j' },
    'hr-internships': { description: 'Stagiaires, filières, encadrants', tone: 'violet' },
    'hr-documents': { description: 'Attestations et courriers', tone: 'violet' },
    'hr-professional-emails': { description: 'Adresses @domaine du personnel', tone: 'sky' },
    'hr-attendance': { description: 'Entrées et sorties', tone: 'emerald', pending: 'open_attendance', pendingLabel: 'sans sortie' },
    'hr-leave': { description: 'Demandes et décisions', tone: 'amber', pending: 'pending_leave', pendingLabel: 'à décider' },
    'hr-planning': { description: 'Créneaux des équipes', tone: 'violet' },
    'hr-reports': { description: 'Chiffres et exports', tone: 'rose' },
    'hr-block-credit': { description: 'Crédit du personnel', tone: 'primary' },
    'hr-departments': { description: 'Services de la clinique', tone: 'sky' },
    'hr-job-titles': { description: 'Postes et métiers', tone: 'emerald' },
    'hr-settings': { description: 'Contrats, congés, attestations', tone: 'slate' },
};

// Les thèmes sont ceux de la barre RH du portail : une seule liste (hrSections).
const GROUPS = HR_SECTION_GROUPS;

const TONES = { ...HR_FIGURE_TONES, slate: 'bg-muted text-muted-foreground' };

/* ------------------------------------------------------------------ */
/* Disposition des rubriques, arrangée par chacun                      */
/* ------------------------------------------------------------------ */

/**
 * Chacun range les cartes comme il travaille : glisser-déposer à la souris,
 * flèches au doigt et au clavier, d'une colonne à l'autre. La disposition est
 * propre à ce poste (navigateur) et n'est jamais envoyée : elle ne change ni
 * les droits, ni les adresses, ni rien de ce que la page affiche.
 */
const STORAGE_KEY = 'rivo:hr:home-areas';
const DEFAULT_LAYOUT = Object.fromEntries(GROUPS.map((group) => [group.key, group.codes]));

const layout = ref(copyLayout(DEFAULT_LAYOUT));
const customizing = ref(false);
const draggingCode = ref(null);
const movedCode = ref(null);

// Lue après l'affichage, jamais pendant : la page est rendue par le serveur,
// qui ne connaît pas le navigateur (sinon cartes et liens se décalent).
onMounted(() => {
    try {
        layout.value = normalizeLayout(JSON.parse(window.localStorage.getItem(STORAGE_KEY)), DEFAULT_LAYOUT);
    } catch {
        layout.value = copyLayout(DEFAULT_LAYOUT);
    }
});

const persist = () => {
    try {
        if (sameLayout(layout.value, DEFAULT_LAYOUT)) window.localStorage.removeItem(STORAGE_KEY);
        else window.localStorage.setItem(STORAGE_KEY, JSON.stringify(layout.value));
    } catch {
        // Stockage indisponible : la disposition vaut pour cette visite.
    }
};

const isCustom = computed(() => ! sameLayout(layout.value, DEFAULT_LAYOUT));

const groups = computed(() => {
    const sections = hrSections(props.base, can);

    return GROUPS
        .map((group) => ({
            ...group,
            areas: (layout.value[group.key] ?? [])
                .map((code) => sections.find((section) => section.code === code))
                .filter(Boolean)
                .map((section) => {
                    const details = DETAILS[section.code] ?? {};
                    const count = details.pending ? Number(props.summary?.[details.pending] ?? 0) : 0;

                    return { ...section, ...details, count };
                }),
        }))
        // Une colonne vidée reste visible pendant qu'on arrange, pour pouvoir y déposer.
        .filter((group) => group.areas.length || customizing.value);
});

const groupLabel = (key) => GROUPS.find((group) => group.key === key)?.label ?? '';
const visibleCodes = (column) => groups.value.find((group) => group.key === column)?.areas.map((area) => area.code) ?? [];

/** Marque un instant la carte qui vient de bouger, pour que l'œil la suive. */
const flash = (code) => {
    movedCode.value = code;
    window.setTimeout(() => { if (movedCode.value === code) movedCode.value = null; }, 900);
};

const settle = (code) => {
    persist();
    flash(code);
};

/** Monter ou descendre dans sa colonne, en sautant les rubriques que ce compte ne voit pas. */
const moveWithin = (code, column, delta) => {
    const visible = visibleCodes(column);
    const target = visible[visible.indexOf(code) + delta];

    if (! target) return;

    layout.value = moveCard(layout.value, code, column, layout.value[column].indexOf(target));
    settle(code);
};

/** Passer dans la colonne voisine, au même rang si possible. */
const moveAcross = (code, column, delta) => {
    const target = neighbourColumn(layout.value, column, delta);

    if (! target) return;

    const anchor = visibleCodes(target)[visibleCodes(column).indexOf(code)];
    layout.value = moveCard(layout.value, code, target, anchor ? layout.value[target].indexOf(anchor) : layout.value[target].length);
    settle(code);
};

const reset = () => {
    layout.value = copyLayout(DEFAULT_LAYOUT);
    persist();
};

/* Glisser-déposer souris ; le tactile passe par les flèches. */
const ghost = ref(null);
const ghostLabel = ref('');

const onDragStart = (event, area) => {
    if (! customizing.value) return;

    draggingCode.value = area.code;
    ghostLabel.value = area.label;
    event.dataTransfer.effectAllowed = 'move';
    // Firefox refuse de commencer un glissement sans donnée.
    event.dataTransfer.setData('text/plain', area.code);
    if (ghost.value) event.dataTransfer.setDragImage(ghost.value, 16, 18);
};

/** La grille se réarrange sous le curseur : ce qu'on voit pendant le geste est déjà le résultat. */
const onDragOver = (event, column, area) => {
    if (! customizing.value || draggingCode.value === null) return;

    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';

    if (draggingCode.value !== area.code) {
        layout.value = moveCard(layout.value, draggingCode.value, column, layout.value[column].indexOf(area.code));
    }
};

/** Déposer au bas d'une colonne — y compris une colonne vide. */
const onDragOverEnd = (event, column) => {
    if (! customizing.value || draggingCode.value === null) return;

    event.preventDefault();
    const visible = visibleCodes(column);

    if (visible[visible.length - 1] !== draggingCode.value) {
        layout.value = moveCard(layout.value, draggingCode.value, column, layout.value[column].length);
    }
};

const onDragEnd = () => {
    if (draggingCode.value === null) return;

    settle(draggingCode.value);
    draggingCode.value = null;
};

const finish = () => {
    customizing.value = false;
    draggingCode.value = null;
};

const positionOf = (code) => locate(layout.value, code);
</script>

<template>
    <section v-if="groups.length" aria-labelledby="hr-areas-title">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <h2 id="hr-areas-title" class="me-auto font-heading text-base font-bold text-foreground">Que voulez-vous faire ?</h2>

            <!-- Rien ne bouge tant que ce mode n'est pas ouvert : une carte ne
                 part jamais d'un clic mal placé, et en dehors une carte reste un lien. -->
            <template v-if="customizing">
                <Button v-if="isCustom" variant="outline" size="sm" @click="reset"><RotateCcw class="h-4 w-4" />Réinitialiser</Button>
                <Button size="sm" @click="finish"><Check class="h-4 w-4" />Terminer</Button>
            </template>
            <Button v-else variant="ghost" size="sm" @click="customizing = true">
                <ArrowUpDown class="h-4 w-4" />Personnaliser
            </Button>
        </div>
        <p v-if="customizing" class="-mt-1 mb-3 flex items-center gap-2 rounded-lg border border-primary/20 bg-primary/5 px-3 py-2 text-xs text-foreground" role="status">
            <Move class="h-3.5 w-3.5 shrink-0 text-primary" aria-hidden="true" />
            Glissez une carte où vous voulez, même dans une autre colonne, ou utilisez ses flèches. La disposition est propre à ce poste.
        </p>

        <!-- L'aperçu emporté par le curseur : hors écran plutôt que masqué,
             `display:none` ne peut pas servir d'image de glissement. -->
        <div ref="ghost" class="pointer-events-none fixed -left-[9999px] top-0 flex items-center gap-2 rounded-lg border border-primary/40 bg-card px-3 py-2 text-xs font-semibold text-foreground shadow-lg" aria-hidden="true">
            <Move class="h-3.5 w-3.5 text-primary" />{{ ghostLabel }}
        </div>

        <div class="grid gap-5 lg:grid-cols-3">
            <div v-for="group in groups" :key="group.key" class="flex flex-col gap-2.5">
                <p class="flex items-center gap-2 px-1 text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">
                    <component :is="group.icon" class="h-3.5 w-3.5" aria-hidden="true" />{{ group.label }}
                </p>

                <!-- Clé = rubrique, jamais le rang : Vue déplace la carte au lieu de
                     la reconstruire, et le bouton qu'on vient d'utiliser garde le focus. -->
                <component
                    :is="customizing ? 'div' : Link"
                    v-for="(area, index) in group.areas"
                    :key="area.code"
                    :href="customizing ? undefined : area.href"
                    :draggable="customizing ? 'true' : undefined"
                    :aria-label="customizing ? `${area.label}, position ${index + 1} dans « ${group.label} »` : undefined"
                    :class="cn(
                        'group flex items-center gap-3.5 rounded-xl border bg-card p-3.5 shadow-sm transition-all',
                        customizing
                            ? 'cursor-grab border-dashed border-primary/40 active:cursor-grabbing'
                            : 'border-border hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                        draggingCode === area.code && 'opacity-40',
                        movedCode === area.code && 'ring-2 ring-primary/50 ring-offset-2 ring-offset-background',
                    )"
                    @dragstart="onDragStart($event, area)"
                    @dragend="onDragEnd"
                    @dragover="onDragOver($event, group.key, area)"
                    @drop.prevent
                >
                    <GripVertical v-if="customizing" class="-me-1.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <span :class="['grid h-11 w-11 shrink-0 place-items-center rounded-lg transition-transform', ! customizing && 'group-hover:scale-105', TONES[area.tone]]">
                        <component :is="area.icon" class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-foreground">{{ area.label }}</span>
                        <span class="block truncate text-xs text-muted-foreground">{{ area.description }}</span>
                    </span>

                    <!-- Les flèches ne sont pas un repli : elles marchent au doigt, à la
                         souris et au clavier, là où le glisser HTML5 n'existe pas (tactile). -->
                    <span v-if="customizing" class="grid shrink-0 grid-cols-2 gap-1">
                        <button
                            type="button"
                            class="grid h-7 w-7 place-items-center rounded-md border border-border text-muted-foreground transition hover:border-primary/40 hover:text-primary disabled:opacity-30"
                            :disabled="index === 0"
                            :aria-label="`Monter ${area.label}`"
                            title="Monter"
                            @click="moveWithin(area.code, group.key, -1)"
                        ><ChevronUp class="h-3.5 w-3.5" /></button>
                        <button
                            type="button"
                            class="grid h-7 w-7 place-items-center rounded-md border border-border text-muted-foreground transition hover:border-primary/40 hover:text-primary disabled:opacity-30"
                            :disabled="index === group.areas.length - 1"
                            :aria-label="`Descendre ${area.label}`"
                            title="Descendre"
                            @click="moveWithin(area.code, group.key, 1)"
                        ><ChevronDown class="h-3.5 w-3.5" /></button>
                        <button
                            type="button"
                            class="grid h-7 w-7 place-items-center rounded-md border border-border text-muted-foreground transition hover:border-primary/40 hover:text-primary disabled:opacity-30"
                            :disabled="! neighbourColumn(layout, positionOf(area.code)?.column, -1)"
                            :aria-label="`Déplacer ${area.label} vers « ${groupLabel(neighbourColumn(layout, positionOf(area.code)?.column, -1))} »`"
                            :title="neighbourColumn(layout, positionOf(area.code)?.column, -1) ? `Vers « ${groupLabel(neighbourColumn(layout, positionOf(area.code)?.column, -1))} »` : ''"
                            @click="moveAcross(area.code, group.key, -1)"
                        ><ChevronLeft class="h-3.5 w-3.5" /></button>
                        <button
                            type="button"
                            class="grid h-7 w-7 place-items-center rounded-md border border-border text-muted-foreground transition hover:border-primary/40 hover:text-primary disabled:opacity-30"
                            :disabled="! neighbourColumn(layout, positionOf(area.code)?.column, 1)"
                            :aria-label="`Déplacer ${area.label} vers « ${groupLabel(neighbourColumn(layout, positionOf(area.code)?.column, 1))} »`"
                            :title="neighbourColumn(layout, positionOf(area.code)?.column, 1) ? `Vers « ${groupLabel(neighbourColumn(layout, positionOf(area.code)?.column, 1))} »` : ''"
                            @click="moveAcross(area.code, group.key, 1)"
                        ><ChevronRight class="h-3.5 w-3.5" /></button>
                    </span>

                    <template v-else>
                        <!-- Ce qui attend une décision dans cette rubrique. -->
                        <span
                            v-if="area.count > 0"
                            class="shrink-0 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold tabular-nums text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                        >{{ area.count }} {{ area.pendingLabel }}</span>
                        <ChevronRight class="h-4 w-4 shrink-0 text-muted-foreground transition group-hover:translate-x-0.5 group-hover:text-primary" aria-hidden="true" />
                    </template>
                </component>

                <!-- Le bas de chaque colonne reçoit une carte, même une colonne vide. -->
                <div
                    v-if="customizing"
                    :class="cn(
                        'grid min-h-12 flex-1 place-items-center rounded-xl border border-dashed border-border px-3 text-xs text-muted-foreground transition-colors',
                        draggingCode && 'border-primary/40 bg-primary/5',
                    )"
                    @dragover="onDragOverEnd($event, group.key)"
                    @drop.prevent
                >Déposer ici</div>
            </div>
        </div>
    </section>
</template>
