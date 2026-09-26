<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    BadgeCheck,
    Banknote,
    Briefcase,
    Building2,
    CalendarClock,
    Check,
    CircleCheck,
    CircleOff,
    Coins,
    Contact,
    CreditCard,
    GraduationCap,
    HandCoins,
    Hash,
    HeartHandshake,
    IdCard,
    Info,
    Landmark,
    ListPlus,
    Lock,
    Mail,
    MapPin,
    Pencil,
    Phone,
    ScanFace,
    ShieldCheck,
    Shirt,
    Sparkles,
    User,
    UserRound,
    Wallet,
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
import EmployeePhoto from '@/Components/Administration/EmployeePhoto.vue';
import EmployeePhotoField from '@/Components/Administration/EmployeePhotoField.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { hrUrl } from '@/utilities/hrUrl';
import { currencyLabel, formatMoney } from '@/utilities/money';
import { seniority } from '@/utilities/seniority';

/**
 * ADR-066 / ADR-187 — le dossier Employé, en cinq étapes, identique au site et
 * sur le portail. Seuls le genre, le nom et le matricule sont exigés ; le reste
 * se complète plus tard depuis la fiche. Le compte de connexion ne se relie plus
 * ici mais depuis « Utilisateurs », à la création du compte (ADR-188).
 *
 * ADR-194 — la photo d'identité 4 × 4 se règle à tout moment : le bandeau
 * d'identité, en tête de chaque étape, la porte avec le nom et le poste. Et la
 * fonction suit le département : choisir « Laboratoire » ne propose que les
 * fonctions de ce département (module Fonctions) ; le serveur refuse de toute
 * façon un couple incohérent (JobTitleDepartmentGuard).
 *
 * ADR-197 — une étape « Rémunération » (salaire, indemnité ou rien, et le compte
 * bancaire) n'existe que pour qui détient `employees.payroll.update` ; les étapes
 * sont donc repérées par leur clé, jamais par leur rang. L'ancienneté se calcule
 * depuis la date d'entrée.
 */
const props = defineProps({
    form: Object,
    options: Object,
    departments: Array,
    jobTitles: Array,
    addresses: Array,
    submitLabel: String,
    cancelHref: String,
    // L'email de la fiche, en lecture : c'est l'adresse professionnelle, posée par sa
    // création (ADR-190). Absent à la création d'un employé.
    currentEmail: { type: String, default: null },
    // ADR-191 — à la création : le matricule proposé selon le modèle du site.
    suggestedEmployeeNumber: { type: String, default: '' },
    employeeNumberModel: { type: String, default: '' },
    // ADR-194 — le couple département/fonction déjà enregistré, toujours choisissable.
    currentPair: { type: Object, default: null },
    currentPhotoUrl: { type: String, default: null },
    // ADR-194 — « Nouveau stagiaire » : le stage s'enregistre juste après.
    internshipIntent: { type: Boolean, default: false },
});
const emit = defineEmits(['submit']);
const { can } = usePermissions();

// ADR-197 — les étapes par clé : « Rémunération » n'apparaît qu'avec son droit.
const payrollEnabled = computed(() => can('employees.payroll.update'));
const ALL_STEPS = [
    { key: 'identity', label: 'Identité', hint: 'Qui est la personne ?', icon: User, fields: ['sex', 'last_name', 'first_name', 'birth_date', 'birth_place', 'photo', 'remove_photo'], required: ['sex', 'last_name'] },
    { key: 'post', label: 'Poste', hint: 'Où travaille-t-elle ?', icon: Briefcase, fields: ['employee_number', 'department_uuid', 'job_title_uuid', 'hire_date'], required: ['employee_number'] },
    { key: 'pay', label: 'Rémunération', hint: 'Salaire et banque', icon: Wallet, fields: ['remuneration_type', 'remuneration_amount', 'bank_account_number', 'bank_account_holder'], required: [] },
    { key: 'contact', label: 'Contact', hint: 'Comment la joindre ?', icon: Phone, fields: ['phone', 'address_entry_uuid', 'new_address_label', 'identity_document_type', 'identity_document_number', 'identity_document_issued_on', 'identity_document_issued_at'], required: [] },
    { key: 'more', label: 'Compléments', hint: 'Données facultatives', icon: ListPlus, fields: ['marital_status', 'children_count', 'children_details', 'diploma', 'education_level', 'badge', 'blouse', 'observation', 'active'], required: [] },
    { key: 'confirm', label: 'Confirmer', hint: 'Contrôle du dossier', icon: CircleCheck, fields: [], required: [] },
];
const steps = computed(() => ALL_STEPS
    .filter((step) => step.key !== 'pay' || payrollEnabled.value)
    .map((step, index) => ({ ...step, number: index + 1 })));
