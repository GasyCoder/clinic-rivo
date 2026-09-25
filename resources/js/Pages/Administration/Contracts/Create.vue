<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, FilePlus2, GraduationCap } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ContractForm from './ContractForm.vue';

defineOptions({ layout: AppLayout });
const props = defineProps({
    employees: [Array, Object],
    contractTypes: [Array, Object],
    internshipFields: { type: [Array, Object], default: () => [] },
    selectedEmployeeUuid: String,
    /** ADR-194 — « Enregistrer un stage » : le type de stage est déjà choisi. */
    selectedContractTypeUuid: { type: String, default: null },
});

const form = useForm({
    employee_uuid: props.selectedEmployeeUuid ?? '', contract_type_uuid: props.selectedContractTypeUuid ?? '',
    reference_number: '', signed_on: '', starts_on: '', trial_ends_on: '', ends_on: '', observation: '',
    internship_field_uuid: '', internship_school: '', internship_level: '', internship_supervisor_uuid: '',
});
const forInternship = computed(() => Boolean(props.contractTypes.find((type) => type.uuid === form.contract_type_uuid)?.internship));
const submit = () => form.post(hrUrl('/administration/contracts'));
</script>

<template>
    <Head :title="forInternship ? 'Nouveau stage' : 'Nouveau contrat'" />
    <div class="w-full space-y-5">
        <PageHeader
            :eyebrow="forInternship ? 'Stages · Contrat de stage' : 'Contrats · Parcours guidé'"
            :title="forInternship ? 'Enregistrer un stage' : 'Enregistrer un contrat'"
            :description="forInternship
                ? 'Le stagiaire, sa filière, son école et son encadrant, puis les dates du stage.'
                : 'Choisissez le collaborateur et le type, puis vérifiez le calendrier. Le document à signer se génère ensuite depuis « Documents ».'"
            :icon="forInternship ? GraduationCap : FilePlus2"
            tone="sky"
        >
            <template #actions>
                <Button :as="Link" :href="forInternship ? hrUrl('/administration/internships') : hrUrl('/administration/contracts')" variant="outline">
                    <ArrowLeft class="h-4 w-4" />{{ forInternship ? 'Retour aux stages' : 'Retour aux contrats' }}
                </Button>
            </template>
        </PageHeader>
        <ContractForm
            :form="form"
            :employees="employees"
            :contract-types="contractTypes"
            :internship-fields="internshipFields"
            :cancel-href="forInternship ? hrUrl('/administration/internships') : hrUrl('/administration/contracts')"
            :submit-label="forInternship ? 'Enregistrer le stage' : 'Créer le contrat'"
            @submit="submit"
        />
    </div>
</template>
