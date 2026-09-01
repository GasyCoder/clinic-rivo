<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';
import HrNav from '../Partials/HrNav.vue';
import HrPageHeader from '../Partials/HrPageHeader.vue';
import ContractForm from './ContractForm.vue';
defineOptions({ layout: AppLayout });
const props = defineProps({ employees: [Array, Object], contractTypes: [Array, Object], selectedEmployeeUuid: String });
const form = useForm({ employee_uuid: props.selectedEmployeeUuid ?? '', contract_type_uuid: '', reference_number: '', signed_on: '', starts_on: '', trial_ends_on: '', ends_on: '', observation: '' });
const submit = () => form.post('/administration/contracts');
</script>
<template><Head title="Nouveau contrat" /><div class="w-full space-y-5"><HrNav /><HrPageHeader eyebrow="Contrats · Parcours guidé" title="Enregistrer un contrat" description="Identifiez le collaborateur, choisissez la nature du contrat puis vérifiez son calendrier. Aucun calcul de paie n’est déclenché." icon="file-docs" tone="sky"><template #actions><Link href="/administration/contracts" class="inline-flex h-10 items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 text-sm font-bold text-slate-600 hover:border-sky-300 hover:text-sky-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200"><Icon name="arrow-left" /> Retour aux contrats</Link></template></HrPageHeader><ContractForm :form="form" :employees="employees" :contract-types="contractTypes" cancel-href="/administration/contracts" submit-label="Créer le contrat" @submit="submit" /></div></template>
