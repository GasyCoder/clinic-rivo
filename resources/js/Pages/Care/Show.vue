<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import Radio from '@/Components/UI/Radio.vue';
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
const activePatientAllergyUuids = computed(() => new Set(props.patientAllergies.map((allergy) => allergy.uuid)));
const selectedActiveAllergyUuids = (snapshot = []) => snapshot
    .map((allergy) => allergy.uuid)
    .filter((uuid) => activePatientAllergyUuids.value.has(uuid));
const recordedProcedureCodes = new Set((props.careRecord?.procedures ?? []).map((procedure) => procedure.code));
const initialRequestedProcedures = (props.orientation.episode.designations ?? [])
    .filter((designation) => designation.module === 'CARE' && !recordedProcedureCodes.has(designation.code))
    .map((designation) => {
        const catalogItem = props.procedureCatalog.find((item) => item.uuid === designation.catalog_item_uuid);
        if (!catalogItem) return null;

        return {
            catalog_item_uuid: catalogItem.uuid,
            code: catalogItem.code,
            name: catalogItem.name,
            quantity: designation.quantity ?? '1',
            notes: '',
            care_requires_allergy_check: Boolean(
                designation.care_requires_allergy_check || catalogItem.care_requires_allergy_check,
            ),
            care_recommends_vitals: Boolean(
                designation.care_recommends_vitals || catalogItem.care_recommends_vitals,
            ),
            allergy_checked: false,
        };
    })
    .filter(Boolean);

const form = useForm({
    blood_group: props.careRecord?.blood_group ?? '',
    blood_pressure_left_systolic: props.careRecord?.blood_pressure_left_systolic ?? '',
    blood_pressure_left_diastolic: props.careRecord?.blood_pressure_left_diastolic ?? '',
    blood_pressure_right_systolic: props.careRecord?.blood_pressure_right_systolic ?? '',
    blood_pressure_right_diastolic: props.careRecord?.blood_pressure_right_diastolic ?? '',
    temperature_celsius: props.careRecord?.temperature_celsius ?? '',
    known_diabetes: props.careRecord?.known_diabetes === true ? '1' : props.careRecord?.known_diabetes === false ? '0' : '',
    height_cm: props.careRecord?.height_cm ?? '',
    weight_kg: props.careRecord?.weight_kg ?? '',
    allergy_note: props.careRecord?.allergy_note ?? '',
    allergy_uuids: selectedActiveAllergyUuids(props.careRecord?.allergy_snapshot ?? []),
    allergen_reference_uuids: [],
    new_allergies: [],
    smoker: props.careRecord?.smoker === true ? '1' : props.careRecord?.smoker === false ? '0' : '',
    diagnostic_note: props.orientation.episode.care_transmission_expected ? (props.careRecord?.diagnostic_note ?? '') : '',
    transmission_reason: props.orientation.episode.care_transmission_expected ? (props.careRecord?.transmission_reason ?? '') : '',
    no_procedure_reason: props.careRecord?.no_procedure_reason ?? '',
    procedures: initialRequestedProcedures,
});

const formEl = ref(null);
const hasRecordedClinicalDetails = Boolean(
    props.careRecord?.blood_group
    || props.careRecord?.blood_pressure_left_systolic
    || props.careRecord?.blood_pressure_left_diastolic
    || props.careRecord?.blood_pressure_right_systolic
    || props.careRecord?.blood_pressure_right_diastolic
    || props.careRecord?.temperature_celsius
    || props.careRecord?.known_diabetes !== null && props.careRecord?.known_diabetes !== undefined
    || props.careRecord?.height_cm
    || props.careRecord?.weight_kg
    || props.careRecord?.smoker !== null && props.careRecord?.smoker !== undefined
    || props.careRecord?.allergy_note
    || props.careRecord?.allergy_snapshot?.length,
);
const showClinicalDetails = ref(props.orientation.episode.care_vitals_recommended || hasRecordedClinicalDetails);
const historicalSnapshotAllergies = computed(() => (props.careRecord?.allergy_snapshot ?? []).filter(
    (allergy) => !allergy.uuid || !activePatientAllergyUuids.value.has(allergy.uuid),
));
const noProcedureSelected = ref(Boolean(props.careRecord?.no_procedure_reason));

