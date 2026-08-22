<script setup>
import { computed, onBeforeUnmount, reactive, ref, toRef } from 'vue';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/vue';
import { Head, Link, router, useForm, usePage, useRemember } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import CardBody from '@/Components/UI/CardBody.vue';
import CheckBox from '@/Components/UI/CheckBox.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconInput from '@/Components/UI/IconInput.vue';
import Input from '@/Components/UI/Input.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatDateTime, formatRelativeTime } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    step: String,
    search: String,
    matches: { type: Array, default: () => [] },
    recentEpisodes: { type: Array, default: () => [] },
    recentEpisodeFilter: String,
    addressEntries: { type: Array, default: () => [] },
    staffEmployees: { type: Array, default: () => [] },
    mutualOrganizations: { type: Array, default: () => [] },
});

const page = usePage();
const { can } = usePermissions();
const duplicates = computed(() => page.props.flash?.duplicates ?? []);
const showArrivalFlow = computed(() => Boolean(props.step) || duplicates.value.length > 0);
const query = ref(props.search ?? '');
const employeeQuery = ref('');
const showNewAddress = ref(false);
const fileInput = ref(null);
const mutualAttachmentPreviews = ref([]);
const activeMutualAttachment = ref(null);
const mutualAttachmentClientError = ref('');

const acceptedMutualAttachmentTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
const maxMutualAttachmentSize = 5 * 1024 * 1024;
const maxMutualAttachments = 5;

const workflow = useRemember(reactive({
    arrivalMode: props.search ? 'existing' : null,
    patientCategory: 'STANDARD',
    selectedPatient: null,
    selectedEmployee: null,
    birthMode: 'date',
}), 'ReceptionPatientAdministrativeWorkflow');

const arrivalMode = toRef(workflow, 'arrivalMode');
const patientCategory = toRef(workflow, 'patientCategory');
const selectedPatient = toRef(workflow, 'selectedPatient');
const selectedEmployee = toRef(workflow, 'selectedEmployee');
const birthMode = toRef(workflow, 'birthMode');

const allSteps = {
    type: { key: 'type', slug: 'type', label: 'Type' },
    identity: { key: 'identity', slug: 'identite', label: arrivalMode.value === 'existing' ? 'Patient' : 'Identité' },
    contact: { key: 'contact', slug: 'contact', label: 'Contact' },
    coverage: { key: 'coverage', slug: 'couverture', label: 'Couverture' },
    confirmation: { key: 'confirmation', slug: 'confirmation', label: 'Confirmation' },
};

const visibleSteps = computed(() => {
    if (arrivalMode.value === 'existing') {
        return [allSteps.type, allSteps.identity, allSteps.confirmation];
    }

    if (patientCategory.value === 'STANDARD') {
        return [allSteps.type, allSteps.identity, allSteps.contact, allSteps.confirmation];
    }

    if (patientCategory.value === 'STAFF') {
        return [allSteps.type, allSteps.identity, allSteps.coverage, allSteps.confirmation];
    }

    return [allSteps.type, allSteps.identity, allSteps.contact, allSteps.coverage, allSteps.confirmation];
});

const currentStep = computed(() => {
    const bySlug = Object.values(allSteps).find((step) => step.slug === props.step);

    if (!bySlug || !visibleSteps.value.some((step) => step.key === bySlug.key)) {
        return 'type';
    }

    return bySlug.key;
});
const currentVisibleIndex = computed(() => Math.max(
    visibleSteps.value.findIndex((step) => step.key === currentStep.value),
    0,
));

const form = useForm('ReceptionPatientAdministrativeForm', {
    patient_type: 'STANDARD',
    employee_uuid: '',
    first_name: '',
    last_name: '',
    birth_date: '',
    age: '',
    sex: 'M',
    civility: null,
    identity_document_type: null,
    identity_document_number: '',
    marital_status: null,
    children_count: '',
    profession: '',
    phone: '',
    email: '',
    address_entry_uuid: '',
    new_address_label: '',
    emergency_contact_name: '',
    emergency_contact_phone: '',
    emergency_contact_email: '',
    emergency_contact_relationship: '',
    mutual_organization_name: '',
    mutual_employer_name: '',
    mutual_beneficiary_type: 'PRINCIPAL',
    mutual_membership_number: '',
    mutual_attachments: [],
    is_emergency: false,
    confirm_duplicate: false,
});

const mutualAttachmentServerError = computed(() => {
    const error = Object.entries(form.errors)
        .find(([key]) => key === 'mutual_attachments' || key.startsWith('mutual_attachments.'));

    return error?.[1] ?? '';
});

const syncMutualAttachments = () => {
    form.mutual_attachments = mutualAttachmentPreviews.value.map((attachment) => attachment.file);
};

const resetMutualAttachmentInput = () => {
    if (fileInput.value) fileInput.value.value = '';
};

const clearMutualAttachmentErrors = () => {
    mutualAttachmentClientError.value = '';
    Object.keys(form.errors)
        .filter((key) => key === 'mutual_attachments' || key.startsWith('mutual_attachments.'))
        .forEach((key) => form.clearErrors(key));
};

const revokeMutualAttachmentUrl = (attachment) => {
    if (attachment?.previewUrl) URL.revokeObjectURL(attachment.previewUrl);
};

const clearMutualAttachments = () => {
    mutualAttachmentPreviews.value.forEach(revokeMutualAttachmentUrl);
    mutualAttachmentPreviews.value = [];
    activeMutualAttachment.value = null;
    syncMutualAttachments();
    clearMutualAttachmentErrors();
    resetMutualAttachmentInput();
};

const resetDraft = () => {
    clearMutualAttachments();
    form.reset();
    form.clearErrors();
    workflow.arrivalMode = null;
    workflow.patientCategory = 'STANDARD';
    workflow.selectedPatient = null;
    workflow.selectedEmployee = null;
    workflow.birthMode = 'date';
    query.value = '';
    employeeQuery.value = '';
    showNewAddress.value = false;
};

const navigate = (slug, data = {}) => router.get(`/reception/patients/${slug}`, data, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
});

const startArrival = () => {
    resetDraft();
    navigate('type');
};

const showPassages = () => {
    resetDraft();
    router.get('/reception/patients', {}, { preserveState: true });
};

const chooseExisting = () => {
    arrivalMode.value = 'existing';
    selectedPatient.value = null;
    navigate('identite');
};

const chooseNew = () => {
    arrivalMode.value = 'new';
    selectedPatient.value = null;
};

const restartAsNew = () => {
    chooseNew();
    navigate('type');
};

const chooseCategory = (category) => {
    arrivalMode.value = 'new';
    patientCategory.value = category;
    form.patient_type = category;
    selectedEmployee.value = null;
    form.employee_uuid = '';
    navigate('identite');
};

const submitSearch = () => navigate('identite', query.value ? { q: query.value } : {});
const pickExistingPatient = (patient) => {
    selectedPatient.value = patient;
    navigate('confirmation');
};

