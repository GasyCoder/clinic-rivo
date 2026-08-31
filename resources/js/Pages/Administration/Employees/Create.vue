<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';
import HrNav from '../Partials/HrNav.vue';
import EmployeeForm from './EmployeeForm.vue';

defineOptions({ layout: AppLayout });
defineProps({ options: Object, departments: Array, jobTitles: Array, addresses: [Array, Object] });

const form = useForm({
    employee_number: '', department_uuid: '', job_title_uuid: '', civility: '', first_name: '', last_name: '', sex: '',
    birth_date: '', hire_date: '', birth_place: '', identity_document_type: '', identity_document_number: '',
    identity_document_issued_on: '', identity_document_issued_at: '', marital_status: '', children_count: '',
    diploma: '', education_level: '', children_details: '', badge: '', blouse: '', phone: '', email: '',
    address_entry_uuid: '', new_address_label: '', observation: '', active: true,
});

const submit = () => form.post('/administration/employees');
</script>

<template>
    <Head title="Nouvel employé" />
    <div class="w-full space-y-5">
        <HrNav />
        <header class="flex items-start gap-3">
            <Link href="/administration/employees" class="mt-0.5 flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white text-slate-500 hover:text-primary-600 dark:border-gray-800 dark:bg-gray-950"><Icon name="arrow-left" /></Link>
            <div><p class="text-xs font-bold uppercase tracking-wide text-primary-600">Dossier personnel</p><h1 class="mt-1 font-heading text-2xl font-bold text-slate-800 dark:text-white">Créer un employé</h1><p class="mt-1 text-sm text-slate-500">Chaque donnée pourra être complétée plus tard. Aucun compte de connexion n’est créé.</p></div>
        </header>
        <EmployeeForm :form="form" :options="options" :departments="departments" :job-titles="jobTitles" :addresses="addresses" submit-label="Créer le dossier" cancel-href="/administration/employees" @submit="submit" />
    </div>
</template>
