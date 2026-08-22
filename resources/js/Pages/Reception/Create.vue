<script setup>
import { computed, reactive, ref, toRef } from 'vue';
import { Head, Link, router, useForm, usePage, useRemember } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Card from '@/Components/UI/Card.vue';
import CardBody from '@/Components/UI/CardBody.vue';
import BlockHead from '@/Components/UI/BlockHead.vue';
import BlockTitle from '@/Components/UI/BlockTitle.vue';
import BlockText from '@/Components/UI/BlockText.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormLabel from '@/Components/UI/FormLabel.vue';
import FormError from '@/Components/UI/FormError.vue';
import InputWrap from '@/Components/UI/InputWrap.vue';
import Input from '@/Components/UI/Input.vue';
import IconInput from '@/Components/UI/IconInput.vue';
import Radio from '@/Components/UI/Radio.vue';
import RadioButton from '@/Components/UI/RadioButton.vue';
import CheckBox from '@/Components/UI/CheckBox.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatDateTime, formatRelativeTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({
    layout: AppLayout,
});

const props = defineProps({
    step: String,
    search: String,
    matches: Array,
    recentEpisodes: Array,
    recentEpisodeFilter: String,
    billingCatalog: Array,
    paymentMethods: Array,
    openCashSession: Object,
});

const page = usePage();
const duplicates = computed(() => page.props.flash?.duplicates ?? null);
const { can } = usePermissions();
const canUpdatePatient = computed(() => can('patients.update'));

const steps = [
    { key: 'type', slug: 'type', label: 'Type', href: '/reception/patients/type' },
    { key: 'identity', slug: 'identite', label: 'Identité', href: '/reception/patients/identite' },
    { key: 'contact', slug: 'contact', label: 'Contact', href: '/reception/patients/contact' },
    { key: 'services', slug: 'prestations', label: 'Prestations', href: '/reception/patients/prestations' },
    { key: 'confirm', slug: 'confirmation', label: 'Confirmation', href: '/reception/patients/confirmation' },
];
const initialStepIndex = Math.max(steps.findIndex((step) => step.slug === props.step), 0);
const stepIndex = computed(() => Math.max(steps.findIndex((step) => step.slug === props.step), 0));
const currentStep = computed(() => steps[stepIndex.value].key);

const workflow = useRemember(reactive({
    patientType: props.search ? 'existing' : (initialStepIndex > 0 ? 'new' : null),
    selectedPatient: null,
    birthDateMode: 'date',
    furthestStepIndex: initialStepIndex,
}), 'ReceptionPatientArrivalWorkflow');
const patientType = toRef(workflow, 'patientType'); // 'existing' | 'new'
const selectedPatient = toRef(workflow, 'selectedPatient');
const birthDateMode = toRef(workflow, 'birthDateMode'); // 'date' | 'age'
const furthestStepIndex = toRef(workflow, 'furthestStepIndex');
const showArrivalFlow = computed(() => Boolean(props.step) || Boolean(duplicates.value?.length));
const query = ref(props.search ?? '');

const recentFilterOptions = [
    { value: 'all', label: 'Tous', icon: 'list' },
    { value: 'normal', label: 'Normal', icon: 'user-check' },
    { value: 'emergency', label: 'Urgence', icon: 'alert-circle' },
    { value: 'pending', label: 'À orienter', icon: 'clock' },
    { value: 'oriented', label: 'Orientés', icon: 'check-circle' },
];
const activeRecentFilter = computed(() => props.recentEpisodeFilter ?? 'all');
const hasActiveRecentFilter = computed(() => activeRecentFilter.value !== 'all');
const recentFilterHref = (value) => value === 'all'
    ? '/reception/patients'
    : `/reception/patients?filter=${value}`;

if (props.search) {
    patientType.value = 'existing';
    furthestStepIndex.value = Math.max(furthestStepIndex.value, 1);
}

// Only matters on a fresh mount (e.g. a hard refresh while duplicates are
// flashed) — the normal duplicate round-trip uses preserveState on the
// form.post() below, so the wizard's own state survives without this.
if (duplicates.value?.length > 0) {
    furthestStepIndex.value = 4;
    patientType.value = 'new';
}

furthestStepIndex.value = Math.max(furthestStepIndex.value, stepIndex.value);

const stepRequestData = (step) => (
    step.key === 'identity' && patientType.value === 'existing' && query.value
        ? { q: query.value }
        : {}
);

const stepHref = (step) => {
    const params = new URLSearchParams(stepRequestData(step));

    return params.size ? `${step.href}?${params.toString()}` : step.href;
};

const navigateToStep = (index, { replace = false } = {}) => {
    const step = steps[index];
    if (!step) return;

    furthestStepIndex.value = Math.max(furthestStepIndex.value, index);
    router.get(step.href, stepRequestData(step), {
        preserveState: true,
        preserveScroll: true,
        replace,
    });
};

const openStepperStep = (index) => {
    if (index > furthestStepIndex.value) return;

    navigateToStep(index);
};

const chooseType = (type) => {
    resetPatientForm();
    selectedPatient.value = null;
    patientType.value = type;
    query.value = '';
    navigateToStep(1);
};

const goBack = () => {
    if (currentStep.value === 'services' && patientType.value === 'existing' && !canUpdatePatient.value) {
        selectedPatient.value = null;
        navigateToStep(1);
        return;
    }

    if (currentStep.value === 'identity' && patientType.value === 'existing' && selectedPatient.value) {
        resetPatientForm();
        selectedPatient.value = null;
        return;
    }

    if (stepIndex.value > 0) {
        navigateToStep(stepIndex.value - 1);
    }
};

// --- Existing patient search ---
const submitSearch = () => {
    furthestStepIndex.value = Math.max(furthestStepIndex.value, 1);
    router.get('/reception/patients/identite', { q: query.value }, { preserveState: true, preserveScroll: true, replace: true });
};

const pickExistingPatient = (patient) => {
    selectedPatient.value = patient;

    if (canUpdatePatient.value) {
        hydratePatientForm(patient);
        return;
    }

    navigateToStep(3);
};

// --- New patient form ---
const form = useForm('ReceptionPatientArrivalForm', {
    first_name: '',
    last_name: '',
    birth_date: '',
    age: '',
    sex: 'M',
    civility: null,
    identity_document_type: null,
    identity_document_number: '',
    phone: '',
    email: '',
    address: '',
    emergency_contact_name: '',
    emergency_contact_phone: '',
    emergency_contact_email: '',
    emergency_contact_relationship: '',
    is_emergency: false,
    confirm_duplicate: false,
    defer_designation: false,
    catalog_lines: [],
    payment_choice: 'LATER',
    payment_method_id: props.paymentMethods?.[0]?.id ?? '',
    payment_reference: '',
});

const resetPatientForm = () => {
    const isEmergency = form.is_emergency;

    form.reset();
    form.clearErrors();
    form.is_emergency = isEmergency;
    birthDateMode.value = 'date';
};

const clearArrivalDraft = () => {
    form.reset();
    form.clearErrors();
    form.is_emergency = false;
    patientType.value = null;
    selectedPatient.value = null;
    birthDateMode.value = 'date';
    furthestStepIndex.value = 0;
    query.value = '';
};

const startArrivalFlow = () => {
    clearArrivalDraft();
    navigateToStep(0);
};

const showRecentPassages = () => {
    clearArrivalDraft();
    router.get('/reception/patients', {}, { preserveState: true, preserveScroll: true });
};

const hydratePatientForm = (patient) => {
    resetPatientForm();

    form.first_name = patient.first_name ?? '';
    form.last_name = patient.last_name ?? '';
    form.sex = patient.sex ?? 'M';
    form.civility = patient.civility ?? null;
    form.identity_document_type = patient.identity_document_type ?? null;
    form.identity_document_number = patient.identity_document_number ?? '';
    form.phone = patient.phone ?? '';
    form.email = patient.email ?? '';
    form.address = patient.address ?? '';
    form.emergency_contact_name = patient.emergency_contact_name ?? '';
    form.emergency_contact_phone = patient.emergency_contact_phone ?? '';
    form.emergency_contact_email = patient.emergency_contact_email ?? '';
    form.emergency_contact_relationship = patient.emergency_contact_relationship ?? '';

    if (patient.birth_date_is_approximate) {
        birthDateMode.value = 'age';
        form.age = patient.age ?? '';
        form.birth_date = '';
    } else {
        birthDateMode.value = 'date';
        form.birth_date = patient.birth_date ?? '';
        form.age = '';
    }
};

const setBirthDateMode = (mode) => {
    birthDateMode.value = mode;
    if (mode === 'date') {
        form.age = '';
    } else {
        form.birth_date = '';
    }
};

const civilityOptions = [
    { value: 'MR', label: 'M.', sex: 'M' },
    { value: 'MRS', label: 'Mme', sex: 'F' },
    { value: 'GIRL', label: 'Enfant fille', sex: 'F' },
    { value: 'BOY', label: 'Enfant garçon', sex: 'M' },
];

