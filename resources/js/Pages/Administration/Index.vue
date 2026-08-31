<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import HrNav from './Partials/HrNav.vue';
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
            <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Employés actifs</p>
                <p class="mt-2 text-2xl font-bold text-slate-800 dark:text-white">{{ summary.active_employees }}</p>
            </article>
            <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Contrats en cours</p>
                <p class="mt-2 text-2xl font-bold text-sky-700 dark:text-sky-300">{{ summary.current_contracts }}</p>
            </article>
            <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Présents aujourd’hui</p>
                <p class="mt-2 text-2xl font-bold text-emerald-700 dark:text-emerald-300">{{ summary.today_attendance }}</p>
            </article>
            <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Congés à traiter</p>
                <p class="mt-2 text-2xl font-bold text-amber-700 dark:text-amber-300">{{ summary.pending_leave }}</p>
            </article>
            <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-900 dark:bg-gray-950 sm:col-span-2 xl:col-span-1">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Créneaux · 7 jours</p>
                <p class="mt-2 text-2xl font-bold text-violet-700 dark:text-violet-300">{{ summary.upcoming_shifts }}</p>
            </article>
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
