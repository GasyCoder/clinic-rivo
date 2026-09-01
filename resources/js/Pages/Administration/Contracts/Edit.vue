<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';
import HrNav from '../Partials/HrNav.vue';
import HrPageHeader from '../Partials/HrPageHeader.vue';
import ContractForm from './ContractForm.vue';
defineOptions({ layout: AppLayout });
const props = defineProps({ contract: Object, employees: [Array, Object], contractTypes: [Array, Object] });
const form = useForm({ employee_uuid: props.contract.employee.uuid, contract_type_uuid: props.contract.contract_type_uuid, reference_number: props.contract.reference_number ?? '', signed_on: props.contract.signed_on ?? '', starts_on: props.contract.starts_on, trial_ends_on: props.contract.trial_ends_on ?? '', ends_on: props.contract.ends_on ?? '', observation: props.contract.observation ?? '' });
const submit = () => form.put(`/administration/contracts/${props.contract.uuid}`);
</script>
<template><Head title="Modifier le contrat" /><div class="w-full space-y-5"><HrNav /><HrPageHeader :eyebrow="`${contract.employee.employee_number} · ${contract.contract_type}`" title="Modifier le contrat" :description="`Contrat de ${contract.employee.name}. Toute modification reste auditée.`" icon="edit" tone="sky"><template #actions><Link href="/administration/contracts" class="inline-flex h-10 items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 text-sm font-bold text-slate-600 hover:border-sky-300 hover:text-sky-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200"><Icon name="arrow-left" /> Retour aux contrats</Link></template></HrPageHeader><ContractForm :form="form" :employees="employees" :contract-types="contractTypes" cancel-href="/administration/contracts" submit-label="Enregistrer les modifications" @submit="submit" /></div></template>
