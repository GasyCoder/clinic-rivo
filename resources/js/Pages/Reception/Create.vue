<script setup>
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Baby,
    Briefcase,
    Building2,
    CalendarDays,
    Check,
    CircleAlert,
    CircleCheck,
    ClipboardList,
    Eye,
    Heart,
    History,
    IdCard,
    Info,
    Layers,
    LayoutGrid,
    Link2,
    LoaderCircle,
    Lock,
    Mail,
    MapPin,
    Minus,
    Pencil,
    Phone,
    Pill,
    Plus,
    ScanLine,
    Search,
    Settings,
    ShieldCheck,
    ShoppingCart,
    Trash2,
    Siren,
    UserRound,
    UserRoundPlus,
    UsersRound,
    Wallet,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Shadcn/Avatar.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import CardBody from '@/Components/UI/CardBody.vue';
import FormError from '@/Components/UI/FormError.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import NewbornPicker from '@/Components/Reception/NewbornPicker.vue';
import { financialModeLabel } from '@/utilities/financialMode';
import { formatMoney } from '@/utilities/money';
import {
    formatPatientAge, formatPatientBirthDate, formatPatientCivilName,
    formatPatientInitials, formatPatientName,
} from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    estimateCatalog: { type: Array, default: () => [] },
    addressEntries: { type: Array, default: () => [] },
    mutualOrganizations: { type: Array, default: () => [] },
    partnerOrganizations: { type: Array, default: () => [] },
    capabilities: { type: Object, default: () => ({}) },
    resumeEpisode: { type: Object, default: null },
    /** ADR-104 — le second rayon du panier : les médicaments vendables. */
    pharmacyCatalog: { type: Array, default: () => [] },
    receptionDraft: { type: Object, default: null },
    financialPreview: { type: Object, default: null },
});

const steps = [
    { number: 1, label: 'Besoin' },
    { number: 2, label: 'Estimation' },
    { number: 3, label: 'Patient' },
    { number: 4, label: 'Épisode' },
    { number: 5, label: 'Prise en charge' },
    { number: 6, label: 'Confirmation' },
    { number: 7, label: 'Routage' },
];
const currentStep = ref(props.resumeEpisode ? (props.resumeEpisode.financial_mode ? 6 : 5) : 1);
const catalogQuery = ref('');
const moduleFilter = ref('');
const showUnavailable = ref(false);
const cart = ref((props.receptionDraft?.catalog_lines ?? []).map((line) => ({ ...line })));
const estimate = ref(null);
const estimateLoading = ref(false);
const estimateError = ref('');
const designationDeferred = ref(Boolean(props.receptionDraft?.designation_deferred));

const patientMode = ref('search');
const patientQuery = ref('');
const patientMatches = ref([]);
const patientSearchLoading = ref(false);
const patientSearchPerformed = ref(false);
const selectedPatient = ref(props.resumeEpisode?.patient ?? null);
const duplicates = ref([]);
const arrivalLoading = ref(false);
const arrivalErrors = ref({});
const arrivalMessage = ref('');
const episode = ref(props.resumeEpisode ?? null);
const isExternalNewborn = ref(false);

const birthMode = ref('date');
const addressMode = ref('existing');
const patientForm = reactive({
    civility: '', first_name: '', last_name: '', birth_date: '', age: '', sex: 'M',
    identity_document_type: '', identity_document_number: '',
    marital_status: '', children_count: '',
    phone: '', email: '', profession: '', address_entry_uuid: '', new_address_label: '',
    emergency_contact_name: '', emergency_contact_phone: '',
    emergency_contact_relationship: '', emergency_contact_email: '',
});
const civilityOptions = [
    { value: 'MR', label: 'M.', sex: 'M' },
    { value: 'MRS', label: 'Mme', sex: 'F' },
    { value: 'GIRL', label: 'Enfant fille', sex: 'F' },
    { value: 'BOY', label: 'Enfant garçon', sex: 'M' },
];
const maritalStatusOptions = [
    { value: 'SINGLE', label: 'Célibataire' },
    { value: 'MARRIED', label: 'Marié(e)' },
    { value: 'DIVORCED', label: 'Divorcé(e)' },
    { value: 'WIDOWED', label: 'Veuf / Veuve' },
];
const civilitySelectOptions = [
    { value: '', label: 'Choisir' },
    ...civilityOptions.map((option) => ({ value: option.value, label: option.label })),
];
const identityDocumentOptions = [
    { value: '', label: 'Type' },
    { value: 'CIN', label: 'CIN' },
    { value: 'PASSPORT', label: 'Passeport' },
];
const beneficiaryTypeOptions = [
    { value: 'PRINCIPAL', label: 'Principal' },
    { value: 'FAMILY_MEMBER', label: 'Membre de la famille' },
];
const maritalSelectOptions = [
    { value: '', label: 'Non renseignée' },
    ...maritalStatusOptions.map((option) => ({ value: option.value, label: option.label })),
];
const addressSelectOptions = computed(() => [
    { value: '', label: 'Non renseignée' },
    ...(props.addressEntries ?? []).map((address) => ({ value: address.uuid, label: address.label })),
]);
const mutualSelectOptions = computed(() => [
    { value: '', label: 'Choisir un organisme' },
    ...(props.mutualOrganizations ?? []).map((organization) => ({
        value: organization.uuid,
        label: `${organization.name} · ${Number(organization.coverage_rate).toLocaleString('fr-FR')} %`,
    })),
]);
const partnerSelectOptions = computed(() => [
    { value: '', label: 'Choisir un partenaire' },
    ...(props.partnerOrganizations ?? []).map((organization) => ({ value: organization.uuid, label: organization.name })),
]);

const isChildPatient = computed(() => (
    patientMode.value === 'create' && ['GIRL', 'BOY'].includes(patientForm.civility)
));
const isDependentPatient = computed(() => isExternalNewborn.value || isChildPatient.value);

const resetAdultAdministrativeFields = () => {
    patientForm.identity_document_type = '';
    patientForm.identity_document_number = '';
    patientForm.marital_status = '';
    patientForm.children_count = '';
    patientForm.phone = '';
    patientForm.email = '';
    patientForm.profession = '';
};

const chooseCivility = (value) => {
    patientForm.civility = value || '';
    patientForm.sex = civilityOptions.find((option) => option.value === patientForm.civility)?.sex ?? patientForm.sex;

    if (['GIRL', 'BOY'].includes(patientForm.civility)) {
        resetAdultAdministrativeFields();
    }
};

const financialMode = ref(props.resumeEpisode?.financial_mode ?? 'SELF');
const financialLoading = ref(false);
const financialErrors = ref({});
const preview = ref(props.financialPreview ?? null);
const mutualForm = reactive({
    mutual_organization_uuid: props.resumeEpisode?.mutual_coverage?.mutual_organization_uuid ?? '',
    employer_name: props.resumeEpisode?.mutual_coverage?.employer_name ?? '',
    beneficiary_type: props.resumeEpisode?.mutual_coverage?.beneficiary_type ?? 'PRINCIPAL',
    membership_number: props.resumeEpisode?.mutual_coverage?.membership_number ?? '',
});
const partnerForm = reactive({
    partner_organization_uuid: props.resumeEpisode?.partner_coverage?.partner_organization_uuid ?? '',
});
const employeeQuery = ref('');
const employeeMatches = ref([]);
const employeeSearchLoading = ref(false);
const employeeSearchPerformed = ref(false);
const selectedEmployee = ref(props.resumeEpisode?.staff_coverage?.employee ?? null);

const finalForm = useForm({
    defer_designation: false,
    catalog_lines: [],
    payment_choice: 'LATER',
});
const emergencyForm = useForm({});

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const requestJson = async (url, options = {}) => {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            ...(options.headers ?? {}),
        },
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(data.message || 'La demande n’a pas pu être traitée.');
        error.payload = data;
        throw error;
    }

    return data;
};

// Un seul index pour les deux rayons : le panier ne désigne ses lignes que
// par l'UUID du `catalog_item`, et n'a donc jamais à savoir de quel rayon
// vient celle qu'il affiche.
const catalogMap = computed(() => new Map(
    [...props.estimateCatalog, ...props.pharmacyCatalog].map((item) => [item.catalog_item_uuid, item]),
));
/** `services` | `pharmacy` — quel rayon est ouvert à l'étape Besoin. */
const aisle = ref('services');
const pharmacyQuery = ref('');
const receptionCatalogModules = [
    { value: 'MEDICINE', label: 'Médecine' },
    { value: 'IMAGING', label: 'Imagerie' },
    { value: 'CARE', label: 'Soins' },
    { value: 'LABORATORY', label: 'Laboratoire' },
    { value: 'MATERNITY', label: 'Maternité' },
];
const catalogModules = computed(() => {
    const modules = new Map(receptionCatalogModules.map((module) => [module.value, {
        ...module,
        count: 0,
        readyCount: 0,
    }]));

    props.estimateCatalog.forEach((item) => {
        const module = modules.get(item.module) ?? {
            value: item.module,
            label: item.module_label,
            count: 0,
            readyCount: 0,
        };
        module.count += 1;
        if (item.reception_ready) module.readyCount += 1;
        modules.set(item.module, module);
    });

    return Array.from(modules.values()).sort((left, right) => left.label.localeCompare(right.label, 'fr'));
});
const catalogCategoryOptions = computed(() => [
    {
        value: '',
        label: 'Toutes',
        count: props.estimateCatalog.length,
        readyCount: props.estimateCatalog.filter((item) => item.reception_ready).length,
    },
    ...catalogModules.value,
]);
const selectedCatalogCategory = computed(() => catalogCategoryOptions.value.find(
    (category) => category.value === moduleFilter.value,
) ?? catalogCategoryOptions.value[0]);
// « Utilisables ici / total » dans le libellé : le domaine et ce qu'il offre
// réellement à la Réception se lisent d'un coup, sans ouvrir la liste.
const catalogCategorySelectOptions = computed(() => catalogCategoryOptions.value.map((category) => ({
    value: category.value,
    label: `${category.label} · ${category.readyCount}/${category.count}`,
})));
// Renvoie le composant lucide lui-même : le template le rend par
// <component :is>, sans table de noms à garder synchronisée avec l'icon set.
const catalogModuleIcon = (module) => ({
    '': LayoutGrid,
    IMAGING: ScanLine,
    MEDICINE: Heart,
    CARE: ShieldCheck,
    LABORATORY: Activity,
    MATERNITY: Heart,
    SURGERY: Activity,
    OPHTHALMOLOGY: Eye,
    FAMILY_PLANNING: Heart,
}[module] ?? Layers);
const readyCatalogCount = computed(() => props.estimateCatalog.filter((item) => item.reception_ready).length);
const cartIds = computed(() => new Set(cart.value.map((line) => line.catalog_item_uuid)));
const matchesCatalogQuery = (item) => {
    if (cartIds.value.has(item.catalog_item_uuid)) return false;
    if (moduleFilter.value && item.module !== moduleFilter.value) return false;

    const needle = catalogQuery.value.trim().toLocaleLowerCase('fr');

    return !needle || `${item.name} ${item.code} ${item.module_label}`
        .toLocaleLowerCase('fr')
        .includes(needle);
};
const filteredCatalog = computed(() => props.estimateCatalog
    .filter((item) => matchesCatalogQuery(item) && (showUnavailable.value || item.reception_ready))
    .sort((left, right) => Number(right.reception_ready) - Number(left.reception_ready)
        || left.name.localeCompare(right.name, 'fr')));
// Every domain of the referential is now listed here (Chirurgie,
// Ophtalmologie, Planning Familial included), not only the ones Reception
// can already route — but most of those have no tariff yet, or no Reception
// workflow at all (e.g. a bloc intervention is never booked directly here,
// see ADR-067/048). Hidden by default so the receptionist only sees what is
// actually usable; this count lets them reveal the rest on demand instead of
// scrolling past a wall of locked rows.
const hiddenUnavailableCount = computed(() => showUnavailable.value
    ? 0
    : props.estimateCatalog.filter((item) => matchesCatalogQuery(item) && !item.reception_ready).length);
const hasCatalogFilters = computed(() => catalogQuery.value.trim() !== '' || moduleFilter.value !== '');
const estimateLineMap = computed(() => new Map(
    (estimate.value?.lines ?? []).map((line) => [line.catalog_item_uuid, line]),
));
const cartLines = computed(() => cart.value.map((line, index) => ({
    index,
    line,
    catalog: catalogMap.value.get(line.catalog_item_uuid),
    estimated: estimateLineMap.value.get(line.catalog_item_uuid),
})));
const selectedOrganization = computed(() => props.mutualOrganizations.find(
    (organization) => organization.uuid === mutualForm.mutual_organization_uuid,
) ?? null);
const previewLines = computed(() => preview.value?.lines ?? []);

