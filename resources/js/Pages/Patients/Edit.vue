<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconInput from '@/Components/UI/IconInput.vue';
import Input from '@/Components/UI/Input.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    patient: { type: Object, required: true },
    addressEntries: { type: Array, default: () => [] },
});

const { can } = usePermissions();
const civilityOptions = [
    { value: 'MR', label: 'M.', sex: 'M' },
    { value: 'MRS', label: 'Mme', sex: 'F' },
    { value: 'GIRL', label: 'Enfant fille', sex: 'F' },
    { value: 'BOY', label: 'Enfant garçon', sex: 'M' },
];
const patientTypeLabels = {
    STANDARD: 'Patient standard',
    MUTUAL: 'Patient avec mutuelle',
    STAFF: 'Personnel de la clinique',
};
const maritalStatusOptions = [
    { value: 'SINGLE', label: 'Célibataire' },
    { value: 'MARRIED', label: 'Marié(e)' },
    { value: 'DIVORCED', label: 'Divorcé(e)' },
    { value: 'WIDOWED', label: 'Veuf / Veuve' },
];
const beneficiaryTypeLabels = {
    PRINCIPAL: 'Principal',
    FAMILY_MEMBER: 'Membre de famille',
};

const patientTypeLabel = computed(() => patientTypeLabels[props.patient.patient_type] ?? 'Patient standard');
const isStaffPatient = computed(() => props.patient.patient_type === 'STAFF');
const isMutualPatient = computed(() => props.patient.patient_type === 'MUTUAL');
const activeMutualCoverage = computed(() => props.patient.active_mutual_coverage ?? null);
const existingAttachments = computed(() => activeMutualCoverage.value?.attachments ?? []);
const canViewCoverage = computed(() => can('patient_coverages.view'));
const canViewCoverageDocuments = computed(() => can('patient_coverage_documents.view'));
const canAddCoverageDocuments = computed(() => can('patient_coverage_documents.create') && Boolean(activeMutualCoverage.value));

const birthDateMode = ref(props.patient.birth_date ? 'date' : 'age');
const currentAddressUuid = props.patient.address_entry?.uuid ?? props.patient.address_entry_uuid ?? '';
const legacyAddress = !currentAddressUuid ? (props.patient.address ?? '') : '';
const showNewAddress = ref(Boolean(legacyAddress));

const form = useForm({
    first_name: props.patient.first_name ?? '',
    last_name: props.patient.last_name ?? '',
    birth_date: props.patient.birth_date ?? '',
    age: props.patient.birth_date ? '' : (props.patient.declared_age ?? props.patient.age ?? ''),
    sex: props.patient.sex ?? 'M',
    civility: props.patient.civility ?? null,
    identity_document_type: props.patient.identity_document_type ?? null,
    identity_document_number: props.patient.identity_document_number ?? '',
    marital_status: props.patient.marital_status ?? null,
    children_count: props.patient.children_count ?? '',
    profession: props.patient.profession ?? '',
    phone: props.patient.phone ?? '',
    email: props.patient.email ?? '',
    address_entry_uuid: currentAddressUuid,
    new_address_label: legacyAddress,
    emergency_contact_name: props.patient.emergency_contact_name ?? '',
    emergency_contact_phone: props.patient.emergency_contact_phone ?? '',
    emergency_contact_relationship: props.patient.emergency_contact_relationship ?? '',
    emergency_contact_email: props.patient.emergency_contact_email ?? '',
});

const exactAge = computed(() => {
    if (!form.birth_date) return null;
    const birthDate = new Date(`${form.birth_date}T00:00:00`);
    if (Number.isNaN(birthDate.getTime())) return null;
    const today = new Date();
    let age = today.getFullYear() - birthDate.getFullYear();
    const beforeBirthday = today.getMonth() < birthDate.getMonth()
        || (today.getMonth() === birthDate.getMonth() && today.getDate() < birthDate.getDate());
    if (beforeBirthday) age--;
    return age >= 0 ? age : null;
});
const canSubmit = computed(() => Boolean(form.last_name) && Boolean(form.sex) && Boolean(form.birth_date || form.age !== ''));

