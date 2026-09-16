<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import Avatar from '@/Components/Shadcn/Avatar.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { Activity, ArrowRight, CircleAlert, Clock, Eye, Folder, Info, List, Play, RefreshCw, Search, Siren, Users } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({ orientations: Object, counts: Object, filter: String, search: String });
const { can } = usePermissions();
const query = ref(props.search ?? '');

const visit = (params) => router.get('/medicine', params, { preserveState: true, preserveScroll: true, replace: true });
const selectFilter = (filter) => visit({ ...(query.value ? { q: query.value } : {}), ...(filter !== 'all' ? { filter } : {}) });

let debounceTimer = null;
watch(query, (value) => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => visit({ ...(value ? { q: value } : {}), ...(props.filter !== 'all' ? { filter: props.filter } : {}) }), 350);
});

// A queue is read live: waiting times must keep moving without a reload,
// otherwise "12 min" quietly becomes an hour old on a screen left open.
const now = ref(Date.now());
const clock = setInterval(() => { now.value = Date.now(); }, 30_000);
onBeforeUnmount(() => {
    clearInterval(clock);
    clearTimeout(debounceTimer);
});

const waitedMinutes = (orientation) => Math.max(
    0,
    Math.round((now.value - new Date(orientation.oriented_at).getTime()) / 60000),
);
const waitedLabel = (orientation) => {
    const minutes = waitedMinutes(orientation);

    if (minutes < 60) return `${minutes} min`;

    return `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')}`;
};

/**
 * Service-level escalation only — how long someone has been waiting in the
 * queue. It is never a clinical severity: a calm patient waiting two hours
 * turns red, an emergency seen at once does not.
 */
const waitTone = (orientation) => {
    const minutes = waitedMinutes(orientation);

    if (minutes >= 60) return 'text-red-600 dark:text-red-300';
    if (minutes >= 30) return 'text-amber-600 dark:text-amber-300';

    return 'text-muted-foreground';
};

const isEmergency = (orientation) => orientation.episode.priority === 'EMERGENCY';

/* ------------------------------------------------------------------ *
 * Sauter un patient de la file : on demande, on n'interdit pas.
 *
 * Prendre le n° 2 avant le n° 1 est parfois la bonne décision — le
 * premier est aux toilettes, son dossier n'est pas remonté, il n'est pas
 * revenu des Soins. Aucune règle du CDC n'impose l'ordre d'arrivée, et le
 * serveur ne bloque donc rien : ce garde-fou est ergonomique, il évite
 * l'oubli, pas la décision.
 * ------------------------------------------------------------------ */

/** Les patients encore à prendre en charge placés avant celui-ci. */
const pendingAhead = (orientation) => {
    const rows = props.orientations.data;
    const index = rows.findIndex((row) => row.uuid === orientation.uuid);

    return index <= 0 ? [] : rows.slice(0, index).filter((row) => row.status === 'PENDING');
};

const skipConfirm = ref(null);

const goToOrientation = (orientation) => router.post(
    `/medicine/orientations/${orientation.uuid}/accept`,
    {},
    { preserveScroll: true },
);

const requestAccept = (orientation) => {
    const ahead = pendingAhead(orientation);
    // Une page précédente contient forcément des patients arrivés avant,
    // que cette page n'affiche pas : on ne peut pas prétendre le contraire.
    const earlierPages = (props.orientations.current_page ?? 1) > 1;

    if (ahead.length === 0 && ! earlierPages) {
        goToOrientation(orientation);

        return;
    }

    skipConfirm.value = { orientation, ahead, earlierPages };
};

const confirmSkip = () => {
    const target = skipConfirm.value?.orientation;
    skipConfirm.value = null;

    if (target) goToOrientation(target);
};

// Passer devant une urgence n'est pas passer devant une attente ordinaire :
// le message change de ton pour que la différence se voie.
const skippedEmergencies = computed(() => (skipConfirm.value?.ahead ?? []).filter(isEmergency));

