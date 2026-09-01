<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({ sites: Array, summary: Object });
const selectedSiteCode = ref(props.sites.find((site) => site.ok)?.site.code ?? props.sites[0]?.site.code);
const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value));
const siteSummary = computed(() => selectedSite.value?.data?.summary ?? {});
const departments = computed(() => selectedSite.value?.data?.departments ?? []);
const maxDepartmentCount = computed(() => Math.max(1, ...departments.value.map((department) => department.employees_count)));

const metrics = computed(() => [
    { label: 'Effectif actif', value: summary.active_employees, icon: 'users', tone: 'text-primary-600 bg-primary-50 dark:bg-primary-950' },
    { label: 'Contrats en cours', value: summary.current_contracts, icon: 'file-docs', tone: 'text-sky-600 bg-sky-50 dark:bg-sky-950' },
    { label: 'Présences ouvertes', value: summary.open_attendance, icon: 'clock', tone: summary.open_attendance ? 'text-amber-600 bg-amber-50 dark:bg-amber-950' : 'text-emerald-600 bg-emerald-50 dark:bg-emerald-950' },
    { label: 'Congés à décider', value: summary.pending_leave, icon: 'calendar', tone: summary.pending_leave ? 'text-amber-600 bg-amber-50 dark:bg-amber-950' : 'text-emerald-600 bg-emerald-50 dark:bg-emerald-950' },
    { label: 'Créneaux à 7 jours', value: summary.upcoming_shifts, icon: 'calender-date', tone: 'text-violet-600 bg-violet-50 dark:bg-violet-950' },
]);
</script>