const filteredEmployees = computed(() => {
    const needle = employeeQuery.value.trim().toLocaleLowerCase('fr');
    if (!needle) return props.staffEmployees.slice(0, 12);

    return props.staffEmployees.filter((employee) => `${employee.employee_number} ${employee.first_name ?? ''} ${employee.last_name}`
        .toLocaleLowerCase('fr')
        .includes(needle)).slice(0, 12);
});

const pickEmployee = (employee) => {
    selectedEmployee.value = employee;
    form.employee_uuid = employee.uuid;
};

const civilityOptions = [
    { value: 'MR', label: 'M.', sex: 'M' },
    { value: 'MRS', label: 'Mme', sex: 'F' },
    { value: 'GIRL', label: 'Enfant fille', sex: 'F' },
    { value: 'BOY', label: 'Enfant garçon', sex: 'M' },
];
const maritalOptions = [
    { value: 'SINGLE', label: 'Célibataire' },
    { value: 'MARRIED', label: 'Marié(e)' },
    { value: 'DIVORCED', label: 'Divorcé(e)' },
    { value: 'WIDOWED', label: 'Veuf / veuve' },
];

const chooseCivility = (event) => {
    form.civility = event.target.value || null;
    form.sex = civilityOptions.find((option) => option.value === form.civility)?.sex ?? form.sex;
};

const setBirthMode = (mode) => {
    birthMode.value = mode;
    if (mode === 'date') form.age = '';
    else form.birth_date = '';
};

const exactAge = computed(() => {
    if (!form.birth_date) return null;
    const birth = new Date(`${form.birth_date}T00:00:00`);
    const today = new Date();
    let age = today.getFullYear() - birth.getFullYear();
    if (today.getMonth() < birth.getMonth()
        || (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate())) age--;
    return Math.max(age, 0);
});

const identityComplete = computed(() => {
    if (patientCategory.value === 'STAFF') return Boolean(selectedEmployee.value);
    return Boolean(form.last_name && form.sex && (form.birth_date || form.age));
});

const contactComplete = computed(() => true);
const coverageComplete = computed(() => {
    if (patientCategory.value === 'STAFF') return Boolean(selectedEmployee.value);
    if (patientCategory.value !== 'MUTUAL') return true;
    return Boolean(form.mutual_organization_name
        && form.mutual_employer_name
        && form.mutual_beneficiary_type
        && form.mutual_membership_number);
});

const continueIdentity = () => {
    if (!identityComplete.value) return;
    navigate(patientCategory.value === 'STAFF' ? 'couverture' : 'contact');
};
const continueContact = () => {
    if (!contactComplete.value) return;
    navigate(patientCategory.value === 'MUTUAL' ? 'couverture' : 'confirmation');
};
const continueCoverage = () => {
    if (coverageComplete.value) navigate('confirmation');
};

const goBack = () => {
    const steps = visibleSteps.value;
    const previous = steps[currentVisibleIndex.value - 1];
    if (previous) navigate(previous.slug, previous.key === 'identity' && query.value ? { q: query.value } : {});
    else showPassages();
};

const selectAddress = (event) => {
    form.address_entry_uuid = event.target.value;
    if (form.address_entry_uuid) {
        form.new_address_label = '';
        showNewAddress.value = false;
    }
};

const toggleNewAddress = () => {
    showNewAddress.value = !showNewAddress.value;
    if (showNewAddress.value) form.address_entry_uuid = '';
    else form.new_address_label = '';
};

const addMutualAttachments = (fileList) => {
    clearMutualAttachmentErrors();

    const files = Array.from(fileList ?? []);
    const errors = [];
    let ignoredCount = 0;

    files.forEach((file) => {
        if (mutualAttachmentPreviews.value.length >= maxMutualAttachments) {
            ignoredCount++;
            return;
        }

        if (!acceptedMutualAttachmentTypes.includes(file.type)) {
            errors.push(`${file.name} : format non accepté.`);
            return;
        }

        if (file.size > maxMutualAttachmentSize) {
            errors.push(`${file.name} dépasse 5 Mo.`);
            return;
        }

        const duplicate = mutualAttachmentPreviews.value.some((attachment) => (
            attachment.file.name === file.name
            && attachment.file.size === file.size
            && attachment.file.lastModified === file.lastModified
        ));

        if (duplicate) {
            errors.push(`${file.name} est déjà sélectionné.`);
            return;
        }

        mutualAttachmentPreviews.value.push({
            file,
            isImage: file.type.startsWith('image/'),
            previewUrl: URL.createObjectURL(file),
        });
    });

    if (ignoredCount > 0) {
        errors.push(`${ignoredCount} fichier${ignoredCount > 1 ? 's ont' : ' a'} été ignoré${ignoredCount > 1 ? 's' : ''} : 5 fichiers maximum.`);
    }

    mutualAttachmentClientError.value = errors.join(' ');
    syncMutualAttachments();
    resetMutualAttachmentInput();
};

const selectAttachments = (event) => addMutualAttachments(event.target.files);
const dropMutualAttachments = (event) => addMutualAttachments(event.dataTransfer?.files);

const removeAttachment = (index) => {
    const [removed] = mutualAttachmentPreviews.value.splice(index, 1);

    if (activeMutualAttachment.value === removed) activeMutualAttachment.value = null;
    revokeMutualAttachmentUrl(removed);
    syncMutualAttachments();
    clearMutualAttachmentErrors();
    resetMutualAttachmentInput();
};

const formatFileSize = (bytes) => {
    if (!bytes) return '0 Ko';

    return bytes >= 1024 * 1024
        ? `${(bytes / (1024 * 1024)).toFixed(1)} Mo`
        : `${Math.max(1, Math.round(bytes / 1024))} Ko`;
};

onBeforeUnmount(() => {
    mutualAttachmentPreviews.value.forEach(revokeMutualAttachmentUrl);
});

const categoryLabels = {
    STANDARD: 'Patient standard',
    MUTUAL: 'Patient mutuelle',
    STAFF: 'Personnel de la clinique',
};

const recapPatient = computed(() => selectedPatient.value ?? selectedEmployee.value);
const recapName = computed(() => {
    if (arrivalMode.value === 'existing' || patientCategory.value === 'STAFF') {
        return formatPatientName(recapPatient.value ?? {});
    }
    return formatPatientName({ first_name: form.first_name, last_name: form.last_name });
});

const returnToInvalidStep = (errors) => {
    const keys = Object.keys(errors);
    if (keys.some((key) => key.startsWith('mutual_') || key === 'employee_uuid')) return navigate('couverture');
    if (keys.some((key) => ['phone', 'email', 'address_entry_uuid', 'new_address_label'].includes(key)
        || key.startsWith('emergency_contact_'))) return navigate('contact');
    navigate('identite', query.value ? { q: query.value } : {});
};