const chooseCivility = (event) => {
    const value = event.target.value || null;
    form.civility = value;
    form.sex = civilityOptions.find((option) => option.value === value)?.sex ?? form.sex;
};
const setBirthDateMode = (mode) => {
    birthDateMode.value = mode;
    if (mode === 'date') form.age = '';
    else form.birth_date = '';
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
const submit = () => {
    form.transform((data) => ({
        ...data,
        birth_date: birthDateMode.value === 'date' ? data.birth_date : null,
        age: birthDateMode.value === 'age' ? data.age : null,
        address_entry_uuid: showNewAddress.value ? null : (data.address_entry_uuid || null),
        new_address_label: showNewAddress.value ? (data.new_address_label || null) : null,
    })).put(`/patients/${props.patient.uuid}`, { preserveScroll: true });
};

const acceptedAttachmentTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
const maxAttachmentSize = 5 * 1024 * 1024;
const maxAttachments = 5;
const attachmentInput = ref(null);
const attachmentPreviews = ref([]);
const previewedAttachment = ref(null);
const attachmentClientError = ref('');
const isDraggingAttachments = ref(false);
const attachmentForm = useForm({ files: [] });
const existingAttachmentCount = computed(() => existingAttachments.value.length);
const remainingAttachmentSlots = computed(() => Math.max(0, maxAttachments - existingAttachmentCount.value));
const availableDraftSlots = computed(() => Math.max(0, remainingAttachmentSlots.value - attachmentPreviews.value.length));
const attachmentServerError = computed(() => {
    const error = Object.entries(attachmentForm.errors).find(([key]) => key === 'files' || key.startsWith('files.'));
    return error?.[1] ?? '';
});

const formatFileSize = (bytes) => {
    if (!bytes) return '0 Ko';
    return bytes >= 1024 * 1024
        ? `${(bytes / (1024 * 1024)).toFixed(1)} Mo`
        : `${Math.max(1, Math.round(bytes / 1024))} Ko`;
};
const attachmentUrl = (attachment) => attachment.previewUrl
    ?? attachment.url
    ?? `/reception/mutual-coverages/${activeMutualCoverage.value?.uuid}/attachments/${attachment.uuid}`;
const attachmentName = (attachment) => attachment.file?.name ?? attachment.original_name ?? 'Justificatif';
const attachmentSize = (attachment) => attachment.file?.size ?? attachment.size ?? 0;
const attachmentIsImage = (attachment) => attachment.isImage ?? attachment.is_image ?? false;
const resetAttachmentInput = () => { if (attachmentInput.value) attachmentInput.value.value = ''; };
const clearAttachmentErrors = () => {
    attachmentClientError.value = '';
    Object.keys(attachmentForm.errors)
        .filter((key) => key === 'files' || key.startsWith('files.'))
        .forEach((key) => attachmentForm.clearErrors(key));
};
const revokeAttachmentUrl = (attachment) => { if (attachment?.previewUrl) URL.revokeObjectURL(attachment.previewUrl); };
const syncAttachmentFiles = () => { attachmentForm.files = attachmentPreviews.value.map((attachment) => attachment.file); };

const addAttachmentFiles = (fileList) => {
    clearAttachmentErrors();
    const errors = [];
    let ignoredCount = 0;
    Array.from(fileList ?? []).forEach((file) => {
        if (attachmentPreviews.value.length >= remainingAttachmentSlots.value) {
            ignoredCount++;
            return;
        }
        if (!acceptedAttachmentTypes.includes(file.type)) {
            errors.push(`${file.name} : format non accepté.`);
            return;
        }
        if (file.size > maxAttachmentSize) {
            errors.push(`${file.name} dépasse 5 Mo.`);
            return;
        }
        const alreadySelected = attachmentPreviews.value.some((attachment) => (
            attachment.file.name === file.name
            && attachment.file.size === file.size
            && attachment.file.lastModified === file.lastModified
        ));
        const alreadyStored = existingAttachments.value.some((attachment) => (
            attachment.original_name === file.name && Number(attachment.size) === file.size
        ));
        if (alreadySelected || alreadyStored) {
            errors.push(`${file.name} est déjà présent.`);
            return;
        }
        attachmentPreviews.value.push({
            file,
            isImage: file.type.startsWith('image/'),
            previewUrl: URL.createObjectURL(file),
        });
    });
    if (ignoredCount > 0) {
        errors.push(`${ignoredCount} fichier${ignoredCount > 1 ? 's ont' : ' a'} été ignoré${ignoredCount > 1 ? 's' : ''} : cinq justificatifs maximum au total.`);
    }
    attachmentClientError.value = errors.join(' ');
    syncAttachmentFiles();
    resetAttachmentInput();
};
const selectAttachments = (event) => addAttachmentFiles(event.target.files);
const dropAttachments = (event) => {
    isDraggingAttachments.value = false;
    addAttachmentFiles(event.dataTransfer?.files);
};
const removeDraftAttachment = (index) => {
    const [removed] = attachmentPreviews.value.splice(index, 1);
    if (previewedAttachment.value === removed) previewedAttachment.value = null;
    revokeAttachmentUrl(removed);
    syncAttachmentFiles();
    clearAttachmentErrors();
    resetAttachmentInput();
};
const clearDraftAttachments = () => {
    attachmentPreviews.value.forEach(revokeAttachmentUrl);
    attachmentPreviews.value = [];
    previewedAttachment.value = null;
    syncAttachmentFiles();
    clearAttachmentErrors();
    resetAttachmentInput();
};
const uploadAttachments = () => {
    if (!activeMutualCoverage.value || !attachmentForm.files.length) return;
    attachmentForm.post(`/patients/${props.patient.uuid}/mutual-coverages/${activeMutualCoverage.value.uuid}/attachments`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: clearDraftAttachments,
    });
};

