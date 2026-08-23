<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import { formatDateTime } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    orientation: Object,
    careRecord: Object,
    bmiReference: Object,
    patientAllergies: Array,
    allergenReference: Array,
    procedureCatalog: Array,
    capabilities: Object,
});

const episode = computed(() => props.orientation.episode);
const patient = computed(() => episode.value.patient);
const procedureSearch = ref('');

const form = useForm({
    blood_group: props.careRecord?.blood_group ?? '',
    height_cm: props.careRecord?.height_cm ?? '',
    weight_kg: props.careRecord?.weight_kg ?? '',
    allergy_note: props.careRecord?.allergy_note ?? '',
    allergy_uuids: props.careRecord?.allergy_snapshot?.map((allergy) => allergy.uuid) ?? [],
    allergen_reference_uuids: [],
    new_allergies: [],
    smoker: props.careRecord?.smoker === true ? '1' : props.careRecord?.smoker === false ? '0' : '',
    hospitalization_reason: props.careRecord?.hospitalization_reason ?? '',
    hospitalized_at: props.careRecord?.hospitalized_at ?? '',
    discharged_at: props.careRecord?.discharged_at ?? '',
    diagnostic_note: props.careRecord?.diagnostic_note ?? '',
    transmission_reason: props.careRecord?.transmission_reason ?? '',
    procedures: [],
});

const severityLabels = { MILD: 'Légère', MODERATE: 'Modérée', SEVERE: 'Sévère' };
const showNewAllergy = ref(false);
const allergenReferenceSelection = ref('');
const newAllergyError = ref('');
const newAllergy = reactive({ substance: '', reaction: '', severity: '' });

const normalizeAllergyName = (value) => value.trim().replace(/\s+/g, ' ').toLocaleLowerCase('fr');
const selectedAllergenReferences = computed(() => props.allergenReference.filter(
    (reference) => form.allergen_reference_uuids.includes(reference.uuid),
));
const availableAllergenGroups = computed(() => {
    const knownNames = new Set(props.patientAllergies.map((allergy) => normalizeAllergyName(allergy.substance)));
    const groups = new Map();

    props.allergenReference
        .filter((reference) => !knownNames.has(normalizeAllergyName(reference.name)))
        .filter((reference) => !form.allergen_reference_uuids.includes(reference.uuid))
        .forEach((reference) => {
            if (!groups.has(reference.category)) {
                groups.set(reference.category, {
                    code: reference.category,
                    label: reference.category_label,
                    items: [],
                });
            }
            groups.get(reference.category).items.push(reference);
        });

    return [...groups.values()];
});

const addAllergenReference = () => {
    if (!allergenReferenceSelection.value) return;
    if (!form.allergen_reference_uuids.includes(allergenReferenceSelection.value)) {
        form.allergen_reference_uuids.push(allergenReferenceSelection.value);
    }
    allergenReferenceSelection.value = '';
};

const removeAllergenReference = (uuid) => {
    const index = form.allergen_reference_uuids.indexOf(uuid);
    if (index >= 0) form.allergen_reference_uuids.splice(index, 1);
};

const allergySelected = (uuid) => form.allergy_uuids.includes(uuid);
const toggleAllergy = (uuid) => {
    if (!props.capabilities.can_edit || !props.capabilities.can_view_allergies) return;
    const index = form.allergy_uuids.indexOf(uuid);
    if (index >= 0) form.allergy_uuids.splice(index, 1);
    else form.allergy_uuids.push(uuid);
};

const resetNewAllergy = () => {
    newAllergy.substance = '';
    newAllergy.reaction = '';
    newAllergy.severity = '';
    newAllergyError.value = '';
    showNewAllergy.value = false;
};