const submitArrival = (confirmDuplicate = false) => {
    form.confirm_duplicate = confirmDuplicate;
    form.transform((data) => {
        if (arrivalMode.value === 'existing') {
            return { patient_uuid: selectedPatient.value.uuid, is_emergency: data.is_emergency };
        }

        if (patientCategory.value === 'STAFF') {
            return {
                patient_type: 'STAFF',
                employee_uuid: selectedEmployee.value.uuid,
                is_emergency: data.is_emergency,
            };
        }

        return {
            ...data,
            patient_type: patientCategory.value,
            employee_uuid: null,
            birth_date: birthMode.value === 'date' ? data.birth_date : null,
            age: birthMode.value === 'age' ? data.age : null,
            mutual_organization_name: patientCategory.value === 'MUTUAL' ? data.mutual_organization_name : null,
            mutual_employer_name: patientCategory.value === 'MUTUAL' ? data.mutual_employer_name : null,
            mutual_beneficiary_type: patientCategory.value === 'MUTUAL' ? data.mutual_beneficiary_type : null,
            mutual_membership_number: patientCategory.value === 'MUTUAL' ? data.mutual_membership_number : null,
            mutual_attachments: patientCategory.value === 'MUTUAL' ? data.mutual_attachments : [],
        };
    }).post('/reception/patients', {
        forceFormData: true,
        preserveScroll: true,
        onError: returnToInvalidStep,
        onSuccess: resetDraft,
    });
};

const recentFilterOptions = [
    { value: 'all', label: 'Tous' },
    { value: 'normal', label: 'Normal' },
    { value: 'emergency', label: 'Urgence' },
    { value: 'pending', label: 'À orienter' },
    { value: 'oriented', label: 'Orientés' },
];
const activeRecentFilter = computed(() => props.recentEpisodeFilter ?? 'all');
const recentFilterHref = (value) => value === 'all' ? '/reception/patients' : `/reception/patients?filter=${value}`;
const pathwayStatus = (episode) => {
    const orientations = episode.orientations ?? [];
    const medicine = orientations.find((item) => item.destination_module === 'MEDICINE' && ['PENDING', 'IN_PROGRESS'].includes(item.status));
    const care = orientations.find((item) => item.destination_module === 'CARE' && ['PENDING', 'IN_PROGRESS'].includes(item.status));
    if (medicine?.status === 'IN_PROGRESS') return 'En consultation';
    if (medicine) return 'En attente Médecine';
    if (care?.status === 'IN_PROGRESS') return 'En cours aux Soins';
    if (care) return 'En attente aux Soins';
    return episode.service_plan_finalized_at ? 'Parcours défini' : 'Prestations à définir';
};

const selectClass = 'block h-9 w-full appearance-none rounded border border-gray-200 bg-white px-3 pe-9 text-sm text-slate-700 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
</script>

