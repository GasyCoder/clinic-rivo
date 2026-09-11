<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';

defineOptions({ layout: AppLayout });
defineProps({ contract: Object });
const page = usePage();
const formatDate = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' }).format(new Date(`${value}T00:00:00`))
    : 'Non renseignée';
</script>

<template>
    <Head title="Aperçu du contrat" />
    <div class="mx-auto max-w-4xl space-y-3">
        <div class="print-actions flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"><Button :as="Link" href="/administration/contracts" size="rg" variant="white-outline"><Icon name="arrow-left" /><span class="ms-2">Retour</span></Button><Button size="rg" variant="white-outline" @click="window.print()"><Icon name="printer" /><span class="ms-2">Imprimer cette fiche</span></Button></div>

        <aside class="print-actions flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800"><Icon class="mt-0.5 shrink-0" name="file-text" /><p><strong>Document fusionné et imprimable.</strong> Générez-le depuis Administration → Documents, canevas « {{ contract.contract_type }} ».</p></aside>

        <article class="print-document bg-white p-8 text-slate-900">
            <header class="flex justify-between border-b-2 border-slate-900 pb-4"><div><strong class="text-lg uppercase">{{ page.props.site?.brand || 'Clinique Saint Georges' }}</strong><p class="mt-1 text-xs">Site {{ page.props.site?.name || '' }}</p></div><div class="text-end"><h1 class="text-xl font-black uppercase">Fiche de contrat</h1><p class="font-mono text-xs">{{ contract.uuid }}</p></div></header>
            <div class="mt-8 grid grid-cols-2 gap-5 text-sm"><p><strong>Employé :</strong><br>{{ contract.employee.name }}</p><p><strong>Matricule :</strong><br>{{ contract.employee.employee_number }}</p><p><strong>Fonction :</strong><br>{{ contract.employee.job_title || 'Non renseignée' }}</p><p><strong>Service :</strong><br>{{ contract.employee.department || 'Non renseigné' }}</p><p><strong>Type :</strong><br>{{ contract.contract_type }}</p><p><strong>Référence :</strong><br>{{ contract.reference_number || 'Non renseignée' }}</p><p><strong>Signature :</strong><br>{{ formatDate(contract.signed_on) }}</p><p><strong>Début :</strong><br>{{ formatDate(contract.starts_on) }}</p><p><strong>Fin d’essai :</strong><br>{{ formatDate(contract.trial_ends_on) }}</p><p><strong>Fin :</strong><br>{{ formatDate(contract.ends_on) }}</p><p class="col-span-2"><strong>Observation :</strong><br>{{ contract.observation || 'Aucune' }}</p></div>
            <footer class="mt-16 grid grid-cols-2 gap-16 text-center text-xs"><p class="border-t border-slate-500 pt-2">Signature du personnel</p><p class="border-t border-slate-500 pt-2">Administration / RH</p></footer>
        </article>
    </div>
</template>

<style>@media print {.print-actions,aside,nav{display:none!important}.print-document{padding:0!important}}</style>