// Full words, as on the patient directory: a queue cell has room for them
// and "H"/"F" is jargon the app avoids elsewhere.
const SEX_LABELS = { M: 'Homme', F: 'Femme' };
const patientProfile = (patient) => {
    const age = patient.age;
    const parts = [SEX_LABELS[patient.sex] ?? '—'];

    parts.push(age === null || age === undefined ? 'âge N/R' : `${age} ans`);

    return parts.join(' · ');
};

const designations = (orientation) => orientation.episode.designations ?? [];
const designationSummary = (orientation) => {
    const items = designations(orientation);

    if (items.length === 0) return null;

    const labels = items.slice(0, 2).map((item) => item.description).join(' · ');

    return items.length > 2 ? `${labels} · +${items.length - 2}` : labels;
};

const tabs = [
    { value: 'all', label: 'Toute la file', hint: 'Patients orientés vers Médecine', icon: List, tone: 'neutral' },
    { value: 'waiting', label: 'À prendre en charge', hint: 'En attente d’un médecin', icon: Clock, tone: 'amber' },
    { value: 'in_progress', label: 'En consultation', hint: 'Dossiers ouverts', icon: Activity, tone: 'primary' },
    { value: 'emergency', label: 'Urgences', hint: 'Priorité du passage', icon: Siren, tone: 'red' },
];

// Three distinct workflow states, each with its own wording and colour so
// "not yet seen" is never confused with "seen, but blocked mid-consultation"
// — both used to read "En attente" with the same amber dot.
const statusMeta = (orientation) => {
    if (orientation.status === 'PENDING') {
        return {
            label: 'À prendre en charge',
            classes: 'border-border text-muted-foreground dark:text-muted-foreground',
            dot: 'bg-muted-foreground',
        };
    }
    if (orientation.is_waiting_on_results) {
        return {
            label: 'En attente de résultat',
            classes: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300',
            dot: 'bg-amber-500',
        };
    }

    return {
        label: 'En consultation',
        classes: 'border-primary/30 bg-primary/10 text-primary ',
        dot: 'bg-primary/100',
    };
};

/**
 * What the doctor needs to know about the queue without scanning it: an
 * emergency still unattended, and the longest wait.
 */
const queuePulse = computed(() => {
    const rows = props.orientations.data;
    const unattendedEmergencies = rows.filter(
        (orientation) => isEmergency(orientation) && orientation.status === 'PENDING',
    ).length;
    const longest = rows
        .filter((orientation) => orientation.status === 'PENDING')
        .reduce((worst, orientation) => (
            !worst || waitedMinutes(orientation) > waitedMinutes(worst) ? orientation : worst
        ), null);

    return {
        unattendedEmergencies,
        longest,
        longestMinutes: longest ? waitedMinutes(longest) : 0,
    };
});

const EMPTY_STATES = {
    all: 'Les patients apparaissent ici selon la désignation d’arrivée, la transmission des Soins ou une urgence.',
    waiting: 'Aucun patient n’attend une prise en charge médicale.',
    in_progress: 'Aucune consultation n’est ouverte en ce moment.',
    emergency: 'Aucune urgence en cours dans cette file.',
};
</script>

