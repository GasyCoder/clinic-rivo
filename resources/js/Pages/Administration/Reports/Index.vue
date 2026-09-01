<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import HrEmptyState from '../Partials/HrEmptyState.vue';
import HrNav from '../Partials/HrNav.vue';
import HrPageHeader from '../Partials/HrPageHeader.vue';
import HrStatCard from '../Partials/HrStatCard.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });
const props = defineProps({ report: Object, filters: Object });
const { can } = usePermissions();
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const periodQuery = computed(() => new URLSearchParams({ from: from.value, to: to.value }).toString());
const periodLabel = computed(() => {
    const formatter = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' });
    return `Du ${formatter.format(new Date(`${from.value}T00:00:00`))} au ${formatter.format(new Date(`${to.value}T00:00:00`))}`;
});
const apply = () => router.get('/administration/reports', { from: from.value, to: to.value }, { preserveState: true, replace: true });
const localDate = (date) => {
    const offset = date.getTimezoneOffset();
    return new Date(date.getTime() - offset * 60_000).toISOString().slice(0, 10);
};
const setPreset = (preset) => {
    const today = new Date();
    if (preset === 'month') {
        from.value = localDate(new Date(today.getFullYear(), today.getMonth(), 1));
        to.value = localDate(new Date(today.getFullYear(), today.getMonth() + 1, 0));
    } else if (preset === 'previous') {
        from.value = localDate(new Date(today.getFullYear(), today.getMonth() - 1, 1));
        to.value = localDate(new Date(today.getFullYear(), today.getMonth(), 0));
    } else {
        from.value = localDate(new Date(today.getFullYear(), 0, 1));
        to.value = localDate(new Date(today.getFullYear(), 11, 31));
    }
    apply();
};
const duration = (minutes) => `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
const maxDepartment = computed(() => Math.max(1, ...props.report.by_department.map((item) => item.count)));
const totalLeave = computed(() => Math.max(1, props.report.leave_statuses.reduce((sum, item) => sum + item.count, 0)));
const snapshotMetrics = computed(() => [
    { label: 'Employés actifs', value: props.report.summary.active_employees, hint: 'Effectif actif actuel', icon: 'users', tone: 'primary' },
    { label: 'Contrats en cours', value: props.report.summary.current_contracts, hint: 'À la date du jour', icon: 'file-docs', tone: 'sky' },
    { label: 'Congés en attente', value: props.report.summary.pending_leave, hint: 'Toutes périodes', icon: 'alert-circle', tone: 'amber' },
]);
const periodMetrics = computed(() => [
    { label: 'Sessions de présence', value: props.report.summary.attendance_sessions, hint: 'Dans la période', icon: 'activity', tone: 'violet' },
    { label: 'Temps constaté', value: duration(props.report.summary.attendance_minutes), hint: 'Sessions terminées', icon: 'clock', tone: 'emerald' },
    { label: 'Congés acceptés', value: props.report.summary.approved_leave, hint: 'Début dans la période', icon: 'check-circle', tone: 'emerald' },
    { label: 'Créneaux planifiés', value: props.report.summary.planning_shifts, hint: 'Dans la période', icon: 'calender-date', tone: 'sky' },
]);
</script>

<template>
    <Head title="Rapports RH" />
    <div class="space-y-5">
        <HrNav />
        <HrPageHeader eyebrow="Pilotage administratif" title="Rapports RH" description="Analysez des indicateurs factuels sur les dossiers, contrats, présences, congés et plannings de la période." icon="reports" tone="emerald">
            <template #actions><Button v-if="can('hr_reports.print')" :as="Link" :href="`/administration/reports/print?${periodQuery}`" size="rg" variant="white-outline"><Icon name="printer" /><span class="ms-2">Imprimer</span></Button><Button v-if="can('hr_reports.export')" as="a" :href="`/administration/reports/export?${periodQuery}`" size="rg"><Icon name="download" /><span class="ms-2">Exporter Excel</span></Button></template>
        </HrPageHeader>

        <form class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950" @submit.prevent="apply">
            <div class="flex flex-col gap-3 border-b border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/40 sm:flex-row sm:items-center sm:justify-between"><div><p class="text-[11px] font-bold uppercase tracking-wide text-emerald-600">Périmètre d’analyse</p><h2 class="mt-1 text-sm font-bold text-slate-800 dark:text-white">{{ periodLabel }}</h2></div><div class="flex flex-wrap gap-2"><button type="button" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-500 hover:border-emerald-300 hover:text-emerald-700 dark:border-gray-800 dark:bg-gray-950" @click="setPreset('month')">Ce mois</button><button type="button" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-500 hover:border-emerald-300 hover:text-emerald-700 dark:border-gray-800 dark:bg-gray-950" @click="setPreset('previous')">Mois précédent</button><button type="button" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-500 hover:border-emerald-300 hover:text-emerald-700 dark:border-gray-800 dark:bg-gray-950" @click="setPreset('year')">Cette année</button></div></div>
            <div class="grid gap-3 p-5 sm:grid-cols-[1fr_1fr_auto]"><div><label for="report_from" class="mb-1.5 block text-xs font-bold text-slate-500">Période du</label><input id="report_from" v-model="from" type="date" class="h-10 w-full rounded-lg border border-gray-200 px-3 text-sm dark:border-gray-800 dark:bg-gray-950"></div><div><label for="report_to" class="mb-1.5 block text-xs font-bold text-slate-500">Au</label><input id="report_to" v-model="to" type="date" class="h-10 w-full rounded-lg border border-gray-200 px-3 text-sm dark:border-gray-800 dark:bg-gray-950"></div><Button class="self-end" size="rg"><Icon name="check" /><span class="ms-2">Appliquer</span></Button></div>
        </form>

        <section class="space-y-3"><div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-950"><Icon name="dashboard" /></span><div><h2 class="text-sm font-bold text-slate-800 dark:text-white">Photographie actuelle</h2><p class="text-xs text-slate-500">Ces valeurs ne changent pas avec le filtre de période.</p></div></div><div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"><HrStatCard v-for="item in snapshotMetrics" :key="item.label" :label="item.label" :value="item.value" :hint="item.hint" :icon="item.icon" :tone="item.tone" /></div></section>

        <section class="space-y-3"><div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950"><Icon name="activity" /></span><div><h2 class="text-sm font-bold text-slate-800 dark:text-white">Activité de la période</h2><p class="text-xs text-slate-500">Indicateurs calculés uniquement entre les deux dates sélectionnées.</p></div></div><div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><HrStatCard v-for="item in periodMetrics" :key="item.label" :label="item.label" :value="item.value" :hint="item.hint" :icon="item.icon" :tone="item.tone" /></div></section>

        <section class="grid gap-5 lg:grid-cols-2">
            <article class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><h2 class="font-bold text-slate-800 dark:text-white">Effectif actif par département</h2><p class="mt-1 text-xs text-slate-400">Répartition de l’effectif actuellement actif.</p></div>
                <div v-if="report.by_department.length" class="space-y-4 p-5">
                    <div v-for="item in report.by_department" :key="item.uuid"><div class="mb-1.5 flex items-center justify-between gap-3 text-sm"><span class="truncate text-slate-600 dark:text-slate-300">{{ item.label }}</span><strong class="text-slate-800 dark:text-white">{{ item.count }}</strong></div><div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-900"><div class="h-full rounded-full bg-primary-500" :style="{ width: `${(item.count / maxDepartment) * 100}%` }" /></div></div>
                </div>
                <HrEmptyState v-else icon="users" title="Aucun département renseigné" />
            </article>
            <article class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><h2 class="font-bold text-slate-800 dark:text-white">Congés sur la période</h2><p class="mt-1 text-xs text-slate-400">Répartition par état des demandes dont le départ se situe dans la période.</p></div>
                <div v-if="report.leave_statuses.length" class="space-y-4 p-5">
                    <div v-for="item in report.leave_statuses" :key="item.status"><div class="mb-1.5 flex items-center justify-between gap-3 text-sm"><span class="text-slate-600 dark:text-slate-300">{{ item.label }}</span><strong class="text-slate-800 dark:text-white">{{ item.count }}</strong></div><div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-900"><div class="h-full rounded-full bg-emerald-500" :style="{ width: `${(item.count / totalLeave) * 100}%` }" /></div></div>
                </div>
                <HrEmptyState v-else icon="calendar" title="Aucune demande de congé" />
            </article>
        </section>

        <aside class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-300"><Icon class="mt-0.5 shrink-0 text-lg" name="alert-circle" /><p><strong>Paie non activée.</strong> Aucun salaire, CNAPS ou IRSA n’est calculé : les formules doivent être validées officiellement avant toute automatisation.</p></aside>
    </div>
</template>
