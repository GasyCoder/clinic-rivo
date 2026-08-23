<script setup>
import { ref, watch } from 'vue';
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
    filter: String,
    search: String,
    priority: String,
});

const { can } = usePermissions();
const query = ref(props.search ?? '');
const priorityFilter = ref(props.priority ?? '');

const visit = (params) => router.get('/care', params, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
});

const buildParams = (overrides = {}) => ({
    ...(query.value ? { q: query.value } : {}),
    ...(props.filter !== 'active' ? { filter: props.filter } : {}),
    ...(priorityFilter.value ? { priority: priorityFilter.value } : {}),
    ...overrides,
});

const selectFilter = (filter) => visit(buildParams({ filter: filter !== 'active' ? filter : undefined }));
const submitPriority = () => visit(buildParams());

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
                    <span class="relative"><select v-model="priorityFilter" class="h-9 appearance-none bg-none rounded border border-gray-200 bg-white px-3 pe-9 text-sm text-slate-700 outline-none transition-all focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950" @change="submitPriority"><option value="">Toute priorité</option><option value="emergency">Urgence</option><option value="normal">Normal</option></select><span class="pointer-events-none absolute inset-y-0 end-0 flex w-9 items-center justify-center text-slate-400"><Icon class="text-sm" name="chevron-down" /></span></span>
                </div>
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[1040px] border-collapse">
                    <caption class="sr-only">File des patients aux Soins</caption>
                    <thead class="bg-gray-50/70 dark:bg-gray-1000/40">
                        <tr>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Patient</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Passage</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Demande connue</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Arrivée</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Statut</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                        <tr v-for="orientation in orientations.data" :key="orientation.uuid" class="hover:bg-gray-50/70 dark:hover:bg-gray-1000/40">
                            <td class="px-5 py-3">
                                <div class="flex min-w-[230px] items-center gap-3">
                                    <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(orientation.episode.patient)" />
                                    <div class="min-w-0">
                                        <Link v-if="can('patients.view')" :href="`/patients/${orientation.episode.patient.uuid}`" class="block truncate text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white">
                                            {{ formatPatientName(orientation.episode.patient) }}
                                        </Link>
                                        <span v-else class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(orientation.episode.patient) }}</span>
                                        <span class="text-xs text-slate-400">{{ orientation.episode.patient.patient_number }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <span class="font-mono text-sm font-semibold text-slate-600 dark:text-slate-300">{{ orientation.episode.episode_number }}</span>
                                <span :class="['mt-1 flex items-center gap-1.5 text-xs font-semibold', orientation.episode.priority === 'EMERGENCY' ? 'text-red-600 dark:text-red-300' : 'text-slate-400']">
                                    <span :class="['h-1.5 w-1.5 rounded-full', orientation.episode.priority === 'EMERGENCY' ? 'bg-red-500' : 'bg-slate-300']" />
                                    {{ orientation.episode.priority === 'EMERGENCY' ? 'Urgence' : 'Normal' }}
                                </span>
                            </td>
                            <td class="max-w-[310px] px-5 py-3 text-sm text-slate-500 dark:text-slate-300">
                                {{ designationSummary(orientation) }}
                            </td>
                            <td class="px-5 py-3">
                                <span class="block text-sm text-slate-600 dark:text-slate-300">{{ formatDateTime(orientation.episode.started_at) }}</span>
                                <span class="text-xs text-slate-400">{{ formatRelativeTime(orientation.episode.started_at) }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-600 dark:text-slate-300">
                                    <span :class="['h-1.5 w-1.5 rounded-full', orientation.status === 'IN_PROGRESS' ? 'bg-primary-500' : orientation.status === 'COMPLETED' ? 'bg-emerald-500' : 'bg-amber-500']" />
                                    {{ orientation.status_label }}
                                </span>
                                <span v-if="orientation.accepted_by" class="mt-1 block text-xs text-slate-400">par {{ orientation.accepted_by }}</span>
                            </td>
                            <td class="px-5 py-3 text-end">
                                <Link v-if="orientation.status === 'PENDING' && can('care.update')" :href="`/care/orientations/${orientation.uuid}/accept`" method="post" as="button" preserve-scroll>
                                    <Button size="sm" variant="white-outline">Prendre en charge</Button>
                                </Link>
                                <Button v-else :as="Link" :href="`/care/orientations/${orientation.uuid}`" size="sm" variant="white-outline"><Icon class="text-base" :name="orientation.status === 'COMPLETED' ? 'eye' : 'edit'" /><span class="ms-1.5">{{ orientation.status === 'COMPLETED' ? 'Voir la fiche' : 'Ouvrir la fiche' }}</span></Button>
                            </td>
                        </tr>
                        <tr v-if="orientations.data.length === 0">
                            <td colspan="6" class="px-5 py-12 text-center">
                                <Icon class="text-2xl text-slate-300" name="user-check" />
                                <p class="mt-2 text-sm font-semibold text-slate-600 dark:text-slate-300">Aucun patient dans cette file</p>
                                <p class="mt-1 text-xs text-slate-400">Les nouveaux passages apparaîtront ici automatiquement.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-gray-200 md:hidden dark:divide-gray-900">
                <article v-for="orientation in orientations.data" :key="orientation.uuid" class="p-4">
                    <div class="flex items-start gap-3">
                        <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(orientation.episode.patient)" />
                        <div class="min-w-0 flex-1">
                            <p class="font-bold text-slate-700 dark:text-white">{{ formatPatientName(orientation.episode.patient) }}</p>
                            <p class="mt-0.5 text-xs text-slate-400">{{ orientation.episode.episode_number }} · {{ formatRelativeTime(orientation.episode.started_at) }}</p>
                            <p class="mt-3 text-sm text-slate-500">{{ designationSummary(orientation) }}</p>
                            <div class="mt-4">
                                <Link v-if="orientation.status === 'PENDING' && can('care.update')" :href="`/care/orientations/${orientation.uuid}/accept`" method="post" as="button" preserve-scroll><Button block size="sm" variant="white-outline">Prendre en charge</Button></Link>
                                <Button v-else :as="Link" :href="`/care/orientations/${orientation.uuid}`" block size="sm" variant="white-outline"><Icon class="text-base" :name="orientation.status === 'COMPLETED' ? 'eye' : 'edit'" /><span class="ms-1.5">{{ orientation.status === 'COMPLETED' ? 'Voir la fiche' : 'Ouvrir la fiche' }}</span></Button>
                            </div>
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
</template>