const addService = (item) => {
    if (!item.reception_ready) return;

    // `kind` voyage avec la ligne jusqu'au serveur : c'est lui qui décide
    // si elle ouvre une file clinique ou réserve un lot de stock (ADR-104).
    cart.value.push({
        kind: item.kind ?? 'SERVICE',
        catalog_item_uuid: item.catalog_item_uuid,
        quantity: 1,
    });
    estimate.value = null;
    estimateError.value = '';
    designationDeferred.value = false;
};
const filteredPharmacyCatalog = computed(() => {
    const needle = pharmacyQuery.value.trim().toLocaleLowerCase('fr');

    return props.pharmacyCatalog
        .filter((item) => !cartIds.value.has(item.catalog_item_uuid))
        .filter((item) => !needle || `${item.name} ${item.code} ${item.generic_name ?? ''} ${item.category ?? ''}`
            .toLocaleLowerCase('fr')
            .includes(needle))
        .sort((left, right) => left.name.localeCompare(right.name, 'fr'));
});
const serviceCartLines = computed(() => cartLines.value.filter((entry) => entry.line.kind !== 'MEDICINE'));
const medicineCartLines = computed(() => cartLines.value.filter((entry) => entry.line.kind === 'MEDICINE'));

/**
 * Les deux rayons du panier, chacun avec sa teinte : bleu pour les
 * prestations, vert pour la Pharmacie. Les couleurs viennent des tokens de
 * l'application (`primary`, `emerald`) et portent leurs deux thèmes — un
 * `bg-blue-50` en dur resterait clair sur fond sombre (ADR-099).
 *
 * L'état sélectionné ne se lit pas qu'à la teinte : il ajoute un fond, un
 * anneau et une ombre. Deux boutons colorés dont un seul est actif doivent
 * rester distinguables par autre chose que la couleur.
 */
const aisleTabs = computed(() => [
    {
        value: 'services',
        label: 'Désignations & consultations',
        icon: ClipboardList,
        count: serviceCartLines.value.length,
        activeClass: 'bg-primary/10 text-primary shadow-sm ring-1 ring-primary/30',
        idleIconClass: 'text-primary/70',
        badgeVariant: 'default',
    },
    {
        value: 'pharmacy',
        label: 'Pharmacie',
        icon: Pill,
        count: medicineCartLines.value.length,
        activeClass: 'bg-emerald-50 text-emerald-700 shadow-sm ring-1 ring-emerald-300 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-800',
        idleIconClass: 'text-emerald-600/80 dark:text-emerald-400/80',
        badgeVariant: 'success',
    },
]);
/**
 * Le patient n'est venu que pour des médicaments : aucune file clinique ne
 * s'ouvrira, et la confirmation l'enverra directement à la Caisse avec son
 * ticket. L'écran doit le dire avant, pas le faire découvrir après.
 */
const pharmacyOnlyCart = computed(() => cart.value.length > 0
    && medicineCartLines.value.length === cart.value.length);
const resetCatalogFilters = () => {
    catalogQuery.value = '';
    moduleFilter.value = '';
};
const removeService = async (index) => {
    cart.value.splice(index, 1);
    estimate.value = null;
    if (currentStep.value === 2 && cart.value.length) await recalculateEstimate();
    if (!cart.value.length) currentStep.value = 1;
};
/**
 * Vider le panier en un geste. Rien n'est encore enregistré à ce stade —
 * ni Patient, ni Episode, ni facture (ADR-051) — donc il n'y a rien à
 * annuler côté serveur : seule la sélection à l'écran disparaît.
 *
 * Pas de fenêtre de confirmation : le bouton ne s'affiche qu'à partir de
 * deux lignes (en dessous, la croix de la ligne fait le même travail) et
 * reste volontairement discret, à l'écart de l'action principale.
 */
const clearCart = () => {
    cart.value = [];
    estimate.value = null;
    estimateError.value = '';
    designationDeferred.value = false;
    currentStep.value = 1;
};
const stepQuantity = async (entry, delta) => {
    const current = Number(entry.line.quantity) || 1;
    const next = Math.min(9999, Math.max(1, current + delta));

    if (next === current) return;

    entry.line.quantity = next;
    if (currentStep.value === 2) await recalculateEstimate();
};
const cartPayload = () => cart.value.map((line) => ({
    kind: line.kind ?? 'SERVICE',
    catalog_item_uuid: line.catalog_item_uuid,
    quantity: Number(line.quantity),
}));
const recalculateEstimate = async () => {
    if (!cart.value.length) return;
    estimateLoading.value = true;
    estimateError.value = '';

    try {
        estimate.value = await requestJson('/reception/estimates', {
            method: 'POST',
            body: JSON.stringify({ lines: cartPayload() }),
        });
    } catch (error) {
        estimate.value = null;
        estimateError.value = Object.values(error.payload?.errors ?? {}).flat()[0] ?? error.message;
    } finally {
        estimateLoading.value = false;
    }
};
const showEstimate = async () => {
    if (!cart.value.length) return;
    currentStep.value = 2;
    await recalculateEstimate();
};
const continueWithoutKnownDesignation = () => {
    designationDeferred.value = true;
    cart.value = [];
    estimate.value = null;
    currentStep.value = 3;
};
const continueToPatient = () => {
    currentStep.value = 3;
};
const patientBackTarget = computed(() => (
    !designationDeferred.value && cart.value.length ? 2 : 1
));
const patientBackLabel = computed(() => (
    patientBackTarget.value === 2 ? 'Retour à l’estimation' : 'Modifier le besoin'
));
const returnFromPatientStep = async () => {
    arrivalErrors.value = {};
    arrivalMessage.value = '';
    duplicates.value = [];

    if (patientBackTarget.value === 2) {
        currentStep.value = 2;
        if (!estimate.value) await recalculateEstimate();
        return;
    }

    designationDeferred.value = false;
    currentStep.value = 1;
};

const resetEpisodeContact = () => {
    patientForm.emergency_contact_name = '';
    patientForm.emergency_contact_phone = '';
    patientForm.emergency_contact_relationship = '';
    patientForm.emergency_contact_email = '';
};
const searchPatients = async () => {
    if (patientQuery.value.trim().length < 2) return;
    patientSearchLoading.value = true;
    patientSearchPerformed.value = false;
    selectedPatient.value = null;
    resetEpisodeContact();
    arrivalMessage.value = '';

    try {
        const result = await requestJson(`/reception/patients/search?q=${encodeURIComponent(patientQuery.value.trim())}`);
        patientMatches.value = result.data ?? [];
        patientSearchPerformed.value = true;
    } catch (error) {
        arrivalMessage.value = error.message;
    } finally {
        patientSearchLoading.value = false;
    }
};
const choosePatient = (patient) => {
    selectedPatient.value = patient;
    duplicates.value = [];
    arrivalErrors.value = {};
};
const chooseExistingPatient = () => {
    patientMode.value = 'search';
    isExternalNewborn.value = false;
    selectedPatient.value = null;
    duplicates.value = [];
    arrivalErrors.value = {};
    resetEpisodeContact();
};
// Éditer le dossier reste dans le même onglet (plus prévisible pour un
// utilisateur peu familier des onglets) : l'état de la prise en charge en
// cours est donc sauvegardé ici puis restauré au retour, voir
// `resumeAfterPatientEdit` ci-dessous.
const RECEPTION_RESUME_KEY = 'rivo:reception:resume-after-patient-edit';
const editSelectedPatient = () => {
    if (!selectedPatient.value) return;

    try {
        sessionStorage.setItem(RECEPTION_RESUME_KEY, JSON.stringify({
            currentStep: currentStep.value,
            cart: cart.value,
            designationDeferred: designationDeferred.value,
            patientQuery: patientQuery.value,
            patientNumber: selectedPatient.value.patient_number,
        }));
    } catch {
        // Navigation privée ou stockage désactivé — le bouton fonctionne
        // quand même, la prise en charge ne sera simplement pas restaurée.
    }

    router.visit(`/patients/${selectedPatient.value.uuid}/edit?return_to=reception`);
};
const resumeAfterPatientEdit = async () => {
    let raw;

    try {
        raw = sessionStorage.getItem(RECEPTION_RESUME_KEY);
        if (raw) sessionStorage.removeItem(RECEPTION_RESUME_KEY);
    } catch {
        raw = null;
    }

    if (!raw) return;

    let saved;

    try {
        saved = JSON.parse(raw);
    } catch {
        return;
    }

    if (saved.patientNumber) {
        try {
            const result = await requestJson(`/reception/patients/search?q=${encodeURIComponent(saved.patientNumber)}`);
            const refreshed = (result.data ?? []).find((patient) => patient.patient_number === saved.patientNumber);
            if (refreshed) {
                selectedPatient.value = refreshed;
                patientMode.value = 'search';
            }
        } catch {
            // Le dossier n’a pas pu être rechargé — la prise en charge
            // reprend quand même, il suffit de rechercher à nouveau.
        }
    }

    patientQuery.value = saved.patientQuery ?? '';
    cart.value = Array.isArray(saved.cart) ? saved.cart : [];
    designationDeferred.value = Boolean(saved.designationDeferred);
    currentStep.value = saved.currentStep ?? currentStep.value;

    if (currentStep.value === 2 && cart.value.length) await recalculateEstimate();
};

onMounted(resumeAfterPatientEdit);

const chooseAnotherPatient = () => {
    selectedPatient.value = null;
    arrivalErrors.value = {};
    resetEpisodeContact();
};
// ADR-146 — un nouveau-né né chez nous : la Réception l'a choisi dans le dossier de sa mère, il est devenu
// patient, et il repart comme n'importe quel patient existant.
const chooseNewbornMode = () => {
    patientMode.value = 'newborn';
    isExternalNewborn.value = false;
    selectedPatient.value = null;
    duplicates.value = [];
    arrivalErrors.value = {};
    resetEpisodeContact();
};
const newbornSelected = (patient) => {
    patientMode.value = 'search';
    isExternalNewborn.value = false;
    selectedPatient.value = patient;
    duplicates.value = [];
    arrivalErrors.value = {};
};
const chooseNewPatient = () => {
    patientMode.value = 'create';
    isExternalNewborn.value = false;
    selectedPatient.value = null;
    duplicates.value = [];
    arrivalErrors.value = {};
    resetEpisodeContact();
};
const chooseInternalNewborn = () => {
    isExternalNewborn.value = false;
    selectedPatient.value = null;
    duplicates.value = [];
    arrivalErrors.value = {};
    arrivalMessage.value = '';
    resetEpisodeContact();
};
const chooseExternalNewborn = () => {
    patientMode.value = 'newborn';
    isExternalNewborn.value = true;
    selectedPatient.value = null;
    duplicates.value = [];
    arrivalErrors.value = {};
    arrivalMessage.value = '';
    birthMode.value = 'date';

    // Un nouveau-né n'a pas de coordonnées ni de situation administrative
    // d'adulte. Nettoyer ici évite de réutiliser une ancienne saisie lorsque
    // l'agent bascule depuis « Nouveau Patient ».
    patientForm.civility = '';
    resetAdultAdministrativeFields();
    patientForm.age = '';
    resetEpisodeContact();
};
const setBirthMode = (mode) => {
    birthMode.value = mode;
    if (mode === 'date') patientForm.age = '';
    else patientForm.birth_date = '';
};
/**
 * Une adresse absente du référentiel ne doit pas empêcher une arrivée : la
 * saisie manuelle crée l'entrée côté serveur (`RegisterArrivalAction`). Les
 * deux champs sont exclusifs — `StoreArrivalRequest` refuse de recevoir les
 * deux —, d'où le nettoyage de l'autre à chaque bascule.
 */
