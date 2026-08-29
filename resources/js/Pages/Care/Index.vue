<script setup>
import { computed, ref, watch } from 'vue';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import { usePermissions } from '@/composables/usePermissions';
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

const openGroup = ref(null);
</script>

<template>
    <Head title="Soins" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-gray-100 text-slate-600 dark:bg-gray-900 dark:text-slate-300">
                    <Icon class="text-2xl" name="user-check" />
                </span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-slate-700 dark:text-white">Soins</h1>
                    <p class="mt-1 text-sm text-slate-400">File d’évaluation et d’orientation des patients.</p>
                </div>
            </div>
            <Button v-if="can('patients.view')" :as="Link" href="/patients" size="rg" variant="white-outline">
                <Icon class="text-lg" name="users" />
                <span class="ms-2">Dossiers patients</span>
            </Button>
        </header>

        <Card class="overflow-hidden shadow-sm">
            <div class="flex flex-col gap-3 border-b border-gray-200 p-4 dark:border-gray-900 lg:flex-row lg:items-center lg:justify-between">
                <div class="inline-flex w-fit rounded border border-gray-200 bg-gray-50 p-1 dark:border-gray-900 dark:bg-gray-1000">
                    <button
                        type="button"
                        :class="['rounded px-3 py-1.5 text-sm font-semibold transition-colors', filter === 'active' ? 'bg-white text-slate-700 shadow-sm dark:bg-gray-900 dark:text-white' : 'text-slate-400 hover:text-slate-600']"
                        @click="selectFilter('active')"
                    >
                        File active <span class="ms-1 text-xs text-slate-400">{{ counts.active }}</span>
                    </button>
                    <button
                        type="button"
                        :class="['rounded px-3 py-1.5 text-sm font-semibold transition-colors', filter === 'oriented' ? 'bg-white text-slate-700 shadow-sm dark:bg-gray-900 dark:text-white' : 'text-slate-400 hover:text-slate-600']"
                        @click="selectFilter('oriented')"
                    >
                        Orientés <span class="ms-1 text-xs text-slate-400">{{ counts.oriented }}</span>
                    </button>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    <div class="relative w-full sm:max-w-xs sm:flex-1">
                        <Input v-model="query" icon="start" type="search" placeholder="Patient ou n° passage" autocomplete="off" />
                        <span class="pointer-events-none absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400">
                            <Icon class="text-lg" name="search" />
                        </span>
                    </div>
                    <div class="inline-flex w-fit rounded border border-gray-200 bg-gray-50 p-1 dark:border-gray-900 dark:bg-gray-1000">
                        <button type="button" :class="['rounded px-3 py-1.5 text-sm font-semibold transition-colors', !priority ? 'bg-white text-slate-700 shadow-sm dark:bg-gray-900 dark:text-white' : 'text-slate-400 hover:text-slate-600']" @click="selectPriority('')">
                            Tous <span class="ms-1 text-xs text-slate-400">{{ priorityCounts.all }}</span>
                        </button>
                        <button type="button" :class="['rounded px-3 py-1.5 text-sm font-semibold transition-colors', priority === 'emergency' ? 'bg-white text-red-600 shadow-sm dark:bg-gray-900 dark:text-red-300' : 'text-slate-400 hover:text-slate-600']" @click="selectPriority('emergency')">
                            Urgence <span class="ms-1 text-xs text-slate-400">{{ priorityCounts.emergency }}</span>
                        </button>
                        <button type="button" :class="['rounded px-3 py-1.5 text-sm font-semibold transition-colors', priority === 'normal' ? 'bg-white text-slate-700 shadow-sm dark:bg-gray-900 dark:text-white' : 'text-slate-400 hover:text-slate-600']" @click="selectPriority('normal')">
                            Normale <span class="ms-1 text-xs text-slate-400">{{ priorityCounts.normal }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[1040px] border-collapse">
                    <caption class="sr-only">File des patients aux Soins</caption>
                    <thead class="bg-gray-50/70 dark:bg-gray-1000/40">
                        <tr>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">N°</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Patient</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Passage</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Demande connue</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Arrivée</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Statut</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                        <tr v-for="group in groupedRows" :key="group.patient.uuid" class="hover:bg-gray-50/70 dark:hover:bg-gray-1000/40">
                            <td class="px-5 py-3">
                                <span v-if="group.orientations.length === 1 && group.orientations[0].queue_number" class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-primary-100 text-sm font-bold text-primary-700 dark:bg-primary-950/40 dark:text-primary-300">{{ group.orientations[0].queue_number }}</span>
                                <span v-else class="text-slate-300 dark:text-slate-700">—</span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex min-w-[230px] items-center gap-3">
                                    <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(group.patient)" />
                                    <div class="min-w-0">
                                        <Link v-if="can('patients.view')" :href="`/patients/${group.patient.uuid}`" class="block truncate text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white">
                                            {{ formatPatientName(group.patient) }}
                                        </Link>
                                        <span v-else class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(group.patient) }}</span>
                                        <span class="inline-flex items-center gap-1 text-xs text-slate-400"><Icon class="text-sm" name="folder" />{{ group.patient.patient_number }}</span>
                                    </div>
                                </div>
                            </td>

                            <template v-if="group.orientations.length === 1">
                                <td class="px-5 py-3">
                                    <span class="font-mono text-sm font-semibold text-slate-600 dark:text-slate-300">{{ group.orientations[0].episode.episode_number }}</span>
                                    <span :class="['mt-1 flex items-center gap-1.5 text-xs font-semibold', group.orientations[0].episode.priority === 'EMERGENCY' ? 'text-red-600 dark:text-red-300' : 'text-slate-400']">
                                        <span :class="['h-1.5 w-1.5 rounded-full', group.orientations[0].episode.priority === 'EMERGENCY' ? 'bg-red-500' : 'bg-slate-300']" />
                                        {{ group.orientations[0].episode.priority === 'EMERGENCY' ? 'Urgence' : 'Normal' }}
                                    </span>
                                </td>
                                <td class="max-w-[310px] px-5 py-3 text-sm text-slate-500 dark:text-slate-300">
                                    {{ designationSummary(group.orientations[0]) }}
                                </td>
                                <td class="px-5 py-3">
                                    <span class="block text-sm text-slate-600 dark:text-slate-300">{{ formatDateTime(group.orientations[0].episode.started_at) }}</span>
                                    <span class="text-xs text-slate-400">{{ formatRelativeTime(group.orientations[0].episode.started_at) }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-600 dark:text-slate-300">
                                        <span :class="['h-1.5 w-1.5 rounded-full', group.orientations[0].status === 'IN_PROGRESS' ? 'bg-primary-500' : group.orientations[0].status === 'COMPLETED' ? 'bg-emerald-500' : 'bg-amber-500']" />
                                        {{ group.orientations[0].status_label }}
                                    </span>
                                    <span v-if="group.orientations[0].accepted_by" class="mt-1 block text-xs text-slate-400">par {{ group.orientations[0].accepted_by }}</span>
                                </td>
                                <td class="px-5 py-3 text-end">
                                    <Link v-if="group.orientations[0].status === 'PENDING' && can('care.update')" :href="`/care/orientations/${group.orientations[0].uuid}/accept`" method="post" as="button" preserve-scroll>
                                        <Button size="sm" variant="white-outline">Prendre en charge</Button>
                                    </Link>
                                    <Button v-else :as="Link" :href="`/care/orientations/${group.orientations[0].uuid}`" size="sm" variant="white-outline"><Icon class="text-base" :name="group.orientations[0].status === 'COMPLETED' ? 'eye' : 'edit'" /><span class="ms-1.5">{{ group.orientations[0].status === 'COMPLETED' ? 'Voir la fiche' : 'Ouvrir la fiche' }}</span></Button>
                                </td>
                            </template>

                            <template v-else>
                                <td class="px-5 py-3">
                                    <span class="block text-sm font-semibold text-slate-700 dark:text-white">{{ group.orientations.length }} passages</span>
                                </td>
                                <td class="max-w-[310px] px-5 py-3 text-sm text-slate-500 dark:text-slate-300">{{ group.orientations.length }} demandes différentes</td>
                                <td class="px-5 py-3">
                                    <span class="block text-sm text-slate-600 dark:text-slate-300">{{ formatDateTime(latestOrientation(group).episode.started_at) }}</span>
                                    <span class="text-xs text-slate-400">Dernier passage · {{ formatRelativeTime(latestOrientation(group).episode.started_at) }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-semibold text-slate-600 dark:text-slate-300">
                                        <span v-if="statusCounts(group).PENDING" class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-amber-500" />{{ statusCounts(group).PENDING }} en attente</span>
                                        <span v-if="statusCounts(group).IN_PROGRESS" class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-primary-500" />{{ statusCounts(group).IN_PROGRESS }} en cours</span>
                                        <span v-if="statusCounts(group).COMPLETED" class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />{{ statusCounts(group).COMPLETED }} orienté(s)</span>
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-end">
                                    <Button size="sm" variant="white-outline" type="button" @click="openGroup = group"><Icon class="text-base" name="list" /><span class="ms-1.5">Voir les {{ group.orientations.length }} passages</span></Button>
                                </td>
                            </template>
                        </tr>
                        <tr v-if="groupedRows.length === 0">
                            <td colspan="7" class="px-5 py-12 text-center">
                                <Icon class="text-2xl text-slate-300" name="user-check" />
                                <p class="mt-2 text-sm font-semibold text-slate-600 dark:text-slate-300">Aucun patient dans cette file</p>
                                <p class="mt-1 text-xs text-slate-400">Les nouveaux passages apparaîtront ici automatiquement.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-gray-200 md:hidden dark:divide-gray-900">
                <article v-for="group in groupedRows" :key="group.patient.uuid" class="p-4">
                    <div class="flex items-start gap-3">
                        <span v-if="group.orientations.length === 1 && group.orientations[0].queue_number" class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-100 text-sm font-bold text-primary-700 dark:bg-primary-950/40 dark:text-primary-300">{{ group.orientations[0].queue_number }}</span>
                        <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(group.patient)" />
                        <div class="min-w-0 flex-1">
                            <p class="font-bold text-slate-700 dark:text-white">{{ formatPatientName(group.patient) }}</p>
                            <template v-if="group.orientations.length === 1">
                                <p class="mt-0.5 text-xs text-slate-400">{{ group.orientations[0].episode.episode_number }} · {{ formatRelativeTime(group.orientations[0].episode.started_at) }}</p>
                                <p class="mt-3 text-sm text-slate-500">{{ designationSummary(group.orientations[0]) }}</p>
                                <div class="mt-4">
                                    <Link v-if="group.orientations[0].status === 'PENDING' && can('care.update')" :href="`/care/orientations/${group.orientations[0].uuid}/accept`" method="post" as="button" preserve-scroll><Button block size="sm" variant="white-outline">Prendre en charge</Button></Link>
                                    <Button v-else :as="Link" :href="`/care/orientations/${group.orientations[0].uuid}`" block size="sm" variant="white-outline"><Icon class="text-base" :name="group.orientations[0].status === 'COMPLETED' ? 'eye' : 'edit'" /><span class="ms-1.5">{{ group.orientations[0].status === 'COMPLETED' ? 'Voir la fiche' : 'Ouvrir la fiche' }}</span></Button>
                                </div>
                            </template>
                            <template v-else>
                                <p class="mt-0.5 text-xs text-slate-400">{{ group.orientations.length }} passages</p>
                                <div class="mt-4">
                                    <Button block size="sm" variant="white-outline" type="button" @click="openGroup = group"><Icon class="text-base" name="list" /><span class="ms-1.5">Voir les {{ group.orientations.length }} passages</span></Button>
                                </div>
                            </template>
                        </div>
                    </div>
                </article>
            </div>

            <div v-if="orientations.last_page > 1" class="flex items-center justify-between border-t border-gray-200 p-4 text-xs text-slate-400 dark:border-gray-900">
                <span>Page {{ orientations.current_page }} sur {{ orientations.last_page }}</span>
                <div class="flex gap-2">
                    <Link v-if="orientations.prev_page_url" :href="orientations.prev_page_url" preserve-state class="rounded border border-gray-200 px-3 py-1.5 dark:border-gray-800">Précédent</Link>
                    <Link v-if="orientations.next_page_url" :href="orientations.next_page_url" preserve-state class="rounded border border-gray-200 px-3 py-1.5 dark:border-gray-800">Suivant</Link>
                </div>
            </div>
        </Card>
    </div>

    <Dialog :open="Boolean(openGroup)" as="div" class="relative z-[1200]" @close="openGroup = null">
        <div class="fixed inset-0 bg-slate-950/60" aria-hidden="true"></div>
        <div class="fixed inset-0 overflow-y-auto p-4">
            <div class="flex min-h-full items-center justify-center">
                <DialogPanel v-if="openGroup" class="w-full max-w-3xl overflow-hidden rounded-lg border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-950">
                    <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                        <div class="flex min-w-0 items-center gap-3">
                            <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(openGroup.patient)" />
                            <div class="min-w-0">
                                <DialogTitle class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(openGroup.patient) }}</DialogTitle>
                                <p class="text-xs text-slate-400">{{ openGroup.patient.patient_number }} · {{ openGroup.orientations.length }} passages</p>
                            </div>
                        </div>
                        <button type="button" class="text-slate-400 hover:text-slate-600" aria-label="Fermer" @click="openGroup = null"><Icon class="text-xl" name="cross" /></button>
                    </div>
                    <div class="max-h-[70vh] divide-y divide-gray-100 overflow-y-auto dark:divide-gray-900">
                        <div v-for="orientation in openGroup.orientations" :key="orientation.uuid" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-sm font-semibold text-slate-700 dark:text-white">{{ orientation.episode.episode_number }}</span>
                                    <span v-if="orientation.episode.priority === 'EMERGENCY'" class="inline-flex items-center gap-1 text-[11px] font-bold uppercase text-red-600 dark:text-red-300"><span class="h-1.5 w-1.5 rounded-full bg-red-500"></span> Urgence</span>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-300"><span :class="['h-1.5 w-1.5 rounded-full', orientation.status === 'IN_PROGRESS' ? 'bg-primary-500' : orientation.status === 'COMPLETED' ? 'bg-emerald-500' : 'bg-amber-500']" />{{ orientation.status_label }}</span>
                                </div>
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-300">{{ designationSummary(orientation) }}</p>
                                <p class="mt-1 text-xs text-slate-400">{{ formatDateTime(orientation.episode.started_at) }} · {{ formatRelativeTime(orientation.episode.started_at) }}<template v-if="orientation.accepted_by"> · par {{ orientation.accepted_by }}</template></p>
                            </div>
                            <div class="shrink-0">
                                <Link v-if="orientation.status === 'PENDING' && can('care.update')" :href="`/care/orientations/${orientation.uuid}/accept`" method="post" as="button" preserve-scroll>
                                    <Button size="sm" variant="white-outline">Prendre en charge</Button>
                                </Link>
                                <Button v-else :as="Link" :href="`/care/orientations/${orientation.uuid}`" size="sm" variant="white-outline"><Icon class="text-base" :name="orientation.status === 'COMPLETED' ? 'eye' : 'edit'" /><span class="ms-1.5">{{ orientation.status === 'COMPLETED' ? 'Voir la fiche' : 'Ouvrir la fiche' }}</span></Button>
                            </div>
                        </div>
                    </div>
                </DialogPanel>
            </div>
        </div>
    </Dialog>
</template>
