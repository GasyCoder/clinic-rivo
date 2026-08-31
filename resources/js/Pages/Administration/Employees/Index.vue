<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import HrNav from '../Partials/HrNav.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });
const props = defineProps({ employees: Object, filters: Object, summary: Object });
const { can } = usePermissions();
const query = ref(props.filters?.q ?? '');
const statusFilter = ref(props.filters?.status ?? 'active');
const importForm = useForm({ file: null });

const submitFilters = () => router.get('/administration/employees', { q: query.value || undefined, status: statusFilter.value }, { preserveState: true, replace: true });
const restore = (employee) => router.post(`/administration/employees/${employee.uuid}/restore`);
const submitImport = () => importForm.post('/administration/employees/import', { forceFormData: true, onSuccess: () => importForm.reset() });
const initials = (employee) => `${employee.first_name?.[0] ?? ''}${employee.last_name?.[0] ?? ''}`.toUpperCase() || 'RH';
</script>

<template>
    <Head title="Employés" />
    <div class="w-full space-y-5">
        <HrNav />

        <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div><p class="text-xs font-bold uppercase tracking-[0.15em] text-primary-600">Annuaire du personnel</p><h1 class="mt-1 font-heading text-3xl font-bold text-slate-800 dark:text-white">Dossiers employés</h1><p class="mt-2 text-sm text-slate-500">Identité, affectation, contrats et documents administratifs dans un dossier unique.</p></div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="can('employees.export')" as="a" href="/administration/employees/export" size="rg" variant="white-outline"><Icon class="text-lg" name="download" /><span class="ms-2">Exporter Excel</span></Button>
                <Button v-if="can('employees.create')" :as="Link" href="/administration/employees/create" size="rg"><Icon class="text-lg" name="user-add" /><span class="ms-2">Nouvel employé</span></Button>
            </div>
        </header>

        <section class="grid gap-3 sm:grid-cols-3">
            <Link href="/administration/employees?status=active" class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-emerald-300 dark:border-gray-900 dark:bg-gray-950"><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Actifs</p><div class="mt-2 flex items-end justify-between"><strong class="text-2xl text-slate-800 dark:text-white">{{ summary.active }}</strong><span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950"><Icon name="check-circle" /></span></div></Link>
            <Link href="/administration/employees?status=inactive" class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-amber-300 dark:border-gray-900 dark:bg-gray-950"><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Inactifs</p><div class="mt-2 flex items-end justify-between"><strong class="text-2xl text-slate-800 dark:text-white">{{ summary.inactive }}</strong><span class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950"><Icon name="pause" /></span></div></Link>
            <Link href="/administration/employees?status=archived" class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-slate-300 dark:border-gray-900 dark:bg-gray-950"><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Archivés</p><div class="mt-2 flex items-end justify-between"><strong class="text-2xl text-slate-800 dark:text-white">{{ summary.archived }}</strong><span class="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 text-slate-500 dark:bg-gray-900"><Icon name="archive" /></span></div></Link>
        </section>

        <details v-if="can('employees.import')" class="group rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4"><div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-950"><Icon name="upload-cloud" /></span><div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Importer des employés</h2><p class="mt-0.5 text-xs text-slate-500">Création atomique : une ligne invalide annule tout le fichier.</p></div></div><Icon class="text-slate-400 transition group-open:rotate-180" name="chevron-down" /></summary>
            <form class="border-t border-gray-200 px-5 py-4 dark:border-gray-900" @submit.prevent="submitImport"><div class="flex flex-col gap-4 lg:flex-row lg:items-end"><div class="min-w-0 flex-1"><div class="mb-1.5 flex items-center justify-between gap-3"><label class="text-sm font-medium text-slate-700 dark:text-white">Fichier Excel ou CSV</label><a href="/administration/employees/import-template" class="text-xs font-bold text-primary-600 hover:underline">Télécharger le modèle</a></div><input type="file" accept=".xlsx,.xls,.csv" class="block h-10 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-slate-600 file:me-3 file:border-0 file:bg-transparent file:text-xs file:font-bold dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300" required @change="importForm.file = $event.target.files[0] ?? null"><p class="mt-1 text-xs text-slate-400">1 000 lignes maximum · 5 Mo · les référentiels doivent déjà exister.</p><p v-if="importForm.errors.file" class="mt-1 text-xs text-red-600">{{ importForm.errors.file }}</p></div><Button size="rg" :disabled="importForm.processing || !importForm.file"><Icon name="upload" /><span class="ms-2">{{ importForm.processing ? 'Importation…' : 'Importer' }}</span></Button></div></form>
        </details>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 p-4 dark:border-gray-900 lg:flex-row lg:items-center lg:justify-between lg:px-5">
                <form class="relative w-full lg:max-w-md" role="search" @submit.prevent="submitFilters"><Input v-model="query" icon="start" type="search" placeholder="Matricule, nom, fonction, département…" autocomplete="off" /><button type="submit" class="absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400" aria-label="Rechercher"><Icon name="search" /></button></form>
                <select v-model="statusFilter" class="h-10 min-w-48 rounded-lg border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white" @change="submitFilters"><option value="active">Employés actifs</option><option value="inactive">Employés inactifs</option><option value="archived">Dossiers archivés</option><option value="all">Tous les dossiers</option></select>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] border-collapse">
                    <thead><tr class="bg-gray-50/70 dark:bg-gray-1000/40"><th class="px-5 py-3 text-start text-xs font-bold uppercase tracking-wide text-slate-400">Employé</th><th class="px-5 py-3 text-start text-xs font-bold uppercase tracking-wide text-slate-400">Affectation</th><th class="px-5 py-3 text-start text-xs font-bold uppercase tracking-wide text-slate-400">Contact</th><th class="px-5 py-3 text-start text-xs font-bold uppercase tracking-wide text-slate-400">Entrée</th><th class="px-5 py-3 text-start text-xs font-bold uppercase tracking-wide text-slate-400">État</th><th class="px-5 py-3 text-end text-xs font-bold uppercase tracking-wide text-slate-400">Actions</th></tr></thead>
                    <tbody>
                        <tr v-for="employee in employees.data" :key="employee.uuid" class="border-t border-gray-100 transition hover:bg-gray-50/70 dark:border-gray-900 dark:hover:bg-gray-1000/50">
                            <td class="px-5 py-3.5"><Link :href="`/administration/employees/${employee.uuid}`" class="flex min-w-[240px] items-center gap-3 group"><Avatar rounded size="sm" variant="slate-pale" :text="initials(employee)" /><span class="min-w-0"><strong class="block truncate text-sm text-slate-800 group-hover:text-primary-600 dark:text-white">{{ employee.name }}</strong><span class="mt-0.5 block font-mono text-xs text-slate-400">{{ employee.employee_number }}</span></span></Link></td>
                            <td class="px-5 py-3.5"><p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ employee.job_title || 'Fonction non renseignée' }}</p><p class="mt-0.5 text-xs text-slate-400">{{ employee.department || 'Département non affecté' }}</p></td>
                            <td class="px-5 py-3.5"><p class="text-sm text-slate-600 dark:text-slate-300">{{ employee.phone || 'Téléphone non renseigné' }}</p><p class="mt-0.5 max-w-52 truncate text-xs text-slate-400">{{ employee.email || employee.address || 'Coordonnées incomplètes' }}</p></td>
                            <td class="px-5 py-3.5 text-sm text-slate-500">{{ employee.hire_date || 'Non renseignée' }}</td>
                            <td class="px-5 py-3.5"><span v-if="employee.archived" class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-bold text-slate-500 dark:bg-gray-900"><span class="h-1.5 w-1.5 rounded-full bg-slate-400" />Archivé</span><span v-else-if="employee.active" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />Actif</span><span v-else class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-700 dark:bg-amber-950/40 dark:text-amber-300"><span class="h-1.5 w-1.5 rounded-full bg-amber-500" />Inactif</span></td>
                            <td class="px-5 py-3.5 text-end"><div class="inline-flex gap-1"><Button :as="Link" :href="`/administration/employees/${employee.uuid}`" icon size="rg" variant="white-outline" title="Consulter"><Icon name="eye" /></Button><Button v-if="!employee.archived && can('employees.update')" :as="Link" :href="`/administration/employees/${employee.uuid}/edit`" icon size="rg" variant="white-outline" title="Modifier"><Icon name="edit" /></Button><Button v-if="employee.archived && can('employees.restore')" icon size="rg" variant="white-outline" title="Restaurer" @click="restore(employee)"><Icon name="undo" /></Button></div></td>
                        </tr>
                        <tr v-if="employees.data.length === 0"><td colspan="6" class="px-5 py-14 text-center"><span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-2xl" name="users" /></span><p class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Aucun employé trouvé</p><p class="mt-1 text-xs text-slate-400">Ajustez les filtres ou créez un nouveau dossier.</p></td></tr>
                    </tbody>
                </table>
            </div>
            <div v-if="employees.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 p-4 dark:border-gray-900"><span class="text-xs text-slate-400">Page {{ employees.current_page }} sur {{ employees.last_page }}</span><div class="flex gap-1"><template v-for="(link, index) in employees.links" :key="index"><Link v-if="link.url" :href="link.url" preserve-state :class="['rounded-lg px-3 py-1.5 text-sm', link.active ? 'bg-primary-600 text-white' : 'text-slate-500 hover:bg-gray-100 dark:hover:bg-gray-900']" v-html="link.label" /><span v-else class="rounded-lg px-3 py-1.5 text-sm text-slate-300" v-html="link.label" /></template></div></div>
        </section>
    </div>
</template>