const stepOf = (key) => steps.value.find((step) => step.key === key)?.number ?? 1;
const requiredLabels = { employee_number: 'Le matricule', last_name: 'Le nom', sex: 'Le genre' };

const currentStep = ref(1);
const maxStepReached = ref(1);
const addressMode = ref(props.form.new_address_label ? 'new' : 'existing');
const photoField = ref(null);
const dropping = ref(false);
const currentMeta = computed(() => steps.value[currentStep.value - 1] ?? steps.value[0]);
const currentKey = computed(() => currentMeta.value.key);
const progress = computed(() => Math.round((currentStep.value / steps.value.length) * 100));
const derivedCivility = computed(() => ({ M: 'Monsieur (M.)', F: 'Madame (Mme)' }[props.form.sex] || 'Attribuée après le choix du genre'));
const typedName = computed(() => [props.form.last_name, props.form.first_name].filter(Boolean).join(' '));
const employeeName = computed(() => typedName.value || 'Identité à compléter');
const photoPreview = computed(() => photoField.value?.preview ?? (props.form.remove_photo ? null : props.currentPhotoUrl));
const departmentRecord = computed(() => props.departments.find((item) => item.uuid === props.form.department_uuid));
const jobTitleRecord = computed(() => props.jobTitles.find((item) => item.uuid === props.form.job_title_uuid));
const selectedDepartment = computed(() => departmentRecord.value?.label || 'Non affecté');
const selectedJobTitle = computed(() => jobTitleRecord.value?.label || 'Fonction non renseignée');
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

/*
 * ADR-194 — département → fonctions. Une fonction reliée à aucun département
 * reste proposée partout ; le couple déjà enregistré reste choisissable.
 */
const isCurrentPair = (departmentUuid, jobTitleUuid) => props.currentPair
    && props.currentPair.department_uuid === departmentUuid
    && props.currentPair.job_title_uuid === jobTitleUuid;
const belongsTo = (jobTitle, departmentUuid) => ! departmentUuid
    || (jobTitle.department_uuids ?? []).length === 0
    || jobTitle.department_uuids.includes(departmentUuid)
    || isCurrentPair(departmentUuid, jobTitle.uuid);
const allowedJobTitles = computed(() => (props.jobTitles ?? []).filter((item) => belongsTo(item, props.form.department_uuid)));
const jobTitleOptions = computed(() => {
    const option = (item) => ({
        value: item.uuid,
        label: item.available ? item.label : `${item.label} — archivée`,
        disabled: ! item.available,
    });
    const none = { value: '', label: 'Non renseignée' };

    if (! props.form.department_uuid) return [none, ...allowedJobTitles.value.map(option)];

    const own = allowedJobTitles.value.filter((item) => (item.department_uuids ?? []).includes(props.form.department_uuid) || isCurrentPair(props.form.department_uuid, item.uuid));
    const shared = allowedJobTitles.value.filter((item) => (item.department_uuids ?? []).length === 0 && ! own.includes(item));

    return [
        none,
        ...(own.length ? [{ label: `Fonctions de ${selectedDepartment.value}`, items: own.map(option) }] : []),
        ...(shared.length ? [{ label: 'Proposées dans tous les départements', items: shared.map(option) }] : []),
    ];
});
// Changer de département retire une fonction qui n'y existe pas, et le dit.
const clearedJobTitle = ref('');
watch(() => props.form.department_uuid, (departmentUuid) => {
    const current = jobTitleRecord.value;
    if (current && ! belongsTo(current, departmentUuid)) {
        clearedJobTitle.value = current.label;
        props.form.job_title_uuid = '';
    } else {
        clearedJobTitle.value = '';
    }
});
watch(() => props.form.job_title_uuid, (value) => { if (value) clearedJobTitle.value = ''; });
const addressOptions = computed(() => referenceOptions(props.addresses, 'Non renseignée', 'archivée'));
const listOptions = (items, empty) => [{ value: '', label: empty }, ...(items ?? []).map((item) => ({ value: item.value, label: item.label }))];
const identityTypeOptions = computed(() => listOptions(props.options?.identity_document_types, 'Non renseigné'));
const maritalOptions = computed(() => listOptions(props.options?.marital_statuses, 'Non renseignée'));

