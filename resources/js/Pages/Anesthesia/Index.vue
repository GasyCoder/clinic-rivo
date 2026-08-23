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
import { formatDateTime } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({ surgicalRequests: Object, search: String });
const { can } = usePermissions();
const query = ref(props.search ?? '');
let debounceTimer = null;

const runSearch = (value) => router.get('/anesthesia', value ? { q: value } : {}, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
});
watch(query, (value) => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => runSearch(value), 350);
});
const submitSearch = () => {
    clearTimeout(debounceTimer);
    runSearch(query.value);
};

const assessmentState = (request) => {
    if (request.anesthesia_record?.assessment_validated_at) return { label: 'Évaluation validée', style: 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300', icon: 'check-circle' };
    if (request.anesthesia_record) return { label: 'Brouillon en cours', style: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300', icon: 'edit' };
    return { label: 'À commencer', style: 'bg-slate-100 text-slate-500 dark:bg-slate-900 dark:text-slate-300', icon: 'clock' };
};
</script>

<template>
    <Head title="Anesthésie" />

    <div class="mx-auto w-full max-w-[1500px] space-y-5">
        <header class="flex items-start gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-violet-100 text-violet-600 dark:bg-violet-950 dark:text-violet-300"><Icon class="text-2xl" name="shield-check" /></span>
            <div>
                <h1 class="font-heading text-2xl font-bold -tracking-snug text-slate-700 dark:text-white">Anesthésie</h1>
                <p class="mt-0.5 max-w-3xl text-sm leading-5 text-slate-400">
                    {{ surgicalRequests.total }} dossier{{ surgicalRequests.total > 1 ? 's' : '' }} · Consultation pré-anesthésique, examens et conduite anesthésique.
                </p>
            </div>
        </header>

        <Card class="overflow-hidden shadow-sm">
            <div class="border-b border-gray-200 p-4 dark:border-gray-900 sm:px-5">
                <form class="relative w-full sm:max-w-md" role="search" @submit.prevent="submitSearch">
                    <Input v-model="query" icon="start" type="search" placeholder="Patient, passage ou intervention" autocomplete="off" />
                    <button type="submit" class="absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400" aria-label="Rechercher"><Icon class="text-lg" name="search" /></button>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[960px] border-collapse">
                    <thead class="bg-gray-50/70 dark:bg-gray-1000/40">
                        <tr><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Patient</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Intervention</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Programmation</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Anesthésiste</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Évaluation</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Actions</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                        <tr v-for="request in surgicalRequests.data" :key="request.uuid" class="transition-colors hover:bg-violet-50/40 dark:hover:bg-violet-950/10">
                            <td class="px-4 py-3">
                                <div class="flex min-w-[220px] items-center gap-3">
                                    <Avatar rounded size="sm" variant="primary-pale" :text="formatPatientInitials(request.episode.patient)" />
                                    <div class="min-w-0">
                                        <Link v-if="can('patients.view')" :href="`/patients/${request.episode.patient.uuid}`" class="block truncate text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white">{{ formatPatientName(request.episode.patient) }}</Link>
                                        <span v-else class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(request.episode.patient) }}</span>
                                        <small class="text-slate-400">{{ request.episode.episode_number }}</small>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm font-medium text-slate-600 dark:text-slate-200">{{ request.procedure_name }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ formatDateTime(request.scheduled_at) ?? 'Non programmée' }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ request.anesthesia_record?.anesthetist?.name ?? 'Non affecté' }}</td>
                            <td class="px-4 py-3"><span :class="['inline-flex items-center gap-1.5 rounded px-2 py-1 text-xs font-bold', assessmentState(request).style]"><Icon :name="assessmentState(request).icon" />{{ assessmentState(request).label }}</span></td>
                            <td class="px-4 py-3 text-end"><Button :as="Link" :href="`/anesthesia/${request.uuid}`" size="rg" variant="white-outline" :aria-label="`Ouvrir l’évaluation ${request.procedure_name}`" title="Ouvrir l’évaluation"><Icon class="text-lg" name="eye" /></Button></td>
                        </tr>
                        <tr v-if="surgicalRequests.data.length === 0">
                            <td colspan="6" class="px-5 py-12 text-center">
                                <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-violet-50 text-violet-400 dark:bg-violet-950/40">
                                    <Icon class="text-xl" name="shield-check" />
                                </span>
                                <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-200">Aucun dossier d’anesthésie</p>
                                <p class="mt-1 text-xs text-slate-400">Modifiez la recherche pour retrouver un dossier chirurgical.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="surgicalRequests.last_page > 1" class="flex items-center justify-between gap-3 border-t border-gray-200 p-4 dark:border-gray-900">
                <span class="text-xs text-slate-400">Page {{ surgicalRequests.current_page }} sur {{ surgicalRequests.last_page }}</span>
                <div class="flex gap-1"><template v-for="(link, index) in surgicalRequests.links" :key="index"><Link v-if="link.url" :href="link.url" preserve-state :class="['rounded px-3 py-1.5 text-sm', link.active ? 'bg-violet-600 text-white' : 'text-slate-500 hover:bg-gray-100 dark:hover:bg-gray-900']" v-html="link.label" /><span v-else class="px-3 py-1.5 text-sm text-slate-300" v-html="link.label" /></template></div>
            </div>
        </Card>
    </div>
</template>
