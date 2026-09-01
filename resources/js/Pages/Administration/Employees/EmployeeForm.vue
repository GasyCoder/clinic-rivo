<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import CheckBox from '@/Components/UI/CheckBox.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';

const props = defineProps({
    form: Object,
    options: Object,
    departments: Array,
    jobTitles: Array,
    addresses: Array,
    submitLabel: String,
    cancelHref: String,
});
const emit = defineEmits(['submit']);

const steps = [
    { number: 1, label: 'Identité', hint: 'Qui est la personne ?', icon: 'user' },
    { number: 2, label: 'Poste', hint: 'Où travaille-t-elle ?', icon: 'briefcase' },
    { number: 3, label: 'Contact', hint: 'Comment la joindre ?', icon: 'phone' },
    { number: 4, label: 'Compléments', hint: 'Données facultatives', icon: 'list' },
    { number: 5, label: 'Confirmer', hint: 'Contrôle du dossier', icon: 'check-circle' },
];
const stepFields = {
    1: ['sex', 'last_name', 'first_name', 'birth_date', 'birth_place'],
    2: ['employee_number', 'department_uuid', 'job_title_uuid', 'hire_date'],
    3: ['phone', 'email', 'address_entry_uuid', 'new_address_label', 'identity_document_type', 'identity_document_number', 'identity_document_issued_on', 'identity_document_issued_at'],
    4: ['marital_status', 'children_count', 'children_details', 'diploma', 'education_level', 'badge', 'blouse', 'observation', 'active'],
    5: [],
};
const requiredByStep = { 1: ['sex', 'last_name'], 2: ['employee_number'] };
const requiredLabels = { employee_number: 'Le matricule', last_name: 'Le nom', sex: 'Le genre' };

const currentStep = ref(1);
const maxStepReached = ref(1);
const addressMode = ref(props.form.new_address_label ? 'new' : 'existing');
const currentMeta = computed(() => steps[currentStep.value - 1]);
const progress = computed(() => `${Math.round((currentStep.value / steps.length) * 100)}%`);
const derivedCivility = computed(() => ({ M: 'Monsieur (M.)', F: 'Madame (Mme)' }[props.form.sex] || 'Attribuée après le choix du genre'));
const employeeName = computed(() => [props.form.last_name, props.form.first_name].filter(Boolean).join(' ') || 'Identité à compléter');
const selectedDepartment = computed(() => props.departments.find((item) => item.uuid === props.form.department_uuid)?.label || 'Non affecté');
const selectedJobTitle = computed(() => props.jobTitles.find((item) => item.uuid === props.form.job_title_uuid)?.label || 'Fonction non renseignée');
const selectedAddress = computed(() => {
    if (addressMode.value === 'new') return props.form.new_address_label || 'Non renseignée';
    return props.addresses.find((item) => item.uuid === props.form.address_entry_uuid)?.label || 'Non renseignée';
});
const optionalDetailsCount = computed(() => [
    props.form.phone, props.form.email, props.form.address_entry_uuid, props.form.new_address_label,
    props.form.identity_document_number, props.form.marital_status, props.form.children_count,
    props.form.diploma, props.form.education_level, props.form.badge, props.form.blouse,
].filter((value) => value !== '' && value !== null && value !== undefined).length);

const fieldClass = 'block h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950';
const areaClass = 'block min-h-24 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950';

