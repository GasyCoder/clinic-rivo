<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    Baby,
    CalendarDays,
    Check,
    CircleCheck,
    ClipboardCheck,
    ClipboardList,
    FileText,
    HeartPulse,
    Lock,
    Minus,
    Package,
    Pencil,
    Plus,
    Save,
    Scissors,
    Search,
    ShoppingBasket,
    SquareArrowOutUpRight,
    Stethoscope,
    Trash2,
    TriangleAlert,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClinicalPatientHeader from '@/Components/Clinical/ClinicalPatientHeader.vue';
import ClinicalFieldHints from '@/Components/Clinical/ClinicalFieldHints.vue';
import ClinicalSegmentedChoice from '@/Components/Clinical/ClinicalSegmentedChoice.vue';
import VitalSignsStrip from '@/Components/Clinical/VitalSignsStrip.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import NewbornDossiers from '@/Components/Clinical/NewbornDossiers.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import { useFormDraft } from '@/composables/useFormDraft';
import {
    apgarHints,
    birthWeightHints,
    dilationHints,
    fetalHeartRateHints,
    fundalHeightHints,
    futureDateHints,
    gestationalAgeFromLastPeriod,
    gestationalAgeHints,
    laborTimingHints,
    newbornCountHints,
    parityHints,
    pregnancyFromLastPeriod,
} from '@/utilities/maternityChecks';
import { cn } from '@/lib/cn';

defineOptions({ layout: AppLayout });

const props = defineProps({
    orientation: Object,
    record: Object,
    procedureCatalog: Array,
    capabilities: Object,
    /** Le matériel que ce compte peut déclarer pour cette prise en charge (ADR-142). */
    consumableCatalog: { type: Array, default: () => [] },
    /** Les demandes de matériel déjà transmises à la Pharmacie pour cette prise en charge. */
    consumableRequests: { type: Array, default: () => [] },
    /** Projection partagée des constantes du passage (ADR-054), lecture seule. */
    careRecord: { type: Object, default: null },
    careRecordUrl: { type: String, default: null },
    allergies: { type: Array, default: () => [] },
    /** Ce que la Réception a demandé à la Maternité, avec ce qui est déjà enregistré (ADR-136). */
    plannedProcedures: { type: Array, default: () => [] },
    /** Les sections que ces actes mettent en avant — une aide, jamais un verrou. */
    actProfile: { type: Object, default: () => ({ sections: [], expected_newborns: null }) },
    /** Les repères rappelés sous les champs : servis par le serveur, jamais recopiés ici (ADR-137). */
    maternityReference: { type: Object, required: true },
    /** La saisie non enregistrée de ce compte, restaurée après une actualisation (ADR-136). */
    recordDraft: { type: Object, default: null },
    /** ADR-144 — les bébés de ce dossier qui ont déjà leur dossier patient, par identité de fiche. */
    newbornPatients: { type: Object, default: () => ({}) },
    /** ADR-145 — les dossiers des bébés (état, liens, création), la même projection que le détail du passage. */
    babies: { type: Object, default: null },
});

const patient = computed(() => props.orientation.episode.patient);
const episode = computed(() => props.orientation.episode);
const readOnly = computed(() => ! props.capabilities.can_edit);

/** Cinq au maximum : `UpdateMaternityRecordRequest` refuse au-delà. */
const MAX_NEWBORNS = 5;
/**
 * Les soins du bébé se notent **par nouveau-né** (ADR-139) : avec des jumeaux,
 * l'un peut être sous photothérapie et pas l'autre. Les soins de la mère, eux,
 * restent uniques — il n'y a qu'une mère.
 */
const blankNewborn = () => ({ first_name: '', last_name: '', sex: '', birth_weight_g: '', condition: '', apgar: '', care_notes: '' });

const form = useForm({
    obstetric_context: props.record?.obstetric_context ?? '',
    pregnancy_data: {
        gravidity: props.record?.pregnancy_data?.gravidity ?? '',
        parity: props.record?.pregnancy_data?.parity ?? '',
        last_menstrual_period: props.record?.pregnancy_data?.last_menstrual_period ?? '',
        estimated_due_date: props.record?.pregnancy_data?.estimated_due_date ?? '',
        risk_factors: props.record?.pregnancy_data?.risk_factors ?? '',
    },
    prenatal_data: {
        gestational_age_weeks: props.record?.prenatal_data?.gestational_age_weeks ?? '',
        fundal_height_cm: props.record?.prenatal_data?.fundal_height_cm ?? '',
        fetal_heart_rate: props.record?.prenatal_data?.fetal_heart_rate ?? '',
        notes: props.record?.prenatal_data?.notes ?? '',
    },
    labor_data: {
        started_at: props.record?.labor_data?.started_at ?? '',
        membranes_status: props.record?.labor_data?.membranes_status ?? 'UNKNOWN',
        cervical_dilation_cm: props.record?.labor_data?.cervical_dilation_cm ?? '',
        contractions: props.record?.labor_data?.contractions ?? '',
        surveillance_notes: props.record?.labor_data?.surveillance_notes ?? '',
    },
    delivery_data: {
        occurred_at: props.record?.delivery_data?.occurred_at ?? '',
        mode: props.record?.delivery_data?.mode ?? '',
        placenta_status: props.record?.delivery_data?.placenta_status ?? '',
        complications: props.record?.delivery_data?.complications ?? '',
    },
    newborn_data: {
        // Un nouveau-né enregistré avant les soins par bébé n'a pas ce champ : on le lui donne vide.
        newborns: props.record?.newborn_data?.newborns?.length
            ? props.record.newborn_data.newborns.map((newborn) => ({ ...blankNewborn(), ...newborn }))
            // Un accouchement gémellaire annonce deux enfants : deux fiches
            // vides s'ouvrent, jamais deux fiches remplies (ADR-136).
            : Array.from({ length: Math.min(props.actProfile.expected_newborns ?? 1, MAX_NEWBORNS) }, () => blankNewborn()),
    },
    maternal_care_notes: props.record?.maternal_care_notes ?? '',
    baby_care_notes: props.record?.baby_care_notes ?? '',
    observations: props.record?.observations ?? '',
    transmission_notes: props.record?.transmission_notes ?? '',
});

// Une note « Soins bébé » d'avant la saisie par nouveau-né reste visible tant qu'elle existe.
const legacyBabyCare = Boolean(String(props.record?.baby_care_notes ?? '').trim());

// Le panier d'actes (ADR-138) : chaque ligne est un acte, sa quantité et sa précision.
// Le matériel utilisé part dans le même geste que les actes (ADR-142) : un DIU et son
// geste sont un seul acte pour la sage-femme, et un second formulaire ferait perdre le
// matériel dès qu'elle valide sans l'avoir envoyé.
const basketForm = useForm({ lines: [], consumables: [], consumable_notes: '' });
const cesareanForm = useForm({ type: 'SIMPLE', indication: '' });
const completeForm = useForm({ medicine_note: '' });

/**
 * L'issue de la prise en charge (ADR-135) — choisie par la sage-femme, jamais
 * déduite. « Terminer » reste le geste par défaut : c'est ce qu'il faisait
 * avant, et orienter vers Médecine est une décision, pas une formalité.
 */
const outcome = ref('end');
const toMedicine = computed(() => outcome.value === 'medicine');
const OUTCOMES = [
    { value: 'end', label: 'Terminer', description: 'La Maternité a fini' },
    { value: 'medicine', label: 'Terminer et orienter vers Médecine', description: 'Un médecin revoit la patiente', tone: 'warning' },
];

// ── Actes demandés à la Réception ────────────────────────────────────────
// La sage-femme n'a pas à retrouver dans une liste ce que la Réception vient
// de lui envoyer : chaque acte demandé s'enregistre en un clic (ADR-136).
const catalogByUuid = computed(() => Object.fromEntries((props.procedureCatalog ?? []).map((item) => [item.uuid, item])));
const canRecordProcedures = computed(() => props.capabilities.can_procedures && props.orientation.status === 'IN_PROGRESS');
const recordingPlanned = ref(null);

const recordPlanned = (act) => {
    if (recordingPlanned.value) return;

    // « Autres » et tout acte qui exige une précision passent par le panier,
    // où la précision se saisit avant l'enregistrement.
    if (catalogByUuid.value[act.uuid]?.requires_note) {
        addToBasket(act.uuid, act.quantity);
        activeSection.value = 'procedures';

        return;
    }

    recordingPlanned.value = act.uuid;
    router.post(
        `/maternity/orientations/${props.orientation.uuid}/procedures`,
        { catalog_item_uuid: act.uuid, quantity: act.quantity || 1, notes: '' },
        { preserveScroll: true, onFinish: () => { recordingPlanned.value = null; } },
    );
};

const pendingPlanned = computed(() => props.plannedProcedures.filter((act) => ! act.done));

