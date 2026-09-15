<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
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

    return 'text-slate-600 dark:text-slate-300';
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
    { value: 'all', label: 'Tous', icon: 'list' },
    { value: 'waiting', label: 'À prendre en charge', icon: 'clock' },
    { value: 'in_progress', label: 'En consultation', icon: 'activity' },
    { value: 'emergency', label: 'Urgences', icon: 'alert-circle' },
];

// Three distinct workflow states, each with its own wording and colour so
// "not yet seen" is never confused with "seen, but blocked mid-consultation"
// — both used to read "En attente" with the same amber dot.
const statusMeta = (orientation) => {
    if (orientation.status === 'PENDING') {
        return {
            label: 'À prendre en charge',
            classes: 'border-gray-200 text-slate-500 dark:border-gray-800 dark:text-slate-400',
            dot: 'bg-slate-400',
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
        classes: 'border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-900 dark:bg-primary-950/30 dark:text-primary-300',
        dot: 'bg-primary-500',
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
                <span class="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-gray-100 text-slate-600 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-2xl" name="activity" /></span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-slate-700 dark:text-white">Médecine</h1>
                    <p class="mt-1 text-sm text-slate-400">File de consultation — par ordre d’arrivée, urgences non encore vues en tête.</p>
                </div>
            </div>
            <Button v-if="can('patients.view')" :as="Link" href="/patients" size="rg" variant="white-outline"><Icon class="text-lg" name="users" /><span class="ms-2">Dossiers patients</span></Button>
        </header>

        <div
            v-if="queuePulse.unattendedEmergencies || queuePulse.longestMinutes >= 60"
            :class="['flex flex-col gap-2 rounded-lg border px-4 py-3 sm:flex-row sm:items-center sm:justify-between', queuePulse.unattendedEmergencies
                ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/20 dark:text-red-200'
                : 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200']"
            role="status"
        >
            <p class="flex items-start gap-2 text-sm font-semibold">
                <Icon name="alert-circle" class="mt-0.5 shrink-0 text-base" />
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

        <Card class="overflow-hidden shadow-sm">
            <div class="flex flex-col gap-3 border-b border-gray-200 p-4 dark:border-gray-900 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex w-full overflow-x-auto rounded border border-gray-200 bg-gray-50 p-1 dark:border-gray-900 dark:bg-gray-1000 lg:w-fit">
                    <button
                        v-for="tab in tabs"
                        :key="tab.value"
                        type="button"
                        :class="['inline-flex shrink-0 items-center gap-2 rounded px-3 py-1.5 text-sm font-semibold transition-colors', filter === tab.value ? 'bg-white shadow-sm dark:bg-gray-900' : 'text-slate-400 hover:text-slate-600', filter === tab.value ? (tab.value === 'emergency' ? 'text-red-700 dark:text-red-300' : 'text-slate-700 dark:text-white') : '']"
                        :aria-pressed="filter === tab.value"
                        @click="selectFilter(tab.value)"
                    >
                        <Icon class="text-base" :name="tab.icon" />
                        <span>{{ tab.label }}</span>
                        <span :class="['min-w-5 rounded px-1.5 py-0.5 text-center text-[10px] font-bold', tab.value === 'emergency' && counts[tab.value] > 0 ? 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' : 'bg-gray-100 text-slate-500 dark:bg-gray-800 dark:text-slate-300']">{{ counts[tab.value] }}</span>
                    </button>
                </div>
                <div class="relative w-full lg:max-w-xs">
                    <Input v-model="query" icon="start" type="search" placeholder="Patient ou n° passage" autocomplete="off" />
                    <span class="pointer-events-none absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400"><Icon class="text-lg" name="search" /></span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[1000px] border-collapse">
                    <caption class="sr-only">File des patients en Médecine</caption>
                    <thead class="bg-gray-50/70 dark:bg-gray-1000/40">
                        <tr>
                            <th class="w-14 border-b border-gray-200 px-4 py-2.5 text-center text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">N°</th>
                            <th class="border-b border-gray-200 px-4 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Patient</th>
                            <th class="border-b border-gray-200 px-4 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Passage</th>
                            <th class="border-b border-gray-200 px-4 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Motif de venue</th>
                            <th class="w-28 border-b border-gray-200 px-4 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Attente</th>
                            <th class="border-b border-gray-200 px-4 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Statut</th>
                            <th class="border-b border-gray-200 px-4 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                        <tr
                            v-for="orientation in orientations.data"
                            :key="orientation.uuid"
                            :class="['transition-colors', isEmergency(orientation)
                                ? 'bg-red-50/40 hover:bg-red-50/70 dark:bg-red-950/10 dark:hover:bg-red-950/20'
                                : 'hover:bg-gray-50/70 dark:hover:bg-gray-1000/40']"
                        >
                            <td :class="['border-s-2 px-4 py-3 text-center', isEmergency(orientation) ? 'border-s-red-500' : 'border-s-transparent']">
                                <span v-if="orientation.queue_number" :class="['inline-flex h-7 w-7 items-center justify-center rounded-full text-sm font-bold', isEmergency(orientation) ? 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300' : 'bg-primary-100 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300']">{{ orientation.queue_number }}</span>
                                <!-- Déjà pris en charge : la place dans l'attente est consommée,
                                     le numéro passe au patient suivant. -->
                                <span v-else-if="orientation.status === 'IN_PROGRESS'" class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-emerald-50 text-sm text-emerald-600 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-300 dark:ring-emerald-900" title="Déjà pris en charge — ne compte plus dans l'attente" aria-label="Déjà pris en charge"><Icon name="activity" /></span>
                                <span v-else class="text-slate-300 dark:text-slate-700">—</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex min-w-[220px] items-center gap-3">
                                    <Avatar rounded size="sm" :variant="isEmergency(orientation) ? 'danger-pale' : 'slate-pale'" :text="formatPatientInitials(orientation.episode.patient)" aria-hidden="true" />
                                    <div class="min-w-0">
                                        <Link v-if="can('patients.view')" :href="`/patients/${orientation.episode.patient.uuid}`" class="block truncate text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white dark:hover:text-primary-400">{{ formatPatientName(orientation.episode.patient) }}</Link>
                                        <span v-else class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(orientation.episode.patient) }}</span>
                                        <span class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-400">
                                            <span class="inline-flex items-center gap-1 font-medium"><Icon class="text-sm" name="folder" />{{ orientation.episode.patient.patient_number }}</span>
                                            <span>{{ patientProfile(orientation.episode.patient) }}</span>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="block font-mono text-xs font-semibold text-slate-600 dark:text-slate-300">{{ orientation.episode.episode_number }}</span>
                                <span class="mt-0.5 flex items-center gap-1 text-[11px] text-slate-400">
                                    <Icon class="text-xs" name="arrow-right" />{{ orientation.source_label }} · {{ formatDateTime(orientation.oriented_at) }}
                                </span>
                            </td>
                            <td class="max-w-[300px] px-4 py-3">
                                <span v-if="designationSummary(orientation)" class="block text-xs text-slate-600 dark:text-slate-300">{{ designationSummary(orientation) }}</span>
                                <span v-else class="block text-xs italic text-slate-400">Motif à préciser en consultation</span>
                                <span v-if="orientation.reason" class="mt-0.5 block truncate text-[11px] text-slate-400" :title="orientation.reason">{{ orientation.reason }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="['inline-flex items-center gap-1.5 text-sm font-bold', waitTone(orientation)]">
                                    <Icon class="text-sm" name="clock" />{{ waitedLabel(orientation) }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="['inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2 py-1 text-[11px] font-semibold', statusMeta(orientation).classes]">
                                    <span :class="['h-1.5 w-1.5 rounded-full', statusMeta(orientation).dot]" />
                                    {{ statusMeta(orientation).label }}
                                </span>
                                <span v-if="orientation.is_waiting_on_results" class="mt-1 block max-w-[220px] text-[11px] text-amber-600 dark:text-amber-400">{{ orientation.pending_reasons.join(' · ') }}</span>
                                <span v-if="orientation.accepted_by" class="mt-1 block text-[11px] text-slate-400">Dr {{ orientation.accepted_by }}</span>
                            </td>
                            <td class="px-4 py-3 text-end">
                                <Button v-if="orientation.status === 'PENDING' && can('consultations.create')" size="sm" type="button" :variant="isEmergency(orientation) ? 'danger' : 'primary'" @click="requestAccept(orientation)"><Icon class="text-base" name="play" /><span class="ms-1.5">Prendre en charge</span></Button>
                                <Button v-else-if="orientation.has_consultation" :as="Link" :href="`/medicine/orientations/${orientation.uuid}/dossier`" size="sm" variant="white-outline"><Icon class="text-base" :name="orientation.is_waiting_on_results ? 'reload' : 'eye'" /><span class="ms-1.5">{{ orientation.is_waiting_on_results ? 'Reprendre' : 'Ouvrir' }}</span></Button>
                                <Link v-else-if="can('consultations.create')" :href="`/medicine/orientations/${orientation.uuid}/accept`" method="post" as="button" preserve-scroll>
                                    <Button size="sm" variant="white-outline"><Icon class="text-base" name="eye" /><span class="ms-1.5">Ouvrir</span></Button>
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="orientations.data.length === 0">
                            <td colspan="7" class="px-5 py-14 text-center">
                                <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-xl" name="activity" /></span>
                                <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-200">Aucun patient dans cette file</p>
                                <p class="mt-1 text-xs text-slate-400">{{ EMPTY_STATES[filter] ?? EMPTY_STATES.all }}</p>
                                <Button v-if="filter !== 'all'" class="mt-4" size="sm" variant="white-outline" type="button" @click="selectFilter('all')">
                                    <Icon class="text-base" name="list" /><span class="ms-1.5">Voir toute la file</span>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="orientations.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 p-4 text-xs text-slate-400 dark:border-gray-900">
                <span>{{ orientations.from }}–{{ orientations.to }} sur {{ orientations.total }} · page {{ orientations.current_page }} sur {{ orientations.last_page }}</span>
                <div class="flex gap-2">
                    <Link v-if="orientations.prev_page_url" :href="orientations.prev_page_url" preserve-state class="rounded border border-gray-200 px-3 py-1.5 font-semibold text-slate-500 transition-colors hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-900">Précédent</Link>
                    <Link v-if="orientations.next_page_url" :href="orientations.next_page_url" preserve-state class="rounded border border-gray-200 px-3 py-1.5 font-semibold text-slate-500 transition-colors hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-900">Suivant</Link>
                </div>
            </div>
        </Card>
    </div>

    <!-- Confirmation, jamais blocage : le médecin garde la décision, on lui
         rappelle seulement qui attend devant. -->
    <div v-if="skipConfirm" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/55 p-4" role="presentation" @click.self="skipConfirm = null">
        <section class="w-full max-w-lg overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="skip-queue-title">
            <header class="flex items-start gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-full', skippedEmergencies.length ? 'bg-red-50 text-red-600 dark:bg-red-950/30 dark:text-red-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300']">
                    <Icon class="text-xl" name="alert-circle" />
                </span>
                <div class="min-w-0">
                    <h2 id="skip-queue-title" class="font-heading text-base font-bold text-slate-700 dark:text-white">
                        {{ skippedEmergencies.length ? 'Une urgence attend avant ce patient' : 'Un patient attend avant celui-ci' }}
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-400">
                        Vous allez prendre en charge {{ formatPatientName(skipConfirm.orientation.episode.patient) }}.
                    </p>
                </div>
            </header>

            <div class="space-y-3 px-5 py-4">
                <ul v-if="skipConfirm.ahead.length" class="space-y-1.5">
                    <li
                        v-for="row in skipConfirm.ahead.slice(0, 4)"
                        :key="row.uuid"
                        :class="['flex items-center justify-between gap-3 rounded border px-3 py-2 text-sm', isEmergency(row) ? 'border-red-200 bg-red-50/60 dark:border-red-900 dark:bg-red-950/20' : 'border-gray-200 dark:border-gray-800']"
                    >
                        <span class="min-w-0">
                            <span class="block truncate font-semibold text-slate-700 dark:text-white">{{ formatPatientName(row.episode.patient) }}</span>
                            <span class="text-xs text-slate-400">{{ row.episode.episode_number }}<template v-if="isEmergency(row)"> · Urgence</template></span>
                        </span>
                        <span :class="['shrink-0 text-sm font-bold', waitTone(row)]">{{ waitedLabel(row) }}</span>
                    </li>
                </ul>
                <p v-if="skipConfirm.ahead.length > 4" class="text-xs text-slate-400">
                    … et {{ skipConfirm.ahead.length - 4 }} autre{{ skipConfirm.ahead.length - 4 > 1 ? 's' : '' }} patient{{ skipConfirm.ahead.length - 4 > 1 ? 's' : '' }} avant celui-ci.
                </p>
                <p v-if="skipConfirm.earlierPages" class="flex items-start gap-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
                    <Icon class="mt-px shrink-0" name="info" />Les pages précédentes de la file contiennent d’autres patients arrivés avant, non affichés ici.
                </p>
                <p class="text-xs leading-5 text-slate-500 dark:text-slate-400">
                    Prendre ce patient d’abord reste possible — dossier incomplet, patient absent, priorité clinique.
                    Ce rappel n’empêche rien.
                </p>
            </div>

            <footer class="flex flex-col-reverse gap-2 border-t border-gray-200 bg-gray-50/60 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/30 sm:flex-row sm:justify-end">
                <Button size="rg" type="button" variant="white-outline" @click="skipConfirm = null">Annuler</Button>
                <Button size="rg" type="button" :variant="skippedEmergencies.length ? 'danger' : 'primary'" @click="confirmSkip">
                    <Icon class="me-1.5 text-base" name="play" />Prendre celui-ci quand même
                </Button>
            </footer>
        </section>
    </div>
</template>