// Each civility implies a sex — choosing one sets it directly, but sex
// stays a normal editable field afterward in case it needs correcting.
const chooseCivility = (value) => {
    form.civility = value;
    form.sex = civilityOptions.find((option) => option.value === value)?.sex ?? form.sex;
};

const identityComplete = computed(() => form.last_name && form.sex && (form.birth_date || form.age));

const civilityLabel = computed(() => civilityOptions.find((option) => option.value === form.civility)?.label);

const recapUsesDeclaredAge = computed(() => {
    if (patientType.value === 'existing' && selectedPatient.value && !canUpdatePatient.value) {
        return Boolean(selectedPatient.value.birth_date_is_approximate);
    }

    return Boolean(form.age) && !form.birth_date;
});

const birthRecapLabel = computed(() => recapUsesDeclaredAge.value ? 'Âge' : 'Date de naissance');

const birthRecapValue = computed(() => {
    if (patientType.value === 'existing' && selectedPatient.value && !canUpdatePatient.value) {
        if (recapUsesDeclaredAge.value) {
            return selectedPatient.value.age ? `${selectedPatient.value.age} ans` : 'Non renseigné';
        }

        return selectedPatient.value.birth_date
            ? formatDate(selectedPatient.value.birth_date)
            : 'Non renseignée';
    }

    if (recapUsesDeclaredAge.value) {
        return form.age ? `${form.age} ans` : 'Non renseigné';
    }

    return form.birth_date ? formatDate(form.birth_date) : 'Non renseignée';
});

// --- Services and settlement intent ---
const serviceQuery = ref('');
const normalizedServiceQuery = computed(() => serviceQuery.value.trim().toLocaleLowerCase('fr'));
const selectedServiceUuids = computed(() => new Set(form.catalog_lines.map((line) => line.catalog_item_uuid)));
const catalogByUuid = computed(() => new Map((props.billingCatalog ?? []).map((item) => [item.uuid, item])));
const filteredCatalog = computed(() => (props.billingCatalog ?? [])
    .filter((item) => {
        if (selectedServiceUuids.value.has(item.uuid)) return false;
        if (!normalizedServiceQuery.value) return true;

        return `${item.code} ${item.name} ${item.module_label}`
            .toLocaleLowerCase('fr')
            .includes(normalizedServiceQuery.value);
    })
    .slice(0, 12));
const selectedServices = computed(() => form.catalog_lines.map((line, index) => ({
    formLine: line,
    index,
    item: catalogByUuid.value.get(line.catalog_item_uuid),
})).filter((line) => line.item));
const arrivalTotal = computed(() => selectedServices.value.reduce(
    (total, line) => total + Number(line.formLine.quantity || 0) * Number(line.item.tariff_amount || 0),
    0,
));
const canPrepareBilling = computed(() => can('billing.create') && can('billing.validate'));
const canPayNow = computed(() => can('payments.create') && Boolean(props.openCashSession) && (props.paymentMethods?.length ?? 0) > 0);
const servicesComplete = computed(() => selectedServices.value.length > 0
    || form.defer_designation
    || form.is_emergency
    || !canPrepareBilling.value);

const addService = (item) => {
    if (selectedServiceUuids.value.has(item.uuid)) return;

    form.catalog_lines.push({ catalog_item_uuid: item.uuid, quantity: 1 });
    form.defer_designation = false;
    serviceQuery.value = '';
};

const removeService = (index) => form.catalog_lines.splice(index, 1);

const choosePayment = (choice) => {
    if (choice === 'NOW' && !canPayNow.value) return;

    form.payment_choice = choice;
};

const continueToConfirmation = () => {
    if (!servicesComplete.value) return;

    if (!selectedServices.value.length) {
        form.payment_choice = 'LATER';
        form.payment_method_id = '';
        form.payment_reference = '';
    } else if (form.payment_choice === 'NOW' && !canPayNow.value) {
        form.payment_choice = 'LATER';
    }

    navigateToStep(4);
};

const arrivalBillingPayload = (data) => ({
    defer_designation: data.defer_designation,
    catalog_lines: data.catalog_lines,
    payment_choice: data.catalog_lines.length ? data.payment_choice : null,
    payment_method_id: data.catalog_lines.length && data.payment_choice === 'NOW' ? data.payment_method_id : null,
    payment_reference: data.catalog_lines.length && data.payment_choice === 'NOW' ? data.payment_reference : null,
});

// --- Final submission (step 5) ---
const returnToInvalidStep = (errors) => {
    const contactFields = [
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_email',
        'emergency_contact_relationship',
    ];
    const fields = Object.keys(errors);

    if (fields.some((field) => field.startsWith('catalog_lines') || field.startsWith('payment_'))) {
        navigateToStep(3, { replace: true });
        return;
    }

    navigateToStep(fields.some((field) => contactFields.includes(field)) ? 2 : 1, { replace: true });
};

const confirmArrival = () => {
    if (patientType.value === 'existing') {
        form.transform((data) => canUpdatePatient.value
            ? {
                ...data,
                patient_uuid: selectedPatient.value.uuid,
                update_patient: true,
            }
            : {
                patient_uuid: selectedPatient.value.uuid,
                is_emergency: data.is_emergency,
                ...arrivalBillingPayload(data),
            }
        ).post('/reception/patients', {
            preserveState: true,
            preserveScroll: true,
            onError: returnToInvalidStep,
            onSuccess: clearArrivalDraft,
        });
        return;
    }

    form.transform((data) => data).post('/reception/patients', {
        preserveState: true,
        preserveScroll: true,
        onError: returnToInvalidStep,
        onSuccess: clearArrivalDraft,
    });
};

const confirmDespiteDuplicate = () => {
    form.confirm_duplicate = true;
    form.transform((data) => data).post('/reception/patients', {
        preserveState: true,
        preserveScroll: true,
        onSuccess: clearArrivalDraft,
    });
};

const statusLabels = {
    OPEN: 'Ouvert',
    CLOSED: 'Clos',
    CANCELLED: 'Annulé',
};

const administrativeStatusLabels = {
    PENDING_ORIENTATION: 'En attente aux Soins',
    ORIENTED: 'Orienté',
    IN_CARE: 'En cours de soins',
    PENDING_SETTLEMENT: 'En attente de règlement',
    DISCHARGED: 'Sorti',
};

const statusBadgeClasses = {
    OPEN: 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300',
    CLOSED: 'bg-slate-100 text-slate-600 dark:bg-gray-900 dark:text-slate-300',
    CANCELLED: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
};

const statusBadgeClass = (status) => statusBadgeClasses[status] ?? statusBadgeClasses.CLOSED;
const pathwayStatus = (episode) => {
    const orientations = episode.orientations ?? [];
    const medicine = orientations.find((orientation) => orientation.destination_module === 'MEDICINE'
        && ['PENDING', 'IN_PROGRESS'].includes(orientation.status));
    const care = orientations.find((orientation) => orientation.destination_module === 'CARE'
        && ['PENDING', 'IN_PROGRESS'].includes(orientation.status));

    if (medicine?.status === 'IN_PROGRESS') return 'En consultation';
    if (medicine) return 'En attente en Médecine';
    if (care?.status === 'IN_PROGRESS') return 'Pris en charge aux Soins';
    if (care) return 'En attente aux Soins';

    return administrativeStatusLabels[episode.administrative_status] ?? episode.administrative_status;
};
const administrativeStatusBadgeClass = (status) => ({
    PENDING_ORIENTATION: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300',
    ORIENTED: 'border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950/40 dark:text-green-300',
    IN_CARE: 'border-gray-200 bg-gray-50 text-slate-600 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-300',
    PENDING_SETTLEMENT: 'border-gray-200 bg-gray-50 text-slate-600 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-300',
    DISCHARGED: 'border-gray-200 bg-gray-50 text-slate-500 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-400',
}[status] ?? 'border-gray-200 bg-gray-50 text-slate-500 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-400');
</script>

