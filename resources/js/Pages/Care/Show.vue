<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import ClinicalPatientHeader from '@/Components/Clinical/ClinicalPatientHeader.vue';
import { formatDateTime } from '@/utilities/date';

defineOptions({ layout: AppLayout });

const props = defineProps({
    orientation: Object,
    careRecord: Object,
    careRecordDraft: { type: Object, default: null },
    bmiReference: Object,
    bloodPressureReference: Object,
    heartRateReference: Object,
    oxygenSaturationReference: Object,
    temperatureReference: Object,
    patientAllergies: Array,
    allergenReference: Array,
    procedureCatalog: Array,
    consumableCatalog: { type: Array, default: () => [] },
    consumableRequests: { type: Array, default: () => [] },
    careOrders: { type: Array, default: () => [] },
    hasActiveMedicineOrientation: { type: Boolean, default: false },
    latestDiagnosis: { type: Object, default: null },
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

// ADR-072 — an act planned at Réception already suggests its usual material,
// so the nurse finds it pre-filled rather than having to think of it.
const initialSuggestedConsumables = [];

if (props.capabilities.can_request_consumables) {
    initialRequestedProcedures.forEach((procedure) => {
        const entry = props.procedureCatalog.find((item) => item.uuid === procedure.catalog_item_uuid);

        (entry?.default_consumables ?? []).forEach((suggestion) => {
            const existing = initialSuggestedConsumables.find(
                (line) => line.medicine_uuid === suggestion.medicine_uuid,
            );

            if (existing) {
                existing.suggested_by.push(procedure.catalog_item_uuid);

                return;
            }

            const stock = props.consumableCatalog.find(
                (item) => item.medicine_uuid === suggestion.medicine_uuid,
            );
            initialSuggestedConsumables.push({
                medicine_uuid: suggestion.medicine_uuid,
                name: suggestion.name,
                code: suggestion.code,
                unit: suggestion.unit,
                available_quantity: stock?.available_quantity ?? 0,
                quantity: suggestion.quantity,
                suggested_by: [procedure.catalog_item_uuid],
                suggested_quantity: suggestion.quantity,
            });
        });
    });
}

const form = useForm({
    blood_group: props.careRecord?.blood_group ?? '',
    blood_pressure_systolic: props.careRecord?.blood_pressure_systolic ?? '',
    blood_pressure_diastolic: props.careRecord?.blood_pressure_diastolic ?? '',
    heart_rate: props.careRecord?.heart_rate ?? '',
    spo2: props.careRecord?.spo2 ?? '',
    temperature_celsius: props.careRecord?.temperature_celsius ?? '',
    known_diabetes: props.careRecord?.known_diabetes === true ? '1' : props.careRecord?.known_diabetes === false ? '0' : '',
    diabetes_note: props.careRecord?.diabetes_note ?? '',
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
    // ADR-072 — material used, saved with the acts in one submission.
    consumables: initialSuggestedConsumables,
    consumable_notes: '',
});

const formEl = ref(null);

// ── Saisie en cours ───────────────────────────────────────────────────────
// A nurse's entry must survive a page reload: only an explicit "Annuler la
// saisie" — or a real save — discards it. The draft lives server-side and is
// scoped to this account, so nothing clinical is left in a shared browser
// and no one inherits another nurse's unvalidated values.
const DRAFT_KEYS = Object.keys(form.data());
const draftSavedAt = ref(props.careRecordDraft?.updated_at ?? null);
const draftRestored = ref(false);
const draftSaving = ref(false);
let draftTimer = null;
let draftSuspended = false;

if (props.careRecordDraft?.payload) {
    Object.entries(props.careRecordDraft.payload).forEach(([key, value]) => {
        if (!DRAFT_KEYS.includes(key) || value === undefined) return;

        // A restored value must keep the shape the form expects. A null
        // where a string is expected would break every .trim() on the
        // worksheet and blank the page — never let the transport decide
        // the form's types.
        if (value === null) {
            form[key] = Array.isArray(form[key]) ? [] : '';

            return;
        }

        form[key] = value;
    });
    draftRestored.value = true;
}

const readCookie = (name) => document.cookie
    .split('; ')
    .find((row) => row.startsWith(`${name}=`))
    ?.split('=')[1];

const persistDraft = async () => {
    if (draftSuspended || !props.capabilities.can_edit) return;

    draftSaving.value = true;

    try {
        const response = await fetch(`/care/orientations/${props.orientation.uuid}/draft`, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(readCookie('XSRF-TOKEN') ?? ''),
            },
            body: JSON.stringify({ payload: form.data() }),
        });

        if (response.ok) {
            draftSavedAt.value = (await response.json()).saved_at;
        }
    } catch {
        // Offline or the session expired: the entry stays on screen and the
        // real save is still available. Never block the nurse for a draft.
    } finally {
        draftSaving.value = false;
    }
};

watch(
    () => form.data(),
    () => {
        if (draftSuspended || !props.capabilities.can_edit || !form.isDirty) return;

        clearTimeout(draftTimer);
        draftTimer = setTimeout(persistDraft, 1200);
    },
    { deep: true },
);

onBeforeUnmount(() => clearTimeout(draftTimer));

const discardDraft = () => {
    // Stop autosaving before the reload, otherwise the pending timer would
    // immediately recreate the draft we are deleting.
    draftSuspended = true;
    clearTimeout(draftTimer);

    router.delete(`/care/orientations/${props.orientation.uuid}/draft`, {
        onFinish: () => { draftSuspended = false; },
    });
};

// Wizard steps — a step only exists when the account can actually see that
// data (ADR-032/048), and "Transmission" only exists at all when this care
// pathway expects one (ADR-030). Navigation is free: nothing here is
// individually required field-by-field, only the final submission is gated
// (allergySafetyReady below) — so a nurse can jump straight to any step.
const steps = [
    { key: 'context', label: 'Contexte', icon: 'clipboard' },
    // ADR-032 — constants are optional for a standalone act (a dressing
    // needs no blood pressure): the step is marked as such instead of
    // looking like a gate the nurse must clear.
    ...(props.capabilities.can_view_vitals
        ? [{
            key: 'vitals',
            label: 'Constantes',
            icon: 'activity',
            optional: !props.orientation.episode.care_vitals_recommended,
        }]
        : []),
    ...(props.capabilities.can_view_allergies
        ? [{ key: 'allergies', label: 'Allergies', icon: 'shield-check', optional: true }]
        : []),
    // Acts and the material they consumed are one single gesture for a
    // nurse (a dressing IS its compresses): one step, one save.
    { key: 'procedures', label: 'Actes et matériel', icon: 'check-circle' },
    ...(props.orientation.episode.care_transmission_expected ? [{ key: 'transmission', label: 'Transmission', icon: 'send' }] : []),
    { key: 'finish', label: 'Terminer', icon: 'flag' },
];
/**
 * A patient who came only for a dressing has no constants to take, so the
 * page opens straight on the acts instead of making the nurse walk through
 * two optional steps first. Anything that expects constants, a transmission
 * or an allergy check still opens at the beginning.
 */
