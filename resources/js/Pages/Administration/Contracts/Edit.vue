<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Pencil } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ContractForm from './ContractForm.vue';

defineOptions({ layout: AppLayout });
const props = defineProps({
    contract: Object,
    employees: [Array, Object],
    contractTypes: [Array, Object],
    internshipFields: { type: [Array, Object], default: () => [] },
});

const form = useForm({
    employee_uuid: props.contract.employee.uuid, contract_type_uuid: props.contract.contract_type_uuid,
    reference_number: props.contract.reference_number ?? '', signed_on: props.contract.signed_on ?? '',
    starts_on: props.contract.starts_on, trial_ends_on: props.contract.trial_ends_on ?? '',
    ends_on: props.contract.ends_on ?? '', observation: props.contract.observation ?? '',
    internship_field_uuid: props.contract.internship?.field_uuid ?? '',
    internship_school: props.contract.internship?.school ?? '',
    internship_level: props.contract.internship?.level ?? '',
    internship_supervisor_uuid: props.contract.internship?.supervisor?.uuid ?? '',
});
const back = props.contract.internship ? hrUrl('/administration/internships') : hrUrl('/administration/contracts');
const submit = () => form.put(hrUrl(`/administration/contracts/${props.contract.uuid}`));
</script>

<template>
    <Head :title="contract.internship ? 'Modifier le stage' : 'Modifier le contrat'" />
    <div class="w-full space-y-5">
        <PageHeader
            :eyebrow="`${contract.employee.employee_number} · ${contract.contract_type}`"
            :title="contract.internship ? 'Modifier le stage' : 'Modifier le contrat'"
            :description="`${contract.internship ? 'Stage' : 'Contrat'} de ${contract.employee.name}. Toute modification reste auditée.`"
            :icon="Pencil"
            tone="sky"
        >
            <template #actions>
                <Button :as="Link" :href="back" variant="outline"><ArrowLeft class="h-4 w-4" />Retour</Button>
            </template>
        </PageHeader>
        <ContractForm
            :form="form"
            :employees="employees"
            :contract-types="contractTypes"
            :internship-fields="internshipFields"
            :cancel-href="back"
            submit-label="Enregistrer les modifications"
            @submit="submit"
        />
    </div>
</template>
