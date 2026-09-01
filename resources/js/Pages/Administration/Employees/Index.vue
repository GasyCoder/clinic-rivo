<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
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
const props = defineProps({ employees: Object, filters: Object, summary: Object });
const { can } = usePermissions();
const query = ref(props.filters?.q ?? '');
const statusFilter = ref(props.filters?.status ?? 'active');
const submitFilters = () => router.get('/administration/employees', { q: query.value || undefined, status: statusFilter.value }, { preserveState: true, replace: true });
const clearFilters = () => {
    query.value = '';
    statusFilter.value = 'active';
    submitFilters();
};
const restore = (employee) => router.post(`/administration/employees/${employee.uuid}/restore`);
const initials = (employee) => `${employee.first_name?.[0] ?? ''}${employee.last_name?.[0] ?? ''}`.toUpperCase() || 'RH';
</script>

<template>
    <Head title="Employés" />
    <div class="w-full space-y-5">
        <HrNav />
        <HrPageHeader eyebrow="Annuaire du personnel" title="Dossiers employés" description="Retrouvez l’identité, l’affectation, les contrats et les documents administratifs dans un dossier unique." icon="users">
            <template #actions>
                <Button v-if="can('employees.import')" :as="Link" href="/administration/employees/import" size="rg" variant="white-outline"><Icon name="upload-cloud" /><span class="ms-2">Importer</span></Button>
                <Button v-if="can('employees.export')" as="a" href="/administration/employees/export" size="rg" variant="white-outline"><Icon name="download" /><span class="ms-2">Exporter</span></Button>
                <Button v-if="can('employees.create')" :as="Link" href="/administration/employees/create" size="rg"><Icon name="user-add" /><span class="ms-2">Nouvel employé</span></Button>
            </template>
        </HrPageHeader>

        <section class="grid gap-3 sm:grid-cols-3">
            <Link href="/administration/employees?status=active"><HrStatCard label="Employés actifs" :value="summary.active" hint="Disponibles dans les parcours Personnel" icon="check-circle" tone="emerald" :active="statusFilter === 'active'" /></Link>
            <Link href="/administration/employees?status=inactive"><HrStatCard label="Employés inactifs" :value="summary.inactive" hint="Dossiers conservés mais indisponibles" icon="pause" tone="amber" :active="statusFilter === 'inactive'" /></Link>
            <Link href="/administration/employees?status=archived"><HrStatCard label="Dossiers archivés" :value="summary.archived" hint="Archives RH restaurables sur autorisation" icon="archive" tone="slate" :active="statusFilter === 'archived'" /></Link>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="border-b border-gray-200 p-4 dark:border-gray-900 lg:px-5">
                <form class="flex flex-col gap-3 lg:flex-row lg:items-center" role="search" @submit.prevent="submitFilters">
                    <div class="relative min-w-0 flex-1 lg:max-w-xl"><Input v-model="query" icon="start" type="search" placeholder="Matricule, nom, fonction, département…" autocomplete="off" /><button type="submit" class="absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400" aria-label="Rechercher"><Icon name="search" /></button></div>
                    <select v-model="statusFilter" class="h-10 rounded-lg border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white lg:min-w-52" @change="submitFilters"><option value="active">Employés actifs</option><option value="inactive">Employés inactifs</option><option value="archived">Dossiers archivés</option><option value="all">Tous les dossiers</option></select>
                    <Button size="rg">Rechercher</Button>
                    <button v-if="query || statusFilter !== 'active'" type="button" class="h-10 px-2 text-xs font-bold text-slate-400 hover:text-primary-600" @click="clearFilters">Réinitialiser</button>
                </form>
            </div>

            <div v-if="employees.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[980px] border-collapse">
                    <thead><tr class="bg-gray-50/70 dark:bg-gray-1000/40"><th class="px-5 py-3 text-start text-[11px] font-bold uppercase tracking-wide text-slate-400">Employé</th><th class="px-5 py-3 text-start text-[11px] font-bold uppercase tracking-wide text-slate-400">Affectation</th><th class="px-5 py-3 text-start text-[11px] font-bold uppercase tracking-wide text-slate-400">Contact</th><th class="px-5 py-3 text-start text-[11px] font-bold uppercase tracking-wide text-slate-400">Entrée</th><th class="px-5 py-3 text-start text-[11px] font-bold uppercase tracking-wide text-slate-400">État</th><th class="px-5 py-3 text-end text-[11px] font-bold uppercase tracking-wide text-slate-400">Actions</th></tr></thead>
                    <tbody>
                        <tr v-for="employee in employees.data" :key="employee.uuid" class="border-t border-gray-100 transition hover:bg-gray-50/70 dark:border-gray-900 dark:hover:bg-gray-1000/50">
                            <td class="px-5 py-3.5"><Link :href="`/administration/employees/${employee.uuid}`" class="group flex min-w-[240px] items-center gap-3"><Avatar rounded size="sm" variant="slate-pale" :text="initials(employee)" /><span class="min-w-0"><strong class="block truncate text-sm text-slate-800 group-hover:text-primary-600 dark:text-white">{{ employee.name }}</strong><span class="mt-0.5 block font-mono text-xs text-slate-400">{{ employee.employee_number }}</span></span></Link></td>
                            <td class="px-5 py-3.5"><p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ employee.job_title || 'Fonction non renseignée' }}</p><p class="mt-0.5 text-xs text-slate-400">{{ employee.department || 'Département non affecté' }}</p></td>
                            <td class="px-5 py-3.5"><p class="text-sm text-slate-600 dark:text-slate-300">{{ employee.phone || 'Téléphone non renseigné' }}</p><p class="mt-0.5 max-w-52 truncate text-xs text-slate-400">{{ employee.email || employee.address || 'Coordonnées incomplètes' }}</p></td>
                            <td class="px-5 py-3.5 text-sm text-slate-500">{{ employee.hire_date || 'Non renseignée' }}</td>
                            <td class="px-5 py-3.5"><span v-if="employee.archived" class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-bold text-slate-500 dark:bg-gray-900"><span class="h-1.5 w-1.5 rounded-full bg-slate-400" />Archivé</span><span v-else-if="employee.active" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />Actif</span><span v-else class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-700 dark:bg-amber-950/40 dark:text-amber-300"><span class="h-1.5 w-1.5 rounded-full bg-amber-500" />Inactif</span></td>
                            <td class="px-5 py-3.5 text-end"><div class="inline-flex gap-1"><Button :as="Link" :href="`/administration/employees/${employee.uuid}`" icon size="rg" variant="white-outline" title="Consulter"><Icon name="eye" /></Button><Button v-if="!employee.archived && can('employees.update')" :as="Link" :href="`/administration/employees/${employee.uuid}/edit`" icon size="rg" variant="white-outline" title="Modifier"><Icon name="edit" /></Button><Button v-if="employee.archived && can('employees.restore')" icon size="rg" variant="white-outline" title="Restaurer" @click="restore(employee)"><Icon name="undo" /></Button></div></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <HrEmptyState v-else icon="users" title="Aucun employé trouvé" description="Ajustez vos critères de recherche ou créez un nouveau dossier employé."><Button v-if="can('employees.create')" :as="Link" href="/administration/employees/create" size="sm"><Icon name="user-add" /><span class="ms-2">Créer un employé</span></Button></HrEmptyState>
            <HrPagination :paginator="employees" />
        </section>
    </div>
</template>
