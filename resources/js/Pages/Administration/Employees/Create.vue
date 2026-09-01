<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';
import HrNav from '../Partials/HrNav.vue';
import HrPageHeader from '../Partials/HrPageHeader.vue';
import EmployeeForm from './EmployeeForm.vue';

defineOptions({ layout: AppLayout });
defineProps({ options: Object, departments: Array, jobTitles: Array, addresses: [Array, Object] });

const form = useForm({
    employee_number: '', department_uuid: '', job_title_uuid: '', first_name: '', last_name: '', sex: '',
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
        <HrPageHeader eyebrow="Dossier personnel · Parcours guidé" title="Créer un employé" description="Avancez étape par étape. Le dossier est enregistré uniquement après votre vérification finale et aucun compte de connexion n’est créé." icon="user-add">
            <template #actions><Link href="/administration/employees" class="inline-flex h-10 items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 text-sm font-bold text-slate-600 hover:border-gray-300 hover:text-primary-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200"><Icon name="arrow-left" /> Retour aux employés</Link></template>
        </HrPageHeader>
        <EmployeeForm :form="form" :options="options" :departments="departments" :job-titles="jobTitles" :addresses="addresses" submit-label="Créer le dossier" cancel-href="/administration/employees" @submit="submit" />
    </div>
</template>
