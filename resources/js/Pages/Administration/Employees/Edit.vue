<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArrowLeft } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import HrPageHeader from '../Partials/HrPageHeader.vue';
import EmployeeForm from './EmployeeForm.vue';

defineOptions({ layout: AppLayout });
const props = defineProps({ employee: Object, options: Object, departments: Array, jobTitles: Array, addresses: [Array, Object] });

const form = useForm({
    employee_number: props.employee.employee_number,
    department_uuid: props.employee.department_uuid ?? '', job_title_uuid: props.employee.job_title_uuid ?? '',
    first_name: props.employee.first_name ?? '', last_name: props.employee.last_name,
    sex: props.employee.sex, birth_date: props.employee.birth_date ?? '', hire_date: props.employee.hire_date ?? '',
    birth_place: props.employee.birth_place ?? '', identity_document_type: props.employee.identity_document_type ?? '',
    identity_document_number: props.employee.identity_document_number ?? '',
    identity_document_issued_on: props.employee.identity_document_issued_on ?? '',
    identity_document_issued_at: props.employee.identity_document_issued_at ?? '',
    marital_status: props.employee.marital_status ?? '', children_count: props.employee.children_count ?? '',
    diploma: props.employee.diploma ?? '', education_level: props.employee.education_level ?? '',
    children_details: props.employee.children_details ?? '', badge: props.employee.badge ?? '', blouse: props.employee.blouse ?? '',
    phone: props.employee.phone ?? '', address_entry_uuid: props.employee.address_entry_uuid ?? '',
    new_address_label: '', observation: props.employee.observation ?? '', active: props.employee.active,
});

const submit = () => form.put(hrUrl(`/administration/employees/${props.employee.uuid}`));
</script>

<template>
    <Head :title="`Modifier ${employee.name}`" />
    <div class="w-full space-y-5">
        <HrPageHeader :eyebrow="`${employee.employee_number} · Parcours guidé`" :title="`Modifier ${employee.name}`" description="Les changements d’identité sont synchronisés avec le dossier Patient lié lorsqu’il existe." icon="edit">
            <template #actions><Button :as="Link" :href="hrUrl(`/administration/employees/${employee.uuid}`)" variant="outline"><ArrowLeft class="h-4 w-4" />Retour au dossier</Button></template>
        </HrPageHeader>
        <EmployeeForm :form="form" :options="options" :departments="departments" :job-titles="jobTitles" :addresses="addresses" :current-email="employee.email" submit-label="Enregistrer les modifications" :cancel-href="hrUrl(`/administration/employees/${employee.uuid}`)" @submit="submit" />
    </div>
</template>
