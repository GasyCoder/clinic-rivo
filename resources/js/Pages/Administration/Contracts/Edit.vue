<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';
import HrNav from '../Partials/HrNav.vue';
import ContractForm from './ContractForm.vue';
defineOptions({ layout: AppLayout });
const props = defineProps({ contract: Object, employees: [Array, Object], contractTypes: [Array, Object] });
const form = useForm({ employee_uuid: props.contract.employee.uuid, contract_type_uuid: props.contract.contract_type_uuid, reference_number: props.contract.reference_number ?? '', signed_on: props.contract.signed_on ?? '', starts_on: props.contract.starts_on, trial_ends_on: props.contract.trial_ends_on ?? '', ends_on: props.contract.ends_on ?? '', observation: props.contract.observation ?? '' });
const submit = () => form.put(`/administration/contracts/${props.contract.uuid}`);
</script>
<template><Head title="Modifier le contrat" /><div class="w-full space-y-5"><HrNav /><header class="flex items-start gap-3"><Link href="/administration/contracts" class="mt-0.5 flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white text-slate-500 dark:border-gray-800 dark:bg-gray-950"><Icon name="arrow-left" /></Link><div><p class="text-xs font-bold uppercase tracking-wide text-sky-600">{{ contract.employee.employee_number }} · {{ contract.contract_type }}</p><h1 class="mt-1 font-heading text-2xl font-bold text-slate-800 dark:text-white">Modifier le contrat</h1><p class="mt-1 text-sm text-slate-500">{{ contract.employee.name }}</p></div></header><ContractForm :form="form" :employees="employees" :contract-types="contractTypes" cancel-href="/administration/contracts" submit-label="Enregistrer les modifications" @submit="submit" /></div></template>