const initialStepIndex = (() => {
    const opensOnActs = !props.orientation.episode.care_vitals_recommended
        && !props.orientation.episode.care_transmission_expected
        && !props.careRecord;
    const actsIndex = steps.findIndex((step) => step.key === 'procedures');

    return opensOnActs && actsIndex > 0 ? actsIndex : 0;
})();
const currentStepIndex = ref(initialStepIndex);
const currentStepKey = computed(() => steps[currentStepIndex.value]?.key);
const stepIndexFor = (key) => steps.findIndex((step) => step.key === key);
const goToStep = (index) => {
    currentStepIndex.value = index;
    requestAnimationFrame(() => formEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
};
const nextStep = () => { if (currentStepIndex.value < steps.length - 1) goToStep(currentStepIndex.value + 1); };
const prevStep = () => { if (currentStepIndex.value > 0) goToStep(currentStepIndex.value - 1); };

// ── Consommables (ADR-072) ────────────────────────────────────────────────
// A separate submission from the care worksheet: declaring a consumable is
// what notifies Pharmacy, so it must not wait for the end of the visit.
// Soins never sees or enters a price — the charge is resolved server-side
// and collected by Réception/Caisse, exactly like a nursing act.
const consumableSearch = ref('');
const cancelTarget = ref(null);
const cancelReason = ref('');
const cancelProcessing = ref(false);
const cancelError = ref('');

const selectedConsumableUuids = computed(
    () => new Set(form.consumables.map((line) => line.medicine_uuid)),
);
const filteredConsumables = computed(() => {
    const term = consumableSearch.value.trim().toLowerCase();

    return props.consumableCatalog.filter((item) => {
        if (selectedConsumableUuids.value.has(item.medicine_uuid)) return false;
        if (!term) return true;

        return `${item.name} ${item.code}`.toLowerCase().includes(term);
    });
});
const addConsumable = (item) => {
    if (selectedConsumableUuids.value.has(item.medicine_uuid)) return;

    form.consumables = [...form.consumables, {
        medicine_uuid: item.medicine_uuid,
        name: item.name,
        code: item.code,
        unit: item.unit,
        available_quantity: item.available_quantity,
        quantity: 1,
    }];
};
const removeConsumable = (uuid) => {
    form.consumables = form.consumables.filter((line) => line.medicine_uuid !== uuid);
};

/**
 * ADR-072 — selecting an act pre-fills the material it usually consumes,
 * so the nurse confirms instead of searching. It stays a suggestion: every
 * line can be adjusted or removed, and the quantity the nurse types wins.
 */
const suggestConsumablesFor = (item) => {
    if (!props.capabilities.can_request_consumables) return;

    (item.default_consumables ?? []).forEach((suggestion) => {
        const existing = form.consumables.find((line) => line.medicine_uuid === suggestion.medicine_uuid);

        if (existing) {
            // Already there (another act, or added by hand): remember this
            // act needs it too, but never overwrite a chosen quantity.
            existing.suggested_by = [...new Set([...(existing.suggested_by ?? []), item.uuid])];
            return;
        }

        const stock = props.consumableCatalog.find(
            (entry) => entry.medicine_uuid === suggestion.medicine_uuid,
        );

        form.consumables = [...form.consumables, {
            medicine_uuid: suggestion.medicine_uuid,
            name: suggestion.name,
            code: suggestion.code,
            unit: suggestion.unit,
            available_quantity: stock?.available_quantity ?? 0,
            quantity: suggestion.quantity,
            suggested_by: [item.uuid],
            suggested_quantity: suggestion.quantity,
        }];
    });
};

/**
 * Removing an act withdraws only the suggestions it brought and that the
 * nurse never touched — a quantity they adjusted is a real declaration and
 * is kept.
 */
const dropSuggestedConsumables = (actUuid) => {
    form.consumables = form.consumables.filter((line) => {
        const origins = (line.suggested_by ?? []).filter((uuid) => uuid !== actUuid);

        if (!(line.suggested_by ?? []).includes(actUuid)) return true;

        line.suggested_by = origins;

        if (origins.length > 0) return true;

        const untouched = Number(line.quantity) === Number(line.suggested_quantity);

        return !untouched;
    });
};

const isSuggestedConsumable = (line) => (line.suggested_by ?? []).length > 0;
const suggestingActNames = (line) => (line.suggested_by ?? [])
    .map((uuid) => form.procedures.find((procedure) => procedure.catalog_item_uuid === uuid)?.name)
    .filter(Boolean)
    .join(' · ');

/** Acts whose usual material is not configured yet, to say so plainly. */
const actsWithoutConfiguredMaterial = computed(() => form.procedures
    .filter((procedure) => {
        const entry = props.procedureCatalog.find((item) => item.uuid === procedure.catalog_item_uuid);

        return entry && (entry.default_consumables ?? []).length === 0;
    })
    .map((procedure) => procedure.name));
const consumableLineError = (index, field) => form.errors[`consumables.${index}.${field}`];

const openCancelDialog = (request) => {
    cancelTarget.value = request;
    cancelReason.value = '';
    cancelError.value = '';
};
const closeCancelDialog = () => {
    if (cancelProcessing.value) return;
    cancelTarget.value = null;
};
const confirmCancel = () => {
    const reason = cancelReason.value.trim();

    if (!reason) {
        cancelError.value = 'Indiquez le motif de l’annulation.';
        return;
    }

    router.post(
        `/care/orientations/${props.orientation.uuid}/consumables/${cancelTarget.value.uuid}/cancel`,
        { reason },
        {
            preserveScroll: true,
            onStart: () => { cancelProcessing.value = true; },
            onSuccess: () => { cancelTarget.value = null; },
            onFinish: () => { cancelProcessing.value = false; },
        },
    );
};

const CONSUMABLE_STATUS_TONES = {
    PENDING: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300',
    PARTIALLY_SERVED: 'border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-900 dark:bg-primary-950/30 dark:text-primary-300',
    SERVED: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300',
    CANCELLED: 'border-gray-200 bg-gray-50 text-slate-500 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-400',
};
const consumableStatusTone = (status) => CONSUMABLE_STATUS_TONES[status] ?? CONSUMABLE_STATUS_TONES.CANCELLED;
const pendingConsumableRequests = computed(
    () => props.consumableRequests.filter((request) => request.can_be_served),
);

const historicalSnapshotAllergies = computed(() => (props.careRecord?.allergy_snapshot ?? []).filter(
    (allergy) => !allergy.uuid || !activePatientAllergyUuids.value.has(allergy.uuid),
));
const noProcedureSelected = ref(Boolean(props.careRecord?.no_procedure_reason));
const yesNoOptions = [
    { value: '', label: 'N/R', title: 'Non renseigné' },
    { value: '0', label: 'Non', title: 'Non' },
    { value: '1', label: 'Oui', title: 'Oui' },
];
const routingLabels = {
    MEDICINE_DIRECT: 'Médecine directe',
    CARE_THEN_MEDICINE: 'Soins → Médecine',
    CARE_ONLY: 'Soins uniquement',
};
const routingLabel = (mode) => routingLabels[mode] ?? 'Parcours à préciser';
const requestedCareProcedureUuids = computed(() => new Set(
    episode.value.designations
        .filter((designation) => designation.module === 'CARE')
        .map((designation) => designation.catalog_item_uuid),
));
const requestedAtReception = (uuid) => requestedCareProcedureUuids.value.has(uuid);

const severityLabels = { MILD: 'Légère', MODERATE: 'Modérée', SEVERE: 'Sévère' };
const showNewAllergy = ref(false);
const allergenReferenceSelection = ref('');
const newAllergyError = ref('');
const newAllergy = reactive({ substance: '', reaction: '', severity: '' });

const normalizeAllergyName = (value) => (typeof value === 'string' ? value : '')
    .trim().replace(/\s+/g, ' ').toLocaleLowerCase('fr');
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

const numericVitalValue = (value) => {
    if (value === '' || value === null || value === undefined) return null;

    const normalized = typeof value === 'string' ? value.trim().replace(',', '.') : value;
    const numeric = Number(normalized);

    return Number.isFinite(numeric) ? numeric : null;
};

const bloodPressureAssessment = computed(() => {
    const systolic = numericVitalValue(form.blood_pressure_systolic);
    const diastolic = numericVitalValue(form.blood_pressure_diastolic);
    const reference = props.bloodPressureReference;

    if (!reference || systolic === null || diastolic === null) return null;

    if (systolic < 40 || diastolic < 20) {
        return {
            code: 'MMHG_FORMAT',
            label: 'Format mmHg',
            tone: 'danger',
            message: 'Saisissez la valeur complète en mmHg : 170/120 et non 17/12.',
            compact: 'Saisir 170/120, pas 17/12',
        };
    }

    if (systolic > 300 || diastolic > 200 || systolic <= diastolic) {
        return {
            code: 'INCONSISTENT',
            label: 'TA incohérente',
            tone: 'danger',
            message: 'Vérifiez les valeurs SYS et DIA avant l’enregistrement.',
            compact: 'Vérifier SYS / DIA',
        };
    }

    if (systolic > reference.severe_systolic_above || diastolic > reference.severe_diastolic_above) {
        return reference.severe;
    }

    if (systolic < reference.low_systolic_below || diastolic < reference.low_diastolic_below) {
        return reference.low;
    }

    if (systolic >= reference.stage_two_systolic_from || diastolic >= reference.stage_two_diastolic_from) {
        return reference.stage_two;
    }

    if (systolic >= reference.stage_one_systolic_from || diastolic >= reference.stage_one_diastolic_from) {
        return reference.stage_one;
    }

    return null;
});

const heartRateAssessment = computed(() => {
    const value = numericVitalValue(form.heart_rate);
    const reference = props.heartRateReference;

    if (!reference || value === null || value <= 0 || value >= reference.low_threshold) return null;
    if (reference.patient_age === null || reference.patient_age === undefined) {
        return value < reference.marked_low_threshold
            ? reference.age_unknown_marked_low
            : reference.age_unknown_low;
    }
    if (reference.patient_age < reference.adult_min_age) return reference.pediatric_low;

    return value < reference.marked_low_threshold
        ? reference.adult_marked_low
        : reference.adult_low;
});

const oxygenSaturationAssessment = computed(() => {
    const value = numericVitalValue(form.spo2);
    const reference = props.oxygenSaturationReference;

    if (!reference || value === null || value < 0 || value > 100 || value >= reference.usual_minimum) return null;

    return value <= reference.danger_maximum ? reference.danger : reference.warning;
});

const temperatureAssessment = computed(() => {
    const value = numericVitalValue(form.temperature_celsius);
    const reference = props.temperatureReference;

    if (!reference || value === null || value < 25 || value > 45) return null;
    if (value < reference.low_danger_below) return reference.very_low;
    if (value < reference.low_warning_below) return reference.low;
    if (value >= reference.high_danger_from) return reference.very_high;
    if (value >= reference.fever_from) return reference.fever;

    return null;
});

const vitalInputClasses = (assessment) => assessment?.tone === 'danger'
    ? '!border-red-400 !bg-red-50/30 focus:!border-red-500 focus:!ring-red-100 dark:!border-red-800 dark:!bg-red-950/10 dark:focus:!ring-red-950'
    : assessment
        ? '!border-amber-400 !bg-amber-50/30 focus:!border-amber-500 focus:!ring-amber-100 dark:!border-amber-800 dark:!bg-amber-950/10 dark:focus:!ring-amber-950'
        : '';

const vitalGroupClasses = (assessment) => assessment?.tone === 'danger'
    ? '!border-red-400 !bg-red-50/30 focus-within:!border-red-500 focus-within:!ring-red-100 dark:!border-red-800 dark:!bg-red-950/10 dark:focus-within:!ring-red-950'
    : assessment
        ? '!border-amber-400 !bg-amber-50/30 focus-within:!border-amber-500 focus-within:!ring-amber-100 dark:!border-amber-800 dark:!bg-amber-950/10 dark:focus-within:!ring-amber-950'
        : '';

const compactVitalAlertClasses = (assessment) => assessment?.tone === 'danger'
    ? 'text-red-600 dark:text-red-300'
    : 'text-amber-700 dark:text-amber-300';

const filteredProcedures = computed(() => {
    const query = procedureSearch.value.trim().toLocaleLowerCase('fr');
    const selectedUuids = new Set(form.procedures.map((procedure) => procedure.catalog_item_uuid));

    return props.procedureCatalog
        .filter((item) => !selectedUuids.has(item.uuid))
        .filter((item) => !query || `${item.name} ${item.code}`.toLocaleLowerCase('fr').includes(query));
});

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
const trimmed = (value) => (typeof value === 'string' ? value.trim() : '');
const hasNoProcedureReason = computed(() => trimmed(form.no_procedure_reason).length > 0);
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
        dropSuggestedConsumables(item.uuid);
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
    suggestConsumablesFor(item);
};

const realizeOrderItem = (orderItem) => {
    if (!props.capabilities.can_edit) return;
    if (form.procedures.some((procedure) => procedure.care_order_item_uuid === orderItem.uuid)) return;

    const catalogEntry = props.procedureCatalog.find((entry) => entry.code === orderItem.code);
    noProcedureSelected.value = false;
    form.no_procedure_reason = '';

    if (catalogEntry) suggestConsumablesFor(catalogEntry);
    form.procedures.push({
        catalog_item_uuid: catalogEntry?.uuid,
        care_order_item_uuid: orderItem.uuid,
        code: orderItem.code,
        name: orderItem.name,
        quantity: orderItem.remaining_quantity,
        notes: '',
        care_requires_allergy_check: Boolean(catalogEntry?.care_requires_allergy_check),
        care_recommends_vitals: Boolean(catalogEntry?.care_recommends_vitals),
        allergy_checked: false,
    });
    // Realized from the Contexte step (where the medical order is listed) —
    // jump to Actes so the nurse immediately sees the confirmation fields
    // for what was just added, instead of it silently landing off-screen.
    goToStep(stepIndexFor('procedures'));
};

const notPerformedTarget = ref(null);
const notPerformedReason = ref('');
const markNotPerformed = (item) => {
    notPerformedTarget.value = item;
    notPerformedReason.value = '';
};
const confirmNotPerformed = () => {
    if (!notPerformedReason.value.trim()) return;
    router.post(
        `/care/orientations/${props.orientation.uuid}/care-order-items/${notPerformedTarget.value.uuid}/not-performed`,
        { reason: notPerformedReason.value },
        { preserveScroll: true, onSuccess: () => { notPerformedTarget.value = null; } },
    );
};

const activeCareOrder = computed(() => props.careOrders.find((order) => order.status !== 'COMPLETED') ?? null);
const careOrderUnresolvedCount = computed(() => activeCareOrder.value?.items.filter((item) => Number(item.remaining_quantity) > 0 && !item.not_performed_at).length ?? 0);
const isEmergency = computed(() => episode.value.priority === 'EMERGENCY');

// Purely a label: the actual destination/settlement rule is decided
// backend-side (CompleteCareAndOrientToMedicineAction) from the very same
// facts (activeCareOrder, care_completion_mode, hasActiveMedicineOrientation).
const completionLabel = computed(() => {
    if (activeCareOrder.value) {
        return activeCareOrder.value.requires_return_to_medicine
            ? 'Terminer et retourner à Médecine'
            : 'Terminer la prise en charge Soins';
    }
    if (isEmergency.value && props.hasActiveMedicineOrientation) return 'Terminer le travail Soins';
    if (episode.value.care_completion_mode === 'MEDICINE') return 'Enregistrer et transmettre à Médecine';
    if (episode.value.care_completion_mode === 'FINISH') return 'Terminer les soins';
    return 'Enregistrer et terminer';
});
const completionWarning = computed(() => {
    if (activeCareOrder.value && careOrderUnresolvedCount.value > 0) {
        const n = careOrderUnresolvedCount.value;
        return `${n} acte${n > 1 ? 's' : ''} demandé${n > 1 ? 's' : ''} reste${n > 1 ? 'nt' : ''} à réaliser ou à marquer non réalisé.`;
    }
    if (isEmergency.value && props.hasActiveMedicineOrientation) {
        return 'La fin du travail Soins ne ferme pas la prise en charge Médecine.';
    }
    if (activeCareOrder.value?.requires_return_to_medicine || episode.value.care_completion_mode === 'MEDICINE') {
        return 'Après validation, le patient sera réorienté vers Médecine.';
    }
    return 'Après validation, le parcours Soins sera terminé.';
});

