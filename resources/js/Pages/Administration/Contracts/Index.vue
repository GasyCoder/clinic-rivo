<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import HrEmptyState from '../Partials/HrEmptyState.vue';
import HrNav from '../Partials/HrNav.vue';
import HrPageHeader from '../Partials/HrPageHeader.vue';
import HrPagination from '../Partials/HrPagination.vue';
import HrStatCard from '../Partials/HrStatCard.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });
const props = defineProps({ contracts: Object, filters: Object, summary: Object });
const { can } = usePermissions();
const query = ref(props.filters?.q ?? '');
const status = ref(props.filters?.status ?? 'current');
const archiveForms = ref({});
const filter = () => router.get('/administration/contracts', { q: query.value || undefined, status: status.value }, { preserveState: true, replace: true });
const archiveForm = (uuid) => archiveForms.value[uuid] ??= useForm({ reason: '' });
const archive = (contract) => archiveForm(contract.uuid).delete(`/administration/contracts/${contract.uuid}`);
const restore = (contract) => router.post(`/administration/contracts/${contract.uuid}/restore`);
const stats = [
    { key: 'current', label: 'En cours', hint: 'Contrats actifs aujourd’hui', icon: 'check-circle', tone: 'emerald' },
    { key: 'future', label: 'À venir', hint: 'Date de début future', icon: 'calender-date', tone: 'sky' },
    { key: 'ended', label: 'Terminés', hint: 'Date de fin dépassée', icon: 'clock', tone: 'amber' },
    { key: 'archived', label: 'Archivés', hint: 'Contrats restaurables', icon: 'archive', tone: 'slate' },
];
</script>

<template>
    <Head title="Contrats RH" />
    <div class="w-full space-y-5">
        <HrNav />
        <HrPageHeader eyebrow="Cycle contractuel" title="Contrats du personnel" description="Suivez les engagements, leurs modèles documentaires, leurs périodes et leurs archives, sans automatisation de paie." icon="file-docs" tone="sky">
            <template #actions><Button v-if="can('contracts.export')" as="a" href="/administration/contracts/export" size="rg" variant="white-outline"><Icon name="download" /><span class="ms-2">Exporter</span></Button><Button v-if="can('contracts.create')" :as="Link" href="/administration/contracts/create" size="rg"><Icon name="plus" /><span class="ms-2">Nouveau contrat</span></Button></template>
        </HrPageHeader>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Link v-for="item in stats" :key="item.key" :href="`/administration/contracts?status=${item.key}`"><HrStatCard :label="item.label" :value="summary[item.key]" :hint="item.hint" :icon="item.icon" :tone="item.tone" :active="status === item.key" /></Link>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <form class="flex flex-col gap-3 border-b border-gray-200 p-4 dark:border-gray-900 lg:flex-row" @submit.prevent="filter">
                <div class="relative min-w-0 flex-1"><Input v-model="query" icon="start" type="search" placeholder="Référence, matricule, employé ou type…" /><span class="pointer-events-none absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400"><Icon name="search" /></span></div>
                <select v-model="status" class="h-10 rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white lg:min-w-48" @change="filter"><option value="current">En cours</option><option value="future">À venir</option><option value="ended">Terminés</option><option value="archived">Archivés</option><option value="all">Tous</option></select><Button size="rg">Rechercher</Button>
            </form>
            <div v-if="contracts.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[980px]"><thead><tr class="bg-gray-50/70 text-[11px] font-bold uppercase tracking-wide text-slate-400 dark:bg-gray-1000/40"><th class="px-5 py-3 text-start">Employé</th><th class="px-5 py-3 text-start">Contrat</th><th class="px-5 py-3 text-start">Période</th><th class="px-5 py-3 text-start">État</th><th class="px-5 py-3 text-end">Actions</th></tr></thead>
                    <tbody><tr v-for="contract in contracts.data" :key="contract.uuid" class="border-t border-gray-100 align-top transition hover:bg-gray-50/60 dark:border-gray-900 dark:hover:bg-gray-1000/30"><td class="px-5 py-4"><strong class="text-sm text-slate-800 dark:text-white">{{ contract.employee.name }}</strong><p class="mt-1 font-mono text-xs text-slate-400">{{ contract.employee.employee_number }}</p></td><td class="px-5 py-4"><span class="rounded-full bg-sky-50 px-2.5 py-1 text-xs font-bold text-sky-700 dark:bg-sky-950/40 dark:text-sky-300">{{ contract.contract_type }}</span><p class="mt-2 text-xs text-slate-500">{{ contract.reference_number || 'Sans référence' }}</p></td><td class="px-5 py-4 text-sm text-slate-600 dark:text-slate-300"><p>{{ contract.starts_on }}</p><p class="mt-1 text-xs text-slate-400">au {{ contract.ends_on || 'sans date de fin' }}</p></td><td class="px-5 py-4"><span :class="['rounded-full px-2.5 py-1 text-xs font-bold', contract.archived ? 'bg-gray-100 text-slate-500 dark:bg-gray-900' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300']">{{ contract.archived ? 'Archivé' : 'Actif' }}</span><p v-if="contract.delete_reason" class="mt-2 max-w-48 text-xs text-slate-400">{{ contract.delete_reason }}</p></td><td class="px-5 py-4 text-end"><div class="inline-flex gap-1"><Button v-if="can('contracts.print')" :as="Link" :href="`/administration/contracts/${contract.uuid}/print`" icon size="rg" variant="white-outline" title="Aperçu / impression"><Icon name="printer" /></Button><Button v-if="!contract.archived && can('contracts.update')" :as="Link" :href="`/administration/contracts/${contract.uuid}/edit`" icon size="rg" variant="white-outline" title="Modifier"><Icon name="edit" /></Button><Button v-if="contract.archived && can('contracts.restore')" icon size="rg" variant="white-outline" title="Restaurer" @click="restore(contract)"><Icon name="undo" /></Button></div><details v-if="!contract.archived && can('contracts.archive')" class="mt-3 text-start"><summary class="cursor-pointer text-xs font-bold text-red-600">Archiver ce contrat</summary><form class="mt-2 flex min-w-64 gap-2" @submit.prevent="archive(contract)"><input v-model="archiveForm(contract.uuid).reason" required maxlength="1000" placeholder="Motif obligatoire" class="h-9 min-w-0 flex-1 rounded-lg border border-gray-200 px-3 text-xs dark:border-gray-800 dark:bg-gray-950"><Button size="sm" variant="danger" :disabled="archiveForm(contract.uuid).processing">Archiver</Button></form><p v-if="archiveForm(contract.uuid).errors.reason" class="mt-1 text-xs text-red-600">{{ archiveForm(contract.uuid).errors.reason }}</p></details></td></tr></tbody>
                </table>
            </div>
            <HrEmptyState v-else icon="file-docs" title="Aucun contrat pour ce filtre" description="Changez l’état sélectionné ou enregistrez un nouveau contrat." />
            <HrPagination :paginator="contracts" />
        </section>
    </div>
</template>