const optionalDetailsCount = computed(() => [
    props.form.phone, props.form.address_entry_uuid, props.form.new_address_label,
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
/** Photo : déposer une image sur le grand cadre de l'étape Identité. */
const onDrop = (event) => {
    dropping.value = false;
    photoField.value?.acceptFile(event.dataTransfer?.files?.[0]);
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
    (currentMeta.value.required ?? []).forEach((field) => {
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
    currentStep.value = Math.min(steps.value.length, currentStep.value + 1);
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
const submit = () => currentStep.value < steps.value.length ? nextStep() : emit('submit');

/** Un clic dans le résumé des erreurs mène au champ, à son étape. */
const focusField = (field) => {
    const step = steps.value.find((item) => item.fields.includes(field));
    if (step) editStep(step.number);
    nextTick(() => document.getElementById(field)?.focus({ preventScroll: false }));
};

watch(() => props.form.identity_document_number, (number) => {
    if (number && !props.form.identity_document_type) props.form.identity_document_type = 'CIN';
});
watch(
    () => props.form.errors,
    (errors) => {
        const firstErrorStep = steps.value.find((step) => step.fields.some((field) => errors?.[field]));
        if (!firstErrorStep) return;
        currentStep.value = firstErrorStep.number;
        maxStepReached.value = Math.max(maxStepReached.value, currentStep.value);
    },
    { deep: true },
);

// ADR-197 — l'ancienneté, lue sur la date d'entrée : un aperçu, le serveur la recalcule.
const hireSeniority = computed(() => seniority(props.form.hire_date));

/* ADR-197 — rémunération déclarée et compte bancaire. */
const REMUNERATION_TYPES = [
    { value: 'SALARY', label: 'Salaire', hint: 'Salaire mensuel convenu au contrat', icon: Banknote },
    { value: 'ALLOWANCE', label: 'Indemnité', hint: 'Par exemple un stagiaire indemnisé', icon: HandCoins },
    { value: 'UNPAID', label: 'Non rémunéré', hint: 'Stagiaire non indemnisé, bénévole…', icon: CircleOff },
];
const remunerationType = computed(() => REMUNERATION_TYPES.find((item) => item.value === props.form.remuneration_type) ?? null);
const hasAmount = computed(() => ['SALARY', 'ALLOWANCE'].includes(props.form.remuneration_type));
const amountNumber = computed(() => {
    const value = String(props.form.remuneration_amount ?? '').replace(/[\s\u00A0\u202F]/g, '').replace(',', '.');

    return value !== '' && Number.isFinite(Number(value)) ? Number(value) : null;
});
const chooseRemuneration = (value) => {
    props.form.remuneration_type = props.form.remuneration_type === value ? '' : value;
    if (! hasAmount.value) props.form.remuneration_amount = '';
    props.form.clearErrors('remuneration_type', 'remuneration_amount');
};
const useNameAsHolder = () => {
    props.form.bank_account_holder = typedName.value.toUpperCase();
    props.form.clearErrors('bank_account_holder');
};
const payrollSummary = computed(() => {
    if (! remunerationType.value) return 'Rémunération non renseignée';
    if (! hasAmount.value) return remunerationType.value.label;

    return `${remunerationType.value.label} · ${amountNumber.value !== null ? `${formatMoney(amountNumber.value)} / mois` : 'montant à saisir'}`;
});

const recap = computed(() => [
    { step: stepOf('identity'), label: 'Identité', icon: User, tone: 'text-primary', photo: true, title: `${derivedCivility.value} · ${employeeName.value}`, lines: [frenchDate(props.form.birth_date) ? `Né(e) le ${frenchDate(props.form.birth_date)}${props.form.birth_place ? ` à ${props.form.birth_place}` : ''}` : 'Naissance non renseignée', photoPreview.value ? 'Photo d’identité ajoutée' : 'Sans photo'] },
    { step: stepOf('post'), label: 'Poste', icon: Briefcase, tone: 'text-sky-600 dark:text-sky-400', title: props.form.employee_number || 'Matricule à saisir', mono: true, lines: [`${selectedJobTitle.value} · ${selectedDepartment.value}`, hireSeniority.value ? `Ancienneté : ${hireSeniority.value.label}` : 'Date d’entrée non renseignée'] },
    ...(payrollEnabled.value ? [{ step: stepOf('pay'), label: 'Rémunération', icon: Wallet, tone: 'text-emerald-600 dark:text-emerald-400', title: payrollSummary.value, lines: [props.form.bank_account_number ? `Compte ${props.form.bank_account_number}${props.form.bank_account_holder ? ` · ${props.form.bank_account_holder}` : ''}` : 'Compte bancaire non renseigné'] }] : []),
    { step: stepOf('contact'), label: 'Contact', icon: Phone, tone: 'text-cyan-600 dark:text-cyan-400', title: props.form.phone || props.currentEmail || 'Aucun contact renseigné', lines: [selectedAddress.value] },
    { step: stepOf('more'), label: 'Compléments', icon: ListPlus, tone: 'text-violet-600 dark:text-violet-400', title: `${optionalDetailsCount.value} information(s) complémentaire(s)`, lines: [props.form.active ? 'Dossier actif' : 'Dossier inactif'] },
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
                <ol :class="cn('grid divide-x divide-border', steps.length === 6 ? 'grid-cols-6' : 'grid-cols-5')">
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

        <!-- ADR-194 — le bandeau d'identité, à chaque étape : on voit qui l'on
             enregistre, et la photo 4 × 4 se règle d'ici à tout moment. -->
        <Card class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between">
            <EmployeePhotoField
                ref="photoField"
                v-model="form.photo"
                v-model:remove="form.remove_photo"
                :current-url="currentPhotoUrl"
                :name="typedName"
                :error="form.errors.photo"
            />
            <div class="min-w-0 sm:text-end">
                <p class="truncate text-base font-bold text-foreground">{{ employeeName }}</p>
                <p class="mt-0.5 truncate text-xs text-muted-foreground">
                    <span v-if="form.employee_number" class="font-mono">{{ form.employee_number }}</span>
                    <span v-else>Matricule à venir</span>
                    · {{ jobTitleRecord?.label || 'Fonction à choisir' }}<span v-if="departmentRecord"> · {{ departmentRecord.label }}</span>
                </p>
                <Badge v-if="internshipIntent" variant="secondary" class="mt-1.5"><GraduationCap class="h-3.5 w-3.5" />Stagiaire — le stage suit ce dossier</Badge>
            </div>
        </Card>

        <!-- 1 · Identité -->
        <Card v-if="currentKey === 'identity'" class="grid overflow-hidden lg:grid-cols-[300px_minmax(0,1fr)]">
            <aside class="border-b border-border bg-muted/40 p-5 lg:border-b-0 lg:border-e">
                <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Photo d’identité 4 × 4</p>
                <button
                    type="button"
                    :class="cn(
                        'group mt-3 grid aspect-square w-full max-w-[13rem] place-items-center overflow-hidden rounded-xl border-2 border-dashed bg-card transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        dropping ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/50',
                    )"
                    aria-label="Choisir ou déposer la photo d’identité"
                    @click="photoField?.open()"
                    @dragover.prevent="dropping = true"
                    @dragleave.prevent="dropping = false"
                    @drop.prevent="onDrop"
                >
                    <img v-if="photoPreview" :src="photoPreview" alt="Aperçu de la photo d’identité" class="h-full w-full object-cover">
                    <span v-else class="flex flex-col items-center gap-2 px-4 text-center text-muted-foreground">
                        <ScanFace class="h-9 w-9" />
                        <span class="text-sm font-semibold text-foreground">Ajouter la photo</span>
                        <span class="text-xs leading-5">Cliquez ou déposez une image, puis cadrez le visage.</span>
                    </span>
                </button>
                <ul class="mt-4 space-y-1.5 text-xs leading-5 text-muted-foreground">
                    <li class="flex gap-2"><Check class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" />Visage de face, fond clair</li>
                    <li class="flex gap-2"><Check class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" />JPEG, PNG ou WebP</li>
                    <li class="flex gap-2"><Check class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" />Recadrée en carré, gardée en privé</li>
                </ul>
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
                <p class="flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-xs leading-5 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300">
                    <Sparkles class="mt-0.5 h-3.5 w-3.5 shrink-0" /><span>Civilité : <strong>{{ derivedCivility }}</strong> — calculée depuis le genre et sécurisée par le serveur.</span>
                </p>
            </div>
        </Card>

        <!-- 2 · Poste -->
        <Card v-else-if="currentKey === 'post'" class="grid overflow-hidden lg:grid-cols-[300px_minmax(0,1fr)]">
            <aside class="border-b border-border bg-sky-50/60 p-5 dark:bg-sky-950/15 lg:border-b-0 lg:border-e">
                <span class="grid h-11 w-11 place-items-center rounded-xl bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300"><Briefcase class="h-5 w-5" /></span>
                <h2 class="mt-4 font-heading text-lg font-bold text-foreground">Affectation professionnelle</h2>
                <p class="mt-2 text-sm leading-6 text-muted-foreground">Choisissez d’abord le département : la liste des fonctions ne montre ensuite que celles qui y existent. Le matricule est l’identifiant RH local.</p>
                <p class="mt-3 text-xs leading-5 text-muted-foreground">
                    La correspondance fonctions ↔ départements se règle dans le
                    <Link v-if="can('hr_settings.view')" :href="hrUrl('/administration/job-titles')" class="font-semibold text-primary hover:underline">module Fonctions</Link>
                    <span v-else>module Fonctions</span>.
                </p>
                <p class="mt-4 rounded-lg border border-border bg-card/80 p-3 text-xs leading-5 text-muted-foreground">La création d’un dossier n’ouvre ni compte utilisateur ni contrat. Un compte de connexion se relie à cette fiche depuis « Utilisateurs », au moment de le créer.</p>
            </aside>
            <div class="grid content-start gap-4 p-5 sm:grid-cols-2 sm:p-6">
                <FormField label="Matricule" required :error="form.errors.employee_number">
                    <IconInput id="employee_number" v-model="form.employee_number" :icon="Hash" autocomplete="off" :placeholder="suggestedEmployeeNumber || 'Ex. RH-2026-001'" :aria-invalid="invalid('employee_number')" :class="cn('font-mono', fieldClass('employee_number'))" />
                    <span v-if="suggestedEmployeeNumber" class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        <span>Proposé selon le modèle <span class="font-mono text-foreground">{{ employeeNumberModel }}</span> — modifiable. Unique sur le site, archives comprises.</span>
                        <button v-if="form.employee_number !== suggestedEmployeeNumber" type="button" class="font-semibold text-primary hover:underline" @click="form.employee_number = suggestedEmployeeNumber">Reprendre {{ suggestedEmployeeNumber }}</button>
                    </span>
                    <span v-else class="mt-1.5 block text-xs text-muted-foreground">Unique sur le site, y compris dans les archives.</span>
                </FormField>
                <FormField as="div" label="Date d’entrée" :error="form.errors.hire_date">
                    <DatePicker id="hire_date" v-model="form.hire_date" aria-label="Date d’entrée" :invalid="invalid('hire_date')" />
                    <!-- ADR-197 — l'ancienneté se lit sur la date d'entrée, jamais saisie. -->
                    <span class="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                        <CalendarClock class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <template v-if="hireSeniority">Ancienneté : <strong class="font-semibold text-foreground">{{ hireSeniority.label }}</strong> (calculée)</template>
                        <template v-else>L’ancienneté se calcule depuis cette date.</template>
                    </span>
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
                    <p v-else class="mt-1.5 text-xs text-muted-foreground">
                        <template v-if="form.department_uuid">{{ allowedJobTitles.length }} fonction{{ allowedJobTitles.length > 1 ? 's' : '' }} pour {{ selectedDepartment }}.</template>
                        <template v-else>Choisissez un département pour ne voir que ses fonctions.</template>
                    </p>
                </FormField>
                <p v-if="clearedJobTitle" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-800 sm:col-span-2 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300">
                    <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />« {{ clearedJobTitle }} » n’existe pas dans {{ selectedDepartment }} : choisissez une fonction de ce département.
                </p>
            </div>
        </Card>

        <!-- Rémunération (ADR-197) — seulement avec employees.payroll.update. -->
        <Card v-else-if="currentKey === 'pay'" class="grid overflow-hidden lg:grid-cols-[300px_minmax(0,1fr)]">
            <aside class="border-b border-border bg-emerald-50/60 p-5 dark:bg-emerald-950/15 lg:border-b-0 lg:border-e">
                <span class="grid h-11 w-11 place-items-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"><Wallet class="h-5 w-5" /></span>
                <h2 class="mt-4 font-heading text-lg font-bold text-foreground">Rémunération et banque</h2>
                <p class="mt-2 text-sm leading-6 text-muted-foreground">Ce que la personne reçoit, et le compte où elle le reçoit. C’est une déclaration du RH : aucune paie, retenue ni net n’en est calculé.</p>
                <p class="mt-4 flex items-start gap-2 rounded-lg border border-border bg-card/80 p-3 text-xs leading-5 text-muted-foreground">
                    <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" aria-hidden="true" />
                    <span>Données confidentielles : seuls les comptes qui ont le droit de voir la rémunération les lisent, sur la fiche comme à l’impression.</span>
                </p>
            </aside>
            <div class="space-y-6 p-5 sm:p-6">
                <FormField as="div" label="Rémunération" :error="form.errors.remuneration_type">
                    <div id="remuneration_type" role="radiogroup" aria-label="Rémunération" tabindex="-1" class="grid gap-3 focus:outline-none sm:grid-cols-3">
                        <button
                            v-for="item in REMUNERATION_TYPES"
                            :key="item.value"
                            type="button"
                            role="radio"
                            :aria-checked="form.remuneration_type === item.value"
                            :class="cn(
                                'flex items-start gap-3 rounded-xl border p-3.5 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                form.remuneration_type === item.value ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border bg-card hover:border-primary/40 hover:bg-accent/50',
                            )"
                            @click="chooseRemuneration(item.value)"
                        >
                            <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-full', form.remuneration_type === item.value ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')">
                                <Check v-if="form.remuneration_type === item.value" class="h-4 w-4" :stroke-width="3" />
                                <component :is="item.icon" v-else class="h-4 w-4" />
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-foreground">{{ item.label }}</span>
                                <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">{{ item.hint }}</span>
                            </span>
                        </button>
                    </div>
                    <span class="mt-1.5 block text-xs text-muted-foreground">Facultatif ; un second clic retire le choix.</span>
                </FormField>

                <FormField v-if="hasAmount" class="block" :label="form.remuneration_type === 'SALARY' ? 'Salaire mensuel' : 'Indemnité mensuelle'" required :error="form.errors.remuneration_amount">
                    <div class="relative">
                        <IconInput id="remuneration_amount" v-model="form.remuneration_amount" :icon="Coins" inputmode="decimal" autocomplete="off" placeholder="Ex. 450 000" :aria-invalid="invalid('remuneration_amount')" :class="cn('pe-14 tabular-nums', fieldClass('remuneration_amount'))" />
                        <span class="pointer-events-none absolute inset-y-0 end-0 grid place-items-center pe-3 text-sm font-medium text-muted-foreground">{{ currencyLabel() }}</span>
                    </div>
                    <span class="mt-1.5 block text-xs text-muted-foreground">
                        <template v-if="amountNumber !== null">Soit <strong class="font-semibold text-foreground">{{ formatMoney(amountNumber) }}</strong> par mois.</template>
                        <template v-else>Montant brut par mois, en chiffres.</template>
                    </span>
                </FormField>

                <section class="rounded-xl border border-border bg-card p-4 shadow-sm" aria-labelledby="employee-bank-title">
                    <div class="mb-4 flex items-start gap-2.5">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-950/50 dark:text-sky-300"><Landmark class="h-4 w-4" /></span>
                        <div>
                            <h3 id="employee-bank-title" class="text-sm font-bold text-foreground">Compte bancaire</h3>
                            <p class="mt-0.5 text-xs text-muted-foreground">Le compte où la personne est payée ; le titulaire tel qu’il figure à la banque.</p>
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField label="Numéro de compte" :error="form.errors.bank_account_number">
                            <IconInput id="bank_account_number" v-model="form.bank_account_number" :icon="CreditCard" autocomplete="off" placeholder="Ex. 00005 00001 12345678901 23" :aria-invalid="invalid('bank_account_number')" :class="cn('font-mono uppercase', fieldClass('bank_account_number'))" />
                        </FormField>
                        <FormField label="Nom du titulaire" :required="Boolean(form.bank_account_number)" :error="form.errors.bank_account_holder">
                            <IconInput id="bank_account_holder" v-model="form.bank_account_holder" :icon="UserRound" autocomplete="off" placeholder="Nom sur le compte" :aria-invalid="invalid('bank_account_holder')" :class="fieldClass('bank_account_holder')" />
                            <button
                                v-if="typedName && form.bank_account_holder !== typedName.toUpperCase()"
                                type="button"
                                class="mt-1.5 text-xs font-semibold text-primary hover:underline"
                                @click="useNameAsHolder"
                            >Reprendre « {{ typedName.toUpperCase() }} »</button>
                        </FormField>
                    </div>
                </section>
            </div>
        </Card>

        <!-- 3 · Contact -->
        <Card v-else-if="currentKey === 'contact'" class="overflow-hidden">
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
                            <p class="text-[11px] text-muted-foreground">Téléphone et adresse de la personne.</p>
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField label="Téléphone" :error="form.errors.phone">
                            <IconInput id="phone" v-model="form.phone" :icon="Phone" type="tel" autocomplete="tel" :class="fieldClass('phone')" />
                        </FormField>
                        <div class="space-y-2">
                            <p class="text-sm font-medium text-foreground">Email</p>
                            <div class="flex min-h-10 items-start gap-2.5 rounded-lg border border-dashed border-border bg-muted/40 px-3 py-2">
                                <Mail class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                <p v-if="currentEmail" class="min-w-0 text-sm">
                                    <span class="block truncate font-medium text-foreground">{{ currentEmail }}</span>
                                    <span class="block text-xs text-muted-foreground">Posé par l’adresse professionnelle — il ne se modifie pas ici.</span>
                                </p>
                                <p v-else class="text-xs leading-5 text-muted-foreground">
                                    L’email d’un employé est son adresse professionnelle : elle se demande depuis sa fiche, une fois l’employé enregistré.
                                </p>
                            </div>
                            <p v-if="form.errors.email" class="text-xs font-medium text-destructive">{{ form.errors.email }}</p>
                        </div>
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
        <Card v-else-if="currentKey === 'more'" class="overflow-hidden">
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
                        <span :class="cn('mt-3 flex items-center gap-3', ! item.photo && 'block')">
                            <EmployeePhoto v-if="item.photo" :src="photoPreview" :name="typedName" size="md" />
                            <span class="min-w-0">
                                <strong :class="cn('block break-words text-base text-foreground', item.mono && 'font-mono')">{{ item.title }}</strong>
                                <span v-for="(line, index) in item.lines" :key="index" class="mt-1 block text-xs text-muted-foreground">{{ line }}</span>
                            </span>
                        </span>
                    </button>
                </div>
            </Card>
            <Card class="h-fit border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/20">
                <div class="p-5">
                    <span class="grid h-11 w-11 place-items-center rounded-full bg-emerald-600 text-white"><Check class="h-5 w-5" :stroke-width="3" /></span>
                    <h2 class="mt-4 font-heading text-lg font-bold text-emerald-900 dark:text-emerald-100">Ce qui sera automatisé</h2>
                    <ul class="mt-3 space-y-2.5 text-sm leading-5 text-emerald-800 dark:text-emerald-200">
                        <li class="flex gap-2"><CircleCheck class="mt-0.5 h-4 w-4 shrink-0" /><span>Civilité calculée depuis le genre.</span></li>
                        <li class="flex gap-2"><CircleCheck class="mt-0.5 h-4 w-4 shrink-0" /><span>Fonction vérifiée pour le département choisi.</span></li>
                        <li class="flex gap-2"><CircleCheck class="mt-0.5 h-4 w-4 shrink-0" /><span>Photo recadrée en 4 × 4 et gardée en privé.</span></li>
                        <li class="flex gap-2"><CircleCheck class="mt-0.5 h-4 w-4 shrink-0" /><span>Ancienneté calculée depuis la date d’entrée.</span></li>
                        <li class="flex gap-2"><CircleCheck class="mt-0.5 h-4 w-4 shrink-0" /><span>Nouvelle adresse ajoutée une seule fois au référentiel.</span></li>
                    </ul>
                    <p v-if="internshipIntent" class="mt-4 flex gap-2 rounded-lg bg-card p-3 text-xs leading-5 text-foreground ring-1 ring-border">
                        <GraduationCap class="mt-0.5 h-4 w-4 shrink-0 text-primary" />Après l’enregistrement, vous saisirez son stage : filière, école, encadrant et dates.
                    </p>
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
