<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Pencil } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/Shadcn/Button.vue';
import PlanningForm from './PlanningForm.vue';

defineOptions({ layout: AppLayout });
const props = defineProps({
    shift: Object,
    employees: [Array, Object],
    departments: [Array, Object],
    kinds: { type: [Array, Object], default: () => [] },
});

// L'heure de la clinique, telle que le serveur l'a enregistrée (sans décalage).
const local = (value) => (value ? String(value).slice(0, 16) : '');
const form = useForm({
    employee_uuid: props.shift.employee.uuid,
    department_uuid: props.shift.department_uuid ?? '',
    kind: props.shift.kind ?? 'SHIFT',
    title: props.shift.title ?? '',
    starts_at: local(props.shift.starts_at),
    ends_at: local(props.shift.ends_at),
    observation: props.shift.observation ?? '',
});
const onCall = computed(() => form.kind === 'ON_CALL');
const back = hrUrl(`/administration/planning?kind=${props.shift.kind ?? 'SHIFT'}&date=${String(props.shift.starts_at).slice(0, 10)}`);
const submit = () => form.put(hrUrl(`/administration/planning/${props.shift.uuid}`));
</script>

<template>
    <Head :title="onCall ? 'Modifier la garde' : 'Modifier le créneau'" />
    <div class="w-full space-y-5">
        <PageHeader
            :eyebrow="`${shift.employee.employee_number} · Correction auditée`"
            :title="onCall ? 'Modifier la garde' : 'Modifier le créneau'"
            :description="`${shift.employee.name}. Le changement ne déduit aucune absence ni heure supplémentaire.`"
            :icon="Pencil"
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
            :cancel-href="back"
            submit-label="Enregistrer"
            @submit="submit"
        />
    </div>
</template>
