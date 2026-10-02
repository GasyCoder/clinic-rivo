<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import {
    Activity, ArrowRight, BarChart3, Check, CircleAlert, Download, LayoutDashboard, Printer, Wallet,
} from 'lucide-vue-next';
import HrEmptyState from '../Partials/HrEmptyState.vue';
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
const periodInvalid = computed(() => Boolean(from.value && to.value && from.value > to.value));
const apply = () => periodInvalid.value || router.get(hrUrl('/administration/reports'), { from: from.value, to: to.value }, { preserveState: true, replace: true });
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
        <PageHeader eyebrow="Pilotage administratif" title="Rapports RH" description="Des indicateurs factuels sur les dossiers, contrats, présences, congés et plannings de la période." :icon="BarChart3" tone="emerald">
            <template #actions>
                <Button v-if="can('hr_reports.print')" :as="Link" :href="hrUrl(`/administration/reports/print?${periodQuery}`)" variant="outline"><Printer class="h-4 w-4" />Imprimer</Button>
                <Button v-if="can('hr_reports.export')" as="a" :href="hrUrl(`/administration/reports/export?${periodQuery}`)"><Download class="h-4 w-4" />Exporter Excel</Button>
            </template>
        </PageHeader>

        <form class="overflow-hidden rounded-2xl border border-border bg-card shadow-sm" @submit.prevent="apply">
            <div class="flex flex-col gap-3 border-b border-border bg-muted/40 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Période analysée</p>
                    <h2 class="mt-1 text-sm font-bold text-foreground">{{ periodLabel }}</h2>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button type="button" size="xs" variant="outline" @click="setPreset('month')">Ce mois</Button>
                    <Button type="button" size="xs" variant="outline" @click="setPreset('previous')">Mois précédent</Button>
                    <Button type="button" size="xs" variant="outline" @click="setPreset('year')">Cette année</Button>
                </div>
            </div>
            <div class="grid gap-3 p-5 sm:grid-cols-[1fr_1fr_auto]">
                <label class="block"><span class="mb-1.5 block text-xs font-semibold text-muted-foreground">Du</span><DatePicker id="report_from" v-model="from" :max="to || undefined" /></label>
                <label class="block"><span class="mb-1.5 block text-xs font-semibold text-muted-foreground">Au</span><DatePicker id="report_to" v-model="to" :min="from || undefined" /></label>
                <Button type="submit" class="self-end" :disabled="periodInvalid"><Check class="h-4 w-4" />Appliquer</Button>
            </div>
            <p v-if="periodInvalid" class="flex items-center gap-1.5 px-5 pb-4 text-xs font-medium text-destructive"><CircleAlert class="h-3.5 w-3.5" />La date de début doit précéder la date de fin.</p>
        </form>

        <section class="space-y-3">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/10 text-primary"><LayoutDashboard class="h-4 w-4" /></span>
                <div><h2 class="text-sm font-bold text-foreground">Photographie actuelle</h2><p class="text-xs text-muted-foreground">Ces valeurs ne suivent pas la période.</p></div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"><HrStatCard v-for="item in snapshotMetrics" :key="item.label" :label="item.label" :value="item.value" :hint="item.hint" :icon="item.icon" :tone="item.tone" /></div>
        </section>

        <section class="space-y-3">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40"><Activity class="h-4 w-4" /></span>
                <div><h2 class="text-sm font-bold text-foreground">Activité de la période</h2><p class="text-xs text-muted-foreground">Calculée entre les deux dates choisies.</p></div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><HrStatCard v-for="item in periodMetrics" :key="item.label" :label="item.label" :value="item.value" :hint="item.hint" :icon="item.icon" :tone="item.tone" /></div>
        </section>

        <section class="grid gap-5 lg:grid-cols-2">
            <article class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                <div class="border-b border-border px-5 py-4"><h2 class="font-bold text-foreground">Effectif actif par département</h2><p class="mt-1 text-xs text-muted-foreground">Répartition de l’effectif actuellement actif.</p></div>
                <div v-if="report.by_department.length" class="space-y-4 p-5">
                    <div v-for="item in report.by_department" :key="item.uuid">
                        <div class="mb-1.5 flex items-center justify-between gap-3 text-sm"><span class="truncate text-muted-foreground">{{ item.label }}</span><strong class="tabular-nums text-foreground">{{ item.count }}</strong></div>
                        <div class="h-2 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-primary" :style="{ width: `${(item.count / maxDepartment) * 100}%` }" /></div>
                    </div>
                </div>
                <HrEmptyState v-else icon="users" title="Aucun département renseigné" />
            </article>
            <article class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                <div class="border-b border-border px-5 py-4"><h2 class="font-bold text-foreground">Congés de la période</h2><p class="mt-1 text-xs text-muted-foreground">Par état, pour les demandes qui commencent dans la période.</p></div>
                <div v-if="report.leave_statuses.length" class="space-y-4 p-5">
                    <div v-for="item in report.leave_statuses" :key="item.status">
                        <div class="mb-1.5 flex items-center justify-between gap-3 text-sm"><span class="text-muted-foreground">{{ item.label }}</span><strong class="tabular-nums text-foreground">{{ item.count }}</strong></div>
                        <div class="h-2 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-emerald-500" :style="{ width: `${(item.count / totalLeave) * 100}%` }" /></div>
                    </div>
                </div>
                <HrEmptyState v-else icon="calendar" title="Aucune demande de congé" />
            </article>
        </section>

        <!-- ADR-233 — la paie a son propre écran : salaires, retenues paramétrées, bulletins. -->
        <Link v-if="can('salary_payments.view')" :href="hrUrl('/administration/paie')" class="flex items-center gap-3 rounded-xl border border-border bg-card p-4 text-sm shadow-sm transition hover:border-primary/40">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"><Wallet class="h-4 w-4" /></span>
            <span class="min-w-0 flex-1"><strong class="block text-foreground">Paie du mois</strong><span class="text-xs text-muted-foreground">Salaires, avantages, retenues paramétrées, bulletins et liste de virement.</span></span>
            <ArrowRight class="h-4 w-4 text-muted-foreground" />
        </Link>
    </div>
</template>
