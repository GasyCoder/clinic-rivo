<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import QueueSkipConfirm from '@/Components/Clinical/QueueSkipConfirm.vue';
import { Activity, ArrowRight, CircleAlert, CircleCheck, CircleX, Clock, Eye, FolderOpen, List, LogOut, NotebookText, Pencil, Play, Search, Siren, Undo2, UserRoundCheck, UsersRound } from 'lucide-vue-next';
import Avatar from '@/Components/Shadcn/Avatar.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useQueueSkipGuard } from '@/composables/useQueueSkipGuard';
import { itemQuantity } from '@/utilities/episodePathway';
import { formatDateTime, formatRelativeTime } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    orientations: Object,
    counts: Object,
    priorityCounts: { type: Object, default: () => ({}) },
    filter: String,
    search: String,
    priority: String,
});

const { can } = usePermissions();
const page = usePage();

/** Someone else's patient is read, never worked on twice (CareHandlerGuard). */
const isEditableByMe = (orientation) => orientation.status === 'IN_PROGRESS'
    && (!orientation.accepted_by_id || orientation.accepted_by_id === page.props.auth?.user?.id);
const query = ref(props.search ?? '');

const visit = (params) => router.get('/care', params, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
});

const buildParams = (overrides = {}) => ({
    ...(query.value ? { q: query.value } : {}),
    ...(props.filter !== 'active' ? { filter: props.filter } : {}),
    ...(props.priority ? { priority: props.priority } : {}),
    ...overrides,
});

const selectFilter = (filter) => visit(buildParams({ filter: filter !== 'active' ? filter : undefined }));
const selectPriority = (priority) => visit(buildParams({ priority: priority || undefined }));

let debounceTimer = null;
watch(query, (value) => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => visit(buildParams({ q: value || undefined })), 350);
});

const isEmergency = (orientation) => orientation.episode.priority === 'EMERGENCY';

// What a queue is actually read for: how long this patient has been waiting
// to be taken, then how long the care has been running once accepted.
// Orienté et en attente du médecin (ADR-124) : l'attente se compte depuis que
// Médecine a été sollicitée, pas depuis la fin des soins.
const isWaitingDoctor = (orientation) => orientation.status === 'COMPLETED' && Boolean(orientation.doctor);
const elapsedFrom = (orientation) => (isWaitingDoctor(orientation) && orientation.doctor.since
    ? orientation.doctor.since
    : orientation.status === 'IN_PROGRESS'
        ? orientation.accepted_at
        : orientation.oriented_at) ?? orientation.episode.started_at;
const elapsedMinutes = (orientation) => {
    const from = elapsedFrom(orientation);

    return from ? Math.max(0, Math.round((Date.now() - new Date(from).getTime()) / 60000)) : null;
};
const elapsedLabel = (orientation) => {
    const minutes = elapsedMinutes(orientation);

    if (minutes === null) return '—';
    if (minutes < 60) return `${minutes} min`;

    const hours = Math.floor(minutes / 60);

    return minutes % 60 === 0 ? `${hours} h` : `${hours} h ${minutes % 60}`;
};
// Service-level cue, not a clinical one: it flags a queue that is getting
// long, and never blocks or ranks anything. Thresholds are deliberately
// plain (30 min / 1 h) — no clinical threshold is invented here.
const elapsedTone = (orientation) => {
    const minutes = elapsedMinutes(orientation);

    if ((orientation.status !== 'PENDING' && !isWaitingDoctor(orientation)) || minutes === null) return 'text-muted-foreground';
    if (minutes >= 60) return 'text-red-600 dark:text-red-300';
    if (minutes >= 30) return 'text-amber-600 dark:text-amber-300';

    return 'text-muted-foreground';
};
// Prendre un patient qui n'est pas le premier de la file : on demande, on
// n'interdit pas (même garde-fou que Médecine).
const skipGuard = useQueueSkipGuard({
    rows: () => props.orientations.data,
    currentPage: () => props.orientations.current_page,
    accept: (orientation) => router.post(`/care/orientations/${orientation.uuid}/accept`, {}, { preserveScroll: true }),
});