const procedureSourceLabel = (source) => ({
    RECEPTION: 'Accueil',
    MEDICAL_ORDER: 'Ordre médical',
    ADDED_ON_SITE: 'Ajouté sur place',
}[source] ?? '—');

const orderStatusLabel = (status) => ({ PENDING: 'En attente', IN_PROGRESS: 'En cours', COMPLETED: 'Terminé' }[status] ?? status);
const orderStatusBadgeClass = (status) => ['rounded px-2 py-0.5 text-[10px] font-bold uppercase', {
    PENDING: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    IN_PROGRESS: 'bg-primary-100 text-primary-700 dark:bg-primary-950 dark:text-primary-200',
    COMPLETED: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200',
}[status] ?? 'bg-gray-100 text-slate-600 dark:bg-gray-900'];

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
// ── Récapitulatif de fin de prise en charge ──────────────────────────────
// The nurse validates from here, so the recap must show what will actually
// be recorded — not four counters. Every figure is read from the same
// sources the submission uses; nothing is recomputed independently.

/** Vitals actually entered, in the order a nurse reads them. */
const enteredVitals = computed(() => {
    if (!props.capabilities.can_view_vitals) return [];

    const systolic = form.blood_pressure_systolic;
    const diastolic = form.blood_pressure_diastolic;
    const entries = [];

    if (systolic && diastolic) {
        entries.push({ label: 'TA', value: `${systolic}/${diastolic} mmHg`, assessment: bloodPressureAssessment.value });
    }
    if (form.heart_rate) {
        entries.push({ label: 'FC', value: `${form.heart_rate} bpm`, assessment: heartRateAssessment.value });
    }
    if (form.spo2) {
        entries.push({ label: 'SpO₂', value: `${form.spo2} %`, assessment: oxygenSaturationAssessment.value });
    }
    if (form.temperature_celsius) {
        entries.push({ label: 'T°', value: `${form.temperature_celsius} °C`, assessment: temperatureAssessment.value });
    }
    if (displayedBmi.value) {
        entries.push({ label: 'IMC', value: displayedBmi.value, assessment: bmiAssessment.value });
    }
    if (form.height_cm) entries.push({ label: 'Taille', value: `${form.height_cm} cm`, assessment: null });
    if (form.weight_kg) entries.push({ label: 'Poids', value: `${form.weight_kg} kg`, assessment: null });
    if (form.blood_group) entries.push({ label: 'Groupe', value: form.blood_group, assessment: null });
    if (form.known_diabetes !== '') {
        entries.push({ label: 'Diabète', value: form.known_diabetes === '1' ? 'Oui' : 'Non', assessment: null });
    }
    if (form.smoker !== '') {
        entries.push({ label: 'Tabac', value: form.smoker === '1' ? 'Oui' : 'Non', assessment: null });
    }

    return entries;
});

/** Everything worth a second look before validating, never blocking. */
const completionWarnings = computed(() => {
    const warnings = [];

    [bloodPressureAssessment, heartRateAssessment, oxygenSaturationAssessment, temperatureAssessment, bmiAssessment]
        .map((assessment) => assessment.value)
        .filter((assessment) => assessment && assessment.tone !== 'success')
        .forEach((assessment) => warnings.push({
            tone: assessment.tone === 'danger' ? 'danger' : 'warning',
            text: `${assessment.label} — ${assessment.compact || assessment.message}`,
        }));

    if (requiresAllergySafetyCheck.value && !allergySafetyReady.value) {
        warnings.push({
            tone: 'danger',
            text: 'Statut allergique à vérifier avant d’enregistrer un acte à risque.',
        });
    }

    form.consumables
        .filter((line) => Number(line.quantity) > Number(line.available_quantity))
        .forEach((line) => warnings.push({
            tone: 'warning',
            text: `${line.name} : ${line.quantity} déclaré(s) pour ${line.available_quantity} en stock — la Pharmacie devra ajuster son inventaire.`,
        }));

    if (careOrderUnresolvedCount.value > 0) {
        warnings.push({
            tone: 'warning',
            text: `${careOrderUnresolvedCount.value} acte(s) demandé(s) par le médecin ne sont ni réalisés ni marqués non réalisés.`,
        });
    }

    if (props.capabilities.can_view_consumables && form.consumables.length > 0) {
        warnings.push({
            tone: 'info',
            text: 'Le matériel déclaré sera transmis à la Pharmacie pour la sortie de stock et facturé au passage.',
        });
    }

    return warnings;
});

/** What is already in the file and will not be recorded again. */
const alreadyOnFile = computed(() => {
    const entries = [];

    if (recordedProcedureCount.value > 0) {
        entries.push(`${recordedProcedureCount.value} acte(s) déjà enregistré(s)`);
    }
    if (props.consumableRequests.length > 0) {
        entries.push(`${props.consumableRequests.length} demande(s) de matériel déjà transmise(s)`);
    }
    if (props.careRecord) {
        entries.push(`Fiche ouverte par ${props.careRecord.created_by || '—'}`);
    }

    return entries;
});

const vitalCellClasses = (assessment) => {
    if (assessment?.tone === 'danger') {
        return 'border-red-200 bg-red-50/70 text-red-800 dark:border-red-900 dark:bg-red-950/20 dark:text-red-300';
    }
    if (assessment && assessment.tone !== 'success') {
        return 'border-amber-200 bg-amber-50/70 text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200';
    }

    return 'border-gray-200 text-slate-700 dark:border-gray-800 dark:text-white';
};

const newAllergyCount = computed(
    () => form.new_allergies.length + form.allergen_reference_uuids.length,
);