// ── Panier d'actes (ADR-138) ─────────────────────────────────────────────
// On ajoute autant d'actes qu'il faut, on règle quantité et précision ligne
// par ligne, puis on enregistre le tout d'un geste : tout, ou rien.
const catalogFilter = ref('');
const plain = (text) => String(text ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const visibleCatalog = computed(() => {
    const term = plain(catalogFilter.value).trim();

    return (props.procedureCatalog ?? []).filter((item) => term === '' || plain(item.name).includes(term));
});
const basketIndex = (uuid) => basketForm.lines.findIndex((line) => line.catalog_item_uuid === uuid);
const inBasket = (uuid) => basketIndex(uuid) !== -1;

/** Un acte n'entre qu'une fois dans le panier : la quantité porte les répétitions. */
function addToBasket(uuid, quantity = 1) {
    if (! catalogByUuid.value[uuid] || inBasket(uuid)) return;

    basketForm.lines.push({ catalog_item_uuid: uuid, quantity: quantity || 1, notes: '' });
    suggestConsumablesFor(uuid);
}

// ── Matériel habituel de l'acte (ADR-142) ─────────────────────────────────
// Une suggestion de saisie, jamais une règle : la sage-femme confirme, corrige ou retire.
// Une quantité qu'elle a corrigée est une déclaration réelle, jamais réécrite.
const consumableByUuid = computed(() => Object.fromEntries((props.consumableCatalog ?? []).map((item) => [item.medicine_uuid, item])));
const canRequestConsumables = computed(() => Boolean(props.capabilities.can_request_consumables));

function suggestConsumablesFor(actUuid) {
    if (! canRequestConsumables.value) return;

    for (const suggestion of catalogByUuid.value[actUuid]?.default_consumables ?? []) {
        const existing = basketForm.consumables.find((line) => line.medicine_uuid === suggestion.medicine_uuid);

        if (existing) {
            if (! existing.suggested_by.includes(actUuid)) existing.suggested_by.push(actUuid);
            continue;
        }

        if (! consumableByUuid.value[suggestion.medicine_uuid]) continue;

        basketForm.consumables.push({
            medicine_uuid: suggestion.medicine_uuid,
            quantity: suggestion.quantity || 1,
            suggested_by: [actUuid],
            touched: false,
        });
    }
}

/** Retirer un acte retire seulement le matériel qu'il avait apporté et que personne n'a corrigé. */
function dropSuggestedConsumablesOf(actUuid) {
    basketForm.consumables = basketForm.consumables
        .map((line) => ({ ...line, suggested_by: (line.suggested_by ?? []).filter((uuid) => uuid !== actUuid) }))
        .filter((line) => line.touched || line.suggested_by.length > 0);
}

const consumableSearch = ref('');
const consumableOptions = computed(() => {
    const chosen = new Set(basketForm.consumables.map((line) => line.medicine_uuid));
    const term = plain(consumableSearch.value).trim();

    return (props.consumableCatalog ?? [])
        .filter((item) => ! chosen.has(item.medicine_uuid) && (term === '' || plain(`${item.name} ${item.code}`).includes(term)))
        .slice(0, 8);
});
const addConsumable = (item) => {
    basketForm.consumables.push({ medicine_uuid: item.medicine_uuid, quantity: 1, suggested_by: [], touched: true });
    consumableSearch.value = '';
};
const removeConsumable = (uuid) => {
    basketForm.consumables = basketForm.consumables.filter((line) => line.medicine_uuid !== uuid);
};
const changeConsumableQuantity = (line, delta) => {
    line.quantity = Math.max(1, Math.round((Number(line.quantity) || 0) + delta));
    line.touched = true;
};
const consumableItem = (line) => consumableByUuid.value[line.medicine_uuid];
/** Au-delà du stock connu : la Pharmacie refusera la sortie, autant le dire avant l'envoi. */
const consumableShort = (line) => {
    const item = consumableItem(line);

    return Boolean(item) && Number(line.quantity) > item.available_quantity;
};

const toggleInBasket = (uuid) => {
    const index = basketIndex(uuid);

    if (index === -1) addToBasket(uuid);
    else removeFromBasket(index);
};
const removeFromBasket = (index) => {
    const [removed] = basketForm.lines.splice(index, 1);
    if (removed) dropSuggestedConsumablesOf(removed.catalog_item_uuid);
};
const changeQuantity = (line, delta) => {
    line.quantity = Math.max(1, Math.round((Number(line.quantity) || 0) + delta));
};
const lineItem = (line) => catalogByUuid.value[line.catalog_item_uuid];
/** « Autres » ne se comprend pas sans sa description. */
const missingNote = (line) => Boolean(lineItem(line)?.requires_note) && String(line.notes ?? '').trim() === '';
const basketBlockers = computed(() => basketForm.lines.filter(missingNote).length);
const basketHasContent = computed(() => basketForm.lines.length > 0 || basketForm.consumables.length > 0);
const basketReady = computed(() => basketHasContent.value
    && basketBlockers.value === 0
    && basketForm.lines.every((line) => Number(line.quantity) > 0)
    && basketForm.consumables.every((line) => Number(line.quantity) >= 1));
const basketUnits = computed(() => basketForm.lines.reduce((total, line) => total + (Number(line.quantity) || 0), 0));
const plannedNotInBasket = computed(() => pendingPlanned.value.filter((act) => ! inBasket(act.uuid)));
const addPlannedToBasket = () => plannedNotInBasket.value.forEach((act) => addToBasket(act.uuid, act.quantity));
/** Les erreurs propres à une ligne s'affichent sur elle ; le reste au pied du panier. */
const basketError = computed(() => Object.entries(basketForm.errors).find(([key]) => ! key.startsWith('procedures.'))?.[1] ?? null);
const basketSaveLabel = computed(() => {
    const acts = basketForm.lines.length;

    if (acts === 0) return 'Transmettre le matériel';

    return `Enregistrer ${acts} acte${acts > 1 ? 's' : ''}${basketForm.consumables.length ? ' et transmettre le matériel' : ''}`;
});
const clearBasket = () => { basketForm.lines = []; basketForm.consumables = []; basketForm.consumable_notes = ''; };

const confirmingCesarean = ref(false);
const confirmingComplete = ref(false);

const hasValue = (value) => {
    if (value === null || value === undefined || value === '') return false;
    if (Array.isArray(value)) return value.some(hasValue);
    if (typeof value === 'object') return Object.values(value).some(hasValue);

    return true;
};

/**
 * Un point vert par onglet déjà renseigné.
 *
 * Six sections dont on ne voyait que celle ouverte : il fallait toutes les
 * parcourir pour savoir où le dossier en était, et quoi reprendre après une
 * interruption au chevet de la patiente.
 */
const sectionFilled = {
    context: () => hasValue(form.obstetric_context) || hasValue(form.pregnancy_data),
    prenatal: () => hasValue(form.prenatal_data),
    labor: () => hasValue({ ...form.labor_data, membranes_status: form.labor_data.membranes_status === 'UNKNOWN' ? '' : form.labor_data.membranes_status }),
    delivery: () => hasValue(form.delivery_data),
    newborn: () => hasValue(form.newborn_data) || hasValue(form.maternal_care_notes) || hasValue(form.baby_care_notes),
    procedures: () => Boolean(props.record?.procedures?.length) || hasValue(form.observations) || hasValue(form.transmission_notes),
};

const sections = computed(() => [
    { key: 'context', label: 'Contexte', icon: FileText, visible: true },
    { key: 'prenatal', label: 'Grossesse & prénatal', icon: CalendarDays, visible: props.capabilities.can_prenatal || hasValue(props.record?.prenatal_data) },
    { key: 'labor', label: 'Travail', icon: Activity, visible: props.capabilities.can_labor || hasValue(props.record?.labor_data) },
    { key: 'delivery', label: 'Accouchement', icon: HeartPulse, visible: props.capabilities.can_delivery || hasValue(props.record?.delivery_data) },
    { key: 'newborn', label: 'Nouveau-né', icon: Baby, visible: props.capabilities.can_newborn || hasValue(props.record?.newborn_data) },
    { key: 'procedures', label: 'Actes & transmission', icon: ClipboardList, visible: true },
].filter((section) => section.visible).map((section) => ({
    ...section,
    filled: sectionFilled[section.key](),
    // Mise en avant par les actes demandés à la Réception : une aide, jamais
    // un verrou — toute section que le compte peut écrire reste atteignable.
    suggested: props.actProfile.sections.includes(section.key),
})));

/**
 * On ouvre sur ce que les actes demandés attendent de la sage-femme : la
 * première section mise en avant qu'elle n'a pas encore renseignée. Sans acte
 * demandé, ou une fois tout renseigné, le dossier s'ouvre comme avant.
 */
const firstSectionToFill = () => sections.value.find((section) => section.suggested && ! section.filled)?.key ?? 'context';
const activeSection = ref(firstSectionToFill());
const currentSection = computed(() => sections.value.find((section) => section.key === activeSection.value));

const membranesOptions = [
    { value: 'UNKNOWN', label: 'Non précisé' },
    { value: 'INTACT', label: 'Intactes' },
    { value: 'RUPTURED', label: 'Rompues' },
];
const deliveryModeOptions = [
    { value: '', label: 'Non renseignée' },
    { value: 'VAGINAL', label: 'Voie basse' },
    { value: 'INSTRUMENTAL', label: 'Instrumental' },
    { value: 'CESAREAN', label: 'Césarienne réalisée en Chirurgie' },
];
const newbornSexOptions = [
    { value: '', label: 'Non renseigné' },
    { value: 'F', label: 'Féminin' },
    { value: 'M', label: 'Masculin' },
    { value: 'UNDETERMINED', label: 'Indéterminé' },
];
const cesareanTypeOptions = [
    { value: 'SIMPLE', label: 'Simple' },
    { value: 'TWIN', label: 'Gémellaire' },
];
/**
 * Un bloc que le compte n'a pas le droit d'écrire ne part pas.
 *
 * `UpdateMaternityRecordRequest` les déclare `prohibited` sans la permission
 * correspondante, et « prohibited » refuse un tableau non vide — or le
 * formulaire envoyait toujours les cinq blocs avec leurs valeurs par défaut.
 * Une sage-femme qui avait `maternity.update` sans `maternity.labor.manage`
 * ne pouvait donc rien enregistrer du tout, sur une erreur illisible
 * (« Le champ labor data est interdit »). Le serveur garde sa règle : c'est
 * lui la protection, ceci n'envoie simplement plus ce qu'il refuse.
 */
// ── Repères sous les champs (ADR-137) ────────────────────────────────────
// Une aide au dépistage : un message dit ce qui mérite un second regard, rien
// n'empêche d'enregistrer. Les seuils viennent du serveur ; ici on ne fait que
// lire la valeur saisie.
const reference = computed(() => props.maternityReference);
const lastPeriod = computed(() => pregnancyFromLastPeriod(
    form.pregnancy_data.last_menstrual_period,
    form.pregnancy_data.estimated_due_date,
    reference.value,
));
const contextHints = computed(() => [
    ...parityHints(form.pregnancy_data.gravidity, form.pregnancy_data.parity),
    ...(lastPeriod.value?.hints ?? []),
]);
const gestationalHints = computed(() => [
    ...gestationalAgeHints(form.prenatal_data.gestational_age_weeks, reference.value),
    ...gestationalAgeFromLastPeriod(form.pregnancy_data.last_menstrual_period, form.prenatal_data.gestational_age_weeks, reference.value),
]);
const fundalHints = computed(() => fundalHeightHints(
    form.prenatal_data.fundal_height_cm,
    form.prenatal_data.gestational_age_weeks,
    reference.value,
));
const fetalHeartHints = computed(() => fetalHeartRateHints(form.prenatal_data.fetal_heart_rate, reference.value));
const dilationHint = computed(() => dilationHints(form.labor_data.cervical_dilation_cm, reference.value));
const laborStartHints = computed(() => [
    ...futureDateHints(form.labor_data.started_at, 'Début du travail'),
    ...laborTimingHints(form.labor_data.started_at, form.delivery_data.occurred_at),
]);
const deliveryDateHints = computed(() => futureDateHints(form.delivery_data.occurred_at, 'Accouchement'));
const newbornHints = (newborn) => ({
    weight: birthWeightHints(newborn.birth_weight_g, reference.value),
    apgar: apgarHints(newborn.apgar, reference.value),
});
const newbornCountHint = computed(() => newbornCountHints(form.newborn_data.newborns.length, props.actProfile.expected_newborns));

/** Reprend une valeur calculée dans son champ — jamais d'office, jamais par-dessus une saisie. */
const applyDueDate = (hint) => { form.pregnancy_data.estimated_due_date = hint.action.value; };
const applyGestationalAge = (hint) => { form.prenatal_data.gestational_age_weeks = hint.action.value; };

const save = () => form.transform((data) => {
    const payload = { ...data };

    if (! props.capabilities.can_prenatal) delete payload.prenatal_data;
    if (! props.capabilities.can_labor) delete payload.labor_data;
    if (! props.capabilities.can_delivery) delete payload.delivery_data;
    if (! props.capabilities.can_newborn) {
        delete payload.newborn_data;
        delete payload.baby_care_notes;
    }

    return payload;
}).put(`/maternity/orientations/${props.orientation.uuid}/record`, {
    preserveScroll: true,
    onSuccess: () => draft.markSaved(),
});

// ── Dossier patient d'un nouveau-né (ADR-144, ADR-145) ────────────────────
// La création vit dans `NewbornDossiers`, hors du formulaire verrouillé. Ici ne reste que l'identité du bébé.
const newbornPatient = (newborn) => (newborn.uuid ? props.newbornPatients[newborn.uuid] : null);
// Le serveur donne à un bébé son identité (`uuid`) au moment où un patient en dépend : le formulaire,
// lui, garde les données avec lesquelles il a été monté. Sans cette reprise, la fiche renverrait le
// dossier sans l'identité du bébé — et l'enregistrement serait refusé, ou le badge resterait muet.
// Seulement quand rien n'est en cours de saisie : jamais par-dessus ce que la sage-femme est en train de taper.
watch(() => props.record?.newborn_data?.newborns, (saved) => {
    if (! saved || form.isDirty) return;

    saved.forEach((entry, index) => {
        const mine = form.newborn_data.newborns[index];

        if (! mine) return;
        if (entry.uuid && ! mine.uuid) mine.uuid = entry.uuid;
        // Le sexe choisi à la création du dossier est écrit dans la fiche : l'écran le reprend, sinon un
        // enregistrement ultérieur l'effacerait de la fiche alors que le dossier patient le porte.
        if (entry.sex && mine.sex !== entry.sex) mine.sex = entry.sex;
    });
    form.defaults();
}, { deep: true });

const addNewborn = () => {
    if (form.newborn_data.newborns.length >= MAX_NEWBORNS) return;
    form.newborn_data.newborns.push(blankNewborn());
};

const saveBasket = () => basketForm
    .transform((data) => ({
        // Chaque partie n'est envoyée que si elle existe : le matériel peut partir seul.
        ...(data.lines.length ? {
            procedures: data.lines.map((line) => ({
                catalog_item_uuid: line.catalog_item_uuid,
                quantity: line.quantity,
                notes: String(line.notes ?? '').trim() || null,
            })),
        } : {}),
        ...(data.consumables.length ? {
            consumables: data.consumables.map((line) => ({ medicine_uuid: line.medicine_uuid, quantity: Number(line.quantity) })),
            consumable_notes: String(data.consumable_notes ?? '').trim() || null,
        } : {}),
    }))
    .post(`/maternity/orientations/${props.orientation.uuid}/procedures/batch`, {
        preserveScroll: true,
        // Vidé directement : après un succès, `reset()` reviendrait au contenu
        // que le formulaire vient de prendre pour référence.
        onSuccess: clearBasket,
    });

// ── Corriger ou retirer un acte enregistré (ADR-140) ─────────────────────
// Les gestes permis viennent du serveur (`can_modify`) : l'acte enregistré par
// un médecin reste intact pour le personnel Maternité. Retirer ne détruit
// rien — l'acte quitte la liste et l'audit garde la trace.
const editingProcedure = ref(null);
const editForm = useForm({ quantity: 1, notes: '' });
const removingProcedure = ref(null);
// Annuler une demande de matériel : possible tant que la Pharmacie n'a rien sorti du stock (ADR-072).
const cancellingRequest = ref(null);
const cancelForm = useForm({ reason: '' });
const confirmCancelRequest = () => cancelForm.post(
    `/maternity/orientations/${props.orientation.uuid}/consumables/${cancellingRequest.value.uuid}/cancel`,
    { preserveScroll: true, onSuccess: () => { cancellingRequest.value = null; } },
);
const removeForm = useForm({});

const startEdit = (procedure) => {
    editForm.clearErrors();
    editForm.quantity = Number(procedure.quantity);
    editForm.notes = procedure.notes ?? '';
    editingProcedure.value = procedure.uuid;
};
const cancelEdit = () => {
    editForm.clearErrors();
    editingProcedure.value = null;
};
const saveEdit = (procedure) => editForm.put(
    `/maternity/orientations/${props.orientation.uuid}/procedures/${procedure.uuid}`,
    { preserveScroll: true, onSuccess: () => { editingProcedure.value = null; } },
);
const editNeedsNote = (procedure) => Boolean(catalogByUuid.value[procedure.catalog_item_uuid]?.requires_note);
const confirmRemove = () => removeForm.delete(
    `/maternity/orientations/${props.orientation.uuid}/procedures/${removingProcedure.value.uuid}`,
    { preserveScroll: true, onSuccess: () => { removingProcedure.value = null; } },
);
const performedOn = (procedure) => (procedure.performed_at ? new Date(procedure.performed_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '');

// ── Saisie en cours ───────────────────────────────────────────────────────
// Ce qui est tapé survit à une actualisation : seul un enregistrement réel ou
// « Effacer le brouillon » le supprime. Côté serveur et propre à ce compte :
// un poste partagé ne doit jamais donner à une sage-femme la saisie non
// validée d'une collègue (ADR-073, ADR-136).
const draft = useFormDraft({
    endpoint: `/maternity/orientations/${props.orientation.uuid}/draft`,
    initial: props.recordDraft,
    enabled: Boolean(props.capabilities.can_edit),
    forms: { record: form, basket: basketForm, cesarean: cesareanForm },
});

// Un panier restauré peut porter un acte retiré du catalogue depuis : il ne
// pourrait jamais partir, on ne le montre pas.
basketForm.lines = basketForm.lines.filter((line) => catalogByUuid.value[line.catalog_item_uuid]);
// Idem pour le matériel : un produit devenu inéligible depuis ne pourrait jamais partir.
basketForm.consumables = (basketForm.consumables ?? []).filter((line) => consumableByUuid.value[line.medicine_uuid]);

const discardDraft = () => {
    // Suspendre avant la requête : sinon le minuteur en attente recréerait le
    // brouillon qu'on efface.
    draft.suspend();
    router.delete(`/maternity/orientations/${props.orientation.uuid}/draft`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            clearBasket();
            cesareanForm.reset();
            draft.markSaved();
        },
        onFinish: () => draft.resume(),
    });
};

