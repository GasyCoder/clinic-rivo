<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';

defineOptions({ layout: AppLayout });
defineProps({ employee: Object });
const page = usePage();
const printDocument = () => window.print();
const formatDate = (value) => value ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' }).format(new Date(`${value}T00:00:00`)) : 'N/R';
</script>

<template>
    <Head :title="`Fiche ${employee.employee_number}`" />
    <div class="employee-print-page mx-auto w-full max-w-4xl space-y-3">
        <div class="print-actions flex items-center justify-between gap-3"><Button :as="Link" :href="`/administration/employees/${employee.uuid}`" size="rg" variant="white-outline"><Icon name="arrow-left" /><span class="ms-2">Retour au dossier</span></Button><Button size="rg" @click="printDocument"><Icon name="printer" /><span class="ms-2">Imprimer</span></Button></div>
        <article class="employee-print-document">
            <header class="flex items-start justify-between gap-6 border-b-2 border-slate-900 pb-4"><div><p class="text-lg font-black uppercase">{{ page.props.site?.brand || 'Clinique Saint Georges' }}</p><p class="mt-1 text-xs">Site {{ page.props.site?.name || '' }}</p></div><div class="text-end"><h1 class="text-xl font-black uppercase">Fiche du personnel</h1><p class="mt-1 font-mono text-sm">{{ employee.employee_number }}</p></div></header>
            <section class="mt-6"><h2 class="border-b border-slate-300 pb-1 text-xs font-black uppercase tracking-wider">Identité professionnelle</h2><div class="mt-3 grid grid-cols-2 gap-x-8 gap-y-3 text-sm"><p><strong>Nom et prénoms :</strong> {{ employee.name }}</p><p><strong>Fonction :</strong> {{ employee.job_title || 'N/R' }}</p><p><strong>Département :</strong> {{ employee.department || 'N/R' }}</p><p><strong>Date d’entrée :</strong> {{ formatDate(employee.hire_date) }}</p><p><strong>Diplôme :</strong> {{ employee.diploma || 'N/R' }}</p><p><strong>Niveau :</strong> {{ employee.education_level || 'N/R' }}</p><p><strong>Badge :</strong> {{ employee.badge || 'N/R' }}</p><p><strong>Blouse :</strong> {{ employee.blouse || 'N/R' }}</p></div></section>
            <section class="mt-6"><h2 class="border-b border-slate-300 pb-1 text-xs font-black uppercase tracking-wider">État civil</h2><div class="mt-3 grid grid-cols-2 gap-x-8 gap-y-3 text-sm"><p><strong>Genre :</strong> {{ employee.sex === 'M' ? 'Homme' : 'Femme' }}</p><p><strong>Naissance :</strong> {{ formatDate(employee.birth_date) }} à {{ employee.birth_place || 'N/R' }}</p><p><strong>Pièce :</strong> {{ employee.identity_document_type || 'N/R' }} {{ employee.identity_document_number || '' }}</p><p><strong>Délivrée :</strong> {{ formatDate(employee.identity_document_issued_on) }} à {{ employee.identity_document_issued_at || 'N/R' }}</p><p><strong>Nombre d’enfants :</strong> {{ employee.children_count ?? 'N/R' }}</p><p><strong>Situation matrimoniale :</strong> {{ employee.marital_status || 'N/R' }}</p><p class="col-span-2"><strong>Détails des enfants :</strong> {{ employee.children_details || 'N/R' }}</p></div></section>
            <section class="mt-6"><h2 class="border-b border-slate-300 pb-1 text-xs font-black uppercase tracking-wider">Coordonnées</h2><div class="mt-3 grid grid-cols-2 gap-x-8 gap-y-3 text-sm"><p><strong>Téléphone :</strong> {{ employee.phone || 'N/R' }}</p><p><strong>Email :</strong> {{ employee.email || 'N/R' }}</p><p class="col-span-2"><strong>Adresse :</strong> {{ employee.address || 'N/R' }}</p><p v-if="employee.observation" class="col-span-2"><strong>Observation :</strong> {{ employee.observation }}</p></div></section>
            <footer class="mt-12 grid grid-cols-2 gap-16 text-center text-xs"><div><div class="h-16" /><p class="border-t border-slate-500 pt-2">Signature du personnel</p></div><div><div class="h-16" /><p class="border-t border-slate-500 pt-2">Administration / RH</p></div></footer>
        </article>
    </div>
</template>

<style>
.employee-print-document { background:#fff; color:#0f172a; padding:32px; font-family:Arial,Helvetica,sans-serif; }
@media print { .print-actions, aside, nav { display:none !important; } .employee-print-page { max-width:none !important; } .employee-print-document { padding:0; } }
</style>