const buildPayload = (data, orientToMedicine = undefined) => {
    const payload = {
            ...data,
            blood_group: data.blood_group || null,
            blood_pressure_systolic: data.blood_pressure_systolic || null,
            blood_pressure_diastolic: data.blood_pressure_diastolic || null,
            heart_rate: data.heart_rate || null,
            spo2: data.spo2 || null,
            temperature_celsius: typeof data.temperature_celsius === 'string'
                ? (data.temperature_celsius.trim().replace(',', '.') || null)
                : (data.temperature_celsius || null),
            known_diabetes: data.known_diabetes === '' ? null : data.known_diabetes === '1',
            diabetes_note: data.known_diabetes === '1' ? (trimmed(data.diabetes_note) || null) : null,
            height_cm: data.height_cm || null,
            weight_kg: data.weight_kg || null,
            smoker: data.smoker === '' ? null : data.smoker === '1',
            no_procedure_reason: trimmed(data.no_procedure_reason) || null,
            procedures: data.procedures.map(({ catalog_item_uuid, care_order_item_uuid, quantity, notes, allergy_checked }) => ({
                catalog_item_uuid,
                care_order_item_uuid,
                quantity,
                notes,
                allergy_checked,
            })),
            consumables: data.consumables.map(({ medicine_uuid, quantity }) => ({
                medicine_uuid,
                quantity,
            })),
            // suggested_by / suggested_quantity stay in the browser: they
            // describe how a line got there, never what to record.
            consumable_notes: trimmed(data.consumable_notes) || null,
    };

    if (!props.capabilities.can_edit_vitals) {
        delete payload.blood_group;
        delete payload.blood_pressure_systolic;
        delete payload.blood_pressure_diastolic;
        delete payload.heart_rate;
        delete payload.spo2;
        delete payload.temperature_celsius;
        delete payload.known_diabetes;
        delete payload.diabetes_note;
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

    if (!props.capabilities.can_request_consumables || payload.consumables.length === 0) {
        delete payload.consumables;
        delete payload.consumable_notes;
    }

    if (orientToMedicine !== undefined) payload.orient_to_medicine = orientToMedicine;

    return payload;
};

// A field belongs to whichever step actually shows it — an error coming
// back from a step other than the one the nurse is currently on (always
// possible since submission only happens from "Terminer") must bring them
// back to it, otherwise the error is invisible.
const errorStepKeyByField = {
    blood_group: 'vitals', blood_pressure_systolic: 'vitals', blood_pressure_diastolic: 'vitals',
    heart_rate: 'vitals', spo2: 'vitals', temperature_celsius: 'vitals', known_diabetes: 'vitals',
    diabetes_note: 'vitals', height_cm: 'vitals', weight_kg: 'vitals', smoker: 'vitals',
    allergy_note: 'allergies', allergy_uuids: 'allergies', allergen_reference_uuids: 'allergies', new_allergies: 'allergies',
    diagnostic_note: 'transmission', transmission_reason: 'transmission',
};
const stepKeyForErrorField = (field) => {
    if (field.startsWith('procedures.') || field === 'no_procedure_reason' || field === 'care_record') return 'procedures';
    if (field.startsWith('consumables') || field === 'consumable_notes') return 'procedures';
    return errorStepKeyByField[field] ?? null;
};

const scrollToErrors = () => {
    const firstField = Object.keys(form.errors)[0];
    const stepKey = firstField ? stepKeyForErrorField(firstField) : null;
    const index = stepKey ? stepIndexFor(stepKey) : -1;
    if (index >= 0) currentStepIndex.value = index;
    requestAnimationFrame(() => formEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
};

const submit = () => {
    form.transform((data) => buildPayload(data)).put(`/care/orientations/${props.orientation.uuid}/record`, {
        preserveScroll: true,
        onSuccess: () => {
            // The server deleted the draft with the record it saved.
            draftSavedAt.value = null;
            draftRestored.value = false;
            clearTimeout(draftTimer);
            form.procedures = [];
            form.consumables = [];
            form.consumable_notes = '';
            consumableSearch.value = '';
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
        <ClinicalPatientHeader :patient="patient" :episode="episode" :reason="orientation.reason" back-href="/care" back-label="File Soins" />

        <div class="flex items-center gap-2">
            <span :class="['inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-bold', orientation.status === 'IN_PROGRESS' ? 'border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-900 dark:bg-primary-950/40 dark:text-primary-300' : orientation.status === 'PENDING' ? 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300' : orientation.status === 'COMPLETED' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300' : 'border-gray-200 bg-gray-50 text-slate-500 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-300']"><span class="h-1.5 w-1.5 rounded-full bg-current" />{{ orientation.status_label }}</span>
            <span class="text-xs text-slate-400">Pris en charge {{ orientation.accepted_at ? formatDateTime(orientation.accepted_at) : '—' }}<span v-if="orientation.accepted_by"> par {{ orientation.accepted_by }}</span></span>
        </div>

        <div v-if="capabilities.handled_by_other" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200" role="status">
            <Icon class="mt-0.5 shrink-0 text-base" name="lock" />
            <span>Ce patient est pris en charge par <strong>{{ orientation.accepted_by ?? 'un autre soignant' }}</strong>. Vous consultez la fiche en lecture seule : seule la personne qui l’a pris en charge peut la compléter ou transférer le patient.</span>
        </div>

        <div v-if="orientation.status === 'PENDING'" class="rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
            Le patient doit être pris en charge avant de renseigner cette fiche.
        </div>
        <div v-else-if="orientation.status === 'COMPLETED'" class="rounded-lg border border-gray-200 bg-white px-5 py-4 text-sm text-slate-500 dark:border-gray-900 dark:bg-gray-950 dark:text-slate-300">
            Cette prise en charge est terminée. La fiche reste consultable dans l’historique clinique.
        </div>

        <div v-if="capabilities.can_edit && (draftRestored || draftSavedAt)" class="flex flex-col gap-2 rounded-lg border border-primary-200 bg-primary-50/60 px-4 py-3 text-primary-900 dark:border-primary-900 dark:bg-primary-950/20 dark:text-primary-100 sm:flex-row sm:items-center sm:justify-between">
            <p class="flex items-start gap-2 text-xs leading-5">
                <Icon name="save" class="mt-0.5 shrink-0 text-base" />
                <span>
                    <strong>{{ draftRestored ? 'Saisie en cours restaurée.' : 'Saisie en cours conservée.' }}</strong>
                    Elle est enregistrée automatiquement et survit à une actualisation de la page. Rien n’est encore ajouté au dossier du patient.
                    <span v-if="draftSaving" class="text-primary-600 dark:text-primary-300"> Enregistrement…</span>
                    <span v-else-if="draftSavedAt" class="text-primary-600 dark:text-primary-300"> Dernier enregistrement {{ formatDateTime(draftSavedAt) }}.</span>
                </span>
            </p>
            <Button class="shrink-0" type="button" size="sm" variant="white-outline" @click="discardDraft">
                <Icon class="text-base" name="cross" /><span class="ms-1.5">Annuler la saisie</span>
            </Button>
        </div>

        <nav class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950" aria-label="Étapes de la fiche de soins">
            <ol class="flex overflow-x-auto">
                <li v-for="(step, index) in steps" :key="step.key" class="min-w-[150px] flex-1">
                    <button
                        type="button"
                        :class="['flex w-full items-center gap-3 px-4 py-3.5 text-start transition-colors', index > 0 ? 'border-s border-gray-200 dark:border-gray-900' : '', 'hover:bg-gray-50 dark:hover:bg-gray-1000']"
                        @click="goToStep(index)"
                    >
                        <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold', currentStepIndex === index ? 'bg-primary-600 text-white' : index < currentStepIndex ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-gray-100 text-slate-400 dark:bg-gray-900']">
                            <Icon v-if="index < currentStepIndex" class="text-base" name="check" />
                            <template v-else>{{ index + 1 }}</template>
                        </span>
                        <span class="min-w-0">
                            <span :class="['flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide', currentStepIndex === index ? 'text-primary-600 dark:text-primary-300' : 'text-slate-500']">
                                Étape {{ index + 1 }}
                                <span v-if="step.optional" class="rounded bg-gray-100 px-1 py-0.5 text-[9px] font-semibold normal-case tracking-normal text-slate-400 dark:bg-gray-900">facultatif</span>
                            </span>
                            <span class="flex items-center gap-1.5 truncate text-sm font-bold text-slate-700 dark:text-white"><Icon class="shrink-0 text-sm text-slate-400" :name="step.icon" />{{ step.label }}<span v-if="step.key === 'procedures' && form.procedures.length" class="ms-0.5 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500 dark:bg-gray-900 dark:text-slate-300">{{ form.procedures.length }}</span></span>
                        </span>
                    </button>
                </li>
            </ol>
        </nav>

        <form ref="formEl" class="space-y-5" novalidate @submit.prevent="submit">
            <section v-if="currentStepKey === 'context'" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm shadow-slate-200/20 dark:border-gray-900 dark:bg-gray-950 dark:shadow-none">
                <div class="flex items-start gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" name="clipboard" /></span>
                    <div class="min-w-0">
                        <h2 class="text-sm font-bold text-slate-700 dark:text-white">Demande et contexte du passage</h2>
                        <p class="mt-1 text-xs text-slate-400">Ce qui a motivé l’arrivée en Soins — ne signifie pas que l’acte a déjà été réalisé.</p>
                    </div>
                </div>

                <div class="px-5 py-4">
                    <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-300">Demande de l’accueil / Réception</h3>

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
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1"><strong class="text-sm font-semibold text-slate-700 dark:text-white">{{ designation.description }}</strong><span class="inline-flex shrink-0 items-center rounded bg-gray-100 px-2 py-1 text-[10px] font-semibold text-slate-500 dark:bg-gray-900 dark:text-slate-300">{{ routingLabel(designation.routing_mode) }}</span></span>
                                <span v-if="designation.code || Number(designation.quantity) > 1" class="mt-1 flex flex-wrap gap-x-2 text-[11px] text-slate-400"><span v-if="designation.code" class="font-mono">{{ designation.code }}</span><span v-if="Number(designation.quantity) > 1">Quantité : {{ designation.quantity }}</span></span>
                            </span>
                        </li>
                    </ul>
                </div>

                <div v-if="careOrders.length" class="border-t border-gray-200 bg-white px-5 py-4 dark:border-gray-900 dark:bg-gray-950">
                    <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-300">Ordre médical</h3>
                    <p class="mt-1 text-[11px] text-slate-400">Demandé par le médecin pendant la consultation — distinct des prestations de l’accueil ci-dessus.</p>
                    <ul class="mt-3 grid gap-3 md:grid-cols-2">
                        <li v-for="order in careOrders" :key="order.uuid" class="rounded-md border border-primary-100 bg-primary-50/40 px-3.5 py-3 dark:border-primary-950 dark:bg-primary-950/10">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-semibold text-slate-700 dark:text-white">Dr {{ order.requested_by }} · {{ formatDateTime(order.ordered_at) }}</p>
                                <span :class="orderStatusBadgeClass(order.status)">{{ orderStatusLabel(order.status) }}</span>
                            </div>
                            <ul class="mt-2 space-y-1.5 border-t border-primary-100 pt-2 dark:border-primary-950">
                                <li v-for="item in order.items" :key="item.uuid" class="text-sm text-slate-600 dark:text-slate-300">
                                    <div class="flex items-center justify-between gap-2">
                                        <span>{{ item.name }}</span>
                                        <span v-if="item.not_performed_at" class="text-[11px] font-semibold text-red-600 dark:text-red-300">Non réalisé</span>
                                        <span v-else class="text-[11px] font-semibold text-slate-400">{{ item.realized_quantity }}/{{ item.quantity }}</span>
                                    </div>
                                    <p v-if="item.not_performed_reason" class="mt-0.5 text-[11px] text-slate-400">Motif : {{ item.not_performed_reason }}</p>
                                    <div v-else-if="Number(item.remaining_quantity) > 0 && orientation.status === 'IN_PROGRESS' && capabilities.can_edit" class="mt-1 flex items-center gap-3">
                                        <button type="button" class="text-[11px] font-semibold text-primary-600 hover:underline dark:text-primary-300" @click="realizeOrderItem(item)">Réaliser</button>
                                        <button type="button" class="text-[11px] font-semibold text-red-500 hover:underline" @click="markNotPerformed(item)">Non réalisé</button>
                                    </div>
                                    <div v-if="notPerformedTarget && notPerformedTarget.uuid === item.uuid" class="mt-2 flex items-center gap-2">
                                        <Input v-model="notPerformedReason" size="sm" placeholder="Motif obligatoire" class="flex-1" />
                                        <Button type="button" size="sm" variant="danger-outline" @click="confirmNotPerformed">OK</Button>
                                        <button type="button" class="text-xs text-slate-400" @click="notPerformedTarget = null">Annuler</button>
                                    </div>
                                </li>
                            </ul>
                            <div v-if="order.instructions" class="mt-2 border-t border-primary-100 pt-2 dark:border-primary-950">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Instructions</p>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-300">{{ order.instructions }}</p>
                            </div>
                            <p class="mt-2 text-[11px] font-semibold text-slate-500 dark:text-slate-300">Retour médecin : {{ order.requires_return_to_medicine ? 'Oui' : 'Non' }}</p>
                        </li>
                    </ul>
                </div>
            </section>

            <section v-else-if="currentStepKey === 'vitals'" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm shadow-slate-200/20 dark:border-gray-900 dark:bg-gray-950 dark:shadow-none">
                <div class="flex items-start gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" name="activity" /></span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-sm font-bold text-slate-700 dark:text-white">Constantes de ce passage</h2>
                            <span v-if="!episode.care_vitals_recommended" class="rounded bg-gray-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:bg-gray-900 dark:text-slate-300">Facultatif</span>
                            <span v-else class="inline-flex items-center gap-1.5 rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />Recommandé</span>
                        </div>
                        <p class="mt-1 text-xs text-slate-400">Renseignez uniquement les données réellement relevées pendant ce passage. Tous les champs sont facultatifs.</p>
                    </div>
                </div>

                <div class="divide-y divide-gray-200 dark:divide-gray-900">
                    <section class="px-5 py-5">
                        <h3 class="mb-4 text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-300">Constantes vitales</h3>
                        <div class="grid gap-x-4 gap-y-4 sm:grid-cols-2 xl:grid-cols-[1.35fr_repeat(3,minmax(0,1fr))]">
                            <div class="min-w-0">
                                <label for="blood_pressure_systolic" class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-200">Tension artérielle</label>
                                <div :class="['grid h-10 grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)_auto] items-center overflow-hidden rounded border border-gray-200 bg-white transition focus-within:border-primary-500 focus-within:ring-2 focus-within:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:focus-within:ring-primary-950', vitalGroupClasses(bloodPressureAssessment)]">
                                    <input id="blood_pressure_systolic" v-model="form.blood_pressure_systolic" type="number" min="40" max="300" step="1" :disabled="!capabilities.can_edit_vitals" placeholder="SYS" aria-label="Pression artérielle systolique" class="h-full min-w-0 appearance-none border-0 bg-transparent px-3 text-sm text-slate-700 outline-none placeholder:text-slate-300 focus:ring-0 disabled:bg-slate-50 dark:text-white dark:disabled:bg-slate-950 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none" />
                                    <span class="text-slate-300">/</span>
                                    <input id="blood_pressure_diastolic" v-model="form.blood_pressure_diastolic" type="number" min="20" max="200" step="1" :disabled="!capabilities.can_edit_vitals" placeholder="DIA" aria-label="Pression artérielle diastolique" class="h-full min-w-0 appearance-none border-0 bg-transparent px-3 text-sm text-slate-700 outline-none placeholder:text-slate-300 focus:ring-0 disabled:bg-slate-50 dark:text-white dark:disabled:bg-slate-950 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none" />
                                    <span class="pe-3 text-[11px] font-medium text-slate-400">mmHg</span>
                                </div>
                                <FormError class="mt-1" :message="form.errors.blood_pressure_systolic || form.errors.blood_pressure_diastolic" />
                                <p v-if="bloodPressureAssessment" :class="['mt-1 flex items-center gap-1 text-[10px] font-semibold leading-4', compactVitalAlertClasses(bloodPressureAssessment)]" role="alert" aria-live="polite" :title="bloodPressureAssessment.message"><Icon name="alert-circle" class="shrink-0 text-xs" /><span>{{ bloodPressureAssessment.label }} · {{ bloodPressureAssessment.compact || 'Recontrôler' }}</span></p>
                            </div>
                            <div class="min-w-0">
                                <label for="heart_rate" class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-200">Fréquence cardiaque</label>
                                <div class="relative"><Input id="heart_rate" v-model="form.heart_rate" :class="['!h-10 pe-14 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none', vitalInputClasses(heartRateAssessment)]" type="number" min="20" max="250" step="1" :disabled="!capabilities.can_edit_vitals" placeholder="Ex. 78" /><span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-[11px] font-medium text-slate-400">bpm</span></div>
                                <FormError class="mt-1" :message="form.errors.heart_rate" />
                                <p v-if="heartRateAssessment" :class="['mt-1 flex items-center gap-1 text-[10px] font-semibold leading-4', compactVitalAlertClasses(heartRateAssessment)]" role="alert" aria-live="polite" :title="heartRateAssessment.message"><Icon name="alert-circle" class="shrink-0 text-xs" /><span>{{ heartRateAssessment.label }} · Recontrôler</span></p>
                            </div>
                            <div class="min-w-0">
                                <label for="spo2" class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-200">Saturation en oxygène</label>
                                <div class="relative"><Input id="spo2" v-model="form.spo2" :class="['!h-10 pe-10 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none', vitalInputClasses(oxygenSaturationAssessment)]" type="number" min="0" max="100" step="1" :disabled="!capabilities.can_edit_vitals" placeholder="Ex. 98" /><span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-[11px] font-medium text-slate-400">%</span></div>
                                <FormError class="mt-1" :message="form.errors.spo2" />
                                <p v-if="oxygenSaturationAssessment" :class="['mt-1 flex items-center gap-1 text-[10px] font-semibold leading-4', compactVitalAlertClasses(oxygenSaturationAssessment)]" role="alert" aria-live="polite" :title="oxygenSaturationAssessment.message"><Icon name="alert-circle" class="shrink-0 text-xs" /><span>{{ oxygenSaturationAssessment.label }} · Recontrôler</span></p>
                            </div>
                            <div class="min-w-0">
                                <label for="temperature_celsius" class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-200">Température</label>
                                <div class="relative"><Input id="temperature_celsius" v-model="form.temperature_celsius" :class="['!h-10 pe-10', vitalInputClasses(temperatureAssessment)]" type="text" inputmode="decimal" :disabled="!capabilities.can_edit_vitals" placeholder="Ex. 37,2" /><span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-[11px] font-medium text-slate-400">°C</span></div>
                                <FormError class="mt-1" :message="form.errors.temperature_celsius" />
                                <p v-if="temperatureAssessment" :class="['mt-1 flex items-center gap-1 text-[10px] font-semibold leading-4', compactVitalAlertClasses(temperatureAssessment)]" role="alert" aria-live="polite" :title="temperatureAssessment.message"><Icon name="alert-circle" class="shrink-0 text-xs" /><span>{{ temperatureAssessment.label }} · Recontrôler</span></p>
                            </div>
                        </div>
                    </section>

                    <section class="px-5 py-5">
                        <h3 class="mb-4 text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-300">Profil clinique</h3>
                        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            <fieldset class="min-w-0">
                                <legend class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-200">Diabète connu</legend>
                                <div class="grid h-10 grid-cols-3 gap-1 rounded border border-gray-200 bg-gray-50 p-1 dark:border-gray-800 dark:bg-gray-1000/60">
                                    <label v-for="option in yesNoOptions" :key="`diabetes-${option.value}`" :title="option.title" :class="['flex cursor-pointer items-center justify-center rounded px-2 text-xs font-semibold transition-colors', form.known_diabetes === option.value ? 'bg-white text-slate-700 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:text-white dark:ring-gray-700' : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-200', !capabilities.can_edit_vitals ? 'pointer-events-none opacity-60' : '']"><input v-model="form.known_diabetes" class="sr-only" type="radio" name="known_diabetes" :value="option.value" :disabled="!capabilities.can_edit_vitals" />{{ option.label }}</label>
                                </div>
                                <FormError class="mt-1" :message="form.errors.known_diabetes" />
                                <div v-if="form.known_diabetes === '1'" class="mt-2">
                                    <Input v-model="form.diabetes_note" placeholder="Type, traitement…" :disabled="!capabilities.can_edit_vitals" />
                                    <FormError class="mt-1" :message="form.errors.diabetes_note" />
                                </div>
                            </fieldset>
                            <fieldset class="min-w-0">
                                <legend class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-200">Tabac</legend>
                                <div class="grid h-10 grid-cols-3 gap-1 rounded border border-gray-200 bg-gray-50 p-1 dark:border-gray-800 dark:bg-gray-1000/60">
                                    <label v-for="option in yesNoOptions" :key="`smoker-${option.value}`" :title="option.title" :class="['flex cursor-pointer items-center justify-center rounded px-2 text-xs font-semibold transition-colors', form.smoker === option.value ? 'bg-white text-slate-700 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:text-white dark:ring-gray-700' : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-200', !capabilities.can_edit_vitals ? 'pointer-events-none opacity-60' : '']"><input v-model="form.smoker" class="sr-only" type="radio" name="smoker" :value="option.value" :disabled="!capabilities.can_edit_vitals" />{{ option.label }}</label>
                                </div>
                                <FormError class="mt-1" :message="form.errors.smoker" />
                            </fieldset>
                            <div class="min-w-0">
                                <label for="blood_group" class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-200">Groupe sanguin</label>
                                <div class="relative"><select id="blood_group" v-model="form.blood_group" :disabled="!capabilities.can_edit_vitals" class="block h-10 w-full appearance-none rounded border border-gray-200 bg-white px-3 pe-9 text-sm text-slate-700 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-1000"><option value="">N/R — Non renseigné</option><option v-for="group in ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']" :key="group" :value="group">{{ group }}</option></select><Icon class="pointer-events-none absolute inset-y-0 end-3 my-auto text-sm text-slate-400" name="chevron-down" /></div>
                                <FormError class="mt-1" :message="form.errors.blood_group" />
                            </div>
                        </div>

                        <div class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-900">
                            <div class="mb-3 flex items-center justify-between gap-3"><h4 class="text-xs font-semibold text-slate-600 dark:text-slate-200">Mesures corporelles</h4><span class="text-[11px] text-slate-400">L’IMC est calculé automatiquement</span></div>
                            <div class="grid gap-4 sm:grid-cols-3">
                                <div class="min-w-0"><label for="height_cm" class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-200">Taille</label><div class="relative"><Input id="height_cm" v-model="form.height_cm" class="!h-10 pe-12 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none" type="number" min="20" max="250" step="0.01" :disabled="!capabilities.can_edit_vitals" placeholder="Ex. 165" /><span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-[11px] font-medium text-slate-400">cm</span></div><FormError class="mt-1" :message="form.errors.height_cm" /></div>
                                <div class="min-w-0"><label for="weight_kg" class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-200">Poids</label><div class="relative"><Input id="weight_kg" v-model="form.weight_kg" class="!h-10 pe-12 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none" type="number" min="0.1" max="500" step="0.01" :disabled="!capabilities.can_edit_vitals" placeholder="Ex. 62" /><span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-[11px] font-medium text-slate-400">kg</span></div><FormError class="mt-1" :message="form.errors.weight_kg" /></div>
                                <div class="min-w-0"><span class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-200">IMC</span><div class="flex h-10 items-center justify-between rounded border border-gray-200 bg-gray-50 px-3 dark:border-gray-800 dark:bg-gray-1000"><strong class="text-sm text-slate-700 dark:text-white">{{ displayedBmi ?? '—' }}</strong><span v-if="bmiAssessment" :class="bmiBadgeClasses" class="rounded px-2 py-1 text-[10px] font-bold">{{ bmiAssessment.label }}</span><span v-else class="text-[10px] font-semibold text-slate-400">AUTO</span></div></div>
                            </div>
                            <div v-if="bmiAssessment" :class="bmiAlertClasses" class="mt-4 rounded-md border px-4 py-3" role="status" aria-live="polite">
                                <div class="flex items-start gap-3"><Icon :name="bmiAlertIcon" class="mt-0.5 shrink-0 text-lg" /><div class="min-w-0"><p class="text-xs font-semibold leading-5">{{ bmiAssessment.message }}</p><p class="mt-1 text-[11px] leading-4 opacity-65">{{ bmiReference.disclaimer }}</p></div></div>
                            </div>
                        </div>
                    </section>
                </div>
            </section>

            <section v-else-if="currentStepKey === 'allergies'" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm shadow-slate-200/20 dark:border-gray-900 dark:bg-gray-950 dark:shadow-none">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                    <div class="flex items-start gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" name="shield-check" /></span>
                        <div class="min-w-0">
                            <h2 class="text-sm font-bold text-slate-700 dark:text-white">Repères permanents du patient — Allergies</h2>
                            <p class="mt-1 text-xs text-slate-400">Dossier permanent du patient : sélectionnez une allergie connue ou ajoutez une substance absente du référentiel.</p>
                        </div>
                    </div>
                    <button v-if="capabilities.can_edit && capabilities.can_manage_allergies" type="button" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded border border-gray-200 bg-white px-3 text-xs font-semibold text-slate-600 transition-colors hover:border-primary-300 hover:text-primary-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300" @click="showNewAllergy = !showNewAllergy"><Icon name="plus" />Ajouter manuellement</button>
                </div>

                <div class="space-y-3 px-5 py-5">
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
                    <p v-else class="rounded border border-dashed border-gray-200 px-3 py-3 text-xs text-slate-400 dark:border-gray-800"><strong class="font-semibold text-slate-500 dark:text-slate-300">Non renseigné.</strong> Aucune allergie n’a encore été enregistrée pour ce patient — cela ne signifie pas qu’il n’en a aucune. Utilisez le référentiel ci-dessus ou l’ajout manuel si une allergie est connue.</p>

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
            </section>

            <section v-else-if="currentStepKey === 'procedures'" class="space-y-5">
                <div v-if="requiresAllergySafetyCheck" class="rounded-lg border border-amber-200 bg-amber-50/60 px-5 py-4 text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3"><Icon name="shield-check" class="mt-0.5 shrink-0 text-lg" /><div><p class="text-sm font-bold">Vérification de sécurité avant l’acte</p><p v-if="knownSafetyAllergyNames.length" class="mt-1 text-xs">Allergies signalées : <strong>{{ knownSafetyAllergyNames.join(' · ') }}</strong></p><p v-else class="mt-1 text-xs">Aucune allergie n’est enregistrée. Vérifiez directement le statut allergique auprès du patient.</p></div></div>
                        <label v-if="capabilities.can_view_allergies && capabilities.can_edit" class="inline-flex shrink-0 cursor-pointer items-center gap-2 rounded border border-amber-300 bg-white px-3 py-2 text-xs font-bold text-amber-900 dark:border-amber-800 dark:bg-gray-950 dark:text-amber-100"><input v-model="allergySafetyConfirmed" type="checkbox" class="h-4 w-4 rounded border-amber-400 text-primary-600 focus:ring-primary-500" />Statut allergique vérifié</label>
                        <p v-else-if="!capabilities.can_view_allergies" class="max-w-xs text-xs font-bold text-red-600 dark:text-red-300">Autorisation de consultation des allergies requise pour enregistrer cet acte.</p>
                    </div>
                    <FormError class="mt-2" :message="allergySafetyError" />
                </div>

                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm shadow-slate-200/20 dark:border-gray-900 dark:bg-gray-950 dark:shadow-none">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                        <div class="flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" name="check-circle" /></span>
                            <div class="min-w-0">
                                <h2 class="text-sm font-bold text-slate-700 dark:text-white">Actes à réaliser</h2>
                                <p class="mt-1 text-xs text-slate-400">Sélection en cours d’enregistrement — confirmez uniquement ce qui a réellement été réalisé.</p>
                            </div>
                        </div>
                        <span class="rounded bg-gray-100 px-2 py-1 text-xs font-bold text-slate-500 dark:bg-gray-900 dark:text-slate-300">{{ form.procedures.length }}</span>
                    </div>
                    <div v-if="capabilities.can_edit" class="divide-y divide-gray-200 dark:divide-gray-900">
                        <div class="grid gap-0 lg:grid-cols-[1fr_380px] lg:divide-x lg:divide-gray-200 dark:lg:divide-gray-900">
                            <div class="p-5">
                                <div class="mb-3 flex items-center justify-between gap-3"><h3 class="text-[11px] font-bold uppercase tracking-wide text-slate-500 dark:text-slate-300">Actes à confirmer</h3><span v-if="requestedCareProcedureUuids.size" class="text-[10px] font-medium text-slate-400">Préremplis depuis l’accueil</span></div>
                                <div v-if="form.procedures.length" class="max-h-[420px] space-y-3 overflow-y-auto pr-1">
                                    <div v-for="(procedure, index) in form.procedures" :key="procedure.catalog_item_uuid" class="rounded border border-gray-200 p-3 dark:border-gray-800">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="truncate text-xs font-bold text-slate-700 dark:text-white">{{ procedure.name }}</p>
                                                <div class="mt-1 flex flex-wrap items-center gap-1.5"><span class="font-mono text-[10px] text-slate-400">{{ procedure.code }}</span><span :class="['rounded px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide', requestedAtReception(procedure.catalog_item_uuid) ? 'bg-primary-50 text-primary-700 dark:bg-primary-950/30 dark:text-primary-300' : 'bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300']">{{ requestedAtReception(procedure.catalog_item_uuid) ? 'Demandé à l’accueil' : 'Ajouté aux Soins' }}</span><span v-if="procedure.care_requires_allergy_check" class="text-[10px] font-semibold text-amber-600 dark:text-amber-300">Allergies à vérifier</span></div>
                                            </div>
                                            <button type="button" class="flex h-7 w-7 shrink-0 items-center justify-center rounded text-slate-400 transition-colors hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-950/20" aria-label="Retirer cet acte" @click="toggleProcedure({ uuid: procedure.catalog_item_uuid })"><Icon name="trash" /></button>
                                        </div>
                                        <div class="mt-3 grid grid-cols-[90px_minmax(0,1fr)] gap-2"><div><label :for="`quantity-${index}`" class="mb-1 block text-[11px] text-slate-400">Quantité</label><Input :id="`quantity-${index}`" v-model="procedure.quantity" type="number" min="0.01" max="999" step="0.01" /></div><div><label :for="`notes-${index}`" class="mb-1 block text-[11px] text-slate-400">Observation{{ procedure.code === 'CARE-OTHER' ? ' *' : '' }}</label><Input :id="`notes-${index}`" v-model="procedure.notes" :placeholder="procedure.code === 'CARE-OTHER' ? 'Précisez l’acte' : 'Facultatif'" /></div></div>
                                        <FormError class="mt-1" :message="procedureError(index, 'quantity') || procedureError(index, 'notes') || procedureError(index, 'allergy_checked')" />
                                    </div>
                                </div>
                                <div v-else class="rounded border border-dashed border-gray-200 px-4 py-5 text-center dark:border-gray-800">
                                    <Icon class="text-xl text-slate-300" name="check-circle" />
                                    <p class="mt-2 text-xs font-semibold text-slate-600 dark:text-slate-300">Aucun acte infirmier sélectionné</p>
                                    <p class="mt-1 text-[11px] leading-5 text-slate-400">{{ requestedCareProcedureUuids.size ? 'Les actes demandés ont été retirés. Ajoutez uniquement ceux qui ont été réalisés.' : 'Aucun acte infirmier n’a été demandé à l’accueil. Ajoutez-en un seulement s’il est réellement effectué.' }}</p>
                                </div>
                            </div>

                            <div class="p-5">
                                <label for="procedure-search" class="mb-2 block text-[11px] font-bold uppercase tracking-wide text-slate-500 dark:text-slate-300">Ajouter un autre acte</label>
                                <div class="relative"><Input id="procedure-search" v-model="procedureSearch" icon="start" type="search" placeholder="Rechercher par nom ou code" autocomplete="off" /><span class="pointer-events-none absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400"><Icon name="search" /></span></div>
                                <div class="mt-3 max-h-56 overflow-y-auto rounded border border-gray-200 dark:border-gray-800">
                                    <button v-for="item in filteredProcedures" :key="item.uuid" type="button" class="flex w-full items-center gap-3 border-b border-gray-100 px-3 py-2.5 text-start transition-colors last:border-b-0 hover:bg-gray-50 dark:border-gray-900 dark:hover:bg-gray-1000" @click="toggleProcedure(item)"><span class="flex h-7 w-7 shrink-0 items-center justify-center rounded border border-gray-200 text-slate-400 dark:border-gray-700"><Icon name="plus" /></span><span class="min-w-0 flex-1"><span class="block text-xs font-bold text-slate-700 dark:text-white">{{ item.name }}</span><span class="mt-0.5 flex flex-wrap items-center gap-2 font-mono text-[10px] text-slate-400"><span>{{ item.code }}</span><span v-if="requestedAtReception(item.uuid)" class="font-sans font-semibold text-primary-600 dark:text-primary-300">Demandé à l’accueil</span><span v-if="item.care_requires_allergy_check" class="font-sans font-semibold text-amber-600 dark:text-amber-300">Allergies à vérifier</span><span v-if="item.care_recommends_vitals" class="font-sans font-semibold">Constantes recommandées</span><span v-if="(item.default_consumables ?? []).length" class="font-sans font-semibold text-primary-600 dark:text-primary-300">{{ item.default_consumables.length }} matériel(s) proposé(s)</span></span></span></button>
                                    <div v-if="filteredProcedures.length === 0" class="px-4 py-7 text-center"><Icon class="text-xl text-slate-300" name="search" /><p class="mt-2 text-xs font-semibold text-slate-500 dark:text-slate-300">{{ procedureSearch ? 'Aucun acte trouvé' : 'Tous les actes disponibles sont déjà sélectionnés' }}</p></div>
                                </div>
                            </div>
                        </div>

                        <div v-if="episode.care_completion_mode === 'CHOICE' && !activeCareOrder" class="p-5">
                            <div class="rounded border border-dashed border-gray-300 p-3 dark:border-gray-700">
                                <button type="button" class="flex w-full items-start gap-2 text-start" @click="toggleNoProcedure"><span :class="['mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded border', noProcedureSelected ? 'border-primary-600 bg-primary-600 text-white' : 'border-gray-300 dark:border-gray-700']"><Icon v-if="noProcedureSelected" name="check" class="text-xs" /></span><span><span class="block text-xs font-bold text-slate-700 dark:text-white">Aucun acte effectué</span><span class="mt-0.5 block text-[11px] text-slate-400">Utilisez cette option seulement pour clôturer l’évaluation sans acte ni orientation médicale.</span></span></button>
                                <div v-if="noProcedureSelected" class="mt-3"><label for="no_procedure_reason" class="mb-1 block text-[11px] font-medium text-slate-500">Motif *</label><Input id="no_procedure_reason" v-model="form.no_procedure_reason" placeholder="Ex. patient reparti avant le soin" /><FormError class="mt-1" :message="form.errors.no_procedure_reason" /></div>
                            </div>
                        </div>
                        <FormError class="px-5 pb-4" :message="form.errors.procedures || form.errors.care_record" />
                    </div>
                    <p v-else class="p-5 text-sm text-slate-400">La fiche est en lecture seule.</p>
                </div>

                <div v-if="capabilities.can_view_consumables" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm shadow-slate-200/20 dark:border-gray-900 dark:bg-gray-950 dark:shadow-none">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                        <div class="flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" name="package" /></span>
                            <div class="min-w-0">
                                <h2 class="text-sm font-bold text-slate-700 dark:text-white">Matériel utilisé pour ces actes</h2>
                                <p class="mt-1 text-xs text-slate-400">
                                    Pré-rempli d’après les actes sélectionnés — corrigez les quantités ou retirez ce qui n’a pas servi.
                                    Transmis à la Pharmacie pour la sortie de stock.
                                    <span class="font-semibold text-slate-500 dark:text-slate-300">Aucun médicament : les Soins ne prescrivent jamais.</span>
                                </p>
                            </div>
                        </div>
                        <span v-if="form.consumables.length" class="rounded bg-gray-100 px-2 py-1 text-xs font-bold text-slate-500 dark:bg-gray-900 dark:text-slate-300">{{ form.consumables.length }}</span>
                    </div>

                    <div v-if="capabilities.can_request_consumables" class="grid gap-0 lg:grid-cols-[1fr_380px] lg:divide-x lg:divide-gray-200 dark:lg:divide-gray-900">
                        <div class="p-5">
                            <div v-if="form.consumables.length" class="space-y-2">
                                <div v-for="(line, index) in form.consumables" :key="line.medicine_uuid" class="flex flex-wrap items-center gap-3 rounded border border-gray-200 px-3 py-2 dark:border-gray-800">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-xs font-bold text-slate-700 dark:text-white">{{ line.name }}</p>
                                        <p class="mt-0.5 flex flex-wrap items-center gap-2 text-[10px] text-slate-400">
                                            <span class="font-mono">{{ line.code }}</span>
                                            <span>{{ line.available_quantity }} {{ line.unit }} en stock</span>
                                            <span v-if="isSuggestedConsumable(line)" class="rounded bg-primary-50 px-1.5 py-0.5 font-semibold text-primary-700 dark:bg-primary-950/30 dark:text-primary-300" :title="`Proposé automatiquement pour : ${suggestingActNames(line)}`">
                                                Proposé par l’acte
                                            </span>
                                            <span v-else class="rounded bg-gray-100 px-1.5 py-0.5 font-semibold text-slate-500 dark:bg-gray-900 dark:text-slate-300">Ajouté à la main</span>
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-1.5">
                                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 transition-colors hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-900" :aria-label="`Diminuer ${line.name}`" @click="line.quantity = Math.max(1, Number(line.quantity || 1) - 1)"><Icon name="minus" /></button>
                                        <Input :id="`consumable-qty-${index}`" v-model="line.quantity" class="w-16 text-center" type="number" min="1" step="1" :aria-label="`Quantité de ${line.name} en ${line.unit}`" />
                                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 transition-colors hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-900" :aria-label="`Augmenter ${line.name}`" @click="line.quantity = Number(line.quantity || 0) + 1"><Icon name="plus" /></button>
                                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded text-slate-400 transition-colors hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-950/20" :aria-label="`Retirer ${line.name}`" @click="removeConsumable(line.medicine_uuid)"><Icon name="trash" /></button>
                                    </div>
                                    <p v-if="Number(line.quantity) > line.available_quantity" class="w-full text-[11px] font-semibold text-amber-600 dark:text-amber-300">
                                        Au-delà du stock enregistré — la Pharmacie devra ajuster son inventaire.
                                    </p>
                                    <FormError class="w-full" :message="consumableLineError(index, 'quantity') || consumableLineError(index, 'medicine_uuid')" />
                                </div>
                            </div>
                            <p v-else class="rounded border border-dashed border-gray-200 px-4 py-4 text-center text-[11px] leading-5 text-slate-400 dark:border-gray-800">
                                Aucun matériel déclaré. Laissez vide si aucun consommable n’a été utilisé.
                            </p>

                            <p v-if="actsWithoutConfiguredMaterial.length" class="mt-3 flex items-start gap-2 rounded border border-gray-200 bg-gray-50/60 px-3 py-2 text-[11px] leading-5 text-slate-500 dark:border-gray-800 dark:bg-gray-900/40 dark:text-slate-400">
                                <Icon name="info" class="mt-0.5 shrink-0 text-sm" />
                                <span>
                                    Aucun matériel habituel n’est encore configuré pour {{ actsWithoutConfiguredMaterial.join(' · ') }}.
                                    Ajoutez-le à la main ici ; le responsable peut le prédéfinir dans Administration&nbsp;›&nbsp;Catalogue.
                                </span>
                            </p>

                            <div v-if="form.consumables.length" class="mt-3">
                                <label for="consumable_notes" class="mb-1.5 block text-[11px] font-medium text-slate-500 dark:text-slate-300">Précision pour la Pharmacie <span class="font-normal text-slate-400">(facultatif)</span></label>
                                <Input id="consumable_notes" v-model="form.consumable_notes" placeholder="Ex. pansement refait deux fois" />
                                <FormError class="mt-1" :message="form.errors.consumable_notes || form.errors.consumables" />
                            </div>
                        </div>

                        <div class="p-5">
                            <label for="consumable-search" class="mb-2 block text-[11px] font-bold uppercase tracking-wide text-slate-500 dark:text-slate-300">Ajouter du matériel</label>
                            <div class="relative"><Input id="consumable-search" v-model="consumableSearch" icon="start" type="search" placeholder="Rechercher par nom ou code" autocomplete="off" /><span class="pointer-events-none absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400"><Icon name="search" /></span></div>
                            <div class="mt-3 max-h-56 overflow-y-auto rounded border border-gray-200 dark:border-gray-800">
                                <button v-for="item in filteredConsumables" :key="item.medicine_uuid" type="button" class="flex w-full items-center gap-3 border-b border-gray-100 px-3 py-2.5 text-start transition-colors last:border-b-0 hover:bg-gray-50 dark:border-gray-900 dark:hover:bg-gray-1000" @click="addConsumable(item)">
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded border border-gray-200 text-slate-400 dark:border-gray-700"><Icon name="plus" /></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-xs font-bold text-slate-700 dark:text-white">{{ item.name }}</span>
                                        <span class="mt-0.5 flex flex-wrap items-center gap-2 font-mono text-[10px] text-slate-400">
                                            <span>{{ item.code }}</span>
                                            <span :class="['font-sans font-semibold', item.available ? 'text-emerald-600 dark:text-emerald-300' : 'text-red-600 dark:text-red-300']">{{ item.available ? `${item.available_quantity} ${item.unit} en stock` : 'Rupture de stock' }}</span>
                                        </span>
                                    </span>
                                </button>
                                <div v-if="filteredConsumables.length === 0" class="px-4 py-7 text-center">
                                    <Icon class="text-xl text-slate-300" name="search" />
                                    <p class="mt-2 text-xs font-semibold text-slate-500 dark:text-slate-300">{{ consumableSearch ? 'Aucun matériel trouvé' : (consumableCatalog.length ? 'Tout le matériel est déjà sélectionné' : 'Aucun consommable de parapharmacie n’est configuré en Pharmacie') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p v-else class="p-5 text-sm text-slate-400">Vous pouvez consulter le matériel de ce passage, mais pas en déclarer.</p>
                </div>

                <section v-if="consumableRequests.length" class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                        <div>
                            <h2 class="text-sm font-bold text-slate-700 dark:text-white">Matériel transmis à la Pharmacie</h2>
                            <p class="mt-1 text-xs text-slate-400">Une déclaration reste dans l’historique : elle est annulée, jamais supprimée.</p>
                        </div>
                        <span v-if="pendingConsumableRequests.length" class="shrink-0 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-bold text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300">
                            {{ pendingConsumableRequests.length }} en attente Pharmacie
                        </span>
                    </div>
                    <div class="divide-y divide-gray-200 dark:divide-gray-900">
                        <article v-for="request in consumableRequests" :key="request.uuid" class="p-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-xs font-bold text-slate-700 dark:text-white">{{ request.request_number }}</span>
                                        <span :class="['inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide', consumableStatusTone(request.status)]">{{ request.status_label }}</span>
                                    </div>
                                    <p class="mt-1 text-[11px] text-slate-400">
                                        Déclaré par {{ request.requested_by || '—' }} · {{ formatDateTime(request.requested_at) }}
                                        <template v-if="request.served_at"> · servi par {{ request.served_by || 'Pharmacie' }} le {{ formatDateTime(request.served_at) }}</template>
                                    </p>
                                    <p v-if="request.notes" class="mt-1 text-xs text-slate-500 dark:text-slate-300">{{ request.notes }}</p>
                                </div>
                                <Button v-if="request.can_be_cancelled && capabilities.can_cancel_consumables" type="button" size="sm" variant="danger-outline" @click="openCancelDialog(request)">
                                    <Icon class="text-base" name="cross" /><span class="ms-1.5">Annuler</span>
                                </Button>
                            </div>

                            <ul class="mt-3 space-y-2">
                                <li v-for="line in request.lines" :key="line.uuid" class="rounded border border-gray-200 px-3 py-2 dark:border-gray-800">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="min-w-0 text-xs font-semibold text-slate-700 dark:text-white">{{ line.name }} <span class="font-mono font-normal text-slate-400">{{ line.code }}</span></span>
                                        <span class="shrink-0 text-xs text-slate-500 dark:text-slate-300">
                                            <strong class="text-slate-700 dark:text-white">{{ line.quantity_served }}</strong> / {{ line.quantity_requested }} {{ line.unit }} sortis du stock
                                        </span>
                                    </div>
                                    <ul v-if="line.allocations.length" class="mt-1.5 flex flex-wrap gap-1.5">
                                        <li v-for="allocation in line.allocations" :key="allocation.uuid" class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[10px] text-slate-500 dark:bg-gray-900 dark:text-slate-300">
                                            Lot {{ allocation.lot_number }} · {{ allocation.quantity }}
                                        </li>
                                    </ul>
                                </li>
                            </ul>

                            <p v-if="request.cancellation_reason" class="mt-2 text-[11px] text-slate-400">
                                Annulé par {{ request.cancelled_by || '—' }} — {{ request.cancellation_reason }}
                            </p>
                        </article>
                    </div>
                </section>

                <section v-if="careRecord?.procedures?.length" class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                    <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Actes réalisés</h2><p class="mt-1 text-xs text-slate-400">Les actes enregistrés restent dans l’historique et ne sont pas supprimés.</p></div>
                    <div class="overflow-x-auto"><table class="w-full min-w-[820px] border-collapse"><thead class="bg-gray-50/70 dark:bg-gray-1000/40"><tr><th class="px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Acte</th><th class="px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Source</th><th class="px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Qté</th><th class="px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Observation</th><th class="px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400">Réalisé par</th><th class="px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-slate-400">Date / heure</th></tr></thead><tbody class="divide-y divide-gray-200 dark:divide-gray-900"><tr v-for="procedure in careRecord.procedures" :key="procedure.uuid"><td class="px-5 py-3"><span class="block text-sm font-bold text-slate-700 dark:text-white">{{ procedure.name }}</span><span class="font-mono text-xs text-slate-400">{{ procedure.code }}</span></td><td class="px-5 py-3 text-xs text-slate-500 dark:text-slate-300">{{ procedureSourceLabel(procedure.source) }}</td><td class="px-5 py-3 text-sm text-slate-600 dark:text-slate-300">{{ procedure.quantity }}</td><td class="max-w-sm px-5 py-3 text-sm text-slate-500 dark:text-slate-300">{{ procedure.notes || '—' }}</td><td class="px-5 py-3 text-sm text-slate-500 dark:text-slate-300">{{ procedure.performed_by || '—' }}</td><td class="px-5 py-3 text-end text-sm text-slate-500 dark:text-slate-300">{{ formatDateTime(procedure.performed_at) }}</td></tr></tbody></table></div>
                </section>
            </section>

            <section v-else-if="currentStepKey === 'transmission'" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm shadow-slate-200/20 dark:border-gray-900 dark:bg-gray-950 dark:shadow-none">
                <div class="flex items-start gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" name="send" /></span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Transmission clinique</h2><span class="inline-flex items-center gap-1 rounded bg-primary-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-primary-700 dark:bg-primary-950/30 dark:text-primary-300">Vers Médecine</span></div>
                        <p class="mt-1 text-xs text-slate-400">Ce qui doit être su avant la suite du parcours.</p>
                    </div>
                </div>
                <div class="grid gap-4 p-5">
                    <div v-if="latestDiagnosis" class="rounded border border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-gray-1000/40">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Diagnostic transmis <span class="rounded bg-gray-200 px-1.5 py-0.5 text-[9px] font-bold text-slate-500 dark:bg-gray-800 dark:text-slate-300">Lecture seule</span></p>
                        <p class="mt-1.5 text-sm font-semibold text-slate-700 dark:text-white">{{ latestDiagnosis.description }}</p>
                        <p class="mt-1 text-[11px] text-slate-400">Dr {{ latestDiagnosis.doctor }} · {{ formatDateTime(latestDiagnosis.recorded_at) }}</p>
                    </div>
                    <div>
                        <label for="diagnostic_note" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Information médicale complémentaire <span class="font-normal text-slate-400">(si communiquée verbalement)</span></label>
                        <textarea id="diagnostic_note" v-model="form.diagnostic_note" rows="2" :disabled="!capabilities.can_edit" placeholder="Ne pas inventer un diagnostic : uniquement ce qui a été réellement communiqué" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-1000" />
                        <FormError class="mt-1" :message="form.errors.diagnostic_note" />
                    </div>
                    <div><label for="transmission_reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Observations / transmission infirmière</label><textarea id="transmission_reason" v-model="form.transmission_reason" rows="3" :disabled="!capabilities.can_edit" placeholder="État du patient, actes réalisés, réaction, surveillance, points de vigilance" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-1000" /><FormError class="mt-1" :message="form.errors.transmission_reason" /></div>
                </div>
            </section>

            <section v-else-if="currentStepKey === 'finish'" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm shadow-slate-200/20 dark:border-gray-900 dark:bg-gray-950 dark:shadow-none">
                <header class="flex items-start justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" name="flag" /></span>
                        <div class="min-w-0">
                            <h2 class="text-sm font-bold text-slate-700 dark:text-white">Fin de prise en charge</h2>
                            <p class="mt-1 truncate text-xs text-slate-400">{{ patient.last_name }} {{ patient.first_name }} · passage {{ episode.episode_number }}</p>
                        </div>
                    </div>
                    <span v-if="isEmergency" class="shrink-0 rounded-full border border-red-200 bg-red-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">Urgence</span>
                </header>

                <!-- Content on the left, context on the right: a single wide
                     column left "Aucun" floating in empty space and gave the
                     alerts the same visual weight as the data. -->
                <div :class="['grid gap-0', completionWarnings.length ? 'lg:grid-cols-[minmax(0,1fr)_340px] lg:divide-x lg:divide-gray-200 dark:lg:divide-gray-900' : '']">
                    <div class="space-y-5 p-5">
                        <div>
                            <h3 class="text-[11px] font-bold uppercase tracking-wide text-slate-500 dark:text-slate-300">À enregistrer maintenant</h3>
                            <dl class="mt-3 space-y-2.5">
                                <div class="grid gap-0.5 sm:grid-cols-[132px_minmax(0,1fr)] sm:gap-3">
                                    <dt class="text-xs text-slate-400">Actes</dt>
                                    <dd>
                                        <ul v-if="form.procedures.length" class="space-y-1">
                                            <li v-for="procedure in form.procedures" :key="procedure.catalog_item_uuid" class="text-xs text-slate-700 dark:text-white">
                                                <strong class="font-semibold">{{ procedure.name }}</strong>
                                                <span class="text-slate-400"> × {{ procedure.quantity }}</span>
                                                <span v-if="procedure.notes" class="block text-[11px] text-slate-400">{{ procedure.notes }}</span>
                                            </li>
                                        </ul>
                                        <span v-else-if="hasNoProcedureReason" class="text-xs text-slate-600 dark:text-slate-300">Aucun — {{ form.no_procedure_reason }}</span>
                                        <span v-else class="text-xs text-slate-300 dark:text-slate-600">—</span>
                                    </dd>
                                </div>

                                <div v-if="capabilities.can_view_consumables" class="grid gap-0.5 sm:grid-cols-[132px_minmax(0,1fr)] sm:gap-3">
                                    <dt class="text-xs text-slate-400">Matériel</dt>
                                    <dd>
                                        <ul v-if="form.consumables.length" class="space-y-1">
                                            <li v-for="line in form.consumables" :key="line.medicine_uuid" class="text-xs text-slate-700 dark:text-white">
                                                <strong class="font-semibold">{{ line.name }}</strong>
                                                <span class="text-slate-400"> × {{ line.quantity }} {{ line.unit }}</span>
                                            </li>
                                        </ul>
                                        <span v-else class="text-xs text-slate-300 dark:text-slate-600">—</span>
                                    </dd>
                                </div>

                                <div v-if="capabilities.can_view_allergies" class="grid gap-0.5 sm:grid-cols-[132px_minmax(0,1fr)] sm:gap-3">
                                    <dt class="text-xs text-slate-400">Allergies</dt>
                                    <dd class="text-xs text-slate-700 dark:text-white">
                                        <span v-if="knownSafetyAllergyNames.length" class="font-semibold">{{ knownSafetyAllergyNames.join(' · ') }}</span>
                                        <span v-else class="text-slate-300 dark:text-slate-600">Aucune signalée</span>
                                        <span v-if="newAllergyCount" class="mt-0.5 block text-[11px] text-primary-600 dark:text-primary-300">{{ newAllergyCount }} ajoutée(s) au dossier permanent</span>
                                    </dd>
                                </div>

                                <div v-if="episode.care_transmission_expected" class="grid gap-0.5 sm:grid-cols-[132px_minmax(0,1fr)] sm:gap-3">
                                    <dt class="text-xs text-slate-400">Transmission</dt>
                                    <dd class="text-xs">
                                        <span v-if="trimmed(form.transmission_reason)" class="text-slate-700 dark:text-white">{{ form.transmission_reason }}</span>
                                        <span v-else class="text-slate-300 dark:text-slate-600">Non renseignée</span>
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <div v-if="capabilities.can_view_vitals">
                            <h3 class="text-[11px] font-bold uppercase tracking-wide text-slate-500 dark:text-slate-300">Constantes relevées</h3>
                            <!-- Labelled cells rather than inline chips: this
                                 is the densest data on the card and reads
                                 like a monitor, value first. -->
                            <div v-if="enteredVitals.length" class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                                <div v-for="vital in enteredVitals" :key="vital.label" :class="['rounded border px-2.5 py-2', vitalCellClasses(vital.assessment)]">
                                    <p class="text-[10px] font-medium uppercase tracking-wide opacity-70">{{ vital.label }}</p>
                                    <p class="mt-0.5 text-sm font-bold leading-5">{{ vital.value }}</p>
                                </div>
                            </div>
                            <p v-else class="mt-3 rounded border border-dashed border-gray-200 px-3 py-3 text-center text-[11px] text-slate-400 dark:border-gray-800">
                                Aucune constante relevée — facultatif pour ce passage.
                            </p>
                        </div>

                        <p v-if="alreadyOnFile.length" class="flex flex-wrap items-center gap-x-2 gap-y-1 border-t border-gray-100 pt-3 text-[11px] text-slate-400 dark:border-gray-900">
                            <Icon name="history" class="text-sm" />
                            <span>Déjà au dossier : {{ alreadyOnFile.join(' · ') }}</span>
                        </p>
                    </div>

                    <aside v-if="completionWarnings.length" class="border-t border-gray-200 p-5 dark:border-gray-900 lg:border-t-0">
                        <h3 class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-wide text-slate-500 dark:text-slate-300">
                            Points de vigilance
                            <span class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500 dark:bg-gray-900 dark:text-slate-300">{{ completionWarnings.length }}</span>
                        </h3>
                        <ul class="mt-3 space-y-2">
                            <li v-for="warning in completionWarnings" :key="warning.text" :class="['flex items-start gap-2 rounded border-s-2 py-1.5 pe-2 ps-2.5 text-[11px] leading-4', {
                                danger: 'border-s-red-500 bg-red-50/60 text-red-800 dark:bg-red-950/20 dark:text-red-300',
                                warning: 'border-s-amber-500 bg-amber-50/60 text-amber-900 dark:bg-amber-950/20 dark:text-amber-200',
                                info: 'border-s-slate-300 bg-gray-50 text-slate-600 dark:border-s-slate-600 dark:bg-gray-900/40 dark:text-slate-300',
                            }[warning.tone]]">
                                <Icon :name="warning.tone === 'info' ? 'info' : 'alert-circle'" class="mt-px shrink-0 text-xs" />
                                <span>{{ warning.text }}</span>
                            </li>
                        </ul>
                        <p class="mt-3 text-[10px] leading-4 text-slate-400">
                            Ces repères sont une aide au dépistage et ne bloquent pas la validation.
                        </p>
                    </aside>
                </div>

                <footer v-if="activeCareOrder || capabilities.can_complete" :class="['flex items-start gap-2 border-t px-5 py-3 text-xs font-semibold', careOrderUnresolvedCount > 0 ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200' : 'border-gray-200 bg-gray-50/70 text-slate-600 dark:border-gray-900 dark:bg-gray-1000/40 dark:text-slate-300']">
                    <Icon :name="careOrderUnresolvedCount > 0 ? 'alert-circle' : 'arrow-right'" class="mt-px shrink-0 text-sm" />
                    <span>{{ completionWarning }}</span>
                </footer>
            </section>

            <div class="flex flex-col-reverse gap-3 border-t border-gray-200 pt-5 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                <Button v-if="currentStepIndex > 0" type="button" size="rg" variant="white-outline" @click="prevStep"><Icon class="text-lg" name="arrow-left" /><span class="ms-2">Précédent</span></Button>
                <span v-else />

                <Button v-if="currentStepKey !== 'finish'" type="button" size="rg" variant="primary" @click="nextStep">Suivant<Icon class="ms-2 text-lg" name="arrow-right" /></Button>

                <div v-else-if="capabilities.can_edit && (form.isDirty || form.hasErrors || form.procedures.length || noProcedureSelected || activeCareOrder)" class="flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center">
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-300 sm:me-2">
                        <span v-if="form.consumables.length">Les actes et le matériel seront enregistrés ensemble ; la Pharmacie recevra la demande de sortie de stock.</span>
                        <span v-else-if="form.procedures.length">Les actes seront enregistrés avec l’identité du soignant et l’heure de validation.</span>
                        <span v-else>Enregistrez uniquement les informations réellement constatées.</span>
                    </p>
                    <div class="flex flex-wrap justify-end gap-2">
                        <template v-if="activeCareOrder">
                            <Button v-if="form.procedures.length" type="button" size="rg" variant="white-outline" :disabled="form.processing" @click="submit">Enregistrer</Button>
                            <Button type="button" size="rg" variant="primary" :disabled="form.processing || careOrderUnresolvedCount > 0" @click="submitAndComplete(false)"><Icon name="check" /><span class="ms-2">{{ form.processing ? 'Validation…' : completionLabel }}</span></Button>
                        </template>
                        <template v-else-if="capabilities.can_complete && episode.care_completion_mode === 'FINISH' && form.procedures.length">
                            <Button type="button" size="rg" variant="primary" :disabled="form.processing || !allergySafetyReady" @click="submitAndComplete(false)"><Icon name="check" /><span class="ms-2">{{ form.processing ? 'Validation…' : 'Enregistrer l’acte et terminer' }}</span></Button>
                        </template>
                        <template v-else-if="capabilities.can_complete && episode.care_completion_mode === 'MEDICINE'">
                            <Button type="button" size="rg" variant="primary" :disabled="form.processing || !allergySafetyReady" @click="submitAndComplete(false)"><Icon name="check" /><span class="ms-2">{{ form.processing ? 'Validation…' : completionLabel }}</span></Button>
                        </template>
                        <template v-else-if="capabilities.can_complete && episode.care_completion_mode === 'CHOICE' && (form.procedures.length || hasNoProcedureReason)">
                            <Button type="button" size="rg" variant="white-outline" :disabled="form.processing || !allergySafetyReady" @click="submitAndComplete(true)">Enregistrer et orienter</Button>
                            <Button type="button" size="rg" variant="primary" :disabled="form.processing || !allergySafetyReady" @click="submitAndComplete(false)"><Icon name="check" /><span class="ms-2">{{ form.processing ? 'Validation…' : completionLabel }}</span></Button>
                        </template>
                        <Button v-else-if="episode.care_completion_mode === 'CHOICE' && noProcedureSelected" type="button" size="rg" variant="primary" disabled>Indiquez le motif</Button>
                        <Button v-else type="submit" size="rg" variant="primary" :disabled="form.processing || !allergySafetyReady"><Icon name="check" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : 'Enregistrer la fiche' }}</span></Button>
                    </div>
                </div>
                <span v-else />
            </div>
        </form>

        <section v-if="currentStepKey === 'finish' && capabilities.can_complete && !form.isDirty && form.procedures.length === 0 && !noProcedureSelected" class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950 sm:flex-row sm:items-center sm:justify-between">
            <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Terminer sans nouvel acte</h2><p class="mt-1 text-xs text-slate-400">{{ canCompleteWithoutSaving || episode.care_completion_mode === 'MEDICINE' ? completionWarning : 'Enregistrez un acte réalisé avant de terminer les soins.' }}</p></div>
            <div class="flex flex-wrap gap-2">
                <Link v-if="canCompleteWithoutSaving" :href="`/care/orientations/${orientation.uuid}/complete`" method="post" as="button" preserve-scroll><Button size="rg" :variant="episode.care_completion_mode === 'MEDICINE' ? 'primary' : 'white-outline'"><Icon name="check" /><span class="ms-2">{{ completionLabel }}</span></Button></Link>
                <Link v-if="episode.care_completion_mode === 'CHOICE'" :href="`/care/orientations/${orientation.uuid}/complete-and-orient`" method="post" as="button" preserve-scroll><Button size="rg" variant="primary">Vers Médecine</Button></Link>
            </div>
        </section>

        <div
            v-if="cancelTarget"
            class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4"
            role="presentation"
            @click.self="closeCancelDialog"
        >
            <section class="w-full max-w-md rounded-lg border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="cancel-consumables-title">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-300"><Icon class="text-xl" name="cross" /></span>
                    <div>
                        <h2 id="cancel-consumables-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">Annuler la demande {{ cancelTarget.request_number }} ?</h2>
                        <p class="mt-1 text-sm leading-5 text-slate-500">
                            La demande reste dans l’historique avec son motif. La Pharmacie ne la verra plus dans sa file et aucune sortie de stock n’aura lieu.
                        </p>
                    </div>
                </div>

                <div class="mt-5">
                    <label for="cancel_consumable_reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif <span class="text-red-500">*</span></label>
                    <textarea
                        id="cancel_consumable_reason"
                        v-model="cancelReason"
                        rows="3"
                        autofocus
                        placeholder="Ex. déclaré par erreur sur ce passage"
                        class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none transition-all placeholder:text-slate-300 focus:border-red-500 focus:ring-2 focus:ring-red-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-red-950"
                        @input="cancelError = ''"
                    ></textarea>
                    <FormError class="mt-1.5" :message="cancelError" />
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Button size="rg" variant="white-outline" type="button" :disabled="cancelProcessing" @click="closeCancelDialog">Conserver</Button>
                    <Button size="rg" variant="danger" type="button" :disabled="cancelProcessing" @click="confirmCancel">
                        <Icon class="text-lg" name="cross" />
                        <span class="ms-2">{{ cancelProcessing ? 'Annulation…' : 'Confirmer l’annulation' }}</span>
                    </Button>
                </div>
            </section>
        </div>
    </div>
</template>