const setAddressMode = (mode) => {
    addressMode.value = mode;
    if (mode === 'new') patientForm.address_entry_uuid = '';
    else patientForm.new_address_label = '';
};
const arrivalPayload = (confirmDuplicate = false) => {
    const contact = {
        emergency_contact_name: patientForm.emergency_contact_name || null,
        emergency_contact_phone: patientForm.emergency_contact_phone || null,
        emergency_contact_relationship: patientForm.emergency_contact_relationship || null,
        emergency_contact_email: patientForm.emergency_contact_email || null,
    };
    const journey = {
        reception_draft: {
            designation_deferred: designationDeferred.value,
            catalog_lines: cartPayload(),
        },
    };

    if (selectedPatient.value) {
        return { patient_uuid: selectedPatient.value.uuid, ...contact, ...journey };
    }

    const identity = {
        patient_type: 'STANDARD',
        first_name: patientForm.first_name || null,
        last_name: patientForm.last_name,
        birth_date: birthMode.value === 'date' ? patientForm.birth_date || null : null,
        age: birthMode.value === 'age' ? Number(patientForm.age) || null : null,
        sex: patientForm.sex,
        address_entry_uuid: addressMode.value === 'new' ? null : (patientForm.address_entry_uuid || null),
        new_address_label: addressMode.value === 'new' ? (patientForm.new_address_label || null) : null,
        confirm_duplicate: confirmDuplicate,
        ...contact,
        ...journey,
    };

    if (isExternalNewborn.value) {
        return {
            ...identity,
            registration_context: 'EXTERNAL_NEWBORN',
        };
    }

    if (isChildPatient.value) {
        return {
            ...identity,
            civility: patientForm.civility,
        };
    }

    return {
        ...identity,
        civility: patientForm.civility || null,
        identity_document_type: patientForm.identity_document_type || null,
        identity_document_number: patientForm.identity_document_number || null,
        marital_status: patientForm.marital_status || null,
        children_count: patientForm.children_count !== '' ? Number(patientForm.children_count) : null,
        phone: patientForm.phone || null,
        email: patientForm.email || null,
        profession: patientForm.profession || null,
    };
};
const createEpisode = async (confirmDuplicate = false) => {
    if (!selectedPatient.value && patientMode.value !== 'create' && !isExternalNewborn.value) return;
    arrivalLoading.value = true;
    arrivalErrors.value = {};
    arrivalMessage.value = '';

    try {
        const result = await requestJson('/reception/patients', {
            method: 'POST',
            body: JSON.stringify(arrivalPayload(confirmDuplicate)),
        });
        selectedPatient.value = result.patient;
        episode.value = result.episode;
        duplicates.value = [];

        window.location.replace(result.resume_url);
    } catch (error) {
        arrivalErrors.value = error.payload?.errors ?? {};
        duplicates.value = error.payload?.duplicates ?? [];
        arrivalMessage.value = error.message;
    } finally {
        arrivalLoading.value = false;
    }
};

const selectFinancialMode = (mode) => {
    financialMode.value = mode;
    preview.value = null;
    financialErrors.value = {};
    selectedEmployee.value = null;
    employeeSearchPerformed.value = false;
};
const searchEmployees = async () => {
    if (employeeQuery.value.trim().length < 2) return;
    employeeSearchLoading.value = true;
    employeeSearchPerformed.value = false;
    selectedEmployee.value = null;
    financialErrors.value = {};

    try {
        const result = await requestJson(`/reception/employees/patient-lookup?q=${encodeURIComponent(employeeQuery.value.trim())}`);
        employeeMatches.value = result.data ?? [];
        employeeSearchPerformed.value = true;
    } catch (error) {
        financialErrors.value = { employee_uuid: [error.message] };
    } finally {
        employeeSearchLoading.value = false;
    }
};
const employeeSelectable = (employee) => employee.eligible
    && (!employee.linked_patient || employee.linked_patient.uuid === selectedPatient.value?.uuid);
const chooseEmployee = (employee) => {
    if (employeeSelectable(employee)) selectedEmployee.value = employee;
};
const chooseAnotherEmployee = () => {
    selectedEmployee.value = null;
    financialErrors.value = {};
};
const financialPayload = () => {
    // Seules les prestations : la couverture Mutuelle/Personnel porte sur le
    // passage, pas sur le ticket Pharmacie réglé au tarif Sans mutuelle
    // (ADR-104). Le serveur refiltre de toute façon.
    const payload = {
        financial_mode: financialMode.value,
        lines: cartPayload().filter((line) => line.kind !== 'MEDICINE'),
    };
    if (financialMode.value === 'MUTUAL') Object.assign(payload, mutualForm);
    if (financialMode.value === 'STAFF') payload.employee_uuid = selectedEmployee.value?.uuid;
    if (financialMode.value === 'PARTNER') Object.assign(payload, partnerForm);
    return payload;
};
const configureFinancialContext = async () => {
    financialLoading.value = true;
    financialErrors.value = {};

    try {
        const result = await requestJson(`/reception/passages/${episode.value.uuid}/financial-context`, {
            method: 'POST',
            body: JSON.stringify(financialPayload()),
        });
        episode.value = { ...episode.value, ...result.episode };
        preview.value = result.preview;
        currentStep.value = 6;
    } catch (error) {
        financialErrors.value = error.payload?.errors ?? { financial_mode: [error.message] };
    } finally {
        financialLoading.value = false;
    }
};

const showEmergencyConfirm = ref(false);
const markEpisodeEmergency = () => {
    if (!episode.value || emergencyForm.processing) return;

    showEmergencyConfirm.value = true;
};
const closeEmergencyConfirm = () => {
    if (emergencyForm.processing) return;

    showEmergencyConfirm.value = false;
};
const confirmMarkEmergency = () => {
    emergencyForm.post(`/reception/passages/${episode.value.uuid}/urgence`, {
        onSuccess: () => { showEmergencyConfirm.value = false; },
    });
};

/**
 * Le patient n'est venu que pour des médicaments : il ne verra ni médecin ni
 * infirmier, et aucune couverture ne s'applique à son ticket (ADR-104). Lui
 * faire traverser « Prise en charge », « Confirmation » et « Routage » lui
 * ferait répondre trois fois à des questions sans objet pour son passage.
 *
 * Le serveur reste le seul à décider : il refera la même lecture du panier
 * et n'ouvrira aucune file clinique parce qu'il n'y a aucune prestation.
 */
const finishToPharmacy = () => {
    finalForm.defer_designation = false;
    finalForm.catalog_lines = cartPayload();
    finalForm.payment_choice = 'LATER';
    finalForm.post(`/reception/passages/${episode.value.uuid}/prestations`, { preserveScroll: true });
};
const confirmCare = () => {
    finalForm.defer_designation = designationDeferred.value;
    finalForm.catalog_lines = cartPayload();
    finalForm.payment_choice = designationDeferred.value || financialMode.value === 'STAFF'
        ? null
        : 'LATER';
    finalForm.post(`/reception/passages/${episode.value.uuid}/prestations`, { preserveScroll: true });
};
const firstError = (errors, key) => Array.isArray(errors?.[key]) ? errors[key][0] : errors?.[key];
const modeLabel = computed(() => financialModeLabel(financialMode.value));
</script>

