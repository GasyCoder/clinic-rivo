<script setup>
import { computed } from 'vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import HrEmployeePicker from '../Partials/HrEmployeePicker.vue';
import HrFormActions from '../Partials/HrFormActions.vue';
import HrFormSection from '../Partials/HrFormSection.vue';

const props = defineProps({ form: Object, employees: [Array, Object], cancelHref: String, submitLabel: String });
defineEmits(['submit']);

const selectedEmployee = computed(() => props.employees.find((employee) => employee.uuid === props.form.employee_uuid));
const workDate = computed(() => props.form.started_at?.slice(0, 10) || 'Déduite après la saisie de l’entrée');
const duration = computed(() => {
    if (!props.form.started_at || !props.form.ended_at) return null;
    const minutes = Math.round((new Date(props.form.ended_at) - new Date(props.form.started_at)) / 60000);
    if (minutes <= 0) return null;
    return `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')}`;
});
const nowLocal = () => {
    const now = new Date();
    return new Date(now.getTime() - (now.getTimezoneOffset() * 60000)).toISOString().slice(0, 16);
};
const setNow = (field) => { props.form[field] = nowLocal(); props.form.clearErrors(field); };
const fieldClass = 'block h-11 w-full rounded-xl border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-violet-950';
const areaClass = 'block min-h-28 w-full rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
</script>

<template>
    <form class="space-y-4" @submit.prevent="$emit('submit')">
        <ValidationErrorSummary :errors="form.errors" />
        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
            <main class="space-y-4">
                <HrFormSection number="1" title="Identifier le collaborateur" description="Une seule session est créée pour l’employé choisi." tone="violet">
                    <HrEmployeePicker id="attendance_employee" v-model="form.employee_uuid" :employees="employees" required :error="form.errors.employee_uuid" />
                </HrFormSection>

                <HrFormSection number="2" title="Constater la session" description="La sortie est facultative : laissez-la vide si la présence est toujours en cours." tone="violet">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><div class="mb-1.5 flex items-center justify-between gap-2"><label for="attendance_started_at" class="block text-sm font-medium text-slate-700 dark:text-white">Heure d’entrée <span class="text-red-500">*</span></label><button type="button" class="text-xs font-bold text-violet-600 hover:text-violet-700" @click="setNow('started_at')">Mettre maintenant</button></div><input id="attendance_started_at" v-model="form.started_at" type="datetime-local" :class="fieldClass" required><FormError v-if="form.errors.started_at">{{ form.errors.started_at }}</FormError></div>
                        <div><div class="mb-1.5 flex items-center justify-between gap-2"><label for="attendance_ended_at" class="block text-sm font-medium text-slate-700 dark:text-white">Heure de sortie</label><button type="button" class="text-xs font-bold text-violet-600 hover:text-violet-700" @click="setNow('ended_at')">Clôturer maintenant</button></div><input id="attendance_ended_at" v-model="form.ended_at" type="datetime-local" :class="fieldClass"><FormError v-if="form.errors.ended_at">{{ form.errors.ended_at }}</FormError></div>
                    </div>
                </HrFormSection>

                <HrFormSection title="Observation de pointage" description="Expliquez uniquement une correction ou une particularité utile." icon="edit" tone="slate" optional>
                    <label for="attendance_observation" class="sr-only">Observation</label><textarea id="attendance_observation" v-model="form.observation" :class="areaClass" placeholder="Ex. sortie non saisie le jour même, horaire corrigé selon le registre…" /><FormError v-if="form.errors.observation">{{ form.errors.observation }}</FormError>
                </HrFormSection>
            </main>

            <aside class="space-y-4 xl:sticky xl:top-4">
                <section class="overflow-hidden rounded-2xl border border-violet-200 bg-violet-50 shadow-sm dark:border-violet-900 dark:bg-violet-950/20"><div class="p-5"><div class="flex items-start justify-between gap-3"><div><p class="text-[11px] font-bold uppercase tracking-wide text-violet-700 dark:text-violet-300">Aperçu de la session</p><h2 class="mt-1 text-base font-bold text-slate-800 dark:text-white">{{ selectedEmployee?.name || 'Employé à sélectionner' }}</h2><p v-if="selectedEmployee" class="mt-0.5 text-xs text-slate-500">{{ selectedEmployee.employee_number }} · {{ selectedEmployee.department || 'Sans département' }}</p></div><span :class="['rounded-full px-2.5 py-1 text-[10px] font-bold uppercase', form.ended_at ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300']">{{ form.ended_at ? 'Terminée' : 'Ouverte' }}</span></div><dl class="mt-5 grid grid-cols-2 gap-3"><div class="rounded-xl bg-white/70 p-3 dark:bg-gray-950/40"><dt class="text-[10px] font-bold uppercase text-slate-400">Date de travail</dt><dd class="mt-1 text-sm font-bold text-slate-800 dark:text-white">{{ workDate }}</dd></div><div class="rounded-xl bg-white/70 p-3 dark:bg-gray-950/40"><dt class="text-[10px] font-bold uppercase text-slate-400">Durée constatée</dt><dd class="mt-1 text-sm font-bold text-slate-800 dark:text-white">{{ duration || 'En attente de sortie' }}</dd></div></dl></div></section>
                <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-900 dark:bg-gray-950"><div class="flex gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950"><Icon name="check-circle" /></span><div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Automatisation contrôlée</h2><p class="mt-1 text-xs leading-5 text-slate-500">La date de travail vient de l’heure d’entrée. La durée est affichée seulement lorsque la sortie existe. Aucun retard, absence ou heure supplémentaire n’est déduit.</p></div></div></section>
            </aside>
        </div>
        <HrFormActions :cancel-href="cancelHref" :submit-label="submitLabel" :processing="form.processing"><Button v-if="form.ended_at" type="button" size="rg" variant="white-outline" @click="form.ended_at = ''"><Icon name="undo" /><span class="ms-2">Laisser ouverte</span></Button></HrFormActions>
    </form>
</template>
