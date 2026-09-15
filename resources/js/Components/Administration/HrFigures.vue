<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/UI/Icon.vue';

/**
 * ADR-066 — the HR figures, identical wherever they appear: the site overview,
 * the HR space and the central portal. Same order, same words, same colours.
 * At the clinic each card opens its list; on the portal they are read-only.
 * A null figure means the account may not see it, and the card is hidden.
 */
const props = defineProps({
    summary: { type: Object, required: true },
    // true at the clinic: each card links to the list behind the figure.
    linkable: { type: Boolean, default: false },
    // 'todo' = only what waits for a decision; 'all' adds the headcount row.
    show: { type: String, default: 'all' },
});

const TONES = {
    amber: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300',
    sky: 'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300',
    rose: 'bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-300',
    primary: 'bg-primary-50 text-primary-600 dark:bg-primary-950/40 dark:text-primary-300',
    emerald: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300',
    violet: 'bg-violet-50 text-violet-600 dark:bg-violet-950/40 dark:text-violet-300',
};

const todo = computed(() => [
    { key: 'pending_leave', icon: 'calendar', label: 'Congés à décider', href: '/administration/leave?status=PENDING', tone: 'amber' },
    { key: 'open_attendance', icon: 'clock', label: 'Présences sans heure de sortie', href: '/administration/attendance', tone: 'sky' },
    { key: 'contracts_ending_soon', icon: 'file-docs', label: 'Contrats qui finissent sous 30 jours', href: '/administration/contracts', tone: 'rose' },
].filter((item) => props.summary[item.key] !== null && props.summary[item.key] !== undefined));

const headcount = computed(() => [
    { key: 'active_employees', icon: 'users', label: 'Employés actifs', href: '/administration/employees', tone: 'primary', hint: props.summary.inactive_employees !== undefined ? `${props.summary.inactive_employees} inactif(s) · ${props.summary.archived_employees} archivé(s)` : null },
    { key: 'current_contracts', icon: 'file-docs', label: 'Contrats en cours', href: '/administration/contracts', tone: 'sky' },
    { key: 'today_attendance', icon: 'check-circle', label: 'Pointés aujourd’hui', href: '/administration/attendance', tone: 'emerald' },
    { key: 'upcoming_shifts', icon: 'calender-date', label: 'Créneaux dans 7 jours', href: '/administration/planning', tone: 'violet' },
].filter((item) => props.summary[item.key] !== null && props.summary[item.key] !== undefined));
</script>

<template>
    <div class="space-y-3">
        <div v-if="todo.length" class="grid gap-3 md:grid-cols-3">
            <component
                :is="linkable ? Link : 'div'"
                v-for="item in todo"
                :key="item.key"
                :href="linkable ? item.href : undefined"
                :class="['flex items-center gap-4 rounded-xl border bg-white p-4 shadow-sm dark:bg-gray-950', summary[item.key] ? 'border-amber-200 dark:border-amber-900' : 'border-gray-200 dark:border-gray-900', linkable && 'transition hover:shadow-md']"
            >
                <span :class="['flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-2xl', TONES[item.tone]]"><Icon :name="item.icon" /></span>
                <span class="min-w-0">
                    <span :class="['block text-3xl font-bold tabular-nums', summary[item.key] ? 'text-slate-800 dark:text-white' : 'text-slate-300 dark:text-slate-600']">{{ summary[item.key] }}</span>
                    <span class="block text-sm text-slate-500">{{ item.label }}</span>
                </span>
                <Icon v-if="linkable" name="chevron-right" class="ms-auto text-xl text-slate-300" />
            </component>
        </div>

        <div v-if="show === 'all' && headcount.length" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <component
                :is="linkable ? Link : 'div'"
                v-for="item in headcount"
                :key="item.key"
                :href="linkable ? item.href : undefined"
                :class="['flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-900 dark:bg-gray-950', linkable && 'transition hover:shadow-md']"
            >
                <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-xl', TONES[item.tone]]"><Icon :name="item.icon" /></span>
                <span class="min-w-0">
                    <span class="block text-2xl font-bold tabular-nums text-slate-800 dark:text-white">{{ summary[item.key] }}</span>
                    <span class="block text-xs text-slate-500">{{ item.label }}</span>
                    <span v-if="item.hint" class="block text-[11px] text-slate-400">{{ item.hint }}</span>
                </span>
            </component>
        </div>
    </div>
</template>