<template>
    <Head title="Médecine" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-4">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground"><Activity class="h-6 w-6" /></span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-foreground">Médecine</h1>
                    <p class="mt-1 text-sm text-muted-foreground">File de consultation — par ordre d’arrivée, urgences non encore vues en tête.</p>
                </div>
            </div>
            <Button v-if="can('patients.view')" :as="Link" href="/patients" size="rg" variant="white-outline"><Users class="h-4.5 w-4.5" />Dossiers patients</Button>
        </header>

        <div
            v-if="queuePulse.unattendedEmergencies || queuePulse.longestMinutes >= 60"
            :class="['flex flex-col gap-2 rounded-lg border px-4 py-3 sm:flex-row sm:items-center sm:justify-between', queuePulse.unattendedEmergencies
                ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/20 dark:text-red-200'
                : 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200']"
            role="status"
        >
            <p class="flex items-start gap-2 text-sm font-semibold">
                <CircleAlert class="mt-0.5 shrink-0 h-4 w-4" />
                <span>
                    <template v-if="queuePulse.unattendedEmergencies">
                        {{ queuePulse.unattendedEmergencies }} urgence{{ queuePulse.unattendedEmergencies > 1 ? 's' : '' }} en attente de prise en charge.
                    </template>
                    <template v-else>
                        Attente la plus longue : {{ waitedLabel(queuePulse.longest) }} — {{ formatPatientName(queuePulse.longest.episode.patient) }}.
                    </template>
                </span>
            </p>
            <button
                v-if="queuePulse.unattendedEmergencies && filter !== 'emergency'"
                type="button"
                class="shrink-0 text-xs font-bold underline decoration-dotted underline-offset-2"
                @click="selectFilter('emergency')"
            >
                Voir les urgences
            </button>
        </div>

        <!-- Les compteurs viennent du serveur : recalculés depuis la page
             affichée, ils mentiraient dès la deuxième page. -->
        <QueueCounters
            :tiles="tabs.map((tab) => ({ ...tab, count: counts[tab.value], active: filter === tab.value }))"
            @select="selectFilter"
        />

        <Card class="overflow-hidden shadow-sm">
            <div class="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:items-center lg:justify-between">
                <p class="text-xs text-muted-foreground">
                    <template v-if="filter === 'all'">Toute la file, par ordre d’arrivée.</template>
                    <template v-else>Filtrée sur « {{ tabs.find((tab) => tab.value === filter)?.label }} » — <button type="button" class="font-bold text-primary hover:underline" @click="selectFilter('all')">tout afficher</button>.</template>
                </p>
                <div class="relative w-full lg:max-w-xs">
                    <IconInput v-model="query" :icon="Search" type="search" placeholder="Patient ou n° passage" autocomplete="off" aria-label="Rechercher un patient" />
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[1000px] border-collapse">
                    <caption class="sr-only">File des patients en Médecine</caption>
                    <thead class="bg-muted/70 /40">
                        <tr>
                            <th class="w-14 border-b border-border px-4 py-2.5 text-center text-xs font-medium uppercase tracking-wide text-muted-foreground">N°</th>
                            <th class="border-b border-border px-4 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Patient</th>
                            <th class="border-b border-border px-4 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Passage</th>
                            <th class="border-b border-border px-4 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Motif de venue</th>
                            <th class="w-28 border-b border-border px-4 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Attente</th>
                            <th class="border-b border-border px-4 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-muted-foreground">Statut</th>
                            <th class="border-b border-border px-4 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-muted-foreground">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="orientation in orientations.data"
                            :key="orientation.uuid"
                            :class="['transition-colors', isEmergency(orientation)
                                ? 'bg-red-50/40 hover:bg-red-50/70 dark:bg-red-950/10 dark:hover:bg-red-950/20'
                                : 'hover:bg-muted/70 ']"
                        >
                            <td :class="['border-s-2 px-4 py-3 text-center', isEmergency(orientation) ? 'border-s-red-500' : 'border-s-transparent']">
                                <span v-if="orientation.queue_number" :class="['inline-flex h-7 w-7 items-center justify-center rounded-full text-sm font-bold', isEmergency(orientation) ? 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300' : 'bg-primary/15 text-primary /40 ']">{{ orientation.queue_number }}</span>
                                <!-- Déjà pris en charge : la place dans l'attente est consommée,
                                     le numéro passe au patient suivant. -->
                                <span v-else-if="orientation.status === 'IN_PROGRESS'" class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-emerald-50 text-sm text-emerald-600 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-300 dark:ring-emerald-900" title="Déjà pris en charge — ne compte plus dans l'attente" aria-label="Déjà pris en charge"><Activity class="h-4 w-4" /></span>
                                <span v-else class="text-muted-foreground dark:text-foreground">—</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex min-w-[220px] items-center gap-3">
                                    <Avatar size="sm" :variant="isEmergency(orientation) ? 'danger-pale' : 'slate-pale'" :text="formatPatientInitials(orientation.episode.patient)" aria-hidden="true" />
                                    <div class="min-w-0">
                                        <Link v-if="can('patients.view')" :href="`/patients/${orientation.episode.patient.uuid}`" class="block truncate text-sm font-bold text-foreground hover:text-primary dark:hover:text-primary">{{ formatPatientName(orientation.episode.patient) }}</Link>
                                        <span v-else class="block truncate text-sm font-bold text-foreground">{{ formatPatientName(orientation.episode.patient) }}</span>
                                        <span class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                            <span class="inline-flex items-center gap-1 font-medium"><Folder class="h-3.5 w-3.5" />{{ orientation.episode.patient.patient_number }}</span>
                                            <span>{{ patientProfile(orientation.episode.patient) }}</span>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="block font-mono text-xs font-semibold text-muted-foreground">{{ orientation.episode.episode_number }}</span>
                                <span class="mt-0.5 flex items-center gap-1 text-[11px] text-muted-foreground">
                                    <ArrowRight class="h-3 w-3" />{{ orientation.source_label }} · {{ formatDateTime(orientation.oriented_at) }}
                                </span>
                            </td>
                            <td class="max-w-[300px] px-4 py-3">
                                <span v-if="designationSummary(orientation)" class="block text-xs text-muted-foreground">{{ designationSummary(orientation) }}</span>
                                <span v-else class="block text-xs italic text-muted-foreground">Motif à préciser en consultation</span>
                                <span v-if="orientation.reason" class="mt-0.5 block truncate text-[11px] text-muted-foreground" :title="orientation.reason">{{ orientation.reason }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="['inline-flex items-center gap-1.5 text-sm font-bold', waitTone(orientation)]">
                                    <Clock class="h-3.5 w-3.5" />{{ waitedLabel(orientation) }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="['inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2 py-1 text-[11px] font-semibold', statusMeta(orientation).classes]">
                                    <span :class="['h-1.5 w-1.5 rounded-full', statusMeta(orientation).dot]" />
                                    {{ statusMeta(orientation).label }}
                                </span>
                                <span v-if="orientation.is_waiting_on_results" class="mt-1 block max-w-[220px] text-[11px] text-amber-600 dark:text-amber-400">{{ orientation.pending_reasons.join(' · ') }}</span>
                                <span v-if="orientation.accepted_by" class="mt-1 block text-[11px] text-muted-foreground">Dr {{ orientation.accepted_by }}</span>
                            </td>
                            <td class="px-4 py-3 text-end">
                                <Button v-if="orientation.status === 'PENDING' && can('consultations.create')" size="sm" type="button" :variant="isEmergency(orientation) ? 'danger' : 'primary'" @click="requestAccept(orientation)"><Play class="h-4 w-4" />Prendre en charge</Button>
                                <Button v-else-if="orientation.has_consultation" :as="Link" :href="`/medicine/orientations/${orientation.uuid}/dossier`" size="sm" variant="white-outline"><component :is="orientation.is_waiting_on_results ? RefreshCw : Eye" class="h-4 w-4" />{{ orientation.is_waiting_on_results ? 'Reprendre' : 'Ouvrir' }}</Button>
                                <Link v-else-if="can('consultations.create')" :href="`/medicine/orientations/${orientation.uuid}/accept`" method="post" as="button" preserve-scroll>
                                    <Button size="sm" variant="white-outline"><Eye class="h-4 w-4" />Ouvrir</Button>
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="orientations.data.length === 0">
                            <td colspan="7" class="px-5 py-14 text-center">
                                <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-muted text-muted-foreground"><Activity class="h-5 w-5" /></span>
                                <p class="mt-3 text-sm font-medium text-muted-foreground">Aucun patient dans cette file</p>
                                <p class="mt-1 text-xs text-muted-foreground">{{ EMPTY_STATES[filter] ?? EMPTY_STATES.all }}</p>
                                <Button v-if="filter !== 'all'" class="mt-4" size="sm" variant="white-outline" type="button" @click="selectFilter('all')">
                                    <List class="h-4 w-4" />Voir toute la file
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="orientations.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-border p-4 text-xs text-muted-foreground">
                <span>{{ orientations.from }}–{{ orientations.to }} sur {{ orientations.total }} · page {{ orientations.current_page }} sur {{ orientations.last_page }}</span>
                <div class="flex gap-2">
                    <Link v-if="orientations.prev_page_url" :href="orientations.prev_page_url" preserve-state class="rounded border border-border px-3 py-1.5 font-semibold text-muted-foreground transition-colors hover:bg-muted dark:hover:bg-muted">Précédent</Link>
                    <Link v-if="orientations.next_page_url" :href="orientations.next_page_url" preserve-state class="rounded border border-border px-3 py-1.5 font-semibold text-muted-foreground transition-colors hover:bg-muted dark:hover:bg-muted">Suivant</Link>
                </div>
            </div>
        </Card>
    </div>

    <!-- Confirmation, jamais blocage : le médecin garde la décision, on lui
         rappelle seulement qui attend devant. -->
    <div v-if="skipConfirm" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/55 p-4" role="presentation" @click.self="skipConfirm = null">
        <section class="w-full max-w-lg overflow-hidden rounded-lg border border-border bg-card shadow-xl" role="dialog" aria-modal="true" aria-labelledby="skip-queue-title">
            <header class="flex items-start gap-3 border-b border-border px-5 py-4">
                <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-full', skippedEmergencies.length ? 'bg-red-50 text-red-600 dark:bg-red-950/30 dark:text-red-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300']">
                    <CircleAlert class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <h2 id="skip-queue-title" class="font-heading text-base font-bold text-foreground">
                        {{ skippedEmergencies.length ? 'Une urgence attend avant ce patient' : 'Un patient attend avant celui-ci' }}
                    </h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Vous allez prendre en charge {{ formatPatientName(skipConfirm.orientation.episode.patient) }}.
                    </p>
                </div>
            </header>

            <div class="space-y-3 px-5 py-4">
                <ul v-if="skipConfirm.ahead.length" class="space-y-1.5">
                    <li
                        v-for="row in skipConfirm.ahead.slice(0, 4)"
                        :key="row.uuid"
                        :class="['flex items-center justify-between gap-3 rounded border px-3 py-2 text-sm', isEmergency(row) ? 'border-red-200 bg-red-50/60 dark:border-red-900 dark:bg-red-950/20' : 'border-border']"
                    >
                        <span class="min-w-0">
                            <span class="block truncate font-semibold text-foreground">{{ formatPatientName(row.episode.patient) }}</span>
                            <span class="text-xs text-muted-foreground">{{ row.episode.episode_number }}<template v-if="isEmergency(row)"> · Urgence</template></span>
                        </span>
                        <span :class="['shrink-0 text-sm font-bold', waitTone(row)]">{{ waitedLabel(row) }}</span>
                    </li>
                </ul>
                <p v-if="skipConfirm.ahead.length > 4" class="text-xs text-muted-foreground">
                    … et {{ skipConfirm.ahead.length - 4 }} autre{{ skipConfirm.ahead.length - 4 > 1 ? 's' : '' }} patient{{ skipConfirm.ahead.length - 4 > 1 ? 's' : '' }} avant celui-ci.
                </p>
                <p v-if="skipConfirm.earlierPages" class="flex items-start gap-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
                    <Info class="mt-px shrink-0 h-4 w-4" />Les pages précédentes de la file contiennent d’autres patients arrivés avant, non affichés ici.
                </p>
                <p class="text-xs leading-5 text-muted-foreground">
                    Prendre ce patient d’abord reste possible — dossier incomplet, patient absent, priorité clinique.
                    Ce rappel n’empêche rien.
                </p>
            </div>

            <footer class="flex flex-col-reverse gap-2 border-t border-border bg-muted/60 px-5 py-4 /30 sm:flex-row sm:justify-end">
                <Button size="rg" type="button" variant="white-outline" @click="skipConfirm = null">Annuler</Button>
                <Button size="rg" type="button" :variant="skippedEmergencies.length ? 'danger' : 'primary'" @click="confirmSkip">
                    <Play class="me-1.5 h-4 w-4" />Prendre celui-ci quand même
                </Button>
            </footer>
        </section>
    </div>
</template>