<template>
    <Head title="Nouvelle prise en charge" />

    <div class="mx-auto w-full max-w-[1480px] space-y-5">
        <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex items-start gap-3.5">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary ring-1 ring-primary/15"><ClipboardList class="h-5 w-5" /></span>
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-primary">Réception patient</p>
                    <h1 class="mt-1 font-heading text-2xl font-bold tracking-tight text-foreground">Nouvelle prise en charge</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Le besoin d’abord, puis l’identité et le contexte financier du passage.</p>
                </div>
            </div>
            <Button :as="Link" href="/reception" variant="outline"><History class="h-4 w-4" />Activité récente</Button>
        </header>

        <Card class="overflow-hidden">
            <nav class="overflow-x-auto border-b border-border bg-muted/25 p-2" aria-label="Progression de la prise en charge">
                <ol class="flex min-w-[850px] items-stretch gap-1">
                    <li v-for="step in steps" :key="step.number" :class="['flex min-w-0 flex-1 items-center gap-2 rounded-lg px-3 py-2.5 transition-colors', step.number === currentStep ? 'bg-card text-primary shadow-sm ring-1 ring-border' : 'text-muted-foreground']" :aria-current="step.number === currentStep ? 'step' : undefined">
                        <span :class="['grid h-7 w-7 shrink-0 place-items-center rounded-full text-xs font-bold', step.number < currentStep ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : step.number === currentStep ? 'bg-primary text-primary-foreground' : 'bg-background text-muted-foreground ring-1 ring-border']">
                            <Check v-if="step.number < currentStep" class="h-3.5 w-3.5" /><span v-else>{{ step.number }}</span>
                        </span>
                        <span class="truncate text-xs font-semibold">{{ step.label }}</span>
                    </li>
                </ol>
            </nav>

            <CardBody v-if="currentStep === 1" class="!p-5 lg:!p-7">
                <div><div class="flex items-center gap-2"><Badge variant="secondary">Étape 1 sur 7</Badge><span class="text-xs font-medium text-muted-foreground">Besoin</span></div><h2 class="mt-3 font-heading text-2xl font-bold tracking-tight text-foreground">Quel est votre besoin aujourd’hui ?</h2><p class="mt-1 text-sm text-muted-foreground">Ajoutez au panier tout ce dont le patient a besoin. Aucun dossier patient n’est créé à cette étape.</p></div>

                <!-- ADR-104 — deux rayons, un seul panier. Le basculement
                     n'efface jamais la sélection de l'autre rayon : c'est le
                     même besoin, pris en une fois. -->
                <div v-if="capabilities.can_sell_medicines" class="mt-5 inline-flex rounded-lg border border-border bg-muted/35 p-1" role="tablist" aria-label="Rayon du panier">
                    <button
                        v-for="tab in aisleTabs"
                        :key="tab.value"
                        type="button"
                        role="tab"
                        :aria-selected="aisle === tab.value"
                        :class="['inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-semibold transition-colors', aisle === tab.value ? tab.activeClass : 'text-muted-foreground hover:text-foreground']"
                        @click="aisle = tab.value"
                    >
                        <!-- La couleur reste portée par l'icône même au repos :
                             les deux rayons se distinguent d'un coup d'œil,
                             sans attendre d'avoir cliqué. -->
                        <component :is="tab.icon" :class="['h-4 w-4', aisle === tab.value ? '' : tab.idleIconClass]" />{{ tab.label }}
                        <Badge v-if="tab.count" :variant="tab.badgeVariant">{{ tab.count }}</Badge>
                    </button>
                </div>

                <div class="mt-6 grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
                    <section v-show="aisle === 'services'">
                        <div v-if="estimateCatalog.length" class="rounded-xl border border-border bg-muted/25 p-4">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Catégories de prestations</p>
                                    <p class="mt-0.5 text-xs text-muted-foreground">Tous les domaines du référentiel du site · seules les prestations utilisables ici sont affichées par défaut.</p>
                                </div>
                                <Badge variant="success">{{ readyCatalogCount }} utilisable{{ readyCatalogCount > 1 ? 's' : '' }} ici</Badge>
                            </div>
                            <div class="grid gap-2 sm:grid-cols-[minmax(0,260px)_minmax(0,1fr)]">
                                <Select
                                    v-model="moduleFilter"
                                    :options="catalogCategorySelectOptions"
                                    :icon="catalogModuleIcon(moduleFilter)"
                                    placeholder="Toutes les catégories"
                                    aria-label="Domaine de prestations"
                                    class="w-full"
                                />
                                <div class="relative">
                                    <Search class="pointer-events-none absolute start-3 top-1/2 z-10 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input v-model="catalogQuery" class="ps-9" placeholder="Rechercher une désignation, un code ou un service…" autocomplete="off" />
                                </div>
                            </div>
                        </div>
                        <div v-if="estimateCatalog.length" class="mt-3 flex flex-wrap items-center justify-between gap-2 px-1">
                            <label class="inline-flex items-center gap-2 text-xs font-medium text-muted-foreground"><input v-model="showUnavailable" type="checkbox" class="rounded border-border text-primary focus:ring-ring">Afficher aussi les prestations non disponibles ici</label>
                            <span v-if="!showUnavailable && hiddenUnavailableCount" class="text-xs text-muted-foreground">{{ hiddenUnavailableCount }} masquée{{ hiddenUnavailableCount > 1 ? 's' : '' }} · <button type="button" class="font-bold text-primary hover:text-primary" @click="showUnavailable = true">Afficher</button></span>
                        </div>
                        <div v-if="estimateCatalog.length && filteredCatalog.length" class="mt-3 max-h-[480px] overflow-y-auto rounded-md border border-border">
                            <button v-for="item in filteredCatalog" :key="item.catalog_item_uuid" type="button" :disabled="!item.reception_ready" :class="['grid w-full grid-cols-[40px_minmax(0,1fr)_auto] items-center gap-3 border-b border-border px-4 py-3 text-start transition last:border-0', item.reception_ready ? 'hover:bg-accent' : 'cursor-not-allowed bg-muted/25 opacity-70']" @click="addService(item)"><span :class="['flex h-9 w-9 items-center justify-center rounded border', item.reception_ready ? 'border-border text-primary' : item.tariff_available ? 'border-border bg-muted text-muted-foreground ' : 'border-amber-200 bg-amber-50 text-amber-600 dark:border-amber-900 dark:bg-amber-950/20']"><component :is="item.reception_ready ? Plus : item.tariff_available ? Info : Lock" class="h-4 w-4" /></span><span class="min-w-0"><span class="block truncate text-sm font-bold text-foreground">{{ item.name }}</span><span class="mt-0.5 block truncate text-xs text-muted-foreground">{{ item.code }} · {{ item.module_label }} · {{ item.routing_label }}</span></span><span class="text-end"><span :class="['block text-sm font-bold', item.reception_ready ? 'text-foreground' : item.tariff_available ? 'text-muted-foreground' : 'text-amber-600']">{{ item.tariff_available ? formatMoney(item.unit_price) : 'Tarif requis' }}</span><span class="text-[11px] text-muted-foreground">{{ item.unit }}</span></span></button>
                        </div>
                        <div v-else-if="!estimateCatalog.length" class="rounded-md border border-amber-200 bg-amber-50/70 px-5 py-6 dark:border-amber-900 dark:bg-amber-950/20">
                            <div class="flex items-start gap-4">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-xl text-amber-700 dark:bg-amber-900/50 dark:text-amber-300"><CircleAlert class="h-4 w-4" /></span>
                                <div>
                                    <h3 class="text-sm font-bold text-amber-900 dark:text-amber-200">Catalogue Réception non configuré</h3>
                                    <p class="mt-1 max-w-2xl text-xs leading-5 text-amber-800 dark:text-amber-300">Aucune prestation active, facturable, sélectionnable et routée n’est disponible sur ce site. Le référentiel doit être configuré par l’Administration avant toute sélection.</p>
                                    <Button v-if="capabilities.can_manage_catalog" class="mt-3" :as="Link" href="/administration/catalog" size="sm" variant="white-outline"><Settings class="h-4 w-4" />Ouvrir Désignations & tarifs</Button>
                                </div>
                            </div>
                        </div>
                        <div v-else class="mt-3 rounded-md border border-dashed border-border px-5 py-8 text-center">
                            <template v-if="!showUnavailable && hiddenUnavailableCount">
                                <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-amber-50 text-xl text-amber-600 dark:bg-amber-950/30"><Lock class="h-4 w-4" /></span>
                                <p class="mt-3 text-sm font-semibold text-foreground">{{ hiddenUnavailableCount }} prestation{{ hiddenUnavailableCount > 1 ? 's' : '' }} existe{{ hiddenUnavailableCount > 1 ? 'nt' : '' }} ici, non disponible{{ hiddenUnavailableCount > 1 ? 's' : '' }} à la Réception</p>
                                <p class="mt-1 text-xs text-muted-foreground">Tarif manquant, ou domaine sans parcours Réception (ex. Chirurgie, réservée à une orientation depuis un autre service). Affichez-les pour les consulter.</p>
                                <Button class="mt-3" size="sm" variant="white-outline" @click="showUnavailable = true"><Eye class="h-4 w-4" />Afficher {{ hiddenUnavailableCount }} prestation{{ hiddenUnavailableCount > 1 ? 's' : '' }}</Button>
                            </template>
                            <template v-else>
                                <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-muted text-xl text-muted-foreground"><Search class="h-4 w-4" /></span>
                                <p class="mt-3 text-sm font-semibold text-foreground">{{ selectedCatalogCategory.count === 0 ? `Catalogue ${selectedCatalogCategory.label} à configurer` : 'Aucune prestation disponible dans cette catégorie' }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">{{ selectedCatalogCategory.count === 0 ? 'Ajoutez les prestations et leurs tarifs dans le référentiel du site.' : 'Modifiez la recherche ou choisissez une autre catégorie. Les prestations déjà sélectionnées sont masquées.' }}</p>
                                <Button v-if="selectedCatalogCategory.count === 0 && capabilities.can_manage_catalog" class="mt-3" :as="Link" href="/administration/catalog" size="sm" variant="white-outline"><Settings class="h-4 w-4" />Configurer le catalogue</Button>
                                <button v-if="hasCatalogFilters" type="button" class="mt-3 text-xs font-bold text-primary hover:text-primary" @click="resetCatalogFilters">Afficher toutes les prestations</button>
                            </template>
                        </div>
                    </section>

                    <!-- Rayon Pharmacie. La Réception lit le prix et la
                         disponibilité, elle ne sort jamais un lot : la
                         réservation FEFO n'a lieu qu'à la confirmation, et
                         la délivrance qu'après encaissement (ADR-049). -->
                    <section v-show="aisle === 'pharmacy'">
                        <div v-if="pharmacyCatalog.length" class="rounded-xl border border-border bg-muted/25 p-4">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Médicaments disponibles</p>
                                    <p class="mt-0.5 text-xs text-muted-foreground">Prix de vente et stock relus côté serveur · seuls les produits réellement en stock sont proposés.</p>
                                </div>
                                <Badge variant="success">{{ pharmacyCatalog.length }} en stock</Badge>
                            </div>
                            <div class="relative">
                                <Search class="pointer-events-none absolute start-3 top-1/2 z-10 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input v-model="pharmacyQuery" class="ps-9" placeholder="Rechercher un médicament, une DCI ou une famille…" autocomplete="off" />
                            </div>
                        </div>
                        <div v-if="filteredPharmacyCatalog.length" class="mt-3 max-h-[480px] overflow-y-auto rounded-md border border-border">
                            <button v-for="item in filteredPharmacyCatalog" :key="item.catalog_item_uuid" type="button" class="grid w-full grid-cols-[40px_minmax(0,1fr)_auto] items-center gap-3 border-b border-border px-4 py-3 text-start transition last:border-0 hover:bg-accent" @click="addService(item)">
                                <span class="flex h-9 w-9 items-center justify-center rounded border border-border text-primary"><Plus class="h-4 w-4" /></span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-bold text-foreground">{{ item.name }}</span>
                                    <span class="mt-0.5 block truncate text-xs text-muted-foreground">
                                        {{ item.code }}<template v-if="item.form_label"> · {{ item.form_label }}</template><template v-if="item.strength"> {{ item.strength }}</template>
                                        · {{ item.available_quantity }} {{ item.unit }} en stock
                                    </span>
                                </span>
                                <span class="text-end">
                                    <span class="block text-sm font-bold text-foreground">{{ formatMoney(item.unit_price) }}</span>
                                    <!-- Un produit sous ordonnance reste vendable ici : la
                                         Réception n'a pas à juger d'une prescription, mais
                                         elle doit savoir ce qu'elle vend. -->
                                    <span v-if="item.prescription_required" class="text-[11px] font-semibold text-amber-600 dark:text-amber-400">Sur ordonnance</span>
                                    <span v-else class="text-[11px] text-muted-foreground">{{ item.unit }}</span>
                                </span>
                            </button>
                        </div>
                        <div v-else class="mt-3 rounded-md border border-dashed border-border px-5 py-8 text-center">
                            <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-muted text-muted-foreground"><Pill class="h-4 w-4" /></span>
                            <p class="mt-3 text-sm font-semibold text-foreground">{{ pharmacyCatalog.length ? 'Aucun médicament ne correspond' : 'Aucun médicament disponible à la vente' }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">{{ pharmacyCatalog.length ? 'Modifiez la recherche. Les médicaments déjà au panier sont masqués.' : 'Un produit doit être actif, facturable, avoir un prix de vente configuré et rester en stock pour être proposé ici.' }}</p>
                        </div>
                    </section>

                    <aside class="space-y-3 self-start xl:sticky xl:top-20">
                        <div class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                            <div class="flex items-center justify-between gap-2 border-b border-border bg-muted/35 px-4 py-3">
                                <span class="flex items-center gap-2 text-sm font-bold text-foreground"><ShoppingCart class="h-4 w-4 text-primary" />Sélection</span>
                                <span class="flex items-center gap-1.5">
                                    <button v-if="cart.length > 1" type="button" class="inline-flex items-center gap-1 rounded px-1.5 py-1 text-[11px] font-semibold text-muted-foreground transition-colors hover:bg-accent hover:text-destructive" @click="clearCart"><Trash2 class="h-3.5 w-3.5" />Tout retirer</button>
                                    <Badge variant="secondary">{{ cart.length }}</Badge>
                                </span>
                            </div>
                            <!-- Les deux rayons restent distincts dans le
                                 panier : ils ne se règlent pas sur le même
                                 document (ADR-050), et le confondre ferait
                                 lire un seul total à payer là où il y en a
                                 deux. -->
                            <template v-if="cartLines.length">
                                <div v-for="group in [
                                    { key: 'services', label: 'Désignations & consultations', entries: serviceCartLines },
                                    { key: 'pharmacy', label: 'Pharmacie', entries: medicineCartLines },
                                ].filter((g) => g.entries.length)" :key="group.key">
                                    <p class="border-b border-border bg-muted/20 px-4 py-1.5 text-[10px] font-bold uppercase tracking-[0.14em] text-muted-foreground">{{ group.label }}</p>
                                    <div class="divide-y divide-border">
                                        <div v-for="entry in group.entries" :key="entry.line.catalog_item_uuid" class="flex items-center gap-3 px-4 py-3">
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-semibold text-foreground">{{ entry.catalog?.name }}</span>
                                                <span class="text-xs text-muted-foreground">
                                                    <template v-if="entry.line.kind === 'MEDICINE'">{{ entry.line.quantity }} {{ entry.catalog?.unit }} · {{ formatMoney(entry.catalog?.unit_price) }}</template>
                                                    <template v-else>{{ entry.catalog?.module_label }}</template>
                                                </span>
                                            </span>
                                            <span v-if="entry.line.kind === 'MEDICINE'" class="flex shrink-0 items-center gap-1">
                                                <Button icon size="xs" variant="ghost" type="button" :aria-label="`Retirer une unité de ${entry.catalog?.name}`" @click="stepQuantity(entry, -1)"><Minus class="h-3.5 w-3.5" /></Button>
                                                <span class="w-6 text-center text-sm font-bold tabular-nums text-foreground">{{ entry.line.quantity }}</span>
                                                <Button icon size="xs" variant="ghost" type="button" :aria-label="`Ajouter une unité de ${entry.catalog?.name}`" @click="stepQuantity(entry, 1)"><Plus class="h-3.5 w-3.5" /></Button>
                                            </span>
                                            <Button icon size="xs" variant="ghost" type="button" :aria-label="`Retirer ${entry.catalog?.name}`" @click="removeService(entry.index)"><X class="h-4 w-4" /></Button>
                                        </div>
                                    </div>
                                </div>
                            </template>
                            <p v-else class="px-4 py-9 text-center text-sm text-muted-foreground">Ajoutez au moins une prestation ou un médicament.</p>
                            <p v-if="pharmacyOnlyCart" class="flex items-start gap-2 border-t border-border bg-amber-50/70 px-4 py-2.5 text-[11px] leading-4 text-amber-900 dark:bg-amber-950/25 dark:text-amber-200">
                                <Info class="mt-px h-3.5 w-3.5 shrink-0" />
                                <span>Médicaments seuls : après le dossier patient, le passage part directement à la Caisse avec son ticket. Aucun parcours clinique n’est ouvert.</span>
                            </p>
                            <div class="border-t border-border p-3"><Button class="w-full" :disabled="!cart.length" @click="showEstimate">Voir l’estimation<ArrowRight class="h-4 w-4" /></Button></div>
                        </div>
                        <button type="button" class="group w-full rounded-xl border border-dashed border-amber-300 bg-amber-50/60 p-4 text-start transition-all hover:-translate-y-0.5 hover:border-amber-400 hover:bg-amber-50 hover:shadow-sm dark:border-amber-900 dark:bg-amber-950/20 dark:hover:border-amber-800" @click="continueWithoutKnownDesignation">
                            <span class="flex items-start gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-amber-100 text-lg text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"><Info class="h-4 w-4" /></span>
                                <span class="min-w-0 flex-1"><span class="block text-sm font-bold text-foreground">Besoin à préciser après évaluation</span><span class="mt-1 block text-xs leading-5 text-muted-foreground">La prestation et son montant seront définis après l’évaluation par les Soins.</span></span>
                                <ArrowRight class="mt-1 h-4 w-4 shrink-0 text-amber-600 transition group-hover:translate-x-0.5" />
                            </span>
                        </button>
                    </aside>
                </div>
            </CardBody>

            <CardBody v-else-if="currentStep === 2" class="!p-5 lg:!p-7">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="font-heading text-2xl font-bold text-foreground">Estimation au tarif Sans mutuelle</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Montants relus côté serveur à partir des désignations et des quantités.</p>
                    </div>
                    <span class="inline-flex shrink-0 items-center gap-2 rounded-full border border-border bg-muted/35 px-3 py-1.5 text-xs font-semibold text-muted-foreground"><Info class="h-4 w-4" />Estimation, pas une facture</span>
                </div>

                <div class="mt-6 grid gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
                    <section class="space-y-3">
                        <div v-if="estimateError" class="rounded-md border border-amber-200 bg-amber-50/70 px-4 py-3 dark:border-amber-900 dark:bg-amber-950/20">
                            <p class="flex items-start gap-2 text-sm leading-5 text-amber-800 dark:text-amber-200"><CircleAlert class="h-4 w-4 mt-0.5 shrink-0" /><span>{{ estimateError }} Le parcours clinique pourra néanmoins être conservé sans inventer de tarif.</span></p>
                        </div>

                        <div class="overflow-hidden rounded-md border border-border">
                            <div class="flex items-center justify-between gap-3 border-b border-border bg-muted/30 px-4 py-2.5">
                                <p class="text-xs font-bold uppercase tracking-[0.12em] text-muted-foreground">{{ cartLines.length }} ligne{{ cartLines.length > 1 ? 's' : '' }}</p>
                                <span class="flex items-center gap-3">
                                    <button v-if="cartLines.length > 1" type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground transition-colors hover:text-destructive" @click="clearCart"><Trash2 class="h-4 w-4" />Tout retirer</button>
                                    <button type="button" class="inline-flex items-center gap-1.5 text-xs font-bold text-primary hover:text-primary" @click="currentStep = 1"><Plus class="h-4 w-4" />Ajouter au panier</button>
                                </span>
                            </div>
                            <div v-for="entry in cartLines" :key="entry.line.catalog_item_uuid" class="grid grid-cols-[36px_minmax(0,1fr)] items-center gap-3 border-b border-border px-4 py-3 last:border-0 sm:grid-cols-[36px_minmax(0,1fr)_auto_minmax(120px,auto)_32px]">
                                <span class="flex h-9 w-9 items-center justify-center rounded border border-border text-muted-foreground"><component :is="entry.line.kind === 'MEDICINE' ? Pill : catalogModuleIcon(entry.catalog.module)" class="h-4 w-4" /></span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-foreground">{{ entry.catalog.name }}</p>
                                    <p class="mt-0.5 truncate text-xs text-muted-foreground">{{ entry.catalog.code }} · {{ entry.line.kind === 'MEDICINE' ? 'Médicament' : entry.catalog.module_label }}</p>
                                </div>
                                <div class="col-span-2 flex items-center gap-2 sm:col-span-1">
                                    <div class="inline-flex items-center rounded border border-border">
                                        <button type="button" class="flex h-8 w-8 items-center justify-center text-base font-bold text-muted-foreground transition hover:bg-accent hover:text-primary disabled:opacity-40" :disabled="Number(entry.line.quantity) <= 1" aria-label="Diminuer la quantité" @click="stepQuantity(entry, -1)">−</button>
                                        <input v-model="entry.line.quantity" type="number" min="0.01" max="9999.99" step="0.01" aria-label="Quantité" class="h-8 w-14 border-x border-border bg-card text-center text-sm font-bold text-foreground outline-none focus:ring-0" @change="recalculateEstimate">
                                        <button type="button" class="flex h-8 w-8 items-center justify-center text-base font-bold text-muted-foreground transition hover:bg-accent hover:text-primary" aria-label="Augmenter la quantité" @click="stepQuantity(entry, 1)">+</button>
                                    </div>
                                    <span class="text-xs text-muted-foreground">{{ entry.catalog.unit }}</span>
                                </div>
                                <div class="col-span-2 sm:col-span-1 sm:text-end">
                                    <p class="text-sm font-bold text-foreground">{{ entry.estimated ? formatMoney(entry.estimated.line_total) : '—' }}</p>
                                    <p class="text-[11px] text-muted-foreground">{{ entry.estimated ? `${formatMoney(entry.estimated.unit_price)} / ${entry.catalog.unit}` : 'Tarif indisponible' }}</p>
                                </div>
                                <button type="button" class="col-span-2 flex h-8 w-8 items-center justify-center justify-self-end rounded text-muted-foreground transition hover:bg-red-50 hover:text-red-600 sm:col-span-1 dark:hover:bg-red-950/30" :aria-label="`Retirer ${entry.catalog.name}`" @click="removeService(entry.index)"><X class="h-4 w-4" /></button>
                            </div>
                        </div>
                    </section>

                    <aside class="space-y-3 self-start xl:sticky xl:top-20">
                        <div class="overflow-hidden rounded-md border border-emerald-200 dark:border-emerald-900">
                            <div class="bg-emerald-50 px-5 py-4 dark:bg-emerald-950/30">
                                <p class="flex items-center justify-between text-[11px] font-bold uppercase tracking-[0.12em] text-emerald-700 dark:text-emerald-300">
                                    <span>Total standard estimé</span>
                                    <span v-if="estimateLoading" class="font-medium normal-case tracking-normal text-emerald-600">Recalcul…</span>
                                </p>
                                <p class="mt-1 font-heading text-3xl font-bold text-emerald-800 dark:text-emerald-200">{{ estimate ? formatMoney(estimate.total_amount) : 'À confirmer' }}</p>
                            </div>
                            <!-- Deux sous-totaux, parce que ce sont deux
                                 documents : la facture du passage et le
                                 ticket Pharmacie (ADR-050, ADR-104). Un
                                 total unique laisserait croire à un seul
                                 règlement. -->
                            <dl class="divide-y divide-border bg-card text-sm">
                                <div v-if="serviceCartLines.length" class="flex items-center justify-between px-5 py-2.5"><dt class="text-muted-foreground">Prestations · {{ serviceCartLines.length }}</dt><dd class="font-bold tabular-nums text-foreground">{{ estimate ? formatMoney(estimate.services_total) : '—' }}</dd></div>
                                <div v-if="medicineCartLines.length" class="flex items-center justify-between px-5 py-2.5"><dt class="text-muted-foreground">Pharmacie · {{ medicineCartLines.length }}</dt><dd class="font-bold tabular-nums text-foreground">{{ estimate ? formatMoney(estimate.medicines_total) : '—' }}</dd></div>
                                <div class="flex items-center justify-between px-5 py-2.5"><dt class="text-muted-foreground">Barème appliqué</dt><dd class="font-bold text-foreground">Sans mutuelle</dd></div>
                            </dl>
                            <p v-if="medicineCartLines.length && serviceCartLines.length" class="border-t border-border bg-muted/30 px-5 py-3 text-xs leading-5 text-muted-foreground">Les médicaments sont réglés sur un <strong class="font-semibold text-foreground">ticket Pharmacie séparé</strong> : leur délivrance attend ce règlement, indépendamment de la facture du passage.</p>
                            <p class="border-t border-border bg-muted/30 px-5 py-3 text-xs leading-5 text-muted-foreground">Le montant définitif peut varier selon le mode de prise en charge. Aucun Patient, Episode, facture ou paiement n’a encore été créé.</p>
                        </div>

                        <Button class="w-full justify-center" size="rg" :disabled="estimateLoading" @click="continueToPatient">Continuer la prise en charge<ArrowRight class="h-4 w-4" /></Button>
                        <Button class="w-full justify-center" size="rg" variant="white-outline" @click="currentStep = 1"><ArrowLeft class="h-4 w-4" />Modifier le besoin</Button>
                        <p class="text-center"><Link href="/reception" class="text-xs font-semibold text-muted-foreground underline-offset-2 hover:text-foreground hover:underline">Je voulais seulement connaître le prix</Link></p>
                    </aside>
                </div>
            </CardBody>

            <CardBody v-else-if="currentStep === 3" class="!p-5 lg:!p-7">
                <div>
                    <h2 class="text-xl font-bold text-foreground">Identifier le Patient</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Recherchez d’abord le dossier permanent. Créez-en un uniquement si le patient n’existe pas.</p>
                </div>

                <div class="mt-5 inline-flex rounded-md border border-border bg-card p-1">
                    <button type="button" :class="['inline-flex items-center gap-1.5 rounded px-5 py-2.5 text-sm font-semibold transition', patientMode === 'search' ? 'bg-muted text-foreground shadow-sm ' : 'text-muted-foreground hover:text-foreground']" @click="chooseExistingPatient"><Search class="h-4 w-4" />Patient existant</button>
                    <button v-if="capabilities.can_create_patient" type="button" :class="['inline-flex items-center gap-1.5 rounded px-5 py-2.5 text-sm font-semibold transition', patientMode === 'create' ? 'bg-muted text-foreground shadow-sm ' : 'text-muted-foreground hover:text-foreground']" @click="chooseNewPatient"><UserRoundPlus class="h-4 w-4" />Nouveau Patient</button>
                    <button v-if="capabilities.can_create_patient" type="button" :class="['inline-flex items-center gap-1.5 rounded px-5 py-2.5 text-sm font-semibold transition', patientMode === 'newborn' ? 'bg-muted text-foreground shadow-sm ' : 'text-muted-foreground hover:text-foreground']" @click="chooseNewbornMode"><Baby class="h-4 w-4" />Nouveau-né</button>
                </div>

                <!-- ADR-146 — « accouchement chez nous ou externe ? » : chez nous, le bébé se choisit dans le dossier
                     de sa mère, sans seconde saisie d'identité ; ailleurs, seule son identité de bébé est demandée. -->
                <section v-if="patientMode === 'newborn'" class="mt-5 w-full">
                    <NewbornPicker
                        :request="requestJson"
                        @select="newbornSelected"
                        @internal="chooseInternalNewborn"
                        @external="chooseExternalNewborn"
                    />
                </section>

                <section v-if="patientMode === 'search'" class="mt-5 w-full">
                    <div class="rounded-md border border-border bg-muted/25 p-4 sm:p-5">
                        <div class="mb-3">
                            <h3 class="text-sm font-bold text-foreground">Rechercher dans les dossiers patients</h3>
                            <p class="mt-1 text-xs text-muted-foreground">Numéro de dossier, nom, prénom, téléphone ou numéro de pièce d’identité.</p>
                        </div>
                        <form class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]" @submit.prevent="searchPatients">
                            <IconInput v-model="patientQuery" size="lg" :icon="Search" placeholder="Ex. M-26-0001, Rakoto, 034…" autocomplete="off" @update:model-value="patientSearchPerformed = false" />
                            <Button size="lg" type="submit" class="justify-center" :disabled="patientQuery.trim().length < 2 || patientSearchLoading"><component :is="patientSearchLoading ? LoaderCircle : Search" class="h-4 w-4" />{{ patientSearchLoading ? 'Recherche…' : 'Rechercher' }}</Button>
                        </form>
                    </div>

                    <div v-if="selectedPatient" class="mt-4 flex flex-col gap-4 rounded-md border border-primary/30 bg-primary/5 p-4 sm:flex-row sm:items-center">
                        <Avatar rounded size="rg" variant="primary-pale" :text="formatPatientInitials(selectedPatient)" />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="truncate text-base font-bold text-foreground">{{ formatPatientCivilName(selectedPatient) }}</p>
                                <span class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-bold text-primary"><CircleCheck class="h-3.5 w-3.5" />Patient sélectionné</span>
                            </div>
                            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                <span class="font-mono font-semibold">{{ selectedPatient.patient_number }}</span>
                                <span class="inline-flex items-center gap-1"><Phone class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />{{ selectedPatient.phone || 'Téléphone non renseigné' }}</span>
                                <span v-if="formatPatientAge(selectedPatient)" class="inline-flex items-center gap-1"><CalendarDays class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />{{ formatPatientAge(selectedPatient) }}<template v-if="formatPatientBirthDate(selectedPatient)"> · né(e) le {{ formatPatientBirthDate(selectedPatient) }}</template></span>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <Button v-if="capabilities.can_update_patient" type="button" size="sm" variant="white-outline" title="Corrigez le dossier puis revenez : la prise en charge en cours est conservée" @click="editSelectedPatient"><Pencil class="h-4 w-4" />Modifier le dossier</Button>
                            <Button size="sm" variant="white-outline" @click="chooseAnotherPatient">Changer de patient</Button>
                        </div>
                    </div>

                    <div v-else-if="patientMatches.length" class="mt-4 overflow-hidden rounded-md border border-border">
                        <div class="border-b border-border bg-muted/35 px-4 py-2.5 text-xs font-semibold text-muted-foreground">{{ patientMatches.length }} dossier{{ patientMatches.length > 1 ? 's' : '' }} trouvé{{ patientMatches.length > 1 ? 's' : '' }}</div>
                        <button v-for="patient in patientMatches" :key="patient.uuid" type="button" class="grid w-full gap-3 border-b border-border px-4 py-3.5 text-start transition last:border-0 hover:bg-primary/5 sm:grid-cols-[44px_minmax(0,1fr)_minmax(180px,0.45fr)_auto] sm:items-center" @click="choosePatient(patient)">
                            <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(patient)" />
                            <span class="min-w-0"><span class="block truncate text-sm font-bold text-foreground">{{ formatPatientCivilName(patient) }}</span><span class="mt-0.5 block font-mono text-xs text-muted-foreground">{{ patient.patient_number }}</span></span>
                            <span class="min-w-0 text-xs text-muted-foreground"><span class="flex min-w-0 items-center gap-1"><Phone class="h-3.5 w-3.5 shrink-0 text-muted-foreground" /><span class="truncate">{{ patient.phone || 'Téléphone non renseigné' }}</span></span><span v-if="formatPatientAge(patient)" class="mt-0.5 flex items-center gap-1"><CalendarDays class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />{{ formatPatientAge(patient) }}</span></span>
                            <span class="inline-flex items-center text-xs font-bold text-primary">Sélectionner<ArrowRight class="h-4 w-4" /></span>
                        </button>
                    </div>

                    <div v-else-if="patientSearchPerformed && !patientSearchLoading" class="mt-4 rounded-md border border-dashed border-border px-5 py-8 text-center">
                        <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-muted text-xl text-muted-foreground"><Search class="h-4 w-4" /></span>
                        <p class="mt-3 text-sm font-semibold text-foreground">Aucun dossier patient trouvé</p>
                        <p class="mt-1 text-xs text-muted-foreground">Vérifiez la saisie ou créez un nouveau dossier si nécessaire.</p>
                        <Button v-if="capabilities.can_create_patient" class="mt-3" size="sm" variant="white-outline" @click="chooseNewPatient"><UserRoundPlus class="h-4 w-4" />Créer un nouveau Patient</Button>
                    </div>
                </section>

                <section v-if="patientMode === 'create' || isExternalNewborn" class="mt-5 w-full overflow-hidden rounded-md border border-border">
                    <div :class="['border-b border-border px-5 py-4', isExternalNewborn ? 'bg-primary/5' : 'bg-muted/35']">
                        <div class="flex items-start gap-3">
                            <span v-if="isExternalNewborn" class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-primary"><Baby class="h-4 w-4" /></span>
                            <div>
                                <h3 class="text-sm font-bold text-foreground">{{ isExternalNewborn ? 'Identité du nouveau-né né ailleurs' : 'Identité permanente du Patient' }}</h3>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ isExternalNewborn
                                        ? 'Renseignez uniquement l’identité propre au bébé. Les coordonnées appartiennent au parent ou au responsable à joindre.'
                                        : isChildPatient
                                            ? 'Renseignez l’identité de l’enfant. Ses coordonnées de contact sont celles du parent ou du responsable.'
                                            : 'Les informations ci-dessous seront conservées dans le dossier administratif.' }}
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="space-y-5 p-5">
                        <div :class="['grid gap-4', isExternalNewborn ? 'md:grid-cols-2' : 'md:grid-cols-[180px_minmax(0,1fr)_minmax(0,1fr)]']">
                            <FormField v-if="!isExternalNewborn" label="Civilité">
                                <!-- `min-w-0` : le Select impose 176 px par
                                     défaut, ce qui le faisait déborder de sa
                                     colonne et chevaucher le champ voisin. -->
                                <Select class="h-11 w-full min-w-0" :model-value="patientForm.civility" :options="civilitySelectOptions" placeholder="Choisir" @update:model-value="chooseCivility" />
                            </FormField>
                            <FormField :label="isExternalNewborn ? 'Nom du bébé' : 'Nom'" required :error="firstError(arrivalErrors, 'last_name')">
                                <IconInput v-model="patientForm.last_name" size="lg" :icon="UserRound" autocomplete="family-name" placeholder="Nom de famille" :aria-invalid="Boolean(firstError(arrivalErrors, 'last_name'))" />
                            </FormField>
                            <FormField :label="isExternalNewborn ? 'Prénom(s) du bébé' : 'Prénom(s)'" :error="firstError(arrivalErrors, 'first_name')">
                                <IconInput v-model="patientForm.first_name" size="lg" :icon="UserRound" autocomplete="given-name" placeholder="Prénom(s)" :aria-invalid="Boolean(firstError(arrivalErrors, 'first_name'))" />
                            </FormField>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <!-- Le sélecteur Date/Âge vit dans la ligne de
                                 libellé, à hauteur fixe : c'est lui qui
                                 décalait tout le reste de la rangée. -->
                            <FormField
                                :label="isExternalNewborn ? 'Naissance du bébé' : 'Naissance ou âge'"
                                required
                                :error="firstError(arrivalErrors, 'birth_date') || firstError(arrivalErrors, 'age')"
                            >
                                <template #action>
                                    <span class="inline-flex shrink-0 rounded border border-border bg-card p-0.5">
                                        <button type="button" :class="['rounded px-2 py-0.5 text-[10px] font-semibold transition-colors', birthMode === 'date' ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground']" @click="setBirthMode('date')">Date</button>
                                        <button type="button" :class="['rounded px-2 py-0.5 text-[10px] font-semibold transition-colors', birthMode === 'age' ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground']" @click="setBirthMode('age')">Âge</button>
                                    </span>
                                </template>
                                <DatePicker v-if="birthMode === 'date'" v-model="patientForm.birth_date" size="lg" :invalid="Boolean(firstError(arrivalErrors, 'birth_date'))" />
                                <Input v-else v-model="patientForm.age" size="lg" type="number" min="0" max="130" placeholder="Âge déclaré" :aria-invalid="Boolean(firstError(arrivalErrors, 'age'))" />
                            </FormField>

                            <!-- `as="div"` : un <label> enveloppant deux radios
                                 cocherait le premier au moindre clic. -->
                            <FormField as="div" label="Sexe" required :error="firstError(arrivalErrors, 'sex')">
                                <div class="flex h-11 items-center gap-5 rounded-lg border border-input bg-card px-4 shadow-sm" role="radiogroup" aria-label="Sexe">
                                    <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-foreground"><input v-model="patientForm.sex" type="radio" value="M" class="text-primary focus-visible:ring-2 focus-visible:ring-ring/25" />Masculin</label>
                                    <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-foreground"><input v-model="patientForm.sex" type="radio" value="F" class="text-primary focus-visible:ring-2 focus-visible:ring-ring/25" />Féminin</label>
                                </div>
                            </FormField>

                            <FormField v-if="!isDependentPatient" label="Téléphone" :error="firstError(arrivalErrors, 'phone')">
                                <IconInput v-model="patientForm.phone" size="lg" :icon="Phone" type="tel" autocomplete="tel" placeholder="Ex. 034 00 000 00" :aria-invalid="Boolean(firstError(arrivalErrors, 'phone'))" />
                            </FormField>
                            <FormField v-if="!isDependentPatient" label="Email" :error="firstError(arrivalErrors, 'email')">
                                <IconInput v-model="patientForm.email" size="lg" :icon="Mail" type="email" autocomplete="email" placeholder="patient@exemple.mg" :aria-invalid="Boolean(firstError(arrivalErrors, 'email'))" />
                            </FormField>
                            <FormField v-if="!isDependentPatient" label="Profession" :error="firstError(arrivalErrors, 'profession')">
                                <IconInput v-model="patientForm.profession" size="lg" :icon="Briefcase" autocomplete="organization-title" placeholder="Profession" :aria-invalid="Boolean(firstError(arrivalErrors, 'profession'))" />
                            </FormField>
                            <FormField :label="isDependentPatient ? 'Domicile familial' : 'Adresse'" :error="firstError(arrivalErrors, 'address_entry_uuid') || firstError(arrivalErrors, 'new_address_label')">
                                <template v-if="capabilities.can_create_address" #action>
                                    <span class="inline-flex shrink-0 rounded border border-border bg-card p-0.5">
                                        <button type="button" :class="['rounded px-2 py-0.5 text-[10px] font-semibold transition-colors', addressMode === 'existing' ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground']" @click="setAddressMode('existing')">Liste</button>
                                        <button type="button" :class="['rounded px-2 py-0.5 text-[10px] font-semibold transition-colors', addressMode === 'new' ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground']" @click="setAddressMode('new')">Nouvelle</button>
                                    </span>
                                </template>
                                <Select v-if="addressMode === 'existing'" v-model="patientForm.address_entry_uuid" class="h-11 w-full" :options="addressSelectOptions" placeholder="Non renseignée" :aria-invalid="Boolean(firstError(arrivalErrors, 'address_entry_uuid'))" />
                                <IconInput v-else v-model="patientForm.new_address_label" size="lg" :icon="MapPin" placeholder="Saisissez la nouvelle adresse" :aria-invalid="Boolean(firstError(arrivalErrors, 'new_address_label'))" />
                            </FormField>
                        </div>

                        <div v-if="!isDependentPatient" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <FormField
                                as="div"
                                label="Pièce d’identité"
                                hint="(facultatif)"
                                :error="firstError(arrivalErrors, 'identity_document_type') || firstError(arrivalErrors, 'identity_document_number')"
                            >
                                <div class="grid gap-2 sm:grid-cols-[180px_minmax(0,1fr)]">
                                    <Select v-model="patientForm.identity_document_type" class="h-11 w-full min-w-0" :options="identityDocumentOptions" placeholder="Type" />
                                    <IconInput v-model="patientForm.identity_document_number" size="lg" :icon="IdCard" :disabled="!patientForm.identity_document_type" placeholder="Numéro du document" />
                                </div>
                            </FormField>
                            <FormField label="Situation maritale" :error="firstError(arrivalErrors, 'marital_status')">
                                <Select v-model="patientForm.marital_status" class="h-11 w-full" :options="maritalSelectOptions" placeholder="Non renseignée" />
                            </FormField>
                            <FormField label="Nombre d’enfants" :error="firstError(arrivalErrors, 'children_count')">
                                <Input v-model="patientForm.children_count" size="lg" type="number" min="0" max="30" />
                            </FormField>
                        </div>
                    </div>
                </section>

                <!-- ADR-146 : tant que la Réception choisit le bébé chez sa mère, rien d'autre n'est demandé —
                     un formulaire de nouveau patient sous l'arborescence ferait saisir ce qu'on vient d'y trouver. -->
                <section v-if="patientMode === 'create' || isExternalNewborn || (patientMode !== 'newborn' && selectedPatient)" class="mt-5 w-full overflow-hidden rounded-md border border-border">
                    <div class="flex items-start gap-3 border-b border-border bg-muted/35 px-5 py-4">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-lg text-primary"><UsersRound class="h-4 w-4" /></span>
                        <div>
                            <h3 class="text-sm font-bold text-foreground">{{ isDependentPatient ? 'Parent ou responsable à joindre' : 'Personne à contacter pour ce passage' }} <span class="font-normal text-muted-foreground">(facultatif)</span></h3>
                            <p class="mt-1 text-xs text-muted-foreground">{{ isDependentPatient ? 'Le téléphone et l’email sont ceux de l’adulte responsable, jamais ceux de l’enfant.' : 'Ces coordonnées appartiennent uniquement au nouvel Episode et peuvent changer à chaque passage.' }}</p>
                        </div>
                    </div>
                    <div class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-4">
                        <FormField label="Nom du contact" :error="firstError(arrivalErrors, 'emergency_contact_name')">
                            <IconInput v-model="patientForm.emergency_contact_name" size="lg" :icon="UserRound" autocomplete="off" placeholder="Nom complet" :aria-invalid="Boolean(firstError(arrivalErrors, 'emergency_contact_name'))" />
                        </FormField>
                        <FormField label="Téléphone du contact" :error="firstError(arrivalErrors, 'emergency_contact_phone')">
                            <IconInput v-model="patientForm.emergency_contact_phone" size="lg" :icon="Phone" type="tel" autocomplete="off" placeholder="Ex. 034 00 000 00" :aria-invalid="Boolean(firstError(arrivalErrors, 'emergency_contact_phone'))" />
                        </FormField>
                        <FormField label="Lien avec le patient" :error="firstError(arrivalErrors, 'emergency_contact_relationship')">
                            <IconInput v-model="patientForm.emergency_contact_relationship" size="lg" :icon="UsersRound" autocomplete="off" :placeholder="isDependentPatient ? 'Ex. Mère, père, tuteur' : 'Ex. Conjoint, parent, enfant…'" :aria-invalid="Boolean(firstError(arrivalErrors, 'emergency_contact_relationship'))" />
                        </FormField>
                        <FormField label="Email du contact" :error="firstError(arrivalErrors, 'emergency_contact_email')">
                            <IconInput v-model="patientForm.emergency_contact_email" size="lg" :icon="Mail" type="email" autocomplete="off" placeholder="contact@exemple.mg" :aria-invalid="Boolean(firstError(arrivalErrors, 'emergency_contact_email'))" />
                        </FormField>
                    </div>
                </section>

                <div v-if="duplicates.length" class="mt-4 w-full rounded-md border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/20"><p class="text-sm font-bold text-amber-800 dark:text-amber-200">Un dossier similaire existe déjà.</p><div class="mt-2 space-y-1 text-xs text-amber-700 dark:text-amber-300"><p v-for="patient in duplicates" :key="patient.uuid">{{ patient.patient_number }} · {{ formatPatientName(patient) }}</p></div><div class="mt-3 flex flex-wrap gap-2"><Button size="sm" variant="white-outline" @click="patientMode = 'search'; patientMatches = duplicates; patientSearchPerformed = true">Utiliser un dossier existant</Button><Button size="sm" :disabled="arrivalLoading" @click="createEpisode(true)">Créer quand même</Button></div></div>
                <FormError v-if="arrivalMessage && !duplicates.length" class="mt-4 w-full">{{ arrivalMessage }}</FormError>
                <div class="mt-6 flex flex-col-reverse gap-3 border-t border-border pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <Button size="lg" variant="white-outline" @click="returnFromPatientStep"><ArrowLeft class="h-4 w-4" />{{ patientBackLabel }}</Button>
                    <Button v-if="patientMode === 'create' || isExternalNewborn || (patientMode !== 'newborn' && selectedPatient)" size="lg" :disabled="arrivalLoading" @click="createEpisode(false)">{{ arrivalLoading ? 'Création du passage…' : 'Créer l’Episode' }}<ArrowRight class="h-4 w-4" /></Button>
                </div>
            </CardBody>

            <CardBody v-else-if="currentStep === 5" class="!p-5 lg:!p-7">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div><h2 class="text-xl font-bold text-foreground">Mode de prise en charge</h2><p class="mt-1 text-sm text-muted-foreground">Ce choix s’applique uniquement à l’Episode {{ episode.episode_number }}.</p></div>
                    <div class="flex items-center gap-3 rounded-md border border-border bg-muted/35 px-3 py-2"><Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(selectedPatient)" /><span><strong class="block text-sm text-foreground">{{ formatPatientCivilName(selectedPatient) }}</strong><span class="font-mono text-xs text-muted-foreground">{{ selectedPatient.patient_number }}</span><span v-if="formatPatientAge(selectedPatient)" class="text-xs text-muted-foreground"> · {{ formatPatientAge(selectedPatient) }}</span></span></div>
                </div>

                <!-- Pas d'urgence sur un achat de médicaments : classer ce
                     passage ouvrirait les files Soins et Médecine (ADR-056)
                     pour quelqu'un venu chercher une boîte. Ajouter une
                     prestation fait réapparaître la question. -->
                <section v-if="capabilities.can_mark_emergency && episode.priority !== 'EMERGENCY' && ! pharmacyOnlyCart" class="mt-5 flex flex-col gap-4 rounded-md border border-red-200 bg-red-50/60 px-5 py-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between dark:border-red-900 dark:bg-red-950/20">
                    <div class="min-w-0">
                        <h3 class="text-sm font-bold text-red-800 dark:text-red-200">Ce passage doit-il être traité en urgence ?</h3>
                        <p class="mt-1 max-w-3xl text-xs leading-5 text-red-700 dark:text-red-300">La décision concerne uniquement l’Épisode {{ episode.episode_number }}.</p>
                    </div>
                    <Button class="shrink-0" size="rg" variant="danger" :disabled="emergencyForm.processing" @click="markEpisodeEmergency"><Activity class="h-4 w-4" />Classer ce passage en urgence</Button>
                </section>

                <!-- ADR-104 — panier de médicaments seuls : aucun mode de
                     prise en charge ne s'applique, et l'étape suivante
                     refuserait de toute façon de calculer une couverture sur
                     zéro prestation. On propose donc les deux seules suites
                     qui ont un sens pour ce patient. -->
                <section v-if="pharmacyOnlyCart" class="mt-6 overflow-hidden rounded-xl border border-border">
                    <div class="flex items-start gap-3 border-b border-border bg-muted/35 px-5 py-4">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"><Pill class="h-4 w-4" /></span>
                        <div class="min-w-0">
                            <h3 class="text-sm font-bold text-foreground">Ce passage ne contient que des médicaments</h3>
                            <p class="mt-1 text-xs leading-5 text-muted-foreground">
                                Le ticket Pharmacie est réglé au tarif <strong class="font-semibold text-foreground">Sans mutuelle</strong> : aucun mode de prise en charge ne s’y applique.
                                Le patient ne passera ni en Médecine ni aux Soins.
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                        <Button variant="white-outline" :disabled="finalForm.processing" @click="currentStep = 1">
                            <ArrowLeft class="h-4 w-4" />Ajouter une prestation
                        </Button>
                        <Button size="rg" :disabled="finalForm.processing" @click="finishToPharmacy">
                            {{ finalForm.processing ? 'Transmission…' : 'Terminer — envoyer à la Caisse' }}<ArrowRight class="h-4 w-4" />
                        </Button>
                    </div>
                </section>

                <div v-else class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <button type="button" :aria-pressed="financialMode === 'SELF'" :class="['relative rounded-md border p-5 text-start transition lg:p-6', financialMode === 'SELF' ? 'border-primary bg-primary/5 ring-1 ring-ring/25 ' : 'border-border hover:border-primary/40']" @click="selectFinancialMode('SELF')"><CircleCheck class="h-5 w-5 absolute end-4 top-4 text-primary" v-if="financialMode === 'SELF'" /><Wallet class="h-6 w-6 text-primary" /><span class="mt-3 block text-sm font-bold text-foreground">Standard</span><span class="mt-1 block text-xs leading-5 text-muted-foreground">Tarif STANDARD, à la charge du patient.</span></button>
                    <button type="button" :disabled="!capabilities.can_use_mutual" :aria-pressed="financialMode === 'MUTUAL'" :class="['relative rounded-md border p-5 text-start transition disabled:cursor-not-allowed disabled:opacity-50 lg:p-6', financialMode === 'MUTUAL' ? 'border-primary bg-primary/5 ring-1 ring-ring/25 ' : 'border-border hover:border-primary/40']" @click="selectFinancialMode('MUTUAL')"><CircleCheck class="h-5 w-5 absolute end-4 top-4 text-primary" v-if="financialMode === 'MUTUAL'" /><ShieldCheck class="h-6 w-6 text-primary" /><span class="mt-3 block text-sm font-bold text-foreground">Mutuelle</span><span class="mt-1 block text-xs leading-5 text-muted-foreground">Organisme existant et tarif MUTUAL du site.</span></button>
                    <button type="button" :disabled="!capabilities.can_use_staff || !capabilities.can_link_staff" :aria-pressed="financialMode === 'STAFF'" :class="['relative rounded-md border p-5 text-start transition disabled:cursor-not-allowed disabled:opacity-50 lg:p-6', financialMode === 'STAFF' ? 'border-primary bg-primary/5 ring-1 ring-ring/25 ' : 'border-border hover:border-primary/40']" @click="selectFinancialMode('STAFF')"><CircleCheck class="h-5 w-5 absolute end-4 top-4 text-primary" v-if="financialMode === 'STAFF'" /><Briefcase class="h-6 w-6 text-primary" /><span class="mt-3 block text-sm font-bold text-foreground">Personnel</span><span class="mt-1 block text-xs leading-5 text-muted-foreground">Employé RH existant et règles Personnel serveur.</span></button>
                    <button type="button" :disabled="!capabilities.can_use_partner" :aria-pressed="financialMode === 'PARTNER'" :class="['relative rounded-md border p-5 text-start transition disabled:cursor-not-allowed disabled:opacity-50 lg:p-6', financialMode === 'PARTNER' ? 'border-primary bg-primary/5 ring-1 ring-ring/25 ' : 'border-border hover:border-primary/40']" @click="selectFinancialMode('PARTNER')"><CircleCheck class="h-5 w-5 absolute end-4 top-4 text-primary" v-if="financialMode === 'PARTNER'" /><Link2 class="h-6 w-6 text-primary" /><span class="mt-3 block text-sm font-bold text-foreground">Partenaire</span><span class="mt-1 block text-xs leading-5 text-muted-foreground">Organisme partenaire existant (ISPSG, TsaraShop…).</span></button>
                </div>

                <section v-if="financialMode === 'MUTUAL'" class="mt-5 w-full overflow-hidden rounded-md border border-border">
                    <div class="flex flex-col gap-3 border-b border-border bg-muted/35 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-lg text-primary"><ShieldCheck class="h-4 w-4" /></span><div><h3 class="text-sm font-bold text-foreground">Couverture mutuelle de cet Episode</h3><p class="mt-1 text-xs text-muted-foreground">Sélectionnez un organisme existant et renseignez l’adhésion pour ce passage.</p></div></div>
                        <span v-if="selectedOrganization" class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300"><CircleCheck class="h-4 w-4" />{{ Number(selectedOrganization.coverage_rate).toLocaleString('fr-FR') }} % de couverture</span>
                    </div>
                    <div class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-4">
                        <FormField label="Organisme" required :error="firstError(financialErrors, 'mutual_organization_uuid')">
                            <Select v-model="mutualForm.mutual_organization_uuid" class="h-11 w-full" :options="mutualSelectOptions" placeholder="Choisir un organisme" :aria-invalid="Boolean(firstError(financialErrors, 'mutual_organization_uuid'))" />
                        </FormField>
                        <FormField label="Entreprise" required :error="firstError(financialErrors, 'employer_name')">
                            <IconInput v-model="mutualForm.employer_name" size="lg" :icon="Building2" placeholder="Nom de l’entreprise" :aria-invalid="Boolean(firstError(financialErrors, 'employer_name'))" />
                        </FormField>
                        <FormField label="Qualité du bénéficiaire" required :error="firstError(financialErrors, 'beneficiary_type')">
                            <Select v-model="mutualForm.beneficiary_type" class="h-11 w-full" :options="beneficiaryTypeOptions" :aria-invalid="Boolean(firstError(financialErrors, 'beneficiary_type'))" />
                        </FormField>
                        <FormField label="Matricule d’adhésion" :error="firstError(financialErrors, 'membership_number')">
                            <IconInput v-model="mutualForm.membership_number" size="lg" :icon="IdCard" placeholder="Numéro de matricule (si connu)" :aria-invalid="Boolean(firstError(financialErrors, 'membership_number'))" />
                        </FormField>
                    </div>
                </section>

                <section v-if="financialMode === 'STAFF'" class="mt-5 w-full overflow-hidden rounded-md border border-border">
                    <div class="flex items-start gap-3 border-b border-border bg-muted/35 px-5 py-4"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-lg text-primary"><Briefcase class="h-4 w-4" /></span><div><h3 class="text-sm font-bold text-foreground">Identifier l’Employé RH</h3><p class="mt-1 text-xs text-muted-foreground">Recherchez le dossier Employé existant puis confirmez la personne liée à ce Patient.</p></div></div>
                    <div class="p-5">
                        <div class="min-w-0">
                            <span class="mb-1.5 flex h-6 items-center text-sm font-medium text-foreground">Employé de la clinique <span class="text-destructive">&nbsp;*</span></span>
                            <form class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]" @submit.prevent="searchEmployees">
                                <IconInput v-model="employeeQuery" size="lg" :icon="Search" placeholder="Matricule, nom, prénom, téléphone ou pièce d’identité…" autocomplete="off" @update:model-value="employeeSearchPerformed = false" />
                                <Button size="lg" type="submit" class="justify-center" :disabled="employeeQuery.trim().length < 2 || employeeSearchLoading"><component :is="employeeSearchLoading ? LoaderCircle : Search" class="h-4 w-4" />{{ employeeSearchLoading ? 'Recherche…' : 'Rechercher' }}</Button>
                            </form>

                            <div v-if="selectedEmployee" class="mt-4 flex flex-col gap-3 rounded-md border border-primary/30 bg-primary/5 p-4 sm:flex-row sm:items-center">
                                <Avatar rounded size="rg" variant="primary-pale" :text="formatPatientInitials(selectedEmployee)" />
                                <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><p class="truncate text-sm font-bold text-foreground">{{ formatPatientName(selectedEmployee) }}</p><span class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-bold text-primary"><CircleCheck class="h-3.5 w-3.5" />Employé sélectionné</span></div><p class="mt-1 text-xs text-muted-foreground"><span class="font-mono font-semibold">{{ selectedEmployee.employee_number }}</span><span class="mx-2 text-muted-foreground">·</span>{{ selectedEmployee.profession || 'Fonction non renseignée' }}</p></div>
                                <Button size="sm" variant="white-outline" @click="chooseAnotherEmployee">Changer d’employé</Button>
                            </div>

                            <div v-else-if="employeeSearchPerformed && employeeMatches.length" class="mt-4 overflow-hidden rounded-md border border-border">
                                <div class="border-b border-border bg-muted/35 px-4 py-2.5 text-xs font-semibold text-muted-foreground">{{ employeeMatches.length }} employé{{ employeeMatches.length > 1 ? 's' : '' }} trouvé{{ employeeMatches.length > 1 ? 's' : '' }}</div>
                                <button v-for="employee in employeeMatches" :key="employee.uuid" type="button" :disabled="!employeeSelectable(employee)" :class="['grid w-full gap-3 border-b border-border px-4 py-3.5 text-start transition last:border-0 sm:grid-cols-[44px_minmax(0,1fr)_auto] sm:items-center', employeeSelectable(employee) ? 'hover:bg-primary/5' : 'cursor-not-allowed bg-muted/30 opacity-60']" @click="chooseEmployee(employee)"><Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(employee)" /><span class="min-w-0"><span class="block truncate text-sm font-bold text-foreground">{{ formatPatientName(employee) }}</span><span class="mt-0.5 block text-xs text-muted-foreground"><span class="font-mono">{{ employee.employee_number }}</span> · {{ employee.profession || 'Fonction non renseignée' }}</span></span><span :class="['inline-flex items-center rounded-full px-2 py-1 text-[11px] font-bold', employeeSelectable(employee) ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300']"><component :is="employeeSelectable(employee) ? CircleCheck : CircleAlert" class="h-4 w-4" />{{ employeeSelectable(employee) ? 'Sélectionner' : 'Relié à un autre Patient' }}</span></button>
                            </div>

                            <div v-else-if="employeeSearchPerformed && !employeeSearchLoading" class="mt-4 rounded-md border border-dashed border-border px-5 py-7 text-center"><Search class="mx-auto h-6 w-6 text-muted-foreground" /><p class="mt-2 text-sm font-semibold text-foreground">Aucun Employé RH trouvé</p><p class="mt-1 text-xs text-muted-foreground">Vérifiez le matricule, l’identité ou le numéro saisi.</p></div>
                            <FormError v-if="firstError(financialErrors, 'employee_uuid')" class="mt-2">{{ firstError(financialErrors, 'employee_uuid') }}</FormError>
                        </div>
                    </div>
                </section>

                <section v-if="financialMode === 'PARTNER'" class="mt-5 w-full overflow-hidden rounded-md border border-border">
                    <div class="flex items-start gap-3 border-b border-border bg-muted/35 px-5 py-4"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-lg text-primary"><Link2 class="h-4 w-4" /></span><div><h3 class="text-sm font-bold text-foreground">Partenaire de cet Episode</h3><p class="mt-1 text-xs text-muted-foreground">Sélectionnez un organisme partenaire existant pour ce passage.</p></div></div>
                    <div class="grid gap-4 p-5 md:grid-cols-2">
                        <FormField class="md:col-span-2" label="Organisme partenaire" required :error="firstError(financialErrors, 'partner_organization_uuid')">
                            <Select v-model="partnerForm.partner_organization_uuid" class="h-11 w-full" :options="partnerSelectOptions" placeholder="Choisir un partenaire" :aria-invalid="Boolean(firstError(financialErrors, 'partner_organization_uuid'))" />
                        </FormField>
                    </div>
                </section>

                <FormError v-if="! pharmacyOnlyCart && firstError(financialErrors, 'financial_mode')" class="mt-4">{{ firstError(financialErrors, 'financial_mode') }}</FormError>
                <FormError v-if="pharmacyOnlyCart && firstError(finalForm.errors, 'catalog_lines')" class="mt-4">{{ firstError(finalForm.errors, 'catalog_lines') }}</FormError>
                <!-- Masqué pour un panier de médicaments seuls : ce bouton
                     calcule une couverture sur les prestations, et il n'y en
                     a aucune. La suite de ce passage est juste au-dessus. -->
                <div v-if="! pharmacyOnlyCart" class="mt-6 flex justify-end border-t border-border pt-5"><Button size="lg" :disabled="financialLoading || (financialMode === 'MUTUAL' && (!mutualForm.mutual_organization_uuid || !mutualForm.employer_name)) || (financialMode === 'STAFF' && !selectedEmployee) || (financialMode === 'PARTNER' && !partnerForm.partner_organization_uuid)" @click="configureFinancialContext">{{ financialLoading ? 'Calcul Laravel…' : 'Calculer la prise en charge' }}<ArrowRight class="h-4 w-4" /></Button></div>
            </CardBody>

            <CardBody v-else-if="currentStep === 6" class="!p-5 lg:!p-7">
                <div>
                    <h2 class="font-heading text-2xl font-bold text-foreground">Confirmer la prise en charge</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Montants recalculés depuis le contexte de l’Episode. La confirmation fige les prestations puis déclenche le routage.</p>
                </div>

                <div class="mt-6 grid gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
                    <section class="space-y-4">
                        <div class="overflow-hidden rounded-md border border-border">
                            <div class="flex items-center gap-3 border-b border-border bg-muted/30 px-4 py-3">
                                <Avatar rounded size="rg" variant="primary-pale" :text="formatPatientInitials(selectedPatient)" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="truncate text-base font-bold text-foreground">{{ formatPatientCivilName(selectedPatient) }}</p>
                                        <span v-if="selectedPatient.civility_label && !['MR', 'MRS'].includes(selectedPatient.civility)" class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">{{ selectedPatient.civility_label }}</span>
                                        <span v-if="episode.priority === 'EMERGENCY'" class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-bold text-red-700 dark:bg-red-950/50 dark:text-red-300"><Activity class="h-3.5 w-3.5" />Urgence</span>
                                    </div>
                                    <div class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                                        <span class="font-mono font-semibold">{{ selectedPatient.patient_number }}</span>
                                        <span v-if="formatPatientAge(selectedPatient)" class="inline-flex items-center gap-1"><CalendarDays class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />{{ formatPatientAge(selectedPatient) }}</span>
                                        <span v-if="formatPatientBirthDate(selectedPatient)">né(e) le {{ formatPatientBirthDate(selectedPatient) }}</span>
                                        <span v-if="selectedPatient.phone" class="inline-flex items-center gap-1"><Phone class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />{{ selectedPatient.phone }}</span>
                                    </div>
                                </div>
                            </div>
                            <dl class="grid divide-y divide-border text-sm sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                                <div class="px-4 py-3">
                                    <dt class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Passage</dt>
                                    <dd class="mt-1 font-mono font-bold text-foreground">{{ episode.episode_number }}</dd>
                                    <dd class="text-xs text-muted-foreground">Un seul passage pour toutes les prestations</dd>
                                </div>
                                <div class="px-4 py-3">
                                    <dt class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Mode financier</dt>
                                    <dd class="mt-1 font-bold text-foreground">{{ modeLabel }}</dd>
                                    <dd v-if="preview?.organization_name" class="text-xs text-muted-foreground">{{ preview.organization_name }}<template v-if="preview.coverage_rate !== null"> · {{ Number(preview.coverage_rate).toLocaleString('fr-FR') }} %</template><template v-else> · Facturation en attente</template></dd>
                                </div>
                            </dl>
                        </div>

                        <div class="overflow-hidden rounded-md border border-border">
                            <div v-if="designationDeferred" class="flex items-start gap-3 px-4 py-5">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-950/30"><Info class="h-4 w-4" /></span>
                                <div>
                                    <p class="text-sm font-bold text-foreground">Besoin à définir après évaluation</p>
                                    <p class="mt-1 text-xs text-muted-foreground">Prestation et montant à définir après l’évaluation. Destination initiale : Soins.</p>
                                </div>
                            </div>
                            <template v-else>
                                <div class="hidden grid-cols-[minmax(0,1fr)_60px_repeat(3,minmax(90px,110px))] gap-3 border-b border-border bg-muted/30 px-4 py-2.5 text-[10px] font-bold uppercase tracking-wide text-muted-foreground sm:grid">
                                    <span>Désignation</span><span class="text-center">Qté</span><span class="text-end">Brut</span><span class="text-end">Couverture</span><span class="text-end">Patient</span>
                                </div>
                                <div v-for="line in previewLines" :key="line.catalog_item_uuid" class="grid gap-1 border-b border-border px-4 py-3 last:border-0 sm:grid-cols-[minmax(0,1fr)_60px_repeat(3,minmax(90px,110px))] sm:gap-3 sm:items-center">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-bold text-foreground">{{ line.name }}</p>
                                        <p class="truncate text-xs text-muted-foreground">{{ line.code }} · {{ line.routing_label }}</p>
                                    </div>
                                    <p class="text-xs text-muted-foreground sm:text-center sm:text-sm">× {{ Number(line.quantity).toLocaleString('fr-FR') }}</p>
                                    <p class="text-sm font-semibold text-foreground sm:text-end"><span class="text-[10px] font-bold uppercase text-muted-foreground sm:hidden">Brut </span>{{ line.gross_amount ? formatMoney(line.gross_amount) : 'En attente' }}</p>
                                    <p class="text-sm font-semibold text-emerald-600 sm:text-end"><span class="text-[10px] font-bold uppercase text-muted-foreground sm:hidden">Couverture </span>{{ line.coverage_amount !== null ? formatMoney(line.coverage_amount) : 'En attente' }}</p>
                                    <p class="text-sm font-bold text-foreground sm:text-end"><span class="text-[10px] font-bold uppercase text-muted-foreground sm:hidden">Patient </span>{{ line.patient_amount !== null ? formatMoney(line.patient_amount) : 'En attente' }}</p>
                                </div>
                            </template>
                        </div>

                        <div v-if="preview?.totals.resolution_pending" class="rounded-md border border-amber-200 bg-amber-50/70 px-4 py-3 dark:border-amber-900 dark:bg-amber-950/20">
                            <p class="flex items-start gap-2 text-xs leading-5 text-amber-800 dark:text-amber-200"><CircleAlert class="h-4 w-4 mt-0.5 shrink-0" /><span>Une ligne reste financièrement en attente (tarif manquant ou politique Personnel non classifiée). Sa demande clinique et son routage seront conservés ; aucun montant ne sera inventé.</span></p>
                        </div>
                    </section>

                    <aside class="space-y-3 self-start xl:sticky xl:top-20">
                        <div class="overflow-hidden rounded-md border border-border">
                            <p class="border-b border-border bg-muted/30 px-5 py-2.5 text-[11px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Récapitulatif financier</p>
                            <dl class="divide-y divide-border text-sm">
                                <div class="flex items-center justify-between px-5 py-3"><dt class="text-muted-foreground">Montant brut</dt><dd class="font-bold text-foreground">{{ preview?.totals.gross_amount ? formatMoney(preview.totals.gross_amount) : '—' }}</dd></div>
                                <div class="flex items-center justify-between px-5 py-3"><dt class="text-muted-foreground">Couverture</dt><dd class="font-bold text-emerald-600 dark:text-emerald-400">{{ preview && preview.totals.coverage_amount !== null ? formatMoney(preview.totals.coverage_amount) : '—' }}</dd></div>
                            </dl>
                            <div class="border-t border-amber-200 bg-amber-50 px-5 py-4 dark:border-amber-900 dark:bg-amber-950/30">
                                <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-amber-700 dark:text-amber-300">Reste à la charge du patient</p>
                                <p class="mt-1 font-heading text-3xl font-bold text-amber-800 dark:text-amber-200">{{ preview && preview.totals.patient_amount !== null ? formatMoney(preview.totals.patient_amount) : '—' }}</p>
                            </div>
                            <div class="flex items-center gap-3 border-t border-border px-5 py-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"><ArrowRight class="h-4 w-4" /></span>
                                <div>
                                    <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Destination initiale</p>
                                    <p class="font-bold text-foreground">{{ designationDeferred ? 'Soins' : (preview?.initial_destination?.label || 'À calculer') }}</p>
                                </div>
                            </div>
                        </div>

                        <FormError v-if="finalForm.errors.catalog_lines || finalForm.errors.financial_mode">{{ finalForm.errors.catalog_lines || finalForm.errors.financial_mode }}</FormError>

                        <Button class="w-full justify-center" size="rg" :disabled="finalForm.processing" @click="confirmCare"><Check class="h-4 w-4" />{{ finalForm.processing ? 'Confirmation…' : 'Confirmer la prise en charge' }}</Button>
                        <Button class="w-full justify-center" size="rg" variant="white-outline" :disabled="finalForm.processing" @click="currentStep = 5"><ArrowLeft class="h-4 w-4" />Modifier le mode</Button>
                        <p class="px-1 text-center text-xs leading-5 text-muted-foreground">Aucun paiement n’est encaissé ici : la facture sera réglée à la Caisse.</p>
                    </aside>
                </div>
            </CardBody>
        </Card>

        <Dialog
            :open="showEmergencyConfirm"
            title="Classer ce passage en urgence ?"
            :description="`Passage ${episode?.episode_number ?? ''}`"
            close-label="Fermer la confirmation"
            @update:open="(value) => (value ? null : closeEmergencyConfirm())"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-red-50 text-red-600 dark:bg-red-950/30 dark:text-red-300">
                    <Siren class="h-5 w-5" />
                </span>
            </template>

            <p class="text-sm leading-6 text-muted-foreground">
                L’urgence est une priorité de ce passage, jamais du dossier patient : les autres passages de cette personne
                ne changent pas. Les files Soins et Médecine sont ouvertes immédiatement, sans attendre le règlement.
            </p>
            <FormError :message="emergencyForm.errors.episode" />

            <template #footer>
                <Button type="button" variant="outline" :disabled="emergencyForm.processing" @click="closeEmergencyConfirm">Annuler</Button>
                <Button type="button" variant="destructive" :disabled="emergencyForm.processing" @click="confirmMarkEmergency">
                    <Siren class="h-4 w-4" />{{ emergencyForm.processing ? 'Classement…' : 'Confirmer l’urgence' }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
