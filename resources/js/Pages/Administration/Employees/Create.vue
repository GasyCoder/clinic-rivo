<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArrowLeft, GraduationCap } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import HrPageHeader from '../Partials/HrPageHeader.vue';
import EmployeeForm from './EmployeeForm.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });
const props = defineProps({
    options: Object, departments: Array, jobTitles: Array, addresses: [Array, Object],
    // ADR-191 — le prochain matricule du modèle du site, proposé et modifiable.
    suggestedEmployeeNumber: { type: String, default: '' },
    employeeNumberModel: { type: String, default: '' },
    currentPair: { type: Object, default: null },
    // ADR-194 — « Nouveau stagiaire » : le stage s'enregistre juste après.
    internshipIntent: { type: Boolean, default: false },
});

const { can } = usePermissions();

const form = useForm({
    employee_number: props.suggestedEmployeeNumber ?? '', department_uuid: '', job_title_uuid: '', first_name: '', last_name: '', sex: '',
    birth_date: '', hire_date: '', birth_place: '', identity_document_type: '', identity_document_number: '',
    identity_document_issued_on: '', identity_document_issued_at: '', marital_status: '', children_count: '',
    diploma: '', education_level: '', children_details: '', badge: '', blouse: '', phone: '',
    address_entry_uuid: '', new_address_label: '', observation: '', active: true,
    // ADR-194 — la photo 4 × 4 part avec le dossier (multipart).
    photo: null, remove_photo: false,
    after: props.internshipIntent ? 'internship' : '',
    // ADR-197 — rémunération et compte bancaire : envoyés seulement avec leur droit.
    ...(can('employees.payroll.update') ? { remuneration_type: '', remuneration_amount: '', bank_account_number: '', bank_account_holder: '' } : {}),
});

const back = props.internshipIntent ? hrUrl('/administration/internships') : hrUrl('/administration/employees');
const submit = () => form.post(hrUrl('/administration/employees'));
</script>

<template>
    <Head :title="internshipIntent ? 'Nouveau stagiaire' : 'Nouvel employé'" />
    <div class="w-full space-y-5">
        <HrPageHeader
            :eyebrow="internshipIntent ? 'Stages · Dossier du stagiaire' : 'Dossier personnel · Parcours guidé'"
            :title="internshipIntent ? 'Nouveau stagiaire' : 'Créer un employé'"
            :description="internshipIntent
                ? 'D’abord son dossier (identité, photo, service d’accueil), puis son stage : filière, école, encadrant et dates.'
                : 'Avancez étape par étape. Le dossier est enregistré uniquement après votre vérification finale et aucun compte de connexion n’est créé.'"
            :icon="internshipIntent ? GraduationCap : 'user-add'"
        >
            <template #actions><Button :as="Link" :href="back" variant="outline"><ArrowLeft class="h-4 w-4" />{{ internshipIntent ? 'Retour aux stages' : 'Retour aux employés' }}</Button></template>
        </HrPageHeader>
        <EmployeeForm
            :form="form"
            :options="options"
            :departments="departments"
            :job-titles="jobTitles"
            :addresses="addresses"
            :current-pair="currentPair"
            :internship-intent="internshipIntent"
            :suggested-employee-number="suggestedEmployeeNumber"
            :employee-number-model="employeeNumberModel"
            :submit-label="internshipIntent ? 'Créer le dossier et saisir le stage' : 'Créer le dossier'"
            :cancel-href="back"
            @submit="submit"
        />
    </div>
</template>