// Pris en charge par erreur (ADR-122) : le patient retrouve sa place. Proposé
// seulement au soignant qui l'a pris ; le serveur refuse dès qu'un soin est
// enregistré, et le dit.
const canRelease = (orientation) => orientation.status === 'IN_PROGRESS'
    && orientation.accepted_by_id === page.props.auth?.user?.id
    && can('care.update');
const releaseError = ref('');
const releasePatient = (orientation) => {
    releaseError.value = '';
    router.post(`/care/orientations/${orientation.uuid}/release`, {}, {
        preserveScroll: true,
        onError: (errors) => { releaseError.value = errors.orientation ?? 'Le patient n’a pas pu être remis en file.'; },
    });
};

const statusPillClass = (status) => ({
    PENDING: 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300',
    IN_PROGRESS: 'bg-primary/10 text-primary ',
    COMPLETED: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300',
}[status] ?? 'bg-muted text-foreground ');
const patientDetails = (patient) => [
    patient.age !== null && patient.age !== undefined ? `${patient.age} ans` : null,
    patient.sex === 'F' ? 'F' : patient.sex === 'M' ? 'M' : null,
].filter(Boolean).join(' · ');

const designationSummary = (orientation) => {
    const items = orientation.episode.designations ?? [];

    if (items.length === 0) {
        return 'Besoin à préciser après évaluation';
    }

    const labels = items.slice(0, 2).map((item) => item.description).join(' · ');
    return items.length > 2 ? `${labels} · +${items.length - 2}` : labels;
};

// Grouped by patient so a patient with several active passages appears once
// in the queue instead of once per passage — grouping only covers the
// current page of results, a patient split across two pages of the queue
// would still show up twice, which is an accepted edge case for a display
// concern like this rather than a reason to restructure the backend query.
const groupedRows = computed(() => {
    const groups = new Map();

    for (const orientation of props.orientations.data) {
        const uuid = orientation.episode.patient.uuid;
        if (!groups.has(uuid)) {
            groups.set(uuid, { patient: orientation.episode.patient, orientations: [] });
        }
        groups.get(uuid).orientations.push(orientation);
    }

    return Array.from(groups.values());
});

const statusCounts = (group) => {
    const counts = { PENDING: 0, IN_PROGRESS: 0, COMPLETED: 0 };
    group.orientations.forEach((orientation) => { counts[orientation.status] = (counts[orientation.status] ?? 0) + 1; });

    return counts;
};
const latestOrientation = (group) => group.orientations.reduce((latest, orientation) => (
    !latest || new Date(orientation.episode.started_at) > new Date(latest.episode.started_at) ? orientation : latest
), null);

/**
 * Deux orientations du même passage sont deux demandes de soins, jamais deux
 * passages : « 2 passages » au même numéro (A-26-0009-01 deux fois) ne disait
 * rien de ce qui les distinguait (ADR-118). Les demandes d'un passage restent
 * dans l'ordre où le médecin les a faites, les passages dans l'ordre d'arrivée.
 */
const requestsInOrder = (group) => [...group.orientations]
    .sort((a, b) => new Date(a.oriented_at) - new Date(b.oriented_at));
const passagesOf = (group) => {
    const byEpisode = new Map();
    const requests = requestsInOrder(group);

    for (const orientation of requests) {
        const key = orientation.episode.uuid;

        if (!byEpisode.has(key)) {
            byEpisode.set(key, { episode: orientation.episode, orientations: [] });
        }

        byEpisode.get(key).orientations.push(orientation);
    }

    return [...byEpisode.values()].sort((a, b) => new Date(a.episode.started_at) - new Date(b.episode.started_at));
};
const pluralize = (count, one, many) => `${count} ${count > 1 ? many : one}`;
const groupSummary = (group) => {
    const passages = passagesOf(group).length;
    const requests = group.orientations.length;

    return passages === requests
        ? pluralize(passages, 'passage', 'passages')
        : `${pluralize(passages, 'passage', 'passages')} · ${pluralize(requests, 'demande de soins', 'demandes de soins')}`;
};
const groupActionLabel = (group) => {
    const passages = passagesOf(group).length;

    return passages === 1 ? `Voir les ${group.orientations.length} demandes` : `Voir les ${passages} passages`;
};

