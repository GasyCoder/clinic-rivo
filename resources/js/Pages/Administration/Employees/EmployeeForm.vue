<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    BadgeCheck,
    Briefcase,
    Building2,
    Check,
    CircleCheck,
    Contact,
    GraduationCap,
    Hash,
    HeartHandshake,
    IdCard,
    ListPlus,
    Lock,
    Mail,
    MapPin,
    Pencil,
    Phone,
    Shirt,
    Sparkles,
    User,
} from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import ShadSelect from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { hrUrl } from '@/utilities/hrUrl';

/**
 * ADR-066 / ADR-187 — le dossier Employé, en cinq étapes, identique au site et
 * sur le portail. Seuls le genre, le nom et le matricule sont exigés ; le reste
 * se complète plus tard depuis la fiche. Le compte de connexion ne se relie plus
 * ici mais depuis « Utilisateurs », à la création du compte (ADR-188).
 */
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
const { can } = usePermissions();

const steps = [
    { number: 1, label: 'Identité', hint: 'Qui est la personne ?', icon: User },
    { number: 2, label: 'Poste', hint: 'Où travaille-t-elle ?', icon: Briefcase },
    { number: 3, label: 'Contact', hint: 'Comment la joindre ?', icon: Phone },
    { number: 4, label: 'Compléments', hint: 'Données facultatives', icon: ListPlus },
    { number: 5, label: 'Confirmer', hint: 'Contrôle du dossier', icon: CircleCheck },
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
const progress = computed(() => Math.round((currentStep.value / steps.length) * 100));
const derivedCivility = computed(() => ({ M: 'Monsieur (M.)', F: 'Madame (Mme)' }[props.form.sex] || 'Attribuée après le choix du genre'));
const employeeName = computed(() => [props.form.last_name, props.form.first_name].filter(Boolean).join(' ') || 'Identité à compléter');
const selectedDepartment = computed(() => props.departments.find((item) => item.uuid === props.form.department_uuid)?.label || 'Non affecté');
const selectedJobTitle = computed(() => props.jobTitles.find((item) => item.uuid === props.form.job_title_uuid)?.label || 'Fonction non renseignée');
const selectedAddress = computed(() => {
    if (addressMode.value === 'new') return props.form.new_address_label || 'Adresse non renseignée';
    return props.addresses.find((item) => item.uuid === props.form.address_entry_uuid)?.label || 'Adresse non renseignée';
});

/**
 * Un référentiel archivé reste affiché, grisé : un dossier qui le porte déjà
 * doit le montrer, mais aucun nouveau dossier ne peut le choisir.
 */
const referenceOptions = (items, empty, archived) => [
    { value: '', label: empty },
    ...(items ?? []).map((item) => ({
        value: item.uuid,
        label: item.available ? item.label : `${item.label} — ${archived}`,
        disabled: !item.available,
    })),
];
const departmentOptions = computed(() => referenceOptions(props.departments, 'Non affecté', 'archivé'));
const jobTitleOptions = computed(() => referenceOptions(props.jobTitles, 'Non renseignée', 'archivée'));
const addressOptions = computed(() => referenceOptions(props.addresses, 'Non renseignée', 'archivée'));
const listOptions = (items, empty) => [{ value: '', label: empty }, ...(items ?? []).map((item) => ({ value: item.value, label: item.label }))];
const identityTypeOptions = computed(() => listOptions(props.options?.identity_document_types, 'Non renseigné'));
const maritalOptions = computed(() => listOptions(props.options?.marital_statuses, 'Non renseignée'));

const optionalDetailsCount = computed(() => [
    props.form.phone, props.form.email, props.form.address_entry_uuid, props.form.new_address_label,
    props.form.identity_document_number, props.form.marital_status, props.form.children_count,
    props.form.diploma, props.form.education_level, props.form.badge, props.form.blouse,
].filter((value) => value !== '' && value !== null && value !== undefined).length);

const frenchDate = (iso) => (iso ? String(iso).slice(0, 10).split('-').reverse().join('/') : null);
const invalid = (field) => Boolean(props.form.errors?.[field]);
const fieldClass = (field) => cn(invalid(field) && 'border-destructive focus-visible:border-destructive focus-visible:ring-destructive/25');

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
const stepState = (number) => (number === currentStep.value ? 'current' : number > maxStepReached.value ? 'locked' : number < currentStep.value ? 'done' : 'reached');
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

/** Un clic dans le résumé des erreurs mène au champ, à son étape. */
const focusField = (field) => {
    const step = Object.entries(stepFields).find(([, fields]) => fields.includes(field));
    if (step) editStep(Number(step[0]));
    nextTick(() => document.getElementById(field)?.focus({ preventScroll: false }));
};

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

const recap = computed(() => [
    { step: 1, label: 'Identité', icon: User, tone: 'text-primary', title: `${derivedCivility.value} · ${employeeName.value}`, lines: [frenchDate(props.form.birth_date) ? `Né(e) le ${frenchDate(props.form.birth_date)}${props.form.birth_place ? ` à ${props.form.birth_place}` : ''}` : 'Naissance non renseignée'] },
    { step: 2, label: 'Poste', icon: Briefcase, tone: 'text-sky-600 dark:text-sky-400', title: props.form.employee_number || 'Matricule à saisir', mono: true, lines: [`${selectedJobTitle.value} · ${selectedDepartment.value}`] },
    { step: 3, label: 'Contact', icon: Phone, tone: 'text-cyan-600 dark:text-cyan-400', title: props.form.phone || props.form.email || 'Aucun contact renseigné', lines: [selectedAddress.value] },
    { step: 4, label: 'Compléments', icon: ListPlus, tone: 'text-violet-600 dark:text-violet-400', title: `${optionalDetailsCount.value} information(s) complémentaire(s)`, lines: [props.form.active ? 'Dossier actif' : 'Dossier inactif'] },
]);
</script>

<template>
    <form id="employee-wizard" class="scroll-mt-4 space-y-4" novalidate @submit.prevent="submit">
        <ValidationErrorSummary :errors="form.errors" @select="focusField" />

        <!-- Étapes : une pastille par étape ; les libellés s'effacent sur un écran étroit,
             l'étape en cours reste nommée juste en dessous. -->
        <Card class="overflow-hidden">
            <div class="h-1 bg-muted" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" :aria-label="`Avancement : ${progress} %`">
                <div class="h-full bg-primary transition-[width] duration-300" :style="{ width: `${progress}%` }" />
            </div>
            <nav aria-label="Étapes du dossier employé">
                <ol class="grid grid-cols-5 divide-x divide-border">
                    <li v-for="step in steps" :key="step.number">
                        <button
                            type="button"
                            :disabled="stepState(step.number) === 'locked'"
                            :aria-current="stepState(step.number) === 'current' ? 'step' : undefined"
                            :aria-label="`Étape ${step.number} : ${step.label}${stepState(step.number) === 'locked' ? ' (à venir)' : ''}`"
                            :class="cn(
                                'flex h-full w-full items-center justify-center gap-3 px-2 py-3 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring md:justify-start md:px-4',
                                stepState(step.number) === 'current' ? 'bg-primary/5' : 'hover:bg-accent/60',
                                stepState(step.number) === 'locked' && 'cursor-not-allowed opacity-50 hover:bg-transparent',
                            )"
                            @click="goToStep(step.number)"
                        >
                            <span
                                :class="cn(
                                    'grid h-8 w-8 shrink-0 place-items-center rounded-full text-xs font-bold ring-1 ring-inset',
                                    stepState(step.number) === 'current' && 'bg-primary text-primary-foreground ring-primary',
                                    stepState(step.number) === 'done' && 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900',
                                    ['reached', 'locked'].includes(stepState(step.number)) && 'bg-muted text-muted-foreground ring-border',
                                )"
                                aria-hidden="true"
                            >
                                <Check v-if="stepState(step.number) === 'done'" class="h-4 w-4" :stroke-width="3" />
                                <Lock v-else-if="stepState(step.number) === 'locked'" class="h-3.5 w-3.5" />
                                <component :is="step.icon" v-else class="h-4 w-4" />
                            </span>
                            <span class="hidden min-w-0 md:block">
                                <span :class="cn('block truncate text-sm font-semibold', stepState(step.number) === 'current' ? 'text-primary' : 'text-foreground')">{{ step.label }}</span>
                                <span class="mt-0.5 block truncate text-[11px] text-muted-foreground">{{ step.hint }}</span>
                            </span>
                        </button>
                    </li>
                </ol>
            </nav>
        </Card>

        <div class="flex items-center justify-between gap-3 px-1">
            <div class="flex min-w-0 items-center gap-2.5">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                    <component :is="currentMeta.icon" class="h-4 w-4" />
                </span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-foreground">{{ currentMeta.label }}</p>
                    <p class="text-[11px] text-muted-foreground">Étape {{ currentStep }} sur {{ steps.length }} · <span class="text-destructive">*</span> obligatoire</p>
                </div>
            </div>
            <Badge variant="outline" class="tabular-nums">{{ progress }} %</Badge>
        </div>

        <!-- 1 · Identité -->
        <Card v-if="currentStep === 1" class="grid overflow-hidden lg:grid-cols-[300px_minmax(0,1fr)]">
            <aside class="border-b border-border bg-muted/40 p-5 lg:border-b-0 lg:border-e">
                <span class="grid h-11 w-11 place-items-center rounded-xl bg-primary/10 text-primary"><User class="h-5 w-5" /></span>
                <h2 class="mt-4 font-heading text-lg font-bold text-foreground">Identité essentielle</h2>
                <p class="mt-2 text-sm leading-6 text-muted-foreground">Comme à l’accueil Patient, commencez uniquement par identifier la personne. La civilité n’est plus une saisie séparée.</p>
                <div class="mt-5 rounded-lg border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-900 dark:bg-emerald-950/30">
                    <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-300"><Sparkles class="h-3.5 w-3.5" /> Automatisation active</p>
                    <p class="mt-1 text-sm font-semibold text-emerald-800 dark:text-emerald-200">Civilité : {{ derivedCivility }}</p>
                    <p class="mt-1 text-xs leading-5 text-emerald-700/80 dark:text-emerald-300/80">Cette valeur est recalculée et sécurisée par le serveur.</p>
                </div>
            </aside>
            <div class="space-y-6 p-5 sm:p-6">
                <FormField as="div" label="Genre" required :error="form.errors.sex">
                    <div id="sex" role="radiogroup" aria-label="Genre" tabindex="-1" class="grid gap-3 focus:outline-none sm:grid-cols-2">
                        <button
                            v-for="item in options.sexes"
                            :key="item.value"
                            type="button"
                            role="radio"
                            :aria-checked="form.sex === item.value"
                            :class="cn(
                                'flex items-center gap-3 rounded-xl border p-3.5 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                form.sex === item.value ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border bg-card hover:border-primary/40 hover:bg-accent/50',
                                invalid('sex') && form.sex !== item.value && 'border-destructive/60',
                            )"
                            @click="chooseSex(item.value)"
                        >
                            <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-full', form.sex === item.value ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')">
                                <Check v-if="form.sex === item.value" class="h-4 w-4" :stroke-width="3" />
                                <User v-else class="h-4 w-4" />
                            </span>
                            <span>
                                <span class="block text-sm font-semibold text-foreground">{{ item.label }}</span>
                                <span class="mt-0.5 block text-xs text-muted-foreground">Civilité {{ item.value === 'M' ? 'M.' : 'Mme' }}</span>
                            </span>
                        </button>
                    </div>
                </FormField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Nom" required :error="form.errors.last_name">
                        <Input id="last_name" v-model="form.last_name" autocomplete="family-name" :aria-invalid="invalid('last_name')" :class="fieldClass('last_name')" />
                    </FormField>
                    <FormField label="Prénoms" :error="form.errors.first_name">
                        <Input id="first_name" v-model="form.first_name" autocomplete="given-name" :class="fieldClass('first_name')" />
                    </FormField>
                    <FormField as="div" label="Date de naissance" :error="form.errors.birth_date">
                        <DatePicker id="birth_date" v-model="form.birth_date" aria-label="Date de naissance" :invalid="invalid('birth_date')" />
                    </FormField>
                    <FormField label="Lieu de naissance" :error="form.errors.birth_place">
                        <IconInput id="birth_place" v-model="form.birth_place" :icon="MapPin" :class="fieldClass('birth_place')" />
                    </FormField>
                </div>
            </div>
        </Card>

        <!-- 2 · Poste -->
        <Card v-else-if="currentStep === 2" class="grid overflow-hidden lg:grid-cols-[300px_minmax(0,1fr)]">
            <aside class="border-b border-border bg-sky-50/60 p-5 dark:bg-sky-950/15 lg:border-b-0 lg:border-e">
                <span class="grid h-11 w-11 place-items-center rounded-xl bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300"><Briefcase class="h-5 w-5" /></span>
                <h2 class="mt-4 font-heading text-lg font-bold text-foreground">Affectation professionnelle</h2>
                <p class="mt-2 text-sm leading-6 text-muted-foreground">Le matricule est l’identifiant RH local. La fonction sélectionnée devient la référence unique ; son libellé historique est synchronisé en arrière-plan.</p>
                <p class="mt-4 rounded-lg border border-border bg-card/80 p-3 text-xs leading-5 text-muted-foreground">La création d’un dossier n’ouvre ni compte utilisateur ni contrat. Un compte de connexion se relie à cette fiche depuis « Utilisateurs », au moment de le créer.</p>
            </aside>
            <div class="grid content-start gap-4 p-5 sm:grid-cols-2 sm:p-6">
                <FormField label="Matricule" required :error="form.errors.employee_number">
                    <IconInput id="employee_number" v-model="form.employee_number" :icon="Hash" autocomplete="off" placeholder="Ex. RH-2026-001" :aria-invalid="invalid('employee_number')" :class="cn('font-mono', fieldClass('employee_number'))" />
                    <span class="mt-1.5 block text-xs text-muted-foreground">Unique sur le site, y compris dans les archives.</span>
                </FormField>
                <FormField as="div" label="Date d’entrée" :error="form.errors.hire_date">
                    <DatePicker id="hire_date" v-model="form.hire_date" aria-label="Date d’entrée" :invalid="invalid('hire_date')" />
                </FormField>
                <FormField as="div" label="Département" :error="form.errors.department_uuid">
                    <ShadSelect id="department_uuid" v-model="form.department_uuid" :options="departmentOptions" :icon="Building2" placeholder="Non affecté" class="w-full" aria-label="Département" />
                    <p v-if="! departments?.length" class="mt-1.5 text-xs leading-5 text-muted-foreground">
                        Aucun département n’est encore créé<template v-if="can('hr_settings.view')"> : <Link :href="hrUrl('/administration/departments')" class="font-semibold text-primary hover:underline">ouvrir le module Départements</Link></template>.
                    </p>
                </FormField>
                <FormField as="div" label="Fonction" :error="form.errors.job_title_uuid">
                    <ShadSelect id="job_title_uuid" v-model="form.job_title_uuid" :options="jobTitleOptions" :icon="Briefcase" placeholder="Non renseignée" class="w-full" aria-label="Fonction" />
                    <p v-if="! jobTitles?.length" class="mt-1.5 text-xs leading-5 text-muted-foreground">
                        Aucune fonction n’est encore créée<template v-if="can('hr_settings.view')"> : <Link :href="hrUrl('/administration/job-titles')" class="font-semibold text-primary hover:underline">ouvrir le module Fonctions</Link></template>.
                    </p>
                </FormField>
            </div>
        </Card>

        <!-- 3 · Contact -->
        <Card v-else-if="currentStep === 3" class="overflow-hidden">
            <header class="flex flex-col gap-2 border-b border-border bg-muted/40 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-sm font-bold text-foreground">Coordonnées et pièce d’identité</h2>
                    <p class="mt-1 text-xs leading-5 text-muted-foreground">Tout est facultatif à cette étape ; complétez seulement ce qui est disponible.</p>
                </div>
                <Badge variant="outline" class="w-fit">Étape facultative</Badge>
            </header>
            <div class="grid gap-6 p-5 sm:p-6 lg:grid-cols-2 lg:divide-x lg:divide-border">
                <section class="space-y-4" aria-labelledby="employee-contact-title">
                    <div class="flex items-center gap-2">
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-cyan-50 text-cyan-600 dark:bg-cyan-950/50 dark:text-cyan-300"><Contact class="h-4 w-4" /></span>
                        <div>
                            <h3 id="employee-contact-title" class="text-sm font-bold text-foreground">Contact</h3>
                            <p class="text-[11px] text-muted-foreground">Téléphone, email et adresse de la personne.</p>
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField label="Téléphone" :error="form.errors.phone">
                            <IconInput id="phone" v-model="form.phone" :icon="Phone" type="tel" autocomplete="tel" :class="fieldClass('phone')" />
                        </FormField>
                        <FormField label="Email" :error="form.errors.email">
                            <IconInput id="email" v-model="form.email" :icon="Mail" type="email" autocomplete="email" :class="fieldClass('email')" />
                        </FormField>
                    </div>
                    <FormField as="div" label="Adresse" :error="form.errors.address_entry_uuid || form.errors.new_address_label">
                        <template #action>
                            <span class="inline-flex rounded-md bg-muted p-0.5" role="group" aria-label="Source de l’adresse">
                                <button
                                    v-for="mode in [{ key: 'existing', label: 'Référentiel' }, { key: 'new', label: 'Nouvelle' }]"
                                    :key="mode.key"
                                    type="button"
                                    :aria-pressed="addressMode === mode.key"
                                    :class="cn(
                                        'rounded px-2.5 py-0.5 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                        addressMode === mode.key ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                    )"
                                    @click="setAddressMode(mode.key)"
                                >{{ mode.label }}</button>
                            </span>
                        </template>
                        <ShadSelect v-if="addressMode === 'existing'" id="address_entry_uuid" v-model="form.address_entry_uuid" :options="addressOptions" :icon="MapPin" placeholder="Non renseignée" class="w-full" aria-label="Adresse" />
                        <template v-else>
                            <IconInput id="new_address_label" v-model="form.new_address_label" :icon="MapPin" placeholder="Saisir une nouvelle adresse" aria-label="Nouvelle adresse" :class="fieldClass('new_address_label')" />
                            <span class="mt-1.5 block text-xs text-muted-foreground">Ajoutée une seule fois au référentiel local après validation.</span>
                        </template>
                    </FormField>
                </section>
                <section class="space-y-4 lg:ps-6" aria-labelledby="employee-identity-title">
                    <div class="flex items-center gap-2">
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-950/50 dark:text-violet-300"><IdCard class="h-4 w-4" /></span>
                        <div>
                            <h3 id="employee-identity-title" class="text-sm font-bold text-foreground">Pièce administrative</h3>
                            <p class="text-[11px] text-muted-foreground">CIN proposée automatiquement dès la saisie d’un numéro.</p>
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField label="Numéro de pièce" :error="form.errors.identity_document_number">
                            <Input id="identity_document_number" v-model="form.identity_document_number" :class="fieldClass('identity_document_number')" />
                        </FormField>
                        <FormField as="div" label="Type" :error="form.errors.identity_document_type">
                            <ShadSelect id="identity_document_type" v-model="form.identity_document_type" :options="identityTypeOptions" placeholder="Non renseigné" class="w-full" aria-label="Type de pièce" />
                        </FormField>
                        <FormField as="div" label="Délivrée le" :error="form.errors.identity_document_issued_on">
                            <DatePicker id="identity_document_issued_on" v-model="form.identity_document_issued_on" aria-label="Pièce délivrée le" :invalid="invalid('identity_document_issued_on')" />
                        </FormField>
                        <FormField label="Délivrée à" :error="form.errors.identity_document_issued_at">
                            <Input id="identity_document_issued_at" v-model="form.identity_document_issued_at" :class="fieldClass('identity_document_issued_at')" />
                        </FormField>
                    </div>
                </section>
            </div>
        </Card>

        <!-- 4 · Compléments -->
        <Card v-else-if="currentStep === 4" class="overflow-hidden">
            <header class="flex flex-col gap-2 border-b border-border bg-muted/40 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-sm font-bold text-foreground">Compléments du dossier</h2>
                    <p class="mt-1 text-xs leading-5 text-muted-foreground">Regroupés par usage pour éviter une longue liste de champs sans contexte.</p>
                </div>
                <Badge variant="outline" class="w-fit tabular-nums">{{ optionalDetailsCount }} information(s) facultative(s)</Badge>
            </header>
            <div class="grid gap-4 p-5 sm:p-6 lg:grid-cols-3">
                <section class="rounded-xl border border-border bg-card p-4 shadow-sm" aria-labelledby="employee-family-title">
                    <div class="mb-4 flex items-start gap-2.5">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-rose-50 text-rose-600 dark:bg-rose-950/50 dark:text-rose-300"><HeartHandshake class="h-4 w-4" /></span>
                        <div>
                            <h3 id="employee-family-title" class="text-sm font-bold text-foreground">Famille</h3>
                            <p class="mt-0.5 text-xs text-muted-foreground">Informations déclaratives, sans calcul automatique.</p>
                        </div>
                    </div>
                    <div class="grid gap-4">
                        <FormField as="div" label="Situation matrimoniale" :error="form.errors.marital_status">
                            <ShadSelect id="marital_status" v-model="form.marital_status" :options="maritalOptions" placeholder="Non renseignée" class="w-full" aria-label="Situation matrimoniale" />
                        </FormField>
                        <FormField label="Nombre d’enfants" :error="form.errors.children_count">
                            <Input id="children_count" v-model="form.children_count" type="number" min="0" inputmode="numeric" :class="fieldClass('children_count')" />
                        </FormField>
                        <FormField label="Note sur les enfants" :error="form.errors.children_details">
                            <Textarea id="children_details" v-model="form.children_details" :rows="3" placeholder="Prénoms, dates de naissance…" :class="fieldClass('children_details')" />
                        </FormField>
                    </div>
                </section>
                <section class="rounded-xl border border-border bg-card p-4 shadow-sm" aria-labelledby="employee-qualification-title">
                    <div class="mb-4 flex items-start gap-2.5">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/50 dark:text-amber-300"><GraduationCap class="h-4 w-4" /></span>
                        <div>
                            <h3 id="employee-qualification-title" class="text-sm font-bold text-foreground">Qualification</h3>
                            <p class="mt-0.5 text-xs text-muted-foreground">Pièces et niveau déclarés au recrutement.</p>
                        </div>
                    </div>
                    <div class="grid gap-4">
                        <FormField label="Diplôme" :error="form.errors.diploma">
                            <Input id="diploma" v-model="form.diploma" :class="fieldClass('diploma')" />
                        </FormField>
                        <FormField label="Niveau d’études" :error="form.errors.education_level">
                            <Input id="education_level" v-model="form.education_level" :class="fieldClass('education_level')" />
                        </FormField>
                        <FormField label="Observation RH" :error="form.errors.observation">
                            <Textarea id="observation" v-model="form.observation" :rows="3" placeholder="Information utile au suivi administratif" :class="fieldClass('observation')" />
                        </FormField>
                    </div>
                </section>
                <section class="rounded-xl border border-border bg-card p-4 shadow-sm" aria-labelledby="employee-equipment-title">
                    <div class="mb-4 flex items-start gap-2.5">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-300"><Shirt class="h-4 w-4" /></span>
                        <div>
                            <h3 id="employee-equipment-title" class="text-sm font-bold text-foreground">Matériel & disponibilité</h3>
                            <p class="mt-0.5 text-xs text-muted-foreground">Références remises au personnel.</p>
                        </div>
                    </div>
                    <div class="grid gap-4">
                        <FormField label="Badge" :error="form.errors.badge">
                            <IconInput id="badge" v-model="form.badge" :icon="BadgeCheck" placeholder="Numéro ou référence" :class="fieldClass('badge')" />
                        </FormField>
                        <FormField label="Blouse" :error="form.errors.blouse">
                            <IconInput id="blouse" v-model="form.blouse" :icon="Shirt" placeholder="Taille ou référence" :class="fieldClass('blouse')" />
                        </FormField>
                        <div>
                            <label for="active" class="flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-muted/40 px-3.5 py-3 transition-colors hover:bg-accent/60">
                                <Checkbox id="active" v-model="form.active" class="mt-0.5" />
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold text-foreground">Employé actif</span>
                                    <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">Visible dans les parcours RH : présences, congés, planning.</span>
                                </span>
                            </label>
                            <FormError v-if="form.errors.active">{{ form.errors.active }}</FormError>
                        </div>
                    </div>
                </section>
            </div>
        </Card>

        <!-- 5 · Confirmer -->
        <section v-else class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
            <Card class="overflow-hidden">
                <header class="border-b border-border bg-muted/40 px-5 py-4">
                    <h2 class="text-sm font-bold text-foreground">Dossier prêt à enregistrer</h2>
                    <p class="mt-1 text-xs text-muted-foreground">Contrôlez les informations principales. Vous pourrez compléter le reste depuis la fiche employé.</p>
                </header>
                <div class="grid gap-3 p-5 sm:grid-cols-2 sm:p-6">
                    <button
                        v-for="item in recap"
                        :key="item.step"
                        type="button"
                        :aria-label="`Modifier l’étape ${item.label}`"
                        class="group rounded-xl border border-border bg-card p-4 text-start shadow-sm transition-colors hover:border-primary/40 hover:bg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        @click="editStep(item.step)"
                    >
                        <span class="flex items-center justify-between gap-2">
                            <span :class="cn('flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide', item.tone)"><component :is="item.icon" class="h-3.5 w-3.5" />{{ item.label }}</span>
                            <Pencil class="h-3.5 w-3.5 text-muted-foreground transition-colors group-hover:text-primary" />
                        </span>
                        <strong :class="cn('mt-3 block break-words text-base text-foreground', item.mono && 'font-mono')">{{ item.title }}</strong>
                        <span v-for="(line, index) in item.lines" :key="index" class="mt-1 block text-xs text-muted-foreground">{{ line }}</span>
                    </button>
                </div>
            </Card>
            <Card class="h-fit border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/20">
                <div class="p-5">
                    <span class="grid h-11 w-11 place-items-center rounded-full bg-emerald-600 text-white"><Check class="h-5 w-5" :stroke-width="3" /></span>
                    <h2 class="mt-4 font-heading text-lg font-bold text-emerald-900 dark:text-emerald-100">Ce qui sera automatisé</h2>
                    <ul class="mt-3 space-y-2.5 text-sm leading-5 text-emerald-800 dark:text-emerald-200">
                        <li class="flex gap-2"><CircleCheck class="mt-0.5 h-4 w-4 shrink-0" /><span>Civilité calculée depuis le genre.</span></li>
                        <li class="flex gap-2"><CircleCheck class="mt-0.5 h-4 w-4 shrink-0" /><span>Fonction historique synchronisée avec le référentiel.</span></li>
                        <li class="flex gap-2"><CircleCheck class="mt-0.5 h-4 w-4 shrink-0" /><span>Nouvelle adresse ajoutée une seule fois au référentiel.</span></li>
                    </ul>
                    <p class="mt-5 rounded-lg border border-emerald-200 bg-card/70 p-3 text-xs leading-5 text-emerald-800 dark:border-emerald-900 dark:text-emerald-200">Le compte utilisateur, le contrat et le dossier patient ne sont jamais créés implicitement : chacun possède ses propres droits et son propre audit.</p>
                </div>
            </Card>
        </section>

        <footer class="sticky bottom-3 z-10 flex flex-col-reverse gap-2 rounded-xl border border-border bg-card/95 p-3 shadow-lg backdrop-blur sm:flex-row sm:items-center sm:justify-between">
            <Button :as="Link" :href="cancelHref" variant="ghost">Annuler</Button>
            <div class="flex items-center justify-end gap-2">
                <Button v-if="currentStep > 1" type="button" variant="outline" @click="previousStep"><ArrowLeft class="h-4 w-4" />Précédent</Button>
                <Button v-if="currentStep < steps.length" type="button" @click="nextStep">Continuer<ArrowRight class="h-4 w-4" /></Button>
                <Button v-else type="submit" variant="success" :disabled="form.processing"><Check class="h-4 w-4" :stroke-width="3" />{{ form.processing ? 'Enregistrement…' : submitLabel }}</Button>
            </div>
        </footer>
    </form>
</template>
