<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({
    layout: AppLayout,
});

defineProps({
    surgicalRequests: Object,
});

const { can } = usePermissions();

const STATUS_LABELS = {
    PENDING: 'En attente',
    SCHEDULED: 'Programmée',
    PREOPERATIVE_VALIDATED: 'Bilan préop. validé',
    IN_PROGRESS: 'En cours',
    COMPLETED: 'Terminée',
    DISCHARGED: 'Sortie',
};

const STATUS_VARIANTS = {
    PENDING: 'bg-slate-100 text-slate-500 dark:bg-slate-900 dark:text-slate-400',
    SCHEDULED: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    PREOPERATIVE_VALIDATED: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300',
    IN_PROGRESS: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-950 dark:text-yellow-300',
    COMPLETED: 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300',
    DISCHARGED: 'bg-gray-100 text-gray-600 dark:bg-gray-900 dark:text-gray-400',
};

const statusLabel = (status) => STATUS_LABELS[status] ?? status;
const statusVariant = (status) => STATUS_VARIANTS[status] ?? STATUS_VARIANTS.PENDING;
</script>

<template>
    <Head title="Chirurgie" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-6 lg:space-y-8">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                    <Icon class="text-2xl" name="grid-alt" />
                </span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-slate-700 dark:text-white">Chirurgie</h1>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-400">
                        {{ surgicalRequests.total }} demande{{ surgicalRequests.total > 1 ? 's' : '' }} de chirurgie enregistrée{{ surgicalRequests.total > 1 ? 's' : '' }}.
                    </p>
                </div>
            </div>
            <Button v-if="can('surgery.create')" :as="Link" href="/surgery/create" size="rg" variant="primary">
                <Icon class="text-xl/4.5" name="plus" />
                <span class="ms-2">Nouvelle demande</span>
            </Button>
        </header>

        <Card class="overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[880px] border-collapse">
                    <caption class="sr-only">Liste des demandes de chirurgie</caption>
                    <thead class="bg-gray-50/70 dark:bg-gray-1000/40">
                        <tr>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Patient</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Acte</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Chirurgien</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Programmée le</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Statut</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                        <tr
                            v-for="request in surgicalRequests.data"
                            :key="request.uuid"
                            class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-1000"
                        >
                            <td class="px-5 py-3">
                                <div v-if="request.episode?.patient" class="flex min-w-[220px] items-center gap-3">
                                    <Avatar rounded size="sm" variant="primary-pale" :text="formatPatientInitials(request.episode.patient)" aria-hidden="true" />
                                    <div class="min-w-0">
                                        <Link :href="`/patients/${request.episode.patient.uuid}`" class="block truncate text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white dark:hover:text-primary-400">
                                            {{ formatPatientName(request.episode.patient) }}
                                        </Link>
                                        <span class="mt-0.5 block text-xs text-slate-400">{{ request.episode.episode_number }}</span>
                                    </div>
                                </div>
                                <span v-else class="text-sm text-slate-400">—</span>
                            </td>
                            <td class="px-5 py-3 text-sm text-slate-600 dark:text-slate-300">
                                <Link :href="`/surgery/${request.uuid}`" class="font-medium hover:text-primary-600 dark:hover:text-primary-400">
                                    {{ request.procedure_name }}
                                </Link>
                            </td>
                            <td class="px-5 py-3">
                                <div v-if="request.surgeon" class="flex items-center gap-2 text-sm text-slate-500">
                                    <Icon class="text-base text-slate-400" name="user-check" />
                                    {{ request.surgeon.name }}
                                </div>
                                <span v-else class="text-sm text-slate-400">—</span>
                            </td>
                            <td class="px-5 py-3">
                                <div v-if="request.scheduled_at" class="flex items-center gap-2 text-sm text-slate-500">
                                    <Icon class="text-base text-slate-400" name="calendar" />
                                    {{ formatDateTime(request.scheduled_at) }}
                                </div>
                                <span v-else class="text-sm text-slate-400">—</span>
                            </td>
                            <td class="px-5 py-3">
                                <span :class="['inline-flex items-center gap-1.5 rounded px-2 py-1 text-xs font-bold', statusVariant(request.status)]">
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-current"></span>
                                    {{ statusLabel(request.status) }}
                                </span>
                            </td>
                        </tr>

                        <tr v-if="surgicalRequests.data.length === 0">
                            <td colspan="5" class="px-5 py-12 text-center">
                                <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-slate-400 dark:bg-gray-900">
                                    <Icon class="text-xl" name="grid-alt" />
                                </span>
                                <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-200">Aucune demande de chirurgie</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="surgicalRequests.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 p-4 dark:border-gray-900">
                <span class="text-xs text-slate-400">Page {{ surgicalRequests.current_page }} sur {{ surgicalRequests.last_page }}</span>
                <div class="flex flex-wrap items-center gap-1">
                    <template v-for="(link, index) in surgicalRequests.links" :key="index">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-state
                            :class="[
                                'rounded px-3 py-1.5 text-sm',
                                link.active ? 'bg-primary-600 text-white' : 'text-slate-500 hover:bg-gray-100 dark:hover:bg-gray-900',
                            ]"
                            v-html="link.label"
                        />
                        <span v-else class="rounded px-3 py-1.5 text-sm text-slate-300" v-html="link.label" />
                    </template>
                </div>
            </div>
        </Card>
    </div>
</template>