<template>
    <Head title="Réception patient" />

    <div
        :class="[
            'mx-auto w-full',
            showArrivalFlow ? 'max-w-screen-xl space-y-6 lg:space-y-8' : 'max-w-screen-2xl space-y-4',
        ]"
    >
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                    <Icon class="text-2xl" name="user-add" />
                </span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-slate-700 dark:text-white">
                        Réception patient
                    </h1>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-400">
                        Retrouvez un patient ou créez son dossier, puis enregistrez son passage.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <Button :as="Link" href="/reception" class="justify-center" size="rg" variant="white-outline">
                    <Icon class="text-lg/4.5" name="arrow-left" />
                    <span class="ms-2">Accueil réception</span>
                </Button>
                <span class="inline-flex w-fit items-center gap-2 rounded-full bg-gray-100 px-3 py-1.5 text-xs font-medium text-slate-500 dark:bg-gray-900 dark:text-slate-300">
                    <Icon class="text-base text-primary-500" name="activity" />
                    {{ recentEpisodes.length }} passage{{ recentEpisodes.length > 1 ? 's' : '' }} affiché{{ recentEpisodes.length > 1 ? 's' : '' }}
                </span>
                <Button
                    class="justify-center"
                    size="rg"
                    :variant="showArrivalFlow ? 'white-outline' : 'primary'"
                    type="button"
                    @click="showArrivalFlow ? showRecentPassages() : startArrivalFlow()"
                >
                    <Icon class="text-lg/4.5" :name="showArrivalFlow ? 'arrow-left' : 'user-add'" />
                    <span class="ms-2">{{ showArrivalFlow ? 'Retour aux passages' : 'Nouvelle arrivée' }}</span>
                </Button>
            </div>
        </header>

        <Card v-if="showArrivalFlow" class="overflow-hidden shadow-sm">
            <!-- Stepper -->
            <nav aria-label="Progression de l'enregistrement" class="border-b border-gray-200 bg-gray-50/70 px-4 py-3 dark:border-gray-900 dark:bg-gray-1000/40 sm:px-6">
                <ol class="flex items-center" role="list">
                    <li v-for="(step, index) in steps" :key="step.key" class="flex flex-1 items-center last:flex-none">
                        <a
                            :href="stepHref(step)"
                            :aria-current="index === stepIndex ? 'step' : undefined"
                            :aria-disabled="index > furthestStepIndex ? 'true' : undefined"
                            :tabindex="index > furthestStepIndex ? -1 : undefined"
                            :class="[
                                'flex items-center gap-3 rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300',
                                index > furthestStepIndex ? 'cursor-not-allowed' : 'cursor-pointer',
                            ]"
                            @click.prevent="openStepperStep(index)"
                        >
                            <span
                                :class="[
                                    'flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full text-xs font-bold transition-all duration-300',
                                    index < stepIndex ? 'bg-primary-600 text-white' : '',
                                    index === stepIndex ? 'bg-primary-600 text-white ring-4 ring-primary-100 dark:ring-primary-950' : '',
                                    index > stepIndex ? 'bg-gray-100 text-slate-400 dark:bg-gray-900' : '',
                                ]"
                            >
                                <Icon v-if="index < stepIndex" class="text-sm" name="check" />
                                <span v-else>{{ index + 1 }}</span>
                            </span>
                            <span
                                :class="[
                                    'hidden text-sm font-bold sm:inline',
                                    index <= stepIndex ? 'text-slate-700 dark:text-white' : 'text-slate-400',
                                ]"
                            >
                                {{ step.label }}
                            </span>
                        </a>
                        <div
                            v-if="index < steps.length - 1"
                            :class="[
                                'mx-4 h-0.5 flex-1 rounded transition-all duration-300',
                                index < stepIndex ? 'bg-primary-600' : 'bg-gray-200 dark:bg-gray-900',
                            ]"
                        ></div>
                    </li>
                </ol>
            </nav>

            <CardBody class="!p-4 sm:!p-5 lg:!p-6">
                <div
                    v-if="form.is_emergency && currentStep !== 'type'"
                    class="mb-6 flex items-start gap-3 rounded-md border border-red-300 bg-red-50 p-4 text-red-800 dark:border-red-900 dark:bg-red-950/60 dark:text-red-200"
                    role="status"
                >
                    <Icon class="mt-0.5 shrink-0 text-xl" name="alert-circle" />
                    <div>
                        <p class="text-sm font-bold uppercase tracking-wide">Urgence médicale</p>
                        <p class="mt-0.5 text-xs leading-5 opacity-80">Le passage sera prioritaire et immédiatement orienté vers Médecine / Soins.</p>
                    </div>
                </div>

                <Transition
                    mode="out-in"
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="opacity-0 translate-x-2"
                    enter-to-class="opacity-100 translate-x-0"
                    leave-active-class="transition duration-150 ease-in"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <!-- Step 1: type -->
                    <div v-if="currentStep === 'type'" key="type">
                        <BlockHead class="!pb-4">
                            <BlockTitle as="h2">Qui se présente ?</BlockTitle>
                            <BlockText>Choisissez si ce patient est déjà connu de la clinique.</BlockText>
                        </BlockHead>

                        <div
                            :class="[
                                'mb-4 rounded-md border px-4 py-3 transition-colors',
                                form.is_emergency
                                    ? 'border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950/60'
                                    : 'border-gray-200 bg-gray-50/70 dark:border-gray-800 dark:bg-gray-1000/40',
                            ]"
                        >
                            <div class="flex items-start gap-2.5">
                                <span :class="['mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full', form.is_emergency ? 'bg-red-100 text-red-600 dark:bg-red-900/70 dark:text-red-300' : 'bg-white text-slate-400 dark:bg-gray-900']">
                                    <Icon class="text-lg" name="alert-circle" />
                                </span>
                                <div>
                                    <CheckBox id="is_emergency" v-model="form.is_emergency" name="is_emergency" size="sm">
                                        <span :class="['font-bold', form.is_emergency ? 'text-red-700 dark:text-red-200' : 'text-slate-700 dark:text-white']">
                                            Admission en urgence
                                        </span>
                                    </CheckBox>
                                    <p class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">
                                        À cocher si le patient part directement vers Médecine / Soins pendant que la famille complète ce dossier normal.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <button
                                type="button"
                                class="group relative flex min-h-40 flex-col items-start gap-2.5 rounded-md border-2 border-gray-200 p-5 text-start transition-all duration-300 hover:-translate-y-0.5 hover:border-primary-400 hover:bg-primary-50/50 hover:shadow-sm focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-primary-100 dark:border-gray-800 dark:hover:border-primary-700 dark:hover:bg-primary-950/30 dark:focus-visible:ring-primary-950"
                                @click="chooseType('existing')"
                            >
                                <Icon class="absolute end-5 top-5 text-xl text-slate-300 transition-transform duration-300 group-hover:translate-x-1 group-hover:text-primary-500" name="arrow-right" />
                                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-100 text-primary-600 dark:bg-primary-950">
                                    <Icon class="text-xl" name="search" />
                                </span>
                                <span class="font-heading text-base font-bold text-slate-700 dark:text-white">Patient existant</span>
                                <span class="max-w-sm text-sm leading-5 text-slate-400">Rechercher un dossier déjà enregistré et créer un passage.</span>
                            </button>

                            <button
                                type="button"
                                class="group relative flex min-h-40 flex-col items-start gap-2.5 rounded-md border-2 border-gray-200 p-5 text-start transition-all duration-300 hover:-translate-y-0.5 hover:border-primary-400 hover:bg-primary-50/50 hover:shadow-sm focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-primary-100 dark:border-gray-800 dark:hover:border-primary-700 dark:hover:bg-primary-950/30 dark:focus-visible:ring-primary-950"
                                @click="chooseType('new')"
                            >
                                <Icon class="absolute end-5 top-5 text-xl text-slate-300 transition-transform duration-300 group-hover:translate-x-1 group-hover:text-primary-500" name="arrow-right" />
                                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-100 text-primary-600 dark:bg-primary-950">
                                    <Icon class="text-xl" name="user-add" />
                                </span>
                                <span class="font-heading text-base font-bold text-slate-700 dark:text-white">Nouveau patient</span>
                                <span class="max-w-sm text-sm leading-5 text-slate-400">Créer son dossier administratif.</span>
                            </button>
                        </div>
                    </div>

                    <!-- Step 2a: search existing -->
                    <div v-else-if="currentStep === 'identity' && patientType === 'existing' && !selectedPatient" key="search">
                        <BlockHead>
                            <BlockTitle as="h2">Rechercher le patient</BlockTitle>
                            <BlockText>Par nom, numéro patient ou téléphone.</BlockText>
                        </BlockHead>

                        <form class="flex max-w-2xl flex-col gap-3 sm:flex-row" role="search" @submit.prevent="submitSearch">
                            <FormGroup class="!mb-0 flex-1">
                                <FormLabel class="sr-only" for="patient_search">Rechercher un patient</FormLabel>
                                <IconInput
                                    id="patient_search"
                                    v-model="query"
                                    icon="search"
                                    type="search"
                                    placeholder="Nom, numéro patient ou téléphone"
                                    autocomplete="off"
                                    autofocus
                                />
                            </FormGroup>
                            <Button class="justify-center" size="rg" variant="primary" type="submit">
                                Rechercher
                            </Button>
                        </form>

                        <div v-if="search" class="mt-5">
                            <div v-if="matches.length > 0">
                                <p class="mb-2 text-xs font-medium text-slate-400">
                                    {{ matches.length }} résultat{{ matches.length > 1 ? 's' : '' }} trouvé{{ matches.length > 1 ? 's' : '' }}
                                </p>
                                <ul class="divide-y divide-gray-200 overflow-hidden rounded-md border border-gray-200 dark:divide-gray-900 dark:border-gray-800">
                                    <li v-for="match in matches" :key="match.uuid" class="flex flex-col gap-3 bg-white p-4 transition-colors hover:bg-gray-50 dark:bg-gray-950 dark:hover:bg-gray-1000 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="min-w-0">
                                        <p class="text-sm font-medium text-slate-700 dark:text-white">
                                            {{ formatPatientName(match) }}
                                            <span class="ms-2 text-xs font-normal text-slate-400">{{ match.patient_number }}</span>
                                        </p>
                                        <p class="mt-0.5 text-xs text-slate-400">
                                            Né(e) le {{ formatDate(match.birth_date) }} <span v-if="match.phone">· {{ match.phone }}</span>
                                        </p>
                                        </div>
                                        <Button class="justify-center sm:shrink-0" size="sm" type="button" variant="primary" @click="pickExistingPatient(match)">
                                            Choisir ce patient
                                        </Button>
                                    </li>
                                </ul>
                            </div>

                            <div v-else class="py-6 text-center">
                                <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-slate-400 dark:bg-gray-900">
                                    <Icon class="text-xl" name="search" />
                                </span>
                                <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-200">Aucun patient trouvé pour « {{ search }} ».</p>
                                <p class="mt-1 text-xs text-slate-400">Vérifiez la saisie ou créez un nouveau dossier.</p>
                                <Button class="mt-4" size="sm" type="button" variant="white-outline" @click="chooseType('new')">
                                    <Icon class="text-lg/4.5" name="user-add" />
                                    <span class="ms-2">Créer un nouveau dossier</span>
                                </Button>
                            </div>
                        </div>

                        <div class="mt-8 border-t border-gray-200 pt-6 dark:border-gray-900">
                            <Button class="w-full justify-center sm:w-auto" size="rg" variant="white-outline" type="button" @click="goBack">
                                <Icon class="text-lg/4.5" name="arrow-left" />
                                <span class="ms-2">Retour</span>
                            </Button>
                        </div>
                    </div>

                    <!-- Step 2b: create or update the patient identity -->
                    <form
                        v-else-if="currentStep === 'identity' && (patientType === 'new' || (patientType === 'existing' && selectedPatient && canUpdatePatient))"
                        :key="patientType === 'existing' ? 'existing-identity' : 'new-identity'"
                        @submit.prevent="navigateToStep(2)"
                    >
                        <BlockHead>
                            <BlockTitle as="h2">{{ patientType === 'existing' ? 'Mettre à jour le patient' : 'Identité du patient' }}</BlockTitle>
                            <BlockText>
                                <template v-if="patientType === 'existing'">
                                    Vérifiez et corrigez le dossier {{ selectedPatient.patient_number }} avant de créer son passage.
                                </template>
                                <template v-else>Ces informations forment son dossier permanent.</template>
                                <span class="text-red-500">*</span> champ obligatoire.
                            </BlockText>
                        </BlockHead>

                        <!-- Identité -->
                        <section class="border-b border-gray-200 pb-7 dark:border-gray-900">
                            <div class="mb-5 flex items-start gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                                    <Icon class="text-lg" name="user" />
                                </span>
                                <div>
                                    <h3 class="text-sm font-bold text-slate-700 dark:text-white">Identité administrative</h3>
                                    <p class="mt-0.5 text-xs leading-5 text-slate-400">Nom, civilité et document d’identité du patient.</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-5 md:grid-cols-[minmax(150px,0.45fr)_minmax(0,1fr)_minmax(0,1fr)]">
                                <FormGroup class="!mb-0">
                                    <FormLabel class="mb-1.5" for="civility">Civilité</FormLabel>
                                    <div class="relative">
                                        <select
                                            id="civility"
                                            name="civility"
                                            autocomplete="honorific-prefix"
                                            class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm leading-4.5 text-slate-700 outline-none transition-all focus:z-10 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:ring-offset-0 dark:border-gray-800 dark:bg-gray-950 dark:text-white focus:dark:border-primary-600 focus:dark:ring-primary-950"
                                            :value="form.civility ?? ''"
                                            :aria-invalid="Boolean(form.errors.civility)"
                                            aria-describedby="civility_help"
                                            @change="chooseCivility($event.target.value || null)"
                                        >
                                            <option value="">Choisir</option>
                                            <option v-for="option in civilityOptions" :key="option.value" :value="option.value">
                                                {{ option.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <FormError v-if="form.errors.civility">{{ form.errors.civility }}</FormError>
                                </FormGroup>

                                <FormGroup class="!mb-0">
                                    <FormLabel class="mb-1.5" for="last_name">Nom <span class="text-red-500">*</span></FormLabel>
                                    <IconInput
                                        id="last_name"
                                        v-model="form.last_name"
                                        icon="user"
                                        autocomplete="off"
                                        required
                                        :aria-invalid="Boolean(form.errors.last_name)"
                                        :aria-describedby="form.errors.last_name ? 'last_name_error' : undefined"
                                    />
                                    <FormError v-if="form.errors.last_name" id="last_name_error">{{ form.errors.last_name }}</FormError>
                                </FormGroup>

                                <FormGroup class="!mb-0">
                                    <FormLabel class="mb-1.5" for="first_name">Prénom(s)</FormLabel>
                                    <IconInput
                                        id="first_name"
                                        v-model="form.first_name"
                                        icon="user"
                                        autocomplete="off"
                                        :aria-invalid="Boolean(form.errors.first_name)"
                                        :aria-describedby="form.errors.first_name ? 'first_name_error' : undefined"
                                    />
                                    <FormError v-if="form.errors.first_name" id="first_name_error">{{ form.errors.first_name }}</FormError>
                                </FormGroup>
                            </div>
                            <p id="civility_help" class="mt-2 text-xs text-slate-400">La civilité renseigne aussi le sexe, qui reste modifiable.</p>

                            <fieldset class="mt-5">
                                <legend class="mb-3 text-sm font-medium text-slate-700 dark:text-white">Sexe <span class="text-red-500">*</span></legend>
                                <div class="flex min-h-6 flex-wrap items-center gap-x-6 gap-y-3">
                                    <Radio id="sex-m" v-model="form.sex" name="sex" value="M">Masculin</Radio>
                                    <Radio id="sex-f" v-model="form.sex" name="sex" value="F">Féminin</Radio>
                                </div>
                                <FormError v-if="form.errors.sex">{{ form.errors.sex }}</FormError>
                            </fieldset>

                            <div class="mt-6 grid grid-cols-1 gap-7 border-t border-gray-200 pt-6 dark:border-gray-900 lg:grid-cols-2 lg:gap-8">
                                <fieldset class="min-w-0">
                                    <legend class="sr-only">Pièce d’identité facultative</legend>
                                    <div class="mb-4 flex items-start gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                                            <Icon class="text-lg" name="cards" />
                                        </span>
                                        <div>
                                            <h3 class="text-sm font-bold text-slate-700 dark:text-white">
                                                Pièce d’identité <span class="text-xs font-normal text-slate-400">(facultatif)</span>
                                            </h3>
                                            <p class="mt-0.5 text-xs leading-5 text-slate-400">Document présenté lors de l’admission.</p>
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <RadioButton
                                            id="doc-cin"
                                            nocontrol
                                            name="identity_document_type"
                                            v-model="form.identity_document_type"
                                            value="CIN"
                                        >
                                            CIN
                                        </RadioButton>
                                        <RadioButton
                                            id="doc-passport"
                                            nocontrol
                                            name="identity_document_type"
                                            v-model="form.identity_document_type"
                                            value="PASSPORT"
                                        >
                                            Passeport
                                        </RadioButton>
                                        <button
                                            v-if="form.identity_document_type"
                                            type="button"
                                            class="text-xs text-slate-400 underline hover:text-slate-600 dark:hover:text-slate-200"
                                            @click="form.identity_document_type = null; form.identity_document_number = ''"
                                        >
                                            Effacer
                                        </button>
                                        <div class="min-w-[180px] flex-1">
                                            <IconInput
                                                id="identity_document_number"
                                                v-model="form.identity_document_number"
                                                icon="cards"
                                                :disabled="!form.identity_document_type"
                                                placeholder="Numéro du document"
                                                autocomplete="off"
                                                :aria-invalid="Boolean(form.errors.identity_document_number)"
                                                :aria-describedby="form.errors.identity_document_number ? 'identity_document_number_error' : undefined"
                                            />
                                        </div>
                                    </div>
                                    <FormError v-if="form.errors.identity_document_type">{{ form.errors.identity_document_type }}</FormError>
                                    <FormError v-if="form.errors.identity_document_number" id="identity_document_number_error">{{ form.errors.identity_document_number }}</FormError>
                                </fieldset>

                                <fieldset class="min-w-0">
                                    <legend class="sr-only">Naissance</legend>
                                    <div class="mb-4 flex items-start gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                                            <Icon class="text-lg" name="calendar" />
                                        </span>
                                        <div>
                                            <h3 class="text-sm font-bold text-slate-700 dark:text-white">Naissance <span class="text-red-500">*</span></h3>
                                            <p class="mt-0.5 text-xs leading-5 text-slate-400">Saisissez la date exacte ou l’âge déclaré si elle est inconnue.</p>
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap items-start gap-2">
                                        <RadioButton
                                            id="birth-mode-date"
                                            nocontrol
                                            name="birth_mode"
                                            :model-value="birthDateMode"
                                            value="date"
                                            @update:model-value="setBirthDateMode"
                                        >
                                            Date de naissance
                                        </RadioButton>
                                        <RadioButton
                                            id="birth-mode-age"
                                            nocontrol
                                            name="birth_mode"
                                            :model-value="birthDateMode"
                                            value="age"
                                            @update:model-value="setBirthDateMode"
                                        >
                                            Âge
                                        </RadioButton>

                                        <FormGroup v-if="birthDateMode === 'date'" class="!mb-0 min-w-[180px] flex-1">
                                            <FormLabel class="sr-only" for="birth_date">Date de naissance</FormLabel>
                                            <InputWrap>
                                                <Input
                                                    id="birth_date"
                                                    v-model="form.birth_date"
                                                    type="date"
                                                    required
                                                    :aria-invalid="Boolean(form.errors.birth_date)"
                                                    :aria-describedby="form.errors.birth_date ? 'birth_date_error' : undefined"
                                                />
                                            </InputWrap>
                                            <FormError v-if="form.errors.birth_date" id="birth_date_error">{{ form.errors.birth_date }}</FormError>
                                        </FormGroup>

                                        <FormGroup v-else class="!mb-0 min-w-[180px] flex-1">
                                            <FormLabel class="sr-only" for="age">Âge en années</FormLabel>
                                            <InputWrap>
                                                <Input
                                                    id="age"
                                                    v-model="form.age"
                                                    type="number"
                                                    min="0"
                                                    max="130"
                                                    placeholder="Âge en années"
                                                    required
                                                    :aria-invalid="Boolean(form.errors.age)"
                                                    :aria-describedby="form.errors.age ? 'age_error' : 'age_help'"
                                                />
                                            </InputWrap>
                                            <p id="age_help" class="mt-1 text-xs text-slate-400">
                                                Une date de naissance approximative sera enregistrée.
                                            </p>
                                            <FormError v-if="form.errors.age" id="age_error">{{ form.errors.age }}</FormError>
                                        </FormGroup>
                                    </div>
                                </fieldset>
                            </div>
                        </section>

                        <!-- Coordonnées -->
                        <section class="pt-7">
                            <div class="mb-5 flex items-start gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                                    <Icon class="text-lg" name="call" />
                                </span>
                                <div>
                                    <h3 class="text-sm font-bold text-slate-700 dark:text-white">Coordonnées</h3>
                                    <p class="mt-0.5 text-xs leading-5 text-slate-400">Moyens de contact et adresse du patient.</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <FormGroup class="!mb-0">
                                    <FormLabel class="mb-1.5" for="phone">Téléphone</FormLabel>
                                    <IconInput
                                        id="phone"
                                        v-model="form.phone"
                                        icon="call"
                                        type="tel"
                                        inputmode="tel"
                                        autocomplete="off"
                                        :aria-invalid="Boolean(form.errors.phone)"
                                        :aria-describedby="form.errors.phone ? 'phone_error' : undefined"
                                    />
                                    <FormError v-if="form.errors.phone" id="phone_error">{{ form.errors.phone }}</FormError>
                                </FormGroup>

                                <FormGroup class="!mb-0">
                                    <FormLabel class="mb-1.5" for="email">Email</FormLabel>
                                    <IconInput
                                        id="email"
                                        v-model="form.email"
                                        icon="mail"
                                        type="email"
                                        inputmode="email"
                                        autocomplete="off"
                                        :aria-invalid="Boolean(form.errors.email)"
                                        :aria-describedby="form.errors.email ? 'email_error' : undefined"
                                    />
                                    <FormError v-if="form.errors.email" id="email_error">{{ form.errors.email }}</FormError>
                                </FormGroup>

                                <FormGroup class="!mb-0 sm:col-span-2">
                                    <FormLabel class="mb-1.5" for="address">Adresse</FormLabel>
                                    <textarea
                                        id="address"
                                        v-model="form.address"
                                        rows="2"
                                        :aria-invalid="Boolean(form.errors.address)"
                                        :aria-describedby="form.errors.address ? 'address_error' : undefined"
                                        class="block min-h-20 w-full resize-y box-border rounded border border-gray-200 bg-white px-4 py-2 text-sm leading-5 text-slate-700 outline-none transition-all placeholder-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:ring-offset-0 aria-[invalid=true]:border-red-400 aria-[invalid=true]:focus:border-red-500 aria-[invalid=true]:focus:ring-red-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:border-primary-600 dark:focus:ring-primary-950 aria-[invalid=true]:focus:dark:ring-red-950"
                                    ></textarea>
                                    <FormError v-if="form.errors.address" id="address_error">{{ form.errors.address }}</FormError>
                                </FormGroup>
                            </div>
                        </section>

                        <div class="mt-7 flex flex-col-reverse gap-3 border-t border-gray-200 pt-6 dark:border-gray-900 sm:flex-row sm:justify-between">
                            <Button class="w-full justify-center sm:w-auto" size="rg" variant="white-outline" type="button" @click="goBack">
                                <Icon class="text-lg/4.5" name="arrow-left" />
                                <span class="ms-2">{{ patientType === 'existing' ? 'Changer de patient' : 'Retour' }}</span>
                            </Button>
                            <Button class="w-full justify-center sm:w-auto" size="rg" variant="primary" type="submit" :disabled="!identityComplete">
                                <span class="me-2">Continuer</span>
                                <Icon class="text-lg/4.5" name="arrow-right" />
                            </Button>
                        </div>
                    </form>

                    <!-- Step 3: emergency contact -->
                    <form
                        v-else-if="currentStep === 'contact' && (patientType === 'new' || (patientType === 'existing' && canUpdatePatient))"
                        key="contact"
                        @submit.prevent="navigateToStep(3)"
                    >
                        <BlockHead>
                            <div class="mb-4 flex h-11 w-11 items-center justify-center rounded-full bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                                <Icon class="text-xl" name="contact" />
                            </div>
                            <BlockTitle as="h2">Personne à contacter</BlockTitle>
                            <BlockText>
                                {{ patientType === 'existing' ? 'Vérifiez les coordonnées du proche à joindre si nécessaire.' : 'Ajoutez les coordonnées d’un proche à joindre si nécessaire.' }}
                                Cette étape est facultative.
                            </BlockText>
                        </BlockHead>

                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="emergency_contact_name">Nom</FormLabel>
                                <IconInput
                                    id="emergency_contact_name"
                                    v-model="form.emergency_contact_name"
                                    icon="user"
                                    autocomplete="off"
                                    :aria-invalid="Boolean(form.errors.emergency_contact_name)"
                                    :aria-describedby="form.errors.emergency_contact_name ? 'emergency_contact_name_error' : undefined"
                                />
                                <FormError v-if="form.errors.emergency_contact_name" id="emergency_contact_name_error">{{ form.errors.emergency_contact_name }}</FormError>
                            </FormGroup>

                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="emergency_contact_relationship">Lien de parenté</FormLabel>
                                <InputWrap>
                                    <Input
                                        id="emergency_contact_relationship"
                                        v-model="form.emergency_contact_relationship"
                                        autocomplete="off"
                                        :aria-invalid="Boolean(form.errors.emergency_contact_relationship)"
                                        :aria-describedby="form.errors.emergency_contact_relationship ? 'emergency_contact_relationship_error' : undefined"
                                    />
                                </InputWrap>
                                <FormError v-if="form.errors.emergency_contact_relationship" id="emergency_contact_relationship_error">{{ form.errors.emergency_contact_relationship }}</FormError>
                            </FormGroup>

                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="emergency_contact_phone">Téléphone</FormLabel>
                                <IconInput
                                    id="emergency_contact_phone"
                                    v-model="form.emergency_contact_phone"
                                    icon="call"
                                    type="tel"
                                    inputmode="tel"
                                    autocomplete="off"
                                    :aria-invalid="Boolean(form.errors.emergency_contact_phone)"
                                    :aria-describedby="form.errors.emergency_contact_phone ? 'emergency_contact_phone_error' : undefined"
                                />
                                <FormError v-if="form.errors.emergency_contact_phone" id="emergency_contact_phone_error">{{ form.errors.emergency_contact_phone }}</FormError>
                            </FormGroup>

                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="emergency_contact_email">Email</FormLabel>
                                <IconInput
                                    id="emergency_contact_email"
                                    v-model="form.emergency_contact_email"
                                    icon="mail"
                                    type="email"
                                    inputmode="email"
                                    autocomplete="off"
                                    :aria-invalid="Boolean(form.errors.emergency_contact_email)"
                                    :aria-describedby="form.errors.emergency_contact_email ? 'emergency_contact_email_error' : undefined"
                                />
                                <FormError v-if="form.errors.emergency_contact_email" id="emergency_contact_email_error">{{ form.errors.emergency_contact_email }}</FormError>
                            </FormGroup>
                        </div>

                        <div class="mt-8 flex flex-col-reverse gap-3 border-t border-gray-200 pt-6 dark:border-gray-900 sm:flex-row sm:justify-between">
                            <Button class="w-full justify-center sm:w-auto" size="rg" variant="white-outline" type="button" @click="goBack">
                                <Icon class="text-lg/4.5" name="arrow-left" />
                                <span class="ms-2">Retour</span>
                            </Button>
                            <Button class="w-full justify-center sm:w-auto" size="rg" variant="primary" type="submit">
                                <span class="me-2">Continuer</span>
                                <Icon class="text-lg/4.5" name="arrow-right" />
                            </Button>
                        </div>
                    </form>

                    <!-- Step 4: services and payment choice -->
                    <div v-else-if="currentStep === 'services'" key="services">
                        <BlockHead class="!pb-4">
                            <BlockTitle as="h2">Que vient faire le patient ?</BlockTitle>
                            <BlockText>Sélectionnez les prestations demandées. Le tarif affiché vient directement du référentiel du site.</BlockText>
                        </BlockHead>

                        <template v-if="canPrepareBilling">
                            <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
                                <section class="min-w-0">
                                    <FormGroup class="!mb-3">
                                        <FormLabel class="mb-1.5" for="service_search">Rechercher une désignation</FormLabel>
                                        <IconInput
                                            id="service_search"
                                            v-model="serviceQuery"
                                            icon="search"
                                            autocomplete="off"
                                            placeholder="Ex. échographie, ECG, consultation…"
                                        />
                                    </FormGroup>

                                    <div class="max-h-72 overflow-y-auto rounded-md border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950">
                                        <button
                                            v-for="item in filteredCatalog"
                                            :key="item.uuid"
                                            type="button"
                                            class="flex w-full items-center gap-3 border-b border-gray-100 px-4 py-3 text-start transition-colors last:border-0 hover:bg-gray-50 focus-visible:bg-gray-50 focus-visible:outline-none dark:border-gray-900 dark:hover:bg-gray-900/60"
                                            @click="addService(item)"
                                        >
                                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded border border-gray-200 text-slate-500 dark:border-gray-800 dark:text-slate-300">
                                                <Icon class="text-base" name="plus" />
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ item.name }}</span>
                                                <span class="mt-0.5 block text-xs text-slate-400">{{ item.code }} · {{ item.module_label }} · {{ item.unit }}</span>
                                            </span>
                                            <span class="shrink-0 text-sm font-bold text-slate-700 dark:text-white">{{ formatMoney(item.tariff_amount) }}</span>
                                        </button>

                                        <div v-if="filteredCatalog.length === 0" class="px-4 py-8 text-center">
                                            <p class="text-sm font-medium text-slate-500">Aucune prestation trouvée</p>
                                            <p class="mt-1 text-xs text-slate-400">Vérifiez la recherche ou le référentiel tarifé.</p>
                                        </div>
                                    </div>

                                    <section v-if="selectedServices.length" class="mt-5 rounded-md border border-gray-200 p-4 dark:border-gray-800">
                                        <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                            <div><h3 class="text-sm font-bold text-slate-700 dark:text-white">Règlement</h3><p class="mt-0.5 text-xs text-slate-400">La facture sera créée et validée à la confirmation.</p></div>
                                            <span :class="['inline-flex w-fit items-center gap-1.5 text-xs font-medium', openCashSession ? 'text-slate-600 dark:text-slate-300' : 'text-slate-400']"><span :class="['h-1.5 w-1.5 rounded-full', openCashSession ? 'bg-green-500' : 'bg-slate-300']"></span>{{ openCashSession ? `Caisse ${openCashSession.session_number} ouverte` : 'Caisse fermée' }}</span>
                                        </div>

                                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                            <button type="button" :class="['rounded-md border p-4 text-start transition-colors', form.payment_choice === 'NOW' ? 'border-primary-500 bg-primary-50/50 dark:border-primary-700 dark:bg-primary-950/20' : 'border-gray-200 dark:border-gray-800', !canPayNow ? 'cursor-not-allowed opacity-50' : 'hover:border-primary-300']" :disabled="!canPayNow" @click="choosePayment('NOW')">
                                                <span class="flex items-center gap-2 text-sm font-bold text-slate-700 dark:text-white"><Icon name="wallet" /> Payer maintenant</span>
                                                <span class="mt-1.5 block text-xs leading-5 text-slate-400">Encaissement intégral et reçu de paiement immédiat.</span>
                                            </button>
                                            <button type="button" :class="['rounded-md border p-4 text-start transition-colors hover:border-primary-300', form.payment_choice === 'LATER' ? 'border-primary-500 bg-primary-50/50 dark:border-primary-700 dark:bg-primary-950/20' : 'border-gray-200 dark:border-gray-800']" @click="choosePayment('LATER')">
                                                <span class="flex items-center gap-2 text-sm font-bold text-slate-700 dark:text-white"><Icon name="clock" /> Payer plus tard</span>
                                                <span class="mt-1.5 block text-xs leading-5 text-slate-400">Facture à payer, sans reçu tant qu’aucun paiement n’est encaissé.</span>
                                            </button>
                                        </div>

                                        <div v-if="form.payment_choice === 'NOW'" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <FormGroup class="!mb-0">
                                                <FormLabel class="mb-1.5" for="arrival_payment_method">Mode de paiement <span class="text-red-500">*</span></FormLabel>
                                                <select id="arrival_payment_method" v-model="form.payment_method_id" class="block h-9 w-full rounded border border-gray-200 bg-white px-3 py-1.5 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" required>
                                                    <option v-for="method in paymentMethods" :key="method.id" :value="method.id">{{ method.name }}</option>
                                                </select>
                                                <FormError v-if="form.errors.payment_method_id">{{ form.errors.payment_method_id }}</FormError>
                                            </FormGroup>
                                            <FormGroup class="!mb-0">
                                                <FormLabel class="mb-1.5" for="arrival_payment_reference">Référence <span class="font-normal text-slate-400">(facultatif)</span></FormLabel>
                                                <InputWrap><Input id="arrival_payment_reference" v-model="form.payment_reference" autocomplete="off" placeholder="N° transaction ou référence" /></InputWrap>
                                                <FormError v-if="form.errors.payment_reference">{{ form.errors.payment_reference }}</FormError>
                                            </FormGroup>
                                        </div>
                                    </section>
                                </section>

                                <aside class="self-start rounded-md border border-gray-200 bg-gray-50/60 p-4 dark:border-gray-800 dark:bg-gray-1000/40">
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <h3 class="text-sm font-bold text-slate-700 dark:text-white">Prestations retenues</h3>
                                            <p class="mt-0.5 text-xs text-slate-400">{{ selectedServices.length }} désignation{{ selectedServices.length > 1 ? 's' : '' }}</p>
                                        </div>
                                        <p class="text-base font-bold text-slate-800 dark:text-white">{{ formatMoney(arrivalTotal) }}</p>
                                    </div>

                                    <div v-if="selectedServices.length" class="mt-4 max-h-80 space-y-2 overflow-y-auto pe-1">
                                        <div v-for="line in selectedServices" :key="line.item.uuid" class="rounded border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-950">
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="min-w-0"><p class="truncate text-sm font-medium text-slate-700 dark:text-white">{{ line.item.name }}</p><p class="mt-0.5 text-xs text-slate-400">{{ formatMoney(line.item.tariff_amount) }} / {{ line.item.unit }}</p></div>
                                                <button type="button" class="shrink-0 text-slate-400 hover:text-red-600" :aria-label="`Retirer ${line.item.name}`" @click="removeService(line.index)"><Icon name="cross" /></button>
                                            </div>
                                            <div class="mt-2 flex items-center justify-between gap-3">
                                                <label :for="`service_quantity_${line.index}`" class="text-xs text-slate-400">Quantité</label>
                                                <Input :id="`service_quantity_${line.index}`" v-model="line.formLine.quantity" class="!w-24 text-end" type="number" min="0.01" max="9999.99" step="0.01" />
                                            </div>
                                        </div>
                                    </div>
                                    <p v-else class="mt-4 rounded border border-dashed border-gray-300 px-3 py-5 text-center text-xs leading-5 text-slate-400 dark:border-gray-700">Ajoutez une prestation depuis la liste.</p>

                                    <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                                        <CheckBox id="defer_designation" v-model="form.defer_designation" name="defer_designation" size="sm" :disabled="selectedServices.length > 0">
                                            Prestation à définir après orientation
                                        </CheckBox>
                                        <p v-if="form.is_emergency" class="mt-2 text-xs leading-5 text-red-600 dark:text-red-300">Une urgence peut continuer sans prestation ni paiement préalable.</p>
                                    </div>
                                </aside>
                            </div>

                            <FormError v-if="form.errors.catalog_lines" class="mt-3">{{ form.errors.catalog_lines }}</FormError>
                            <FormError v-if="form.errors.payment_choice" class="mt-3">{{ form.errors.payment_choice }}</FormError>
                        </template>

                        <div v-else class="rounded-md border border-dashed border-gray-300 px-5 py-8 text-center dark:border-gray-700">
                            <Icon class="text-2xl text-slate-400" name="file-text" />
                            <p class="mt-2 text-sm font-medium text-slate-600 dark:text-slate-300">Création de facture non autorisée</p>
                            <p class="mt-1 text-xs text-slate-400">Le passage peut être enregistré ; la prestation sera définie par un utilisateur habilité.</p>
                        </div>

                        <div class="mt-7 flex flex-col-reverse gap-3 border-t border-gray-200 pt-5 dark:border-gray-900 sm:flex-row sm:justify-between">
                            <Button class="w-full justify-center sm:w-auto" size="rg" variant="white-outline" type="button" @click="goBack"><Icon class="text-lg/4.5" name="arrow-left" /><span class="ms-2">Retour</span></Button>
                            <Button class="w-full justify-center sm:w-auto" size="rg" variant="primary" type="button" :disabled="!servicesComplete" @click="continueToConfirmation"><span class="me-2">Continuer</span><Icon class="text-lg/4.5" name="arrow-right" /></Button>
                        </div>
                    </div>

                    <!-- Step 5: confirm -->
                    <div v-else-if="currentStep === 'confirm'" key="confirm">
                        <BlockHead>
                            <BlockTitle as="h2">Confirmer l'arrivée</BlockTitle>
                            <BlockText>Un passage sera créé pour ce patient.</BlockText>
                        </BlockHead>

                        <Card
                            variant="light"
                            :class="[
                                'overflow-hidden bg-gray-50 dark:bg-gray-1000/60',
                                form.is_emergency ? '!border-red-300 dark:!border-red-900' : '!border-gray-200 dark:!border-gray-900',
                            ]"
                        >
                            <CardBody class="!p-5 sm:!p-6">
                                <template v-if="patientType === 'existing' && selectedPatient">
                                    <div class="flex items-start gap-4">
                                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                                            <Icon class="text-xl" name="user-check" />
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                <p class="font-heading text-base font-bold text-slate-700 dark:text-white">
                                                    {{ formatPatientName(canUpdatePatient ? form : selectedPatient) }}
                                                </p>
                                                <div class="flex flex-wrap gap-2">
                                                    <span v-if="form.is_emergency" class="inline-flex w-fit items-center gap-1 rounded bg-red-100 px-2 py-0.5 text-xs font-bold uppercase text-red-700 dark:bg-red-950 dark:text-red-300">
                                                        <Icon name="alert-circle" /> Urgence
                                                    </span>
                                                    <span class="inline-flex w-fit rounded bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-950 dark:text-primary-300">
                                                        Patient existant
                                                    </span>
                                                </div>
                                            </div>
                                            <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                                <div>
                                                    <dt class="text-xs text-slate-400">N° patient</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">{{ selectedPatient.patient_number }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-slate-400">{{ birthRecapLabel }}</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">{{ birthRecapValue }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-slate-400">Sexe</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">
                                                        {{ (canUpdatePatient ? form.sex : selectedPatient.sex) === 'M' ? 'Masculin' : 'Féminin' }}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-slate-400">Téléphone</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">
                                                        {{ (canUpdatePatient ? form.phone : selectedPatient.phone) || 'Non renseigné' }}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-slate-400">Email</dt>
                                                    <dd class="mt-0.5 break-all font-medium text-slate-600 dark:text-slate-200">
                                                        {{ (canUpdatePatient ? form.email : selectedPatient.email) || 'Non renseigné' }}
                                                    </dd>
                                                </div>
                                                <div v-if="canUpdatePatient ? form.identity_document_type : selectedPatient.identity_document_type">
                                                    <dt class="text-xs text-slate-400">Pièce d’identité</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">
                                                        {{ (canUpdatePatient ? form.identity_document_type : selectedPatient.identity_document_type) === 'CIN' ? 'CIN' : 'Passeport' }}
                                                        {{ canUpdatePatient ? form.identity_document_number : selectedPatient.identity_document_number }}
                                                    </dd>
                                                </div>
                                                <div
                                                    v-if="canUpdatePatient && (form.emergency_contact_name || form.emergency_contact_phone)"
                                                    class="sm:col-span-2"
                                                >
                                                    <dt class="text-xs text-slate-400">Personne à contacter</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">
                                                        {{ form.emergency_contact_name || 'Nom non renseigné' }}
                                                        <span v-if="form.emergency_contact_relationship">· {{ form.emergency_contact_relationship }}</span>
                                                        <span v-if="form.emergency_contact_phone">· {{ form.emergency_contact_phone }}</span>
                                                    </dd>
                                                </div>
                                            </dl>
                                        </div>
                                    </div>
                                </template>

                                <template v-else-if="patientType === 'new'">
                                    <div class="flex items-start gap-4">
                                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-600 dark:bg-green-950 dark:text-green-300">
                                            <Icon class="text-xl" name="user-add" />
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                <p class="font-heading text-base font-bold text-slate-700 dark:text-white">
                                                    <span v-if="civilityLabel">{{ civilityLabel }}</span>
                                                    {{ formatPatientName(form) }}
                                                </p>
                                                <div class="flex flex-wrap gap-2">
                                                    <span v-if="form.is_emergency" class="inline-flex w-fit items-center gap-1 rounded bg-red-100 px-2 py-0.5 text-xs font-bold uppercase text-red-700 dark:bg-red-950 dark:text-red-300">
                                                        <Icon name="alert-circle" /> Urgence
                                                    </span>
                                                    <span class="inline-flex w-fit rounded bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-950 dark:text-green-300">
                                                        Nouveau dossier
                                                    </span>
                                                </div>
                                            </div>
                                            <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                                <div>
                                                    <dt class="text-xs text-slate-400">{{ birthRecapLabel }}</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">{{ birthRecapValue }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-slate-400">Sexe</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">{{ form.sex === 'M' ? 'Masculin' : 'Féminin' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-slate-400">Téléphone</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">{{ form.phone || 'Non renseigné' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-slate-400">Email</dt>
                                                    <dd class="mt-0.5 break-all font-medium text-slate-600 dark:text-slate-200">{{ form.email || 'Non renseigné' }}</dd>
                                                </div>
                                                <div v-if="form.identity_document_type">
                                                    <dt class="text-xs text-slate-400">Pièce d’identité</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">
                                                        {{ form.identity_document_type === 'CIN' ? 'CIN' : 'Passeport' }} {{ form.identity_document_number }}
                                                    </dd>
                                                </div>
                                                <div v-if="form.emergency_contact_name || form.emergency_contact_phone" class="sm:col-span-2">
                                                    <dt class="text-xs text-slate-400">Personne à contacter</dt>
                                                    <dd class="mt-0.5 font-medium text-slate-600 dark:text-slate-200">
                                                        {{ form.emergency_contact_name || 'Nom non renseigné' }}
                                                        <span v-if="form.emergency_contact_relationship">· {{ form.emergency_contact_relationship }}</span>
                                                        <span v-if="form.emergency_contact_phone">· {{ form.emergency_contact_phone }}</span>
                                                    </dd>
                                                </div>
                                            </dl>
                                        </div>
                                    </div>
                                </template>
                            </CardBody>
                        </Card>

                        <Card v-if="selectedServices.length" class="mt-4 overflow-hidden">
                            <div class="flex flex-col gap-2 border-b border-gray-200 px-5 py-3 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                                <div><h3 class="text-sm font-bold text-slate-700 dark:text-white">Prestations et règlement</h3><p class="mt-0.5 text-xs text-slate-400">Les tarifs seront vérifiés une dernière fois par le serveur.</p></div>
                                <div class="text-start sm:text-end"><p class="text-base font-bold text-slate-800 dark:text-white">{{ formatMoney(arrivalTotal) }}</p><p class="text-xs font-medium text-slate-500">{{ form.payment_choice === 'NOW' ? 'Paiement immédiat' : 'Paiement ultérieur' }}</p></div>
                            </div>
                            <div class="divide-y divide-gray-100 dark:divide-gray-900">
                                <div v-for="line in selectedServices" :key="line.item.uuid" class="flex items-center justify-between gap-4 px-5 py-2.5 text-sm">
                                    <span class="min-w-0 truncate text-slate-600 dark:text-slate-300">{{ line.item.name }} <span class="text-xs text-slate-400">· {{ line.formLine.quantity }} × {{ formatMoney(line.item.tariff_amount) }}</span></span>
                                    <span class="shrink-0 font-bold text-slate-700 dark:text-white">{{ formatMoney(Number(line.formLine.quantity) * Number(line.item.tariff_amount)) }}</span>
                                </div>
                            </div>
                            <div class="border-t border-gray-200 bg-gray-50 px-5 py-3 text-xs leading-5 text-slate-500 dark:border-gray-900 dark:bg-gray-1000/40 dark:text-slate-400">
                                {{ form.payment_choice === 'NOW' ? 'La confirmation créera la facture, enregistrera le paiement intégral et générera le reçu.' : 'La confirmation créera une facture à payer. Aucun reçu ne sera généré avant l’encaissement.' }}
                            </div>
                        </Card>

                        <div v-else class="mt-4 rounded-md border border-dashed border-gray-300 px-4 py-3 text-xs text-slate-500 dark:border-gray-700 dark:text-slate-400">
                            Prestation à définir après orientation : aucun montant ne sera facturé à cette étape.
                        </div>

                        <Card
                            v-if="duplicates && duplicates.length > 0"
                            class="mt-5 !border-yellow-300 bg-yellow-50 dark:!border-yellow-900 dark:bg-yellow-950"
                        >
                            <CardBody class="!p-5">
                                <div class="flex items-start gap-3">
                                    <Icon class="mt-0.5 text-xl text-yellow-600" name="alert-circle" />
                                    <div class="flex-grow">
                                        <p class="text-sm font-medium text-yellow-800 dark:text-yellow-200">
                                            Un ou plusieurs patients correspondent déjà à ce nom et cette date de naissance :
                                        </p>
                                        <ul class="mt-2 space-y-1 text-sm text-yellow-700 dark:text-yellow-300">
                                            <li v-for="match in duplicates" :key="match.uuid">
                                                {{ match.patient_number }} — {{ formatPatientName(match) }} ({{ formatDate(match.birth_date) }})
                                            </li>
                                        </ul>
                                        <Button class="mt-4" size="sm" type="button" variant="white-outline" :disabled="form.processing" @click="confirmDespiteDuplicate">
                                            Créer quand même, c'est un patient différent
                                        </Button>
                                    </div>
                                </div>
                            </CardBody>
                        </Card>

                        <div class="mt-8 flex flex-col-reverse gap-3 border-t border-gray-200 pt-6 dark:border-gray-900 sm:flex-row sm:justify-between">
                            <Button class="w-full justify-center sm:w-auto" size="rg" variant="white-outline" type="button" @click="goBack">
                                <Icon class="text-lg/4.5" name="arrow-left" />
                                <span class="ms-2">Modifier</span>
                            </Button>
                            <Button class="w-full justify-center sm:w-auto" size="rg" variant="primary" type="button" :disabled="form.processing" @click="confirmArrival">
                                <Icon class="text-lg/4.5" name="check" />
                                <span class="ms-2">{{ form.processing ? 'Enregistrement…' : 'Confirmer l’arrivée' }}</span>
                            </Button>
                        </div>
                    </div>
                </Transition>
            </CardBody>
        </Card>

        <!-- Recent passages: one patient may have several episodes. Filters
             therefore target the episode priority and administrative status. -->
        <Card v-if="!showArrivalFlow" class="overflow-hidden shadow-sm">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300">
                        <Icon class="text-lg" name="clock" />
                    </span>
                    <div>
                        <h2 class="text-sm font-bold text-slate-700 dark:text-white">Passages patients</h2>
                        <p class="mt-0.5 text-xs text-slate-400">Suivez les arrivées et leur état d’orientation.</p>
                    </div>
                </div>
                <span class="w-fit rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-slate-500 dark:bg-gray-900 dark:text-slate-300">
                    {{ recentEpisodes.length }} affiché{{ recentEpisodes.length > 1 ? 's' : '' }}
                </span>
            </div>

            <div class="flex min-w-0 items-center gap-3 border-b border-gray-200 bg-gray-50/50 px-5 py-3 dark:border-gray-900 dark:bg-gray-1000/30 sm:px-6">
                <span class="shrink-0 text-[11px] font-bold uppercase tracking-wide text-slate-400">Afficher</span>
                <div class="inline-flex min-w-0 overflow-x-auto rounded-md border border-gray-200 bg-white p-0.5 dark:border-gray-800 dark:bg-gray-950" role="group" aria-label="Filtrer les passages patients">
                    <Link
                        v-for="option in recentFilterOptions"
                        :key="option.value"
                        :href="recentFilterHref(option.value)"
                        replace
                        preserve-scroll
                        :aria-current="activeRecentFilter === option.value ? 'true' : undefined"
                        :class="[
                            'inline-flex shrink-0 items-center gap-1.5 rounded px-2.5 py-1.5 text-xs font-semibold transition-colors',
                            activeRecentFilter === option.value
                                ? 'bg-gray-100 text-slate-700 dark:bg-gray-900 dark:text-white'
                                : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-200',
                        ]"
                    >
                        <Icon :class="['text-sm', option.value === 'emergency' && activeRecentFilter === option.value ? 'text-red-500' : '']" :name="option.icon" />
                        {{ option.label }}
                    </Link>
                </div>
            </div>

            <div v-if="recentEpisodes.length === 0" class="px-5 py-12 text-center">
                <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-slate-400 dark:bg-gray-900">
                    <Icon class="text-xl" name="filter" />
                </span>
                <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-200">Aucun passage ne correspond aux filtres</p>
                <p class="mt-1 text-xs text-slate-400">Choisissez un autre filtre pour élargir la liste.</p>
                <Button v-if="hasActiveRecentFilter" :as="Link" href="/reception/patients" class="mt-4" size="sm" variant="white-outline">Afficher tous les passages</Button>
            </div>

            <template v-else>
                <div class="divide-y divide-gray-200 dark:divide-gray-900 md:hidden">
                    <article v-for="episode in recentEpisodes" :key="episode.id" class="p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(episode.patient)" aria-hidden="true" />
                                <div class="min-w-0">
                                    <Link v-if="can('patients.view')" :href="`/patients/${episode.patient.uuid}`" class="block truncate text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white">{{ formatPatientName(episode.patient) }}</Link>
                                    <span v-else class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(episode.patient) }}</span>
                                    <p class="mt-0.5 text-xs text-slate-400">{{ episode.patient.patient_number }} · {{ episode.episode_number }}</p>
                                </div>
                            </div>
                            <span :class="['shrink-0 rounded border px-2 py-1 text-[11px] font-semibold', administrativeStatusBadgeClass(episode.administrative_status)]">
                                {{ pathwayStatus(episode) }}
                            </span>
                        </div>

                        <dl class="mt-4 grid grid-cols-2 gap-3 border-t border-gray-100 pt-3 text-xs dark:border-gray-900">
                            <div><dt class="text-slate-400">Arrivée</dt><dd class="mt-1 font-medium text-slate-600 dark:text-slate-200">{{ formatDateTime(episode.started_at) }}</dd></div>
                            <div><dt class="text-slate-400">Priorité</dt><dd class="mt-1"><span :class="['inline-flex items-center gap-1.5 font-semibold', episode.priority === 'EMERGENCY' ? 'text-red-600 dark:text-red-300' : 'text-slate-600 dark:text-slate-300']"><span :class="['h-1.5 w-1.5 rounded-full', episode.priority === 'EMERGENCY' ? 'bg-red-500' : 'bg-slate-300 dark:bg-slate-600']"></span>{{ episode.priority === 'EMERGENCY' ? 'Urgence' : 'Normale' }}</span></dd></div>
                        </dl>

                    </article>
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[980px] border-collapse">
                        <caption class="sr-only">Liste des passages patients filtrés</caption>
                        <thead>
                            <tr class="bg-gray-50/70 dark:bg-gray-1000/40">
                                <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Patient</th>
                                <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Passage</th>
                                <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Arrivée</th>
                                <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Priorité</th>
                                <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Parcours</th>
                                <th class="border-b border-gray-200 px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="episode in recentEpisodes" :key="episode.id" class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-1000">
                                <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                    <div class="flex min-w-[220px] items-center gap-3">
                                        <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(episode.patient)" aria-hidden="true" />
                                        <div class="min-w-0">
                                            <Link v-if="can('patients.view')" :href="`/patients/${episode.patient.uuid}`" class="block truncate text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white">{{ formatPatientName(episode.patient) }}</Link>
                                            <span v-else class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(episode.patient) }}</span>
                                            <span class="mt-0.5 block text-xs text-slate-400">{{ episode.patient.patient_number }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900"><span class="font-mono text-sm font-semibold text-slate-600 dark:text-slate-300">{{ episode.episode_number }}</span><span class="mt-0.5 block text-xs text-slate-400">{{ statusLabels[episode.status] ?? episode.status }}</span></td>
                                <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900"><span class="block text-sm text-slate-600 dark:text-slate-300">{{ formatDateTime(episode.started_at) }}</span><span class="mt-0.5 block text-xs text-slate-400">{{ formatRelativeTime(episode.started_at) }}</span></td>
                                <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900"><span :class="['inline-flex items-center gap-1.5 text-xs font-semibold', episode.priority === 'EMERGENCY' ? 'text-red-600 dark:text-red-300' : 'text-slate-500 dark:text-slate-400']"><span :class="['h-1.5 w-1.5 rounded-full', episode.priority === 'EMERGENCY' ? 'bg-red-500' : 'bg-slate-300 dark:bg-slate-600']"></span>{{ episode.priority === 'EMERGENCY' ? 'Urgence' : 'Normale' }}</span></td>
                                <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900"><span :class="['inline-flex rounded border px-2 py-1 text-xs font-semibold', administrativeStatusBadgeClass(episode.administrative_status)]">{{ pathwayStatus(episode) }}</span></td>
                                <td class="border-b border-gray-200 px-5 py-3 text-end dark:border-gray-900">
                                    <div class="inline-flex items-center gap-1.5">
                                        <Button v-if="can('patients.view')" :as="Link" :href="`/patients/${episode.patient.uuid}`" icon size="sm" variant="white-outline" title="Voir le dossier" :aria-label="`Voir le dossier de ${formatPatientName(episode.patient)}`"><Icon class="text-base" name="eye" /></Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </Card>
    </div>
</template>