/**
 * Ce que cette orientation demande, dans les mots du médecin quand il en a
 * fait une demande — et non la désignation d'arrivée du passage, identique
 * pour toutes les orientations de celui-ci.
 */
const requestSummary = (orientation) => {
    const items = orientation.care_request?.items ?? [];

    if (items.length === 0) {
        return designationSummary(orientation);
    }

    // Un acte que le médecin a retiré ne se lit pas comme demandé : la ligne de
    // la file le dit, sans quoi « 2. Injection IM » semblerait une seconde injection.
    const labels = items.slice(0, 2)
        .map((item) => [item.name, itemQuantity(item.quantity), item.state === 'CANCELLED' ? '(retiré)' : null].filter(Boolean).join(' '))
        .join(' · ');

    return items.length > 2 ? `${labels} · +${items.length - 2}` : labels;
};
const requestItems = (orientation) => orientation.care_request?.items ?? [];
const ITEM_ICONS = { DONE: CircleCheck, NOT_PERFORMED: CircleX, CANCELLED: CircleX, PARTIAL: Clock, PENDING: Clock };
const ITEM_TONES = {
    DONE: 'text-emerald-600 dark:text-emerald-300',
    NOT_PERFORMED: 'text-red-600 dark:text-red-300',
    CANCELLED: 'text-muted-foreground',
    PARTIAL: 'text-amber-600 dark:text-amber-300',
    PENDING: 'text-muted-foreground',
};
const elapsedCaption = (orientation) => (orientation.status === 'IN_PROGRESS'
    ? 'en charge'
    : isWaitingDoctor(orientation) ? 'attend le médecin' : orientation.status === 'COMPLETED' ? 'depuis la fin' : 'd’attente');
// Le n° d'ordre : celui de la file Soins pour un patient à prendre ; pour un patient
// orienté qui attend le médecin, celui de la file Médecine — le même que le médecin
// voit, pour qu'un patient ne porte jamais deux numéros (ADR-124).
const queueNumberOf = (orientation) => orientation.queue_number ?? (isWaitingDoctor(orientation) ? orientation.doctor.queue_number : null) ?? null;
const queueNumberTitle = (orientation) => (isWaitingDoctor(orientation)
    ? 'N° d’ordre dans la file du médecin'
    : 'N° d’ordre dans la file Soins');
// Le statut affiché : pour un patient orienté, ce qu'il attend — le médecin.
const statusLabel = (orientation) => (isWaitingDoctor(orientation) ? orientation.doctor.label : orientation.status_label);
const pillStatus = (orientation) => (isWaitingDoctor(orientation) ? 'PENDING' : orientation.status);

const openGroup = ref(null);

/**
 * Les deux questions qu'on se pose devant la file : combien de patients
 * attendent, et y a-t-il une urgence. Elles croisent deux filtres
 * indépendants — la file et la priorité — d'où l'`active` porté par chaque
 * carte plutôt qu'un seul état partagé.
 */
const counterTiles = computed(() => [
    { value: 'priority:emergency', label: 'Urgences', hint: 'Priorité du passage', icon: Siren, tone: 'red', count: props.priorityCounts.emergency, active: props.priority === 'emergency' },
    { value: 'priority:normal', label: 'Priorité normale', hint: 'Par ordre d’arrivée', icon: Clock, tone: 'amber', count: props.priorityCounts.normal, active: props.priority === 'normal' },
]);

/**
 * Deux files, deux onglets (ADR-124) : ceux qu'il faut prendre aux Soins, et
 * ceux que les Soins ont orientés vers Médecine et qui attendent encore le
 * médecin. Dès que le médecin les accueille, ils quittent cette page : ils
 * vivent dans le module Patients.
 */
