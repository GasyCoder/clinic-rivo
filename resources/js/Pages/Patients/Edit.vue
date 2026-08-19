<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import CardBody from '@/Components/UI/CardBody.vue';
import FormError from '@/Components/UI/FormError.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormLabel from '@/Components/UI/FormLabel.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconInput from '@/Components/UI/IconInput.vue';
import Radio from '@/Components/UI/Radio.vue';
import RadioButton from '@/Components/UI/RadioButton.vue';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({
    layout: AppLayout,
});

const props = defineProps({
    patient: Object,
});

const civilityOptions = [
    { value: 'MR', label: 'M.', sex: 'M' },
    { value: 'MRS', label: 'Mme', sex: 'F' },
    { value: 'GIRL', label: 'Enfant fille', sex: 'F' },
    { value: 'BOY', label: 'Enfant garçon', sex: 'M' },
];

const birthDateMode = ref(props.patient.birth_date_is_approximate ? 'age' : 'date');

const form = useForm({
    first_name: props.patient.first_name ?? '',
    last_name: props.patient.last_name ?? '',
    birth_date: props.patient.birth_date_is_approximate ? '' : (props.patient.birth_date ?? ''),
    age: props.patient.birth_date_is_approximate ? (props.patient.age ?? '') : '',
    sex: props.patient.sex,
    civility: props.patient.civility ?? null,
    identity_document_type: props.patient.identity_document_type ?? null,
    identity_document_number: props.patient.identity_document_number ?? '',
    phone: props.patient.phone ?? '',
    email: props.patient.email ?? '',
    address: props.patient.address ?? '',
    emergency_contact_name: props.patient.emergency_contact_name ?? '',
    emergency_contact_phone: props.patient.emergency_contact_phone ?? '',
    emergency_contact_relationship: props.patient.emergency_contact_relationship ?? '',
    emergency_contact_email: props.patient.emergency_contact_email ?? '',
});

const chooseCivility = (value) => {
    form.civility = value;
    form.sex = civilityOptions.find((option) => option.value === value)?.sex ?? form.sex;
};

const setBirthDateMode = (mode) => {
    birthDateMode.value = mode;

    if (mode === 'date') {
        form.age = '';
    } else {
        form.birth_date = '';
    }
};

const canSubmit = computed(() => form.last_name && form.sex && (form.birth_date || form.age));