const severityLabels = { MILD: 'Légère', MODERATE: 'Modérée', SEVERE: 'Sévère' };
const showNewAllergy = ref(false);
const allergenReferenceSelection = ref('');
const newAllergyError = ref('');
const newAllergy = reactive({ substance: '', reaction: '', severity: '' });

const normalizeAllergyName = (value) => value.trim().replace(/\s+/g, ' ').toLocaleLowerCase('fr');
const selectedAllergenReferences = computed(() => props.allergenReference.filter(
    (reference) => form.allergen_reference_uuids.includes(reference.uuid),
));
// The safety banner must reflect every allergy indicated for this passage,
// not only the ones already saved to the permanent record — a substance
// just picked from the reference list or typed manually (not yet persisted)
// is exactly what the nurse needs to see before performing the act.
const knownSafetyAllergyNames = computed(() => [
    ...new Set([
        ...props.patientAllergies.map((allergy) => allergy.substance),
        ...selectedAllergenReferences.value.map((reference) => reference.name),
        ...form.new_allergies.map((allergy) => allergy.substance),
    ]),
]);
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
const requiredAllergyProcedures = computed(() => form.procedures.filter(
    (procedure) => procedure.care_requires_allergy_check,
));
const requiresAllergySafetyCheck = computed(() => requiredAllergyProcedures.value.length > 0);
const allergySafetyConfirmed = computed({
    get: () => requiresAllergySafetyCheck.value
        && requiredAllergyProcedures.value.every((procedure) => procedure.allergy_checked),
    set: (checked) => requiredAllergyProcedures.value.forEach((procedure) => {
        procedure.allergy_checked = checked;
    }),
});
const allergySafetyReady = computed(() => !requiresAllergySafetyCheck.value
    || (props.capabilities.can_view_allergies && allergySafetyConfirmed.value));
const recordedProcedureCount = computed(() => props.careRecord?.procedures?.length ?? 0);
const hasNoProcedureReason = computed(() => form.no_procedure_reason.trim().length > 0);
const canCompleteWithoutSaving = computed(() => {
    if (episode.value.care_completion_mode === 'MEDICINE') return true;
    if (episode.value.care_completion_mode === 'FINISH') return recordedProcedureCount.value > 0;
    return recordedProcedureCount.value > 0 || Boolean(props.careRecord?.no_procedure_reason);
});

const toggleProcedure = (item) => {
    if (!props.capabilities.can_edit) return;
    const index = form.procedures.findIndex((procedure) => procedure.catalog_item_uuid === item.uuid);
    if (index >= 0) {
        form.procedures.splice(index, 1);
        return;
    }
    noProcedureSelected.value = false;
    form.no_procedure_reason = '';
    form.procedures.push({
        catalog_item_uuid: item.uuid,
        code: item.code,
        name: item.name,
        quantity: '1',
        notes: '',
        care_requires_allergy_check: Boolean(item.care_requires_allergy_check),
        care_recommends_vitals: Boolean(item.care_recommends_vitals),
        allergy_checked: false,
    });
};

const toggleNoProcedure = () => {
    noProcedureSelected.value = !noProcedureSelected.value;
    if (noProcedureSelected.value) {
        form.procedures = [];
        return;
    }

    form.no_procedure_reason = '';
};

const procedureError = (index, field) => form.errors[`procedures.${index}.${field}`];
const allergySafetyError = computed(() => Object.entries(form.errors)
    .find(([field]) => field.startsWith('procedures.') && field.endsWith('.allergy_checked'))?.[1]);