const queueTabs = computed(() => [
    { value: 'active', label: 'À prendre aux Soins', hint: 'Patients nouveaux, en attente ou en cours aux Soins', count: props.counts.active },
    { value: 'waiting_doctor', label: 'Orientés · en attente du médecin', hint: 'Soins terminés : le médecin ne les a pas encore accueillis', count: props.counts.waiting_doctor },
].map((tab) => ({ ...tab, active: props.filter === tab.value })));

/** Une carte déjà active se relâche : on n'a pas à chercher « Tous ». */
const selectCounter = (value) => {
    const [, target] = value.split(':');

    selectPriority(props.priority === target ? '' : target);
};
</script>

<template>
    <Head title="Soins" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                    <UserRoundCheck class="h-6 w-6" />
                </span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-foreground">Soins</h1>
                    <p class="mt-1 text-sm text-muted-foreground">File d’évaluation et d’orientation des patients.</p>
                </div>
            </div>
            <Button v-if="can('patients.view')" :as="Link" href="/patients" size="rg" variant="white-outline">
                <UsersRound class="h-5 w-5" />
                Dossiers patients
            </Button>
        </header>

        <div class="-mb-2 overflow-x-auto" role="tablist" aria-label="Files des Soins">
            <div class="flex min-w-max gap-1 border-b border-border">
                <button
                    v-for="tab in queueTabs"
                    :key="tab.value"
                    type="button"
                    role="tab"
                    :title="tab.hint"
                    :aria-selected="tab.active"
                    :class="[
                        '-mb-px inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30',
                        tab.active ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                    ]"
                    @click="selectFilter(tab.value)"
                >
                    {{ tab.label }}
                    <span :class="['rounded-full px-2 py-0.5 text-xs tabular-nums', tab.active ? 'bg-primary/10' : 'bg-muted']">{{ tab.count }}</span>
                </button>
            </div>
        </div>

        <QueueCounters :tiles="counterTiles" class="lg:grid-cols-2" @select="selectCounter" />

        <p v-if="releaseError" class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 dark:border-red-900 dark:bg-red-950/20 dark:text-red-300" role="alert">{{ releaseError }}</p>

        <Card class="overflow-hidden shadow-sm">
            <div class="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    <div class="relative w-full sm:max-w-xs sm:flex-1">
                        <IconInput v-model="query" :icon="Search" type="search" placeholder="Patient ou n° passage" autocomplete="off" />
                    </div>
                </div>
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[1040px] border-collapse">
                    <caption class="sr-only">File des patients aux Soins</caption>
                    <thead class="bg-muted/30">
                        <tr>
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">N°</th>
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Patient</th>
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Passage</th>
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Demande connue</th>
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Attente</th>
                            <th class="border-b border-border px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Statut</th>
                            <th class="border-b border-border px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-muted-foreground">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="group in groupedRows"
                            :key="group.patient.uuid"
                            :class="[
                                'hover:bg-accent ',
                                // Triage reads first: an emergency is a red edge on the row,
                                // not a small dot lost in a middle column.
                                group.orientations.some(isEmergency)
                                    ? 'border-s-4 border-s-red-500 bg-red-50/40 dark:bg-red-950/10'
                                    : 'border-s-4 border-s-transparent',
                            ]"
                        >
                            <td class="px-5 py-3">
                                <span v-if="group.orientations.length === 1 && queueNumberOf(group.orientations[0])" class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary" :title="queueNumberTitle(group.orientations[0])">{{ queueNumberOf(group.orientations[0]) }}</span>
                                <!-- Déjà pris en charge : la place dans l'attente est consommée. -->
                                <span v-else-if="group.orientations.length === 1 && group.orientations[0].status === 'IN_PROGRESS'" class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-emerald-50 text-sm text-emerald-600 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-300 dark:ring-emerald-900" title="Déjà pris en charge — ne compte plus dans l'attente" aria-label="Déjà pris en charge"><UserRoundCheck class="h-4 w-4" /></span>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex min-w-[230px] items-center gap-3">
                                    <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(group.patient)" />
                                    <div class="min-w-0">
                                        <Link v-if="can('patients.view')" :href="`/patients/${group.patient.uuid}`" class="block truncate text-sm font-bold text-foreground hover:text-primary">
                                            {{ formatPatientName(group.patient) }}
                                        </Link>
                                        <span v-else class="block truncate text-sm font-bold text-foreground">{{ formatPatientName(group.patient) }}</span>
                                        <span class="inline-flex items-center gap-1 text-xs text-muted-foreground"><FolderOpen class="h-4 w-4" />{{ group.patient.patient_number }}<template v-if="patientDetails(group.patient)"> · {{ patientDetails(group.patient) }}</template></span>
                                    </div>
                                </div>
                            </td>

                            <template v-if="group.orientations.length === 1">
                                <td class="px-5 py-3">
                                    <span class="font-mono text-sm font-semibold text-muted-foreground">{{ group.orientations[0].episode.episode_number }}</span>
                                    <span v-if="isEmergency(group.orientations[0])" class="mt-1 inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-red-700 dark:bg-red-950/50 dark:text-red-300"><Activity class="h-4 w-4" />Urgence</span>
                                </td>
                                <td class="max-w-[310px] px-5 py-3 text-sm text-muted-foreground">
                                    <p class="line-clamp-2">{{ requestSummary(group.orientations[0]) }}</p>
                                    <span v-if="group.orientations[0].episode.care_requires_allergy_check" class="mt-1 inline-flex items-center gap-1 rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-bold text-amber-700 dark:bg-amber-950/30 dark:text-amber-300"><CircleAlert class="h-4 w-4" />Vérifier les allergies</span>
                                </td>
                                <td class="px-5 py-3">
                                    <span :class="['block text-base font-bold tabular-nums', elapsedTone(group.orientations[0])]">{{ elapsedLabel(group.orientations[0]) }}</span>
                                    <span class="text-xs text-muted-foreground">{{ elapsedCaption(group.orientations[0]) }} · {{ formatDateTime(elapsedFrom(group.orientations[0])) }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    <span :class="['inline-flex rounded-full px-2.5 py-1 text-[11px] font-bold', statusPillClass(pillStatus(group.orientations[0]))]">{{ statusLabel(group.orientations[0]) }}</span>
                                    <span v-if="group.orientations[0].accepted_by" class="mt-1 block text-xs text-muted-foreground">par {{ group.orientations[0].accepted_by }}</span>
                                </td>
                                <td class="px-5 py-3 text-end">
                                    <Button v-if="group.orientations[0].status === 'PENDING' && can('care.update')" size="sm" variant="primary" type="button" @click="skipGuard.request(group.orientations[0])"><Play class="h-4 w-4" />Prendre en charge</Button>
                                    <Button v-else :as="Link" :href="`/care/orientations/${group.orientations[0].uuid}`" size="sm" variant="white-outline"><component :is="isEditableByMe(group.orientations[0]) ? Pencil : Eye" class="h-4 w-4" />{{ isEditableByMe(group.orientations[0]) ? 'Ouvrir la fiche' : 'Voir la fiche' }}</Button>
                                    <Button v-if="canRelease(group.orientations[0])" type="button" size="sm" variant="white-outline" class="ms-2" title="Pris en charge par erreur ? Le patient retrouve sa place dans la file." @click="releasePatient(group.orientations[0])"><Undo2 class="h-4 w-4" />Remettre en file</Button>
                                </td>
                            </template>

                            <template v-else>
                                <td class="px-5 py-3">
                                    <template v-if="passagesOf(group).length === 1">
                                        <span class="font-mono text-sm font-semibold text-muted-foreground">{{ passagesOf(group)[0].episode.episode_number }}</span>
                                        <span class="mt-0.5 block text-xs text-muted-foreground">{{ pluralize(group.orientations.length, 'demande de soins', 'demandes de soins') }}</span>
                                    </template>
                                    <span v-else class="block text-sm font-semibold text-foreground">{{ passagesOf(group).length }} passages</span>
                                </td>
                                <td class="max-w-[310px] px-5 py-3 text-sm text-muted-foreground">
                                    <ul class="space-y-0.5">
                                        <li v-for="(orientation, index) in requestsInOrder(group).slice(0, 2)" :key="orientation.uuid" class="line-clamp-1"><span class="font-semibold text-foreground">{{ index + 1 }}.</span> {{ requestSummary(orientation) }}</li>
                                        <li v-if="group.orientations.length > 2" class="text-xs">+{{ group.orientations.length - 2 }} autre{{ group.orientations.length - 2 > 1 ? 's' : '' }}</li>
                                    </ul>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="block text-sm text-muted-foreground">{{ formatDateTime(latestOrientation(group).episode.started_at) }}</span>
                                    <span class="text-xs text-muted-foreground">Dernier passage · {{ formatRelativeTime(latestOrientation(group).episode.started_at) }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-semibold text-muted-foreground">
                                        <span v-if="statusCounts(group).PENDING" class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-amber-500" />{{ statusCounts(group).PENDING }} en attente</span>
                                        <span v-if="statusCounts(group).IN_PROGRESS" class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-primary" />{{ statusCounts(group).IN_PROGRESS }} en cours</span>
                                        <span v-if="statusCounts(group).COMPLETED" class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />{{ statusCounts(group).COMPLETED }} orienté(s)</span>
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-end">
                                    <Button size="sm" variant="white-outline" type="button" @click="openGroup = group"><List class="h-4 w-4" />{{ groupActionLabel(group) }}</Button>
                                </td>
                            </template>
                        </tr>
                        <tr v-if="groupedRows.length === 0">
                            <td colspan="7" class="px-5 py-12 text-center">
                                <UserRoundCheck class="mx-auto h-6 w-6 text-muted-foreground" />
                                <p class="mt-2 text-sm font-semibold text-muted-foreground">Aucun patient dans cette file</p>
                                <p class="mt-1 text-xs text-muted-foreground">Les nouveaux passages apparaîtront ici automatiquement.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-border md:hidden">
                <article
                    v-for="group in groupedRows"
                    :key="group.patient.uuid"
                    :class="['border-s-4 p-4', group.orientations.some(isEmergency) ? 'border-s-red-500 bg-red-50/40 dark:bg-red-950/10' : 'border-s-transparent']"
                >
                    <div class="flex items-start gap-3">
                        <span v-if="group.orientations.length === 1 && queueNumberOf(group.orientations[0])" class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary" :title="queueNumberTitle(group.orientations[0])">{{ queueNumberOf(group.orientations[0]) }}</span>
                        <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(group.patient)" />
                        <div class="min-w-0 flex-1">
                            <p class="font-bold text-foreground">{{ formatPatientName(group.patient) }}</p>
                            <template v-if="group.orientations.length === 1">
                                <p class="mt-0.5 text-xs text-muted-foreground">{{ group.orientations[0].episode.episode_number }}<template v-if="patientDetails(group.patient)"> · {{ patientDetails(group.patient) }}</template></p>
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <span :class="['inline-flex rounded-full px-2.5 py-1 text-[11px] font-bold', statusPillClass(pillStatus(group.orientations[0]))]">{{ statusLabel(group.orientations[0]) }}</span>
                                    <span :class="['text-sm font-bold tabular-nums', elapsedTone(group.orientations[0])]">{{ elapsedLabel(group.orientations[0]) }}</span>
                                    <span v-if="isEmergency(group.orientations[0])" class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-bold uppercase text-red-700 dark:bg-red-950/50 dark:text-red-300"><Activity class="h-4 w-4" />Urgence</span>
                                </div>
                                <p class="mt-3 text-sm text-muted-foreground">{{ requestSummary(group.orientations[0]) }}</p>
                                <span v-if="group.orientations[0].episode.care_requires_allergy_check" class="mt-1 inline-flex items-center gap-1 rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-bold text-amber-700 dark:bg-amber-950/30 dark:text-amber-300"><CircleAlert class="h-4 w-4" />Vérifier les allergies</span>
                                <div class="mt-4">
                                    <Button v-if="group.orientations[0].status === 'PENDING' && can('care.update')" block size="sm" variant="primary" type="button" @click="skipGuard.request(group.orientations[0])"><Play class="h-4 w-4" />Prendre en charge</Button>
                                    <Button v-else :as="Link" :href="`/care/orientations/${group.orientations[0].uuid}`" block size="sm" variant="white-outline"><component :is="isEditableByMe(group.orientations[0]) ? Pencil : Eye" class="h-4 w-4" />{{ isEditableByMe(group.orientations[0]) ? 'Ouvrir la fiche' : 'Voir la fiche' }}</Button>
                                    <Button v-if="canRelease(group.orientations[0])" type="button" block size="sm" variant="white-outline" class="mt-2" @click="releasePatient(group.orientations[0])"><Undo2 class="h-4 w-4" />Remettre en file</Button>
                                </div>
                            </template>
                            <template v-else>
                                <p class="mt-0.5 text-xs text-muted-foreground">{{ groupSummary(group) }}</p>
                                <div class="mt-4">
                                    <Button block size="sm" variant="white-outline" type="button" @click="openGroup = group"><List class="h-4 w-4" />{{ groupActionLabel(group) }}</Button>
                                </div>
                            </template>
                        </div>
                    </div>
                </article>
            </div>

            <div v-if="orientations.last_page > 1" class="flex items-center justify-between border-t border-border p-4 text-xs text-muted-foreground">
                <span>Page {{ orientations.current_page }} sur {{ orientations.last_page }}</span>
                <div class="flex gap-2">
                    <Link v-if="orientations.prev_page_url" :href="orientations.prev_page_url" preserve-state class="rounded border border-border px-3 py-1.5">Précédent</Link>
                    <Link v-if="orientations.next_page_url" :href="orientations.next_page_url" preserve-state class="rounded border border-border px-3 py-1.5">Suivant</Link>
                </div>
            </div>
        </Card>
    </div>

    <QueueSkipConfirm
        :pending="skipGuard.pending.value"
        :waited-label="elapsedLabel"
        :wait-tone="elapsedTone"
        @confirm="skipGuard.confirm"
        @cancel="skipGuard.cancel"
    />

    <Dialog
        :open="Boolean(openGroup)"
        size="xl"
        :title="openGroup ? formatPatientName(openGroup.patient) : ''"
        :description="openGroup ? `${openGroup.patient.patient_number} · ${groupSummary(openGroup)}` : ''"
        body-class="max-h-[70vh] overflow-y-auto px-0 py-0"
        @update:open="value => { if (!value) openGroup = null; }"
    >
        <template #icon>
            <Avatar v-if="openGroup" rounded size="sm" variant="slate-pale" :text="formatPatientInitials(openGroup.patient)" />
        </template>

        <!-- Un bloc par passage, puis une ligne par demande de soins : deux
             demandes d'un même passage ne se lisent plus comme deux passages
             au numéro identique (ADR-118). -->
        <section v-for="passage in openGroup ? passagesOf(openGroup) : []" :key="passage.episode.uuid" class="border-b border-border last:border-b-0">
            <header class="flex flex-wrap items-center gap-x-3 gap-y-1 bg-muted/40 px-6 py-2.5">
                <span class="font-mono text-sm font-bold text-foreground">{{ passage.episode.episode_number }}</span>
                <span v-if="passage.episode.priority === 'EMERGENCY'" class="inline-flex items-center gap-1 text-[11px] font-bold uppercase text-red-600 dark:text-red-300"><span class="h-1.5 w-1.5 rounded-full bg-red-500"></span> Urgence</span>
                <span class="text-xs text-muted-foreground">Démarré le {{ formatDateTime(passage.episode.started_at) }} · {{ formatRelativeTime(passage.episode.started_at) }}</span>
                <span v-if="passage.orientations.length > 1" class="ms-auto text-xs font-semibold text-muted-foreground">{{ pluralize(passage.orientations.length, 'demande de soins', 'demandes de soins') }}</span>
            </header>

            <ul class="divide-y divide-border">
                <li v-for="(orientation, index) in passage.orientations" :key="orientation.uuid" class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span v-if="passage.orientations.length > 1" class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-bold text-foreground">Demande {{ index + 1 }}</span>
                            <span :class="['inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold', statusPillClass(pillStatus(orientation))]">{{ statusLabel(orientation) }}</span>
                            <span :class="['text-xs font-bold tabular-nums', elapsedTone(orientation)]">{{ elapsedLabel(orientation) }}</span>
                            <span class="text-xs text-muted-foreground">{{ elapsedCaption(orientation) }}</span>
                        </div>

                        <ul v-if="requestItems(orientation).length" class="flex flex-wrap gap-1.5">
                            <li v-for="(item, itemIndex) in requestItems(orientation)" :key="itemIndex" class="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-2 py-1 text-xs">
                                <component :is="ITEM_ICONS[item.state] ?? Clock" :class="['h-3.5 w-3.5 shrink-0', ITEM_TONES[item.state]]" aria-hidden="true" />
                                <span :class="['font-semibold text-foreground', item.state === 'CANCELLED' && 'line-through opacity-60']">{{ item.name }}</span>
                                <span v-if="itemQuantity(item.quantity)" class="text-muted-foreground">{{ itemQuantity(item.quantity) }}</span>
                                <span class="text-muted-foreground">· {{ item.state_label }}</span>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground">{{ designationSummary(orientation) }}</p>

                        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs text-muted-foreground">
                            <span>{{ orientation.source_label }}</span>
                            <ArrowRight class="h-3 w-3" aria-hidden="true" />
                            <span class="font-semibold text-foreground">{{ orientation.destination_label }}</span>
                            <template v-if="orientation.care_request?.requested_by?.length"><span>· demandé par {{ orientation.care_request.requested_by.join(', ') }}</span></template>
                            <template v-if="orientation.accepted_by"><span>· pris en charge par {{ orientation.accepted_by }}</span></template>
                        </p>

                        <!-- La suite décidée par le médecin : retour en Médecine, ou sortie directe. -->
                        <p
                            v-if="orientation.care_request?.follow_up"
                            :class="[
                                'inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium',
                                orientation.care_request.follow_up.code === 'RETURN_TO_MEDICINE'
                                    ? 'bg-primary/10 text-primary'
                                    : 'bg-amber-50 text-amber-800 dark:bg-amber-950/30 dark:text-amber-200',
                            ]"
                        >
                            <Undo2 v-if="orientation.care_request.follow_up.code === 'RETURN_TO_MEDICINE'" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            <LogOut v-else class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ orientation.care_request.follow_up.label }}
                        </p>
                    </div>

                    <div class="shrink-0">
                        <Button v-if="orientation.status === 'PENDING' && can('care.update')" size="sm" variant="primary" type="button" @click="skipGuard.request(orientation)"><Play class="h-4 w-4" />Prendre en charge</Button>
                        <Button v-else :as="Link" :href="`/care/orientations/${orientation.uuid}`" size="sm" variant="white-outline"><component :is="isEditableByMe(orientation) ? Pencil : Eye" class="h-4 w-4" />{{ isEditableByMe(orientation) ? 'Ouvrir la fiche' : 'Voir la fiche' }}</Button>
                    </div>
                </li>
            </ul>
        </section>

        <template v-if="openGroup && can('treatment_journal.view')" #footer>
            <p class="text-xs text-muted-foreground sm:me-auto sm:self-center">Tous les passages de ce patient, réunis dans un seul PDF.</p>
            <Button :as="Link" :href="`/patients/${openGroup.patient.uuid}/journaux-de-traitement`" size="sm" variant="white-outline">
                <NotebookText class="h-4 w-4" />Journaux de traitement (PDF)
            </Button>
        </template>
    </Dialog>
</template>