onBeforeUnmount(() => attachmentPreviews.value.forEach(revokeAttachmentUrl));

const selectClass = 'block h-9 w-full appearance-none rounded border border-gray-200 bg-white px-4 py-1.5 pe-10 text-sm text-slate-700 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:border-primary-600 dark:focus:ring-primary-950';
</script>

<template>
    <Head :title="isStaffPatient ? `Dossier ${formatPatientName(patient)}` : `Modifier ${formatPatientName(patient)}`" />

    <div class="mx-auto w-full max-w-screen-xl space-y-4">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-3.5">
                <Avatar rounded size="lg" variant="slate-pale" :text="formatPatientInitials(patient)" aria-hidden="true" />
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">{{ isStaffPatient ? 'Dossier du personnel' : 'Modification du dossier' }}</span>
                        <span class="rounded border border-gray-200 px-2 py-0.5 text-[11px] font-semibold text-slate-500 dark:border-gray-800 dark:text-slate-300">{{ patientTypeLabel }}</span>
                    </div>
                    <h1 class="mt-1 truncate font-heading text-2xl font-bold text-slate-800 dark:text-white">{{ formatPatientName(patient) }}</h1>
                    <p class="mt-0.5 truncate font-mono text-xs text-slate-400">{{ patient.patient_number }}</p>
                </div>
            </div>
            <Button :as="Link" :href="`/patients/${patient.uuid}`" size="rg" variant="white-outline"><Icon class="text-lg" name="arrow-left" /><span class="ms-2">Retour au dossier</span></Button>
        </header>

        <section v-if="isStaffPatient" class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-900 dark:bg-gray-950">
            <div class="flex items-start gap-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-xl" name="lock" /></span>
                <div>
                    <h2 class="text-base font-bold text-slate-700 dark:text-white">Dossier verrouillé dans le module Patients</h2>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-500 dark:text-slate-400">L’identité et les coordonnées de ce patient proviennent de son dossier Employé. Les corrections doivent être réalisées par les Ressources humaines afin de conserver une source unique et cohérente.</p>
                    <Button :as="Link" :href="`/patients/${patient.uuid}`" class="mt-5" size="sm" variant="white-outline">Consulter le dossier</Button>
                </div>
            </div>
        </section>

        <div v-else :class="['grid items-start gap-4', isMutualPatient ? 'xl:grid-cols-[minmax(0,1fr)_370px]' : '']">
            <form id="patient-edit-form" class="space-y-4" @submit.prevent="submit">
                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                    <header class="flex items-start gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" name="user" /></span>
                        <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Identité administrative</h2><p class="mt-0.5 text-xs text-slate-400">Informations permanentes du dossier patient. <span class="text-red-500">*</span> champ obligatoire.</p></div>
                    </header>

                    <div class="p-5">
                        <div class="grid gap-x-4 gap-y-5 lg:grid-cols-12">
                            <label class="lg:col-span-2">
                                <span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Civilité</span>
                                <span class="relative block"><select :class="selectClass" :value="form.civility ?? ''" @change="chooseCivility"><option value="">Choisir</option><option v-for="option in civilityOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select><Icon class="pointer-events-none absolute inset-y-0 end-3 my-auto text-sm text-slate-400" name="chevron-down" /></span>
                                <FormError v-if="form.errors.civility" class="mt-1">{{ form.errors.civility }}</FormError>
                            </label>
                            <label class="lg:col-span-5"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Nom <span class="text-red-500">*</span></span><IconInput v-model="form.last_name" icon="user" autocomplete="family-name" required /><FormError v-if="form.errors.last_name" class="mt-1">{{ form.errors.last_name }}</FormError></label>
                            <label class="lg:col-span-5"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Prénom(s)</span><IconInput v-model="form.first_name" icon="user" autocomplete="given-name" /><FormError v-if="form.errors.first_name" class="mt-1">{{ form.errors.first_name }}</FormError></label>

                            <fieldset class="lg:col-span-4">
                                <legend class="mb-2 text-sm font-medium text-slate-700 dark:text-white">Sexe <span class="text-red-500">*</span></legend>
                                <div class="flex h-9 items-center gap-6"><label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300"><input v-model="form.sex" type="radio" value="M" class="text-primary-600 focus:ring-primary-200" />Masculin</label><label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300"><input v-model="form.sex" type="radio" value="F" class="text-primary-600 focus:ring-primary-200" />Féminin</label></div>
                                <FormError v-if="form.errors.sex" class="mt-1">{{ form.errors.sex }}</FormError>
                            </fieldset>
                            <div class="lg:col-span-8">
                                <span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Pièce d’identité <span class="font-normal text-slate-400">(facultatif)</span></span>
                                <div class="grid gap-2 sm:grid-cols-[150px_minmax(0,1fr)]"><span class="relative"><select v-model="form.identity_document_type" :class="selectClass"><option :value="null">Type</option><option value="CIN">CIN</option><option value="PASSPORT">Passeport</option></select><Icon class="pointer-events-none absolute inset-y-0 end-3 my-auto text-sm text-slate-400" name="chevron-down" /></span><IconInput v-model="form.identity_document_number" icon="card-view" :disabled="!form.identity_document_type" placeholder="Numéro du document" /></div>
                                <FormError v-if="form.errors.identity_document_type || form.errors.identity_document_number" class="mt-1">{{ form.errors.identity_document_type || form.errors.identity_document_number }}</FormError>
                            </div>

                            <div class="lg:col-span-6">
                                <span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Naissance ou âge <span class="text-red-500">*</span></span>
                                <div class="grid gap-2 sm:grid-cols-[auto_minmax(0,1fr)]"><div class="inline-flex rounded border border-gray-200 p-0.5 dark:border-gray-800"><button type="button" :class="['rounded px-3 py-1.5 text-xs font-semibold transition', birthDateMode === 'date' ? 'bg-gray-100 text-slate-700 dark:bg-gray-900 dark:text-white' : 'text-slate-400 hover:text-slate-600']" @click="setBirthDateMode('date')">Date de naissance</button><button type="button" :class="['rounded px-3 py-1.5 text-xs font-semibold transition', birthDateMode === 'age' ? 'bg-gray-100 text-slate-700 dark:bg-gray-900 dark:text-white' : 'text-slate-400 hover:text-slate-600']" @click="setBirthDateMode('age')">Âge</button></div><Input v-if="birthDateMode === 'date'" v-model="form.birth_date" type="date" /><Input v-else v-model="form.age" type="number" min="0" max="130" placeholder="Âge en années" /></div>
                                <p v-if="birthDateMode === 'date' && exactAge !== null" class="mt-1.5 text-xs text-slate-400">Âge calculé automatiquement : {{ exactAge }} ans.</p><p v-else-if="birthDateMode === 'age'" class="mt-1.5 text-xs text-slate-400">À utiliser uniquement lorsque la date exacte est inconnue.</p>
                                <FormError v-if="form.errors.birth_date || form.errors.age" class="mt-1">{{ form.errors.birth_date || form.errors.age }}</FormError>
                            </div>
                            <label class="lg:col-span-3"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Situation maritale</span><span class="relative block"><select v-model="form.marital_status" :class="selectClass"><option :value="null">Non renseignée</option><option v-for="option in maritalStatusOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select><Icon class="pointer-events-none absolute inset-y-0 end-3 my-auto text-sm text-slate-400" name="chevron-down" /></span><FormError v-if="form.errors.marital_status" class="mt-1">{{ form.errors.marital_status }}</FormError></label>
                            <label class="lg:col-span-3"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Nombre d’enfants</span><Input v-model="form.children_count" type="number" min="0" max="30" /><FormError v-if="form.errors.children_count" class="mt-1">{{ form.errors.children_count }}</FormError></label>
                            <label class="lg:col-span-12"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Profession</span><IconInput v-model="form.profession" icon="briefcase" placeholder="Métier ou activité" /><FormError v-if="form.errors.profession" class="mt-1">{{ form.errors.profession }}</FormError></label>
                        </div>
                    </div>
                </section>

                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                    <header class="flex items-start gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" name="call" /></span><div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Contact et adresse</h2><p class="mt-0.5 text-xs text-slate-400">Coordonnées du patient et proche à contacter.</p></div></header>
                    <div class="space-y-5 p-5">
                        <div class="grid gap-5 md:grid-cols-2">
                            <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Téléphone</span><IconInput v-model="form.phone" icon="call" type="tel" autocomplete="tel" /><FormError v-if="form.errors.phone" class="mt-1">{{ form.errors.phone }}</FormError></label>
                            <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Email</span><IconInput v-model="form.email" icon="mail" type="email" autocomplete="email" /><FormError v-if="form.errors.email" class="mt-1">{{ form.errors.email }}</FormError></label>
                            <div class="md:col-span-2">
                                <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2"><span class="text-sm font-medium text-slate-700 dark:text-white">Adresse</span><button type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-primary-600 hover:text-primary-700" @click="toggleNewAddress"><Icon :name="showNewAddress ? 'minus' : 'plus'" />{{ showNewAddress ? 'Choisir dans la liste' : 'Ajouter une adresse' }}</button></div>
                                <div v-if="!showNewAddress" class="relative"><select :class="selectClass" :value="form.address_entry_uuid" @change="selectAddress"><option value="">Adresse non renseignée</option><option v-for="address in addressEntries" :key="address.uuid" :value="address.uuid">{{ address.label }}</option></select><Icon class="pointer-events-none absolute inset-y-0 end-3 my-auto text-sm text-slate-400" name="chevron-down" /></div>
                                <IconInput v-else v-model="form.new_address_label" icon="map-pin" placeholder="Saisissez la nouvelle adresse" />
                                <FormError v-if="form.errors.address_entry_uuid || form.errors.new_address_label || form.errors.address" class="mt-1">{{ form.errors.address_entry_uuid || form.errors.new_address_label || form.errors.address }}</FormError>
                            </div>
                        </div>
                        <div class="rounded-md border border-gray-200 bg-gray-50/50 p-4 dark:border-gray-800 dark:bg-gray-1000/30">
                            <div><h3 class="text-sm font-bold text-slate-700 dark:text-white">Personne à contacter <span class="font-normal text-slate-400">(facultatif)</span></h3><p class="mt-0.5 text-xs text-slate-400">Proche à joindre si nécessaire.</p></div>
                            <div class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                                <label><span class="mb-1.5 block text-xs font-medium text-slate-500">Nom complet</span><IconInput v-model="form.emergency_contact_name" icon="user" /><FormError v-if="form.errors.emergency_contact_name" class="mt-1">{{ form.errors.emergency_contact_name }}</FormError></label>
                                <label><span class="mb-1.5 block text-xs font-medium text-slate-500">Lien de parenté</span><Input v-model="form.emergency_contact_relationship" /><FormError v-if="form.errors.emergency_contact_relationship" class="mt-1">{{ form.errors.emergency_contact_relationship }}</FormError></label>
                                <label><span class="mb-1.5 block text-xs font-medium text-slate-500">Téléphone</span><IconInput v-model="form.emergency_contact_phone" icon="call" type="tel" /><FormError v-if="form.errors.emergency_contact_phone" class="mt-1">{{ form.errors.emergency_contact_phone }}</FormError></label>
                                <label><span class="mb-1.5 block text-xs font-medium text-slate-500">Email</span><IconInput v-model="form.emergency_contact_email" icon="mail" type="email" /><FormError v-if="form.errors.emergency_contact_email" class="mt-1">{{ form.errors.emergency_contact_email }}</FormError></label>
                            </div>
                        </div>
                    </div>
                </section>

                <footer class="flex flex-col-reverse gap-3 rounded-lg border border-gray-200 bg-white px-5 py-4 dark:border-gray-900 dark:bg-gray-950 sm:flex-row sm:items-center sm:justify-between"><p class="text-xs text-slate-400">Les modifications administratives sont historisées.</p><div class="flex flex-col-reverse gap-2 sm:flex-row"><Button :as="Link" :href="`/patients/${patient.uuid}`" size="rg" variant="white-outline">Annuler</Button><Button size="rg" variant="primary" type="submit" :disabled="form.processing || !canSubmit"><Icon class="text-lg" name="check" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : 'Enregistrer les modifications' }}</span></Button></div></footer>
            </form>

            <aside v-if="isMutualPatient" class="space-y-4 xl:sticky xl:top-4">
                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                    <header class="flex items-start gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" name="shield-check" /></span><div class="min-w-0"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Couverture mutuelle</h2><p class="mt-0.5 text-xs text-slate-400">Informations actives en lecture seule.</p></div></header>
                    <div v-if="activeMutualCoverage && canViewCoverage" class="p-5">
                        <dl class="space-y-3 text-xs">
                            <div class="flex justify-between gap-4"><dt class="text-slate-400">Organisme</dt><dd class="text-end font-semibold text-slate-700 dark:text-slate-200">{{ activeMutualCoverage.organization?.name || 'Non renseigné' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-slate-400">Bénéficiaire</dt><dd class="text-end font-semibold text-slate-700 dark:text-slate-200">{{ beneficiaryTypeLabels[activeMutualCoverage.beneficiary_type] ?? activeMutualCoverage.beneficiary_type }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-slate-400">Matricule</dt><dd class="break-all text-end font-mono font-semibold text-slate-700 dark:text-slate-200">{{ activeMutualCoverage.membership_number || 'Non renseigné' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-slate-400">Entreprise</dt><dd class="text-end font-semibold text-slate-700 dark:text-slate-200">{{ activeMutualCoverage.employer_name || 'Non renseignée' }}</dd></div>
                        </dl>
                        <div class="mt-5 border-t border-gray-200 pt-4 dark:border-gray-900">
                            <div class="flex items-center justify-between gap-3"><div><h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Justificatifs</h3><p class="mt-0.5 text-[11px] text-slate-400">Documents privés de la couverture.</p></div><span class="rounded-full bg-gray-100 px-2 py-1 text-[11px] font-semibold text-slate-500 dark:bg-gray-900">{{ existingAttachmentCount }}/5</span></div>
                            <div v-if="existingAttachments.length && canViewCoverageDocuments" class="mt-3 grid grid-cols-2 gap-2">
                                <button v-for="attachment in existingAttachments" :key="attachment.uuid" type="button" class="group overflow-hidden rounded-md border border-gray-200 text-start transition hover:border-primary-300 dark:border-gray-800 dark:hover:border-primary-800" :aria-label="`Voir ${attachmentName(attachment)}`" @click="previewedAttachment = attachment">
                                    <span class="flex h-24 items-center justify-center overflow-hidden bg-gray-50 dark:bg-gray-1000/40"><img v-if="attachmentIsImage(attachment)" :src="attachmentUrl(attachment)" :alt="attachmentName(attachment)" loading="lazy" class="h-full w-full object-cover transition duration-200 group-hover:scale-[1.02]" /><span v-else class="flex flex-col items-center text-slate-500 dark:text-slate-300"><Icon class="text-2xl" name="file-text" /><span class="mt-1 text-[10px] font-bold">PDF</span></span></span><span class="block truncate border-t border-gray-100 px-2.5 py-2 text-[11px] font-semibold text-slate-600 dark:border-gray-900 dark:text-slate-200" :title="attachmentName(attachment)">{{ attachmentName(attachment) }}</span>
                                </button>
                            </div>
                            <p v-else-if="canViewCoverageDocuments" class="mt-3 rounded-md border border-dashed border-gray-300 px-3 py-4 text-center text-xs text-slate-400 dark:border-gray-700">Aucun justificatif enregistré.</p>
                            <p v-else class="mt-3 text-xs leading-5 text-slate-400">Vous n’avez pas l’autorisation de consulter les justificatifs.</p>
                        </div>
                    </div>
                    <div v-else-if="activeMutualCoverage" class="p-5 text-xs leading-5 text-slate-400">Vous n’avez pas l’autorisation de consulter cette couverture.</div>
                    <div v-else class="p-5"><p class="text-sm font-semibold text-slate-600 dark:text-slate-200">Aucune couverture active</p><p class="mt-1 text-xs leading-5 text-slate-400">Les justificatifs ne peuvent être ajoutés qu’à une couverture mutuelle active.</p></div>
                </section>

                <section v-if="canAddCoverageDocuments && remainingAttachmentSlots > 0" class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                    <header class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><div class="flex items-center justify-between gap-3"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Ajouter des justificatifs</h2><span class="text-[11px] font-semibold text-slate-400">{{ availableDraftSlots }} place{{ availableDraftSlots > 1 ? 's' : '' }}</span></div><p class="mt-0.5 text-xs text-slate-400">JPEG, PNG, WebP ou PDF · 5 Mo maximum.</p></header>
                    <form class="p-4" @submit.prevent="uploadAttachments">
                        <input ref="attachmentInput" id="edit-mutual-attachments" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" multiple @change="selectAttachments" />
                        <label v-if="availableDraftSlots > 0" for="edit-mutual-attachments" :class="['flex cursor-pointer flex-col items-center justify-center rounded-md border border-dashed px-4 py-5 text-center transition', isDraggingAttachments ? 'border-primary-400 bg-primary-50 dark:border-primary-700 dark:bg-primary-950/20' : 'border-gray-300 bg-gray-50/40 hover:border-primary-400 dark:border-gray-700 dark:bg-gray-1000/20']" @dragenter.prevent="isDraggingAttachments = true" @dragover.prevent="isDraggingAttachments = true" @dragleave.prevent="isDraggingAttachments = false" @drop.prevent="dropAttachments">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-white text-slate-500 shadow-sm dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" name="upload-cloud" /></span><span class="mt-2 text-xs font-bold text-slate-600 dark:text-slate-200">Choisir ou déposer des fichiers</span><span class="mt-1 text-[11px] leading-4 text-slate-400">{{ availableDraftSlots }} ajout{{ availableDraftSlots > 1 ? 's' : '' }} possible{{ availableDraftSlots > 1 ? 's' : '' }}</span>
                        </label>
                        <div v-if="attachmentPreviews.length" class="mt-3 space-y-2">
                            <article v-for="(attachment, index) in attachmentPreviews" :key="`${attachment.file.name}-${attachment.file.lastModified}`" class="flex items-center gap-3 rounded-md border border-gray-200 p-2 dark:border-gray-800">
                                <button type="button" class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300" :aria-label="`Aperçu de ${attachment.file.name}`" @click="previewedAttachment = attachment"><img v-if="attachment.isImage" :src="attachment.previewUrl" :alt="attachment.file.name" class="h-full w-full object-cover" /><Icon v-else class="text-xl" name="file-text" /></button>
                                <button type="button" class="min-w-0 flex-1 text-start" @click="previewedAttachment = attachment"><span class="block truncate text-xs font-semibold text-slate-700 dark:text-white">{{ attachment.file.name }}</span><span class="mt-0.5 block text-[11px] text-slate-400">{{ attachment.isImage ? 'Image' : 'PDF' }} · {{ formatFileSize(attachment.file.size) }}</span></button>
                                <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/20" :aria-label="`Retirer ${attachment.file.name}`" @click="removeDraftAttachment(index)"><Icon name="cross" /></button>
                            </article>
                        </div>
                        <FormError v-if="attachmentClientError" class="mt-2">{{ attachmentClientError }}</FormError><FormError v-else-if="attachmentServerError" class="mt-2">{{ attachmentServerError }}</FormError>
                        <div v-if="attachmentPreviews.length" class="mt-4 flex items-center justify-between gap-3 border-t border-gray-200 pt-4 dark:border-gray-900"><button type="button" class="text-xs font-semibold text-slate-400 hover:text-red-600" :disabled="attachmentForm.processing" @click="clearDraftAttachments">Tout retirer</button><Button size="sm" variant="primary" type="submit" :disabled="attachmentForm.processing"><Icon name="upload-cloud" /><span class="ms-1.5">{{ attachmentForm.processing ? 'Envoi…' : 'Ajouter les fichiers' }}</span></Button></div>
                        <p class="mt-3 inline-flex items-start gap-1.5 text-[11px] leading-4 text-slate-400"><Icon class="mt-0.5 shrink-0" name="lock" />Les documents sont stockés de façon privée. Les pièces déjà enregistrées ne sont pas supprimées depuis cette page.</p>
                    </form>
                </section>
                <section v-else-if="canAddCoverageDocuments && remainingAttachmentSlots === 0" class="rounded-lg border border-gray-200 bg-white px-5 py-4 text-xs leading-5 text-slate-500 dark:border-gray-900 dark:bg-gray-950 dark:text-slate-300"><span class="font-semibold">Limite atteinte :</span> cette couverture contient déjà cinq justificatifs.</section>
            </aside>
        </div>
    </div>

    <Dialog :open="Boolean(previewedAttachment)" as="div" class="relative z-[1200]" @close="previewedAttachment = null">
        <div class="fixed inset-0 bg-slate-950/70" aria-hidden="true"></div>
        <div class="fixed inset-0 overflow-y-auto p-4"><div class="flex min-h-full items-center justify-center"><DialogPanel v-if="previewedAttachment" class="flex max-h-[92vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-950">
            <header class="flex items-center justify-between gap-4 border-b border-gray-200 px-4 py-3 dark:border-gray-800"><div class="min-w-0"><DialogTitle class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ attachmentName(previewedAttachment) }}</DialogTitle><p class="mt-0.5 text-xs text-slate-400">{{ attachmentIsImage(previewedAttachment) ? 'Image' : 'Document PDF' }} · {{ formatFileSize(attachmentSize(previewedAttachment)) }}</p></div><button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded text-slate-400 hover:bg-gray-100 hover:text-slate-700 dark:hover:bg-gray-900 dark:hover:text-white" aria-label="Fermer l’aperçu" @click="previewedAttachment = null"><Icon class="text-xl" name="cross" /></button></header>
            <div class="flex min-h-64 flex-1 items-center justify-center overflow-auto bg-gray-100 p-4 dark:bg-gray-1000/60 sm:min-h-[28rem]"><img v-if="attachmentIsImage(previewedAttachment)" :src="attachmentUrl(previewedAttachment)" :alt="attachmentName(previewedAttachment)" class="max-h-[72vh] max-w-full object-contain" /><div v-else class="flex flex-col items-center text-center"><span class="flex h-20 w-20 items-center justify-center rounded-full bg-white text-slate-500 shadow-sm dark:bg-gray-900 dark:text-slate-300"><Icon class="text-4xl" name="file-text" /></span><p class="mt-4 text-sm font-bold text-slate-700 dark:text-white">Document PDF</p><p class="mt-1 text-xs text-slate-400">Ouvrez le fichier dans un nouvel onglet pour vérifier son contenu.</p><a :href="attachmentUrl(previewedAttachment)" target="_blank" rel="noopener" class="mt-4 inline-flex items-center gap-2 rounded-md border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:border-gray-300 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200"><Icon name="eye" />Ouvrir le PDF</a></div></div>
        </DialogPanel></div></div>
    </Dialog>
</template>