<template>
    <Head title="Ressources humaines multi-sites" />

    <div class="w-full space-y-5">
        <header class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-950"><Icon class="text-2xl" name="users" /></span>
                <div><p class="text-xs font-bold uppercase tracking-wide text-primary-600">Super Administration · API inter-sites</p><h1 class="mt-0.5 font-heading text-2xl font-bold text-slate-800 dark:text-white">Ressources humaines</h1><p class="mt-1 max-w-3xl text-sm text-slate-500">Supervision factuelle des effectifs et tâches RH. Chaque chiffre vient de l’API du site concerné.</p></div>
            </div>
            <div class="inline-flex items-center gap-2 self-start rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs text-slate-500 dark:border-gray-900 dark:bg-gray-950"><span :class="['h-2 w-2 rounded-full', summary.online_sites === sites.length ? 'bg-emerald-500' : 'bg-amber-500']" /><strong class="text-slate-700 dark:text-white">{{ summary.online_sites }} / {{ sites.length }}</strong> sites disponibles</div>
        </header>

        <section class="grid overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950 sm:grid-cols-2 xl:grid-cols-5">
            <div v-for="(metric, index) in metrics" :key="metric.label" :class="['flex items-center gap-3 px-5 py-4', index < metrics.length - 1 ? 'border-b border-gray-200 dark:border-gray-900 sm:border-e xl:border-b-0' : '']"><span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-lg', metric.tone]"><Icon :name="metric.icon" /></span><div><p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">{{ metric.label }}</p><p class="mt-0.5 text-xl font-bold text-slate-800 dark:text-white">{{ metric.value }}</p></div></div>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-900 lg:flex-row lg:items-center lg:justify-between">
                <div class="inline-flex max-w-full gap-1 overflow-x-auto rounded-lg bg-gray-100 p-1 dark:bg-gray-900">
                    <button v-for="site in sites" :key="site.site.code" type="button" :class="['inline-flex shrink-0 items-center gap-2 rounded-md px-3 py-2 text-xs font-bold transition', selectedSiteCode === site.site.code ? 'bg-white text-slate-700 shadow-sm dark:bg-gray-950 dark:text-white' : 'text-slate-500']" @click="selectedSiteCode = site.site.code"><span :class="['h-1.5 w-1.5 rounded-full', site.ok ? 'bg-emerald-500' : site.status === 'UNCONFIGURED' ? 'bg-slate-300' : 'bg-red-500']" />{{ site.site.name }}</button>
                </div>
                <Link v-if="selectedSite" :href="`/super-admin/sites/${selectedSite.site.code}?module=HR`" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-gray-200 px-3 text-xs font-bold text-slate-600 hover:border-primary-300 hover:text-primary-600 dark:border-gray-800 dark:text-slate-300"><Icon name="arrow-right" /> Voir le site {{ selectedSite.site.name }}</Link>
            </div>

            <div v-if="!selectedSite?.ok" class="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center"><span class="flex h-12 w-12 items-center justify-center rounded-xl bg-gray-100 text-xl text-slate-400 dark:bg-gray-900"><Icon name="server" /></span><h2 class="mt-4 text-sm font-bold text-slate-700 dark:text-white">RH indisponible pour {{ selectedSite?.site.name }}</h2><p class="mt-1 max-w-lg text-xs leading-5 text-slate-500">{{ selectedSite?.message }}</p><p class="mt-3 text-xs text-slate-400">Les autres sites continuent de fonctionner indépendamment.</p></div>

            <div v-else class="grid lg:grid-cols-[minmax(0,1.25fr)_minmax(320px,.75fr)]">
                <div class="p-5 sm:p-6 lg:border-e lg:border-gray-200 dark:lg:border-gray-900">
                    <div class="flex items-start justify-between gap-3"><div><p class="text-[11px] font-bold uppercase tracking-wide text-primary-600">{{ selectedSite.site.name }}</p><h2 class="mt-1 text-lg font-bold text-slate-800 dark:text-white">Situation opérationnelle</h2><p class="mt-1 text-xs text-slate-500">Indicateurs calculés par le site, sans transfert de dossiers personnels.</p></div><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-bold uppercase text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">Lecture seule</span></div>
                    <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <article class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Employés actifs</p><p class="mt-2 text-2xl font-bold text-slate-800 dark:text-white">{{ siteSummary.active_employees }}</p><p class="mt-1 text-xs text-slate-400">{{ siteSummary.inactive_employees }} inactif(s) · {{ siteSummary.archived_employees }} archivé(s)</p></article>
                        <article class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Contrats en cours</p><p class="mt-2 text-2xl font-bold text-slate-800 dark:text-white">{{ siteSummary.current_contracts ?? '—' }}</p><p class="mt-1 text-xs text-slate-400">{{ siteSummary.contracts_ending_soon ?? '—' }} fin(s) sous 30 jours</p></article>
                        <article class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Présences ouvertes</p><p :class="['mt-2 text-2xl font-bold', siteSummary.open_attendance ? 'text-amber-600' : 'text-slate-800 dark:text-white']">{{ siteSummary.open_attendance ?? '—' }}</p><p class="mt-1 text-xs text-slate-400">Sessions sans heure de fin</p></article>
                        <article class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Congés en attente</p><p :class="['mt-2 text-2xl font-bold', siteSummary.pending_leave ? 'text-amber-600' : 'text-slate-800 dark:text-white']">{{ siteSummary.pending_leave ?? '—' }}</p><p class="mt-1 text-xs text-slate-400">Décisions à traiter localement</p></article>
                        <article class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Planning à 7 jours</p><p class="mt-2 text-2xl font-bold text-slate-800 dark:text-white">{{ siteSummary.upcoming_shifts ?? '—' }}</p><p class="mt-1 text-xs text-slate-400">Créneaux à venir</p></article>
                    </div>
                </div>

                <aside class="p-5 sm:p-6"><div><p class="text-[11px] font-bold uppercase tracking-wide text-sky-600">Répartition</p><h2 class="mt-1 text-lg font-bold text-slate-800 dark:text-white">Effectif par département</h2></div><div v-if="departments.length" class="mt-5 space-y-4"><div v-for="department in departments" :key="department.uuid || department.label"><div class="mb-1.5 flex items-center justify-between gap-3 text-xs"><span class="truncate font-semibold text-slate-600 dark:text-slate-300">{{ department.label }}</span><strong class="text-slate-800 dark:text-white">{{ department.employees_count }}</strong></div><div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-900"><div class="h-full rounded-full bg-primary-500" :style="{ width: `${Math.max(8, Math.round((department.employees_count / maxDepartmentCount) * 100))}%` }" /></div></div></div><p v-else class="mt-5 rounded-lg bg-gray-50 px-4 py-6 text-center text-xs text-slate-400 dark:bg-gray-900">Aucun effectif actif à répartir.</p></aside>
            </div>
        </section>

        <div class="flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50 p-4 dark:border-sky-900 dark:bg-sky-950/20"><Icon class="mt-0.5 shrink-0 text-lg text-sky-600" name="shield-check" /><p class="text-xs leading-5 text-sky-800 dark:text-sky-200"><strong>Architecture respectée :</strong> le portail central reçoit uniquement des indicateurs agrégés via <code>/api/v1/super-admin/human-resources</code>. Les créations, contrats, présences, congés et plannings restent gérés dans leur clinique et selon leurs permissions locales.</p></div>
    </div>
</template>