const requestCesarean = () => cesareanForm.post(
    `/maternity/orientations/${props.orientation.uuid}/cesarean`,
    {
        preserveScroll: true,
        onSuccess: () => { cesareanForm.reset('indication'); confirmingCesarean.value = false; },
    },
);

const completeCare = () => completeForm
    .transform((data) => ({
        orient_to_medicine: toMedicine.value,
        // La note n'a de sens que pour le médecin : sans orientation, elle ne part pas.
        medicine_note: toMedicine.value ? (data.medicine_note?.trim() || null) : null,
    }))
    .post(`/maternity/orientations/${props.orientation.uuid}/complete`, {
        preserveScroll: true,
        onSuccess: () => { confirmingComplete.value = false; },
    });

const firstError = (bag) => Object.values(bag)[0];

/**
 * Les constantes du passage viennent des Soins, relevées une seule fois et
 * lues ici comme en Médecine et au bloc (ADR-054). Maternité ne les ressaisit
 * pas : une seconde version d'une mesure que personne n'a prise deux fois
 * finirait par contredire la première (ADR-077).
 */
const careBloodPressure = computed(() => {
    const systolic = props.careRecord?.blood_pressure_systolic;
    const diastolic = props.careRecord?.blood_pressure_diastolic;

    return systolic && diastolic ? `${systolic}/${diastolic}` : null;
});
</script>

