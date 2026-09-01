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
const props = defineProps({ shifts: Object, filters: Object, summary: Object });
const { can } = usePermissions();
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const periodQuery = computed(() => new URLSearchParams({ from: from.value, to: to.value }).toString());
const filter = () => router.get('/administration/planning', { from: from.value, to: to.value }, { preserveState: true, replace: true });
const time = (value) => new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' }).format(new Date(value));
const groupedShifts = computed(() => {
    const groups = new Map();
    props.shifts.data.forEach((shift) => {
        const date = new Date(shift.starts_at);
        const key = String(shift.starts_at).slice(0, 10);
        if (!groups.has(key)) groups.set(key, { key, label: new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' }).format(date), shifts: [] });
        groups.get(key).shifts.push(shift);
    });
    return [...groups.values()];
});
</script>

<template>
    <Head title="Planning RH" />
    <div class="space-y-5">
        <HrNav />
        <HrPageHeader eyebrow="Organisation des équipes" title="Planning" description="Visualisez les créneaux par journée et leurs affectations. Les éventuels chevauchements restent visibles sans blocage automatique." icon="calender-date" tone="sky">
            <template #actions><Button v-if="can('planning.print')" :as="Link" :href="`/administration/planning/print?${periodQuery}`" size="rg" variant="white-outline"><Icon name="printer" /><span class="ms-2">Imprimer</span></Button><Button v-if="can('planning.export')" as="a" :href="`/administration/planning/export?${periodQuery}`" size="rg" variant="white-outline"><Icon name="download" /><span class="ms-2">Exporter</span></Button><Button v-if="can('planning.create')" :as="Link" href="/administration/planning/create" size="rg"><Icon name="plus" /><span class="ms-2">Nouveau créneau</span></Button></template>
        </HrPageHeader>

        <section class="grid gap-3 sm:grid-cols-2"><HrStatCard label="Créneaux" :value="summary.shifts" hint="Sur la période affichée" icon="calendar" tone="sky" /><HrStatCard label="Employés planifiés" :value="summary.employees" hint="Personnel distinct" icon="users" tone="violet" /></section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <form class="grid gap-3 border-b border-gray-200 p-4 dark:border-gray-900 sm:grid-cols-[1fr_1fr_auto]" @submit.prevent="filter"><div><label for="planning_from" class="mb-1.5 block text-xs font-bold text-slate-500">Du</label><input id="planning_from" v-model="from" type="date" class="h-10 w-full rounded-lg border border-gray-200 px-3 text-sm dark:border-gray-800 dark:bg-gray-950"></div><div><label for="planning_to" class="mb-1.5 block text-xs font-bold text-slate-500">Au</label><input id="planning_to" v-model="to" type="date" class="h-10 w-full rounded-lg border border-gray-200 px-3 text-sm dark:border-gray-800 dark:bg-gray-950"></div><Button class="self-end" size="rg">Afficher la période</Button></form>
            <div v-if="groupedShifts.length" class="divide-y divide-gray-200 dark:divide-gray-900">
                <section v-for="group in groupedShifts" :key="group.key">
                    <div class="flex items-center gap-2 bg-gray-50/70 px-5 py-2.5 dark:bg-gray-1000/30"><span class="h-2 w-2 rounded-full bg-sky-500" /><h2 class="text-xs font-bold capitalize text-slate-600 dark:text-slate-300">{{ group.label }}</h2><span class="ms-auto rounded-full bg-white px-2 py-0.5 text-[11px] font-bold text-slate-400 shadow-sm dark:bg-gray-950">{{ group.shifts.length }}</span></div>
                    <article v-for="shift in group.shifts" :key="shift.uuid" class="grid gap-4 border-t border-gray-100 p-5 first:border-t-0 dark:border-gray-900 lg:grid-cols-[150px_minmax(0,1fr)_minmax(0,.8fr)_auto] lg:items-center"><div><p class="text-base font-bold text-sky-700 dark:text-sky-300">{{ time(shift.starts_at) }} — {{ time(shift.ends_at) }}</p><p class="mt-1 text-xs text-slate-400">Créneau enregistré</p></div><div><h3 class="font-bold text-slate-800 dark:text-white">{{ shift.employee.name }}</h3><p class="mt-1 font-mono text-xs text-slate-400">{{ shift.employee.employee_number }}</p></div><div><p class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ shift.title || 'Créneau sans objet' }}</p><p class="mt-1 text-xs text-slate-400">{{ shift.department || shift.employee.department || 'Département non renseigné' }}</p><p v-if="shift.observation" class="mt-2 text-xs leading-5 text-slate-500">{{ shift.observation }}</p></div><Button v-if="can('planning.update')" :as="Link" :href="`/administration/planning/${shift.uuid}/edit`" icon size="rg" variant="white-outline" title="Modifier"><Icon name="edit" /></Button></article>
                </section>
            </div>
            <HrEmptyState v-else icon="calender-date" title="Aucun créneau sur cette période" description="Modifiez la période affichée ou ajoutez un nouveau créneau au planning." />
            <HrPagination :paginator="shifts" />
        </section>
    </div>
</template>
