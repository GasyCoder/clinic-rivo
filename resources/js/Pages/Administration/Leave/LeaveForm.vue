<script setup>
import { computed, watch } from 'vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import HrEmployeePicker from '../Partials/HrEmployeePicker.vue';
import HrFormActions from '../Partials/HrFormActions.vue';
import HrFormSection from '../Partials/HrFormSection.vue';

const props = defineProps({ form: Object, employees: [Array, Object], cancelHref: String, submitLabel: String });
defineEmits(['submit']);

const requester = computed(() => props.employees.find((employee) => employee.uuid === props.form.employee_uuid));
const interim = computed(() => props.employees.find((employee) => employee.uuid === props.form.interim_employee_uuid));
watch(() => props.form.employee_uuid, (uuid) => {
    if (props.form.interim_employee_uuid === uuid) props.form.interim_employee_uuid = '';
});
const periodLabel = computed(() => props.form.starts_on && props.form.returns_on ? `${props.form.starts_on} → ${props.form.returns_on}` : 'Période à compléter');
const fieldClass = 'block h-11 w-full rounded-xl border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-amber-950';
const areaClass = 'block min-h-32 w-full rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
</script>

<template>
    <form class="space-y-4" @submit.prevent="$emit('submit')">
        <ValidationErrorSummary :errors="form.errors" />
        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
            <main class="space-y-4">
                <HrFormSection number="1" title="Demandeur et continuité" description="Choisissez le demandeur puis, si nécessaire, une autre personne comme intérimaire." tone="amber">
                    <div class="grid gap-5 lg:grid-cols-2">
                        <HrEmployeePicker id="leave_employee" v-model="form.employee_uuid" :employees="employees" label="Demandeur" required :error="form.errors.employee_uuid" />
                        <HrEmployeePicker id="leave_interim" v-model="form.interim_employee_uuid" :employees="employees" label="Intérimaire" placeholder="Aucun intérimaire" :exclude-uuid="form.employee_uuid" :error="form.errors.interim_employee_uuid" />
                    </div>
                </HrFormSection>

                <HrFormSection number="2" title="Période demandée" description="Les dates et compteurs sont conservés comme valeurs déclarées ; aucun droit n’est calculé automatiquement." tone="amber">
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <div><label for="leave_requested_on" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Date de demande <span class="text-red-500">*</span></label><input id="leave_requested_on" v-model="form.requested_on" type="date" :class="fieldClass" required><FormError v-if="form.errors.requested_on">{{ form.errors.requested_on }}</FormError></div>
                        <div><label for="leave_starts_on" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Départ <span class="text-red-500">*</span></label><input id="leave_starts_on" v-model="form.starts_on" type="date" :class="fieldClass" required><FormError v-if="form.errors.starts_on">{{ form.errors.starts_on }}</FormError></div>
                        <div><label for="leave_returns_on" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Retour <span class="text-red-500">*</span></label><input id="leave_returns_on" v-model="form.returns_on" type="date" :class="fieldClass" required><FormError v-if="form.errors.returns_on">{{ form.errors.returns_on }}</FormError></div>
                        <div><label for="leave_days_requested" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Jours demandés</label><input id="leave_days_requested" v-model="form.days_requested" type="number" min="0.01" step="0.01" :class="fieldClass" placeholder="Valeur déclarée"><FormError v-if="form.errors.days_requested">{{ form.errors.days_requested }}</FormError></div>
                        <div><label for="leave_remaining" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Solde constaté</label><input id="leave_remaining" v-model="form.remaining_days_snapshot" type="number" min="0" step="0.01" :class="fieldClass" placeholder="Valeur connue"><FormError v-if="form.errors.remaining_days_snapshot">{{ form.errors.remaining_days_snapshot }}</FormError></div>
                        <div class="flex items-center rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-300"><Icon class="me-2 shrink-0" name="info" />Les week-ends et jours fériés ne sont pas déduits automatiquement.</div>
                    </div>
                </HrFormSection>

                <HrFormSection number="3" title="Motif de la demande" description="Le motif accompagne la décision RH et reste visible dans l’historique." tone="amber">
                    <label for="leave_reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif <span class="text-red-500">*</span></label><textarea id="leave_reason" v-model="form.reason" :class="areaClass" required placeholder="Décrire clairement la demande" /><FormError v-if="form.errors.reason">{{ form.errors.reason }}</FormError>
                </HrFormSection>

                <HrFormSection title="Coordonnées pendant le congé" description="À renseigner seulement si elles sont utiles à la continuité de service." icon="phone" tone="slate" optional>
                    <div class="grid gap-4 sm:grid-cols-2"><div><label for="leave_address" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Adresse pendant le congé</label><input id="leave_address" v-model="form.leave_address" :class="fieldClass"><FormError v-if="form.errors.leave_address">{{ form.errors.leave_address }}</FormError></div><div><label for="leave_emergency_phone" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Téléphone d’urgence</label><input id="leave_emergency_phone" v-model="form.emergency_phone" :class="fieldClass"><FormError v-if="form.errors.emergency_phone">{{ form.errors.emergency_phone }}</FormError></div></div>
                </HrFormSection>
            </main>

            <aside class="space-y-4 xl:sticky xl:top-4">
                <section class="overflow-hidden rounded-2xl border border-amber-200 bg-amber-50 shadow-sm dark:border-amber-900 dark:bg-amber-950/20"><div class="border-b border-amber-200 px-5 py-4 dark:border-amber-900"><div class="flex items-center justify-between gap-3"><div><p class="text-[11px] font-bold uppercase tracking-wide text-amber-700 dark:text-amber-300">Aperçu de la demande</p><h2 class="mt-1 text-base font-bold text-slate-800 dark:text-white">{{ requester?.name || 'Demandeur à sélectionner' }}</h2></div><span class="rounded-full bg-amber-100 px-2.5 py-1 text-[10px] font-bold uppercase text-amber-700 dark:bg-amber-950 dark:text-amber-300">En attente</span></div></div><dl class="divide-y divide-amber-100 px-5 text-sm dark:divide-amber-900/70"><div class="py-3"><dt class="text-xs text-slate-500">Période</dt><dd class="mt-1 font-bold text-slate-800 dark:text-white">{{ periodLabel }}</dd><dd class="text-xs text-slate-500">{{ form.days_requested ? `${form.days_requested} jour(s) déclaré(s)` : 'Durée non renseignée' }}</dd></div><div class="py-3"><dt class="text-xs text-slate-500">Intérimaire</dt><dd class="mt-1 font-bold text-slate-800 dark:text-white">{{ interim?.name || 'Non renseigné' }}</dd></div><div class="py-3"><dt class="text-xs text-slate-500">Solde constaté</dt><dd class="mt-1 font-bold text-slate-800 dark:text-white">{{ form.remaining_days_snapshot !== '' ? `${form.remaining_days_snapshot} jour(s)` : 'Non renseigné' }}</dd></div></dl></section>
                <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-900 dark:bg-gray-950"><div class="flex gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950"><Icon name="shield-check" /></span><div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Décision séparée</h2><p class="mt-1 text-xs leading-5 text-slate-500">L’enregistrement crée une demande <strong>En attente</strong>. L’accepter, la refuser ou l’annuler reste une action distincte, autorisée et auditée.</p></div></div></section>
            </aside>
        </div>
        <HrFormActions :cancel-href="cancelHref" :submit-label="submitLabel" :processing="form.processing" submit-icon="calendar" />
    </form>
</template>
