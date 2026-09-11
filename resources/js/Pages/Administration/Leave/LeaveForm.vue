<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import HrEmployeePicker from '../Partials/HrEmployeePicker.vue';
import HrFormActions from '../Partials/HrFormActions.vue';
import HrFormSection from '../Partials/HrFormSection.vue';

const props = defineProps({
    form: Object,
    employees: [Array, Object],
    leaveTypes: [Array, Object],
    defaultRequestedOn: String,
    cancelHref: String,
    submitLabel: String,
});

defineEmits(['submit']);

const requester = computed(() => props.employees.find((employee) => employee.uuid === props.form.employee_uuid));
const interim = computed(() => props.employees.find((employee) => employee.uuid === props.form.interim_employee_uuid));
const selectedType = computed(() => props.leaveTypes.find((type) => type.uuid === props.form.leave_type_uuid));
const typeRules = computed(() => selectedType.value?.rules ?? {});
const preview = ref(null);
const previewLoading = ref(false);
const previewError = ref('');
let previewTimer;
let requestSequence = 0;

watch(() => props.form.employee_uuid, (uuid) => {
    if (props.form.interim_employee_uuid === uuid) props.form.interim_employee_uuid = '';
});

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const loadPreview = async () => {
    const required = [props.form.employee_uuid, props.form.leave_type_uuid, props.form.starts_on, props.form.returns_on];
    if (required.some((value) => !value)) {
        preview.value = null;
        previewError.value = '';
        return;
    }

    const sequence = ++requestSequence;
    previewLoading.value = true;
    previewError.value = '';

    try {
        const response = await fetch('/administration/leave/preview', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({
                employee_uuid: props.form.employee_uuid,
                leave_type_uuid: props.form.leave_type_uuid,
                starts_on: props.form.starts_on,
                returns_on: props.form.returns_on,
            }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const message = Object.values(data.errors ?? {}).flat()[0] ?? data.message ?? 'Le calcul n’a pas pu être effectué.';
            throw new Error(message);
        }
        if (sequence === requestSequence) preview.value = data.preview;
    } catch (error) {
        if (sequence === requestSequence) {
            preview.value = null;
            previewError.value = error.message;
        }
    } finally {
        if (sequence === requestSequence) previewLoading.value = false;
    }
};

watch(
    () => [props.form.employee_uuid, props.form.leave_type_uuid, props.form.starts_on, props.form.returns_on],
    () => {
        clearTimeout(previewTimer);
        preview.value = null;
        previewError.value = '';
        previewTimer = setTimeout(loadPreview, 250);
    },
);
onBeforeUnmount(() => clearTimeout(previewTimer));

const periodLabel = computed(() => props.form.starts_on && props.form.returns_on
    ? `${props.form.starts_on} → ${props.form.returns_on}`
    : 'Période à compléter');
const days = (value) => value === null || value === undefined ? '—' : `${Number(value).toLocaleString('fr-FR')} jour(s)`;
const requestedDate = computed(() => props.defaultRequestedOn
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' }).format(new Date(`${props.defaultRequestedOn}T00:00:00`))
    : 'Date du serveur');
const onFile = (event) => { props.form.justification = event.target.files?.[0] ?? null; };
const fieldClass = 'block h-11 w-full rounded-xl border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-amber-950';
const areaClass = 'block min-h-32 w-full rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
</script>

<template>
    <form class="space-y-4" @submit.prevent="$emit('submit')">
        <ValidationErrorSummary :errors="form.errors" />
        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_370px]">
            <main class="space-y-4">
                <HrFormSection number="1" title="Demandeur et type de demande" description="Le type sélectionné détermine le calcul, le quota, le justificatif et le circuit de validation." tone="amber">
                    <div class="grid gap-5 lg:grid-cols-2">
                        <HrEmployeePicker id="leave_employee" v-model="form.employee_uuid" :employees="employees" label="Demandeur" required :error="form.errors.employee_uuid" />
                        <div><label for="leave_type" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Type de demande <span class="text-red-500">*</span></label><select id="leave_type" v-model="form.leave_type_uuid" :class="fieldClass" required><option value="">Sélectionner le type</option><option v-for="type in leaveTypes" :key="type.uuid" :value="type.uuid">{{ type.label }}</option></select><FormError v-if="form.errors.leave_type_uuid">{{ form.errors.leave_type_uuid }}</FormError></div>
                    </div>
                    <div v-if="selectedType" class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4"><div class="rounded-xl bg-gray-50 p-3 text-xs dark:bg-gray-900"><span class="block text-slate-400">Décompte</span><strong class="mt-1 block text-slate-700 dark:text-white">{{ typeRules.day_count_method === 'WEEKDAYS_INCLUSIVE' ? 'Lundi à vendredi' : 'Jours calendaires' }}</strong></div><div class="rounded-xl bg-gray-50 p-3 text-xs dark:bg-gray-900"><span class="block text-slate-400">Solde annuel</span><strong class="mt-1 block text-slate-700 dark:text-white">{{ typeRules.consumes_annual_balance ? `${typeRules.annual_quota_days} jours` : 'Non consommé' }}</strong></div><div class="rounded-xl bg-gray-50 p-3 text-xs dark:bg-gray-900"><span class="block text-slate-400">Validation</span><strong class="mt-1 block text-slate-700 dark:text-white">{{ typeRules.requires_approval ? 'Décision requise' : 'Automatique' }}</strong></div><div class="rounded-xl bg-gray-50 p-3 text-xs dark:bg-gray-900"><span class="block text-slate-400">Justificatif</span><strong class="mt-1 block text-slate-700 dark:text-white">{{ typeRules.requires_attachment ? 'Obligatoire' : 'Facultatif' }}</strong></div></div>
                </HrFormSection>

                <HrFormSection number="2" title="Période et calcul automatique" description="Renseignez uniquement les dates. Le serveur calcule la durée et les soldes depuis l’historique validé." tone="amber">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-gray-900"><span class="text-xs font-medium text-slate-400">Date de demande</span><strong class="mt-1 flex items-center gap-2 text-sm text-slate-700 dark:text-white"><Icon name="lock" />{{ requestedDate }}</strong><p class="mt-1 text-[10px] leading-4 text-slate-400">Fixée par le serveur lors de l’enregistrement.</p></div>
                        <div><label for="leave_starts_on" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Premier jour demandé <span class="text-red-500">*</span></label><input id="leave_starts_on" v-model="form.starts_on" type="date" :class="fieldClass" required><FormError v-if="form.errors.starts_on">{{ form.errors.starts_on }}</FormError></div>
                        <div><label for="leave_returns_on" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Dernier jour demandé <span class="text-red-500">*</span></label><input id="leave_returns_on" v-model="form.returns_on" type="date" :class="fieldClass" required><FormError v-if="form.errors.returns_on">{{ form.errors.returns_on }}</FormError></div>
                    </div>

                    <div v-if="previewLoading" class="mt-4 flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700 dark:border-amber-900 dark:bg-amber-950/20"><Icon class="animate-spin" name="loader" />Calcul des jours et du solde…</div>
                    <div v-else-if="previewError" class="mt-4 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/20"><Icon class="mt-0.5 shrink-0" name="alert-circle" /><p>{{ previewError }}</p></div>
                    <div v-else-if="preview" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/20"><span class="text-[10px] font-bold uppercase tracking-wide text-amber-600">Durée calculée</span><strong class="mt-2 block text-xl text-slate-800 dark:text-white">{{ days(preview.days_requested) }}</strong><small class="mt-1 block leading-4 text-slate-500">{{ preview.day_count_method_label }}</small></div>
                        <template v-if="preview.consumes_annual_balance"><div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Solde avant</span><strong class="mt-2 block text-xl text-slate-800 dark:text-white">{{ days(preview.balance_before) }}</strong><small class="mt-1 block text-slate-500">Après {{ days(preview.approved_days) }} validé(s)</small></div><div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Autres demandes</span><strong class="mt-2 block text-xl text-slate-800 dark:text-white">{{ days(preview.pending_days) }}</strong><small class="mt-1 block text-slate-500">En attente, non déduites définitivement</small></div><div :class="['rounded-xl border p-4', Number(preview.projected_balance) < 0 ? 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/20' : 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/20']"><span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Solde prévisionnel</span><strong :class="['mt-2 block text-xl', Number(preview.projected_balance) < 0 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300']">{{ days(preview.projected_balance) }}</strong><small class="mt-1 block text-slate-500">Si toutes les demandes sont acceptées</small></div></template>
                        <div v-else class="rounded-xl border border-sky-200 bg-sky-50 p-4 dark:border-sky-900 dark:bg-sky-950/20 sm:col-span-2 lg:col-span-3"><span class="text-[10px] font-bold uppercase tracking-wide text-sky-600">Type hors solde annuel</span><strong class="mt-2 block text-sm text-slate-800 dark:text-white">Cette demande ne déduit pas le quota annuel.</strong><p class="mt-1 text-xs leading-5 text-slate-500">La durée reste calculée et enregistrée pour l’historique.</p></div>
                    </div>
                </HrFormSection>

                <HrFormSection number="3" title="Motif et justificatif" description="Le motif accompagne la décision. Le justificatif suit la règle configurée pour le type." tone="amber">
                    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_300px]"><div><label for="leave_reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif <span class="text-red-500">*</span></label><textarea id="leave_reason" v-model="form.reason" :class="areaClass" required placeholder="Décrire clairement la demande" /><FormError v-if="form.errors.reason">{{ form.errors.reason }}</FormError></div><div><label for="leave_justification" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Justificatif <span v-if="typeRules.requires_attachment" class="text-red-500">*</span><span v-else class="text-xs text-slate-400"> · facultatif</span></label><label for="leave_justification" class="flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-gray-50 p-4 text-center hover:border-amber-400 dark:border-gray-800 dark:bg-gray-900"><Icon class="text-2xl text-amber-500" name="upload" /><strong class="mt-2 max-w-full truncate text-xs text-slate-700 dark:text-white">{{ form.justification?.name || 'Choisir un fichier' }}</strong><small class="mt-1 text-[10px] leading-4 text-slate-400">PDF, image ou Word · 10 Mo max.</small></label><input id="leave_justification" type="file" class="sr-only" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" :required="typeRules.requires_attachment" @change="onFile"><FormError v-if="form.errors.justification">{{ form.errors.justification }}</FormError></div></div>
                </HrFormSection>

                <HrFormSection number="4" title="Continuité et contact" description="Indiquez l’intérimaire et les coordonnées utiles uniquement si nécessaire." tone="slate" optional>
                    <div class="grid gap-4 lg:grid-cols-3"><HrEmployeePicker id="leave_interim" v-model="form.interim_employee_uuid" :employees="employees" label="Intérimaire" placeholder="Aucun intérimaire" :exclude-uuid="form.employee_uuid" :error="form.errors.interim_employee_uuid" /><div><label for="leave_address" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Adresse pendant l’absence</label><input id="leave_address" v-model="form.leave_address" :class="fieldClass"><FormError v-if="form.errors.leave_address">{{ form.errors.leave_address }}</FormError></div><div><label for="leave_emergency_phone" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Téléphone d’urgence</label><input id="leave_emergency_phone" v-model="form.emergency_phone" :class="fieldClass"><FormError v-if="form.errors.emergency_phone">{{ form.errors.emergency_phone }}</FormError></div></div>
                </HrFormSection>
            </main>

            <aside class="space-y-4 xl:sticky xl:top-4">
                <section class="overflow-hidden rounded-2xl border border-amber-200 bg-amber-50 shadow-sm dark:border-amber-900 dark:bg-amber-950/20"><div class="border-b border-amber-200 px-5 py-4 dark:border-amber-900"><div class="flex items-center justify-between gap-3"><div><p class="text-[11px] font-bold uppercase tracking-wide text-amber-700 dark:text-amber-300">Aperçu de la demande</p><h2 class="mt-1 text-base font-bold text-slate-800 dark:text-white">{{ requester?.name || 'Demandeur à sélectionner' }}</h2><p class="mt-1 text-xs text-slate-500">{{ selectedType?.label || 'Type à sélectionner' }}</p></div><span class="rounded-full bg-amber-100 px-2.5 py-1 text-[10px] font-bold uppercase text-amber-700 dark:bg-amber-950 dark:text-amber-300">{{ typeRules.requires_approval === false ? 'Auto-validée' : 'En attente' }}</span></div></div><dl class="divide-y divide-amber-100 px-5 text-sm dark:divide-amber-900/70"><div class="py-3"><dt class="text-xs text-slate-500">Période</dt><dd class="mt-1 font-bold text-slate-800 dark:text-white">{{ periodLabel }}</dd><dd class="text-xs text-slate-500">{{ preview ? days(preview.days_requested) : 'Calcul à venir' }}</dd></div><div class="py-3"><dt class="text-xs text-slate-500">Solde prévisionnel</dt><dd class="mt-1 font-bold text-slate-800 dark:text-white">{{ preview?.consumes_annual_balance ? days(preview.projected_balance) : 'Non concerné' }}</dd></div><div class="py-3"><dt class="text-xs text-slate-500">Intérimaire</dt><dd class="mt-1 font-bold text-slate-800 dark:text-white">{{ interim?.name || 'Non renseigné' }}</dd></div></dl></section>
                <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-900 dark:bg-gray-950"><div class="flex gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950"><Icon name="shield-check" /></span><div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Calcul serveur unique</h2><p class="mt-1 text-xs leading-5 text-slate-500">L’aperçu aide à préparer la demande. À l’enregistrement puis à l’approbation, le serveur recalcule les valeurs depuis l’historique pour éviter toute manipulation.</p></div></div></section>
            </aside>
        </div>
        <HrFormActions :cancel-href="cancelHref" :submit-label="submitLabel" :processing="form.processing" submit-icon="calendar" />
    </form>
</template>