<template>
    <Head title="Dossier Maternité" />

    <div class="w-full space-y-4">
        <ClinicalPatientHeader
            :patient="patient"
            :episode="episode"
            :reason="orientation.reason"
            back-href="/maternity"
            back-label="File Maternité"
        />

        <!-- D'abord les valeurs relevées à l'arrivée, puis ce qu'elles
             impliquent : même bandeau qu'en consultation Médecine. -->
        <VitalSignsStrip
            v-if="careRecord"
            :care-record="careRecord"
            :blood-pressure="careBloodPressure"
            :allergies="allergies"
            :recorded-at="careRecord.updated_at ?? careRecord.created_at"
        />

        <!-- Sans passage par les Soins, il n'y a aucune constante à montrer :
             on le dit plutôt que d'afficher des tirets qui se liraient
             « normal ». -->
        <Card v-else class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
            <p class="text-xs text-muted-foreground">Aucune constante relevée pour ce passage : la patiente n’est pas passée par les Soins, ou votre compte ne peut pas les consulter.</p>
            <Button v-if="careRecordUrl" :as="Link" :href="careRecordUrl" size="sm" variant="outline">
                <SquareArrowOutUpRight class="h-4 w-4" />Ouvrir la fiche de soins
            </Button>
        </Card>

        <div v-if="careRecord && careRecordUrl" class="flex justify-end">
            <!-- Corriger une constante se fait sur la fiche qui la porte, avec
                 ses propres règles (ADR-092) — jamais dans un second
                 formulaire qui finirait par diverger. -->
            <Button :as="Link" :href="careRecordUrl" size="sm" variant="ghost">
                <SquareArrowOutUpRight class="h-4 w-4" />Ouvrir la fiche de soins complète
            </Button>
        </div>

        <!-- Lecture seule : le dossier s'affichait éditable et seul le bouton
             « Enregistrer » disparaissait, si bien qu'on pouvait saisir un
             relevé entier avant de découvrir qu'il n'irait nulle part. -->
        <Card v-if="readOnly" class="flex items-start gap-3 border-amber-200 bg-amber-50/60 p-4 dark:border-amber-900 dark:bg-amber-950/20">
            <Lock class="mt-0.5 h-4.5 w-4.5 shrink-0 text-amber-600 dark:text-amber-300" />
            <div>
                <p class="text-sm font-bold text-amber-900 dark:text-amber-100">Dossier en lecture seule</p>
                <p class="mt-0.5 text-xs leading-5 text-amber-800 dark:text-amber-200">
                    <template v-if="orientation.status !== 'IN_PROGRESS'">La prise en charge Maternité de ce passage est {{ orientation.status_label.toLowerCase() }} : le dossier n'est plus modifiable.</template>
                    <template v-else>Votre compte ne dispose pas du droit de modifier ce dossier.</template>
                </p>
            </div>
        </Card>

        <!-- Brouillon : ce qui est tapé survit à une actualisation, mais n'est pas
             encore versé au dossier — la sage-femme doit le voir, et pouvoir
             l'effacer (ADR-136). -->
        <Card
            v-if="draft.restored.value || draft.savedAt.value"
            class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-xs"
        >
            <p class="flex items-center gap-2 text-muted-foreground">
                <Save class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span>
                    <strong class="font-semibold text-foreground">{{ draft.restored.value ? 'Brouillon restauré' : 'Brouillon enregistré' }}</strong>
                    · non versé au dossier
                </span>
            </p>
            <Button type="button" size="sm" variant="ghost" class="h-7 px-2 text-xs" @click="discardDraft">Effacer le brouillon</Button>
        </Card>

        <!-- Ce que la Réception a demandé à la Maternité : un clic par acte,
             sans le chercher dans la liste du catalogue (ADR-136). -->
        <Card v-if="plannedProcedures.length" class="p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-muted-foreground">
                    <ClipboardCheck class="h-4 w-4" aria-hidden="true" />Demandé à la Réception
                </p>
                <span class="text-xs text-muted-foreground">
                    {{ pendingPlanned.length === 0 ? 'Tout est enregistré' : `${pendingPlanned.length} acte${pendingPlanned.length > 1 ? 's' : ''} à enregistrer` }}
                </span>
            </div>
            <ul class="mt-3 flex flex-wrap gap-2">
                <li
                    v-for="act in plannedProcedures"
                    :key="act.uuid"
                    :class="cn(
                        'flex items-center gap-2 rounded-lg border px-3 py-2 text-sm',
                        act.done ? 'border-emerald-200 bg-emerald-50/60 dark:border-emerald-900 dark:bg-emerald-950/20' : 'border-border bg-muted/40',
                    )"
                >
                    <CircleCheck v-if="act.done" class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" aria-label="Acte enregistré" />
                    <span class="font-semibold text-foreground">{{ act.name }}</span>
                    <span v-if="act.quantity !== 1" class="text-xs text-muted-foreground">× {{ act.quantity }}</span>
                    <Button
                        v-if="! act.done && canRecordProcedures"
                        type="button"
                        size="sm"
                        variant="primary"
                        class="h-7 px-2.5 text-xs"
                        :disabled="recordingPlanned !== null"
                        @click="recordPlanned(act)"
                    >
                        <Check class="h-3.5 w-3.5" />{{ recordingPlanned === act.uuid ? 'Enregistrement…' : (catalogByUuid[act.uuid]?.requires_note ? 'Préciser' : 'Enregistrer') }}
                    </Button>
                </li>
            </ul>
        </Card>

        <!-- Une pastille par section déjà renseignée : on voit d'un coup
             d'œil où le dossier en est, sans ouvrir les six onglets. -->
        <Card class="overflow-x-auto p-1.5">
            <div class="flex min-w-max gap-1">
                <button
                    v-for="section in sections"
                    :key="section.key"
                    type="button"
                    :class="cn(
                        'flex h-10 items-center gap-2 rounded-lg px-4 text-xs font-bold transition-colors',
                        activeSection === section.key ? 'bg-accent text-foreground shadow-sm' : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
                    )"
                    :aria-current="activeSection === section.key ? 'page' : undefined"
                    @click="activeSection = section.key"
                >
                    <component :is="section.icon" class="h-4 w-4" />
                    {{ section.label }}
                    <CircleCheck v-if="section.filled" class="h-3.5 w-3.5 text-emerald-500" aria-label="Section renseignée" />
                    <!-- Attendue par les actes demandés, pas encore renseignée. -->
                    <span
                        v-else-if="section.suggested"
                        class="h-2 w-2 rounded-full bg-amber-500"
                        title="Attendue par les actes demandés"
                        role="img"
                        aria-label="Section attendue par les actes demandés"
                    />
                </button>
            </div>
        </Card>

        <form @submit.prevent="save">
            <Card class="overflow-hidden">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-5 py-4">
                    <div>
                        <h2 class="text-sm font-bold text-foreground">{{ currentSection?.label }}</h2>
                        <p class="mt-1 text-xs text-muted-foreground">Les données restent rattachées au passage {{ episode.episode_number }}.</p>
                    </div>
                    <Badge v-if="currentSection?.filled" variant="success">Renseignée</Badge>
                </header>

                <!-- ADR-145, ADR-146 : les bébés de cette mère, chacun avec son dossier médical — un seul endroit.
                     Hors du fieldset : un dossier terminé est en lecture seule, mais on doit pouvoir ouvrir le dossier d'un bébé. -->
                <div v-if="activeSection === 'newborn' && babies" class="p-5 pb-0">
                    <NewbornDossiers :babies="babies" :show-maternity-link="false" />
                </div>

                <fieldset class="min-w-0 space-y-5 p-5" :disabled="readOnly">
                    <template v-if="activeSection === 'context'">
                        <FormField as="div" label="Motif et contexte obstétrical" :error="form.errors.obstetric_context">
                            <Textarea v-model="form.obstetric_context" :rows="6" placeholder="Motif, antécédents obstétricaux et contexte clinique utile…" />
                        </FormField>
                        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <FormField label="Gestité" :error="form.errors['pregnancy_data.gravidity']">
                                <Input v-model="form.pregnancy_data.gravidity" type="number" min="0" max="30" />
                            </FormField>
                            <FormField label="Parité" :error="form.errors['pregnancy_data.parity']">
                                <Input v-model="form.pregnancy_data.parity" type="number" min="0" max="30" />
                            </FormField>
                            <FormField label="Dernières règles" :error="form.errors['pregnancy_data.last_menstrual_period']">
                                <Input v-model="form.pregnancy_data.last_menstrual_period" type="date" />
                            </FormField>
                            <FormField label="Terme estimé" :error="form.errors['pregnancy_data.estimated_due_date']">
                                <Input v-model="form.pregnancy_data.estimated_due_date" type="date" />
                            </FormField>
                        </div>
                        <ClinicalFieldHints :hints="contextHints" label="Repères sur la grossesse" @apply="applyDueDate" />
                        <FormField as="div" label="Facteurs de risque" :error="form.errors['pregnancy_data.risk_factors']">
                            <Textarea v-model="form.pregnancy_data.risk_factors" :rows="3" />
                        </FormField>
                    </template>

                    <template v-else-if="activeSection === 'prenatal'">
                        <div class="grid gap-4 md:grid-cols-3">
                            <FormField label="Terme (semaines)" :error="form.errors['prenatal_data.gestational_age_weeks']">
                                <Input v-model="form.prenatal_data.gestational_age_weeks" type="number" min="0" :max="reference.gestational_age.max" />
                                <ClinicalFieldHints :hints="gestationalHints" label="Repères sur le terme" @apply="applyGestationalAge" />
                            </FormField>
                            <FormField label="Hauteur utérine (cm)" :error="form.errors['prenatal_data.fundal_height_cm']">
                                <Input v-model="form.prenatal_data.fundal_height_cm" type="number" min="0" :max="reference.fundal_height.max" step="0.1" />
                                <ClinicalFieldHints :hints="fundalHints" label="Repères sur la hauteur utérine" />
                            </FormField>
                            <FormField label="Rythme cardiaque fœtal (bpm)" :error="form.errors['prenatal_data.fetal_heart_rate']">
                                <Input v-model="form.prenatal_data.fetal_heart_rate" type="number" :min="reference.fetal_heart_rate.min" :max="reference.fetal_heart_rate.max" />
                                <ClinicalFieldHints :hints="fetalHeartHints" label="Repères sur le rythme cardiaque fœtal" />
                            </FormField>
                        </div>
                        <FormField as="div" label="Constatations prénatales" :error="form.errors['prenatal_data.notes']">
                            <Textarea v-model="form.prenatal_data.notes" :rows="7" />
                        </FormField>
                    </template>

                    <template v-else-if="activeSection === 'labor'">
                        <div class="grid gap-4 md:grid-cols-3">
                            <FormField label="Début du travail" :error="form.errors['labor_data.started_at']">
                                <Input v-model="form.labor_data.started_at" type="datetime-local" />
                                <ClinicalFieldHints :hints="laborStartHints" label="Repères sur le début du travail" />
                            </FormField>
                            <FormField label="Membranes" :error="form.errors['labor_data.membranes_status']">
                                <Select v-model="form.labor_data.membranes_status" class="h-10 w-full min-w-0" :options="membranesOptions" />
                            </FormField>
                            <FormField label="Dilatation (cm)" :error="form.errors['labor_data.cervical_dilation_cm']">
                                <Input v-model="form.labor_data.cervical_dilation_cm" type="number" min="0" :max="reference.dilation.max" step="0.1" />
                                <ClinicalFieldHints :hints="dilationHint" label="Repères sur la dilatation" />
                            </FormField>
                        </div>
                        <FormField as="div" label="Contractions" :error="form.errors['labor_data.contractions']">
                            <Textarea v-model="form.labor_data.contractions" :rows="3" />
                        </FormField>
                        <FormField as="div" label="Surveillance du travail" :error="form.errors['labor_data.surveillance_notes']">
                            <Textarea v-model="form.labor_data.surveillance_notes" :rows="6" />
                        </FormField>
                    </template>

                    <template v-else-if="activeSection === 'delivery'">
                        <div class="grid gap-4 md:grid-cols-2">
                            <FormField label="Date et heure" :error="form.errors['delivery_data.occurred_at']">
                                <Input v-model="form.delivery_data.occurred_at" type="datetime-local" />
                                <ClinicalFieldHints :hints="deliveryDateHints" label="Repères sur la date de l’accouchement" />
                            </FormField>
                            <FormField label="Voie d’accouchement" :error="form.errors['delivery_data.mode']">
                                <Select v-model="form.delivery_data.mode" class="h-10 w-full min-w-0" :options="deliveryModeOptions" />
                            </FormField>
                        </div>
                        <FormField as="div" label="Placenta" :error="form.errors['delivery_data.placenta_status']">
                            <Textarea v-model="form.delivery_data.placenta_status" :rows="3" />
                        </FormField>
                        <FormField as="div" label="Complications" :error="form.errors['delivery_data.complications']">
                            <Textarea v-model="form.delivery_data.complications" :rows="4" />
                        </FormField>

                        <!-- Décider la césarienne crée une demande Chirurgie sur
                             ce même passage (ADR-067) : ce n'est pas un champ du
                             dossier, d'où le bloc séparé et la confirmation. -->
                        <div v-if="capabilities.can_delivery && orientation.status === 'IN_PROGRESS'" class="rounded-xl border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-900 dark:bg-amber-950/20">
                            <div class="flex items-start gap-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300"><Scissors class="h-4.5 w-4.5" /></span>
                                <div>
                                    <h3 class="text-sm font-bold text-foreground">Décision de césarienne</h3>
                                    <p class="mt-1 text-xs leading-5 text-muted-foreground">Crée une demande Chirurgie sur ce même passage. Aucune intervention n’est créée dans Maternité — elle reste sous le contrôle du bloc.</p>
                                </div>
                            </div>
                            <div class="mt-4 grid gap-3 lg:grid-cols-[180px_minmax(0,1fr)_auto]">
                                <Select v-model="cesareanForm.type" class="h-10 w-full min-w-0" :options="cesareanTypeOptions" aria-label="Type de césarienne" />
                                <Input v-model="cesareanForm.indication" placeholder="Indication clinique obligatoire" />
                                <Button type="button" variant="warning" :disabled="! cesareanForm.indication || cesareanForm.processing" @click="confirmingCesarean = true">
                                    <Scissors class="h-4 w-4" />Transmettre à Chirurgie
                                </Button>
                            </div>
                            <FormError v-if="firstError(cesareanForm.errors)">{{ firstError(cesareanForm.errors) }}</FormError>
                        </div>
                    </template>

                    <template v-else-if="activeSection === 'newborn'">
                        <div class="space-y-3">
                            <Card v-for="(newborn, index) in form.newborn_data.newborns" :key="index" class="p-4">
                                <div class="mb-3 flex items-center justify-between">
                                    <h3 class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Nouveau-né {{ index + 1 }}</h3>
                                    <Button
                                        v-if="form.newborn_data.newborns.length > 1 && ! newbornPatient(newborn)"
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        class="h-7 px-2 text-[11px] text-destructive hover:text-destructive"
                                        @click="form.newborn_data.newborns.splice(index, 1)"
                                    ><X class="h-3.5 w-3.5" />Retirer</Button>
                                </div>
                                <!-- ADR-146 : le nom du bébé vit dans sa fiche — c'est ainsi que la Réception le retrouve chez
                                     sa mère. Facultatif : un bébé pas encore prénommé se dit « Bébé 2 de <nom de sa mère> ». -->
                                <div class="mb-4 grid gap-4 md:grid-cols-2">
                                    <FormField label="Nom" hint="facultatif — celui de la mère par défaut" :error="form.errors[`newborn_data.newborns.${index}.last_name`]">
                                        <Input v-model="newborn.last_name" maxlength="100" autocomplete="off" :placeholder="patient.last_name" />
                                    </FormField>
                                    <FormField label="Prénom" hint="facultatif : pas toujours déjà prénommé" :error="form.errors[`newborn_data.newborns.${index}.first_name`]">
                                        <Input v-model="newborn.first_name" maxlength="100" autocomplete="off" />
                                    </FormField>
                                </div>
                                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                    <FormField label="Sexe" :error="form.errors[`newborn_data.newborns.${index}.sex`]">
                                        <Select v-model="newborn.sex" class="h-10 w-full min-w-0" :options="newbornSexOptions" />
                                    </FormField>
                                    <FormField label="Poids naissance (g)" :error="form.errors[`newborn_data.newborns.${index}.birth_weight_g`]">
                                        <Input v-model="newborn.birth_weight_g" type="number" :min="reference.birth_weight.min" :max="reference.birth_weight.max" placeholder="ex. 3200" />
                                        <ClinicalFieldHints :hints="newbornHints(newborn).weight" label="Repères sur le poids de naissance" />
                                    </FormField>
                                    <FormField label="Apgar" :error="form.errors[`newborn_data.newborns.${index}.apgar`]">
                                        <Input v-model="newborn.apgar" type="number" min="0" :max="reference.apgar.max" />
                                        <ClinicalFieldHints :hints="newbornHints(newborn).apgar" label="Repères sur le score d’Apgar" />
                                    </FormField>
                                    <FormField label="État du nouveau-né" :error="form.errors[`newborn_data.newborns.${index}.condition`]">
                                        <Input v-model="newborn.condition" />
                                    </FormField>
                                </div>
                                <FormField
                                    as="div"
                                    class="mt-4"
                                    :label="form.newborn_data.newborns.length > 1 ? `Soins — nouveau-né ${index + 1}` : 'Soins bébé'"
                                    :error="form.errors[`newborn_data.newborns.${index}.care_notes`]"
                                >
                                    <Textarea v-model="newborn.care_notes" :rows="3" />
                                </FormField>
                            </Card>

                            <div class="flex flex-wrap items-center gap-3">
                                <Button type="button" size="sm" variant="outline" :disabled="form.newborn_data.newborns.length >= MAX_NEWBORNS" @click="addNewborn">
                                    <Plus class="h-4 w-4" />Ajouter un nouveau-né
                                </Button>
                                <ClinicalFieldHints :hints="newbornCountHint" label="Repères sur le nombre de nouveau-nés" />
                                <span class="text-xs text-muted-foreground">{{ form.newborn_data.newborns.length }} / {{ MAX_NEWBORNS }} — au-delà, le dossier serait refusé à l’enregistrement.</span>
                            </div>
                        </div>

                        <div :class="cn('grid gap-4', legacyBabyCare && 'lg:grid-cols-2')">
                            <!-- Une seule mère : ses soins restent une seule note. -->
                            <FormField as="div" label="Soins mère" :error="form.errors.maternal_care_notes">
                                <Textarea v-model="form.maternal_care_notes" :rows="4" />
                            </FormField>
                            <!-- Une note « Soins bébé » enregistrée avant la saisie par
                                 nouveau-né : elle est conservée, jamais effacée en silence. -->
                            <FormField v-if="legacyBabyCare" as="div" label="Soins bébé — note générale" hint="Saisie avant les soins par nouveau-né" :error="form.errors.baby_care_notes">
                                <Textarea v-model="form.baby_care_notes" :rows="4" />
                            </FormField>
                        </div>
                    </template>

                    <template v-else-if="activeSection === 'procedures'">
                        <!-- Panier d'actes (ADR-138) : à gauche ce qu'on peut ajouter, à
                             droite ce qui sera enregistré d'un seul geste. -->
                        <div v-if="canRecordProcedures" class="grid gap-4 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
                            <div class="rounded-xl border border-border bg-muted/40 p-4">
                                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Actes disponibles</p>
                                    <div class="relative w-full sm:w-56">
                                        <Search class="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                                        <Input v-model="catalogFilter" type="search" class="h-8 ps-8 text-xs" placeholder="Filtrer les actes…" aria-label="Filtrer les actes" autocomplete="off" />
                                    </div>
                                </div>

                                <!-- Un clic ajoute l'acte au panier, un second le retire. -->
                                <div class="flex flex-wrap gap-1.5" role="group" aria-label="Actes Maternité">
                                    <button
                                        v-for="item in visibleCatalog"
                                        :key="item.uuid"
                                        type="button"
                                        role="checkbox"
                                        :aria-checked="inBasket(item.uuid)"
                                        :class="cn(
                                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                            inBasket(item.uuid)
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'border-border bg-background text-foreground hover:bg-accent',
                                        )"
                                        @click="toggleInBasket(item.uuid)"
                                    >
                                        <Check v-if="inBasket(item.uuid)" class="h-3 w-3" aria-hidden="true" />
                                        <Plus v-else class="h-3 w-3 text-muted-foreground" aria-hidden="true" />
                                        {{ item.name }}
                                    </button>
                                    <p v-if="visibleCatalog.length === 0" class="py-2 text-xs text-muted-foreground">Aucun acte ne correspond à « {{ catalogFilter }} ».</p>
                                </div>
                            </div>

                            <section class="flex flex-col overflow-hidden rounded-xl border border-border bg-card" aria-label="Panier d’actes">
                                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-border bg-muted px-4 py-2.5">
                                    <p class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-muted-foreground">
                                        <ShoppingBasket class="h-4 w-4" aria-hidden="true" />Panier d’actes
                                        <Badge :variant="basketForm.lines.length ? 'default' : 'outline'">{{ basketForm.lines.length }}</Badge>
                                    </p>
                                    <div class="flex items-center gap-1">
                                        <Button v-if="plannedNotInBasket.length" type="button" size="sm" variant="outline" class="h-7 px-2 text-[11px]" @click="addPlannedToBasket">
                                            <ClipboardCheck class="h-3.5 w-3.5" />Ajouter les {{ plannedNotInBasket.length }} acte{{ plannedNotInBasket.length > 1 ? 's' : '' }} demandé{{ plannedNotInBasket.length > 1 ? 's' : '' }}
                                        </Button>
                                        <Button v-if="basketHasContent" type="button" size="sm" variant="ghost" class="h-7 px-2 text-[11px] text-muted-foreground" @click="clearBasket">
                                            <Trash2 class="h-3.5 w-3.5" />Vider
                                        </Button>
                                    </div>
                                </header>

                                <p v-if="! basketHasContent" class="px-4 py-10 text-center text-sm text-muted-foreground">
                                    Le panier est vide. Cliquez sur un acte pour l’ajouter, réglez sa quantité, puis enregistrez le tout d’un coup.
                                </p>

                                <ul v-if="basketForm.lines.length" class="divide-y divide-border">
                                    <li v-for="(line, index) in basketForm.lines" :key="line.catalog_item_uuid" class="space-y-2 px-4 py-3">
                                        <div class="flex items-start justify-between gap-2">
                                            <p class="text-sm font-bold text-foreground">
                                                {{ lineItem(line)?.name }}
                                                <Badge v-if="lineItem(line)?.requires_note" variant="warning" class="ms-1.5 align-middle">Précision obligatoire</Badge>
                                            </p>
                                            <Button type="button" size="sm" variant="ghost" class="h-6 w-6 shrink-0 p-0 text-muted-foreground hover:text-destructive" :aria-label="`Retirer ${lineItem(line)?.name} du panier`" @click="removeFromBasket(index)">
                                                <X class="h-4 w-4" />
                                            </Button>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <div class="flex items-center" role="group" :aria-label="`Quantité de ${lineItem(line)?.name}`">
                                                <Button type="button" size="sm" variant="outline" class="h-9 w-9 rounded-e-none p-0" :disabled="Number(line.quantity) <= 1" aria-label="Diminuer la quantité" @click="changeQuantity(line, -1)"><Minus class="h-3.5 w-3.5" /></Button>
                                                <Input v-model="line.quantity" type="number" min="0.01" step="0.01" class="h-9 w-16 rounded-none border-x-0 px-1 text-center" aria-label="Quantité" />
                                                <Button type="button" size="sm" variant="outline" class="h-9 w-9 rounded-s-none p-0" aria-label="Augmenter la quantité" @click="changeQuantity(line, 1)"><Plus class="h-3.5 w-3.5" /></Button>
                                            </div>
                                            <Input
                                                v-model="line.notes"
                                                class="h-9 min-w-0 flex-1"
                                                :placeholder="lineItem(line)?.requires_note ? 'Précisez l’acte (obligatoire)' : 'Précision facultative'"
                                                :aria-required="lineItem(line)?.requires_note ? 'true' : undefined"
                                                :aria-invalid="missingNote(line) ? 'true' : undefined"
                                                :aria-label="`Précision — ${lineItem(line)?.name}`"
                                            />
                                        </div>
                                        <FormError v-if="basketForm.errors[`procedures.${index}.notes`] || basketForm.errors[`procedures.${index}.quantity`]">
                                            {{ basketForm.errors[`procedures.${index}.notes`] || basketForm.errors[`procedures.${index}.quantity`] }}
                                        </FormError>
                                    </li>
                                </ul>

                                <!-- ADR-142 : le matériel utilisé est transmis à la Pharmacie pour la sortie de
                                     stock, puis encaissé par la Caisse. La sage-femme ne voit jamais un prix. -->
                                <div v-if="canRequestConsumables" class="space-y-2 border-t border-border px-4 py-3">
                                    <p class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-muted-foreground">
                                        <Package class="h-4 w-4" aria-hidden="true" />Matériel utilisé
                                        <Badge :variant="basketForm.consumables.length ? 'default' : 'outline'">{{ basketForm.consumables.length }}</Badge>
                                    </p>
                                    <p class="text-[11px] text-muted-foreground">Transmis à la Pharmacie pour la sortie de stock. Le patient règle à la Caisse.</p>

                                    <ul v-if="basketForm.consumables.length" class="divide-y divide-border rounded-lg border border-border">
                                        <li v-for="line in basketForm.consumables" :key="line.medicine_uuid" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                            <div class="min-w-0">
                                                <p class="text-sm font-semibold text-foreground">{{ consumableItem(line)?.name }}</p>
                                                <p class="text-[11px] text-muted-foreground">
                                                    {{ consumableItem(line)?.unit }} · {{ consumableItem(line)?.available_quantity ?? 0 }} en stock
                                                    <span v-if="line.suggested_by?.length" class="italic"> · habituel pour l’acte</span>
                                                </p>
                                                <p v-if="consumableShort(line)" class="mt-0.5 flex items-center gap-1 text-[11px] font-semibold text-amber-700 dark:text-amber-300">
                                                    <TriangleAlert class="h-3 w-3" aria-hidden="true" />Au-delà du stock connu : la Pharmacie devra ajuster son inventaire.
                                                </p>
                                            </div>
                                            <div class="flex items-center gap-1">
                                                <div class="flex items-center" role="group" :aria-label="`Quantité de ${consumableItem(line)?.name}`">
                                                    <Button type="button" size="sm" variant="outline" class="h-8 w-8 rounded-e-none p-0" :disabled="Number(line.quantity) <= 1" aria-label="Diminuer la quantité" @click="changeConsumableQuantity(line, -1)"><Minus class="h-3.5 w-3.5" /></Button>
                                                    <Input v-model="line.quantity" type="number" min="1" step="1" class="h-8 w-14 rounded-none border-x-0 px-1 text-center" aria-label="Quantité" @input="line.touched = true" />
                                                    <Button type="button" size="sm" variant="outline" class="h-8 w-8 rounded-s-none p-0" aria-label="Augmenter la quantité" @click="changeConsumableQuantity(line, 1)"><Plus class="h-3.5 w-3.5" /></Button>
                                                </div>
                                                <Button type="button" size="sm" variant="ghost" class="h-8 w-8 p-0 text-muted-foreground hover:text-destructive" :aria-label="`Retirer ${consumableItem(line)?.name}`" @click="removeConsumable(line.medicine_uuid)"><X class="h-4 w-4" /></Button>
                                            </div>
                                        </li>
                                    </ul>

                                    <div class="relative">
                                        <Search class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                                        <Input v-model="consumableSearch" type="search" class="h-9 ps-9" placeholder="Ajouter du matériel (compresses, gants, DIU…)" autocomplete="off" aria-label="Rechercher du matériel" />
                                    </div>
                                    <ul v-if="consumableSearch.trim() && consumableOptions.length" class="divide-y divide-border rounded-lg border border-border">
                                        <li v-for="item in consumableOptions" :key="item.medicine_uuid">
                                            <button type="button" class="flex w-full items-center justify-between gap-2 px-3 py-2 text-start text-sm hover:bg-accent" @click="addConsumable(item)">
                                                <span class="font-medium text-foreground">{{ item.name }}</span>
                                                <span class="text-[11px] text-muted-foreground">{{ item.available ? `${item.available_quantity} en stock` : 'Épuisé' }}</span>
                                            </button>
                                        </li>
                                    </ul>
                                    <p v-else-if="consumableSearch.trim()" class="text-[11px] text-muted-foreground">Aucun matériel ne correspond. Seul le matériel de parapharmacie et celui configuré pour les actes de la Maternité peut être déclaré.</p>
                                    <Input v-if="basketForm.consumables.length" v-model="basketForm.consumable_notes" class="h-9" placeholder="Note pour la Pharmacie (facultatif)" aria-label="Note pour la Pharmacie" />
                                    <FormError v-if="basketForm.errors.consumables">{{ basketForm.errors.consumables }}</FormError>
                                </div>

                                <footer v-if="basketHasContent" class="mt-auto space-y-2 border-t border-border bg-muted/40 px-4 py-3">
                                    <FormError v-if="basketError">{{ basketError }}</FormError>
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-xs text-muted-foreground">
                                            <template v-if="basketBlockers">{{ basketBlockers }} acte{{ basketBlockers > 1 ? 's' : '' }} à préciser avant d’enregistrer.</template>
                                            <template v-else>
                                                <template v-if="basketForm.lines.length">{{ basketForm.lines.length }} acte{{ basketForm.lines.length > 1 ? 's' : '' }} · {{ basketUnits }} au total</template>
                                                <template v-if="basketForm.lines.length && basketForm.consumables.length"> · </template>
                                                <template v-if="basketForm.consumables.length">{{ basketForm.consumables.length }} matériel{{ basketForm.consumables.length > 1 ? 's' : '' }} à la Pharmacie</template>
                                            </template>
                                        </p>
                                        <Button type="button" variant="primary" :disabled="! basketReady || basketForm.processing" @click="saveBasket">
                                            <Check class="h-4 w-4" />{{ basketForm.processing ? 'Enregistrement…' : basketSaveLabel }}
                                        </Button>
                                    </div>
                                </footer>
                            </section>
                        </div>

                        <div class="overflow-hidden rounded-xl border border-border">
                            <p class="border-b border-border bg-muted px-4 py-2.5 text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Actes réalisés</p>
                            <ul v-if="record?.procedures?.length" class="divide-y divide-border">
                                <li v-for="procedure in record.procedures" :key="procedure.uuid" class="px-4 py-3">
                                    <!-- Correction sur place : quantité et précision, jamais l'acte
                                         lui-même (changer d'acte, c'est retirer et ajouter). -->
                                    <form v-if="editingProcedure === procedure.uuid" class="space-y-2" @submit.prevent="saveEdit(procedure)">
                                        <p class="text-sm font-bold text-foreground">{{ procedure.procedure_name }}</p>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <Input v-model="editForm.quantity" type="number" min="0.01" step="0.01" class="h-9 w-24" aria-label="Quantité" />
                                            <Input
                                                v-model="editForm.notes"
                                                class="h-9 min-w-0 flex-1"
                                                :placeholder="editNeedsNote(procedure) ? 'Précisez l’acte (obligatoire)' : 'Précision facultative'"
                                                :aria-required="editNeedsNote(procedure) ? 'true' : undefined"
                                                aria-label="Précision"
                                            />
                                            <Button type="submit" size="sm" variant="primary" :disabled="editForm.processing || (editNeedsNote(procedure) && ! String(editForm.notes ?? '').trim())">
                                                <Check class="h-4 w-4" />{{ editForm.processing ? 'Enregistrement…' : 'Enregistrer' }}
                                            </Button>
                                            <Button type="button" size="sm" variant="ghost" @click="cancelEdit">Annuler</Button>
                                        </div>
                                        <FormError v-if="editForm.errors.notes || editForm.errors.quantity || editForm.errors.procedure">{{ editForm.errors.notes || editForm.errors.quantity || editForm.errors.procedure }}</FormError>
                                    </form>

                                    <div v-else class="flex flex-wrap items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="text-sm font-bold text-foreground">{{ procedure.procedure_name }} <span class="font-normal text-muted-foreground">× {{ procedure.quantity }}</span></p>
                                            <p class="mt-1 text-xs text-muted-foreground">
                                                Par {{ procedure.performer?.name }}<template v-if="performedOn(procedure)"> · {{ performedOn(procedure) }}</template> · {{ procedure.notes || 'Sans précision' }}
                                                <template v-if="procedure.edited_at"> · <span class="italic">corrigé par {{ procedure.editor?.name ?? 'un collègue' }}</span></template>
                                            </p>
                                            <!-- Sans montant : la sage-femme ne voit jamais un prix. Un acte non chiffré se dit, il ne se tait pas (ADR-103). -->
                                            <Badge v-if="procedure.billing" :variant="procedure.billing.needs_attention ? 'warning' : 'outline'" class="mt-1.5">
                                                {{ procedure.billing.label }}
                                            </Badge>
                                        </div>
                                        <div class="flex shrink-0 items-center gap-1">
                                            <template v-if="procedure.can_modify">
                                                <Button type="button" size="sm" variant="ghost" class="h-8 w-8 p-0 text-muted-foreground hover:text-foreground" :aria-label="`Modifier ${procedure.procedure_name}`" title="Modifier" @click="startEdit(procedure)">
                                                    <Pencil class="h-4 w-4" />
                                                </Button>
                                                <Button type="button" size="sm" variant="ghost" class="h-8 w-8 p-0 text-muted-foreground hover:text-destructive" :aria-label="`Retirer ${procedure.procedure_name}`" title="Retirer" @click="removeForm.clearErrors(); removingProcedure = procedure">
                                                    <Trash2 class="h-4 w-4" />
                                                </Button>
                                            </template>
                                            <!-- L'acte d'un médecin reste intact : on le dit, on ne le masque pas. -->
                                            <span v-else-if="procedure.locked_by_physician" class="inline-flex items-center gap-1 text-[11px] text-muted-foreground" title="Enregistré par un médecin : non modifiable depuis la Maternité">
                                                <Lock class="h-3.5 w-3.5" aria-hidden="true" />Médecin
                                            </span>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                            <p v-else class="px-4 py-8 text-center text-sm text-muted-foreground">Aucun acte Maternité enregistré.</p>
                        </div>

                        <div v-if="capabilities.can_view_consumables && consumableRequests.length" class="overflow-hidden rounded-xl border border-border">
                            <p class="flex items-center gap-2 border-b border-border bg-muted px-4 py-2.5 text-[10px] font-bold uppercase tracking-wider text-muted-foreground">
                                <Package class="h-3.5 w-3.5" aria-hidden="true" />Matériel transmis à la Pharmacie
                            </p>
                            <ul class="divide-y divide-border">
                                <li v-for="request in consumableRequests" :key="request.uuid" class="space-y-1.5 px-4 py-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-sm font-bold text-foreground">{{ request.request_number }} <span class="font-normal text-muted-foreground">· {{ performedOn({ performed_at: request.requested_at }) }}</span></p>
                                        <div class="flex items-center gap-1">
                                            <Badge :variant="request.status === 'SERVED' ? 'success' : request.status === 'CANCELLED' ? 'outline' : 'warning'">{{ request.status_label }}</Badge>
                                            <Button v-if="capabilities.can_cancel_consumables && request.can_be_cancelled" type="button" size="sm" variant="ghost" class="h-7 px-2 text-[11px] text-muted-foreground hover:text-destructive" @click="cancelForm.clearErrors(); cancelForm.reason = ''; cancellingRequest = request">Annuler</Button>
                                        </div>
                                    </div>
                                    <ul class="text-xs text-muted-foreground">
                                        <li v-for="line in request.lines" :key="line.uuid">
                                            {{ line.name }} × {{ line.quantity_requested }}<template v-if="line.quantity_served"> · {{ line.quantity_served }} sorti{{ line.quantity_served > 1 ? 's' : '' }}</template>
                                        </li>
                                    </ul>
                                    <p v-if="request.cancellation_reason" class="text-xs italic text-muted-foreground">Annulée : {{ request.cancellation_reason }}</p>
                                </li>
                            </ul>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <FormField as="div" label="Observations" :error="form.errors.observations">
                                <Textarea v-model="form.observations" :rows="5" />
                            </FormField>
                            <FormField as="div" label="Transmission / sortie du module" :error="form.errors.transmission_notes">
                                <Textarea v-model="form.transmission_notes" :rows="5" />
                            </FormField>
                        </div>
                    </template>
                </fieldset>

                <footer class="flex flex-col gap-3 border-t border-border bg-muted/40 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <span class="text-xs text-muted-foreground">{{ record?.updated_at ? 'Dernière mise à jour enregistrée' : 'Dossier à renseigner' }}</span>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="capabilities.can_edit" type="submit" variant="primary" :disabled="form.processing">
                            <Save class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : 'Enregistrer le dossier' }}
                        </Button>
                        <Button v-if="capabilities.can_complete && record" type="button" variant="success" @click="confirmingComplete = true">
                            <Check class="h-4 w-4" />Terminer la prise en charge
                        </Button>
                    </div>
                </footer>
            </Card>

            <FormError v-if="firstError(form.errors)" class="mt-2">{{ firstError(form.errors) }}</FormError>
        </form>

        <Dialog
            :open="cancellingRequest !== null"
            title="Annuler cette demande de matériel ?"
            description="La Pharmacie ne la verra plus dans sa file et ce qui n'a pas encore été porté sur une facture est retiré du compte du patient."
            @update:open="(open) => { if (! open) cancellingRequest = null; }"
        >
            <FormField label="Motif" required :error="cancelForm.errors.reason || cancelForm.errors.request">
                <Input v-model="cancelForm.reason" placeholder="Ex. saisi par erreur" maxlength="500" />
            </FormField>

            <template #footer>
                <Button type="button" variant="outline" :disabled="cancelForm.processing" @click="cancellingRequest = null">Garder</Button>
                <Button type="button" variant="danger" :disabled="cancelForm.processing || ! cancelForm.reason.trim()" @click="confirmCancelRequest">
                    {{ cancelForm.processing ? 'Annulation…' : 'Annuler la demande' }}
                </Button>
            </template>
        </Dialog>

        <Dialog
            :open="removingProcedure !== null"
            title="Retirer cet acte ?"
            description="L’acte quitte la liste des actes réalisés. Rien n’est détruit : le retrait est tracé à l’audit avec son auteur."
            @update:open="(open) => { if (! open) removingProcedure = null; }"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-red-50 text-red-600 dark:bg-red-950/35 dark:text-red-300"><Trash2 class="h-5 w-5" /></span>
            </template>

            <p v-if="removingProcedure" class="text-sm text-foreground">
                <strong>{{ removingProcedure.procedure_name }}</strong> × {{ removingProcedure.quantity }}
                <span class="text-muted-foreground"> — enregistré par {{ removingProcedure.performer?.name }}</span>
            </p>
            <FormError v-if="removeForm.errors.procedure" class="mt-2">{{ removeForm.errors.procedure }}</FormError>

            <template #footer>
                <Button type="button" variant="outline" :disabled="removeForm.processing" @click="removingProcedure = null">Annuler</Button>
                <Button type="button" variant="danger" :disabled="removeForm.processing" @click="confirmRemove">
                    <Trash2 class="h-4 w-4" />{{ removeForm.processing ? 'Retrait…' : 'Retirer l’acte' }}
                </Button>
            </template>
        </Dialog>

        <Dialog
            :open="confirmingCesarean"
            title="Transmettre la césarienne à Chirurgie ?"
            description="Une demande est créée sur ce même passage. La programmation et l’intervention restent sous le contrôle du bloc opératoire ; Maternité n’enregistre aucun acte chirurgical."
            @update:open="confirmingCesarean = $event"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-950/35 dark:text-amber-300"><Scissors class="h-5 w-5" /></span>
            </template>

            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Patiente</dt><dd class="font-semibold text-foreground">{{ patient.first_name }} {{ patient.last_name }} · {{ episode.episode_number }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Type</dt><dd class="font-semibold text-foreground">{{ cesareanTypeOptions.find((option) => option.value === cesareanForm.type)?.label }}</dd></div>
                <div class="flex flex-col gap-1"><dt class="text-muted-foreground">Indication</dt><dd class="rounded-md bg-muted px-3 py-2 text-foreground">{{ cesareanForm.indication }}</dd></div>
            </dl>

            <template #footer>
                <Button type="button" variant="outline" :disabled="cesareanForm.processing" @click="confirmingCesarean = false">Annuler</Button>
                <Button type="button" variant="warning" :disabled="cesareanForm.processing" @click="requestCesarean">
                    <Scissors class="h-4 w-4" />{{ cesareanForm.processing ? 'Transmission…' : 'Transmettre à Chirurgie' }}
                </Button>
            </template>
        </Dialog>

        <Dialog
            :open="confirmingComplete"
            title="Terminer la prise en charge Maternité ?"
            description="Le dossier passe en lecture seule et le passage quitte la file « En cours ». Les données déjà enregistrées sont conservées."
            @update:open="confirmingComplete = $event"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-950/35 dark:text-emerald-300"><Check class="h-5 w-5" /></span>
            </template>

            <!-- Terminer rend le dossier non modifiable : ce qui n'est pas
                 encore enregistré doit l'être avant, pas après. -->
            <p v-if="form.isDirty" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-200">
                <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                Le dossier porte des modifications non enregistrées. Enregistrez-les d’abord : terminer maintenant les perdrait.
            </p>
            <div v-else class="space-y-4">
                <p class="text-sm text-muted-foreground">Le dossier de {{ patient.first_name }} {{ patient.last_name }} sera clos pour le passage {{ episode.episode_number }}.</p>

                <ClinicalSegmentedChoice
                    v-model="outcome"
                    name="maternity-outcome"
                    label="Et ensuite ?"
                    :options="OUTCOMES"
                    :clearable="false"
                />

                <div v-if="toMedicine" class="space-y-3">
                    <p class="flex items-start gap-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs leading-5 text-sky-800 dark:border-sky-900 dark:bg-sky-950/25 dark:text-sky-200">
                        <Stethoscope class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        La patiente rejoint la file du médecin sur ce même passage. Elle reste visible ici, dans « Orientées vers Médecine », jusqu’à ce que le médecin ait terminé.
                    </p>
                    <FormField label="Message pour le médecin" hint="Facultatif" :error="completeForm.errors.medicine_note">
                        <Textarea v-model="completeForm.medicine_note" rows="3" maxlength="1000" placeholder="Ce que le médecin doit savoir avant de la revoir…" />
                    </FormField>
                </div>
                <p v-else class="text-xs text-muted-foreground">
                    Si plus aucun service n’a la patiente, son passage n’attend plus que la Réception pour la sortie.
                </p>
            </div>

            <template #footer>
                <Button type="button" variant="outline" :disabled="completeForm.processing" @click="confirmingComplete = false">Annuler</Button>
                <Button type="button" :variant="toMedicine ? 'primary' : 'success'" :disabled="completeForm.processing || form.isDirty" @click="completeCare">
                    <component :is="toMedicine ? Stethoscope : Check" class="h-4 w-4" />
                    {{ completeForm.processing ? 'Clôture…' : (toMedicine ? 'Terminer et orienter' : 'Terminer la prise en charge') }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
