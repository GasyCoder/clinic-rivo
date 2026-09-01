<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import HrEmptyState from '../Partials/HrEmptyState.vue';
import HrNav from '../Partials/HrNav.vue';
import HrPageHeader from '../Partials/HrPageHeader.vue';
import HrPagination from '../Partials/HrPagination.vue';
import HrStatCard from '../Partials/HrStatCard.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });
const props = defineProps({ records: Object, employees: [Array, Object], filters: Object, summary: Object });
const { can } = usePermissions();
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const employee = ref(props.filters.employee ?? '');
const queryString = computed(() => new URLSearchParams({ from: from.value, to: to.value, ...(employee.value ? { employee: employee.value } : {}) }).toString());
const filter = () => router.get('/administration/attendance', { from: from.value, to: to.value, employee: employee.value || undefined }, { preserveState: true, replace: true });
const dateTime = (value) => value ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
const duration = (minutes) => minutes === null ? 'Ouverte' : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
</script>

<template>
    <Head title="Présences" />
    <div class="space-y-5">
        <HrNav />
        <HrPageHeader eyebrow="Temps constaté" title="Présences" description="Consultez les sessions d’entrée et de sortie réellement enregistrées, sans calcul automatique de retard ni d’heures supplémentaires." icon="clock" tone="violet">
            <template #actions><Button v-if="can('attendance.print')" :as="Link" :href="`/administration/attendance/print?${queryString}`" size="rg" variant="white-outline"><Icon name="printer" /><span class="ms-2">Imprimer</span></Button><Button v-if="can('attendance.export')" as="a" :href="`/administration/attendance/export?${queryString}`" size="rg" variant="white-outline"><Icon name="download" /><span class="ms-2">Exporter</span></Button><Button v-if="can('attendance.create')" :as="Link" href="/administration/attendance/create" size="rg"><Icon name="plus" /><span class="ms-2">Nouvelle présence</span></Button></template>
        </HrPageHeader>

        <section class="grid gap-3 sm:grid-cols-3"><HrStatCard label="Sessions" :value="summary.sessions" hint="Sur la période affichée" icon="activity" tone="violet" /><HrStatCard label="Employés concernés" :value="summary.employees" hint="Personnel distinct" icon="users" tone="sky" /><HrStatCard label="Sessions ouvertes" :value="summary.open" hint="Sortie encore non renseignée" icon="clock" tone="amber" /></section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <form class="grid gap-3 border-b border-gray-200 p-4 dark:border-gray-900 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_2fr_auto]" @submit.prevent="filter">
                <div><label for="attendance_from" class="mb-1.5 block text-xs font-bold text-slate-500">Du</label><input id="attendance_from" v-model="from" type="date" class="h-10 w-full rounded-lg border border-gray-200 px-3 text-sm dark:border-gray-800 dark:bg-gray-950"></div>
                <div><label for="attendance_to" class="mb-1.5 block text-xs font-bold text-slate-500">Au</label><input id="attendance_to" v-model="to" type="date" class="h-10 w-full rounded-lg border border-gray-200 px-3 text-sm dark:border-gray-800 dark:bg-gray-950"></div>
                <div><label for="attendance_employee" class="mb-1.5 block text-xs font-bold text-slate-500">Employé</label><select id="attendance_employee" v-model="employee" class="h-10 w-full rounded-lg border border-gray-200 px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white"><option value="">Tous les employés</option><option v-for="item in employees" :key="item.uuid" :value="item.uuid">{{ item.employee_number }} · {{ item.name }}</option></select></div>
                <Button class="self-end" size="rg">Afficher</Button>
            </form>
            <div v-if="records.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[880px]"><thead><tr class="bg-gray-50/70 text-[11px] font-bold uppercase tracking-wide text-slate-400 dark:bg-gray-1000/40"><th class="px-5 py-3 text-start">Employé</th><th class="px-5 py-3 text-start">Entrée</th><th class="px-5 py-3 text-start">Sortie</th><th class="px-5 py-3 text-start">Durée constatée</th><th class="px-5 py-3 text-start">Observation</th><th class="px-5 py-3 text-end">Action</th></tr></thead><tbody><tr v-for="record in records.data" :key="record.uuid" class="border-t border-gray-100 transition hover:bg-gray-50/60 dark:border-gray-900 dark:hover:bg-gray-1000/30"><td class="px-5 py-4"><strong class="text-sm text-slate-800 dark:text-white">{{ record.employee.name }}</strong><p class="mt-0.5 font-mono text-xs text-slate-400">{{ record.employee.employee_number }}</p></td><td class="px-5 py-4 text-sm text-slate-600 dark:text-slate-300">{{ dateTime(record.started_at) }}</td><td class="px-5 py-4 text-sm text-slate-600 dark:text-slate-300">{{ dateTime(record.ended_at) }}</td><td class="px-5 py-4"><span :class="['rounded-full px-2.5 py-1 text-xs font-bold', record.minutes === null ? 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300' : 'bg-violet-50 text-violet-700 dark:bg-violet-950 dark:text-violet-300']">{{ duration(record.minutes) }}</span></td><td class="max-w-xs px-5 py-4 text-sm text-slate-500">{{ record.observation || '—' }}</td><td class="px-5 py-4 text-end"><Button v-if="can('attendance.update')" :as="Link" :href="`/administration/attendance/${record.uuid}/edit`" icon size="rg" variant="white-outline" title="Corriger"><Icon name="edit" /></Button></td></tr></tbody></table>
            </div>
            <HrEmptyState v-else icon="clock" title="Aucune présence sur cette période" description="Élargissez la période, changez d’employé ou enregistrez une nouvelle session." />
            <HrPagination :paginator="records" />
        </section>
    </div>
</template>
