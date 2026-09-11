<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';
import HrNav from '../Partials/HrNav.vue';
import HrPageHeader from '../Partials/HrPageHeader.vue';
import LeaveForm from './LeaveForm.vue';

defineOptions({ layout: AppLayout });
const props = defineProps({ employees: [Array, Object], leaveTypes: [Array, Object], selectedEmployeeUuid: String, defaultRequestedOn: String });
const form = useForm({
    employee_uuid: props.selectedEmployeeUuid ?? '', interim_employee_uuid: '', leave_address: '', emergency_phone: '',
    leave_type_uuid: '', reason: '', starts_on: '', returns_on: '', justification: null,
});
const submit = () => form.post('/administration/leave', { forceFormData: true });
</script>

<template>
    <Head title="Nouvelle demande de congé" />
    <div class="space-y-5">
        <HrNav />
        <HrPageHeader eyebrow="Congés · Calcul guidé" title="Créer une demande de congé" description="Choisissez le type et la période : la date de demande, la durée et les soldes sont calculés automatiquement selon les règles RH configurées." icon="calendar" tone="amber">
            <template #actions><Link href="/administration/leave" class="inline-flex h-10 items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 text-sm font-bold text-slate-600 hover:border-amber-300 hover:text-amber-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200"><Icon name="arrow-left" /> Retour aux congés</Link></template>
        </HrPageHeader>
        <LeaveForm :form="form" :employees="employees" :leave-types="leaveTypes" :default-requested-on="defaultRequestedOn" cancel-href="/administration/leave" submit-label="Enregistrer la demande" @submit="submit" />
    </div>
</template>
