<script setup>
import { computed } from 'vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Select from '@/Components/Shadcn/Select.vue';
import EmployeePhoto from '@/Components/Administration/EmployeePhoto.vue';

/*
 * Choisir un employé : matricule, nom et fonction dans la liste, puis la
 * personne choisie se relit en carte — on voit qui on a sélectionné avant
 * d'enregistrer, au lieu d'un matricule seul dans un champ.
 */
const props = defineProps({
    id: { type: String, required: true },
    modelValue: String,
    employees: { type: [Array, Object], required: true },
    label: { type: String, default: 'Employé' },
    placeholder: { type: String, default: 'Sélectionner un employé' },
    required: Boolean,
    error: String,
    excludeUuid: String,
});
const emit = defineEmits(['update:modelValue']);

const options = computed(() => props.employees
    .filter((employee) => employee.uuid !== props.excludeUuid)
    .map((employee) => ({
        value: employee.uuid,
        label: `${employee.name}${employee.job_title ? ` · ${employee.job_title}` : ''} (${employee.employee_number})`,
    })));
const selected = computed(() => props.employees.find((employee) => employee.uuid === props.modelValue));
</script>

<template>
    <FormField :label="label" as="div" :required="required" :error="error">
        <Select
            :id="id"
            :model-value="modelValue ?? ''"
            :options="options"
            :placeholder="placeholder"
            class="w-full"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <div v-if="selected" class="mt-3 flex items-center gap-3 rounded-xl border border-primary/20 bg-primary/5 p-3">
            <EmployeePhoto :src="selected.photo_url" :name="selected.name" size="sm" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-foreground">{{ selected.name }}</p>
                <p class="mt-0.5 truncate text-xs text-muted-foreground">
                    {{ selected.employee_number }}<span v-if="selected.job_title"> · {{ selected.job_title }}</span><span v-if="selected.department"> · {{ selected.department }}</span>
                </p>
            </div>
        </div>
    </FormField>
</template>
