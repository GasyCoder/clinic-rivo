<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import HrFigures from '@/Components/Administration/HrFigures.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({
    summary: { type: Object, required: true },
    siteName: String,
});

const { can } = usePermissions();

// A figure the account may not open is hidden, exactly as on the portal.
const visibleSummary = computed(() => ({
    ...props.summary,
    current_contracts: can('contracts.view') ? props.summary.current_contracts : null,
    contracts_ending_soon: can('contracts.view') ? props.summary.contracts_ending_soon : null,
    today_attendance: can('attendance.view') ? props.summary.today_attendance : null,
    open_attendance: can('attendance.view') ? props.summary.open_attendance : null,
    pending_leave: can('leave.view') ? props.summary.pending_leave : null,
    upcoming_shifts: can('planning.view') ? props.summary.upcoming_shifts : null,
}));

// One tile per HR task, only those this account may open.
const areas = computed(() => [
    { href: '/administration/employees', icon: 'users', title: 'Employés', meta: 'Dossiers du personnel', permission: 'employees.view', tone: 'primary' },
    { href: '/administration/contracts', icon: 'file-docs', title: 'Contrats', meta: 'CDI, CDD, stages…', permission: 'contracts.view', tone: 'sky' },
    { href: '/administration/generated-documents', icon: 'copy', title: 'Documents', meta: 'Attestations et courriers', permission: 'generated_documents.view', tone: 'violet' },
    { href: '/administration/attendance', icon: 'clock', title: 'Présences', meta: 'Entrées et sorties', permission: 'attendance.view', tone: 'emerald' },
    { href: '/administration/leave', icon: 'calendar', title: 'Congés', meta: 'Demandes et décisions', permission: 'leave.view', tone: 'amber' },
    { href: '/administration/planning', icon: 'calender-date', title: 'Planning', meta: 'Créneaux des équipes', permission: 'planning.view', tone: 'violet' },
    { href: '/administration/reports', icon: 'reports', title: 'Rapports', meta: 'Chiffres et exports', permission: 'hr_reports.view', tone: 'rose' },
    { href: '/administration/staff-block-credits', icon: 'wallet', title: 'Crédit Bloc', meta: 'Crédit du personnel', permission: 'staff_block_credits.view', tone: 'primary' },
    { href: '/administration/settings', icon: 'setting', title: 'Paramètres', meta: 'Départements, fonctions, types', permission: 'hr_settings.view', tone: 'slate' },
].filter((area) => can(area.permission)));

const TONES = {
    primary: 'bg-primary-50 text-primary-600 dark:bg-primary-950/40 dark:text-primary-300',
    sky: 'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300',
    emerald: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300',
    amber: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300',
    violet: 'bg-violet-50 text-violet-600 dark:bg-violet-950/40 dark:text-violet-300',
    rose: 'bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-300',
    slate: 'bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400',
};
</script>

<template>
    <Head title="Accueil RH" />

    <div class="w-full space-y-6">
        <PageHeader
            eyebrow="Ressources humaines"
            :title="`Accueil RH · ${siteName}`"
            description="Le travail RH du site : ce qui attend une décision, l’effectif, puis chaque tâche en un clic."
            icon="briefcase"
            tone="primary"
        >
            <template #actions>
                <Button v-if="can('leave.create')" :as="Link" href="/administration/leave/create" size="rg" variant="white-outline"><Icon name="calendar" /><span class="ms-2">Demande de congé</span></Button>
                <Button v-if="can('employees.create')" :as="Link" href="/administration/employees/create" size="rg"><Icon name="user-add" /><span class="ms-2">Nouvel employé</span></Button>
            </template>
        </PageHeader>

        <section aria-labelledby="hr-figures-title">
            <h2 id="hr-figures-title" class="mb-3 font-heading text-base font-bold text-slate-800 dark:text-white">Aujourd’hui</h2>
            <HrFigures :summary="visibleSummary" linkable />
        </section>

        <section aria-labelledby="hr-areas-title">
            <h2 id="hr-areas-title" class="mb-3 font-heading text-base font-bold text-slate-800 dark:text-white">Que voulez-vous faire ?</h2>
            <div class="grid grid-cols-2 gap-1 rounded-xl border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-900 dark:bg-gray-1000/40 sm:grid-cols-3 lg:grid-cols-5">
                <Link
                    v-for="area in areas"
                    :key="area.href"
                    :href="area.href"
                    class="group flex flex-col items-center rounded-xl border border-transparent px-3 py-5 text-center transition hover:border-gray-200 hover:bg-white hover:shadow-sm dark:hover:border-gray-800 dark:hover:bg-gray-950"
                >
                    <span :class="['flex h-16 w-16 items-center justify-center rounded-2xl text-3xl transition group-hover:scale-105', TONES[area.tone]]"><Icon :name="area.icon" /></span>
                    <span class="mt-3 text-sm font-semibold text-slate-800 dark:text-white">{{ area.title }}</span>
                    <span class="mt-0.5 text-xs text-slate-400">{{ area.meta }}</span>
                </Link>
            </div>
        </section>

        <p class="flex items-start gap-2 px-1 text-xs text-slate-500">
            <Icon name="shield-check" class="mt-0.5" />Les dossiers RH restent sur ce site. Le portail central ne voit que ces chiffres, jamais les dossiers. Chaque modification est tracée et aucune paie n’est calculée automatiquement.
        </p>
    </div>
</template>
