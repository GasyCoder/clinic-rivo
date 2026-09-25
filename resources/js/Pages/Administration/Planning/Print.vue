<script setup>
import { hrSiteName, hrUrl } from '@/utilities/hrUrl';
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { PERIOD_LABELS, groupByDay, shiftPeriod, shiftTime } from '@/utilities/planningCalendar';

defineOptions({ layout: AppLayout });

/*
 * Le planning imprimé (ADR-194) : jour par jour, avec le type (service ou
 * garde) et jour / nuit / 24 h écrits en toutes lettres — une feuille
 * affichée au poste doit se lire sans couleur.
 */
const props = defineProps({ shifts: [Array, Object], period: Object, kind: { type: String, default: null } });
const page = usePage();
const days = computed(() => [...groupByDay(props.shifts).entries()]);
const fmt = (date, options) => new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', ...options }).format(new Date(`${date}T00:00:00Z`));
const printDocument = () => window.print();
</script>

<template>
    <Head title="Planning à imprimer" />
    <div class="mx-auto w-full max-w-6xl space-y-3">
        <div class="print-actions flex justify-between gap-2">
            <Button :as="Link" :href="hrUrl('/administration/planning')" variant="outline"><ArrowLeft class="h-4 w-4" />Retour</Button>
            <Button @click="printDocument"><Printer class="h-4 w-4" />Imprimer</Button>
        </div>
        <article class="print-doc bg-white p-8 text-slate-900">
            <header class="flex justify-between border-b-2 border-slate-900 pb-4">
                <div><strong class="uppercase">{{ page.props.site?.brand || 'Clinique Saint Georges' }}</strong><p class="text-xs">Site {{ hrSiteName() }}</p></div>
                <div class="text-end">
                    <h1 class="text-xl font-black uppercase">{{ kind || 'Planning du personnel et de garde' }}</h1>
                    <p class="text-xs">Du {{ fmt(period.from, { day: '2-digit', month: '2-digit', year: 'numeric' }) }} au {{ fmt(period.to, { day: '2-digit', month: '2-digit', year: 'numeric' }) }}</p>
                </div>
            </header>
            <p v-if="!days.length" class="mt-8 text-sm">Aucun créneau sur cette période.</p>
            <section v-for="[day, dayShifts] in days" :key="day" class="mt-6 break-inside-avoid">
                <h2 class="border-b border-slate-400 pb-1 text-sm font-bold capitalize">{{ fmt(day, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) }}</h2>
                <table class="mt-2 w-full border-collapse text-xs">
                    <thead><tr>
                        <th class="border border-slate-400 p-1.5 text-start">Horaire</th>
                        <th class="border border-slate-400 p-1.5 text-start">Type</th>
                        <th class="border border-slate-400 p-1.5 text-start">Personne</th>
                        <th class="border border-slate-400 p-1.5 text-start">Département</th>
                        <th class="border border-slate-400 p-1.5 text-start">Objet</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="shift in dayShifts" :key="shift.uuid">
                            <td class="border border-slate-300 p-1.5 font-semibold">{{ shiftTime(shift.starts_at) }} → {{ shiftTime(shift.ends_at) }}</td>
                            <td class="border border-slate-300 p-1.5">{{ shift.kind_label }} · {{ PERIOD_LABELS[shiftPeriod(shift)] }}</td>
                            <td class="border border-slate-300 p-1.5">{{ shift.employee.name }} <span class="text-slate-500">({{ shift.employee.employee_number }})</span></td>
                            <td class="border border-slate-300 p-1.5">{{ shift.department || shift.employee.department || 'N/R' }}</td>
                            <td class="border border-slate-300 p-1.5">{{ shift.title || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </article>
    </div>
</template>

<style>
@media print { .print-actions, aside, nav { display: none !important; } .print-doc { padding: 0 !important; } }
</style>
