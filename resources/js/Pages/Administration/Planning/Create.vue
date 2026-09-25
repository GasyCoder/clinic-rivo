<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CalendarPlus, ShieldPlus } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/Shadcn/Button.vue';
import PlanningForm from './PlanningForm.vue';

defineOptions({ layout: AppLayout });
const props = defineProps({
    employees: [Array, Object],
    departments: [Array, Object],
    kinds: { type: [Array, Object], default: () => [] },
    selectedEmployeeUuid: String,
    /** ADR-194 — ouvert depuis une case du calendrier : le jour et le type. */
    preset: { type: Object, default: () => ({ date: null, kind: 'SHIFT' }) },
});

const form = useForm({
    employee_uuid: props.selectedEmployeeUuid ?? '', department_uuid: '', kind: props.preset?.kind ?? 'SHIFT',
    title: '', starts_at: '', ends_at: '', observation: '',
});
const onCall = computed(() => form.kind === 'ON_CALL');
const back = computed(() => hrUrl(`/administration/planning?kind=${form.kind}${props.preset?.date ? `&date=${props.preset.date}` : ''}`));
const submit = () => form.post(hrUrl('/administration/planning'));
</script>

<template>
    <Head :title="onCall ? 'Nouvelle garde' : 'Nouveau créneau'" />
    <div class="w-full space-y-5">
        <PageHeader
            :eyebrow="onCall ? 'Planning de garde' : 'Planning du personnel'"
            :title="onCall ? 'Planifier une garde' : 'Ajouter un créneau'"
            description="Choisissez le planning, la personne, puis le jour et les heures."
            :icon="onCall ? ShieldPlus : CalendarPlus"
            :tone="onCall ? 'violet' : 'sky'"
        >
            <template #actions>
                <Button :as="Link" :href="back" variant="outline"><ArrowLeft class="h-4 w-4" />Retour au planning</Button>
            </template>
        </PageHeader>
        <PlanningForm
            :form="form"
            :employees="employees"
            :departments="departments"
            :kinds="kinds"
            :preset-date="preset?.date"
            :cancel-href="back"
            :submit-label="onCall ? 'Planifier la garde' : 'Ajouter au planning'"
            @submit="submit"
        />
    </div>
</template>