const chooseSex = (sex) => {
    props.form.sex = sex;
    props.form.clearErrors('sex');
};
const setAddressMode = (mode) => {
    addressMode.value = mode;
    if (mode === 'new') props.form.address_entry_uuid = '';
    else props.form.new_address_label = '';
    props.form.clearErrors('address_entry_uuid', 'new_address_label');
};
const scrollToWizard = () => nextTick(() => document.getElementById('employee-wizard')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
const goToStep = (step) => {
    if (step > maxStepReached.value) return;
    currentStep.value = step;
    scrollToWizard();
};
const validateCurrentStep = () => {
    let valid = true;
    (requiredByStep[currentStep.value] ?? []).forEach((field) => {
        if (String(props.form[field] ?? '').trim() === '') {
            props.form.setError(field, `${requiredLabels[field]} est obligatoire avant de continuer.`);
            valid = false;
        } else if (String(props.form.errors[field] ?? '').includes('avant de continuer')) {
            props.form.clearErrors(field);
        }
    });
    return valid;
};
const nextStep = () => {
    if (!validateCurrentStep()) return;
    currentStep.value = Math.min(steps.length, currentStep.value + 1);
    maxStepReached.value = Math.max(maxStepReached.value, currentStep.value);
    scrollToWizard();
};
const previousStep = () => {
    currentStep.value = Math.max(1, currentStep.value - 1);
    scrollToWizard();
};
const editStep = (step) => {
    maxStepReached.value = Math.max(maxStepReached.value, step);
    currentStep.value = step;
    scrollToWizard();
};
const submit = () => currentStep.value < steps.length ? nextStep() : emit('submit');

watch(() => props.form.identity_document_number, (number) => {
    if (number && !props.form.identity_document_type) props.form.identity_document_type = 'CIN';
});
watch(
    () => props.form.errors,
    (errors) => {
        const firstErrorStep = Object.entries(stepFields).find(([, fields]) => fields.some((field) => errors?.[field]));
        if (!firstErrorStep) return;
        currentStep.value = Number(firstErrorStep[0]);
        maxStepReached.value = Math.max(maxStepReached.value, currentStep.value);
    },
    { deep: true },
);
</script>

<template>
    <form id="employee-wizard" class="scroll-mt-4 space-y-4" @submit.prevent="submit">
        <ValidationErrorSummary :errors="form.errors" />

        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="h-1 bg-gray-100 dark:bg-gray-900"><div class="h-full bg-primary-600 transition-all duration-300" :style="{ width: progress }" /></div>
            <nav class="overflow-x-auto" aria-label="Étapes du dossier employé">
                <ol class="grid min-w-[760px] grid-cols-5">
                    <li v-for="(step, index) in steps" :key="step.number">
                        <button
                            type="button"
                            :disabled="step.number > maxStepReached"
                            :aria-current="currentStep === step.number ? 'step' : undefined"
                            :class="[
                                'flex w-full items-center gap-3 px-4 py-3.5 text-start transition',
                                index > 0 ? 'border-s border-gray-200 dark:border-gray-900' : '',
                                currentStep === step.number ? 'bg-primary-50/70 dark:bg-primary-950/20' : 'hover:bg-gray-50 dark:hover:bg-gray-1000/40',
                                step.number > maxStepReached ? 'cursor-not-allowed opacity-45' : '',
                            ]"
                            @click="goToStep(step.number)"
                        >
                            <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold', currentStep === step.number ? 'bg-primary-600 text-white' : step.number < currentStep ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-gray-100 text-slate-400 dark:bg-gray-900']">
                                <Icon v-if="step.number < currentStep" name="check" /><span v-else>{{ step.number }}</span>
                            </span>
                            <span class="min-w-0"><span :class="['block truncate text-sm font-bold', currentStep === step.number ? 'text-primary-700 dark:text-primary-300' : 'text-slate-700 dark:text-white']">{{ step.label }}</span><span class="mt-0.5 block truncate text-[11px] text-slate-400">{{ step.hint }}</span></span>
                        </button>
                    </li>
                </ol>
            </nav>
        </section>

        <div class="flex items-center justify-between gap-3 px-1">
            <div class="flex items-center gap-2.5"><span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-950 dark:text-primary-300"><Icon :name="currentMeta.icon" /></span><div><p class="text-sm font-bold text-slate-700 dark:text-white">{{ currentMeta.label }}</p><p class="text-[11px] text-slate-400">Étape {{ currentStep }} sur {{ steps.length }} · * obligatoire</p></div></div>
            <span class="hidden text-xs font-bold text-primary-600 sm:block">{{ progress }}</span>
        </div>

        <section v-if="currentStep === 1" class="grid overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950 lg:grid-cols-[320px_minmax(0,1fr)]">
            <aside class="border-b border-gray-200 bg-slate-50/70 p-5 dark:border-gray-900 dark:bg-gray-1000/40 lg:border-b-0 lg:border-e">
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-primary-100 text-xl text-primary-700 dark:bg-primary-950 dark:text-primary-300"><Icon name="user" /></span>
                <h2 class="mt-4 font-heading text-lg font-bold text-slate-800 dark:text-white">Identité essentielle</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">Comme à l’accueil Patient, commencez uniquement par identifier la personne. La civilité n’est plus une saisie séparée.</p>
                <div class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-900 dark:bg-emerald-950/30"><p class="text-[11px] font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Automatisation active</p><p class="mt-1 text-sm font-semibold text-emerald-800 dark:text-emerald-200">Civilité : {{ derivedCivility }}</p><p class="mt-1 text-xs leading-5 text-emerald-700/80 dark:text-emerald-300/80">Cette valeur est recalculée et sécurisée par le serveur.</p></div>
            </aside>
            <div class="p-5 sm:p-6">
                <fieldset>
                    <legend class="text-sm font-bold text-slate-700 dark:text-white">Genre <span class="text-red-500">*</span></legend>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        <button v-for="item in options.sexes" :key="item.value" type="button" :class="['flex items-center gap-3 rounded-xl border p-4 text-start transition', form.sex === item.value ? 'border-primary-500 bg-primary-50 text-primary-700 ring-2 ring-primary-100 dark:bg-primary-950/30 dark:text-primary-200 dark:ring-primary-950' : 'border-gray-200 text-slate-600 hover:border-primary-300 dark:border-gray-800 dark:text-slate-300']" @click="chooseSex(item.value)">
                            <span :class="['flex h-9 w-9 items-center justify-center rounded-full', form.sex === item.value ? 'bg-primary-600 text-white' : 'bg-gray-100 text-slate-400 dark:bg-gray-900']"><Icon :name="form.sex === item.value ? 'check' : 'user'" /></span>
                            <span><span class="block text-sm font-bold">{{ item.label }}</span><span class="mt-0.5 block text-xs text-slate-400">Civilité {{ item.value === 'M' ? 'M.' : 'Mme' }}</span></span>
                        </button>
                    </div>
                    <FormError v-if="form.errors.sex">{{ form.errors.sex }}</FormError>
                </fieldset>
                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    <div><label for="last_name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Nom <span class="text-red-500">*</span></label><Input id="last_name" v-model="form.last_name" autocomplete="family-name" :aria-invalid="Boolean(form.errors.last_name)" /><FormError v-if="form.errors.last_name">{{ form.errors.last_name }}</FormError></div>
                    <div><label for="first_name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Prénoms</label><Input id="first_name" v-model="form.first_name" autocomplete="given-name" /><FormError v-if="form.errors.first_name">{{ form.errors.first_name }}</FormError></div>
                    <div><label for="birth_date" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Date de naissance</label><input id="birth_date" v-model="form.birth_date" type="date" :class="fieldClass"><FormError v-if="form.errors.birth_date">{{ form.errors.birth_date }}</FormError></div>
                    <div><label for="birth_place" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Lieu de naissance</label><Input id="birth_place" v-model="form.birth_place" /><FormError v-if="form.errors.birth_place">{{ form.errors.birth_place }}</FormError></div>
                </div>
            </div>
        </section>

        <section v-else-if="currentStep === 2" class="grid overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950 lg:grid-cols-[320px_minmax(0,1fr)]">
            <aside class="border-b border-gray-200 bg-sky-50/60 p-5 dark:border-gray-900 dark:bg-sky-950/10 lg:border-b-0 lg:border-e"><span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-sky-100 text-xl text-sky-700 dark:bg-sky-950 dark:text-sky-300"><Icon name="briefcase" /></span><h2 class="mt-4 font-heading text-lg font-bold text-slate-800 dark:text-white">Affectation professionnelle</h2><p class="mt-2 text-sm leading-6 text-slate-500">Le matricule est l’identifiant RH local. La fonction sélectionnée devient la référence unique ; son libellé historique est synchronisé en arrière-plan.</p><p class="mt-4 rounded-lg bg-white/80 p-3 text-xs leading-5 text-slate-500 dark:bg-gray-950/60">La création d’un dossier n’ouvre ni compte utilisateur ni contrat. Ces actions restent séparées et soumises à leurs permissions.</p></aside>
            <div class="grid content-start gap-4 p-5 sm:grid-cols-2 sm:p-6">
                <div><label for="employee_number" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Matricule <span class="text-red-500">*</span></label><Input id="employee_number" v-model="form.employee_number" autocomplete="off" placeholder="Ex. RH-2026-001" :aria-invalid="Boolean(form.errors.employee_number)" /><p class="mt-1.5 text-xs text-slate-400">Unique sur le site, y compris dans les archives.</p><FormError v-if="form.errors.employee_number">{{ form.errors.employee_number }}</FormError></div>
                <div><label for="hire_date" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Date d’entrée</label><input id="hire_date" v-model="form.hire_date" type="date" :class="fieldClass"><FormError v-if="form.errors.hire_date">{{ form.errors.hire_date }}</FormError></div>
                <div><label for="department_uuid" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Département</label><select id="department_uuid" v-model="form.department_uuid" :class="fieldClass"><option value="">Non affecté</option><option v-for="item in departments" :key="item.uuid" :value="item.uuid" :disabled="!item.available">{{ item.label }}{{ item.available ? '' : ' — archivé' }}</option></select><FormError v-if="form.errors.department_uuid">{{ form.errors.department_uuid }}</FormError></div>
                <div><label for="job_title_uuid" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Fonction</label><select id="job_title_uuid" v-model="form.job_title_uuid" :class="fieldClass"><option value="">Non renseignée</option><option v-for="item in jobTitles" :key="item.uuid" :value="item.uuid" :disabled="!item.available">{{ item.label }}{{ item.available ? '' : ' — archivée' }}</option></select><FormError v-if="form.errors.job_title_uuid">{{ form.errors.job_title_uuid }}</FormError></div>
            </div>
        </section>

        <section v-else-if="currentStep === 3" class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <header class="flex flex-col gap-2 border-b border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/40 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-sm font-bold text-slate-800 dark:text-white">Coordonnées et pièce d’identité</h2><p class="mt-1 text-xs leading-5 text-slate-500">Tout est facultatif à cette étape ; complétez seulement ce qui est disponible.</p></div><span class="w-fit rounded-full bg-gray-100 px-3 py-1 text-[11px] font-bold text-slate-500 dark:bg-gray-900">Étape facultative</span></header>
            <div class="grid gap-6 p-5 sm:p-6 lg:grid-cols-2">
                <div class="space-y-4"><div class="flex items-center gap-2"><span class="flex h-8 w-8 items-center justify-center rounded-lg bg-cyan-50 text-cyan-600 dark:bg-cyan-950"><Icon name="phone" /></span><h3 class="text-sm font-bold text-slate-700 dark:text-white">Contact</h3></div><div class="grid gap-4 sm:grid-cols-2"><div><label for="phone" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Téléphone</label><Input id="phone" v-model="form.phone" autocomplete="tel" /><FormError v-if="form.errors.phone">{{ form.errors.phone }}</FormError></div><div><label for="email" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Email</label><Input id="email" v-model="form.email" type="email" autocomplete="email" /><FormError v-if="form.errors.email">{{ form.errors.email }}</FormError></div></div><div><div class="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"><label class="text-sm font-medium text-slate-700 dark:text-white">Adresse</label><div class="inline-flex w-fit rounded-lg bg-gray-100 p-1 dark:bg-gray-900"><button type="button" :class="['rounded-md px-3 py-1.5 text-xs font-bold transition', addressMode === 'existing' ? 'bg-white text-slate-700 shadow-sm dark:bg-gray-800 dark:text-white' : 'text-slate-400']" @click="setAddressMode('existing')">Référentiel</button><button type="button" :class="['rounded-md px-3 py-1.5 text-xs font-bold transition', addressMode === 'new' ? 'bg-white text-slate-700 shadow-sm dark:bg-gray-800 dark:text-white' : 'text-slate-400']" @click="setAddressMode('new')">Nouvelle</button></div></div><select v-if="addressMode === 'existing'" id="address_entry_uuid" v-model="form.address_entry_uuid" :class="fieldClass"><option value="">Non renseignée</option><option v-for="item in addresses" :key="item.uuid" :value="item.uuid" :disabled="!item.available">{{ item.label }}{{ item.available ? '' : ' — archivée' }}</option></select><Input v-else id="new_address_label" v-model="form.new_address_label" placeholder="Saisir une nouvelle adresse" /><p v-if="addressMode === 'new'" class="mt-1.5 text-xs text-slate-400">Ajoutée une seule fois au référentiel local après validation.</p><FormError v-if="form.errors.address_entry_uuid">{{ form.errors.address_entry_uuid }}</FormError><FormError v-if="form.errors.new_address_label">{{ form.errors.new_address_label }}</FormError></div></div>
                <div class="space-y-4 lg:border-s lg:border-gray-200 lg:ps-6 dark:lg:border-gray-900"><div class="flex items-center gap-2"><span class="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-950"><Icon name="shield-check" /></span><div><h3 class="text-sm font-bold text-slate-700 dark:text-white">Pièce administrative</h3><p class="text-[11px] text-slate-400">CIN proposée automatiquement dès la saisie d’un numéro.</p></div></div><div class="grid gap-4 sm:grid-cols-2"><div><label for="identity_number" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Numéro de pièce</label><Input id="identity_number" v-model="form.identity_document_number" /><FormError v-if="form.errors.identity_document_number">{{ form.errors.identity_document_number }}</FormError></div><div><label for="identity_type" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Type</label><select id="identity_type" v-model="form.identity_document_type" :class="fieldClass"><option value="">Non renseigné</option><option v-for="item in options.identity_document_types" :key="item.value" :value="item.value">{{ item.label }}</option></select><FormError v-if="form.errors.identity_document_type">{{ form.errors.identity_document_type }}</FormError></div><div><label for="identity_date" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Délivrée le</label><input id="identity_date" v-model="form.identity_document_issued_on" type="date" :class="fieldClass"><FormError v-if="form.errors.identity_document_issued_on">{{ form.errors.identity_document_issued_on }}</FormError></div><div><label for="identity_place" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Délivrée à</label><Input id="identity_place" v-model="form.identity_document_issued_at" /><FormError v-if="form.errors.identity_document_issued_at">{{ form.errors.identity_document_issued_at }}</FormError></div></div></div>
            </div>
        </section>

        <section v-else-if="currentStep === 4" class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <header class="flex flex-col gap-2 border-b border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/40 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-sm font-bold text-slate-800 dark:text-white">Compléments du dossier</h2><p class="mt-1 text-xs leading-5 text-slate-500">Regroupés par usage pour éviter une longue liste de champs sans contexte.</p></div><span class="w-fit rounded-full bg-gray-100 px-3 py-1 text-[11px] font-bold text-slate-500 dark:bg-gray-900">{{ optionalDetailsCount }} information(s) facultative(s)</span></header>
            <div class="grid gap-4 p-5 sm:p-6 lg:grid-cols-3">
                <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><div class="mb-4"><h3 class="text-sm font-bold text-slate-700 dark:text-white">Famille</h3><p class="mt-1 text-xs text-slate-400">Informations déclaratives, sans calcul automatique.</p></div><div class="space-y-4"><div><label for="marital_status" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Situation matrimoniale</label><select id="marital_status" v-model="form.marital_status" :class="fieldClass"><option value="">Non renseignée</option><option v-for="item in options.marital_statuses" :key="item.value" :value="item.value">{{ item.label }}</option></select><FormError v-if="form.errors.marital_status">{{ form.errors.marital_status }}</FormError></div><div><label for="children_count" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Nombre d’enfants</label><input id="children_count" v-model="form.children_count" min="0" type="number" :class="fieldClass"><FormError v-if="form.errors.children_count">{{ form.errors.children_count }}</FormError></div><div><label for="children_details" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Note sur les enfants</label><textarea id="children_details" v-model="form.children_details" :class="areaClass" placeholder="Prénoms, dates de naissance…" /><FormError v-if="form.errors.children_details">{{ form.errors.children_details }}</FormError></div></div></section>
                <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><div class="mb-4"><h3 class="text-sm font-bold text-slate-700 dark:text-white">Qualification</h3><p class="mt-1 text-xs text-slate-400">Pièces et niveau déclarés au recrutement.</p></div><div class="space-y-4"><div><label for="diploma" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Diplôme</label><Input id="diploma" v-model="form.diploma" /><FormError v-if="form.errors.diploma">{{ form.errors.diploma }}</FormError></div><div><label for="education_level" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Niveau d’études</label><Input id="education_level" v-model="form.education_level" /><FormError v-if="form.errors.education_level">{{ form.errors.education_level }}</FormError></div><div><label for="observation" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Observation RH</label><textarea id="observation" v-model="form.observation" :class="areaClass" placeholder="Information utile au suivi administratif" /><FormError v-if="form.errors.observation">{{ form.errors.observation }}</FormError></div></div></section>
                <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><div class="mb-4"><h3 class="text-sm font-bold text-slate-700 dark:text-white">Matériel & disponibilité</h3><p class="mt-1 text-xs text-slate-400">Références remises au personnel.</p></div><div class="space-y-4"><div><label for="badge" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Badge</label><Input id="badge" v-model="form.badge" placeholder="Numéro ou référence" /><FormError v-if="form.errors.badge">{{ form.errors.badge }}</FormError></div><div><label for="blouse" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Blouse</label><Input id="blouse" v-model="form.blouse" placeholder="Taille ou référence" /><FormError v-if="form.errors.blouse">{{ form.errors.blouse }}</FormError></div><div class="rounded-lg border border-gray-200 bg-gray-50/70 px-4 py-3 dark:border-gray-800 dark:bg-gray-1000/40"><CheckBox id="employee_active" v-model="form.active">Employé actif dans les parcours RH</CheckBox><FormError v-if="form.errors.active">{{ form.errors.active }}</FormError></div></div></section>
            </div>
        </section>

        <section v-else class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <header class="border-b border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/40"><h2 class="text-sm font-bold text-slate-800 dark:text-white">Dossier prêt à enregistrer</h2><p class="mt-1 text-xs text-slate-500">Contrôlez les informations principales. Vous pourrez compléter le reste depuis la fiche employé.</p></header>
                <div class="grid gap-3 p-5 sm:grid-cols-2 sm:p-6">
                    <button type="button" class="rounded-xl border border-gray-200 p-4 text-start transition hover:border-primary-300 dark:border-gray-800" @click="editStep(1)"><span class="flex items-center justify-between"><span class="text-[11px] font-bold uppercase tracking-wide text-primary-600">Identité</span><Icon name="edit" /></span><strong class="mt-3 block text-base text-slate-800 dark:text-white">{{ derivedCivility }} · {{ employeeName }}</strong><span class="mt-1 block text-xs text-slate-400">{{ form.birth_date || 'Naissance non renseignée' }}</span></button>
                    <button type="button" class="rounded-xl border border-gray-200 p-4 text-start transition hover:border-primary-300 dark:border-gray-800" @click="editStep(2)"><span class="flex items-center justify-between"><span class="text-[11px] font-bold uppercase tracking-wide text-sky-600">Poste</span><Icon name="edit" /></span><strong class="mt-3 block font-mono text-base text-slate-800 dark:text-white">{{ form.employee_number }}</strong><span class="mt-1 block text-xs text-slate-400">{{ selectedJobTitle }} · {{ selectedDepartment }}</span></button>
                    <button type="button" class="rounded-xl border border-gray-200 p-4 text-start transition hover:border-primary-300 dark:border-gray-800" @click="editStep(3)"><span class="flex items-center justify-between"><span class="text-[11px] font-bold uppercase tracking-wide text-cyan-600">Contact</span><Icon name="edit" /></span><strong class="mt-3 block text-sm text-slate-800 dark:text-white">{{ form.phone || form.email || 'Aucun contact renseigné' }}</strong><span class="mt-1 block text-xs text-slate-400">{{ selectedAddress }}</span></button>
                    <button type="button" class="rounded-xl border border-gray-200 p-4 text-start transition hover:border-primary-300 dark:border-gray-800" @click="editStep(4)"><span class="flex items-center justify-between"><span class="text-[11px] font-bold uppercase tracking-wide text-violet-600">Compléments</span><Icon name="edit" /></span><strong class="mt-3 block text-sm text-slate-800 dark:text-white">{{ optionalDetailsCount }} information(s) complémentaire(s)</strong><span class="mt-1 block text-xs text-slate-400">{{ form.active ? 'Dossier actif' : 'Dossier inactif' }}</span></button>
                </div>
            </div>
            <aside class="h-fit overflow-hidden rounded-2xl border border-emerald-200 bg-emerald-50 shadow-sm dark:border-emerald-900 dark:bg-emerald-950/20"><div class="p-5"><span class="flex h-11 w-11 items-center justify-center rounded-full bg-emerald-600 text-xl text-white"><Icon name="check" /></span><h2 class="mt-4 font-heading text-lg font-bold text-emerald-900 dark:text-emerald-100">Ce qui sera automatisé</h2><ul class="mt-3 space-y-3 text-sm leading-5 text-emerald-800 dark:text-emerald-200"><li class="flex gap-2"><Icon class="mt-0.5 shrink-0" name="check" /><span>Civilité calculée depuis le genre.</span></li><li class="flex gap-2"><Icon class="mt-0.5 shrink-0" name="check" /><span>Fonction historique synchronisée avec le référentiel.</span></li><li class="flex gap-2"><Icon class="mt-0.5 shrink-0" name="check" /><span>Nouvelle adresse ajoutée une seule fois au référentiel.</span></li></ul><div class="mt-5 rounded-lg bg-white/70 p-3 text-xs leading-5 text-emerald-800 dark:bg-gray-950/30 dark:text-emerald-200">Le compte utilisateur, le contrat et le dossier patient ne sont jamais créés implicitement : chacun possède ses propres droits et son propre audit.</div></div></aside>
        </section>

        <footer class="sticky bottom-3 z-10 flex flex-col-reverse gap-2 rounded-xl border border-gray-200 bg-white/95 p-3 shadow-lg backdrop-blur dark:border-gray-800 dark:bg-gray-950/95 sm:flex-row sm:items-center sm:justify-between">
            <Button :as="Link" :href="cancelHref" size="rg" variant="white-outline">Annuler</Button>
            <div class="flex items-center justify-end gap-2">
                <Button v-if="currentStep > 1" type="button" size="rg" variant="white-outline" @click="previousStep"><Icon name="arrow-left" /><span class="ms-2">Précédent</span></Button>
                <Button v-if="currentStep < steps.length" type="button" size="rg" @click="nextStep"><span class="me-2">Continuer</span><Icon name="arrow-right" /></Button>
                <Button v-else size="rg" type="submit" :disabled="form.processing"><Icon class="text-lg" name="check" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : submitLabel }}</span></Button>
            </div>
        </footer>
    </form>
</template>
