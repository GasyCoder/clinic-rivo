<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import HrNav from './Partials/HrNav.vue';
import HrStatCard from './Partials/HrStatCard.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

defineProps({
    summary: Object,
    siteName: String,
});

const { can } = usePermissions();
const areas = [
    { title: 'Dossiers employés', description: 'Identité administrative, carrière, pièces privées et historique.', icon: 'users', permission: 'employees.view', link: '/administration/employees', tone: 'primary' },
    { title: 'Contrats', description: 'CDI, CDD, consultants, stages, bénévolat et documents associés.', icon: 'file-docs', permission: 'contracts.view', link: '/administration/contracts', tone: 'sky' },
    { title: 'Présences', description: 'Entrées, sorties, durée des sessions et corrections auditées.', icon: 'clock', permission: 'attendance.view', link: '/administration/attendance', tone: 'emerald' },
    { title: 'Congés', description: 'Demandes, intérim, validation, refus, annulation et impression.', icon: 'calendar', permission: 'leave.view', link: '/administration/leave', tone: 'amber' },
    { title: 'Planning', description: 'Organisation des équipes, services et créneaux de travail.', icon: 'calender-date', permission: 'planning.view', link: '/administration/planning', tone: 'violet' },
    { title: 'Rapports RH', description: 'Indicateurs de période, exports Excel et rapports imprimables.', icon: 'reports', permission: 'hr_reports.view', link: '/administration/reports', tone: 'rose' },
    { title: 'Paramètres RH', description: 'Départements, fonctions, types de contrat et attestations configurables.', icon: 'settings', permission: 'hr_settings.view', link: '/administration/settings', tone: 'slate' },
    { title: 'Crédit Bloc personnel', description: 'Allocation et registre des mouvements du crédit forfaitaire du personnel.', icon: 'wallet', permission: 'staff_block_credits.view', link: '/administration/staff-block-credits', tone: 'primary' },
    { title: 'Caisses', description: 'Configuration des postes de caisse nommés du site.', icon: 'wallet', permission: 'cash_registers.view', link: '/administration/cash-registers', tone: 'emerald' },
    { title: 'Diagnostics', description: 'Référentiel clinique utilisé par la recherche rapide des médecins.', icon: 'clipboard', permission: 'diagnostic_catalog.view', link: '/administration/diagnostics', tone: 'amber' },
    { title: 'Analyses laboratoire', description: 'Paramètres, unités et valeurs de référence du catalogue Laboratoire.', icon: 'activity', permission: 'analysis_catalog.view', link: '/administration/analyses', tone: 'sky' },
];

const toneClasses = {
    primary: 'bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300',
    sky: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300',
    emerald: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    amber: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    violet: 'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300',
    rose: 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
    slate: 'bg-gray-100 text-slate-600 dark:bg-gray-900 dark:text-slate-300',
};
</script>

