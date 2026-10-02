<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { ArrowLeft, CalendarPlus } from 'lucide-vue-next';
import LeaveForm from './LeaveForm.vue';

defineOptions({ layout: AppLayout });
const props = defineProps({ employees: [Array, Object], leaveTypes: [Array, Object], selectedEmployeeUuid: String, defaultRequestedOn: String });
const form = useForm({
    employee_uuid: props.selectedEmployeeUuid ?? '', interim_employee_uuid: '', leave_address: '', emergency_phone: '',
    leave_type_uuid: '', reason: '', starts_on: '', returns_on: '', justification: null,
});
const submit = () => form.post(hrUrl('/administration/leave'), { forceFormData: true });
</script>

<template>
    <Head title="Nouvelle demande de congé" />
    <div class="space-y-5">
        <PageHeader eyebrow="Congés · Calcul guidé" title="Créer une demande de congé" description="Choisissez le type et la période : la date de demande, la durée et les soldes sont calculés selon les règles RH du site." :icon="CalendarPlus" tone="amber">
            <template #actions><Button :as="Link" :href="hrUrl('/administration/leave')" variant="outline"><ArrowLeft class="h-4 w-4" />Retour aux congés</Button></template>
        </PageHeader>
        <LeaveForm :form="form" :employees="employees" :leave-types="leaveTypes" :default-requested-on="defaultRequestedOn" :cancel-href="hrUrl('/administration/leave')" submit-label="Enregistrer la demande" @submit="submit" />
    </div>
</template>
