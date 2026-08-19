<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormLabel from '@/Components/UI/FormLabel.vue';
import FormError from '@/Components/UI/FormError.vue';
import InputWrap from '@/Components/UI/InputWrap.vue';
import Input from '@/Components/UI/Input.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';

defineOptions({
    layout: AppLayout,
});

const page = usePage();
const duplicates = computed(() => page.props.flash?.duplicates ?? null);

const form = useForm({
    first_name: '',
    last_name: '',
    birth_date: '',
    sex: 'M',
    phone: '',
    address: '',
    emergency_contact_name: '',
    emergency_contact_phone: '',
    emergency_contact_relationship: '',
    confirm_duplicate: false,
});

const submit = () => {
    form.post('/patients');
};

const confirmAndCreate = () => {
    form.confirm_duplicate = true;
    form.post('/patients');
};
</script>

<template>
    <Head title="Nouveau patient" />

    <div class="space-y-6">
        <div>
            <h1 class="font-heading text-2xl font-bold text-slate-700 dark:text-white">
                Nouveau patient
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                Identité administrative — créée une seule fois, réutilisée à chaque visite.
            </p>
        </div>

        <div
            v-if="duplicates && duplicates.length > 0"
            class="rounded-lg border border-yellow-300 bg-yellow-50 p-5 dark:border-yellow-900 dark:bg-yellow-950"
        >
            <div class="flex items-start gap-3">
                <Icon class="mt-0.5 text-xl text-yellow-600" name="alert-circle" />
                <div class="flex-grow">
                    <p class="text-sm font-medium text-yellow-800 dark:text-yellow-200">
                        Un ou plusieurs patients correspondent déjà à ce nom et cette date de naissance :
                    </p>
                    <ul class="mt-2 space-y-1 text-sm text-yellow-700 dark:text-yellow-300">
                        <li v-for="match in duplicates" :key="match.id">
                            {{ match.patient_number }} — {{ match.last_name }} {{ match.first_name }} ({{ match.birth_date }})
                        </li>
                    </ul>
                    <Button
                        class="mt-4"
                        size="sm"
                        variant="white-outline"
                        :disabled="form.processing"
                        @click="confirmAndCreate"
                    >
                        Créer quand même, c'est un patient différent
                    </Button>
                </div>
            </div>
        </div>

        <form class="max-w-2xl rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-900 dark:bg-gray-950" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <FormGroup>
                    <FormLabel html-for="first_name">Prénom</FormLabel>
                    <InputWrap>
                        <Input id="first_name" v-model="form.first_name" autocomplete="off" />
                    </InputWrap>
                    <FormError v-if="form.errors.first_name">{{ form.errors.first_name }}</FormError>
                </FormGroup>

                <FormGroup>
                    <FormLabel html-for="last_name">Nom</FormLabel>
                    <InputWrap>
                        <Input id="last_name" v-model="form.last_name" autocomplete="off" />
                    </InputWrap>
                    <FormError v-if="form.errors.last_name">{{ form.errors.last_name }}</FormError>
                </FormGroup>

                <FormGroup>
                    <FormLabel html-for="birth_date">Date de naissance</FormLabel>
                    <InputWrap>
                        <Input id="birth_date" v-model="form.birth_date" type="date" />
                    </InputWrap>
                    <FormError v-if="form.errors.birth_date">{{ form.errors.birth_date }}</FormError>
                </FormGroup>

                <FormGroup>
                    <FormLabel html-for="sex">Sexe</FormLabel>
                    <div class="flex h-9 items-center gap-6">
                        <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input v-model="form.sex" type="radio" value="M" class="accent-primary-600" />
                            Masculin
                        </label>
                        <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input v-model="form.sex" type="radio" value="F" class="accent-primary-600" />
                            Féminin
                        </label>
                    </div>
                    <FormError v-if="form.errors.sex">{{ form.errors.sex }}</FormError>
                </FormGroup>

                <FormGroup>
                    <FormLabel html-for="phone">Téléphone</FormLabel>
                    <InputWrap>
                        <Input id="phone" v-model="form.phone" autocomplete="off" />
                    </InputWrap>
                    <FormError v-if="form.errors.phone">{{ form.errors.phone }}</FormError>
                </FormGroup>

                <FormGroup class="sm:col-span-2">
                    <FormLabel html-for="address">Adresse</FormLabel>
                    <InputWrap>
                        <Input id="address" v-model="form.address" autocomplete="off" />
                    </InputWrap>
                    <FormError v-if="form.errors.address">{{ form.errors.address }}</FormError>
                </FormGroup>
            </div>

            <div class="my-6 border-t border-gray-200 dark:border-gray-900"></div>

            <h2 class="mb-4 text-sm font-bold uppercase tracking-wide text-slate-500">
                Personne à contacter
            </h2>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                <FormGroup>
                    <FormLabel html-for="emergency_contact_name">Nom</FormLabel>
                    <InputWrap>
                        <Input id="emergency_contact_name" v-model="form.emergency_contact_name" autocomplete="off" />
                    </InputWrap>
                    <FormError v-if="form.errors.emergency_contact_name">{{ form.errors.emergency_contact_name }}</FormError>
                </FormGroup>

                <FormGroup>
                    <FormLabel html-for="emergency_contact_phone">Téléphone</FormLabel>
                    <InputWrap>
                        <Input id="emergency_contact_phone" v-model="form.emergency_contact_phone" autocomplete="off" />
                    </InputWrap>
                    <FormError v-if="form.errors.emergency_contact_phone">{{ form.errors.emergency_contact_phone }}</FormError>
                </FormGroup>

                <FormGroup>
                    <FormLabel html-for="emergency_contact_relationship">Lien de parenté</FormLabel>
                    <InputWrap>
                        <Input id="emergency_contact_relationship" v-model="form.emergency_contact_relationship" autocomplete="off" />
                    </InputWrap>
                    <FormError v-if="form.errors.emergency_contact_relationship">{{ form.errors.emergency_contact_relationship }}</FormError>
                </FormGroup>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <Button :as="'a'" href="/patients" size="rg" variant="white-outline">Annuler</Button>
                <Button type="submit" size="rg" variant="primary" :disabled="form.processing">
                    Créer le patient
                </Button>
            </div>
        </form>
    </div>
</template>
