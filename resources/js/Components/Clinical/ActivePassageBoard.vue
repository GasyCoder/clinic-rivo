<script setup>
import { computed, onBeforeUnmount, ref, useSlots, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Activity, ArrowRight, CircleAlert, CircleCheck, CircleDashed, CircleHelp, Clock, Compass, FileHeart, FileText, Folder, FolderClock, Lock, NotebookPen, Play, RefreshCw, Search, Siren, Undo2 } from 'lucide-vue-next';
import EntryPathConfirm from '@/Components/Clinical/EntryPathConfirm.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import QueueSkipConfirm from '@/Components/Clinical/QueueSkipConfirm.vue';
import Avatar from '@/Components/Shadcn/Avatar.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import { useQueueSkipGuard } from '@/composables/useQueueSkipGuard';
import { usePermissions } from '@/composables/usePermissions';
import { useToastStore } from '@/stores/toast';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/utilities/date';
import { nextStepIcon } from '@/utilities/nextSteps';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';
import {
    blockHeading,
    boardParams,
    boardTiles,
    durationLabel,
    elapsedCaption,
    elapsedMinutes,
    elsewhereLabel,
    entryPathHint,
    emptyState,
    isEmergency,
    isWaitingView,
    moduleWords,
    needIcon,
    needSummary,
    stateIcon,
    stateTone,
    visibleNeeds,
    waitTone as waitToneAt,
} from '@/utilities/activePassages';

/**
 * ADR-177 — les passages ouverts, tels qu'un service clinique les voit.
 *
 * Le même tableau pour les Soins, la Médecine et la Maternité, en trois blocs
 * qui ne se mélangent pas :
 *
 * ```text
 * En attente         arrivés, pas encore pris en charge ici — chacun son n° de file
 * En cours chez moi  pris en charge ici
 * Terminés chez moi  terminés ici, passage encore ouvert
 * ```
 *
 * « Urgences » est un filtre transversal, « Suggérés pour moi » un filtre du
 * bloc « En attente ». Tout passage ouvert dont l'accueil est terminé est
 * visible, quelle que soit la prochaine étape suggérée par la Réception.
 *
 * Voir n'est pas prendre en charge : rien n'est créé en regardant. « Prendre
 * en charge » est un vrai geste, posté au serveur ; « Voir le passage »
 * n'ouvre que le détail du passage, gardé par ses propres droits.
 *
 * Le serveur décide de tout : les blocs, les comptes, les n° de file, l'état
 * chez ce service et les adresses des gestes permis (une adresse absente dit
 * « pas ce geste ici »). L'écran ne recalcule aucune règle.
 */
const props = defineProps({
    /** `CARE` | `MEDICINE` | `MATERNITY` */
    module: { type: String, required: true },
    /** L'adresse du tableau, pour ses vues et sa recherche. */
    baseUrl: { type: String, required: true },
    passages: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    view: { type: String, default: 'waiting' },
    search: { type: String, default: '' },
});

const { can } = usePermissions();
const toast = useToastStore();
const words = computed(() => moduleWords(props.module));
const rows = computed(() => props.passages?.data ?? []);
const waitingView = computed(() => isWaitingView(props.view));

const query = ref(props.search ?? '');
const visit = (params) => router.get(props.baseUrl, params, { preserveState: true, preserveScroll: true, replace: true });

/** Un filtre déjà actif se relâche vers « En attente », le travail à faire. */
const selectView = (view) => visit(boardParams(view === props.view && view !== 'waiting' ? 'waiting' : view, query.value));

let debounceTimer = null;
watch(query, (value) => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => visit(boardParams(props.view, value)), 350);
});

// Un tableau se lit en direct : une attente de « 12 min » ne doit pas vieillir
// d'une heure sur un écran resté ouvert.
const now = ref(Date.now());
const clock = setInterval(() => { now.value = Date.now(); }, 30_000);
onBeforeUnmount(() => {
    clearInterval(clock);
    clearTimeout(debounceTimer);
});

const elapsedLabel = (row) => durationLabel(elapsedMinutes(row, now.value));
const waitTone = (row) => waitToneAt(row, now.value);

const tiles = computed(() => boardTiles(props.module, props.counts, props.view));
const heading = computed(() => blockHeading(props.module, props.view));
const empty = computed(() => emptyState(props.module, props.view));
const HEADING_ICONS = { waiting: Clock, suggested: Compass, in_progress: Activity, completed: CircleCheck, emergency: Siren };

