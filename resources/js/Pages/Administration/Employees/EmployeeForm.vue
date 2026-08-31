<script setup>
import { ref } from 'vue';
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
defineEmits(['submit']);

const addressMode = ref(props.form.new_address_label ? 'new' : 'existing');
const setAddressMode = (mode) => {
    addressMode.value = mode;
    if (mode === 'new') props.form.address_entry_uuid = '';
    else props.form.new_address_label = '';
    props.form.clearErrors('address_entry_uuid', 'new_address_label');
};

const fieldClass = 'block h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950';
const areaClass = 'block min-h-24 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950';
</script>

<template>
    <form class="space-y-5" @submit.prevent="$emit('submit')">
        <ValidationErrorSummary :errors="form.errors" />

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <header class="border-b border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/40">
                <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary-100 text-primary-700 dark:bg-primary-950 dark:text-primary-300"><Icon name="briefcase" /></span><div><h2 class="text-sm font-bold text-slate-800 dark:text-white">Situation professionnelle</h2><p class="mt-0.5 text-xs text-slate-500">Affectation locale, fonction et date d’entrée.</p></div></div>
            </header>
            <div class="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-4">
                <div><label for="employee_number" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Matricule <span class="text-red-500">*</span></label><Input id="employee_number" v-model="form.employee_number" autocomplete="off" /><FormError v-if="form.errors.employee_number">{{ form.errors.employee_number }}</FormError></div>
                <div><label for="department_uuid" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Département</label><select id="department_uuid" v-model="form.department_uuid" :class="fieldClass"><option value="">Non affecté</option><option v-for="item in departments" :key="item.uuid" :value="item.uuid" :disabled="!item.available">{{ item.label }}{{ item.available ? '' : ' — archivé' }}</option></select><FormError v-if="form.errors.department_uuid">{{ form.errors.department_uuid }}</FormError></div>
                <div><label for="job_title_uuid" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Fonction</label><select id="job_title_uuid" v-model="form.job_title_uuid" :class="fieldClass"><option value="">Non renseignée</option><option v-for="item in jobTitles" :key="item.uuid" :value="item.uuid" :disabled="!item.available">{{ item.label }}{{ item.available ? '' : ' — archivée' }}</option></select><FormError v-if="form.errors.job_title_uuid">{{ form.errors.job_title_uuid }}</FormError></div>
                <div><label for="hire_date" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Date d’entrée</label><input id="hire_date" v-model="form.hire_date" type="date" :class="fieldClass"><FormError v-if="form.errors.hire_date">{{ form.errors.hire_date }}</FormError></div>
                <div><label for="diploma" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Diplôme</label><Input id="diploma" v-model="form.diploma" /><FormError v-if="form.errors.diploma">{{ form.errors.diploma }}</FormError></div>
                <div><label for="education_level" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Niveau</label><Input id="education_level" v-model="form.education_level" /><FormError v-if="form.errors.education_level">{{ form.errors.education_level }}</FormError></div>
                <div><label for="badge" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Badge</label><Input id="badge" v-model="form.badge" /><FormError v-if="form.errors.badge">{{ form.errors.badge }}</FormError></div>
                <div><label for="blouse" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Blouse</label><Input id="blouse" v-model="form.blouse" /><FormError v-if="form.errors.blouse">{{ form.errors.blouse }}</FormError></div>
                <div class="sm:col-span-2 xl:col-span-4"><div class="rounded-lg border border-gray-200 bg-gray-50/70 px-4 py-3 dark:border-gray-800 dark:bg-gray-1000/40"><CheckBox id="employee_active" v-model="form.active">Employé actif et disponible dans les parcours Personnel</CheckBox><FormError v-if="form.errors.active">{{ form.errors.active }}</FormError></div></div>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <header class="border-b border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/40">
                <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300"><Icon name="user" /></span><div><h2 class="text-sm font-bold text-slate-800 dark:text-white">Identité et état civil</h2><p class="mt-0.5 text-xs text-slate-500">Informations administratives du dossier personnel.</p></div></div>
            </header>
            <div class="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-4">
                <div><label for="civility" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Civilité</label><select id="civility" v-model="form.civility" :class="fieldClass"><option value="">Non renseignée</option><option v-for="item in options.civilities" :key="item.value" :value="item.value">{{ item.label }}</option></select><FormError v-if="form.errors.civility">{{ form.errors.civility }}</FormError></div>
                <div><label for="last_name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Nom <span class="text-red-500">*</span></label><Input id="last_name" v-model="form.last_name" autocomplete="family-name" /><FormError v-if="form.errors.last_name">{{ form.errors.last_name }}</FormError></div>
                <div><label for="first_name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Prénoms</label><Input id="first_name" v-model="form.first_name" autocomplete="given-name" /><FormError v-if="form.errors.first_name">{{ form.errors.first_name }}</FormError></div>
                <div><label for="sex" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Genre <span class="text-red-500">*</span></label><select id="sex" v-model="form.sex" :class="fieldClass"><option value="">Sélectionner</option><option v-for="item in options.sexes" :key="item.value" :value="item.value">{{ item.label }}</option></select><FormError v-if="form.errors.sex">{{ form.errors.sex }}</FormError></div>
                <div><label for="birth_date" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Date de naissance</label><input id="birth_date" v-model="form.birth_date" type="date" :class="fieldClass"><FormError v-if="form.errors.birth_date">{{ form.errors.birth_date }}</FormError></div>
                <div><label for="birth_place" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Lieu de naissance</label><Input id="birth_place" v-model="form.birth_place" /><FormError v-if="form.errors.birth_place">{{ form.errors.birth_place }}</FormError></div>
                <div><label for="marital_status" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Situation matrimoniale</label><select id="marital_status" v-model="form.marital_status" :class="fieldClass"><option value="">Non renseignée</option><option v-for="item in options.marital_statuses" :key="item.value" :value="item.value">{{ item.label }}</option></select><FormError v-if="form.errors.marital_status">{{ form.errors.marital_status }}</FormError></div>
                <div><label for="children_count" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Nombre d’enfants</label><input id="children_count" v-model="form.children_count" min="0" type="number" :class="fieldClass"><FormError v-if="form.errors.children_count">{{ form.errors.children_count }}</FormError></div>
                <div class="sm:col-span-2 xl:col-span-4"><label for="children_details" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Prénoms des enfants, naissance et genre</label><textarea id="children_details" v-model="form.children_details" :class="areaClass" placeholder="Informations déclarées par le personnel" /><FormError v-if="form.errors.children_details">{{ form.errors.children_details }}</FormError></div>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <header class="border-b border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/40">
                <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300"><Icon name="file-text" /></span><div><h2 class="text-sm font-bold text-slate-800 dark:text-white">Pièce d’identité</h2><p class="mt-0.5 text-xs text-slate-500">CIN ou passeport, avec informations de délivrance.</p></div></div>
            </header>
            <div class="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-4">
                <div><label for="identity_type" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Type</label><select id="identity_type" v-model="form.identity_document_type" :class="fieldClass"><option value="">Non renseigné</option><option v-for="item in options.identity_document_types" :key="item.value" :value="item.value">{{ item.label }}</option></select><FormError v-if="form.errors.identity_document_type">{{ form.errors.identity_document_type }}</FormError></div>
                <div><label for="identity_number" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Numéro</label><Input id="identity_number" v-model="form.identity_document_number" /><FormError v-if="form.errors.identity_document_number">{{ form.errors.identity_document_number }}</FormError></div>
                <div><label for="identity_date" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Date de délivrance</label><input id="identity_date" v-model="form.identity_document_issued_on" type="date" :class="fieldClass"><FormError v-if="form.errors.identity_document_issued_on">{{ form.errors.identity_document_issued_on }}</FormError></div>
                <div><label for="identity_place" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Lieu de délivrance</label><Input id="identity_place" v-model="form.identity_document_issued_at" /><FormError v-if="form.errors.identity_document_issued_at">{{ form.errors.identity_document_issued_at }}</FormError></div>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <header class="border-b border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/40">
                <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"><Icon name="phone" /></span><div><h2 class="text-sm font-bold text-slate-800 dark:text-white">Coordonnées</h2><p class="mt-0.5 text-xs text-slate-500">Contact et adresse administrative locale.</p></div></div>
            </header>
            <div class="grid gap-4 p-5 sm:grid-cols-2">
                <div><label for="phone" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Téléphone</label><Input id="phone" v-model="form.phone" autocomplete="tel" /><FormError v-if="form.errors.phone">{{ form.errors.phone }}</FormError></div>
                <div><label for="email" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Email</label><Input id="email" v-model="form.email" type="email" autocomplete="email" /><FormError v-if="form.errors.email">{{ form.errors.email }}</FormError></div>
                <div class="sm:col-span-2"><div class="mb-3 inline-flex rounded-lg bg-gray-100 p-1 dark:bg-gray-900"><button type="button" :class="['rounded-md px-3 py-1.5 text-xs font-bold transition', addressMode === 'existing' ? 'bg-white text-slate-700 shadow-sm dark:bg-gray-800 dark:text-white' : 'text-slate-400']" @click="setAddressMode('existing')">Adresse existante</button><button type="button" :class="['rounded-md px-3 py-1.5 text-xs font-bold transition', addressMode === 'new' ? 'bg-white text-slate-700 shadow-sm dark:bg-gray-800 dark:text-white' : 'text-slate-400']" @click="setAddressMode('new')">Nouvelle adresse</button></div><select v-if="addressMode === 'existing'" id="address_entry_uuid" v-model="form.address_entry_uuid" :class="fieldClass"><option value="">Non renseignée</option><option v-for="item in addresses" :key="item.uuid" :value="item.uuid" :disabled="!item.available">{{ item.label }}{{ item.available ? '' : ' — archivée' }}</option></select><Input v-else id="new_address_label" v-model="form.new_address_label" placeholder="Saisir une nouvelle adresse" /><FormError v-if="form.errors.address_entry_uuid">{{ form.errors.address_entry_uuid }}</FormError><FormError v-if="form.errors.new_address_label">{{ form.errors.new_address_label }}</FormError></div>
                <div class="sm:col-span-2"><label for="observation" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Observation</label><textarea id="observation" v-model="form.observation" :class="areaClass" /><FormError v-if="form.errors.observation">{{ form.errors.observation }}</FormError></div>
            </div>
        </section>

        <footer class="sticky bottom-3 z-10 flex flex-col-reverse gap-2 rounded-xl border border-gray-200 bg-white/95 p-3 shadow-lg backdrop-blur dark:border-gray-800 dark:bg-gray-950/95 sm:flex-row sm:justify-end">
            <Button :as="Link" :href="cancelHref" size="rg" variant="white-outline">Annuler</Button>
            <Button size="rg" type="submit" :disabled="form.processing"><Icon class="text-lg" name="check" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : submitLabel }}</span></Button>
        </footer>
    </form>
</template>
