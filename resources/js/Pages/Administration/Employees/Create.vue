<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArrowLeft } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import HrPageHeader from '../Partials/HrPageHeader.vue';
import EmployeeForm from './EmployeeForm.vue';

defineOptions({ layout: AppLayout });
const props = defineProps({
    options: Object, departments: Array, jobTitles: Array, addresses: [Array, Object],
    // ADR-191 — le prochain matricule du modèle du site, proposé et modifiable.
    suggestedEmployeeNumber: { type: String, default: '' },
    employeeNumberModel: { type: String, default: '' },
});

const form = useForm({
    employee_number: props.suggestedEmployeeNumber ?? '', department_uuid: '', job_title_uuid: '', first_name: '', last_name: '', sex: '',
    birth_date: '', hire_date: '', birth_place: '', identity_document_type: '', identity_document_number: '',
    identity_document_issued_on: '', identity_document_issued_at: '', marital_status: '', children_count: '',
    diploma: '', education_level: '', children_details: '', badge: '', blouse: '', phone: '',
    address_entry_uuid: '', new_address_label: '', observation: '', active: true,
});

const submit = () => form.post(hrUrl('/administration/employees'));
</script>

<template>
    <Head title="Nouvel employé" />
    <div class="w-full space-y-5">
        <HrPageHeader eyebrow="Dossier personnel · Parcours guidé" title="Créer un employé" description="Avancez étape par étape. Le dossier est enregistré uniquement après votre vérification finale et aucun compte de connexion n’est créé." icon="user-add">
            <template #actions><Button :as="Link" :href="hrUrl('/administration/employees')" variant="outline"><ArrowLeft class="h-4 w-4" />Retour aux employés</Button></template>
        </HrPageHeader>
        <EmployeeForm :form="form" :options="options" :departments="departments" :job-titles="jobTitles" :addresses="addresses" :suggested-employee-number="suggestedEmployeeNumber" :employee-number-model="employeeNumberModel" submit-label="Créer le dossier" :cancel-href="hrUrl('/administration/employees')" @submit="submit" />
    </div>
</template>
