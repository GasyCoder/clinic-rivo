<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import SubAnalysesEditor from './SubAnalysesEditor.vue';

const props = defineProps({
    form: { type: Object, required: true },
    analysisUuid: { type: String, default: null },
    hierarchyPath: { type: String, default: '' },
    catalogItems: { type: Array, required: true },
    parents: { type: Array, required: true },
    levels: { type: Array, required: true },
    resultTypes: { type: Array, required: true },
    examCategories: { type: Array, default: () => [] },
    submitLabel: { type: String, required: true },
    submitUrl: { type: String, required: true },
    submitMethod: { type: String, required: true },
    cancelHref: { type: String, required: true },
});

// A group (PARENT) can itself sit inside another group — only a terminal
// result (CHILD) is required to have one and a standalone analysis (NORMAL)
// is required to have none; PARENT may be a root or nested either way.
const mayHaveParent = (level) => level === 'PARENT' || level === 'CHILD';

const steps = [
    { number: 1, label: 'Classification', hint: 'Prestation, code et niveau', icon: 'activity' },
    { number: 2, label: 'Définition', hint: 'Désignation et type de résultat', icon: 'edit' },
    { number: 3, label: 'Références', hint: 'Valeurs normales, facultatif', icon: 'list' },
    { number: 4, label: 'Sous-analyses', hint: 'Uniquement pour un groupe', icon: 'tree' },
    { number: 5, label: 'Confirmer', hint: 'Contrôle avant enregistrement', icon: 'check-circle' },
];
const stepFields = {
    1: ['catalog_item_uuid', 'code', 'level', 'parent_uuid'],
    2: ['designation', 'exam_category', 'result_type', 'unit', 'display_order', 'is_bold'],
    3: ['reference_general', 'reference_male', 'reference_female', 'reference_child_male', 'reference_child_female', 'predefined_values_text', 'description'],
    4: ['children'],
    5: [],
};
// Groupe parent is only required once the level actually needs one — a
// static required list can't express that, so this stays a computed.
const requiredByStep = computed(() => ({
    1: ['catalog_item_uuid', 'code', 'level', ...(props.form.level === 'CHILD' ? ['parent_uuid'] : [])],
    2: ['designation', 'result_type'],
}));
const requiredLabels = {
    catalog_item_uuid: 'La prestation Laboratoire', code: 'Le code', level: 'Le niveau', parent_uuid: 'Le groupe parent',
    designation: 'La désignation', result_type: 'Le type de résultat',
};

const currentStep = ref(1);
const maxStepReached = ref(1);
const currentMeta = computed(() => steps[currentStep.value - 1]);
const progress = computed(() => `${Math.round((currentStep.value / steps.length) * 100)}%`);

const selectedCatalogItem = computed(() => props.catalogItems.find((item) => item.uuid === props.form.catalog_item_uuid) ?? null);
const selectedParent = computed(() => availableParents.value.find((parent) => parent.uuid === props.form.parent_uuid) ?? null);
const referencesCount = computed(() => ['reference_general', 'reference_male', 'reference_female', 'reference_child_male', 'reference_child_female']
    .filter((key) => props.form[key]).length);

// A group can never be moved under itself or under its own descendants —
// the backend rejects that as a cycle anyway, but excluding it here (via the
// breadcrumb path already computed server-side) means the dropdown never
// even offers an option that would just bounce back with an error.
const availableParents = computed(() => props.parents.filter((parent) => {
    if (parent.catalog_item_uuid !== props.form.catalog_item_uuid) return false;
    if (!props.analysisUuid) return true;
    if (parent.uuid === props.analysisUuid) return false;
    return !props.hierarchyPath || !parent.path.startsWith(`${props.hierarchyPath} › `);
}));

const selectClass = 'block h-10 w-full appearance-none rounded-lg border border-gray-200 bg-white px-3 pe-9 text-sm text-slate-700 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950';

// Sub-analyses are edited inline up to two levels deep (children, then
// their own children) — a node needing a third level still goes through
// the normal Groupe parent picker above as a separate entry.
const splitPredefinedValues = (text) => text.split('|').map((value) => value.trim()).filter(Boolean);
const transformChild = (child, index) => ({
    ...child,
    display_order: index + 1,
    predefined_values: splitPredefinedValues(child.predefined_values_text),
    children: child.level === 'PARENT' ? child.children.map(transformChild) : [],
});
const payload = (data) => ({
    ...data,
    parent_uuid: mayHaveParent(data.level) ? data.parent_uuid : null,
    predefined_values: splitPredefinedValues(data.predefined_values_text),
    children: data.level === 'PARENT' ? data.children.map(transformChild) : [],
});