const submit = () => {
    form.put(`/patients/${props.patient.uuid}`, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="`Modifier ${formatPatientName(patient)}`" />

    <div class="mx-auto w-full max-w-screen-xl space-y-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-3">
                <Avatar rounded size="rg" variant="primary-pale" :text="formatPatientInitials(patient)" />
                <div class="min-w-0">
                    <h1 class="truncate font-heading text-2xl font-bold text-slate-700 dark:text-white">Modifier le patient</h1>
                    <p class="mt-0.5 truncate text-sm text-slate-400">
                        {{ formatPatientName(patient) }} · {{ patient.patient_number }}
                    </p>
                </div>
            </div>

            <Button :as="Link" :href="`/patients/${patient.uuid}`" size="rg" variant="white-outline">
                <Icon class="text-lg" name="arrow-left" />
                <span class="ms-2">Retour au dossier</span>
            </Button>
        </div>

        <form @submit.prevent="submit">
            <Card class="shadow-sm">
                <CardBody class="space-y-7 p-5 sm:p-7">
                    <section>
                        <div class="mb-5 flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                                <Icon class="text-lg" name="user" />
                            </span>
                            <div>
                                <h2 class="text-sm font-bold text-slate-700 dark:text-white">Identité administrative</h2>
                                <p class="mt-0.5 text-xs text-slate-400"><span class="text-red-500">*</span> champ obligatoire.</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-5 md:grid-cols-[minmax(150px,0.45fr)_minmax(0,1fr)_minmax(0,1fr)]">
                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="civility">Civilité</FormLabel>
                                <select
                                    id="civility"
                                    name="civility"
                                    class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm text-slate-700 outline-none transition-all focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:border-primary-600 dark:focus:ring-primary-950"
                                    :value="form.civility ?? ''"
                                    @change="chooseCivility($event.target.value || null)"
                                >
                                    <option value="">Choisir</option>
                                    <option v-for="option in civilityOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                </select>
                                <FormError v-if="form.errors.civility">{{ form.errors.civility }}</FormError>
                            </FormGroup>

                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="last_name">Nom <span class="text-red-500">*</span></FormLabel>
                                <IconInput id="last_name" v-model="form.last_name" icon="user" autocomplete="off" required />
                                <FormError v-if="form.errors.last_name">{{ form.errors.last_name }}</FormError>
                            </FormGroup>

                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="first_name">Prénom(s)</FormLabel>
                                <IconInput id="first_name" v-model="form.first_name" icon="user" autocomplete="off" />
                                <FormError v-if="form.errors.first_name">{{ form.errors.first_name }}</FormError>
                            </FormGroup>
                        </div>

                        <fieldset class="mt-5">
                            <legend class="mb-3 text-sm font-medium text-slate-700 dark:text-white">Sexe <span class="text-red-500">*</span></legend>
                            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                                <Radio id="edit-sex-m" v-model="form.sex" name="sex" value="M">Masculin</Radio>
                                <Radio id="edit-sex-f" v-model="form.sex" name="sex" value="F">Féminin</Radio>
                            </div>
                            <FormError v-if="form.errors.sex">{{ form.errors.sex }}</FormError>
                        </fieldset>

                        <div class="mt-6 grid grid-cols-1 gap-6 border-t border-gray-200 pt-6 dark:border-gray-900 lg:grid-cols-2">
                            <fieldset class="min-w-0">
                                <legend class="mb-3 text-sm font-bold text-slate-700 dark:text-white">Pièce d’identité <span class="font-normal text-slate-400">(facultatif)</span></legend>
                                <div class="flex flex-wrap items-center gap-2">
                                    <RadioButton id="edit-doc-cin" v-model="form.identity_document_type" nocontrol name="identity_document_type" value="CIN">CIN</RadioButton>
                                    <RadioButton id="edit-doc-passport" v-model="form.identity_document_type" nocontrol name="identity_document_type" value="PASSPORT">Passeport</RadioButton>
                                    <button
                                        v-if="form.identity_document_type"
                                        type="button"
                                        class="text-xs text-slate-400 underline hover:text-slate-600"
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
                                        />
                                    </div>
                                </div>
                                <FormError v-if="form.errors.identity_document_type">{{ form.errors.identity_document_type }}</FormError>
                                <FormError v-if="form.errors.identity_document_number">{{ form.errors.identity_document_number }}</FormError>
                            </fieldset>

                            <fieldset class="min-w-0">
                                <legend class="mb-3 text-sm font-bold text-slate-700 dark:text-white">Naissance <span class="text-red-500">*</span></legend>
                                <div class="flex flex-wrap gap-2">
                                    <RadioButton id="edit-birth-date" nocontrol name="birth_mode" :model-value="birthDateMode" value="date" @update:model-value="setBirthDateMode">Date de naissance</RadioButton>
                                    <RadioButton id="edit-birth-age" nocontrol name="birth_mode" :model-value="birthDateMode" value="age" @update:model-value="setBirthDateMode">Âge</RadioButton>
                                    <div class="min-w-[180px] flex-1">
                                        <IconInput v-if="birthDateMode === 'date'" id="birth_date" v-model="form.birth_date" icon="calendar" type="date" />
                                        <IconInput v-else id="age" v-model="form.age" icon="calendar" type="number" min="0" max="130" placeholder="Âge déclaré" />
                                    </div>
                                </div>
                                <FormError v-if="form.errors.birth_date">{{ form.errors.birth_date }}</FormError>
                                <FormError v-if="form.errors.age">{{ form.errors.age }}</FormError>
                            </fieldset>
                        </div>
                    </section>

                    <section class="border-t border-gray-200 pt-7 dark:border-gray-900">
                        <div class="mb-5 flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                                <Icon class="text-lg" name="call" />
                            </span>
                            <div>
                                <h2 class="text-sm font-bold text-slate-700 dark:text-white">Coordonnées du patient</h2>
                                <p class="mt-0.5 text-xs text-slate-400">Téléphone, email et adresse.</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="phone">Téléphone</FormLabel>
                                <IconInput id="phone" v-model="form.phone" icon="call" type="tel" />
                                <FormError v-if="form.errors.phone">{{ form.errors.phone }}</FormError>
                            </FormGroup>
                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="email">Email</FormLabel>
                                <IconInput id="email" v-model="form.email" icon="mail" type="email" />
                                <FormError v-if="form.errors.email">{{ form.errors.email }}</FormError>
                            </FormGroup>
                            <FormGroup class="!mb-0 sm:col-span-2">
                                <FormLabel class="mb-1.5" for="address">Adresse</FormLabel>
                                <textarea
                                    id="address"
                                    v-model="form.address"
                                    rows="2"
                                    class="block min-h-20 w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none transition-all focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:border-primary-600 dark:focus:ring-primary-950"
                                ></textarea>
                                <FormError v-if="form.errors.address">{{ form.errors.address }}</FormError>
                            </FormGroup>
                        </div>
                    </section>

                    <section class="border-t border-gray-200 pt-7 dark:border-gray-900">
                        <div class="mb-5 flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                                <Icon class="text-lg" name="contact" />
                            </span>
                            <div>
                                <h2 class="text-sm font-bold text-slate-700 dark:text-white">Personne à contacter</h2>
                                <p class="mt-0.5 text-xs text-slate-400">Informations facultatives du proche à joindre.</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="emergency_contact_name">Nom</FormLabel>
                                <IconInput id="emergency_contact_name" v-model="form.emergency_contact_name" icon="user" />
                                <FormError v-if="form.errors.emergency_contact_name">{{ form.errors.emergency_contact_name }}</FormError>
                            </FormGroup>
                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="emergency_contact_relationship">Lien de parenté</FormLabel>
                                <IconInput id="emergency_contact_relationship" v-model="form.emergency_contact_relationship" icon="users" />
                                <FormError v-if="form.errors.emergency_contact_relationship">{{ form.errors.emergency_contact_relationship }}</FormError>
                            </FormGroup>
                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="emergency_contact_phone">Téléphone</FormLabel>
                                <IconInput id="emergency_contact_phone" v-model="form.emergency_contact_phone" icon="call" type="tel" />
                                <FormError v-if="form.errors.emergency_contact_phone">{{ form.errors.emergency_contact_phone }}</FormError>
                            </FormGroup>
                            <FormGroup class="!mb-0">
                                <FormLabel class="mb-1.5" for="emergency_contact_email">Email</FormLabel>
                                <IconInput id="emergency_contact_email" v-model="form.emergency_contact_email" icon="mail" type="email" />
                                <FormError v-if="form.errors.emergency_contact_email">{{ form.errors.emergency_contact_email }}</FormError>
                            </FormGroup>
                        </div>
                    </section>

                    <div class="flex flex-col-reverse gap-3 border-t border-gray-200 pt-6 dark:border-gray-900 sm:flex-row sm:justify-end">
                        <Button :as="Link" :href="`/patients/${patient.uuid}`" size="rg" variant="white-outline">Annuler</Button>
                        <Button size="rg" variant="primary" type="submit" :disabled="form.processing || !canSubmit">
                            <Icon class="text-lg" name="check" />
                            <span class="ms-2">{{ form.processing ? 'Enregistrement…' : 'Enregistrer les modifications' }}</span>
                        </Button>
                    </div>
                </CardBody>
            </Card>
        </form>
    </div>
</template>