const buildPayload = (data, orientToMedicine = undefined) => {
    const payload = {
            ...data,
            blood_group: data.blood_group || null,
            blood_pressure_left_systolic: data.blood_pressure_left_systolic || null,
            blood_pressure_left_diastolic: data.blood_pressure_left_diastolic || null,
            blood_pressure_right_systolic: data.blood_pressure_right_systolic || null,
            blood_pressure_right_diastolic: data.blood_pressure_right_diastolic || null,
            temperature_celsius: data.temperature_celsius || null,
            known_diabetes: data.known_diabetes === '' ? null : data.known_diabetes === '1',
            height_cm: data.height_cm || null,
            weight_kg: data.weight_kg || null,
            smoker: data.smoker === '' ? null : data.smoker === '1',
            no_procedure_reason: data.no_procedure_reason.trim() || null,
            procedures: data.procedures.map(({ catalog_item_uuid, quantity, notes, allergy_checked }) => ({
                catalog_item_uuid,
                quantity,
                notes,
                allergy_checked,
            })),
    };

    if (!props.capabilities.can_edit_vitals) {
        delete payload.blood_group;
        delete payload.blood_pressure_left_systolic;
        delete payload.blood_pressure_left_diastolic;
        delete payload.blood_pressure_right_systolic;
        delete payload.blood_pressure_right_diastolic;
        delete payload.temperature_celsius;
        delete payload.known_diabetes;
        delete payload.height_cm;
        delete payload.weight_kg;
        delete payload.smoker;
    }

    if (!props.capabilities.can_view_allergies) {
        delete payload.allergy_note;
        delete payload.allergy_uuids;
        delete payload.allergen_reference_uuids;
        delete payload.new_allergies;
    } else if (!props.capabilities.can_manage_allergies) {
        delete payload.allergen_reference_uuids;
        delete payload.new_allergies;
    }

    if (!props.orientation.episode.care_transmission_expected) {
        delete payload.diagnostic_note;
        delete payload.transmission_reason;
    }

    if (orientToMedicine !== undefined) payload.orient_to_medicine = orientToMedicine;

    return payload;
};

const scrollToErrors = () => requestAnimationFrame(() => formEl.value?.scrollIntoView({
    behavior: 'smooth',
    block: 'start',
}));

const submit = () => {
    form.transform((data) => buildPayload(data)).put(`/care/orientations/${props.orientation.uuid}/record`, {
        preserveScroll: true,
        onSuccess: () => {
            form.procedures = [];
            form.allergy_uuids = selectedActiveAllergyUuids(props.careRecord?.allergy_snapshot ?? []);
            form.allergen_reference_uuids = [];
            form.new_allergies = [];
            allergenReferenceSelection.value = '';
            form.defaults();
        },
        onError: scrollToErrors,
    });
};

const submitAndComplete = (orientToMedicine = false) => {
    form.transform((data) => buildPayload(data, orientToMedicine))
        .put(`/care/orientations/${props.orientation.uuid}/record-and-complete`, {
            preserveScroll: true,
            onError: scrollToErrors,
        });
};
</script>

