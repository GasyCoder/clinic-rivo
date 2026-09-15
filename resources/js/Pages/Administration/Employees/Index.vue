<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import ExplorerTile from '@/Components/UI/ExplorerTile.vue';
import ExplorerView from '@/Components/UI/ExplorerView.vue';
import Icon from '@/Components/UI/Icon.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import HrPagination from '../Partials/HrPagination.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });
const props = defineProps({ employees: Object, filters: Object, summary: Object });
const { can } = usePermissions();
const query = ref(props.filters?.q ?? '');
const statusFilter = ref(props.filters?.status ?? 'active');
const submitFilters = () => router.get('/administration/employees', { q: query.value || undefined, status: statusFilter.value }, { preserveState: true, replace: true });
const setStatus = (value) => { statusFilter.value = value; submitFilters(); };
const restore = (employee) => router.post(`/administration/employees/${employee.uuid}/restore`);
const initials = (employee) => `${employee.first_name?.[0] ?? ''}${employee.last_name?.[0] ?? ''}`.toUpperCase() || 'RH';

const statusCards = [
    { value: 'active', label: 'Actifs', icon: 'check-circle', tone: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300', key: 'active' },
    { value: 'inactive', label: 'Inactifs', icon: 'pause', tone: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300', key: 'inactive' },
    { value: 'archived', label: 'Archivés', icon: 'archive', tone: 'bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400', key: 'archived' },
];
const tileTone = (employee) => (employee.archived ? 'slate' : (employee.active ? 'emerald' : 'amber'));
const tileBadge = (employee) => (employee.archived ? 'Archivé' : (employee.active ? null : 'Inactif'));
</script>

<template>
    <Head title="Employés" />
    <div class="w-full space-y-5">
        <PageHeader eyebrow="Ressources humaines" title="Employés" description="Un dossier par personne : identité, affectation, contrats et documents." icon="users" tone="primary">
            <template #actions>
                <Button v-if="can('employees.import')" :as="Link" href="/administration/employees/import" size="rg" variant="white-outline"><Icon name="upload-cloud" /><span class="ms-2">Importer</span></Button>
                <Button v-if="can('employees.export')" as="a" href="/administration/employees/export" size="rg" variant="white-outline"><Icon name="download" /><span class="ms-2">Exporter</span></Button>
                <Button v-if="can('employees.create')" :as="Link" href="/administration/employees/create" size="rg"><Icon name="user-add" /><span class="ms-2">Nouvel employé</span></Button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-3 gap-3">
            <button
                v-for="card in statusCards"
                :key="card.value"
                type="button"
                :class="['flex items-center gap-3 rounded-xl border bg-white p-4 text-start shadow-sm transition hover:shadow-md dark:bg-gray-950', statusFilter === card.value ? 'border-primary-400 dark:border-primary-700' : 'border-gray-200 dark:border-gray-900']"
                @click="setStatus(card.value)"
            >
                <span :class="['flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-xl', card.tone]"><Icon :name="card.icon" /></span>
                <span><span class="block text-2xl font-bold tabular-nums text-slate-800 dark:text-white">{{ summary[card.key] }}</span><span class="text-xs text-slate-500">{{ card.label }}</span></span>
            </button>
        </div>

        <ExplorerView storage-key="hr-employees" :count="employees.data.length" count-label="employé" empty-icon="users" empty-title="Aucun employé trouvé" empty-description="Modifiez la recherche ou créez un nouveau dossier.">
            <template #toolbar>
                <form class="flex w-full gap-2 sm:w-auto" role="search" @submit.prevent="submitFilters">
                    <label class="relative block flex-1 sm:w-80">
                        <span class="sr-only">Rechercher un employé</span>
                        <Icon class="pointer-events-none absolute inset-y-0 start-3 my-auto text-lg text-slate-400" name="search" />
                        <input v-model="query" type="search" class="h-9 w-full rounded-lg border border-gray-200 bg-gray-50 ps-10 pe-3 text-sm outline-none focus:border-primary-500 focus:bg-white focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-900 dark:text-white" placeholder="Nom, matricule, fonction…">
                    </label>
                    <Button type="submit" size="sm" variant="white-outline">Rechercher</Button>
                </form>
                <button v-if="statusFilter !== 'all'" type="button" class="text-xs font-bold text-slate-500 hover:text-primary-600" @click="setStatus('all')">Tous les dossiers</button>
            </template>

            <template #grid>
                <ExplorerTile
                    v-for="employee in employees.data"
                    :key="employee.uuid"
                    :href="`/administration/employees/${employee.uuid}`"
                    icon="user"
                    :tone="tileTone(employee)"
                    :badge="tileBadge(employee)"
                    :title="employee.name"
                    :subtitle="employee.job_title || 'Fonction non renseignée'"
                    :highlight="employee.department"
                    :meta="employee.employee_number"
                    :muted="employee.archived"
                >
                    <template #actions>
                        <Button v-if="!employee.archived && can('employees.update')" :as="Link" :href="`/administration/employees/${employee.uuid}/edit`" size="sm" variant="white-outline" title="Modifier"><Icon name="edit" /></Button>
                        <Button v-if="employee.archived && can('employees.restore')" size="sm" variant="white-outline" type="button" title="Restaurer" @click="restore(employee)"><Icon name="undo" /></Button>
                    </template>
                </ExplorerTile>
            </template>

            <template #list>
                <table class="w-full min-w-[900px] text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold text-slate-500 dark:bg-gray-1000">
                        <tr>
                            <th class="px-5 py-3 text-start">Employé</th>
                            <th class="px-4 py-3 text-start">Fonction · département</th>
                            <th class="px-4 py-3 text-start">Contact</th>
                            <th class="px-4 py-3 text-start">Entrée</th>
                            <th class="px-4 py-3 text-start">État</th>
                            <th class="px-5 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                        <tr v-for="employee in employees.data" :key="employee.uuid" class="hover:bg-gray-50 dark:hover:bg-gray-900/40">
                            <td class="px-5 py-3"><Link :href="`/administration/employees/${employee.uuid}`" class="group flex items-center gap-3"><Avatar rounded size="sm" variant="slate-pale" :text="initials(employee)" /><span class="min-w-0"><strong class="block truncate text-slate-800 group-hover:text-primary-600 dark:text-white">{{ employee.name }}</strong><span class="block font-mono text-xs text-slate-400">{{ employee.employee_number }}</span></span></Link></td>
                            <td class="px-4 py-3"><p class="text-slate-700 dark:text-slate-200">{{ employee.job_title || '—' }}</p><p class="text-xs text-slate-400">{{ employee.department || '—' }}</p></td>
                            <td class="px-4 py-3"><p class="text-slate-600 dark:text-slate-300">{{ employee.phone || '—' }}</p><p class="max-w-52 truncate text-xs text-slate-400">{{ employee.email || employee.address || '' }}</p></td>
                            <td class="px-4 py-3 text-slate-500">{{ employee.hire_date || '—' }}</td>
                            <td class="px-4 py-3">
                                <span v-if="employee.archived" class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-bold text-slate-500 dark:bg-gray-900">Archivé</span>
                                <span v-else-if="employee.active" class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">Actif</span>
                                <span v-else class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">Inactif</span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex justify-end gap-1.5">
                                    <Button :as="Link" :href="`/administration/employees/${employee.uuid}`" size="sm" variant="white-outline">Voir</Button>
                                    <Button v-if="!employee.archived && can('employees.update')" :as="Link" :href="`/administration/employees/${employee.uuid}/edit`" size="sm" variant="white-outline" title="Modifier"><Icon name="edit" /></Button>
                                    <Button v-if="employee.archived && can('employees.restore')" size="sm" variant="white-outline" type="button" title="Restaurer" @click="restore(employee)"><Icon name="undo" /></Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>

            <template #footer>
                <div class="border-t border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950"><HrPagination :paginator="employees" /></div>
            </template>
        </ExplorerView>
    </div>
</template>