const addNewAllergy = () => {
    const substance = newAllergy.substance.trim().replace(/\s+/g, ' ');
    if (!substance) {
        newAllergyError.value = 'Indiquez la substance ou le produit allergène.';
        return;
    }

    const normalized = normalizeAllergyName(substance);
    const existing = props.patientAllergies.find((allergy) => normalizeAllergyName(allergy.substance) === normalized);
    if (existing) {
        if (!allergySelected(existing.uuid)) form.allergy_uuids.push(existing.uuid);
        resetNewAllergy();
        return;
    }

    const reference = props.allergenReference.find((item) => normalizeAllergyName(item.name) === normalized);
    if (reference) {
        if (!form.allergen_reference_uuids.includes(reference.uuid)) {
            form.allergen_reference_uuids.push(reference.uuid);
        }
        resetNewAllergy();
        return;
    }

    const alreadyQueued = form.new_allergies.some((allergy) => normalizeAllergyName(allergy.substance) === normalized);
    if (!alreadyQueued) {
        form.new_allergies.push({
            substance,
            reaction: newAllergy.reaction.trim() || null,
            severity: newAllergy.severity || null,
        });
    }

    resetNewAllergy();
};

const removeNewAllergy = (index) => form.new_allergies.splice(index, 1);

const bmi = computed(() => {
    const height = Number(form.height_cm);
    const weight = Number(form.weight_kg);
    if (!height || !weight || height <= 0 || weight <= 0) return null;
    return (weight / ((height / 100) ** 2)).toFixed(2);
});

const displayedBmi = computed(() => bmi.value ?? props.careRecord?.bmi ?? null);
const bmiAssessment = computed(() => {
    const value = Number(displayedBmi.value);
    const reference = props.bmiReference;

    if (!reference || !Number.isFinite(value) || value <= 0) return null;
    if (reference.patient_age === null || reference.patient_age === undefined) return reference.age_unknown;
    if (reference.patient_age < reference.adult_min_age) return reference.pediatric;

    return reference.adult_bands.find((band) => (
        (band.minimum === null || value >= band.minimum)
        && (band.maximum === null || value < band.maximum)
    )) ?? props.careRecord?.bmi_assessment ?? null;
});

const bmiAlertClasses = computed(() => ({
    success: 'border-emerald-200 bg-emerald-50/70 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/20 dark:text-emerald-200',
    warning: 'border-amber-200 bg-amber-50/70 text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200',
    danger: 'border-red-200 bg-red-50/70 text-red-800 dark:border-red-900 dark:bg-red-950/20 dark:text-red-200',
    info: 'border-gray-200 bg-gray-50 text-slate-600 dark:border-gray-800 dark:bg-gray-1000 dark:text-slate-200',
}[bmiAssessment.value?.tone] ?? 'border-gray-200 bg-gray-50 text-slate-600 dark:border-gray-800 dark:bg-gray-1000 dark:text-slate-200'));

const bmiBadgeClasses = computed(() => ({
    success: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200',
    warning: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    danger: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-200',
    info: 'bg-gray-200 text-slate-600 dark:bg-gray-900 dark:text-slate-200',
}[bmiAssessment.value?.tone] ?? 'bg-gray-200 text-slate-600 dark:bg-gray-900 dark:text-slate-200'));

const bmiAlertIcon = computed(() => bmiAssessment.value?.tone === 'success' ? 'check-circle' : 'alert-circle');

const filteredProcedures = computed(() => {
    const query = procedureSearch.value.trim().toLocaleLowerCase('fr');
    if (!query) return props.procedureCatalog;
    return props.procedureCatalog.filter((item) => `${item.name} ${item.code}`.toLocaleLowerCase('fr').includes(query));
});

const selectedProcedure = (uuid) => form.procedures.find((item) => item.catalog_item_uuid === uuid);
const toggleProcedure = (item) => {
    if (!props.capabilities.can_edit) return;
    const index = form.procedures.findIndex((procedure) => procedure.catalog_item_uuid === item.uuid);
    if (index >= 0) {
        form.procedures.splice(index, 1);
        return;
    }
    form.procedures.push({
        catalog_item_uuid: item.uuid,
        code: item.code,
        name: item.name,
        quantity: '1',
        notes: '',
    });
};

