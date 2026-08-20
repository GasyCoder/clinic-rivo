<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const areas = [
    { title: 'Employés & RH', description: 'Dossiers employés et informations administratives.', icon: 'users', permission: 'employees.view' },
    { title: 'Contrats', description: 'Contrats et archivage administratif.', icon: 'file-docs', permission: 'contracts.view' },
    { title: 'Présences & congés', description: 'Présences, absences et demandes de congé.', icon: 'calendar', permission: 'attendance.view' },
    { title: 'Planning', description: 'Organisation des équipes et services.', icon: 'calender-date', permission: 'planning.view' },
    { title: 'Rapports RH', description: 'Indicateurs et exports administratifs.', icon: 'reports', permission: 'hr_reports.view' },
];
</script>

<template>
    <Head title="Administration" />
    <div class="w-full space-y-5">
        <header><p class="text-xs font-medium uppercase tracking-wide text-slate-400">Gestion interne</p><h1 class="mt-1 font-heading text-2xl font-bold text-slate-700 dark:text-white">Ressources humaines</h1><p class="mt-1 text-sm text-slate-500">Employés, contrats, présences, congés et planning. Logistique et Gardiennage disposent de leurs propres espaces.</p></header>
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <template v-for="area in areas" :key="area.title">
                <Link v-if="can(area.permission) && area.link" :href="area.link" class="rounded-lg border border-gray-200 bg-white p-5 transition-colors hover:border-slate-300 dark:border-gray-900 dark:bg-gray-950 dark:hover:border-gray-700"><Icon class="text-xl text-slate-400" :name="area.icon" /><h2 class="mt-4 text-sm font-bold text-slate-700 dark:text-white">{{ area.title }}</h2><p class="mt-1 text-xs leading-5 text-slate-500">{{ area.description }}</p></Link>
                <article v-else-if="can(area.permission)" class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950"><div class="flex items-start justify-between"><Icon class="text-xl text-slate-400" :name="area.icon" /><span class="rounded bg-gray-100 px-2 py-1 text-[10px] font-medium text-slate-400 dark:bg-gray-900">À construire</span></div><h2 class="mt-4 text-sm font-bold text-slate-700 dark:text-white">{{ area.title }}</h2><p class="mt-1 text-xs leading-5 text-slate-500">{{ area.description }}</p></article>
            </template>
        </section>
        <div class="flex items-start gap-3 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950"><Icon class="mt-0.5 text-lg text-slate-400" name="shield-check" /><p class="text-xs leading-5 text-slate-500">Le rôle Administration ne reçoit plus les droits de gestion des utilisateurs, rôles ou permissions. Une délégation éventuelle doit être individuelle, explicite et auditée.</p></div>
    </div>
</template>
