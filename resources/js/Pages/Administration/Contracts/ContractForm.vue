<script setup>
import { Link } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';

defineProps({ form: Object, employees: [Array, Object], contractTypes: [Array, Object], cancelHref: String, submitLabel: String });
defineEmits(['submit']);
const fieldClass = 'block h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
</script>

<template>
    <form class="space-y-5" @submit.prevent="$emit('submit')">
        <ValidationErrorSummary :errors="form.errors" />
        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <header class="border-b border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/40"><div class="flex items-center gap-3"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300"><Icon name="file-docs" /></span><div><h2 class="text-sm font-bold text-slate-800 dark:text-white">Informations du contrat</h2><p class="mt-0.5 text-xs text-slate-500">Le contrat reste rattaché au dossier Employé par identifiant UUID.</p></div></div></header>
            <div class="grid gap-4 p-5 sm:grid-cols-2">
                <div class="sm:col-span-2"><label for="contract_employee" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Employé <span class="text-red-500">*</span></label><select id="contract_employee" v-model="form.employee_uuid" :class="fieldClass" required><option value="">Sélectionner un employé</option><option v-for="employee in employees" :key="employee.uuid" :value="employee.uuid">{{ employee.employee_number }} · {{ employee.name }}{{ employee.job_title ? ` · ${employee.job_title}` : '' }}</option></select><FormError v-if="form.errors.employee_uuid">{{ form.errors.employee_uuid }}</FormError></div>
                <div><label for="contract_type" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Type de contrat <span class="text-red-500">*</span></label><select id="contract_type" v-model="form.contract_type_uuid" :class="fieldClass" required><option value="">Sélectionner</option><option v-for="item in contractTypes" :key="item.uuid" :value="item.uuid">{{ item.label }}</option></select><FormError v-if="form.errors.contract_type_uuid">{{ form.errors.contract_type_uuid }}</FormError></div>
                <div><label for="contract_reference" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Référence</label><Input id="contract_reference" v-model="form.reference_number" /><FormError v-if="form.errors.reference_number">{{ form.errors.reference_number }}</FormError></div>
                <div><label for="contract_signed" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Date de signature</label><input id="contract_signed" v-model="form.signed_on" type="date" :class="fieldClass"><FormError v-if="form.errors.signed_on">{{ form.errors.signed_on }}</FormError></div>
                <div><label for="contract_starts" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Date de début <span class="text-red-500">*</span></label><input id="contract_starts" v-model="form.starts_on" type="date" :class="fieldClass" required><FormError v-if="form.errors.starts_on">{{ form.errors.starts_on }}</FormError></div>
                <div><label for="contract_trial" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Fin de période d’essai</label><input id="contract_trial" v-model="form.trial_ends_on" type="date" :class="fieldClass"><FormError v-if="form.errors.trial_ends_on">{{ form.errors.trial_ends_on }}</FormError></div>
                <div><label for="contract_ends" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Date de fin</label><input id="contract_ends" v-model="form.ends_on" type="date" :class="fieldClass"><FormError v-if="form.errors.ends_on">{{ form.errors.ends_on }}</FormError></div>
                <div class="sm:col-span-2"><label for="contract_observation" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Observation</label><textarea id="contract_observation" v-model="form.observation" class="min-h-28 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" /><FormError v-if="form.errors.observation">{{ form.errors.observation }}</FormError></div>
            </div>
        </section>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><Button :as="Link" :href="cancelHref" size="rg" variant="white-outline">Annuler</Button><Button size="rg" :disabled="form.processing"><Icon name="check" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : submitLabel }}</span></Button></div>
    </form>
</template>