const scrollToWizard = () => nextTick(() => document.getElementById('analysis-wizard')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
const goToStep = (step) => {
    if (step > maxStepReached.value) return;
    currentStep.value = step;
    scrollToWizard();
};
const validateCurrentStep = () => {
    let valid = true;
    (requiredByStep.value[currentStep.value] ?? []).forEach((field) => {
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
const submitForm = () => props.form.transform(payload)[props.submitMethod](props.submitUrl, { preserveScroll: true });
const submit = () => (currentStep.value < steps.length ? nextStep() : submitForm());

watch(
    () => props.form.errors,
    (errors) => {
        const keys = Object.keys(errors ?? {});
        if (!keys.length) return;
        const firstErrorStep = Object.entries(stepFields).find(([, fields]) => (
            fields.some((field) => keys.some((key) => key === field || key.startsWith(`${field}.`)))
        ));
        if (!firstErrorStep) return;
        currentStep.value = Number(firstErrorStep[0]);
        maxStepReached.value = Math.max(maxStepReached.value, currentStep.value);
    },
    { deep: true },
);

const levelLabel = (value) => ({ PARENT: 'Groupe', CHILD: 'Sous-analyse', NORMAL: 'Analyse simple' }[value] ?? value);
const levelHint = (value) => ({
    PARENT: 'Peut contenir des sous-éléments et être lui-même rattaché à un autre groupe.',
    CHILD: 'Résultat terminal : un groupe parent est obligatoire.',
    NORMAL: 'Analyse autonome : jamais de parent.',
}[value] ?? '');
const typeLabel = (value) => ({ NUMERIC: 'Numérique', TEXT: 'Texte', CHOICE: 'Choix', BOOLEAN: 'Oui / Non' }[value] ?? value);
const typeHint = (value) => ({
    NUMERIC: 'Valeur numérique précise avec unité de mesure. Ex : Glycémie (1,05 g/L).',
    TEXT: 'Texte libre ou description qualitative. Ex : Aspect macroscopique.',
    CHOICE: 'Choix parmi une liste de valeurs prédéfinies (ci-dessous). Ex : Groupe sanguin.',
    BOOLEAN: 'Résultat à deux états. Ex : Positif / Négatif.',
}[value] ?? '');
</script>

<template>
    <form id="analysis-wizard" class="scroll-mt-4 space-y-4" @submit.prevent="submit">
        <ValidationErrorSummary :errors="form.errors" />

        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="h-1 bg-gray-100 dark:bg-gray-900"><div class="h-full bg-primary-600 transition-all duration-300" :style="{ width: progress }" /></div>
            <nav class="overflow-x-auto" aria-label="Étapes de la définition d’analyse">
                <ol class="grid min-w-[820px] grid-cols-5">
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
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-primary-100 text-xl text-primary-700 dark:bg-primary-950 dark:text-primary-300"><Icon name="activity" /></span>
                <h2 class="mt-4 font-heading text-lg font-bold text-slate-800 dark:text-white">Où se situe cette analyse ?</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">La prestation Laboratoire porte la facturation ; cette fiche ne structure que le résultat. Le niveau détermine si un groupe parent est possible, obligatoire, ou exclu.</p>
            </aside>
            <div class="grid content-start gap-4 p-5 sm:grid-cols-2 sm:p-6">
                <div class="sm:col-span-2"><label for="catalog_item_uuid" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Prestation Laboratoire <span class="text-red-500">*</span></label><select id="catalog_item_uuid" v-model="form.catalog_item_uuid" :class="selectClass" :aria-invalid="Boolean(form.errors.catalog_item_uuid)" @change="form.parent_uuid = ''"><option v-for="item in catalogItems" :key="item.uuid" :value="item.uuid">{{ item.code }} · {{ item.name }}</option></select><FormError v-if="form.errors.catalog_item_uuid">{{ form.errors.catalog_item_uuid }}</FormError></div>
                <div><label for="code" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Code <span class="text-red-500">*</span></label><Input id="code" v-model="form.code" placeholder="NFS-HB" :aria-invalid="Boolean(form.errors.code)" /><FormError v-if="form.errors.code">{{ form.errors.code }}</FormError></div>
                <div><label for="level" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Niveau <span class="text-red-500">*</span></label><select id="level" v-model="form.level" :class="selectClass" :aria-invalid="Boolean(form.errors.level)" @change="form.parent_uuid = ''"><option v-for="level in levels" :key="level" :value="level">{{ levelLabel(level) }}</option></select><p class="mt-1.5 text-xs text-slate-400">{{ levelHint(form.level) }}</p><FormError v-if="form.errors.level">{{ form.errors.level }}</FormError></div>
                <div v-if="mayHaveParent(form.level)" class="sm:col-span-2"><label for="parent_uuid" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">{{ form.level === 'PARENT' ? 'Groupe parent (facultatif)' : 'Groupe parent' }} <span v-if="form.level === 'CHILD'" class="text-red-500">*</span></label><select id="parent_uuid" v-model="form.parent_uuid" :class="selectClass" :aria-invalid="Boolean(form.errors.parent_uuid)"><option value="">{{ form.level === 'PARENT' ? 'Aucun — groupe racine' : 'Choisir' }}</option><option v-for="parent in availableParents" :key="parent.uuid" :value="parent.uuid">{{ parent.path }}</option></select><FormError v-if="form.errors.parent_uuid">{{ form.errors.parent_uuid }}</FormError></div>
            </div>
        </section>

        <section v-else-if="currentStep === 2" class="grid overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950 lg:grid-cols-[320px_minmax(0,1fr)]">
            <aside class="border-b border-gray-200 bg-sky-50/60 p-5 dark:border-gray-900 dark:bg-sky-950/10 lg:border-b-0 lg:border-e">
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-sky-100 text-xl text-sky-700 dark:bg-sky-950 dark:text-sky-300"><Icon name="edit" /></span>
                <h2 class="mt-4 font-heading text-lg font-bold text-slate-800 dark:text-white">Comment se présente le résultat ?</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">Le type de résultat conditionne la saisie côté laboratoire (valeur numérique, texte libre, choix ou oui/non).</p>
            </aside>
            <div class="grid content-start gap-4 p-5 sm:grid-cols-2 sm:p-6">
                <div class="sm:col-span-2"><label for="designation" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Désignation <span class="text-red-500">*</span></label><Input id="designation" v-model="form.designation" placeholder="Hémoglobine" :aria-invalid="Boolean(form.errors.designation)" /><FormError v-if="form.errors.designation">{{ form.errors.designation }}</FormError></div>
                <div><label for="exam_category" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Examen</label><Input id="exam_category" v-model="form.exam_category" list="exam-category-options" placeholder="BIOCHIMIE, HEMATOLOGIE…" /><datalist id="exam-category-options"><option v-for="category in examCategories" :key="category" :value="category" /></datalist><FormError v-if="form.errors.exam_category">{{ form.errors.exam_category }}</FormError></div>
                <div><label for="result_type" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Type de résultat <span class="text-red-500">*</span></label><select id="result_type" v-model="form.result_type" :class="selectClass" :aria-invalid="Boolean(form.errors.result_type)"><option v-for="type in resultTypes" :key="type" :value="type">{{ typeLabel(type) }}</option></select><p class="mt-1.5 text-xs text-slate-400">{{ typeHint(form.result_type) }}</p><FormError v-if="form.errors.result_type">{{ form.errors.result_type }}</FormError></div>
                <div><label for="unit" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Unité</label><Input id="unit" v-model="form.unit" placeholder="g/dL, mmol/L…" /><FormError v-if="form.errors.unit">{{ form.errors.unit }}</FormError></div>
                <div><label for="display_order" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Ordre</label><Input id="display_order" v-model="form.display_order" type="number" min="0" /><FormError v-if="form.errors.display_order">{{ form.errors.display_order }}</FormError></div>
                <div class="sm:col-span-2 flex items-center rounded-lg border border-gray-200 bg-gray-50/70 px-4 py-3 dark:border-gray-800 dark:bg-gray-1000/40"><label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 dark:text-slate-300"><input v-model="form.is_bold" type="checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />Gras à l’impression</label></div>
            </div>
        </section>

        <section v-else-if="currentStep === 3" class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <header class="flex flex-col gap-2 border-b border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/40 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-sm font-bold text-slate-800 dark:text-white">Références et valeurs</h2><p class="mt-1 text-xs leading-5 text-slate-500">Tout est facultatif ; à valider selon la méthode et les réactifs du laboratoire.</p></div><span class="w-fit rounded-full bg-gray-100 px-3 py-1 text-[11px] font-bold text-slate-500 dark:bg-gray-900">Étape facultative</span></header>
            <div class="grid gap-6 p-5 sm:p-6 lg:grid-cols-2">
                <div class="space-y-4"><div class="flex items-center gap-2"><span class="flex h-8 w-8 items-center justify-center rounded-lg bg-cyan-50 text-cyan-600 dark:bg-cyan-950"><Icon name="list" /></span><h3 class="text-sm font-bold text-slate-700 dark:text-white">Références par profil</h3></div><div class="grid gap-4 sm:grid-cols-2"><div v-for="field in [{ key: 'reference_general', label: 'Référence générale' }, { key: 'reference_male', label: 'Homme' }, { key: 'reference_female', label: 'Femme' }, { key: 'reference_child_male', label: 'Enfant garçon' }, { key: 'reference_child_female', label: 'Enfant fille' }]" :key="field.key"><label :for="field.key" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">{{ field.label }}</label><Input :id="field.key" v-model="form[field.key]" placeholder="Intervalle ou texte" /><FormError v-if="form.errors[field.key]">{{ form.errors[field.key] }}</FormError></div></div></div>
                <div class="space-y-4 lg:border-s lg:border-gray-200 lg:ps-6 dark:lg:border-gray-900"><div class="flex items-center gap-2"><span class="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-950"><Icon name="edit" /></span><h3 class="text-sm font-bold text-slate-700 dark:text-white">Valeurs et description</h3></div><div><label for="predefined_values_text" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Valeurs prédéfinies</label><Input id="predefined_values_text" v-model="form.predefined_values_text" placeholder="Positif|Négatif|Indéterminé" /><p class="mt-1.5 text-xs text-slate-400">Séparez les choix par |</p><FormError v-if="form.errors.predefined_values_text">{{ form.errors.predefined_values_text }}</FormError></div><div><label for="description" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Description</label><Input id="description" v-model="form.description" placeholder="Méthode ou précision utile" /><FormError v-if="form.errors.description">{{ form.errors.description }}</FormError></div></div>
            </div>
        </section>

        <section v-else-if="currentStep === 4" class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <header class="flex flex-col gap-2 border-b border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/40 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-sm font-bold text-slate-800 dark:text-white">Sous-analyses</h2><p class="mt-1 text-xs leading-5 text-slate-500">Uniquement pour un niveau « Groupe » — chaque sous-analyse peut à son tour être un sous-groupe.</p></div><span class="w-fit rounded-full bg-gray-100 px-3 py-1 text-[11px] font-bold text-slate-500 dark:bg-gray-900">Étape facultative</span></header>
            <div class="p-5 sm:p-6">
                <SubAnalysesEditor
                    v-if="form.level === 'PARENT'"
                    :children="form.children"
                    :form="form"
                    path-prefix="children"
                    :result-types="resultTypes"
                    :exam-categories="examCategories"
                    :depth="0"
                    :max-depth="1"
                />
                <p v-else class="rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-slate-400 dark:border-gray-700">Cette analyse est de niveau « {{ levelLabel(form.level) }} » : elle ne peut pas avoir de sous-analyses. Choisissez « Groupe » à l’étape Classification pour en ajouter.</p>
            </div>
        </section>

        <section v-else class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <header class="border-b border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/40"><h2 class="text-sm font-bold text-slate-800 dark:text-white">Définition prête à enregistrer</h2><p class="mt-1 text-xs text-slate-500">Contrôlez les informations principales avant de valider.</p></header>
                <div class="grid gap-3 p-5 sm:grid-cols-2 sm:p-6">
                    <button type="button" class="rounded-xl border border-gray-200 p-4 text-start transition hover:border-primary-300 dark:border-gray-800" @click="editStep(1)"><span class="flex items-center justify-between"><span class="text-[11px] font-bold uppercase tracking-wide text-primary-600">Classification</span><Icon name="edit" /></span><strong class="mt-3 block font-mono text-base text-slate-800 dark:text-white">{{ form.code || 'Code à renseigner' }}</strong><span class="mt-1 block text-xs text-slate-400">{{ levelLabel(form.level) }} · {{ selectedCatalogItem ? `${selectedCatalogItem.code} · ${selectedCatalogItem.name}` : 'Prestation non choisie' }}<template v-if="selectedParent"> · sous {{ selectedParent.path }}</template></span></button>
                    <button type="button" class="rounded-xl border border-gray-200 p-4 text-start transition hover:border-primary-300 dark:border-gray-800" @click="editStep(2)"><span class="flex items-center justify-between"><span class="text-[11px] font-bold uppercase tracking-wide text-sky-600">Définition</span><Icon name="edit" /></span><strong class="mt-3 block text-base text-slate-800 dark:text-white">{{ form.designation || 'Désignation à renseigner' }}</strong><span class="mt-1 block text-xs text-slate-400">{{ typeLabel(form.result_type) }}{{ form.unit ? ` · ${form.unit}` : '' }}{{ form.exam_category ? ` · ${form.exam_category}` : '' }}</span></button>
                    <button type="button" class="rounded-xl border border-gray-200 p-4 text-start transition hover:border-primary-300 dark:border-gray-800" @click="editStep(3)"><span class="flex items-center justify-between"><span class="text-[11px] font-bold uppercase tracking-wide text-cyan-600">Références</span><Icon name="edit" /></span><strong class="mt-3 block text-sm text-slate-800 dark:text-white">{{ referencesCount }} référence(s) renseignée(s)</strong><span class="mt-1 block text-xs text-slate-400">{{ form.predefined_values_text || 'Aucune valeur prédéfinie' }}</span></button>
                    <button type="button" class="rounded-xl border border-gray-200 p-4 text-start transition hover:border-primary-300 dark:border-gray-800" @click="editStep(4)"><span class="flex items-center justify-between"><span class="text-[11px] font-bold uppercase tracking-wide text-violet-600">Sous-analyses</span><Icon name="edit" /></span><strong class="mt-3 block text-sm text-slate-800 dark:text-white">{{ form.level === 'PARENT' ? `${form.children.length} sous-analyse(s)` : 'Non applicable' }}</strong><span class="mt-1 block text-xs text-slate-400">{{ form.is_bold ? 'Affichée en gras à l’impression' : 'Affichage normal' }}</span></button>
                </div>
            </div>
            <aside class="h-fit overflow-hidden rounded-2xl border border-emerald-200 bg-emerald-50 shadow-sm dark:border-emerald-900 dark:bg-emerald-950/20"><div class="p-5"><span class="flex h-11 w-11 items-center justify-center rounded-full bg-emerald-600 text-xl text-white"><Icon name="check" /></span><h2 class="mt-4 font-heading text-lg font-bold text-emerald-900 dark:text-emerald-100">À retenir</h2><ul class="mt-3 space-y-3 text-sm leading-5 text-emerald-800 dark:text-emerald-200"><li class="flex gap-2"><Icon class="mt-0.5 shrink-0" name="check" /><span>La prestation Laboratoire porte le tarif ; cette fiche ne structure que le résultat.</span></li><li class="flex gap-2"><Icon class="mt-0.5 shrink-0" name="check" /><span>Une sous-analyse retirée de la liste est désactivée à l’enregistrement, jamais supprimée.</span></li><li class="flex gap-2"><Icon class="mt-0.5 shrink-0" name="check" /><span>Un troisième niveau se crée séparément, rattaché via « Groupe parent ».</span></li></ul></div></aside>
        </section>

        <footer class="sticky bottom-3 z-10 flex flex-col-reverse gap-2 rounded-xl border border-gray-200 bg-white/95 p-3 shadow-lg backdrop-blur dark:border-gray-800 dark:bg-gray-950/95 sm:flex-row sm:items-center sm:justify-between">
            <Button :as="Link" :href="cancelHref" size="rg" variant="white-outline">Annuler</Button>
            <div class="flex items-center justify-end gap-2">
                <Button v-if="currentStep > 1" type="button" size="rg" variant="white-outline" @click="previousStep"><Icon name="arrow-left" /><span class="ms-2">Précédent</span></Button>
                <Button v-if="currentStep < steps.length" type="button" size="rg" @click="nextStep"><span class="me-2">Continuer</span><Icon name="arrow-right" /></Button>
                <Button v-else size="rg" type="submit" :disabled="form.processing"><Icon class="text-lg" name="save" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : submitLabel }}</span></Button>
            </div>
        </footer>
    </form>
</template>