/**
 * Les colonnes suivent le bloc : ce qu'on cherche devant la file (n°, arrivée,
 * suggestion) n'est pas ce qu'on cherche devant ses patients en cours (qui,
 * depuis quand) ni devant ce qui est terminé (quand, et la suite).
 */
const COLUMNS = {
    waiting: ['number', 'patient', 'arrival', 'need', 'suggestion', 'situation', 'actions'],
    suggested: ['number', 'patient', 'arrival', 'need', 'suggestion', 'situation', 'actions'],
    emergency: ['number', 'patient', 'arrival', 'need', 'situation', 'actions'],
    in_progress: ['patient', 'passage', 'need', 'taken', 'elsewhere', 'actions'],
    completed: ['patient', 'passage', 'need', 'done', 'elsewhere', 'actions'],
};
const columns = computed(() => COLUMNS[props.view] ?? COLUMNS.waiting);
/**
 * Tous les gestes d'une ligne ont la même hauteur et tiennent sur une ligne :
 * le geste de travail garde un libellé court (Prendre, Ouvrir, Remettre), les
 * liens de relecture sont des icônes réunies dans une barre, nommées au survol
 * et pour les lecteurs d'écran.
 */
const slots = useSlots();
const ACTION_CLASS = 'h-8 gap-1.5 px-2.5 text-xs';
const TOOL_CLASS = 'h-8 w-8 rounded-none p-0 text-muted-foreground hover:text-foreground';
const hasTools = (row) => Boolean(row.actions.journal_url || row.actions.medical_record_url || row.actions.passage_url || slots['row-actions']);
const has = (column) => columns.value.includes(column);
const COLUMN_LABELS = computed(() => ({
    number: 'N°',
    patient: 'Patient',
    arrival: 'Arrivée',
    passage: 'Passage',
    need: 'Besoin',
    suggestion: 'Suggestion de l’accueil',
    situation: words.value.name,
    taken: 'Prise en charge',
    done: 'Terminé',
    elsewhere: 'Ailleurs',
    actions: 'Actions',
}));

/** Une seule prise en charge à la fois : un double clic n'en ouvre pas deux. */
const taking = ref(null);
const postTakeCharge = (row, url) => {
    if (taking.value || !url) return;

    taking.value = row.uuid;
    router.post(url, {}, {
        preserveScroll: true,
        onError: (errors) => toast.warning(errors.episode ?? errors.orientation ?? 'La prise en charge n’a pas pu être enregistrée.', 8000),
        onFinish: () => { taking.value = null; },
    });
};
const takeCharge = (row) => postTakeCharge(row, row.actions?.take_charge_url);

// Prendre un patient quand d'autres attendent avant lui dans la file : on
// demande, on n'interdit pas (ADR-121).
const skipGuard = useQueueSkipGuard({
    rows: () => rows.value,
    currentPage: () => props.passages?.current_page ?? 1,
    accept: takeCharge,
});

/**
 * Par où ce patient devrait entrer (`EpisodeEntryPath`) : dit avant de le
 * prendre à contre-sens. Attendu aux Soins, la Médecine choisit ; attendu en
 * Médecine, les Soins sont seulement informés — rien ne part d'ici.
 */
const pathwayRow = ref(null);
const requestTakeCharge = (row) => {
    if (row.pathway) {
        pathwayRow.value = row;

        return;
    }

    skipGuard.request(row);
};
const consultAnyway = () => {
    const row = pathwayRow.value;
    pathwayRow.value = null;

    if (row?.actions?.take_charge_url) skipGuard.request(row);
};
const doCareMyself = () => {
    const row = pathwayRow.value;
    pathwayRow.value = null;

    if (row) postTakeCharge(row, row.pathway?.care_take_charge_url);
};

// Pris par erreur : le patient retrouve sa place (ADR-122, ADR-127). Le
// serveur refuse dès qu'un travail est enregistré, et le dit.
const releasePatient = (row) => router.post(row.actions.release_url, {}, {
    preserveScroll: true,
    onError: (errors) => toast.warning(errors.orientation ?? 'Le patient n’a pas pu être remis en file.', 8000),
});

const SEX_LABELS = { M: 'Homme', F: 'Femme' };
const patientProfile = (patient) => [
    SEX_LABELS[patient.sex] ?? null,
    patient.age === null || patient.age === undefined ? 'âge N/R' : `${patient.age} ans`,
].filter(Boolean).join(' · ');

