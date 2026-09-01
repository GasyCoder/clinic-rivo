<script setup>
import { computed, ref } from 'vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import HrEmployeePicker from '../Partials/HrEmployeePicker.vue';
import HrFormActions from '../Partials/HrFormActions.vue';
import HrFormSection from '../Partials/HrFormSection.vue';

const props = defineProps({
    form: Object,
    employees: [Array, Object],
    departments: [Array, Object],
    cancelHref: String,
    submitLabel: String,
});

defineEmits(['submit']);

const initialEmployee = props.employees.find((employee) => employee.uuid === props.form.employee_uuid);
const useEmployeeDepartment = ref(
    !props.form.department_uuid || props.form.department_uuid === initialEmployee?.department_uuid,
);
const selectedEmployee = computed(() => props.employees.find((employee) => employee.uuid === props.form.employee_uuid));
const selectedDepartment = computed(() => {
    if (useEmployeeDepartment.value) {
        return selectedEmployee.value?.department || 'Aucun département dans le dossier';
    }

    return props.departments.find((department) => department.uuid === props.form.department_uuid)?.label || 'Département à sélectionner';
});
const periodLabel = computed(() => {
    if (!props.form.starts_at || !props.form.ends_at) return 'Période à compléter';

    const formatter = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' });
    return `${formatter.format(new Date(props.form.starts_at))} → ${formatter.format(new Date(props.form.ends_at))}`;
});
const chooseEmployeeDepartment = () => {
    useEmployeeDepartment.value = true;
    props.form.department_uuid = '';
    props.form.clearErrors('department_uuid');
};
const chooseAnotherDepartment = () => {
    useEmployeeDepartment.value = false;
};
const fieldClass = 'block h-11 w-full rounded-xl border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-sky-950';
const areaClass = 'block min-h-28 w-full rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
</script>

