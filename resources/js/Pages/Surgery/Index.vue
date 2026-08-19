<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';
import { formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';

defineOptions({
    layout: AppLayout,
});

defineProps({
    surgicalRequests: Object,
});

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

    <div class="w-full space-y-5">
        <div>
            <h1 class="font-heading text-2xl font-bold text-slate-700 dark:text-white">Chirurgie</h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ surgicalRequests.total }} demande{{ surgicalRequests.total > 1 ? 's' : '' }} de chirurgie enregistrée{{ surgicalRequests.total > 1 ? 's' : '' }}.
            </p>
        </div>

        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[880px] border-collapse">
                    <caption class="sr-only">Liste des demandes de chirurgie</caption>
                    <thead>
                        <tr class="bg-gray-50/70 dark:bg-gray-1000/40">
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Patient</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Acte</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Chirurgien</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Programmée le</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="request in surgicalRequests.data"
                            :key="request.uuid"
                            class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-1000"
                        >
                            <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                <Link
                                    v-if="request.episode?.patient"
                                    :href="`/patients/${request.episode.patient.uuid}`"
                                    class="text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white dark:hover:text-primary-400"
                                >
                                    {{ formatPatientName(request.episode.patient) }}
                                </Link>
                                <span v-else class="text-sm text-slate-400">—</span>
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 text-sm text-slate-600 dark:border-gray-900 dark:text-slate-300">
                                {{ request.procedure_name }}
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 text-sm text-slate-500 dark:border-gray-900">
                                {{ request.surgeon?.name ?? '—' }}
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 text-sm text-slate-500 dark:border-gray-900">
                                {{ formatDateTime(request.scheduled_at) ?? '—' }}
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                <span :class="['inline-flex rounded px-2 py-1 text-xs font-medium', statusVariant(request.status)]">
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
        </div>
    </div>
</template>
