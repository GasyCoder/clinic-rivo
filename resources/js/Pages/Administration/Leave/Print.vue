<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';

defineOptions({ layout: AppLayout });
defineProps({ leave: Object });

const page = usePage();
const formatDate = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`))
    : 'N/R';
const printPage = () => window.print();
</script>

<template>
    <Head title="Demande de congé" />

    <div class="mx-auto max-w-4xl space-y-3">
        <div class="print-actions flex justify-between">
            <Button :as="Link" href="/administration/leave" size="rg" variant="white-outline">
                <Icon name="arrow-left" /><span class="ms-2">Retour</span>
            </Button>
            <Button size="rg" @click="printPage">
                <Icon name="printer" /><span class="ms-2">Imprimer</span>
            </Button>
        </div>

        <article class="print-doc bg-white p-8 text-slate-900">
            <header class="flex items-start justify-between gap-6 border-b-2 border-slate-900 pb-4">
                <div>
                    <strong class="uppercase">{{ page.props.site?.brand || 'Clinique Saint Georges' }}</strong>
                    <p class="text-xs">Site {{ page.props.site?.name || '' }}</p>
                </div>
                <div class="text-end">
                    <h1 class="text-xl font-black uppercase">Demande de congé</h1>
                    <p class="mt-1 font-mono text-[10px]">{{ leave.uuid }}</p>
                    <p class="mt-1 text-xs">Enregistrée le {{ formatDate(leave.requested_on) }}</p>
                </div>
            </header>

            <section class="mt-7 rounded border border-slate-300 p-4">
                <h2 class="text-xs font-black uppercase tracking-wider">Demandeur</h2>
                <div class="mt-3 grid grid-cols-2 gap-x-5 gap-y-3 text-sm">
                    <p><strong>Nom et prénoms</strong><br>{{ leave.employee.name }}</p>
                    <p><strong>Matricule</strong><br>{{ leave.employee.employee_number }}</p>
                    <p><strong>Fonction</strong><br>{{ leave.employee.job_title || 'N/R' }}</p>
                    <p><strong>Département</strong><br>{{ leave.employee.department || 'N/R' }}</p>
                </div>
            </section>

            <section class="mt-4 rounded border border-slate-300 p-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-xs font-black uppercase tracking-wider">Période demandée</h2>
                        <p class="mt-1 text-sm font-bold">{{ leave.leave_type || 'Type non renseigné' }}</p>
                    </div>
                    <span class="rounded-full border border-slate-300 px-3 py-1 text-xs font-bold">{{ leave.status_label }}</span>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-x-5 gap-y-3 text-sm">
                    <p><strong>Premier jour</strong><br>{{ formatDate(leave.starts_on) }}</p>
                    <p><strong>Dernier jour demandé</strong><br>{{ formatDate(leave.returns_on) }}</p>
                    <p><strong>Durée calculée</strong><br>{{ leave.days_requested ?? 'N/R' }} jour(s)</p>
                    <p><strong>Méthode</strong><br>{{ leave.day_count_method_snapshot === 'WEEKDAYS_INCLUSIVE' ? 'Jours ouvrés, lundi à vendredi' : 'Jours calendaires inclusifs' }}</p>
                    <p v-if="leave.consumes_balance_snapshot"><strong>{{ leave.status === 'APPROVED' ? 'Solde ferme après cette approbation' : 'Solde ferme actuel, demande non déduite' }}</strong><br>{{ leave.remaining_days_snapshot ?? 'N/R' }} jour(s)</p>
                    <p v-if="leave.consumes_balance_snapshot"><strong>Solde projeté avec demandes en attente</strong><br>{{ leave.projected_remaining_days_snapshot ?? 'N/R' }} jour(s)</p>
                </div>
            </section>

            <section class="mt-4 grid grid-cols-2 gap-x-5 gap-y-3 rounded border border-slate-300 p-4 text-sm">
                <p><strong>Intérimaire</strong><br>{{ leave.interim_employee?.name || 'N/R' }}</p>
                <p><strong>Téléphone d’urgence</strong><br>{{ leave.emergency_phone || 'N/R' }}</p>
                <p class="col-span-2"><strong>Adresse pendant le congé</strong><br>{{ leave.leave_address || 'N/R' }}</p>
                <p class="col-span-2 whitespace-pre-line"><strong>Motif</strong><br>{{ leave.reason }}</p>
                <p v-if="leave.decision_reason || leave.cancel_reason" class="col-span-2 whitespace-pre-line"><strong>Décision / observation</strong><br>{{ leave.decision_reason || leave.cancel_reason }}</p>
            </section>

            <footer class="mt-16 grid grid-cols-3 gap-10 text-center text-xs">
                <p class="border-t border-slate-500 pt-2">Demandeur</p>
                <p class="border-t border-slate-500 pt-2">Intérimaire</p>
                <p class="border-t border-slate-500 pt-2">Administration / RH</p>
            </footer>
        </article>
    </div>
</template>

<style>
@media print {
    .print-actions,
    aside,
    nav {
        display: none !important;
    }

    .print-doc {
        padding: 0 !important;
    }
}
</style>