<template>
    <Head title="Réception patient" />

    <div :class="['mx-auto w-full', showArrivalFlow ? 'max-w-screen-xl space-y-5' : 'max-w-screen-2xl space-y-4']">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                    <Icon class="text-2xl" name="user-add" />
                </span>
                <div>
                    <h1 class="font-heading text-2xl font-bold text-slate-700 dark:text-white">Réception patient</h1>
                    <p class="mt-1 text-sm text-slate-400">Créez le dossier administratif, puis préparez le passage clinique.</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button :as="Link" href="/reception" size="rg" variant="white-outline"><Icon class="me-2 text-lg" name="arrow-left" />Accueil réception</Button>
                <Button size="rg" :variant="showArrivalFlow ? 'white-outline' : 'primary'" type="button" @click="showArrivalFlow ? showPassages() : startArrival()">
                    <Icon class="me-2 text-lg" :name="showArrivalFlow ? 'list' : 'user-add'" />{{ showArrivalFlow ? 'Voir les passages' : 'Nouvelle arrivée' }}
                </Button>
            </div>
        </header>

        <Card v-if="showArrivalFlow" class="overflow-hidden shadow-sm">
            <nav class="border-b border-gray-200 bg-gray-50/50 px-5 py-3 dark:border-gray-900 dark:bg-gray-1000/30" aria-label="Étapes de l'accueil">
                <ol class="flex items-center">
                    <li v-for="(stepItem, index) in visibleSteps" :key="stepItem.key" class="flex min-w-0 flex-1 items-center last:flex-none">
                        <div class="flex items-center gap-2">
                            <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold', index < currentVisibleIndex ? 'bg-primary-600 text-white' : index === currentVisibleIndex ? 'border-2 border-primary-600 bg-white text-primary-600 dark:bg-gray-950' : 'bg-gray-200 text-slate-400 dark:bg-gray-900']">
                                <Icon v-if="index < currentVisibleIndex" name="check" />
                                <span v-else>{{ index + 1 }}</span>
                            </span>
                            <span :class="['hidden text-sm font-semibold sm:block', index === currentVisibleIndex ? 'text-slate-700 dark:text-white' : 'text-slate-400']">{{ stepItem.label }}</span>
                        </div>
                        <span v-if="index < visibleSteps.length - 1" class="mx-3 h-px min-w-5 flex-1 bg-gray-200 dark:bg-gray-800"></span>
                    </li>
                </ol>
            </nav>

            <CardBody class="!p-5 sm:!p-7">
                <section v-if="currentStep === 'type'">
                    <div class="max-w-2xl">
                        <h2 class="text-xl font-bold text-slate-700 dark:text-white">Quel patient se présente ?</h2>
                        <p class="mt-1 text-sm text-slate-400">Commencez par identifier le dossier et le niveau de priorité.</p>
                    </div>

                    <label class="mt-5 flex cursor-pointer items-start gap-3 rounded-md border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-gray-1000/40">
                        <CheckBox id="is_emergency" v-model="form.is_emergency" size="sm" />
                        <span>
                            <span class="block text-sm font-semibold text-slate-700 dark:text-white">Admission en urgence</span>
                            <span class="mt-0.5 block text-xs leading-5 text-slate-400">Soins et Médecine voient immédiatement le patient ; la famille complète le dossier sans bloquer la prise en charge.</span>
                        </span>
                    </label>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        <button type="button" :class="['rounded-md border p-5 text-start transition', arrivalMode === 'existing' ? 'border-primary-500 bg-primary-50/40 ring-1 ring-primary-100 dark:bg-primary-950/20' : 'border-gray-200 hover:border-gray-300 dark:border-gray-800']" @click="chooseExisting">
                            <Icon class="text-2xl text-primary-600" name="search" />
                            <span class="mt-3 block text-base font-bold text-slate-700 dark:text-white">Patient existant</span>
                            <span class="mt-1 block text-sm leading-5 text-slate-400">Retrouver son dossier et ouvrir son prochain passage.</span>
                        </button>
                        <button type="button" :class="['rounded-md border p-5 text-start transition', arrivalMode === 'new' ? 'border-primary-500 bg-primary-50/40 ring-1 ring-primary-100 dark:bg-primary-950/20' : 'border-gray-200 hover:border-gray-300 dark:border-gray-800']" @click="chooseNew">
                            <Icon class="text-2xl text-primary-600" name="user-add" />
                            <span class="mt-3 block text-base font-bold text-slate-700 dark:text-white">Nouveau dossier</span>
                            <span class="mt-1 block text-sm leading-5 text-slate-400">Créer l’identité permanente avant le premier passage.</span>
                        </button>
                    </div>

                    <div v-if="arrivalMode === 'new'" class="mt-6 border-t border-gray-200 pt-5 dark:border-gray-900">
                        <h3 class="text-sm font-bold text-slate-700 dark:text-white">Type administratif du patient</h3>
                        <p class="mt-1 text-xs text-slate-400">Ce choix détermine les informations de couverture à demander.</p>
                        <div class="mt-3 grid gap-3 md:grid-cols-3">
                            <button type="button" class="rounded-md border border-gray-200 p-4 text-start hover:border-primary-400 dark:border-gray-800" @click="chooseCategory('STANDARD')">
                                <span class="text-sm font-bold text-slate-700 dark:text-white">Standard</span><span class="mt-1 block text-xs leading-5 text-slate-400">Dossier patient classique.</span>
                            </button>
                            <button type="button" class="rounded-md border border-gray-200 p-4 text-start hover:border-primary-400 dark:border-gray-800" @click="chooseCategory('MUTUAL')">
                                <span class="text-sm font-bold text-slate-700 dark:text-white">Mutuelle</span><span class="mt-1 block text-xs leading-5 text-slate-400">Adhésion, entreprise et pièces justificatives.</span>
                            </button>
                            <button type="button" class="rounded-md border border-gray-200 p-4 text-start hover:border-primary-400 dark:border-gray-800" @click="chooseCategory('STAFF')">
                                <span class="text-sm font-bold text-slate-700 dark:text-white">Personnel</span><span class="mt-1 block text-xs leading-5 text-slate-400">Informations récupérées depuis le dossier RH.</span>
                            </button>
                        </div>
                    </div>
                </section>

                <section v-else-if="currentStep === 'identity' && arrivalMode === 'existing'">
                    <h2 class="text-xl font-bold text-slate-700 dark:text-white">Retrouver le patient</h2>
                    <p class="mt-1 text-sm text-slate-400">Recherchez par nom, numéro patient ou téléphone.</p>
                    <form class="mt-5 flex max-w-2xl gap-2" @submit.prevent="submitSearch">
                        <IconInput v-model="query" class="flex-1" icon="search" placeholder="Nom, numéro patient, téléphone…" autocomplete="off" />
                        <Button size="rg" type="submit">Rechercher</Button>
                    </form>
                    <div v-if="matches.length" class="mt-5 max-w-4xl overflow-hidden rounded-md border border-gray-200 dark:border-gray-800">
                        <button v-for="patient in matches" :key="patient.uuid" type="button" class="flex w-full items-center gap-3 border-b border-gray-100 px-4 py-3 text-start last:border-0 hover:bg-gray-50 dark:border-gray-900 dark:hover:bg-gray-1000" @click="pickExistingPatient(patient)">
                            <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(patient)" />
                            <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(patient) }}</span><span class="mt-0.5 block text-xs text-slate-400">{{ patient.patient_number }} · {{ patient.phone || 'Téléphone non renseigné' }}</span></span>
                            <Icon class="text-lg text-slate-400" name="chevron-right" />
                        </button>
                    </div>
                    <div v-else-if="query" class="mt-5 max-w-2xl rounded-md border border-dashed border-gray-300 px-4 py-8 text-center dark:border-gray-700">
                        <p class="text-sm font-semibold text-slate-600 dark:text-slate-200">Aucun dossier trouvé</p>
                        <button type="button" class="mt-2 text-sm font-semibold text-primary-600" @click="restartAsNew">Créer un nouveau dossier</button>
                    </div>
                    <div class="mt-6 border-t border-gray-200 pt-5 dark:border-gray-900"><Button size="rg" variant="white-outline" @click="goBack"><Icon class="me-2" name="arrow-left" />Retour</Button></div>
                </section>

                <section v-else-if="currentStep === 'identity' && patientCategory === 'STAFF'">
                    <h2 class="text-xl font-bold text-slate-700 dark:text-white">Identifier le personnel</h2>
                    <p class="mt-1 text-sm text-slate-400">Le dossier patient sera créé à partir des données RH autorisées, jamais depuis un compte utilisateur.</p>
                    <IconInput v-model="employeeQuery" class="mt-5 max-w-2xl" icon="search" placeholder="Matricule RH, nom ou prénom…" />
                    <div class="mt-4 max-w-4xl overflow-hidden rounded-md border border-gray-200 dark:border-gray-800">
                        <button v-for="employee in filteredEmployees" :key="employee.uuid" type="button" :class="['flex w-full items-center gap-3 border-b border-gray-100 px-4 py-3 text-start last:border-0 dark:border-gray-900', selectedEmployee?.uuid === employee.uuid ? 'bg-primary-50/60 dark:bg-primary-950/20' : 'hover:bg-gray-50 dark:hover:bg-gray-1000']" @click="pickEmployee(employee)">
                            <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(employee)" />
                            <span class="min-w-0 flex-1"><span class="block text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(employee) }}</span><span class="mt-0.5 block text-xs text-slate-400">{{ employee.employee_number }} · {{ employee.profession || 'Fonction non renseignée' }}</span></span>
                            <Icon v-if="selectedEmployee?.uuid === employee.uuid" class="text-xl text-primary-600" name="check-circle" />
                        </button>
                        <p v-if="filteredEmployees.length === 0" class="px-4 py-8 text-center text-sm text-slate-400">Aucun personnel actif disponible. Le dossier doit d’abord être créé par l’Administration/RH.</p>
                    </div>
                    <FormError v-if="form.errors.employee_uuid" class="mt-3">{{ form.errors.employee_uuid }}</FormError>
                    <div class="mt-6 flex justify-between border-t border-gray-200 pt-5 dark:border-gray-900"><Button size="rg" variant="white-outline" @click="goBack"><Icon class="me-2" name="arrow-left" />Retour</Button><Button size="rg" :disabled="!identityComplete" @click="continueIdentity">Continuer<Icon class="ms-2" name="arrow-right" /></Button></div>
                </section>

                <section v-else-if="currentStep === 'identity'">
                    <h2 class="text-xl font-bold text-slate-700 dark:text-white">Identité du patient</h2>
                    <p class="mt-1 text-sm text-slate-400">{{ categoryLabels[patientCategory] }} · les champs marqués * sont obligatoires.</p>
                    <div class="mt-6 grid gap-x-4 gap-y-5 lg:grid-cols-12">
                        <label class="lg:col-span-2"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Civilité</span><span class="relative block"><select :class="selectClass" :value="form.civility ?? ''" @change="chooseCivility"><option value="">Choisir</option><option v-for="option in civilityOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select><Icon class="pointer-events-none absolute inset-y-0 end-3 my-auto text-sm text-slate-400" name="chevron-down" /></span><FormError v-if="form.errors.civility" class="mt-1">{{ form.errors.civility }}</FormError></label>
                        <label class="lg:col-span-5"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Nom *</span><IconInput v-model="form.last_name" icon="user" autocomplete="off" /><FormError v-if="form.errors.last_name" class="mt-1">{{ form.errors.last_name }}</FormError></label>
                        <label class="lg:col-span-5"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Prénom(s)</span><IconInput v-model="form.first_name" icon="user" autocomplete="off" /><FormError v-if="form.errors.first_name" class="mt-1">{{ form.errors.first_name }}</FormError></label>

                        <div class="lg:col-span-4"><span class="mb-2 block text-sm font-medium text-slate-700 dark:text-white">Sexe *</span><div class="flex h-9 items-center gap-5"><label class="inline-flex items-center gap-2 text-sm text-slate-600"><input v-model="form.sex" type="radio" value="M" class="text-primary-600 focus:ring-primary-200" />Masculin</label><label class="inline-flex items-center gap-2 text-sm text-slate-600"><input v-model="form.sex" type="radio" value="F" class="text-primary-600 focus:ring-primary-200" />Féminin</label></div><FormError v-if="form.errors.sex" class="mt-1">{{ form.errors.sex }}</FormError></div>
                        <div class="lg:col-span-8"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Pièce d’identité <span class="font-normal text-slate-400">(facultatif)</span></span><div class="grid gap-2 sm:grid-cols-[150px_minmax(0,1fr)]"><span class="relative"><select v-model="form.identity_document_type" :class="selectClass"><option :value="null">Type</option><option value="CIN">CIN</option><option value="PASSPORT">Passeport</option></select><Icon class="pointer-events-none absolute inset-y-0 end-3 my-auto text-sm text-slate-400" name="chevron-down" /></span><IconInput v-model="form.identity_document_number" icon="card-view" placeholder="Numéro du document" /></div><FormError v-if="form.errors.identity_document_number" class="mt-1">{{ form.errors.identity_document_number }}</FormError></div>

                        <div class="lg:col-span-6"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Naissance ou âge *</span><div class="grid gap-2 sm:grid-cols-[auto_minmax(0,1fr)]"><div class="inline-flex rounded border border-gray-200 p-0.5 dark:border-gray-800"><button type="button" :class="['rounded px-3 py-1.5 text-xs font-semibold', birthMode === 'date' ? 'bg-gray-100 text-slate-700 dark:bg-gray-900 dark:text-white' : 'text-slate-400']" @click="setBirthMode('date')">Date de naissance</button><button type="button" :class="['rounded px-3 py-1.5 text-xs font-semibold', birthMode === 'age' ? 'bg-gray-100 text-slate-700 dark:bg-gray-900 dark:text-white' : 'text-slate-400']" @click="setBirthMode('age')">Âge</button></div><Input v-if="birthMode === 'date'" v-model="form.birth_date" type="date" /><Input v-else v-model="form.age" type="number" min="0" max="130" placeholder="Âge en années" /></div><p v-if="birthMode === 'date' && exactAge !== null" class="mt-1 text-xs text-slate-400">Âge calculé automatiquement : {{ exactAge }} ans.</p><FormError v-if="form.errors.birth_date || form.errors.age" class="mt-1">{{ form.errors.birth_date || form.errors.age }}</FormError></div>
                        <label class="lg:col-span-3"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Situation maritale</span><span class="relative block"><select v-model="form.marital_status" :class="selectClass"><option :value="null">Non renseignée</option><option v-for="option in maritalOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select><Icon class="pointer-events-none absolute inset-y-0 end-3 my-auto text-sm text-slate-400" name="chevron-down" /></span><FormError v-if="form.errors.marital_status" class="mt-1">{{ form.errors.marital_status }}</FormError></label>
                        <label class="lg:col-span-3"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Nombre d’enfants</span><Input v-model="form.children_count" type="number" min="0" max="30" /><FormError v-if="form.errors.children_count" class="mt-1">{{ form.errors.children_count }}</FormError></label>
                        <label class="lg:col-span-6"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Profession</span><IconInput v-model="form.profession" icon="briefcase" placeholder="Métier ou activité" /><FormError v-if="form.errors.profession" class="mt-1">{{ form.errors.profession }}</FormError></label>
                    </div>
                    <div class="mt-6 flex justify-between border-t border-gray-200 pt-5 dark:border-gray-900"><Button size="rg" variant="white-outline" @click="goBack"><Icon class="me-2" name="arrow-left" />Retour</Button><Button size="rg" :disabled="!identityComplete" @click="continueIdentity">Continuer<Icon class="ms-2" name="arrow-right" /></Button></div>
                </section>

                <section v-else-if="currentStep === 'contact'">
                    <h2 class="text-xl font-bold text-slate-700 dark:text-white">Coordonnées du patient</h2>
                    <p class="mt-1 text-sm text-slate-400">Séparez les moyens de contact du bloc d’identité pour une saisie plus rapide.</p>
                    <div class="mt-6 grid gap-5 md:grid-cols-2">
                        <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Téléphone</span><IconInput v-model="form.phone" icon="call" autocomplete="tel" /><FormError v-if="form.errors.phone" class="mt-1">{{ form.errors.phone }}</FormError></label>
                        <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Email</span><IconInput v-model="form.email" icon="mail" type="email" autocomplete="email" /><FormError v-if="form.errors.email" class="mt-1">{{ form.errors.email }}</FormError></label>
                        <div class="md:col-span-2"><div class="mb-1.5 flex items-center justify-between"><span class="text-sm font-medium text-slate-700 dark:text-white">Adresse</span><button type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-primary-600" @click="toggleNewAddress"><Icon :name="showNewAddress ? 'minus' : 'plus'" />{{ showNewAddress ? 'Choisir dans la liste' : 'Ajouter une adresse' }}</button></div><div v-if="!showNewAddress" class="relative"><select :class="selectClass" :value="form.address_entry_uuid" @change="selectAddress"><option value="">Adresse non renseignée</option><option v-for="address in addressEntries" :key="address.uuid" :value="address.uuid">{{ address.label }}</option></select><Icon class="pointer-events-none absolute inset-y-0 end-3 my-auto text-sm text-slate-400" name="chevron-down" /></div><IconInput v-else v-model="form.new_address_label" icon="map-pin" placeholder="Saisissez la nouvelle adresse" /><FormError v-if="form.errors.address_entry_uuid || form.errors.new_address_label" class="mt-1">{{ form.errors.address_entry_uuid || form.errors.new_address_label }}</FormError></div>
                    </div>

                    <div class="mt-6 rounded-md border border-gray-200 bg-gray-50/50 p-4 dark:border-gray-800 dark:bg-gray-1000/30">
                        <h3 class="text-sm font-bold text-slate-700 dark:text-white">Personne à contacter <span class="font-normal text-slate-400">(facultatif)</span></h3>
                        <div class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                            <label><span class="mb-1.5 block text-xs font-medium text-slate-500">Nom complet</span><IconInput v-model="form.emergency_contact_name" icon="user" /></label>
                            <label><span class="mb-1.5 block text-xs font-medium text-slate-500">Lien de parenté</span><Input v-model="form.emergency_contact_relationship" /></label>
                            <label><span class="mb-1.5 block text-xs font-medium text-slate-500">Téléphone</span><IconInput v-model="form.emergency_contact_phone" icon="call" /></label>
                            <label><span class="mb-1.5 block text-xs font-medium text-slate-500">Email</span><IconInput v-model="form.emergency_contact_email" icon="mail" type="email" /></label>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-between border-t border-gray-200 pt-5 dark:border-gray-900"><Button size="rg" variant="white-outline" @click="goBack"><Icon class="me-2" name="arrow-left" />Retour</Button><Button size="rg" @click="continueContact">Continuer<Icon class="ms-2" name="arrow-right" /></Button></div>
                </section>

                <section v-else-if="currentStep === 'coverage' && patientCategory === 'MUTUAL'">
                    <h2 class="text-xl font-bold text-slate-700 dark:text-white">Informations de mutuelle</h2>
                    <p class="mt-1 text-sm text-slate-400">Enregistrez l’adhésion administrative et ses justificatifs privés.</p>
                    <div class="mt-6 grid gap-5 md:grid-cols-2">
                        <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Nom de la mutuelle *</span><Input v-model="form.mutual_organization_name" list="mutual-organizations" placeholder="Mutuelle ou organisme" /><datalist id="mutual-organizations"><option v-for="organization in mutualOrganizations" :key="organization.uuid" :value="organization.name" /></datalist><FormError v-if="form.errors.mutual_organization_name" class="mt-1">{{ form.errors.mutual_organization_name }}</FormError></label>
                        <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Entreprise du patient *</span><IconInput v-model="form.mutual_employer_name" icon="building" /><FormError v-if="form.errors.mutual_employer_name" class="mt-1">{{ form.errors.mutual_employer_name }}</FormError></label>
                        <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Bénéficiaire *</span><span class="relative block"><select v-model="form.mutual_beneficiary_type" :class="selectClass"><option value="PRINCIPAL">Principal</option><option value="FAMILY_MEMBER">Membre de famille</option></select><Icon class="pointer-events-none absolute inset-y-0 end-3 my-auto text-sm text-slate-400" name="chevron-down" /></span><FormError v-if="form.errors.mutual_beneficiary_type" class="mt-1">{{ form.errors.mutual_beneficiary_type }}</FormError></label>
                        <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Numéro matricule *</span><IconInput v-model="form.mutual_membership_number" icon="card-view" /><FormError v-if="form.errors.mutual_membership_number" class="mt-1">{{ form.errors.mutual_membership_number }}</FormError></label>
                        <div class="md:col-span-2">
                            <div class="mb-2 flex flex-wrap items-end justify-between gap-2">
                                <div>
                                    <span class="block text-sm font-medium text-slate-700 dark:text-white">Justificatifs <span class="font-normal text-slate-400">(facultatif)</span></span>
                                    <span class="mt-0.5 block text-xs text-slate-400">Carte de mutuelle, pièce d’identité ou autre justificatif privé.</span>
                                </div>
                                <span class="text-xs font-medium text-slate-400">{{ mutualAttachmentPreviews.length }} / 5 fichiers</span>
                            </div>

                            <div
                                :class="[
                                    'overflow-hidden rounded-md border border-dashed transition-colors',
                                    mutualAttachmentClientError || mutualAttachmentServerError
                                        ? 'border-red-400 bg-red-50/40 dark:border-red-900 dark:bg-red-950/20'
                                        : 'border-gray-300 bg-gray-50/60 hover:border-primary-400 dark:border-gray-800 dark:bg-gray-1000/30 dark:hover:border-primary-700',
                                ]"
                                @dragover.prevent
                                @drop.prevent="dropMutualAttachments"
                            >
                                <input
                                    id="mutual_attachments"
                                    ref="fileInput"
                                    type="file"
                                    class="sr-only"
                                    multiple
                                    accept="image/jpeg,image/png,image/webp,application/pdf"
                                    :aria-invalid="Boolean(mutualAttachmentClientError || mutualAttachmentServerError)"
                                    @change="selectAttachments"
                                />

                                <label v-if="!mutualAttachmentPreviews.length" for="mutual_attachments" class="flex cursor-pointer flex-col items-center px-4 py-6 text-center">
                                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-white text-slate-500 shadow-sm dark:bg-gray-900 dark:text-slate-300">
                                        <Icon class="text-xl" name="upload-cloud" />
                                    </span>
                                    <span class="mt-2 text-sm font-bold text-slate-700 dark:text-white">Ajouter les justificatifs</span>
                                    <span class="mt-1 text-xs leading-5 text-slate-400">Images JPEG, PNG, WebP ou PDF · 5 Mo par fichier</span>
                                    <span class="text-[11px] leading-5 text-slate-400">Cliquez ou déposez jusqu’à 5 fichiers ici.</span>
                                </label>

                                <div v-else class="p-3">
                                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                        <article
                                            v-for="(attachment, index) in mutualAttachmentPreviews"
                                            :key="`${attachment.file.name}-${attachment.file.lastModified}-${index}`"
                                            class="group overflow-hidden rounded-md border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950"
                                        >
                                            <div class="relative">
                                                <button
                                                    type="button"
                                                    class="flex h-24 w-full items-center justify-center overflow-hidden bg-gray-100 text-slate-500 transition hover:bg-gray-200 dark:bg-gray-900 dark:text-slate-300 dark:hover:bg-gray-800"
                                                    :aria-label="`Aperçu de ${attachment.file.name}`"
                                                    @click="activeMutualAttachment = attachment"
                                                >
                                                    <img v-if="attachment.isImage" :src="attachment.previewUrl" :alt="attachment.file.name" class="h-full w-full object-cover" />
                                                    <span v-else class="flex flex-col items-center">
                                                        <Icon class="text-3xl" name="file-text" />
                                                        <span class="mt-1 text-[10px] font-bold uppercase tracking-wide">PDF</span>
                                                    </span>
                                                    <span class="absolute inset-x-0 bottom-0 bg-slate-950/65 px-2 py-1 text-[10px] font-semibold text-white opacity-0 transition group-hover:opacity-100">Voir l’aperçu</span>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="absolute end-1.5 top-1.5 flex h-7 w-7 items-center justify-center rounded-full bg-white/95 text-slate-500 shadow-sm transition hover:text-red-600 dark:bg-gray-950/95 dark:text-slate-300"
                                                    :aria-label="`Retirer ${attachment.file.name}`"
                                                    @click="removeAttachment(index)"
                                                >
                                                    <Icon name="cross" />
                                                </button>
                                            </div>
                                            <div class="min-w-0 px-3 py-2.5">
                                                <p class="truncate text-xs font-bold text-slate-700 dark:text-white" :title="attachment.file.name">{{ attachment.file.name }}</p>
                                                <p class="mt-0.5 text-[11px] text-slate-400">{{ attachment.isImage ? 'Image' : 'Document PDF' }} · {{ formatFileSize(attachment.file.size) }}</p>
                                            </div>
                                        </article>

                                        <label v-if="mutualAttachmentPreviews.length < maxMutualAttachments" for="mutual_attachments" class="flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-md border border-dashed border-gray-300 bg-white px-4 py-3 text-center transition hover:border-primary-400 dark:border-gray-800 dark:bg-gray-950">
                                            <Icon class="text-xl text-slate-400" name="plus" />
                                            <span class="mt-1.5 text-xs font-bold text-slate-600 dark:text-slate-200">Ajouter</span>
                                            <span class="mt-0.5 text-[11px] text-slate-400">{{ maxMutualAttachments - mutualAttachmentPreviews.length }} place{{ maxMutualAttachments - mutualAttachmentPreviews.length > 1 ? 's' : '' }} restante{{ maxMutualAttachments - mutualAttachmentPreviews.length > 1 ? 's' : '' }}</span>
                                        </label>
                                    </div>

                                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 pt-3 dark:border-gray-800">
                                        <span class="inline-flex items-center gap-1.5 text-[11px] text-slate-400"><Icon name="lock" />Stockage privé, réservé aux utilisateurs autorisés.</span>
                                        <button type="button" class="text-xs font-semibold text-red-600 hover:underline dark:text-red-300" @click="clearMutualAttachments">Tout retirer</button>
                                    </div>
                                </div>
                            </div>

                            <FormError v-if="mutualAttachmentClientError" class="mt-1">{{ mutualAttachmentClientError }}</FormError>
                            <FormError v-else-if="mutualAttachmentServerError" class="mt-1">{{ mutualAttachmentServerError }}</FormError>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-between border-t border-gray-200 pt-5 dark:border-gray-900"><Button size="rg" variant="white-outline" @click="goBack"><Icon class="me-2" name="arrow-left" />Retour</Button><Button size="rg" :disabled="!coverageComplete" @click="continueCoverage">Continuer<Icon class="ms-2" name="arrow-right" /></Button></div>
                </section>

                <section v-else-if="currentStep === 'coverage' && patientCategory === 'STAFF'">
                    <h2 class="text-xl font-bold text-slate-700 dark:text-white">Lien avec le personnel</h2>
                    <p class="mt-1 text-sm text-slate-400">Vérifiez le dossier RH sélectionné avant de créer ou réutiliser son dossier patient.</p>
                    <div v-if="selectedEmployee" class="mt-5 max-w-3xl rounded-md border border-gray-200 p-5 dark:border-gray-800"><div class="flex items-center gap-3"><Avatar rounded size="md" variant="slate-pale" :text="formatPatientInitials(selectedEmployee)" /><div><p class="font-bold text-slate-700 dark:text-white">{{ formatPatientName(selectedEmployee) }}</p><p class="mt-0.5 text-xs text-slate-400">{{ selectedEmployee.employee_number }} · {{ selectedEmployee.profession || 'Fonction non renseignée' }}</p></div></div><dl class="mt-4 grid gap-4 border-t border-gray-100 pt-4 text-sm sm:grid-cols-3 dark:border-gray-900"><div><dt class="text-xs text-slate-400">Téléphone</dt><dd class="mt-1 font-medium text-slate-600 dark:text-slate-200">{{ selectedEmployee.phone || 'Non renseigné' }}</dd></div><div><dt class="text-xs text-slate-400">Email</dt><dd class="mt-1 break-all font-medium text-slate-600 dark:text-slate-200">{{ selectedEmployee.email || 'Non renseigné' }}</dd></div><div><dt class="text-xs text-slate-400">Adresse</dt><dd class="mt-1 font-medium text-slate-600 dark:text-slate-200">{{ selectedEmployee.address || 'Non renseignée' }}</dd></div></dl></div>
                    <div class="mt-4 max-w-3xl rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"><strong>Couverture personnel :</strong> l’éligibilité est liée ici, mais le crédit bloc sera calculé par RH / Finance. La Réception ne modifie pas ce crédit et aucun faux paiement ne sera généré.</div>
                    <div class="mt-6 flex justify-between border-t border-gray-200 pt-5 dark:border-gray-900"><Button size="rg" variant="white-outline" @click="goBack"><Icon class="me-2" name="arrow-left" />Retour</Button><Button size="rg" :disabled="!coverageComplete" @click="continueCoverage">Continuer<Icon class="ms-2" name="arrow-right" /></Button></div>
                </section>

                <section v-else-if="currentStep === 'confirmation'">
                    <h2 class="text-xl font-bold text-slate-700 dark:text-white">Confirmer l’arrivée</h2>
                    <p class="mt-1 text-sm text-slate-400">Le dossier patient et son nouveau numéro de passage seront créés ensemble.</p>
                    <div v-if="form.is_emergency" class="mt-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300"><strong>Urgence médicale :</strong> le patient sera immédiatement visible aux Soins et en Médecine.</div>
                    <div class="mt-5 rounded-md border border-gray-200 dark:border-gray-800">
                        <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800"><div class="flex items-center gap-3"><Avatar rounded size="md" variant="slate-pale" :text="formatPatientInitials(recapPatient ?? { first_name: form.first_name, last_name: form.last_name })" /><div><p class="text-base font-bold text-slate-700 dark:text-white">{{ recapName }}</p><p class="mt-0.5 text-xs text-slate-400">{{ arrivalMode === 'existing' ? selectedPatient?.patient_number : categoryLabels[patientCategory] }}</p></div></div><span class="w-fit rounded border border-gray-200 px-2.5 py-1 text-xs font-semibold text-slate-500 dark:border-gray-800">{{ arrivalMode === 'existing' ? 'Dossier existant' : 'Nouveau dossier' }}</span></div>
                        <dl v-if="arrivalMode !== 'existing' && patientCategory !== 'STAFF'" class="grid gap-px bg-gray-200 sm:grid-cols-2 lg:grid-cols-4 dark:bg-gray-800"><div class="bg-white px-5 py-3 dark:bg-gray-950"><dt class="text-xs text-slate-400">{{ birthMode === 'date' ? 'Date de naissance' : 'Âge' }}</dt><dd class="mt-1 text-sm font-semibold text-slate-700 dark:text-white">{{ birthMode === 'date' ? (form.birth_date ? `${formatDate(form.birth_date)} · ${exactAge} ans` : 'Non renseignée') : `${form.age} ans` }}</dd></div><div class="bg-white px-5 py-3 dark:bg-gray-950"><dt class="text-xs text-slate-400">Sexe</dt><dd class="mt-1 text-sm font-semibold text-slate-700 dark:text-white">{{ form.sex === 'M' ? 'Masculin' : 'Féminin' }}</dd></div><div class="bg-white px-5 py-3 dark:bg-gray-950"><dt class="text-xs text-slate-400">Téléphone</dt><dd class="mt-1 text-sm font-semibold text-slate-700 dark:text-white">{{ form.phone || 'Non renseigné' }}</dd></div><div class="bg-white px-5 py-3 dark:bg-gray-950"><dt class="text-xs text-slate-400">Couverture</dt><dd class="mt-1 text-sm font-semibold text-slate-700 dark:text-white">{{ categoryLabels[patientCategory] }}</dd></div></dl>
                    </div>
                    <div class="mt-4 rounded-md border border-dashed border-gray-300 px-4 py-3 text-xs leading-5 text-slate-500 dark:border-gray-700"><strong>Étape suivante :</strong> le système ouvre la page du passage. La Réception y sélectionnera ECG, échographie, consultation ou soins ; Laravel calculera le parcours clinique depuis le référentiel.</div>
                    <div v-if="duplicates.length" class="mt-4 rounded-md border border-amber-300 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30"><p class="text-sm font-semibold text-amber-800 dark:text-amber-200">Un dossier similaire existe déjà.</p><ul class="mt-2 text-xs text-amber-700 dark:text-amber-300"><li v-for="match in duplicates" :key="match.uuid">{{ match.patient_number }} · {{ formatPatientName(match) }}</li></ul><Button class="mt-3" size="sm" variant="white-outline" :disabled="form.processing" @click="submitArrival(true)">Créer quand même</Button></div>
                    <div class="mt-6 flex justify-between border-t border-gray-200 pt-5 dark:border-gray-900"><Button size="rg" variant="white-outline" @click="goBack"><Icon class="me-2" name="arrow-left" />Modifier</Button><Button size="rg" :disabled="form.processing || (arrivalMode === 'existing' && !selectedPatient)" @click="submitArrival(false)"><Icon class="me-2" name="check" />{{ form.processing ? 'Enregistrement…' : 'Créer le passage' }}</Button></div>
                </section>
            </CardBody>
        </Card>

        <Card v-else class="overflow-hidden shadow-sm">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-900"><div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900"><Icon name="clock" /></span><div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Passages patients</h2><p class="mt-0.5 text-xs text-slate-400">Arrivées récentes et état du parcours clinique.</p></div></div><span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-slate-500 dark:bg-gray-900">{{ recentEpisodes.length }} affiché{{ recentEpisodes.length > 1 ? 's' : '' }}</span></div>
            <div class="flex items-center gap-3 border-b border-gray-200 bg-gray-50/50 px-5 py-3 dark:border-gray-900 dark:bg-gray-1000/30"><span class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Afficher</span><div class="inline-flex overflow-x-auto rounded-md border border-gray-200 bg-white p-0.5 dark:border-gray-800 dark:bg-gray-950"><Link v-for="option in recentFilterOptions" :key="option.value" :href="recentFilterHref(option.value)" replace preserve-scroll :class="['shrink-0 rounded px-3 py-1.5 text-xs font-semibold', activeRecentFilter === option.value ? 'bg-gray-100 text-slate-700 dark:bg-gray-900 dark:text-white' : 'text-slate-400']">{{ option.label }}</Link></div></div>
            <div v-if="!recentEpisodes.length" class="px-5 py-12 text-center"><p class="text-sm font-semibold text-slate-600 dark:text-slate-200">Aucun passage dans cette vue</p><Button class="mt-4" size="sm" @click="startArrival">Nouvelle arrivée</Button></div>
            <div v-else class="overflow-x-auto"><table class="w-full min-w-[920px]"><thead><tr class="bg-gray-50/60 text-start text-xs uppercase tracking-wide text-slate-400 dark:bg-gray-1000/40"><th class="px-5 py-2.5 text-start">Patient</th><th class="px-5 py-2.5 text-start">Passage</th><th class="px-5 py-2.5 text-start">Arrivée</th><th class="px-5 py-2.5 text-start">Priorité</th><th class="px-5 py-2.5 text-start">Parcours</th><th class="px-5 py-2.5 text-end">Action</th></tr></thead><tbody><tr v-for="episode in recentEpisodes" :key="episode.uuid" class="border-t border-gray-200 hover:bg-gray-50/50 dark:border-gray-900 dark:hover:bg-gray-1000/30"><td class="px-5 py-3"><div class="flex items-center gap-3"><Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(episode.patient)" /><div><Link v-if="can('patients.view')" :href="`/patients/${episode.patient.uuid}`" class="text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white">{{ formatPatientName(episode.patient) }}</Link><p class="mt-0.5 text-xs text-slate-400">{{ episode.patient.patient_number }}</p></div></div></td><td class="px-5 py-3 font-mono text-sm font-semibold text-slate-600 dark:text-slate-300">{{ episode.episode_number }}</td><td class="px-5 py-3"><span class="block text-sm text-slate-600 dark:text-slate-300">{{ formatDateTime(episode.started_at) }}</span><span class="text-xs text-slate-400">{{ formatRelativeTime(episode.started_at) }}</span></td><td class="px-5 py-3"><span :class="['text-xs font-semibold', episode.priority === 'EMERGENCY' ? 'text-red-600' : 'text-slate-500']">{{ episode.priority === 'EMERGENCY' ? 'Urgence' : 'Normale' }}</span></td><td class="px-5 py-3"><span class="rounded border border-gray-200 px-2 py-1 text-xs font-semibold text-slate-500 dark:border-gray-800">{{ pathwayStatus(episode) }}</span></td><td class="px-5 py-3 text-end"><Button v-if="can('episodes.update') && !episode.service_plan_finalized_at" :as="Link" :href="`/reception/passages/${episode.uuid}/prestations`" size="sm" variant="white-outline">Préparer</Button><Button v-else-if="can('patients.view')" :as="Link" :href="`/patients/${episode.patient.uuid}`" icon size="sm" variant="white-outline"><Icon name="eye" /></Button></td></tr></tbody></table></div>
        </Card>
    </div>

    <Dialog :open="Boolean(activeMutualAttachment)" as="div" class="relative z-[1200]" @close="activeMutualAttachment = null">
        <div class="fixed inset-0 bg-slate-950/70" aria-hidden="true"></div>
        <div class="fixed inset-0 overflow-y-auto p-4">
            <div class="flex min-h-full items-center justify-center">
                <DialogPanel v-if="activeMutualAttachment" class="flex max-h-[92vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-950">
                <header class="flex items-center justify-between gap-4 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                    <div class="min-w-0">
                        <DialogTitle class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ activeMutualAttachment.file.name }}</DialogTitle>
                        <p class="mt-0.5 text-xs text-slate-400">{{ activeMutualAttachment.isImage ? 'Image' : 'Document PDF' }} · {{ formatFileSize(activeMutualAttachment.file.size) }}</p>
                    </div>
                    <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded text-slate-400 hover:bg-gray-100 hover:text-slate-700 dark:hover:bg-gray-900 dark:hover:text-white" aria-label="Fermer l’aperçu" @click="activeMutualAttachment = null">
                        <Icon class="text-xl" name="cross" />
                    </button>
                </header>

                <div class="flex min-h-64 flex-1 items-center justify-center overflow-auto bg-gray-100 p-4 dark:bg-gray-1000/60 sm:min-h-[28rem]">
                    <img v-if="activeMutualAttachment.isImage" :src="activeMutualAttachment.previewUrl" :alt="activeMutualAttachment.file.name" class="max-h-[72vh] max-w-full object-contain" />
                    <div v-else class="flex flex-col items-center text-center">
                        <span class="flex h-20 w-20 items-center justify-center rounded-full bg-white text-slate-500 shadow-sm dark:bg-gray-900 dark:text-slate-300">
                            <Icon class="text-4xl" name="file-text" />
                        </span>
                        <p class="mt-4 text-sm font-bold text-slate-700 dark:text-white">Document PDF prêt à être joint</p>
                        <p class="mt-1 text-xs text-slate-400">Ouvrez-le dans un nouvel onglet pour vérifier son contenu.</p>
                        <a :href="activeMutualAttachment.previewUrl" target="_blank" rel="noopener" class="mt-4 inline-flex items-center gap-2 rounded-md border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:border-gray-300 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200">
                            <Icon name="eye" />Ouvrir le PDF
                        </a>
                    </div>
                </div>
                </DialogPanel>
            </div>
        </div>
    </Dialog>
</template>