<template>
    <form class="space-y-4" @submit.prevent="$emit('submit')">
        <ValidationErrorSummary :errors="form.errors" />

        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
            <main class="space-y-4">
                <HrFormSection number="1" title="Employé et affectation" description="Le département du dossier est proposé automatiquement. Changez-le uniquement pour ce créneau si nécessaire." tone="sky">
                    <div class="space-y-5">
                        <HrEmployeePicker id="planning_employee" v-model="form.employee_uuid" :employees="employees" label="Employé planifié" required :error="form.errors.employee_uuid" />

                        <fieldset>
                            <legend class="mb-2 text-sm font-medium text-slate-700 dark:text-white">Département appliqué</legend>
                            <div class="grid gap-3 md:grid-cols-2">
                                <button type="button" :class="['rounded-xl border p-4 text-start transition', useEmployeeDepartment ? 'border-sky-400 bg-sky-50 ring-2 ring-sky-100 dark:bg-sky-950/30 dark:ring-sky-950' : 'border-gray-200 hover:border-sky-300 dark:border-gray-800']" @click="chooseEmployeeDepartment">
                                    <span class="flex items-start gap-3"><span :class="['mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', useEmployeeDepartment ? 'bg-sky-600 text-white' : 'bg-gray-100 text-slate-400 dark:bg-gray-900']"><Icon name="refresh" /></span><span><strong class="block text-sm text-slate-800 dark:text-white">Département du dossier</strong><small class="mt-1 block leading-5 text-slate-500">{{ selectedEmployee?.department || 'Sélectionnez d’abord un employé' }}</small></span></span>
                                </button>
                                <button type="button" :class="['rounded-xl border p-4 text-start transition', !useEmployeeDepartment ? 'border-sky-400 bg-sky-50 ring-2 ring-sky-100 dark:bg-sky-950/30 dark:ring-sky-950' : 'border-gray-200 hover:border-sky-300 dark:border-gray-800']" @click="chooseAnotherDepartment">
                                    <span class="flex items-start gap-3"><span :class="['mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', !useEmployeeDepartment ? 'bg-sky-600 text-white' : 'bg-gray-100 text-slate-400 dark:bg-gray-900']"><Icon name="edit" /></span><span><strong class="block text-sm text-slate-800 dark:text-white">Affectation différente</strong><small class="mt-1 block leading-5 text-slate-500">Valable uniquement pour ce créneau.</small></span></span>
                                </button>
                            </div>
                        </fieldset>

                        <div v-if="!useEmployeeDepartment">
                            <label for="planning_department" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Autre département <span class="text-red-500">*</span></label>
                            <select id="planning_department" v-model="form.department_uuid" :class="fieldClass" required>
                                <option value="">Sélectionner un département</option>
                                <option v-for="department in departments" :key="department.uuid" :value="department.uuid">{{ department.label }}</option>
                            </select>
                            <FormError v-if="form.errors.department_uuid">{{ form.errors.department_uuid }}</FormError>
                        </div>

                        <div v-else class="flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800 dark:border-sky-900 dark:bg-sky-950/20 dark:text-sky-300">
                            <Icon class="mt-0.5 shrink-0" name="info" />
                            <p><strong>{{ selectedDepartment }}.</strong> À l’enregistrement, le serveur reprend automatiquement l’affectation présente dans le dossier de l’employé.</p>
                        </div>
                    </div>
                </HrFormSection>

                <HrFormSection number="2" title="Objet et période" description="Définissez le créneau réel. La fin doit être postérieure au début." tone="sky">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="lg:col-span-2"><label for="planning_title" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Objet du créneau</label><input id="planning_title" v-model="form.title" :class="fieldClass" maxlength="255" placeholder="Ex. Garde, permanence, consultation"><FormError v-if="form.errors.title">{{ form.errors.title }}</FormError></div>
                        <div><label for="planning_starts_at" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Début <span class="text-red-500">*</span></label><input id="planning_starts_at" v-model="form.starts_at" type="datetime-local" :class="fieldClass" required><FormError v-if="form.errors.starts_at">{{ form.errors.starts_at }}</FormError></div>
                        <div><label for="planning_ends_at" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Fin <span class="text-red-500">*</span></label><input id="planning_ends_at" v-model="form.ends_at" type="datetime-local" :class="fieldClass" required><FormError v-if="form.errors.ends_at">{{ form.errors.ends_at }}</FormError></div>
                    </div>
                </HrFormSection>

                <HrFormSection title="Observation" description="Ajoutez uniquement une précision utile à l’organisation du service." icon="edit" tone="slate" optional>
                    <label for="planning_observation" class="sr-only">Observation</label>
                    <textarea id="planning_observation" v-model="form.observation" :class="areaClass" maxlength="5000" placeholder="Consignes ou information complémentaire" />
                    <FormError v-if="form.errors.observation">{{ form.errors.observation }}</FormError>
                </HrFormSection>
            </main>

            <aside class="space-y-4 xl:sticky xl:top-4">
                <section class="overflow-hidden rounded-2xl border border-sky-200 bg-sky-50 shadow-sm dark:border-sky-900 dark:bg-sky-950/20">
                    <div class="border-b border-sky-200 px-5 py-4 dark:border-sky-900"><p class="text-[11px] font-bold uppercase tracking-wide text-sky-700 dark:text-sky-300">Aperçu du créneau</p><h2 class="mt-1 text-base font-bold text-slate-800 dark:text-white">{{ selectedEmployee?.name || 'Employé à sélectionner' }}</h2><p class="mt-1 text-xs text-slate-500">{{ selectedEmployee?.employee_number || 'Matricule à venir' }}</p></div>
                    <dl class="divide-y divide-sky-100 px-5 text-sm dark:divide-sky-900/70"><div class="py-3"><dt class="text-xs text-slate-500">Affectation</dt><dd class="mt-1 font-bold text-slate-800 dark:text-white">{{ selectedDepartment }}</dd><dd class="text-xs text-slate-500">{{ useEmployeeDepartment ? 'Reprise du dossier employé' : 'Exception pour ce créneau' }}</dd></div><div class="py-3"><dt class="text-xs text-slate-500">Objet</dt><dd class="mt-1 font-bold text-slate-800 dark:text-white">{{ form.title || 'Non précisé' }}</dd></div><div class="py-3"><dt class="text-xs text-slate-500">Période</dt><dd class="mt-1 font-bold leading-5 text-slate-800 dark:text-white">{{ periodLabel }}</dd></div></dl>
                </section>

                <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-900 dark:bg-gray-950">
                    <div class="flex gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-600 dark:bg-amber-950"><Icon name="info" /></span><div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Planning factuel</h2><p class="mt-1 text-xs leading-5 text-slate-500">Le créneau organise le service. Il ne crée ni absence, ni retard, ni heure supplémentaire et aucun chevauchement n’est bloqué automatiquement.</p></div></div>
                </section>
            </aside>
        </div>

        <HrFormActions :cancel-href="cancelHref" :submit-label="submitLabel" :processing="form.processing" submit-icon="calender-date" />
    </form>
</template>