<template>
    <Head :title="`Soins ${episode.episode_number}`" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <header class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm shadow-slate-200/30 dark:border-gray-900 dark:bg-gray-950 dark:shadow-none">
            <div class="px-5 py-4">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex min-w-0 items-center gap-4">
                        <Avatar rounded size="rg" variant="slate-pale" :text="formatPatientInitials(patient)" />
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="truncate font-heading text-2xl font-bold text-slate-700 dark:text-white">{{ formatPatientName(patient) }}</h1>
                                <span :class="['inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-bold', orientation.status === 'IN_PROGRESS' ? 'border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-900 dark:bg-primary-950/40 dark:text-primary-300' : orientation.status === 'PENDING' ? 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300' : orientation.status === 'COMPLETED' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300' : 'border-gray-200 bg-gray-50 text-slate-500 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-300']"><span class="h-1.5 w-1.5 rounded-full bg-current" />{{ orientation.status_label }}</span>
                                <span v-if="episode.priority === 'EMERGENCY'" class="inline-flex items-center gap-1.5 rounded-full border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-bold uppercase text-red-600 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300"><span class="h-1.5 w-1.5 rounded-full bg-red-500" />Urgence</span>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400">
                                <span><span class="me-1 uppercase tracking-wide">Passage</span><strong class="font-mono font-semibold text-slate-600 dark:text-slate-200">{{ episode.episode_number }}</strong></span>
                                <span class="hidden h-3 w-px bg-gray-200 dark:bg-gray-800 sm:block" />
                                <span><span class="me-1 uppercase tracking-wide">Patient</span><strong class="font-mono font-semibold text-slate-600 dark:text-slate-200">{{ patient.patient_number }}</strong></span>
                            </div>
                        </div>
                    </div>
                    <Button :as="Link" href="/care" size="rg" variant="white-outline"><Icon class="text-lg" name="arrow-left" /><span class="ms-2">Retour à la file</span></Button>
                </div>
            </div>
            <div class="border-t border-gray-200 bg-gray-50/40 px-5 py-4 dark:border-gray-900 dark:bg-gray-1000/20">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-sm font-bold text-slate-700 dark:text-white">Besoins du passage</h2>
                        <p class="mt-1 text-xs text-slate-400">Demande connue au moment de l’arrivée.</p>
                    </div>
                    <span class="text-xs text-slate-400">Pris en charge {{ orientation.accepted_at ? formatDateTime(orientation.accepted_at) : '—' }}<span v-if="orientation.accepted_by"> par {{ orientation.accepted_by }}</span></span>
                </div>

                <div v-if="episode.designations.length === 0" class="mt-3 flex items-start gap-3 rounded-md border border-primary-100 bg-white px-3.5 py-3 dark:border-primary-950 dark:bg-gray-950">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-950 dark:text-primary-300"><Icon name="info" /></span>
                    <div>
                        <p class="text-sm font-bold text-slate-700 dark:text-white">Besoin à préciser après évaluation</p>
                        <p class="mt-1 text-xs leading-5 text-slate-400">Aucune prestation n’a été définie à l’accueil. L’équipe Soins précise le besoin et décide de la suite du parcours.</p>
                    </div>
                </div>
                <ul v-else class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                    <li v-for="(designation, index) in episode.designations" :key="designation.uuid" class="flex min-w-0 items-start gap-3 rounded-md border border-gray-200 bg-white px-3.5 py-3 dark:border-gray-800 dark:bg-gray-950">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-bold text-slate-500 dark:bg-gray-900 dark:text-slate-300">{{ index + 1 }}</span>
                        <span class="min-w-0"><strong class="block text-sm font-semibold text-slate-700 dark:text-white">{{ designation.description }}</strong><span v-if="designation.code || Number(designation.quantity) > 1" class="mt-1 flex flex-wrap gap-x-2 text-[11px] text-slate-400"><span v-if="designation.code" class="font-mono">{{ designation.code }}</span><span v-if="Number(designation.quantity) > 1">Quantité : {{ designation.quantity }}</span></span></span>
                    </li>
                </ul>
            </div>
        </header>

        <div v-if="orientation.status === 'PENDING'" class="rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
            Le patient doit être pris en charge avant de renseigner cette fiche.
        </div>
        <div v-else-if="orientation.status === 'COMPLETED'" class="rounded-lg border border-gray-200 bg-white px-5 py-4 text-sm text-slate-500 dark:border-gray-900 dark:bg-gray-950 dark:text-slate-300">
            Cette prise en charge est terminée. La fiche reste consultable dans l’historique clinique.
        </div>

        <form ref="formEl" class="space-y-5" novalidate @submit.prevent="submit">
            <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_430px]">
                <div class="space-y-5">
                    <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                        <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                            <div><div class="flex flex-wrap items-center gap-2"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Constantes et observations</h2><span v-if="!episode.care_vitals_recommended" class="rounded bg-gray-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:bg-gray-900 dark:text-slate-300">Facultatif</span><span v-else class="rounded border border-primary-200 bg-primary-50 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-primary-700 dark:border-primary-900 dark:bg-primary-950/40 dark:text-primary-300">Recommandé pour ce parcours</span></div><p class="mt-1 text-xs text-slate-400">Ouvrez cette partie uniquement si des données cliniques ont réellement été relevées.</p></div>
                            <button v-if="capabilities.can_view_vitals || capabilities.can_view_allergies" type="button" class="inline-flex h-8 shrink-0 items-center justify-center gap-1.5 rounded border border-gray-200 px-3 text-xs font-semibold text-slate-600 hover:border-gray-300 dark:border-gray-800 dark:text-slate-300" @click="showClinicalDetails = !showClinicalDetails"><Icon :name="showClinicalDetails ? 'chevron-up' : 'chevron-down'" />{{ showClinicalDetails ? 'Masquer' : 'Ajouter des données' }}</button>
                        </div>
                        <div v-if="requiresAllergySafetyCheck" class="border-b border-amber-200 bg-amber-50/60 px-5 py-4 text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex items-start gap-3"><Icon name="shield-check" class="mt-0.5 shrink-0 text-lg" /><div><p class="text-sm font-bold">Vérification de sécurité avant l’acte</p><p v-if="knownSafetyAllergyNames.length" class="mt-1 text-xs">Allergies signalées : <strong>{{ knownSafetyAllergyNames.join(' · ') }}</strong></p><p v-else class="mt-1 text-xs">Aucune allergie n’est enregistrée. Vérifiez directement le statut allergique auprès du patient.</p></div></div>
                                <label v-if="capabilities.can_view_allergies && capabilities.can_edit" class="inline-flex shrink-0 cursor-pointer items-center gap-2 rounded border border-amber-300 bg-white px-3 py-2 text-xs font-bold text-amber-900 dark:border-amber-800 dark:bg-gray-950 dark:text-amber-100"><input v-model="allergySafetyConfirmed" type="checkbox" class="h-4 w-4 rounded border-amber-400 text-primary-600 focus:ring-primary-500" />Statut allergique vérifié</label>
                                <p v-else-if="!capabilities.can_view_allergies" class="max-w-xs text-xs font-bold text-red-600 dark:text-red-300">Autorisation de consultation des allergies requise pour enregistrer cet acte.</p>
                            </div>
                            <FormError class="mt-2" :message="allergySafetyError" />
                        </div>
                        <div v-if="showClinicalDetails && (capabilities.can_view_vitals || capabilities.can_view_allergies)" class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                            <template v-if="capabilities.can_view_vitals">
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Tension bras gauche <span class="font-normal text-slate-400">(mmHg)</span></label>
                                    <div class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2">
                                        <Input id="blood_pressure_left_systolic" v-model="form.blood_pressure_left_systolic" type="number" min="40" max="300" step="1" :disabled="!capabilities.can_edit_vitals" placeholder="SYS" aria-label="Pression systolique du bras gauche" />
                                        <span class="text-sm text-slate-300">/</span>
                                        <Input id="blood_pressure_left_diastolic" v-model="form.blood_pressure_left_diastolic" type="number" min="20" max="200" step="1" :disabled="!capabilities.can_edit_vitals" placeholder="DIA" aria-label="Pression diastolique du bras gauche" />
                                    </div>
                                    <FormError class="mt-1" :message="form.errors.blood_pressure_left_systolic || form.errors.blood_pressure_left_diastolic" />
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Tension bras droit <span class="font-normal text-slate-400">(mmHg)</span></label>
                                    <div class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2">
                                        <Input id="blood_pressure_right_systolic" v-model="form.blood_pressure_right_systolic" type="number" min="40" max="300" step="1" :disabled="!capabilities.can_edit_vitals" placeholder="SYS" aria-label="Pression systolique du bras droit" />
                                        <span class="text-sm text-slate-300">/</span>
                                        <Input id="blood_pressure_right_diastolic" v-model="form.blood_pressure_right_diastolic" type="number" min="20" max="200" step="1" :disabled="!capabilities.can_edit_vitals" placeholder="DIA" aria-label="Pression diastolique du bras droit" />
                                    </div>
                                    <FormError class="mt-1" :message="form.errors.blood_pressure_right_systolic || form.errors.blood_pressure_right_diastolic" />
                                </div>
                                <div><label for="temperature_celsius" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Température (°C)</label><Input id="temperature_celsius" v-model="form.temperature_celsius" type="number" min="25" max="45" step="0.1" :disabled="!capabilities.can_edit_vitals" placeholder="Ex. 37,2" /><FormError class="mt-1" :message="form.errors.temperature_celsius" /></div>
                                <fieldset>
                                    <legend class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Diabète connu</legend>
                                    <div class="flex flex-wrap gap-2">
                                        <Radio id="known_diabetes_unknown" v-model="form.known_diabetes" name="known_diabetes" value="" size="sm" :disabled="!capabilities.can_edit_vitals">Inconnu</Radio>
                                        <Radio id="known_diabetes_no" v-model="form.known_diabetes" name="known_diabetes" value="0" size="sm" :disabled="!capabilities.can_edit_vitals">Non</Radio>
                                        <Radio id="known_diabetes_yes" v-model="form.known_diabetes" name="known_diabetes" value="1" size="sm" :disabled="!capabilities.can_edit_vitals">Oui</Radio>
                                    </div>
                                    <FormError class="mt-1" :message="form.errors.known_diabetes" />
                                </fieldset>
                                <div><label for="blood_group" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Groupe sanguin</label><select id="blood_group" v-model="form.blood_group" :disabled="!capabilities.can_edit_vitals" class="block h-9 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-1000"><option value="">Non renseigné</option><option v-for="group in ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']" :key="group" :value="group">{{ group }}</option></select><FormError class="mt-1" :message="form.errors.blood_group" /></div>
                                <div><label for="height_cm" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Taille (cm)</label><Input id="height_cm" v-model="form.height_cm" type="number" min="20" max="250" step="0.01" :disabled="!capabilities.can_edit_vitals" placeholder="Ex. 165" /><FormError class="mt-1" :message="form.errors.height_cm" /></div>
                                <div><label for="weight_kg" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Poids (kg)</label><Input id="weight_kg" v-model="form.weight_kg" type="number" min="0.1" max="500" step="0.01" :disabled="!capabilities.can_edit_vitals" placeholder="Ex. 62" /><FormError class="mt-1" :message="form.errors.weight_kg" /></div>
                                <div><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">IMC</span><div class="flex h-9 items-center rounded border border-gray-200 bg-gray-50 px-3 text-sm font-bold text-slate-600 dark:border-gray-800 dark:bg-gray-1000 dark:text-slate-200">{{ displayedBmi ?? '—' }}</div><p class="mt-1 text-[11px] text-slate-400">Calculé automatiquement.</p></div>
                            </template>
                            <div v-if="capabilities.can_view_vitals && bmiAssessment" :class="bmiAlertClasses" class="rounded-md border px-4 py-3 sm:col-span-2 lg:col-span-4" role="status" aria-live="polite">
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

                                <div v-if="historicalSnapshotAllergies.length" class="rounded border border-gray-200 bg-gray-50/60 px-3 py-2 dark:border-gray-800 dark:bg-gray-1000/40">
                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Historique conservé pour ce passage</p>
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-300">{{ historicalSnapshotAllergies.map((allergy) => allergy.substance).join(' · ') }}</p>
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
                            <fieldset v-if="capabilities.can_view_vitals">
                                <legend class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Tabac</legend>
                                <div class="flex flex-wrap gap-2">
                                    <Radio id="smoker_unknown" v-model="form.smoker" name="smoker" value="" size="sm" :disabled="!capabilities.can_edit_vitals">Inconnu</Radio>
                                    <Radio id="smoker_no" v-model="form.smoker" name="smoker" value="0" size="sm" :disabled="!capabilities.can_edit_vitals">Non</Radio>
                                    <Radio id="smoker_yes" v-model="form.smoker" name="smoker" value="1" size="sm" :disabled="!capabilities.can_edit_vitals">Oui</Radio>
                                </div>
                                <FormError class="mt-1" :message="form.errors.smoker" />
                            </fieldset>
                        </div>
                    </section>

                    <section v-if="episode.care_transmission_expected" class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                        <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Transmission vers Médecine</h2><p class="mt-1 text-xs text-slate-400">Visible uniquement lorsque le parcours continue vers Médecine ou en urgence.</p></div>
                        <div class="grid gap-4 p-5">
                            <div><label for="diagnostic_note" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Diagnostic communiqué <span class="font-normal text-slate-400">(si déjà établi)</span></label><textarea id="diagnostic_note" v-model="form.diagnostic_note" rows="2" :disabled="!capabilities.can_edit" placeholder="Information communiquée par le médecin ou l’équipe précédente" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-1000" /><FormError class="mt-1" :message="form.errors.diagnostic_note" /></div>
                            <div><label for="transmission_reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Observations à transmettre</label><textarea id="transmission_reason" v-model="form.transmission_reason" rows="3" :disabled="!capabilities.can_edit" placeholder="État du patient, actes réalisés et points de vigilance pour Médecine" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-1000" /><FormError class="mt-1" :message="form.errors.transmission_reason" /></div>
                        </div>
                    </section>
                </div>

                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950 xl:sticky xl:top-5">
                    <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><div class="flex items-center justify-between gap-3"><div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Actes réalisés</h2><p class="mt-1 text-xs text-slate-400">Sélectionnez uniquement les actes effectivement réalisés.</p></div><span class="rounded bg-gray-100 px-2 py-1 text-xs font-bold text-slate-500 dark:bg-gray-900 dark:text-slate-300">{{ form.procedures.length }}</span></div></div>
                    <div v-if="capabilities.can_edit" class="p-4">
                        <div class="relative"><Input v-model="procedureSearch" icon="start" type="search" placeholder="Rechercher un acte" autocomplete="off" /><span class="pointer-events-none absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400"><Icon name="search" /></span></div>
                        <div class="mt-3 max-h-72 overflow-y-auto rounded border border-gray-200 dark:border-gray-800">
                            <button v-for="item in filteredProcedures" :key="item.uuid" type="button" :class="['flex w-full items-center gap-3 border-b border-gray-100 px-3 py-2.5 text-start last:border-b-0 dark:border-gray-900', selectedProcedure(item.uuid) ? 'bg-gray-50 dark:bg-gray-1000' : 'hover:bg-gray-50 dark:hover:bg-gray-1000']" @click="toggleProcedure(item)"><span :class="['flex h-7 w-7 shrink-0 items-center justify-center rounded border', selectedProcedure(item.uuid) ? 'border-primary-500 bg-primary-600 text-white' : 'border-gray-200 text-slate-400 dark:border-gray-700']"><Icon :name="selectedProcedure(item.uuid) ? 'check' : 'plus'" /></span><span class="min-w-0 flex-1"><span class="block text-xs font-bold text-slate-700 dark:text-white">{{ item.name }}</span><span class="mt-0.5 flex flex-wrap items-center gap-2 font-mono text-[10px] text-slate-400"><span>{{ item.code }}</span><span v-if="item.care_requires_allergy_check" class="font-sans font-semibold text-amber-600 dark:text-amber-300">Allergies à vérifier</span><span v-if="item.care_recommends_vitals" class="font-sans font-semibold">Constantes recommandées</span></span></span></button>
                            <p v-if="filteredProcedures.length === 0" class="px-4 py-8 text-center text-xs text-slate-400">Aucun acte trouvé.</p>
                        </div>

                        <div v-if="episode.care_completion_mode === 'CHOICE'" class="mt-3 rounded border border-dashed border-gray-300 p-3 dark:border-gray-700">
                            <button type="button" class="flex w-full items-start gap-2 text-start" @click="toggleNoProcedure"><span :class="['mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded border', noProcedureSelected ? 'border-primary-600 bg-primary-600 text-white' : 'border-gray-300 dark:border-gray-700']"><Icon v-if="noProcedureSelected" name="check" class="text-xs" /></span><span><span class="block text-xs font-bold text-slate-700 dark:text-white">Aucun acte réalisé</span><span class="mt-0.5 block text-[11px] text-slate-400">Utilisez cette option seulement pour clôturer l’évaluation sans acte ni orientation médicale.</span></span></button>
                            <div v-if="noProcedureSelected" class="mt-3"><label for="no_procedure_reason" class="mb-1 block text-[11px] font-medium text-slate-500">Motif *</label><Input id="no_procedure_reason" v-model="form.no_procedure_reason" placeholder="Ex. patient reparti avant le soin" /><FormError class="mt-1" :message="form.errors.no_procedure_reason" /></div>
                        </div>

                        <div v-if="form.procedures.length" class="mt-4 max-h-80 space-y-3 overflow-y-auto pr-1">
                            <div v-for="(procedure, index) in form.procedures" :key="procedure.catalog_item_uuid" class="rounded border border-gray-200 p-3 dark:border-gray-800"><div class="flex items-start justify-between gap-3"><div><p class="text-xs font-bold text-slate-700 dark:text-white">{{ procedure.name }}</p><p v-if="procedure.care_requires_allergy_check" class="mt-1 text-[10px] font-semibold text-amber-600 dark:text-amber-300">Vérification allergique requise</p></div><button type="button" class="text-slate-400 hover:text-red-500" aria-label="Retirer cet acte" @click="toggleProcedure({ uuid: procedure.catalog_item_uuid })"><Icon name="cross" /></button></div><div class="mt-3 grid grid-cols-[90px_minmax(0,1fr)] gap-2"><div><label :for="`quantity-${index}`" class="mb-1 block text-[11px] text-slate-400">Quantité</label><Input :id="`quantity-${index}`" v-model="procedure.quantity" type="number" min="0.01" max="999" step="0.01" /></div><div><label :for="`notes-${index}`" class="mb-1 block text-[11px] text-slate-400">Observation{{ procedure.code === 'CARE-OTHER' ? ' *' : '' }}</label><Input :id="`notes-${index}`" v-model="procedure.notes" :placeholder="procedure.code === 'CARE-OTHER' ? 'Précisez l’acte' : 'Facultatif'" /></div></div><FormError class="mt-1" :message="procedureError(index, 'quantity') || procedureError(index, 'notes') || procedureError(index, 'allergy_checked')" /></div>
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

            <div v-if="capabilities.can_edit && (form.isDirty || form.hasErrors || form.procedures.length || noProcedureSelected)" class="space-y-3 border-t border-gray-200 pt-5 dark:border-gray-900">
                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-300">
                        <span v-if="form.procedures.length">Les actes seront enregistrés avec l’identité du soignant et l’heure de validation.</span>
                        <span v-else>Enregistrez uniquement les informations réellement constatées.</span>
                    </p>
                    <div class="flex flex-wrap justify-end gap-2">
                        <template v-if="capabilities.can_complete && episode.care_completion_mode === 'FINISH' && form.procedures.length">
                            <Button type="button" size="rg" variant="primary" :disabled="form.processing || !allergySafetyReady" @click="submitAndComplete(false)"><Icon name="check" /><span class="ms-2">{{ form.processing ? 'Validation…' : 'Enregistrer l’acte et terminer' }}</span></Button>
                        </template>
                        <template v-else-if="capabilities.can_complete && episode.care_completion_mode === 'MEDICINE'">
                            <Button type="button" size="rg" variant="primary" :disabled="form.processing || !allergySafetyReady" @click="submitAndComplete(false)"><Icon name="check" /><span class="ms-2">{{ form.processing ? 'Validation…' : 'Enregistrer et transmettre' }}</span></Button>
                        </template>
                        <template v-else-if="capabilities.can_complete && episode.care_completion_mode === 'CHOICE' && (form.procedures.length || hasNoProcedureReason)">
                            <Button type="button" size="rg" variant="white-outline" :disabled="form.processing || !allergySafetyReady" @click="submitAndComplete(true)">Enregistrer et orienter</Button>
                            <Button type="button" size="rg" variant="primary" :disabled="form.processing || !allergySafetyReady" @click="submitAndComplete(false)"><Icon name="check" /><span class="ms-2">{{ form.processing ? 'Validation…' : 'Enregistrer et terminer' }}</span></Button>
                        </template>
                        <Button v-else-if="episode.care_completion_mode === 'CHOICE' && noProcedureSelected" type="button" size="rg" variant="primary" disabled>Indiquez le motif</Button>
                        <Button v-else type="submit" size="rg" variant="primary" :disabled="form.processing || !allergySafetyReady"><Icon name="check" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : 'Enregistrer la fiche' }}</span></Button>
                    </div>
                </div>
            </div>
        </form>

        <section v-if="capabilities.can_complete && !form.isDirty && form.procedures.length === 0 && !noProcedureSelected" class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950 sm:flex-row sm:items-center sm:justify-between">
            <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Fin de prise en charge</h2><p class="mt-1 text-xs text-slate-400">{{ canCompleteWithoutSaving || episode.care_completion_mode === 'MEDICINE' ? 'La fiche est enregistrée. Vous pouvez maintenant terminer cette prise en charge.' : 'Enregistrez un acte réalisé avant de terminer les soins.' }}</p></div>
            <div class="flex flex-wrap gap-2">
                <Link v-if="canCompleteWithoutSaving" :href="`/care/orientations/${orientation.uuid}/complete`" method="post" as="button" preserve-scroll><Button size="rg" :variant="episode.care_completion_mode === 'MEDICINE' ? 'primary' : 'white-outline'"><Icon name="check" /><span class="ms-2">{{ episode.care_completion_mode === 'MEDICINE' ? 'Terminer et transmettre' : 'Terminer les soins' }}</span></Button></Link>
                <Link v-if="episode.care_completion_mode === 'CHOICE'" :href="`/care/orientations/${orientation.uuid}/complete-and-orient`" method="post" as="button" preserve-scroll><Button size="rg" variant="primary">Vers Médecine</Button></Link>
            </div>
        </section>
    </div>
</template>
