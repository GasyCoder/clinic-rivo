<script setup>
import { computed } from 'vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';

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

const availableEmployees = computed(() => props.employees.filter((employee) => employee.uuid !== props.excludeUuid));
const selected = computed(() => props.employees.find((employee) => employee.uuid === props.modelValue));
const fieldClass = 'block h-11 w-full rounded-xl border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950';
</script>

<template>
    <div>
        <label :for="id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">{{ label }} <span v-if="required" class="text-red-500">*</span></label>
        <select :id="id" :value="modelValue" :required="required" :class="fieldClass" :aria-invalid="Boolean(error)" @change="emit('update:modelValue', $event.target.value)">
            <option value="">{{ placeholder }}</option>
            <option v-for="employee in availableEmployees" :key="employee.uuid" :value="employee.uuid">{{ employee.employee_number }} · {{ employee.name }}{{ employee.job_title ? ` · ${employee.job_title}` : '' }}</option>
        </select>
        <FormError v-if="error">{{ error }}</FormError>
        <div v-if="selected" class="mt-3 flex items-center gap-3 rounded-xl border border-primary-100 bg-primary-50/60 p-3 dark:border-primary-900 dark:bg-primary-950/20">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-600 text-white"><Icon name="user" /></span>
            <div class="min-w-0"><p class="truncate text-sm font-bold text-slate-800 dark:text-white">{{ selected.name }}</p><p class="mt-0.5 truncate text-xs text-slate-500">{{ selected.employee_number }}<span v-if="selected.job_title"> · {{ selected.job_title }}</span><span v-if="selected.department"> · {{ selected.department }}</span></p></div>
        </div>
    </div>
</template>
