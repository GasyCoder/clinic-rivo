<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';
import HrNav from '../Partials/HrNav.vue';
import EmployeeForm from './EmployeeForm.vue';

defineOptions({ layout: AppLayout });
const props = defineProps({ employee: Object, options: Object, departments: Array, jobTitles: Array, addresses: [Array, Object] });

const form = useForm({
    employee_number: props.employee.employee_number,
    department_uuid: props.employee.department_uuid ?? '', job_title_uuid: props.employee.job_title_uuid ?? '',
    civility: props.employee.civility ?? '', first_name: props.employee.first_name ?? '', last_name: props.employee.last_name,
    sex: props.employee.sex, birth_date: props.employee.birth_date ?? '', hire_date: props.employee.hire_date ?? '',
    birth_place: props.employee.birth_place ?? '', identity_document_type: props.employee.identity_document_type ?? '',
    identity_document_number: props.employee.identity_document_number ?? '',
    identity_document_issued_on: props.employee.identity_document_issued_on ?? '',
    identity_document_issued_at: props.employee.identity_document_issued_at ?? '',
    marital_status: props.employee.marital_status ?? '', children_count: props.employee.children_count ?? '',
    diploma: props.employee.diploma ?? '', education_level: props.employee.education_level ?? '',
    children_details: props.employee.children_details ?? '', badge: props.employee.badge ?? '', blouse: props.employee.blouse ?? '',
    phone: props.employee.phone ?? '', email: props.employee.email ?? '', address_entry_uuid: props.employee.address_entry_uuid ?? '',
    new_address_label: '', observation: props.employee.observation ?? '', active: props.employee.active,
});

const submit = () => form.put(`/administration/employees/${props.employee.uuid}`);
</script>

<template>
    <Head :title="`Modifier ${employee.name}`" />
    <div class="w-full space-y-5">
        <HrNav />
        <header class="flex items-start gap-3">
            <Link :href="`/administration/employees/${employee.uuid}`" class="mt-0.5 flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white text-slate-500 hover:text-primary-600 dark:border-gray-800 dark:bg-gray-950"><Icon name="arrow-left" /></Link>
            <div><p class="text-xs font-bold uppercase tracking-wide text-primary-600">{{ employee.employee_number }}</p><h1 class="mt-1 font-heading text-2xl font-bold text-slate-800 dark:text-white">Modifier {{ employee.name }}</h1><p class="mt-1 text-sm text-slate-500">Les changements d’identité sont synchronisés avec le dossier Patient lié lorsqu’il existe.</p></div>
        </header>
        <EmployeeForm :form="form" :options="options" :departments="departments" :job-titles="jobTitles" :addresses="addresses" submit-label="Enregistrer les modifications" :cancel-href="`/administration/employees/${employee.uuid}`" @submit="submit" />
    </div>
</template>