<template>
    <Head title="Ressources humaines" />

    <div class="w-full space-y-6">
        <HrNav />

        <section class="relative overflow-hidden rounded-2xl bg-slate-900 px-6 py-7 text-white shadow-lg sm:px-8 lg:py-9">
            <div class="absolute -end-16 -top-24 h-64 w-64 rounded-full bg-primary-500/20 blur-3xl" />
            <div class="absolute -bottom-28 start-1/3 h-56 w-56 rounded-full bg-sky-500/10 blur-3xl" />
            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-2xl">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-primary-300">Clinique Saint Georges · {{ siteName }}</p>
                    <h1 class="mt-3 font-heading text-3xl font-bold tracking-tight sm:text-4xl">Espace Ressources humaines</h1>
                    <p class="mt-3 max-w-xl text-sm leading-6 text-slate-300">Une vue claire du personnel, des contrats, des présences et de l’organisation quotidienne. Les informations restent locales au site et chaque changement sensible est audité.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="can('employees.create')" :as="Link" href="/administration/employees/create" size="rg" variant="primary">
                        <Icon class="text-lg" name="user-add" /><span class="ms-2">Nouvel employé</span>
                    </Button>
                    <Button v-if="can('leave.create')" :as="Link" href="/administration/leave/create" size="rg" variant="white-outline">
                        <Icon class="text-lg" name="calendar" /><span class="ms-2">Demande de congé</span>
                    </Button>
                </div>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <HrStatCard label="Employés actifs" :value="summary.active_employees" hint="Dossiers actifs" icon="users" tone="primary" />
            <HrStatCard label="Contrats en cours" :value="summary.current_contracts" hint="Actifs aujourd’hui" icon="file-docs" tone="sky" />
            <HrStatCard label="Employés pointés" :value="summary.today_attendance" hint="Au moins une session aujourd’hui" icon="clock" tone="emerald" />
            <HrStatCard label="Congés à traiter" :value="summary.pending_leave" hint="Décision en attente" icon="alert-circle" tone="amber" />
            <HrStatCard class="sm:col-span-2 xl:col-span-1" label="Créneaux · 7 jours" :value="summary.upcoming_shifts" hint="Planning à venir" icon="calender-date" tone="violet" />
        </section>

        <section class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(320px,.55fr)]">
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <header class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><p class="text-[11px] font-bold uppercase tracking-wide text-amber-600">File de travail</p><h2 class="mt-1 text-lg font-bold text-slate-800 dark:text-white">À vérifier maintenant</h2><p class="mt-1 text-xs text-slate-500">Des faits à traiter, sans déduire automatiquement retard, absence ou droit à congé.</p></header>
                <div class="grid sm:grid-cols-3">
                    <Link v-if="can('attendance.view')" href="/administration/attendance" class="flex items-center gap-3 border-b border-gray-200 p-4 transition hover:bg-amber-50/50 dark:border-gray-900 dark:hover:bg-amber-950/10 sm:border-b-0 sm:border-e"><span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', summary.open_attendance ? 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300']"><Icon name="clock" /></span><span><strong class="block text-lg text-slate-800 dark:text-white">{{ summary.open_attendance }}</strong><span class="text-xs text-slate-500">présence(s) sans sortie</span></span></Link>
                    <Link v-if="can('leave.view')" href="/administration/leave?status=PENDING" class="flex items-center gap-3 border-b border-gray-200 p-4 transition hover:bg-amber-50/50 dark:border-gray-900 dark:hover:bg-amber-950/10 sm:border-b-0 sm:border-e"><span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', summary.pending_leave ? 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300']"><Icon name="calendar" /></span><span><strong class="block text-lg text-slate-800 dark:text-white">{{ summary.pending_leave }}</strong><span class="text-xs text-slate-500">congé(s) à décider</span></span></Link>
                    <Link v-if="can('contracts.view')" href="/administration/contracts" class="flex items-center gap-3 p-4 transition hover:bg-amber-50/50 dark:hover:bg-amber-950/10"><span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', summary.contracts_ending_soon ? 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300']"><Icon name="file-docs" /></span><span><strong class="block text-lg text-slate-800 dark:text-white">{{ summary.contracts_ending_soon }}</strong><span class="text-xs text-slate-500">fin(s) sous 30 jours</span></span></Link>
                </div>
            </div>
            <aside class="rounded-xl border border-primary-200 bg-primary-50 p-5 dark:border-primary-900 dark:bg-primary-950/20"><div class="flex items-start gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-primary-600 shadow-sm dark:bg-gray-950"><Icon name="user-add" /></span><div><h2 class="text-sm font-bold text-primary-900 dark:text-primary-100">Parcours de recrutement</h2><p class="mt-1 text-xs leading-5 text-primary-800/80 dark:text-primary-200/80">Créez d’abord l’identité RH. Depuis sa fiche, le contrat, la présence, le congé et le planning reprennent automatiquement cet employé.</p><Link v-if="can('employees.create')" href="/administration/employees/create" class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-primary-700 hover:text-primary-800 dark:text-primary-300">Commencer un dossier <Icon name="arrow-right" /></Link></div></div></aside>
        </section>

        <section>
            <div class="mb-4 flex items-end justify-between gap-4">
                <div><h2 class="font-heading text-xl font-bold text-slate-800 dark:text-white">Gestion RH</h2><p class="mt-1 text-sm text-slate-500">Accédez directement à votre tâche.</p></div>
                <Link v-if="can('hr_settings.view')" href="/administration/settings" class="text-xs font-bold text-primary-600 hover:text-primary-700">Configurer les référentiels →</Link>
            </div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Link v-for="area in areas.filter((entry) => can(entry.permission))" :key="area.link" :href="area.link" class="group rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-primary-200 hover:shadow-md dark:border-gray-900 dark:bg-gray-950 dark:hover:border-gray-700">
                    <div class="flex items-start justify-between gap-4">
                        <span :class="['flex h-11 w-11 items-center justify-center rounded-xl text-xl', toneClasses[area.tone]]"><Icon :name="area.icon" /></span>
                        <Icon class="text-lg text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-primary-500" name="arrow-right" />
                    </div>
                    <h3 class="mt-5 text-base font-bold text-slate-800 dark:text-white">{{ area.title }}</h3>
                    <p class="mt-1.5 text-sm leading-6 text-slate-500">{{ area.description }}</p>
                </Link>
            </div>
        </section>

        <aside class="flex items-start gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-slate-500 dark:bg-gray-900"><Icon class="text-lg" name="shield-check" /></span>
            <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Confidentialité et traçabilité</h2><p class="mt-1 text-xs leading-5 text-slate-500">Les dossiers RH sont séparés des comptes utilisateurs. Les documents sont stockés hors du répertoire public, les archives restent restaurables et aucune donnée de paie automatique n’est calculée avec une formule non validée.</p></div>
        </aside>
    </div>
</template>
