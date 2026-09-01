<script setup>
import { computed } from 'vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import HrEmployeePicker from '../Partials/HrEmployeePicker.vue';
import HrFormActions from '../Partials/HrFormActions.vue';
import HrFormSection from '../Partials/HrFormSection.vue';

const props = defineProps({ form: Object, employees: [Array, Object], contractTypes: [Array, Object], cancelHref: String, submitLabel: String });
defineEmits(['submit']);

const selectedEmployee = computed(() => props.employees.find((employee) => employee.uuid === props.form.employee_uuid));
const selectedType = computed(() => props.contractTypes.find((type) => type.uuid === props.form.contract_type_uuid));
const calendarStatus = computed(() => {
    if (!props.form.starts_on) return 'Date de début à compléter';
    return props.form.ends_on ? `${props.form.starts_on} → ${props.form.ends_on}` : `À partir du ${props.form.starts_on} · sans date de fin renseignée`;
});

const fieldClass = 'block h-10 w-full rounded-xl border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-sky-950';
const areaClass = 'block min-h-28 w-full rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
</script>

<template>
    <form class="space-y-4" @submit.prevent="$emit('submit')">
        <ValidationErrorSummary :errors="form.errors" />
        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
            <main class="space-y-4">
                <HrFormSection number="1" title="Choisir le collaborateur" description="Le contrat est relié au dossier Employé par UUID ; aucune identité n’est recopiée." tone="sky">
                    <HrEmployeePicker id="contract_employee" v-model="form.employee_uuid" :employees="employees" required :error="form.errors.employee_uuid" />
                </HrFormSection>

                <HrFormSection number="2" title="Définir la nature du contrat" description="Utilisez le référentiel RH afin de conserver un type homogène dans les rapports." tone="sky">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label for="contract_type" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Type de contrat <span class="text-red-500">*</span></label><select id="contract_type" v-model="form.contract_type_uuid" :class="fieldClass" required><option value="">Sélectionner un type</option><option v-for="item in contractTypes" :key="item.uuid" :value="item.uuid">{{ item.label }}</option></select><FormError v-if="form.errors.contract_type_uuid">{{ form.errors.contract_type_uuid }}</FormError></div>
                        <div><label for="contract_reference" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Référence interne</label><Input id="contract_reference" v-model="form.reference_number" placeholder="Ex. CTR-2026-014" /><p class="mt-1.5 text-xs text-slate-400">Facultative, mais unique lorsqu’elle est renseignée.</p><FormError v-if="form.errors.reference_number">{{ form.errors.reference_number }}</FormError></div>
                    </div>
                </HrFormSection>

                <HrFormSection number="3" title="Renseigner le calendrier" description="La période est enregistrée telle qu’elle est déclarée ; aucun renouvellement n’est généré automatiquement." tone="sky">
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <div><label for="contract_signed" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Signature</label><input id="contract_signed" v-model="form.signed_on" type="date" :class="fieldClass"><FormError v-if="form.errors.signed_on">{{ form.errors.signed_on }}</FormError></div>
                        <div><label for="contract_starts" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Début <span class="text-red-500">*</span></label><input id="contract_starts" v-model="form.starts_on" type="date" :class="fieldClass" required><FormError v-if="form.errors.starts_on">{{ form.errors.starts_on }}</FormError></div>
                        <div><label for="contract_trial" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Fin d’essai</label><input id="contract_trial" v-model="form.trial_ends_on" type="date" :class="fieldClass"><FormError v-if="form.errors.trial_ends_on">{{ form.errors.trial_ends_on }}</FormError></div>
                        <div><label for="contract_ends" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Fin du contrat</label><input id="contract_ends" v-model="form.ends_on" type="date" :class="fieldClass"><FormError v-if="form.errors.ends_on">{{ form.errors.ends_on }}</FormError></div>
                    </div>
                </HrFormSection>

                <HrFormSection title="Note administrative" description="Ajoutez uniquement une information utile au suivi de ce contrat." icon="edit" tone="slate" optional>
                    <label for="contract_observation" class="sr-only">Observation</label><textarea id="contract_observation" v-model="form.observation" :class="areaClass" placeholder="Observation facultative sur le contrat" /><FormError v-if="form.errors.observation">{{ form.errors.observation }}</FormError>
                </HrFormSection>
            </main>

            <aside class="space-y-4 xl:sticky xl:top-4">
                <section class="overflow-hidden rounded-2xl border border-sky-200 bg-sky-50 shadow-sm dark:border-sky-900 dark:bg-sky-950/20"><div class="border-b border-sky-200 px-5 py-4 dark:border-sky-900"><p class="text-[11px] font-bold uppercase tracking-wide text-sky-700 dark:text-sky-300">Aperçu du contrat</p><h2 class="mt-1 text-base font-bold text-sky-950 dark:text-sky-100">Vérification avant validation</h2></div><dl class="divide-y divide-sky-100 px-5 text-sm dark:divide-sky-900/70"><div class="py-3"><dt class="text-xs text-sky-700/70 dark:text-sky-300/70">Collaborateur</dt><dd class="mt-1 font-bold text-slate-800 dark:text-white">{{ selectedEmployee?.name || 'À sélectionner' }}</dd><dd v-if="selectedEmployee" class="text-xs text-slate-500">{{ selectedEmployee.employee_number }} · {{ selectedEmployee.job_title || 'Fonction non renseignée' }}</dd></div><div class="py-3"><dt class="text-xs text-sky-700/70 dark:text-sky-300/70">Nature</dt><dd class="mt-1 font-bold text-slate-800 dark:text-white">{{ selectedType?.label || 'À sélectionner' }}</dd><dd class="text-xs text-slate-500">{{ form.reference_number || 'Sans référence interne' }}</dd></div><div class="py-3"><dt class="text-xs text-sky-700/70 dark:text-sky-300/70">Période</dt><dd class="mt-1 text-sm font-semibold text-slate-800 dark:text-white">{{ calendarStatus }}</dd></div></dl></section>
                <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-900 dark:bg-gray-950"><div class="flex gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-600 dark:bg-amber-950"><Icon name="alert-circle" /></span><div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Aucune paie automatique</h2><p class="mt-1 text-xs leading-5 text-slate-500">Ce formulaire conserve uniquement type, référence, dates et observation. Il ne calcule ni salaire, CNAPS, IRSA ou renouvellement.</p></div></div></section>
            </aside>
        </div>
        <HrFormActions :cancel-href="cancelHref" :submit-label="submitLabel" :processing="form.processing" />
    </form>
</template>