const procedureError = (index, field) => form.errors[`procedures.${index}.${field}`];
const submit = () => {
    form.transform((data) => {
        const payload = {
            ...data,
            blood_group: data.blood_group || null,
            height_cm: data.height_cm || null,
            weight_kg: data.weight_kg || null,
            smoker: data.smoker === '' ? null : data.smoker === '1',
            hospitalized_at: data.hospitalized_at || null,
            discharged_at: data.discharged_at || null,
            procedures: data.procedures.map(({ catalog_item_uuid, quantity, notes }) => ({
                catalog_item_uuid,
                quantity,
                notes,
            })),
        };

        if (!props.capabilities.can_view_allergies) {
            delete payload.allergy_note;
            delete payload.allergy_uuids;
            delete payload.allergen_reference_uuids;
            delete payload.new_allergies;
        } else if (!props.capabilities.can_manage_allergies) {
            delete payload.allergen_reference_uuids;
            delete payload.new_allergies;
        }

        return payload;
    }).put(`/care/orientations/${props.orientation.uuid}/record`, {
        preserveScroll: true,
        onSuccess: () => {
            form.procedures = [];
            form.allergy_uuids = props.careRecord?.allergy_snapshot?.map((allergy) => allergy.uuid) ?? [];
            form.allergen_reference_uuids = [];
            form.new_allergies = [];
            allergenReferenceSelection.value = '';
            form.defaults();
        },
    });
};
</script>