const openLabel = (row) => {
    if (row.module.state === 'COMPLETED') return 'Consulter';
    if (row.module.is_waiting_on_results) return 'Reprendre';

    return 'Ouvrir';
};
const openTitle = (row) => (row.module.state === 'COMPLETED'
    ? 'Consulter le dossier de ce passage'
    : row.module.is_waiting_on_results ? 'Reprendre : un résultat est attendu' : 'Ouvrir le dossier et poursuivre');

/** Une urgence pas encore vue : épinglée en tête, sans n° — elle ne prend la place de personne. */
const isPinnedEmergency = (row) => row.module.is_waiting && !row.module.queue_number && isEmergency(row);

/**
 * Ce qu'on veut savoir sans parcourir la page : une urgence encore sans prise
 * en charge. Comptée sur la page affichée — le compte des urgences de toute
 * la vue reste celui de la carte.
 */
const unattendedEmergencies = computed(() => rows.value.filter((row) => isEmergency(row) && row.module.is_waiting).length);

const pages = computed(() => props.passages?.links ?? []);
</script>

<template>
    <div class="space-y-4">
        <div
            v-if="unattendedEmergencies"
            class="flex flex-col gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800 sm:flex-row sm:items-center sm:justify-between dark:border-red-900 dark:bg-red-950/20 dark:text-red-200"
            role="status"
        >
            <p class="flex items-start gap-2 text-sm font-semibold">
                <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                {{ unattendedEmergencies }} urgence{{ unattendedEmergencies > 1 ? 's' : '' }} pas encore prise{{ unattendedEmergencies > 1 ? 's' : '' }} en charge {{ words.at }}.
            </p>
            <Button v-if="view !== 'emergency'" type="button" size="xs" variant="danger-outline" @click="selectView('emergency')">Voir les urgences</Button>
        </div>

        <QueueCounters :tiles="tiles" @select="selectView" />

        <Card class="overflow-hidden shadow-sm">
            <div class="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground">
                        <component :is="HEADING_ICONS[view] ?? Clock" class="h-4.5 w-4.5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-base font-bold text-foreground">
                            {{ heading.title }}
                            <span class="ms-1 font-semibold tabular-nums text-muted-foreground">· {{ passages.total ?? rows.length }}</span>
                        </h2>
                        <p class="mt-0.5 max-w-3xl text-xs leading-5 text-muted-foreground">{{ heading.hint }}</p>
                    </div>
                </div>
                <div class="w-full shrink-0 lg:w-80">
                    <IconInput v-model="query" :icon="Search" type="search" placeholder="Patient, n° patient ou passage" autocomplete="off" aria-label="Rechercher un passage" />
                </div>
            </div>

            <!-- Le filtre « suggérés pour moi » reste dans le bloc « En attente » :
                 la suggestion de l'accueil ne sort personne de la file. -->
            <div v-if="waitingView" class="flex flex-wrap items-center gap-2 border-b border-border bg-muted/30 px-4 py-2.5" role="group" aria-label="Filtrer la file d’attente">
                <Button type="button" size="xs" :variant="view === 'waiting' ? 'primary' : 'white-outline'" :aria-pressed="view === 'waiting'" @click="selectView('waiting')">
                    Toute la file · {{ counts.waiting ?? 0 }}
                </Button>
                <Button type="button" size="xs" :variant="view === 'suggested' ? 'primary' : 'white-outline'" :aria-pressed="view === 'suggested'" @click="selectView('suggested')">
                    <Compass class="h-3.5 w-3.5" aria-hidden="true" />Suggérés pour moi · {{ counts.suggested ?? 0 }}
                </Button>
            </div>

            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[980px] border-collapse">
                    <caption class="sr-only">{{ heading.title }}</caption>
                    <thead class="bg-muted/60">
                        <tr>
                            <th
                                v-for="column in columns"
                                :key="column"
                                :class="cn(
                                    'border-b border-border px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-muted-foreground',
                                    column === 'number' ? 'w-20 text-center' : column === 'actions' ? 'text-end' : 'text-start',
                                )"
                            >{{ COLUMN_LABELS[column] }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="row in rows"
                            :key="row.uuid"
                            :class="cn(
                                'align-top transition-colors',
                                isEmergency(row) ? 'bg-red-50/40 hover:bg-red-50/70 dark:bg-red-950/10 dark:hover:bg-red-950/20' : 'hover:bg-muted/50',
                                row.module.queue_number === 1 && !isEmergency(row) && 'bg-amber-50/40 dark:bg-amber-950/10',
                            )"
                        >
                            <!-- N° de file : servi par le serveur, sur toute la file, par ordre d'arrivée. -->
                            <td v-if="has('number')" :class="cn('border-s-2 px-4 py-3 text-center', isEmergency(row) ? 'border-s-red-500' : row.module.queue_number === 1 ? 'border-s-amber-400' : 'border-s-transparent')">
                                <template v-if="row.module.queue_number">
                                    <span
                                        :class="cn('mx-auto grid h-9 w-9 place-items-center rounded-full text-base font-bold tabular-nums', row.module.queue_number === 1 ? 'bg-amber-500 text-white' : 'bg-primary/15 text-primary')"
                                        :title="`N° ${row.module.queue_number} dans la file ${words.name}`"
                                    >{{ row.module.queue_number }}</span>
                                    <span v-if="row.module.queue_number === 1" class="mt-1 block text-[10px] font-bold uppercase tracking-wide text-amber-700 dark:text-amber-300">Prochain</span>
                                </template>
                                <span v-else-if="isPinnedEmergency(row)" class="mx-auto grid h-9 w-9 place-items-center rounded-full bg-red-600 text-white" title="Urgence : en tête de file, sans numéro">
                                    <Siren class="h-4.5 w-4.5" aria-hidden="true" /><span class="sr-only">Urgence en tête de file</span>
                                </span>
                                <span v-else class="text-muted-foreground" :title="row.pathway?.blocking ? row.pathway.title : undefined" aria-label="Pas dans la file">—</span>
                            </td>

                            <td v-if="has('patient')" class="px-4 py-3">
                                <div class="flex min-w-[190px] max-w-[230px] items-center gap-3">
                                    <Avatar size="sm" :variant="isEmergency(row) ? 'danger-pale' : 'slate-pale'" :text="formatPatientInitials(row.episode.patient)" aria-hidden="true" />
                                    <div class="min-w-0">
                                        <Link v-if="can('patients.view')" :href="`/patients/${row.episode.patient.uuid}`" :title="formatPatientName(row.episode.patient)" class="block truncate text-sm font-bold text-foreground hover:text-primary">{{ formatPatientName(row.episode.patient) }}</Link>
                                        <span v-else :title="formatPatientName(row.episode.patient)" class="block truncate text-sm font-bold text-foreground">{{ formatPatientName(row.episode.patient) }}</span>
                                        <span class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                            <span class="inline-flex items-center gap-1 font-medium"><Folder class="h-3.5 w-3.5" aria-hidden="true" />{{ row.episode.patient.patient_number }}</span>
                                            <span>{{ patientProfile(row.episode.patient) }}</span>
                                        </span>
                                        <!-- L'urgence est une propriété du passage, jamais du patient (ADR-021, ADR-056). -->
                                        <Badge v-if="isEmergency(row)" tone="danger" class="mt-1 px-2 py-0.5 text-[10px] uppercase">Urgence</Badge>
                                    </div>
                                </div>
                            </td>

                            <td v-if="has('arrival')" class="whitespace-nowrap px-4 py-3">
                                <span class="block text-sm font-semibold text-foreground">{{ formatDateTime(row.episode.started_at) }}</span>
                                <span class="mt-0.5 flex items-center gap-1 text-xs font-semibold" :class="waitTone(row)">
                                    <Clock class="h-3.5 w-3.5" aria-hidden="true" />{{ elapsedLabel(row) }} {{ elapsedCaption(row) }}
                                </span>
                                <span class="mt-0.5 block font-mono text-[11px] text-muted-foreground">{{ row.episode.episode_number }}</span>
                            </td>

                            <td v-if="has('passage')" class="whitespace-nowrap px-4 py-3">
                                <span class="block font-mono text-xs font-semibold text-muted-foreground">{{ row.episode.episode_number }}</span>
                                <span class="mt-0.5 block text-[11px] text-muted-foreground">Arrivé le {{ formatDateTime(row.episode.started_at) }}</span>
                            </td>

                            <td v-if="has('need')" class="max-w-[240px] px-4 py-3">
                                <!-- Chaque besoin porte l'icône de son service : on reconnaît d'un coup
                                     d'œil une consultation, un soin, une analyse. -->
                                <ul v-if="row.needs.length" class="space-y-1" :title="needSummary(row, row.needs.length)">
                                    <li v-for="(need, index) in visibleNeeds(row).items" :key="index" class="flex items-start gap-1.5 text-sm text-foreground">
                                        <component :is="needIcon(need.module)" class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                        <span>{{ need.description }}<span v-if="Number(need.quantity) > 1" class="ms-1 text-xs font-semibold text-muted-foreground">×{{ Number(need.quantity) }}</span></span>
                                    </li>
                                    <li v-if="visibleNeeds(row).more" class="ps-[22px] text-xs text-muted-foreground">+{{ visibleNeeds(row).more }} autre{{ visibleNeeds(row).more > 1 ? 's' : '' }}</li>
                                </ul>
                                <span v-if="!row.needs.length || row.need_to_specify" :class="cn('flex items-center gap-1.5 text-xs italic text-muted-foreground', row.needs.length && 'mt-1')">
                                    <CircleHelp class="h-4 w-4 shrink-0" aria-hidden="true" />Besoin à préciser
                                </span>
                            </td>

                            <td v-if="has('suggestion')" class="px-4 py-3">
                                <!-- Indicatif : une suggestion ne cache le passage à aucun service. -->
                                <div v-if="row.next_steps.length" class="flex max-w-[200px] flex-wrap gap-1">
                                    <Badge
                                        v-for="step in row.next_steps"
                                        :key="step.value"
                                        :variant="step.value === module ? 'secondary' : 'outline'"
                                        :class="cn('px-2 py-0.5 text-[11px]', step.value === module && 'ring-1 ring-primary/30')"
                                    >
                                        <component :is="nextStepIcon(step.value)" class="h-3 w-3" aria-hidden="true" />{{ step.label }}
                                    </Badge>
                                </div>
                                <span v-else class="inline-flex items-center gap-1.5 whitespace-nowrap text-xs text-muted-foreground">
                                    <CircleDashed class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Aucune suggestion
                                </span>
                            </td>

                            <td v-if="has('situation')" class="px-4 py-3">
                                <Badge :tone="stateTone(row)" class="whitespace-nowrap px-2 py-0.5 text-[11px]"><component :is="stateIcon(row)" class="h-3 w-3" aria-hidden="true" />{{ row.module.state_label }}</Badge>
                                <!-- Par où ce patient devrait entrer : lu avant de le prendre, pas après. -->
                                <span v-if="entryPathHint(row)" :class="cn('mt-1 flex items-center gap-1 whitespace-nowrap text-[11px] font-semibold', entryPathHint(row).class)" :title="row.pathway.title">
                                    <component :is="entryPathHint(row).icon" class="h-3 w-3" aria-hidden="true" />{{ entryPathHint(row).label }}
                                </span>
                                <span v-if="row.module.state === 'REQUESTED' && row.module.source_label" class="mt-1 flex items-center gap-1 text-[11px] text-muted-foreground">
                                    <ArrowRight class="h-3 w-3" aria-hidden="true" />Orienté par {{ row.module.source_label }} · {{ formatDateTime(row.module.oriented_at) }}
                                </span>
                                <span v-if="row.module.reason" class="mt-0.5 block max-w-[240px] truncate text-[11px] text-muted-foreground" :title="row.module.reason">{{ row.module.reason }}</span>
                                <span v-if="row.module.accepted_by && !row.module.is_waiting" class="mt-0.5 block text-[11px] text-muted-foreground">Par {{ row.module.accepted_by }}</span>
                                <!-- En attente ici, mais pris en charge ailleurs en ce moment : le dire
                                     en clair, pour qu'on n'appelle pas un patient en plein soin. -->
                                <div v-if="row.elsewhere.some((item) => item.state === 'IN_PROGRESS')" class="mt-1.5 flex flex-wrap gap-1">
                                    <Badge v-for="item in row.elsewhere.filter((other) => other.state === 'IN_PROGRESS')" :key="item.module" tone="info" class="whitespace-nowrap px-2 py-0.5 text-[11px]" :title="elsewhereLabel(item)">
                                        <Activity class="h-3 w-3" aria-hidden="true" />Actuellement : {{ item.label }}
                                    </Badge>
                                </div>
                                <ul v-if="row.elsewhere.some((item) => item.state !== 'IN_PROGRESS')" class="mt-1.5 space-y-0.5 border-t border-dashed border-border pt-1.5" :aria-label="'Ailleurs que ' + words.at">
                                    <li v-for="item in row.elsewhere.filter((other) => other.state !== 'IN_PROGRESS')" :key="item.module" class="text-[11px] text-muted-foreground">{{ elsewhereLabel(item) }}</li>
                                </ul>
                                <slot name="row-details" :row="row" />
                            </td>

                            <td v-if="has('taken')" class="px-4 py-3">
                                <Badge :tone="stateTone(row)" class="whitespace-nowrap px-2 py-0.5 text-[11px]"><component :is="stateIcon(row)" class="h-3 w-3" aria-hidden="true" />{{ row.module.state_label }}</Badge>
                                <span class="mt-1 block text-xs font-semibold text-foreground">{{ row.module.accepted_by ?? '—' }}</span>
                                <span class="mt-0.5 flex items-center gap-1 whitespace-nowrap text-[11px] text-muted-foreground">
                                    <Clock class="h-3 w-3" aria-hidden="true" />{{ elapsedLabel(row) }} {{ elapsedCaption(row) }}
                                </span>
                                <span class="block whitespace-nowrap text-[11px] text-muted-foreground">depuis {{ formatDateTime(row.module.accepted_at) }}</span>
                                <span v-if="row.module.is_waiting_on_results" class="mt-0.5 block max-w-[240px] text-[11px] text-amber-600 dark:text-amber-400">{{ row.module.pending_reasons.join(' · ') }}</span>
                                <slot name="row-details" :row="row" />
                            </td>

                            <td v-if="has('done')" class="px-4 py-3">
                                <Badge tone="success" class="whitespace-nowrap px-2 py-0.5 text-[11px]"><component :is="stateIcon(row)" class="h-3 w-3" aria-hidden="true" />{{ row.module.state_label }}</Badge>
                                <span class="mt-1 block whitespace-nowrap text-xs font-semibold text-foreground">{{ formatDateTime(row.module.completed_at) }}</span>
                                <span v-if="row.module.accepted_by" class="mt-0.5 block whitespace-nowrap text-[11px] text-muted-foreground">Par {{ row.module.accepted_by }}</span>
                                <slot name="row-details" :row="row" />
                            </td>

                            <td v-if="has('elsewhere')" class="px-4 py-3">
                                <ul v-if="row.elsewhere.length" class="space-y-0.5" :aria-label="'Ailleurs que ' + words.at">
                                    <li v-for="item in row.elsewhere" :key="item.module" class="text-xs text-muted-foreground">{{ elsewhereLabel(item) }}</li>
                                </ul>
                                <span v-else class="text-xs text-muted-foreground">Aucun autre service</span>
                            </td>

                            <td v-if="has('actions')" class="px-4 py-3">
                                <!-- Une seule ligne : le geste de travail d'abord, avec un libellé court,
                                     puis la barre de relecture en icônes, toujours au même endroit à droite. -->
                                <div class="flex flex-nowrap items-center justify-end gap-2">
                                    <Button
                                        v-if="row.actions.take_charge_url"
                                        type="button"
                                        size="sm"
                                        :class="ACTION_CLASS"
                                        :variant="isEmergency(row) ? 'danger' : 'primary'"
                                        :disabled="taking === row.uuid"
                                        title="Prendre en charge ce patient"
                                        aria-label="Prendre en charge"
                                        @click="requestTakeCharge(row)"
                                    >
                                        <Play class="h-3.5 w-3.5" aria-hidden="true" />{{ taking === row.uuid ? 'Prise…' : 'Prendre' }}
                                    </Button>
                                    <!-- Attendu ailleurs : le geste reste à sa place, verrouillé, et dit pourquoi. -->
                                    <Button
                                        v-else-if="row.pathway?.blocking"
                                        type="button"
                                        size="sm"
                                        :class="ACTION_CLASS"
                                        variant="white-outline"
                                        :title="row.pathway.title"
                                        :aria-label="`Prendre en charge — ${row.pathway.title}`"
                                        @click="requestTakeCharge(row)"
                                    >
                                        <Lock class="h-3.5 w-3.5" aria-hidden="true" />Prendre
                                    </Button>
                                    <Button v-if="row.actions.open_url" :as="Link" :href="row.actions.open_url" size="sm" :class="ACTION_CLASS" variant="white-outline" :title="openTitle(row)">
                                        <component :is="row.module.is_waiting_on_results ? RefreshCw : FileText" class="h-3.5 w-3.5" aria-hidden="true" />{{ openLabel(row) }}
                                    </Button>
                                    <Button
                                        v-if="row.actions.release_url"
                                        type="button"
                                        size="sm"
                                        :class="ACTION_CLASS"
                                        variant="white-outline"
                                        title="Remettre en file — pris en charge par erreur ? Le patient retrouve sa place."
                                        aria-label="Remettre en file"
                                        @click="releasePatient(row)"
                                    >
                                        <Undo2 class="h-3.5 w-3.5" aria-hidden="true" />Remettre
                                    </Button>

                                    <!-- Relecture : journal et dossier d'un passage terminé, chacun servi
                                         seulement avec son droit, puis le passage lui-même. -->
                                    <div
                                        v-if="hasTools(row)"
                                        role="group"
                                        aria-label="Relire"
                                        class="inline-flex shrink-0 items-center divide-x divide-border overflow-hidden rounded-md border border-border bg-card shadow-sm"
                                    >
                                        <Button v-if="row.actions.journal_url" :as="Link" :href="row.actions.journal_url" size="sm" :class="TOOL_CLASS" variant="ghost" title="Journal de traitement de ce passage" aria-label="Journal de traitement">
                                            <NotebookPen class="h-4 w-4" aria-hidden="true" />
                                        </Button>
                                        <Button v-if="row.actions.medical_record_url" :as="Link" :href="row.actions.medical_record_url" size="sm" :class="TOOL_CLASS" variant="ghost" title="Dossier médical de ce passage" aria-label="Dossier médical">
                                            <FileHeart class="h-4 w-4" aria-hidden="true" />
                                        </Button>
                                        <slot name="row-actions" :row="row" :action-class="ACTION_CLASS" :tool-class="TOOL_CLASS" />
                                        <Button v-if="row.actions.passage_url" :as="Link" :href="row.actions.passage_url" size="sm" :class="TOOL_CLASS" variant="ghost" title="Voir le passage — le détail, gardé par ses propres droits" aria-label="Voir le passage">
                                            <FolderClock class="h-4 w-4" aria-hidden="true" />
                                        </Button>
                                    </div>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="rows.length === 0">
                            <td :colspan="columns.length" class="px-5 py-14 text-center">
                                <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-muted text-muted-foreground"><component :is="HEADING_ICONS[view] ?? Search" class="h-5 w-5" aria-hidden="true" /></span>
                                <p class="mt-3 text-sm font-semibold text-foreground">{{ empty.title }}</p>
                                <p class="mx-auto mt-1 max-w-xl text-xs text-muted-foreground">{{ empty.hint }}</p>
                                <Button v-if="view !== 'waiting'" class="mt-4" size="sm" variant="white-outline" type="button" @click="selectView('waiting')">Voir la file d’attente</Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="pages.length > 3" class="flex flex-wrap items-center justify-between gap-3 border-t border-border p-4 text-xs text-muted-foreground" aria-label="Pagination">
                <span>{{ passages.from }}–{{ passages.to }} sur {{ passages.total }}</span>
                <div class="flex flex-wrap gap-1">
                    <template v-for="link in pages" :key="link.label">
                        <Button v-if="link.url" :as="Link" :href="link.url" size="xs" :variant="link.active ? 'primary' : 'outline'" :aria-current="link.active ? 'page' : undefined" preserve-scroll preserve-state>
                            <!-- Laravel renvoie « &laquo; Précédent » : l'entité doit être rendue. -->
                            <span v-html="link.label" />
                        </Button>
                        <Button v-else type="button" size="xs" variant="outline" disabled><span v-html="link.label" /></Button>
                    </template>
                </div>
            </nav>
        </Card>

        <EntryPathConfirm
            :row="pathwayRow"
            :busy="Boolean(taking)"
            @consult="consultAnyway"
            @care="doCareMyself"
            @cancel="pathwayRow = null"
        />

        <QueueSkipConfirm
            :pending="skipGuard.pending.value"
            :waited-label="elapsedLabel"
            :wait-tone="waitTone"
            @confirm="skipGuard.confirm"
            @cancel="skipGuard.cancel"
        />
    </div>
</template>
