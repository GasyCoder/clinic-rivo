<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';

const page = usePage();
const { can } = usePermissions();
const currentUrl = computed(() => page.url);
const items = [
    { label: 'Vue d’ensemble', href: '/administration', icon: 'dashboard', permission: 'employees.view', exact: true },
    { label: 'Employés', href: '/administration/employees', icon: 'users', permission: 'employees.view' },
    { label: 'Contrats', href: '/administration/contracts', icon: 'file-docs', permission: 'contracts.view' },
    { label: 'Documents', href: '/administration/generated-documents', icon: 'copy', permission: 'generated_documents.view' },
    { label: 'Présences', href: '/administration/attendance', icon: 'clock', permission: 'attendance.view' },
    { label: 'Congés', href: '/administration/leave', icon: 'calendar', permission: 'leave.view' },
    { label: 'Planning', href: '/administration/planning', icon: 'calender-date', permission: 'planning.view' },
    { label: 'Rapports', href: '/administration/reports', icon: 'reports', permission: 'hr_reports.view' },
    { label: 'Crédit Bloc', href: '/administration/staff-block-credits', icon: 'wallet', permission: 'staff_block_credits.view' },
    { label: 'Paramètres', href: '/administration/settings', icon: 'settings', permission: 'hr_settings.view' },
];
const isActive = (item) => item.exact ? currentUrl.value === item.href : currentUrl.value.startsWith(item.href);
</script>

<template>
    <nav class="overflow-x-auto rounded-xl border border-gray-200 bg-white p-1.5 shadow-sm dark:border-gray-900 dark:bg-gray-950" aria-label="Navigation Ressources humaines">
        <div class="flex min-w-max gap-1">
            <Link
                v-for="item in items.filter((entry) => can(entry.permission))"
                :key="item.href"
                :href="item.href"
                :class="[
                    'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-bold transition sm:text-sm',
                    isActive(item)
                        ? 'bg-primary-600 text-white shadow-sm'
                        : 'text-slate-500 hover:bg-gray-100 hover:text-slate-700 dark:text-slate-300 dark:hover:bg-gray-900 dark:hover:text-white',
                ]"
            >
                <Icon class="text-base" :name="item.icon" />
                {{ item.label }}
            </Link>
        </div>
    </nav>
</template>