<template>
    <Head :title="`Soins ${episode.episode_number}`" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <header class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <Avatar rounded size="rg" variant="slate-pale" :text="formatPatientInitials(patient)" />
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="truncate font-heading text-2xl font-bold text-slate-700 dark:text-white">{{ formatPatientName(patient) }}</h1>
                        <span v-if="episode.priority === 'EMERGENCY'" class="inline-flex items-center gap-1.5 rounded border border-red-200 px-2 py-1 text-xs font-bold uppercase text-red-600 dark:border-red-900 dark:text-red-300"><span class="h-1.5 w-1.5 rounded-full bg-red-500" />Urgence</span>
                    </div>
                    <p class="mt-1 text-sm text-slate-400"><span class="font-mono">{{ episode.episode_number }}</span> · Patient {{ patient.patient_number }} · {{ props.orientation.status_label }}</p>
                </div>
            </div>
            <Button :as="Link" href="/care" size="rg" variant="white-outline"><Icon class="text-lg" name="arrow-left" /><span class="ms-2">Retour à la file</span></Button>
        </header>

        <div v-if="orientation.status === 'PENDING'" class="rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
            Le patient doit être pris en charge avant de renseigner cette fiche.
        </div>
        <div v-else-if="orientation.status === 'COMPLETED'" class="rounded-lg border border-gray-200 bg-white px-5 py-4 text-sm text-slate-500 dark:border-gray-900 dark:bg-gray-950 dark:text-slate-300">
            Cette prise en charge est terminée. La fiche reste consultable dans l’historique clinique.
        </div>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900 md:flex-row md:items-center md:justify-between">
                <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Demande et parcours</h2><p class="mt-1 text-xs text-slate-400">Prestations connues lors de l’arrivée du patient.</p></div>
                <span class="text-xs text-slate-400">Pris en charge {{ orientation.accepted_at ? formatDateTime(orientation.accepted_at) : '—' }}<span v-if="orientation.accepted_by"> par {{ orientation.accepted_by }}</span></span>
            </div>
            <div class="flex flex-wrap gap-2 px-5 py-4">
                <span v-if="episode.designations.length === 0" class="text-sm text-slate-500">Besoin à préciser après évaluation.</span>
                <span v-for="designation in episode.designations" :key="designation.uuid" class="rounded border border-gray-200 px-3 py-2 text-sm font-semibold text-slate-600 dark:border-gray-800 dark:text-slate-300">{{ designation.description }}</span>
            </div>
        </section>

        <form class="space-y-5" @submit.prevent="submit">
            <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_430px]">
                <div class="space-y-5">
                    <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                        <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Constantes et observations</h2><p class="mt-1 text-xs text-slate-400">Données relevées pendant ce passage.</p></div>
                        <div v-if="capabilities.can_view_vitals" class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                            <div><label for="blood_group" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Groupe sanguin</label><select id="blood_group" v-model="form.blood_group" :disabled="!capabilities.can_edit" class="block h-9 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-1000"><option value="">Non renseigné</option><option v-for="group in ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']" :key="group" :value="group">{{ group }}</option></select><FormError class="mt-1" :message="form.errors.blood_group" /></div>
                            <div><label for="height_cm" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Taille (cm)</label><Input id="height_cm" v-model="form.height_cm" type="number" min="20" max="250" step="0.01" :disabled="!capabilities.can_edit" placeholder="Ex. 165" /><FormError class="mt-1" :message="form.errors.height_cm" /></div>
                            <div><label for="weight_kg" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Poids (kg)</label><Input id="weight_kg" v-model="form.weight_kg" type="number" min="0.1" max="500" step="0.01" :disabled="!capabilities.can_edit" placeholder="Ex. 62" /><FormError class="mt-1" :message="form.errors.weight_kg" /></div>
                            <div><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">IMC</span><div class="flex h-9 items-center rounded border border-gray-200 bg-gray-50 px-3 text-sm font-bold text-slate-600 dark:border-gray-800 dark:bg-gray-1000 dark:text-slate-200">{{ displayedBmi ?? '—' }}</div><p class="mt-1 text-[11px] text-slate-400">Calculé automatiquement.</p></div>
                            <div v-if="bmiAssessment" :class="bmiAlertClasses" class="rounded-md border px-4 py-3 sm:col-span-2 lg:col-span-4" role="status" aria-live="polite">
                                <div class="flex items-start gap-3">
                                    <Icon :name="bmiAlertIcon" class="mt-0.5 shrink-0 text-lg" />
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-2 text-sm font-bold"><span :class="bmiBadgeClasses" class="inline-flex rounded px-2 py-1 text-xs font-bold">{{ bmiAssessment.label }}</span><span class="font-normal opacity-75">IMC {{ displayedBmi }}</span></p>
                                        <p class="mt-1 text-xs leading-5 opacity-90">{{ bmiAssessment.message }}</p>
                                        <p class="mt-1 text-[11px] leading-4 opacity-65">{{ bmiReference.disclaimer }}</p>
                                    </div>
                                </div>
                            </div>
                            <div v-if="capabilities.can_view_allergies" class="space-y-3 sm:col-span-2 lg:col-span-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div><span class="block text-sm font-medium text-slate-700 dark:text-white">Allergies signalées</span><p class="mt-0.5 text-[11px] text-slate-400">Choisissez une allergie connue ou ajoutez une substance absente du référentiel.</p></div>
                                    <button v-if="capabilities.can_edit && capabilities.can_manage_allergies" type="button" class="inline-flex h-8 items-center gap-1.5 rounded border border-gray-200 px-2.5 text-xs font-semibold text-slate-600 hover:border-primary-300 hover:text-primary-600 dark:border-gray-800 dark:text-slate-300" @click="showNewAllergy = !showNewAllergy"><Icon name="plus" />Ajouter manuellement</button>
                                </div>

                                <div v-if="capabilities.can_edit && capabilities.can_manage_allergies" class="rounded-md border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-800 dark:bg-gray-1000/40">
                                    <label for="allergen_reference" class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-200">Référentiel d’allergènes courants</label>
                                    <div class="flex flex-col gap-2 sm:flex-row">
                                        <select id="allergen_reference" v-model="allergenReferenceSelection" class="block h-9 min-w-0 flex-1 rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" @change="addAllergenReference">
                                            <option value="">Sélectionner un allergène…</option>
                                            <optgroup v-for="group in availableAllergenGroups" :key="group.code" :label="group.label">
                                                <option v-for="reference in group.items" :key="reference.uuid" :value="reference.uuid">{{ reference.name }}</option>
                                            </optgroup>
                                        </select>
                                    </div>
                                    <p class="mt-1.5 text-[11px] text-slate-400">Le choix sera ajouté au dossier permanent du patient après enregistrement.</p>
                                </div>

                                <div v-if="selectedAllergenReferences.length" class="flex flex-wrap gap-2">
                                    <span v-for="reference in selectedAllergenReferences" :key="reference.uuid" class="inline-flex items-center gap-2 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 dark:border-red-900 dark:bg-red-950/20 dark:text-red-200"><span>{{ reference.name }}</span><button type="button" :aria-label="`Retirer ${reference.name}`" @click="removeAllergenReference(reference.uuid)"><Icon name="cross" /></button></span>
                                </div>

                                <p v-if="patientAllergies.length" class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Déjà enregistrées pour ce patient</p>
                                <div v-if="patientAllergies.length" class="flex flex-wrap gap-2">
                                    <button v-for="allergy in patientAllergies" :key="allergy.uuid" type="button" :disabled="!capabilities.can_edit" :class="['inline-flex items-center gap-2 rounded border px-3 py-2 text-start text-xs transition-colors', allergySelected(allergy.uuid) ? 'border-red-300 bg-red-50 font-semibold text-red-700 dark:border-red-900 dark:bg-red-950/20 dark:text-red-200' : 'border-gray-200 bg-white text-slate-600 hover:border-gray-300 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300']" @click="toggleAllergy(allergy.uuid)">
                                        <Icon :name="allergySelected(allergy.uuid) ? 'check-circle' : 'circle'" class="text-base" />
                                        <span><span class="block">{{ allergy.substance }}</span><span v-if="allergy.reaction || allergy.severity" class="mt-0.5 block text-[10px] font-normal opacity-70">{{ [allergy.reaction, severityLabels[allergy.severity]].filter(Boolean).join(' · ') }}</span></span>
                                    </button>
                                </div>
                                <p v-else class="rounded border border-dashed border-gray-200 px-3 py-3 text-xs text-slate-400 dark:border-gray-800">Aucune allergie n’est encore enregistrée pour ce patient. Utilisez le référentiel ci-dessus ou l’ajout manuel.</p>

                                <div v-if="form.new_allergies.length" class="flex flex-wrap gap-2">
                                    <span v-for="(allergy, index) in form.new_allergies" :key="`${allergy.substance}-${index}`" class="inline-flex items-center gap-2 rounded bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 dark:bg-red-950/20 dark:text-red-200"><span>Nouvelle : {{ allergy.substance }}</span><button type="button" aria-label="Retirer cette nouvelle allergie" @click="removeNewAllergy(index)"><Icon name="cross" /></button></span>
                                </div>

                                <div v-if="showNewAllergy && capabilities.can_manage_allergies" class="rounded-md border border-gray-200 bg-gray-50/70 p-3 dark:border-gray-800 dark:bg-gray-1000/40">
                                    <div class="grid gap-3 md:grid-cols-3">
                                        <div><label for="new_allergy_substance" class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-200">Substance *</label><Input id="new_allergy_substance" v-model="newAllergy.substance" placeholder="Ex. Pénicilline" @input="newAllergyError = ''" /></div>
                                        <div><label for="new_allergy_reaction" class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-200">Réaction</label><Input id="new_allergy_reaction" v-model="newAllergy.reaction" placeholder="Ex. urticaire" /></div>
                                        <div><label for="new_allergy_severity" class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-200">Gravité</label><select id="new_allergy_severity" v-model="newAllergy.severity" class="block h-9 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white"><option value="">Non précisée</option><option value="MILD">Légère</option><option value="MODERATE">Modérée</option><option value="SEVERE">Sévère</option></select></div>
                                    </div>
                                    <FormError class="mt-1" :message="newAllergyError" />
                                    <p class="mt-2 text-[11px] text-slate-400">La nouvelle allergie sera ajoutée au dossier permanent lors de l’enregistrement de la fiche.</p>
                                    <div class="mt-3 flex justify-end gap-2"><Button type="button" size="sm" variant="white-outline" @click="resetNewAllergy">Annuler</Button><Button type="button" size="sm" variant="secondary" @click="addNewAllergy"><Icon name="plus" /><span class="ms-1.5">Ajouter à la fiche</span></Button></div>
                                </div>

                                <div><label for="allergy_note" class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-200">Observation complémentaire</label><Input id="allergy_note" v-model="form.allergy_note" :disabled="!capabilities.can_edit" placeholder="Précision communiquée pendant ce passage" /><FormError class="mt-1" :message="form.errors.allergy_note || form.errors.allergy_uuids || form.errors.allergen_reference_uuids || form.errors.new_allergies" /></div>
                            </div>
                            <div><label for="smoker" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Tabac</label><select id="smoker" v-model="form.smoker" :disabled="!capabilities.can_edit" class="block h-9 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-1000"><option value="">Non renseigné</option><option value="0">Non</option><option value="1">Oui</option></select><FormError class="mt-1" :message="form.errors.smoker" /></div>
                        </div>
                        <p v-else class="p-5 text-sm text-slate-400">Vous n’avez pas l’autorisation de consulter les constantes.</p>
                    </section>

                    <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                        <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Hospitalisation et transmission</h2><p class="mt-1 text-xs text-slate-400">Contexte infirmier du passage ; ces informations ne remplacent pas une décision médicale.</p></div>
                        <div class="grid gap-4 p-5 sm:grid-cols-2">
                            <div class="sm:col-span-2"><label for="hospitalization_reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif d’hospitalisation</label><textarea id="hospitalization_reason" v-model="form.hospitalization_reason" rows="2" :disabled="!capabilities.can_edit" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-1000" /><FormError class="mt-1" :message="form.errors.hospitalization_reason" /></div>
                            <div><label for="hospitalized_at" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Entrée hospitalisation</label><Input id="hospitalized_at" v-model="form.hospitalized_at" type="datetime-local" :disabled="!capabilities.can_edit" /><FormError class="mt-1" :message="form.errors.hospitalized_at" /></div>
                            <div><label for="discharged_at" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Sortie</label><Input id="discharged_at" v-model="form.discharged_at" type="datetime-local" :disabled="!capabilities.can_edit" /><FormError class="mt-1" :message="form.errors.discharged_at" /></div>
                            <div class="sm:col-span-2"><label for="diagnostic_note" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Diagnostic communiqué</label><textarea id="diagnostic_note" v-model="form.diagnostic_note" rows="2" :disabled="!capabilities.can_edit" placeholder="Diagnostic communiqué à l’équipe de soins" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-1000" /><FormError class="mt-1" :message="form.errors.diagnostic_note" /></div>
                            <div class="sm:col-span-2"><label for="transmission_reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif de transmission</label><textarea id="transmission_reason" v-model="form.transmission_reason" rows="3" :disabled="!capabilities.can_edit" placeholder="État, observations et éléments à transmettre à l’équipe suivante" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-1000" /><FormError class="mt-1" :message="form.errors.transmission_reason" /></div>
                        </div>
                    </section>
                </div>

                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950 xl:sticky xl:top-5">
                    <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><div class="flex items-center justify-between gap-3"><div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Actes réalisés</h2><p class="mt-1 text-xs text-slate-400">Sélectionnez uniquement les actes effectivement réalisés.</p></div><span class="rounded bg-gray-100 px-2 py-1 text-xs font-bold text-slate-500 dark:bg-gray-900 dark:text-slate-300">{{ form.procedures.length }}</span></div></div>
                    <div v-if="capabilities.can_edit" class="p-4">
                        <div class="relative"><Input v-model="procedureSearch" icon="start" type="search" placeholder="Rechercher un acte" autocomplete="off" /><span class="pointer-events-none absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400"><Icon name="search" /></span></div>
                        <div class="mt-3 max-h-72 overflow-y-auto rounded border border-gray-200 dark:border-gray-800">
                            <button v-for="item in filteredProcedures" :key="item.uuid" type="button" :class="['flex w-full items-center gap-3 border-b border-gray-100 px-3 py-2.5 text-start last:border-b-0 dark:border-gray-900', selectedProcedure(item.uuid) ? 'bg-gray-50 dark:bg-gray-1000' : 'hover:bg-gray-50 dark:hover:bg-gray-1000']" @click="toggleProcedure(item)"><span :class="['flex h-7 w-7 shrink-0 items-center justify-center rounded border', selectedProcedure(item.uuid) ? 'border-primary-500 bg-primary-600 text-white' : 'border-gray-200 text-slate-400 dark:border-gray-700']"><Icon :name="selectedProcedure(item.uuid) ? 'check' : 'plus'" /></span><span class="min-w-0"><span class="block text-xs font-bold text-slate-700 dark:text-white">{{ item.name }}</span><span class="mt-0.5 block font-mono text-[10px] text-slate-400">{{ item.code }}</span></span></button>
                            <p v-if="filteredProcedures.length === 0" class="px-4 py-8 text-center text-xs text-slate-400">Aucun acte trouvé.</p>
                        </div>

                        <div v-if="form.procedures.length" class="mt-4 max-h-80 space-y-3 overflow-y-auto pr-1">
                            <div v-for="(procedure, index) in form.procedures" :key="procedure.catalog_item_uuid" class="rounded border border-gray-200 p-3 dark:border-gray-800"><div class="flex items-start justify-between gap-3"><p class="text-xs font-bold text-slate-700 dark:text-white">{{ procedure.name }}</p><button type="button" class="text-slate-400 hover:text-red-500" aria-label="Retirer cet acte" @click="toggleProcedure({ uuid: procedure.catalog_item_uuid })"><Icon name="cross" /></button></div><div class="mt-3 grid grid-cols-[90px_minmax(0,1fr)] gap-2"><div><label :for="`quantity-${index}`" class="mb-1 block text-[11px] text-slate-400">Quantité</label><Input :id="`quantity-${index}`" v-model="procedure.quantity" type="number" min="0.01" max="999" step="0.01" /></div><div><label :for="`notes-${index}`" class="mb-1 block text-[11px] text-slate-400">Observation{{ procedure.code === 'CARE-OTHER' ? ' *' : '' }}</label><Input :id="`notes-${index}`" v-model="procedure.notes" :placeholder="procedure.code === 'CARE-OTHER' ? 'Précisez l’acte' : 'Facultatif'" /></div></div><FormError class="mt-1" :message="procedureError(index, 'quantity') || procedureError(index, 'notes')" /></div>
                        </div>
                        <FormError class="mt-3" :message="form.errors.procedures || form.errors.care_record" />
                    </div>
                    <p v-else class="p-5 text-sm text-slate-400">La fiche est en lecture seule.</p>
                </section>
            </div>

            <section v-if="careRecord?.procedures?.length" class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Historique des actes</h2><p class="mt-1 text-xs text-slate-400">Les actes enregistrés restent dans l’historique et ne sont pas supprimés.</p></div>
                <div class="overflow-x-auto"><table class="w-full min-w-[760px] border-collapse"><thead class="bg-gray-50/70 dark:bg-gray-1000/40"><tr><th class="px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Acte</th><th class="px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Qté</th><th class="px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Observation</th><th class="px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Réalisé par</th><th class="px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-slate-400">Date / heure</th></tr></thead><tbody class="divide-y divide-gray-200 dark:divide-gray-900"><tr v-for="procedure in careRecord.procedures" :key="procedure.uuid"><td class="px-5 py-3"><span class="block text-sm font-bold text-slate-700 dark:text-white">{{ procedure.name }}</span><span class="font-mono text-xs text-slate-400">{{ procedure.code }}</span></td><td class="px-5 py-3 text-sm text-slate-600 dark:text-slate-300">{{ procedure.quantity }}</td><td class="max-w-sm px-5 py-3 text-sm text-slate-500 dark:text-slate-300">{{ procedure.notes || '—' }}</td><td class="px-5 py-3 text-sm text-slate-500 dark:text-slate-300">{{ procedure.performed_by || '—' }}</td><td class="px-5 py-3 text-end text-sm text-slate-500 dark:text-slate-300">{{ formatDateTime(procedure.performed_at) }}</td></tr></tbody></table></div>
            </section>

            <div v-if="capabilities.can_edit && form.isDirty" class="flex flex-col-reverse gap-3 border-t border-gray-200 pt-5 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs font-medium text-amber-700 dark:text-amber-300">Modifications non enregistrées. Enregistrez la fiche avant de terminer la prise en charge.</p>
                <Button type="submit" size="rg" variant="primary" :disabled="form.processing"><Icon name="check" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : 'Enregistrer la fiche' }}</span></Button>
            </div>
        </form>

        <section v-if="capabilities.can_complete && !form.isDirty" class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950 sm:flex-row sm:items-center sm:justify-between">
            <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Fin de prise en charge</h2><p class="mt-1 text-xs text-slate-400">La fiche est enregistrée. Vous pouvez maintenant terminer cette prise en charge.</p></div>
            <div class="flex flex-wrap gap-2">
                <Link :href="`/care/orientations/${orientation.uuid}/complete`" method="post" as="button" preserve-scroll><Button size="rg" :variant="episode.care_completion_mode === 'MEDICINE' ? 'primary' : 'white-outline'"><Icon name="check" /><span class="ms-2">{{ episode.care_completion_mode === 'MEDICINE' ? 'Terminer et transmettre' : 'Terminer les soins' }}</span></Button></Link>
                <Link v-if="episode.care_completion_mode === 'CHOICE'" :href="`/care/orientations/${orientation.uuid}/complete-and-orient`" method="post" as="button" preserve-scroll><Button size="rg" variant="primary">Vers Médecine</Button></Link>
            </div>
        </section>
    </div>
</template>
