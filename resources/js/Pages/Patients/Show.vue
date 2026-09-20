<script setup>
import ClinicalRichTextDisplay from '@/Components/Clinical/ClinicalRichTextDisplay.vue';
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Baby,
    Ban,
    Banknote,
    CalendarDays,
    Check,
    CircleAlert,
    CircleCheck,
    CircleDollarSign,
    Clock3,
    Eye,
    FilePlus2,
    FileHeart,
    FileText,
    FolderOpen,
    History,
    IdCard,
    Info,
    LayoutGrid,
    List,
    ListChecks,
    Lock,
    Mail,
    MapPin,
    NotebookText,
    Pencil,
    Phone,
    Plus,
    Printer,
    ReceiptText,
    Search,
    ShieldCheck,
    Trash2,
    UserRound,
    UserRoundCheck,
    WalletCards,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import EpisodePathwayTrail from '@/Components/Clinical/EpisodePathwayTrail.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Avatar from '@/Components/Shadcn/Avatar.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatDateTime } from '@/utilities/date';
import { presenceCountsFromEpisodes, presenceState } from '@/utilities/episodePresence';
import { formatMoney } from '@/utilities/money';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    patient: Object,
    /** ADR-144 — de qui ce patient est le bébé, et ses propres enfants nés à la clinique. */
    family: { type: Object, default: () => ({ mother: null, children: [] }) },
    account: Object,
    paymentMethods: Array,
    openCashSessions: { type: Array, default: () => [] },
    billingCatalog: Array,
});

const { can } = usePermissions();

const billingCatalogOptions = computed(() => (props.billingCatalog ?? []).map((catalogItem) => ({
    value: catalogItem.uuid,
    label: `${catalogItem.code} · ${catalogItem.name} — patient ${formatMoney(catalogItem.patient_amount ?? catalogItem.tariff_amount)}`,
})));
const cashRegisterOptions = computed(() => props.openCashSessions.map((session) => ({
    value: session.register_uuid ?? '',
    label: session.register_name ?? session.session_number,
})));
const paymentMethodOptions = computed(() => (props.paymentMethods ?? []).map((method) => ({
    value: String(method.id),
    label: method.name,
})));
// Le Select travaille en chaînes ; `payment_method_id` doit rester un entier,
// comme l'attend le reste de ce formulaire et la validation serveur.
const setPaymentMethod = (value) => {
    paymentForm.payment_method_id = value === '' ? '' : Number(value);
};
const validSections = ['overview', 'billing', 'episodes'];
const requestedSection = typeof window !== 'undefined'
    ? new URLSearchParams(window.location.search).get('section')
    : null;
const activeSection = ref(validSections.includes(requestedSection) ? requestedSection : 'overview');
const showInvoiceForm = ref(false);
const paymentTarget = ref(null);
const cancellationTarget = ref(null);
const validatingInvoice = ref(null);
const mutualAttachmentsOpen = ref(false);
const episodeQuery = ref('');
const episodeStatusFilter = ref('');
const episodeUrgencyFilter = ref('');
const episodeStatusOptions = [
    { value: '', label: 'Tous les statuts' },
    { value: 'OPEN', label: 'Ouvert' },
    { value: 'CLOSED', label: 'Clos' },
    { value: 'CANCELLED', label: 'Annulé' },
];
const episodeUrgencyOptions = [
    { value: '', label: 'Toutes les priorités' },
    { value: 'emergency', label: 'Urgence' },
    { value: 'normal', label: 'Priorité normale' },
];
const readStoredEpisodeViewMode = () => {
    try {
        return localStorage.getItem('rivo:patient-episodes:view');
    } catch {
        return null;
    }
};
const episodeViewMode = ref('list');
// Applied once the browser has the page: the server renders the default
// view, and reading storage while rendering would not match it (hydration).
onMounted(() => {
    episodeViewMode.value = readStoredEpisodeViewMode() ?? 'list';
});
const setEpisodeViewMode = (mode) => {
    episodeViewMode.value = mode;
    try {
        localStorage.setItem('rivo:patient-episodes:view', mode);
    } catch {
        // Private browsing or storage disabled — the toggle still works for
        // this visit, it just won't be remembered next time.
    }
};

const activeEmergencyEpisode = computed(() => props.patient.episodes.find(
    (episode) => episode.status === 'OPEN' && episode.priority === 'EMERGENCY',
));
// Le même mot, la même couleur qu'au répertoire (Patients/Index.vue) pour le
// même fait : un dossier ouvert depuis la liste ne doit jamais paraître
// contredire ce que la ligne venait d'annoncer.
const patientPresence = computed(() => presenceState(presenceCountsFromEpisodes(props.patient.episodes)));
// Le passage le plus « chaud » du patient : c'est lui qui explique le badge
// ci-dessus quand un seul passage est ouvert — le cas de très loin le plus
// fréquent. Plusieurs passages ouverts à la fois restent lisibles dans
// l'onglet Passages, sans que l'en-tête ne tente de tous les résumer.
const activeOpenEpisode = computed(() => {
    const open = props.patient.episodes.filter((episode) => episode.status === 'OPEN');

    return open.length === 1 ? open[0] : null;
});
const latestEpisode = computed(() => props.patient.episodes[0] ?? null);
const activeMutualCoverage = computed(() => props.patient.active_mutual_coverage ?? null);
const activeStaffLink = computed(() => props.patient.active_staff_link ?? null);
const displayedAge = computed(() => props.patient.declared_age ?? props.patient.age ?? null);
const birthSummary = computed(() => {
    if (props.patient.birth_date) {
        return formatDate(props.patient.birth_date);
    }

    return displayedAge.value !== null && displayedAge.value !== undefined
        ? `${displayedAge.value} ans`
        : 'Non renseigné';
});
const birthLabel = computed(() => (props.patient.birth_date ? 'Date de naissance' : 'Âge'));

const defaultEpisodeUuid = props.patient.episodes.find((episode) => episode.status !== 'CANCELLED')?.uuid ?? '';
const invoiceForm = useForm({
    episode_uuid: defaultEpisodeUuid,
    billable_item_uuids: [],
    catalog_lines: [],
});

const paymentForm = useForm({
    invoice_uuid: '',
    payment_method_id: props.paymentMethods?.[0]?.id ?? '',
    amount: '',
    reference: '',
    notes: '',
    cash_register_uuid: props.openCashSessions.length === 1 ? props.openCashSessions[0].register_uuid ?? '' : '',
});

const cancellationForm = useForm({ reason: '' });

const pendingItemsForEpisode = computed(() => (props.account?.billable_items ?? []).filter(
    (item) => item.status === 'PENDING' && item.episode.uuid === invoiceForm.episode_uuid,
));
// Every submission of the "Nouvelle facture" form creates its own separate
// Invoice (CreateInvoiceAction never merges into an existing one) — this
// surfaces that up front so Reception isn't surprised to see a second
// AF-xxxxx appear for a passage that already has one unpaid.
const openInvoicesForEpisode = computed(() => (props.account?.invoices ?? []).filter(
    (invoice) => invoice.episode.uuid === invoiceForm.episode_uuid && Number(invoice.balance_amount) > 0,
));
const openInvoicesForEpisodeTotal = computed(() => openInvoicesForEpisode.value.reduce(
    (total, invoice) => total + Number(invoice.balance_amount), 0,
));
const cancelledBillableItems = computed(() => (props.account?.billable_items ?? []).filter(
    (item) => item.status === 'CANCELLED',
));
// "Déjà caissé ou payé" — fully settled documents move to their own sidebar
// so the main list stays focused on what still needs action (à payer,
// brouillon, paiement partiel…).
const settledInvoices = computed(() => (props.account?.invoices ?? []).filter(
    (invoice) => ['PAID', 'COVERED'].includes(invoice.status),
));
// "Nouvelle facture" is scoped per passage instead of one global button with
// a dropdown — every passage that still has something unsettled (an open
// invoice or a pending prestation) gets its own group and its own button,
// already pointed at the right episode.
const passageGroups = computed(() => {
    const groups = new Map();
    const groupFor = (episode) => {
        if (!groups.has(episode.uuid)) {
            groups.set(episode.uuid, { episode, invoices: [], pendingItems: [] });
        }

        return groups.get(episode.uuid);
    };

    (props.account?.invoices ?? [])
        .filter((invoice) => !['PAID', 'COVERED'].includes(invoice.status))
        .forEach((invoice) => groupFor(invoice.episode).invoices.push(invoice));

    (props.account?.billable_items ?? [])
        .filter((item) => item.status === 'PENDING')
        .forEach((item) => groupFor(item.episode).pendingItems.push(item));

    return Array.from(groups.values()).sort(
        (a, b) => (a.episode.episode_number < b.episode.episode_number ? 1 : -1),
    );
});
/**
 * Les prestations déjà transmises par les services (Soins, Laboratoire,
 * Imagerie…) sont dans le panier du passage : elles s'affichent d'emblée, et
 * « Facturer » les porte toutes sur une facture en un clic. « Nouvelle
 * facture » reste pour ajouter autre chose (demande du patient,
 * recommandation du médecin). Le serveur relit chaque tarif.
 */
const pendingTotal = (items) => items.reduce((total, item) => total + Number(item.patient_amount ?? item.total_amount), 0);
const billingPendingFor = ref(null);
const pendingInvoiceForm = useForm({ episode_uuid: '', billable_item_uuids: [] });
const invoicePendingItems = (group) => {
    pendingInvoiceForm.episode_uuid = group.episode.uuid;
    pendingInvoiceForm.billable_item_uuids = group.pendingItems.map((item) => item.uuid);
    billingPendingFor.value = group.episode.uuid;
    pendingInvoiceForm.post(`/patients/${props.patient.uuid}/invoices`, {
        preserveScroll: true,
        onFinish: () => { billingPendingFor.value = null; },
    });
};

const toggleInvoiceFormFor = (episodeUuid) => {
    if (showInvoiceForm.value && invoiceForm.episode_uuid === episodeUuid) {
        showInvoiceForm.value = false;
        return;
    }

    invoiceForm.episode_uuid = episodeUuid;
    // Ce qui attend déjà est coché d'office : la Réception retire ce qu'elle
    // ne veut pas facturer maintenant, au lieu de tout cocher à la main.
    invoiceForm.billable_item_uuids = (props.account?.billable_items ?? [])
        .filter((item) => item.status === 'PENDING' && item.episode.uuid === episodeUuid)
        .map((item) => item.uuid);
    showInvoiceForm.value = true;
};
const catalogByUuid = computed(() => new Map(
    (props.billingCatalog ?? []).map((item) => [item.uuid, item]),
));

const invoiceDraftTotal = computed(() => {
    const selectedItemsTotal = pendingItemsForEpisode.value
        .filter((item) => invoiceForm.billable_item_uuids.includes(item.uuid))
        .reduce((total, item) => total + Number(item.patient_amount ?? item.total_amount), 0);
    const catalogLinesTotal = invoiceForm.catalog_lines.reduce(
        (total, line) => total
            + (Number(line.quantity) || 0) * Number(
                catalogByUuid.value.get(line.catalog_item_uuid)?.patient_amount
                    ?? catalogByUuid.value.get(line.catalog_item_uuid)?.tariff_amount
                    ?? 0,
            ),
        0,
    );

    return selectedItemsTotal + catalogLinesTotal;
});

const addInvoiceLine = () => invoiceForm.catalog_lines.push({
    catalog_item_uuid: '',
    quantity: 1,
    idempotency_key: crypto.randomUUID(),
});
const removeInvoiceLine = (index) => {
    invoiceForm.catalog_lines.splice(index, 1);
};

const createInvoice = () => {
    invoiceForm.transform((data) => ({
        ...data,
        billable_item_uuids: data.billable_item_uuids.filter((uuid) => (
            pendingItemsForEpisode.value.some((item) => item.uuid === uuid)
        )),
        catalog_lines: data.catalog_lines.filter((line) => line.catalog_item_uuid),
    }));
    invoiceForm.post(`/patients/${props.patient.uuid}/invoices`, {
        preserveScroll: true,
        onSuccess: () => {
            showInvoiceForm.value = false;
            invoiceForm.billable_item_uuids = [];
            invoiceForm.catalog_lines = [];
        },
    });
};

const validateInvoice = (invoice) => {
    validatingInvoice.value = invoice.uuid;
    router.post(`/invoices/${invoice.uuid}/validate`, {}, {
        preserveScroll: true,
        onFinish: () => { validatingInvoice.value = null; },
    });
};

const openPaymentDialog = (invoice) => {
    paymentTarget.value = invoice;
    paymentForm.clearErrors();
    paymentForm.invoice_uuid = invoice.uuid;
    paymentForm.payment_method_id = props.paymentMethods?.[0]?.id ?? '';
    paymentForm.amount = invoice.balance_amount;
    paymentForm.reference = '';
    paymentForm.notes = '';
    paymentForm.cash_register_uuid = props.openCashSessions.length === 1 ? props.openCashSessions[0].register_uuid ?? '' : '';
};

const closePaymentDialog = () => {
    if (!paymentForm.processing) paymentTarget.value = null;
};
const handlePaymentDialogOpen = (open) => {
    if (!open) closePaymentDialog();
};

const recordPayment = () => {
    paymentForm.post(`/patients/${props.patient.uuid}/payments`, {
        preserveScroll: true,
        onSuccess: () => { paymentTarget.value = null; },
    });
};

const openCancellationDialog = (payment) => {
    cancellationTarget.value = payment;
    cancellationForm.clearErrors();
    cancellationForm.reason = '';
};

const closeCancellationDialog = () => {
    if (!cancellationForm.processing) cancellationTarget.value = null;
};
const handleCancellationDialogOpen = (open) => {
    if (!open) closeCancellationDialog();
};

const cancelPayment = () => {
    cancellationForm.post(`/payments/${cancellationTarget.value.uuid}/cancel`, {
        preserveScroll: true,
        onSuccess: () => { cancellationTarget.value = null; },
    });
};

const sexLabel = (sex) => (sex === 'M' ? 'Masculin' : 'Féminin');
const civilityLabels = { MR: 'M.', MRS: 'Mme', GIRL: 'Enfant fille', BOY: 'Enfant garçon' };
const patientTypeLabels = {
    STANDARD: 'Patient standard',
    MUTUAL: 'Patient avec mutuelle',
    STAFF: 'Personnel de la clinique',
};
const maritalStatusLabels = {
    SINGLE: 'Célibataire',
    MARRIED: 'Marié(e)',
    DIVORCED: 'Divorcé(e)',
    WIDOWED: 'Veuf / Veuve',
};
const patientTypeLabel = computed(() => patientTypeLabels[props.patient.patient_type] ?? 'Patient standard');
const maritalStatusLabel = computed(() => maritalStatusLabels[props.patient.marital_status] ?? 'Non renseignée');
const childrenCountLabel = computed(() => (
    props.patient.children_count !== null && props.patient.children_count !== undefined
        ? props.patient.children_count
        : 'Non renseigné'
));
const beneficiaryTypeLabels = {
    PRINCIPAL: 'Principal',
    FAMILY_MEMBER: 'Membre de famille',
};
const administrativeStatusLabels = {
    PENDING_ORIENTATION: 'En attente aux Soins',
    ORIENTED: 'Orienté',
    IN_CARE: 'En cours de soins',
    PENDING_SETTLEMENT: 'En attente de règlement',
    // CDC §33.3 — les trois sorties administratives réelles. DISCHARGED est
    // l'ancien fourre-tout, jamais écrit depuis l'ADR-090 mais conservé
    // lisible.
    DISCHARGED: 'Sorti',
    DISCHARGED_PAID: 'Sorti — payé comptant',
    DISCHARGED_DEBT: 'Sorti — dette validée',
    DISCHARGED_ESCAPED: 'Sorti — évadé',
};
const pathwayStatus = (episode) => {
    const orientations = episode.orientations ?? [];
    const medicine = orientations.find((orientation) => orientation.destination_module === 'MEDICINE'
        && ['PENDING', 'IN_PROGRESS'].includes(orientation.status));
    const care = orientations.find((orientation) => orientation.destination_module === 'CARE'
        && ['PENDING', 'IN_PROGRESS'].includes(orientation.status));

    if (medicine?.status === 'IN_PROGRESS') return 'En consultation';
    if (medicine) return 'En attente en Médecine';
    if (care?.status === 'IN_PROGRESS') return 'Pris en charge aux Soins';
    if (care) return 'En attente aux Soins';

    // ADR-030 CARE_ONLY : le parcours peut se terminer entièrement aux
    // Soins, sans jamais créer d'orientation Médecine. administrative_status
    // reste alors IN_CARE indéfiniment (rien ne le fait avancer ensuite) —
    // sans ce cas, le texte retombait sur « En cours de soins » pour un
    // passage en réalité terminé.
    const careCompleted = orientations.some((orientation) => orientation.destination_module === 'CARE'
        && orientation.status === 'COMPLETED');
    const medicineOrientationExists = orientations.some((orientation) => orientation.destination_module === 'MEDICINE');
    if (careCompleted && !medicineOrientationExists) return 'Soins terminés';

    return administrativeStatusLabels[episode.administrative_status] ?? episode.administrative_status;
};
/**
 * Un statut de parcours se dit par une variante de pastille et une icône :
 * les couleurs suivent le thème (shadcn), jamais une palette recopiée ici.
 * « En attente de règlement » garde la même teinte que sur le répertoire des
 * patients et sur « Sorties & règlements » — la partie clinique est finie,
 * la Réception doit encore prononcer la sortie (CDC §33.3).
 */
const PATHWAY_BADGES = {
    'Soins terminés': { variant: 'success', icon: CircleCheck },
    'En consultation': { variant: 'secondary', icon: Activity },
    'Pris en charge aux Soins': { variant: 'secondary', icon: UserRoundCheck },
    'En attente en Médecine': { variant: 'warning', icon: Clock3 },
    'En attente aux Soins': { variant: 'warning', icon: Clock3 },
    'En attente de règlement': { variant: 'secondary', icon: WalletCards },
    'Sorti': { variant: 'success', icon: CircleCheck },
    'Sorti — payé comptant': { variant: 'success', icon: CircleCheck },
    'Sorti — dette validée': { variant: 'warning', icon: AlertTriangle },
    'Sorti — évadé': { variant: 'destructive', icon: AlertTriangle },
};
const pathwayBadge = (status) => PATHWAY_BADGES[status] ?? { variant: 'outline', icon: ListChecks };
// Actively-happening-right-now passages surface first regardless of when
// they started — a patient currently in consultation matters more at a
// glance than one whose (later-started) passage already finished at Soins.
const pathwayUrgencyRank = (episode) => {
    const status = pathwayStatus(episode);
    if (['En consultation', 'Pris en charge aux Soins'].includes(status)) return 0;
    if (['En attente en Médecine', 'En attente aux Soins'].includes(status)) return 1;
    return 2;
};
const recentEpisodes = computed(() => [...props.patient.episodes]
    .sort((a, b) => pathwayUrgencyRank(a) - pathwayUrgencyRank(b))
    .slice(0, 4));
const filteredEpisodes = computed(() => {
    const needle = episodeQuery.value.trim().toLocaleLowerCase('fr');

    return [...props.patient.episodes]
        .filter((episode) => {
            if (needle && !episode.episode_number.toLocaleLowerCase('fr').includes(needle)) return false;
            if (episodeStatusFilter.value && episode.status !== episodeStatusFilter.value) return false;
            if (episodeUrgencyFilter.value === 'emergency' && episode.priority !== 'EMERGENCY') return false;
            if (episodeUrgencyFilter.value === 'normal' && episode.priority === 'EMERGENCY') return false;
            return true;
        })
        .sort((a, b) => pathwayUrgencyRank(a) - pathwayUrgencyRank(b));
});
// Per-passage clinical snapshot for the Passages tab — distinct from the
// permanent dossier's current allergies/antecedents (Aperçu tab): this is
// what Soins actually recorded during THIS specific passage.
const careVitalsSummary = (record) => {
    if (!record) return [];
    const rows = [];
    const bloodPressure = (systolic, diastolic) => (systolic || diastolic ? `${systolic ?? '—'}/${diastolic ?? '—'} mmHg` : null);
    const pressure = bloodPressure(record.blood_pressure_systolic, record.blood_pressure_diastolic);
    if (pressure) rows.push({ label: 'Tension artérielle', value: pressure });
    if (record.heart_rate) rows.push({ label: 'Fréquence cardiaque', value: `${record.heart_rate} btt/mn` });
    if (record.spo2 !== null && record.spo2 !== undefined) rows.push({ label: 'SpO2', value: `${record.spo2} %` });
    if (record.temperature_celsius) rows.push({ label: 'Température', value: `${record.temperature_celsius} °C` });
    if (record.height_cm) rows.push({ label: 'Taille', value: `${record.height_cm} cm` });
    if (record.weight_kg) rows.push({ label: 'Poids', value: `${record.weight_kg} kg` });
    if (record.bmi) rows.push({ label: 'IMC', value: record.bmi });
    if (record.blood_group) rows.push({ label: 'Groupe sanguin', value: record.blood_group });
    if (record.known_diabetes !== null && record.known_diabetes !== undefined) rows.push({ label: 'Diabète connu', value: record.known_diabetes ? 'Oui' : 'Non' });
    if (record.smoker !== null && record.smoker !== undefined) rows.push({ label: 'Tabac', value: record.smoker ? 'Oui' : 'Non' });
    if (record.alcohol !== null && record.alcohol !== undefined) rows.push({ label: 'Alcool', value: record.alcohol ? 'Oui' : 'Non' });
    return rows;
};
const statusLabels = { OPEN: 'Ouvert', CLOSED: 'Clos', CANCELLED: 'Annulé' };
const invoiceStatusLabels = {
    DRAFT: 'Brouillon',
    VALIDATED: 'À payer',
    PARTIALLY_PAID: 'Paiement partiel',
    PAID: 'Payée',
    COVERED: 'Prise en charge',
    CANCELLED: 'Annulée',
};
const severityLabels = { MILD: 'Légère', MODERATE: 'Modérée', SEVERE: 'Sévère' };
const moduleLabels = {
    RECEPTION: 'Réception',
    CARE: 'Soins',
    MEDICINE: 'Médecine',
    LABORATORY: 'Laboratoire',
    SURGERY: 'Chirurgie',
    PHARMACY: 'Pharmacie',
    IMAGING: 'Imagerie',
    MATERNITY: 'Maternité',
    HOSPITALIZATION: 'Hospitalisation',
    TRANSFER: 'Transfert',
    PEDIATRICS: 'Pédiatrie',
};
/**
 * « Y a-t-il un problème de paiement ? » pour CE seul passage — jamais
 * l'agrégat de tous les passages du patient, déjà affiché par ailleurs
 * (carte « Situation financière »). Relit ce que la page a déjà chargé
 * (`account`, gardé par `billing.view`) : aucune requête supplémentaire,
 * aucun montant recalculé qui pourrait diverger de celui de la Caisse.
 */
const episodeBilling = (episode) => {
    if (!props.account) return null;

    const invoices = props.account.invoices.filter((invoice) => invoice.episode.uuid === episode.uuid && invoice.status !== 'CANCELLED');
    const pendingItems = props.account.billable_items.filter((item) => item.episode.uuid === episode.uuid && item.status === 'PENDING');

    if (invoices.length === 0 && pendingItems.length === 0) {
        return { hasActivity: false, balanceAmount: 0, pendingCount: 0, pendingAmount: 0 };
    }

    return {
        hasActivity: true,
        totalAmount: invoices.reduce((sum, invoice) => sum + Number(invoice.total_amount), 0),
        paidAmount: invoices.reduce((sum, invoice) => sum + Number(invoice.paid_amount), 0),
        balanceAmount: invoices.reduce((sum, invoice) => sum + Number(invoice.balance_amount), 0),
        pendingCount: pendingItems.length,
        pendingAmount: pendingItems.reduce((sum, item) => sum + Number(item.patient_amount ?? item.total_amount), 0),
    };
};

const formatQuantity = (quantity) => Number(quantity).toLocaleString('fr-FR', {
    maximumFractionDigits: 2,
});
const formatFileSize = (bytes) => {
    if (!bytes) return '';

    return bytes >= 1024 * 1024
        ? `${(bytes / (1024 * 1024)).toFixed(1)} Mo`
        : `${Math.max(1, Math.round(bytes / 1024))} Ko`;
};
const mutualAttachmentUrl = (attachment) => attachment.url
    ?? `/reception/mutual-coverages/${activeMutualCoverage.value.uuid}/attachments/${attachment.uuid}`;

const EPISODE_STATUSES = {
    OPEN: { variant: 'outline', icon: FolderOpen, bubble: 'bg-primary/10 text-primary' },
    CLOSED: { variant: 'secondary', icon: Lock, bubble: 'bg-muted text-muted-foreground' },
    CANCELLED: { variant: 'destructive', icon: Ban, bubble: 'bg-red-50 text-red-600 dark:bg-red-950/20 dark:text-red-300' },
};
const episodeStatus = (statusValue) => EPISODE_STATUSES[statusValue] ?? EPISODE_STATUSES.OPEN;

const INVOICE_VARIANTS = {
    DRAFT: 'outline',
    VALIDATED: 'warning',
    PARTIALLY_PAID: 'warning',
    COVERED: 'success',
    PAID: 'success',
    CANCELLED: 'destructive',
};
const invoiceVariant = (statusValue) => INVOICE_VARIANTS[statusValue] ?? 'outline';

// Une case de la liste « Prestations en attente » : le composant ne connaît
// qu'un booléen, la facture porte la liste des lignes cochées.
const toggleBillable = (uuid, checked) => {
    invoiceForm.billable_item_uuids = checked
        ? [...new Set([...invoiceForm.billable_item_uuids, uuid])]
        : invoiceForm.billable_item_uuids.filter((selected) => selected !== uuid);
};

// Tous les « Dossier médical – Traitement » du patient, un par passage : un
// seul document que l'on relit à l'écran puis enregistre en un seul PDF
// (ADR-118). Le lien n'apparaît que s'il y a au moins un passage à réunir.
const journalsUrl = computed(() => `/patients/${props.patient.uuid}/journaux-de-traitement`);
const canOpenJournals = computed(() => can('treatment_journal.view') && props.patient.episodes.length > 0);
const passageJournalUrl = (episode) => `/passages/${episode.uuid}/journal`;
const invoiceFormOpenFor = (episodeUuid) => showInvoiceForm.value && invoiceForm.episode_uuid === episodeUuid;

const sections = computed(() => [
    { key: 'overview', label: 'Aperçu', icon: UserRound, count: null },
    ...(props.account ? [{ key: 'billing', label: 'Facturation', icon: ReceiptText, count: props.account.invoices.length }] : []),
    { key: 'episodes', label: 'Passages', icon: History, count: props.patient.episodes.length },
]);
const tabClass = (key) => [
    'flex h-10 shrink-0 items-center gap-2 rounded-lg px-4 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30',
    activeSection.value === key ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
];

const identityDocumentLabel = computed(() => (props.patient.identity_document_type
    ? `${props.patient.identity_document_type === 'CIN' ? 'CIN' : 'Passeport'} · ${props.patient.identity_document_number}`
    : 'Non renseignée'));
const administrativeFields = computed(() => [
    { label: 'Type de patient', value: patientTypeLabel.value },
    { label: 'Situation maritale', value: maritalStatusLabel.value },
    { label: 'Nombre d’enfants', value: childrenCountLabel.value },
    { label: 'Profession', value: props.patient.profession || 'Non renseignée' },
    { label: 'Téléphone', icon: Phone, value: props.patient.phone ?? 'Non renseigné' },
    { label: 'Email', icon: Mail, value: props.patient.email ?? 'Non renseigné', breakAll: true },
    { label: 'Adresse', icon: MapPin, value: props.patient.address_entry?.label ?? props.patient.address ?? 'Non renseignée' },
    { label: 'Pièce d’identité', icon: IdCard, value: identityDocumentLabel.value },
]);
</script>

<template>
    <Head :title="formatPatientName(patient)" />

    <div class="mx-auto w-full max-w-[1480px] space-y-4">
        <Card class="overflow-hidden shadow-sm">
            <div class="flex flex-col gap-5 px-5 py-5 lg:flex-row lg:items-center lg:justify-between lg:px-6">
                <div class="flex min-w-0 items-center gap-4">
                    <Avatar size="lg" :initials="formatPatientInitials(patient)" :emergency="Boolean(activeEmergencyEpisode)" aria-hidden="true" />
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-xs font-bold uppercase tracking-[0.12em] text-muted-foreground">{{ patient.patient_number }}</span>
                            <Badge variant="outline">{{ patientTypeLabel }}</Badge>
                        </div>
                        <h1 class="mt-1.5 truncate font-heading text-2xl font-bold tracking-tight text-foreground">
                            <span v-if="patient.civility">{{ civilityLabels[patient.civility] }}</span>
                            {{ formatPatientName(patient) }}
                        </h1>
                        <!-- ADR-144 : le lien avec la mère se lit dans l'en-tête, dans les deux sens. -->
                        <p v-if="family.mother" class="mt-1 text-xs font-medium text-muted-foreground">
                            Nouveau-né{{ family.mother.birth_rank > 1 ? ` n° ${family.mother.birth_rank}` : '' }} de
                            <Link :href="`/patients/${family.mother.uuid}`" class="font-semibold text-primary hover:underline">{{ family.mother.name }}</Link>
                            <span class="font-mono"> · {{ family.mother.patient_number }}</span>
                            <Link :href="family.mother.medical_record_url" class="ms-2 font-semibold text-primary hover:underline">Son dossier médical</Link>
                        </p>
                        <p v-if="family.children.length" class="mt-1 text-xs font-medium text-muted-foreground">
                            Enfant{{ family.children.length > 1 ? 's' : '' }} né{{ family.children.length > 1 ? 's' : '' }} à la clinique :
                            <template v-for="(child, index) in family.children" :key="child.birth_rank">
                                <Link v-if="child.uuid" :href="`/patients/${child.uuid}`" class="font-semibold text-primary hover:underline">{{ child.name }} <span class="font-mono">({{ child.patient_number }})</span></Link>
                                <span v-else class="font-semibold text-foreground">{{ child.name }}</span>
                                <Link v-if="child.medical_record_url" :href="child.medical_record_url" class="text-muted-foreground hover:text-primary hover:underline">· dossier médical</Link><span v-if="index < family.children.length - 1">, </span>
                            </template>
                        </p>
                        <!-- Le même badge qu'au répertoire des patients : ce
                             dossier doit dire au premier coup d'œil ce que la
                             liste disait déjà, jamais rien de plus discret. -->
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <Badge :variant="patientPresence.variant">
                                <span :class="['h-1.5 w-1.5 rounded-full', patientPresence.dot]" />
                                {{ patientPresence.label }}
                            </Badge>
                            <span v-if="activeOpenEpisode" class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                                <!-- Le badge dit déjà « En attente de règlement » : répéter le
                                     même mot à côté n'apprendrait rien. Le parcours n'est nommé
                                     que lorsqu'il précise le badge (ex. « En consultation »). -->
                                <template v-if="pathwayStatus(activeOpenEpisode) !== patientPresence.label">
                                    <component :is="pathwayBadge(pathwayStatus(activeOpenEpisode)).icon" class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ pathwayStatus(activeOpenEpisode) }}
                                </template>
                                <Link :href="`/passages/${activeOpenEpisode.uuid}`" class="inline-flex items-center gap-1 font-semibold text-primary hover:underline">
                                    <Info class="h-3.5 w-3.5" aria-hidden="true" />Pourquoi ce statut ?
                                </Link>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button :as="Link" href="/patients" variant="outline"><ArrowLeft class="h-4 w-4" />Liste des patients</Button>
                    <!-- Tous les « Dossier médical – Traitement » du patient, un
                         par passage, réunis en un seul document que l'on peut
                         relire à l'écran puis enregistrer en un seul PDF. -->
                    <!-- ADR-145 : le dossier médical d'un patient, sans passage requis — un nouveau-né n'en a pas encore. -->
                    <Button :as="Link" :href="`/patients/${patient.uuid}/dossier-medical`" variant="outline"><FileText class="h-4 w-4" />Dossier médical</Button>
                    <Button v-if="canOpenJournals" :as="Link" :href="journalsUrl" variant="outline" title="Voir tous les journaux de traitement et les télécharger en un seul PDF"><NotebookText class="h-4 w-4" />Journaux de traitement</Button>
                    <Button v-if="can('patients.update') && patient.patient_type !== 'STAFF'" :as="Link" :href="`/patients/${patient.uuid}/edit`" variant="outline"><Pencil class="h-4 w-4" />Modifier</Button>
                    <Button v-if="can('cash.view')" :as="Link" href="/cash" variant="outline"><WalletCards class="h-4 w-4" />Caisse</Button>
                </div>
            </div>

            <dl class="grid grid-cols-2 border-y border-border bg-muted/30 sm:grid-cols-4">
                <div class="border-e border-border px-5 py-3.5 lg:px-6"><dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{{ birthLabel }}</dt><dd class="mt-1 text-sm font-semibold text-foreground">{{ birthSummary }}</dd></div>
                <div class="px-5 py-3.5 sm:border-e sm:border-border lg:px-6"><dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Sexe</dt><dd class="mt-1 text-sm font-semibold text-foreground">{{ sexLabel(patient.sex) }}</dd></div>
                <div class="border-e border-t border-border px-5 py-3.5 sm:border-t-0 lg:px-6"><dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Téléphone</dt><dd class="mt-1 truncate text-sm font-semibold text-foreground">{{ patient.phone ?? 'Non renseigné' }}</dd></div>
                <div class="border-t border-border px-5 py-3.5 sm:border-t-0 lg:px-6"><dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Dernier passage</dt><dd class="mt-1 font-mono text-sm font-semibold text-foreground">{{ latestEpisode?.episode_number ?? 'Aucun passage' }}</dd></div>
            </dl>

            <nav class="flex gap-1 overflow-x-auto p-2" role="tablist" aria-label="Sections du dossier patient">
                <button v-for="section in sections" :key="section.key" type="button" :class="tabClass(section.key)" role="tab" :aria-selected="activeSection === section.key" @click="activeSection = section.key">
                    <component :is="section.icon" class="h-4 w-4" />{{ section.label }}
                    <span v-if="section.count !== null" class="rounded-full bg-background px-1.5 py-0.5 text-[11px] text-muted-foreground ring-1 ring-border">{{ section.count }}</span>
                </button>
            </nav>
        </Card>

        <div v-if="activeEmergencyEpisode" class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50/70 px-4 py-3.5 text-red-900 shadow-sm dark:border-red-900 dark:bg-red-950/30 dark:text-red-100" role="status">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-red-600 dark:text-red-400" />
            <div><p class="text-xs font-bold uppercase tracking-wide">Passage prioritaire — {{ activeEmergencyEpisode.episode_number }}</p><p class="mt-0.5 text-xs leading-5 text-red-800/75 dark:text-red-200/75">Les soins urgents restent prioritaires et ne sont jamais bloqués par le paiement.</p></div>
        </div>

        <Card v-if="account && activeSection === 'billing'" class="overflow-hidden">
            <div class="flex flex-col gap-3 border-b border-border bg-gradient-to-r from-primary/10 via-card to-card px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><WalletCards class="h-5 w-5" /></span>
                    <div><h2 class="text-sm font-bold text-foreground">Compte patient</h2><p class="mt-0.5 text-xs text-muted-foreground">Factures, paiements successifs et reçus.</p></div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Badge v-if="openCashSessions.length > 0" variant="success"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />Caisse ouverte</Badge>
                    <Badge v-else-if="can('payments.create')" variant="outline"><span class="h-1.5 w-1.5 rounded-full bg-muted-foreground/50" />Caisse fermée</Badge>
                    <span v-if="patient.patient_type === 'STAFF'" class="text-xs font-medium text-muted-foreground">Couverture RH / Finance à calculer</span>
                </div>
            </div>

            <div class="grid grid-cols-2 divide-x divide-y divide-border border-b border-border bg-muted/15 lg:grid-cols-4 lg:divide-y-0">
                <div class="px-5 py-4"><span class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><Clock3 class="h-3.5 w-3.5" />À facturer</span><p class="mt-1.5 text-xl font-bold tabular-nums text-foreground">{{ formatMoney(account.unbilled_amount) }}</p></div>
                <div class="px-5 py-4"><span class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><FileText class="h-3.5 w-3.5" />Part patient facturée</span><p class="mt-1.5 text-xl font-bold tabular-nums text-foreground">{{ formatMoney(account.total_amount) }}</p></div>
                <div class="px-5 py-4"><span class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><ShieldCheck class="h-3.5 w-3.5" />Total payé</span><p class="mt-1.5 text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatMoney(account.paid_amount) }}</p></div>
                <div class="px-5 py-4"><span class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><CircleDollarSign class="h-3.5 w-3.5" />Reste à payer</span><p :class="['mt-1.5 text-xl font-bold tabular-nums', account.balance_amount > 0 ? 'text-red-600 dark:text-red-400' : 'text-foreground']">{{ formatMoney(account.balance_amount) }}</p></div>
            </div>

            <details v-if="cancelledBillableItems.length" class="border-b border-border px-5 py-3">
                <summary class="cursor-pointer text-xs font-medium text-muted-foreground">Prestations annulées ({{ cancelledBillableItems.length }})</summary>
                <ul class="mt-3 space-y-2">
                    <li v-for="item in cancelledBillableItems" :key="item.uuid" class="flex flex-col justify-between gap-1 text-xs text-muted-foreground sm:flex-row">
                        <span><span class="line-through">{{ item.description }} · {{ formatMoney(item.total_amount) }}</span> · {{ moduleLabels[item.source_module] ?? item.source_module }} · passage {{ item.episode.episode_number }}</span>
                        <span :title="item.cancellation_reason">Annulée le {{ formatDateTime(item.cancelled_at) }}</span>
                    </li>
                </ul>
            </details>

            <EmptyState v-if="account.invoices.length === 0 && passageGroups.length === 0" icon="file-text" title="Aucune facture" description="Les factures liées aux passages apparaîtront ici." />

            <div v-else class="grid grid-cols-1 gap-4 p-5 xl:grid-cols-3">
                <div class="space-y-5 xl:col-span-2">
                    <EmptyState v-if="passageGroups.length === 0" icon="check-circle" title="Rien à régler" description="Toutes les factures de ce patient sont réglées." />

                    <div v-for="group in passageGroups" :key="group.episode.uuid" class="space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-muted/40 px-4 py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <CalendarDays class="h-4 w-4 text-muted-foreground" />
                                <span class="font-mono text-sm font-bold text-foreground">Passage {{ group.episode.episode_number }}</span>
                                <Badge v-if="group.pendingItems.length" variant="warning">{{ group.pendingItems.length }} en attente</Badge>
                            </div>
                            <Button v-if="can('billing.create')" size="sm" :variant="invoiceFormOpenFor(group.episode.uuid) ? 'white-outline' : 'primary'" type="button" @click="toggleInvoiceFormFor(group.episode.uuid)">
                                <component :is="invoiceFormOpenFor(group.episode.uuid) ? X : Plus" class="h-4 w-4" />{{ invoiceFormOpenFor(group.episode.uuid) ? 'Fermer' : 'Nouvelle facture' }}
                            </Button>
                        </div>

                        <!-- Panier du passage : ce que les services ont déjà transmis,
                             visible sans rien ouvrir. Masqué pendant la saisie d'une
                             nouvelle facture, qui reprend ces mêmes lignes cochées. -->
                        <section v-if="group.pendingItems.length && !invoiceFormOpenFor(group.episode.uuid)" class="overflow-hidden rounded-lg border border-amber-200 bg-card dark:border-amber-900/60" :aria-label="`Prestations en attente de facturation, passage ${group.episode.episode_number}`">
                            <header class="flex items-center gap-2 border-b border-amber-200 bg-amber-50 px-4 py-2.5 dark:border-amber-900/60 dark:bg-amber-950/30">
                                <Clock3 class="h-4 w-4 text-amber-600 dark:text-amber-400" />
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-amber-800 dark:text-amber-200">Prestations en attente de facturation</h3>
                                <Badge variant="warning" class="ms-auto">{{ group.pendingItems.length }}</Badge>
                            </header>
                            <ul class="divide-y divide-border">
                                <li v-for="item in group.pendingItems" :key="item.uuid" class="flex items-center gap-3 px-4 py-2.5">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-foreground">{{ item.description }}</span>
                                        <span class="text-xs text-muted-foreground">{{ moduleLabels[item.source_module] ?? item.source_module }} · {{ formatQuantity(item.quantity) }} × {{ formatMoney(item.unit_price) }}</span>
                                    </span>
                                    <span class="shrink-0 text-end text-sm font-semibold tabular-nums text-foreground">{{ formatMoney(item.patient_amount ?? item.total_amount) }}<small v-if="Number(item.coverage_amount) > 0" class="block text-[10px] font-normal text-muted-foreground">Brut {{ formatMoney(item.gross_amount ?? item.total_amount) }}</small></span>
                                </li>
                            </ul>
                            <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-border bg-muted/40 px-4 py-2.5">
                                <p class="text-sm text-muted-foreground">Total patient <span class="ms-1 font-semibold tabular-nums text-foreground">{{ formatMoney(pendingTotal(group.pendingItems)) }}</span></p>
                                <Button v-if="can('billing.create')" size="sm" type="button" :disabled="pendingInvoiceForm.processing" @click="invoicePendingItems(group)">
                                    <FilePlus2 class="h-4 w-4" />{{ billingPendingFor === group.episode.uuid ? 'Facturation…' : 'Facturer ces prestations' }}
                                </Button>
                            </footer>
                            <p v-if="pendingInvoiceForm.errors.billable_item_uuids" class="border-t border-border px-4 py-2 text-xs text-destructive">{{ pendingInvoiceForm.errors.billable_item_uuids }}</p>
                        </section>

                        <form v-if="invoiceFormOpenFor(group.episode.uuid)" class="rounded-lg border border-border bg-muted/30 p-5" @submit.prevent="createInvoice">
                            <div class="mb-5 flex flex-col gap-3 rounded-lg border border-border bg-card px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-primary"><FileText class="h-4 w-4" /></span>
                                    <div><h3 class="text-sm font-bold text-foreground">Créer une facture</h3><p class="mt-0.5 text-xs text-muted-foreground">Sélectionnez les prestations transmises par les services ou ajoutez une désignation du référentiel.</p></div>
                                </div>
                                <span class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-full bg-primary/10 px-3 py-1.5 text-sm font-bold tabular-nums text-primary sm:self-auto">Total : {{ formatMoney(invoiceDraftTotal) }}</span>
                            </div>

                            <div v-if="openInvoicesForEpisode.length" class="mb-4 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
                                <Info class="mt-0.5 h-4 w-4 shrink-0" />
                                <p><span class="font-semibold">{{ openInvoicesForEpisode.length }} facture{{ openInvoicesForEpisode.length > 1 ? 's' : '' }} déjà en attente de paiement</span> sur ce passage ({{ openInvoicesForEpisode.map((invoice) => invoice.invoice_number).join(', ') }}), pour {{ formatMoney(openInvoicesForEpisodeTotal) }} restant dû. Cette action créera une facture <span class="font-semibold">supplémentaire et distincte</span> — les factures existantes ne sont pas modifiées et devront être réglées séparément.</p>
                            </div>

                            <div v-if="pendingItemsForEpisode.length" class="mb-4 overflow-hidden rounded-lg border border-border bg-card">
                                <div class="flex items-center gap-2 border-b border-border bg-muted/40 px-4 py-2.5 text-xs font-bold uppercase tracking-wide text-muted-foreground"><Clock3 class="h-3.5 w-3.5" />Prestations en attente de facturation<Badge variant="secondary" class="ms-auto">{{ pendingItemsForEpisode.length }}</Badge></div>
                                <label v-for="item in pendingItemsForEpisode" :key="item.uuid" class="flex cursor-pointer items-start gap-3 border-b border-border px-4 py-3 last:border-0 hover:bg-primary/5">
                                    <Checkbox class="mt-0.5" :model-value="invoiceForm.billable_item_uuids.includes(item.uuid)" :aria-label="`Facturer ${item.description}`" @update:model-value="toggleBillable(item.uuid, $event)" />
                                    <span class="min-w-0 flex-1">
                                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-medium text-foreground">
                                            {{ item.description }}
                                            <span class="text-xs font-normal text-muted-foreground">{{ moduleLabels[item.source_module] ?? item.source_module }}</span>
                                            <Badge v-if="item.payment_required_before_fulfillment" variant="warning" class="text-[11px]">Paiement préalable requis</Badge>
                                        </span>
                                        <span class="mt-0.5 block text-xs text-muted-foreground">{{ item.quantity }} × {{ formatMoney(item.unit_price) }}</span>
                                    </span>
                                    <span class="shrink-0 text-end text-sm font-bold tabular-nums text-foreground">{{ formatMoney(item.patient_amount ?? item.total_amount) }}<small v-if="Number(item.coverage_amount) > 0" class="mt-0.5 block text-[10px] font-normal text-muted-foreground">Brut {{ formatMoney(item.gross_amount ?? item.total_amount) }}</small></span>
                                </label>
                            </div>

                            <div v-else class="mb-4 flex flex-col items-center gap-2 rounded-lg border border-dashed border-border px-5 py-6 text-center">
                                <span class="grid h-9 w-9 place-items-center rounded-full bg-muted text-muted-foreground"><Clock3 class="h-4 w-4" /></span>
                                <p class="text-xs text-muted-foreground">Aucune prestation métier en attente pour ce passage.</p>
                            </div>

                            <div v-if="invoiceForm.catalog_lines.length" class="overflow-hidden rounded-lg border border-border bg-card">
                                <div class="hidden gap-2 border-b border-border bg-muted/40 px-4 py-2 text-[11px] font-bold uppercase tracking-wide text-muted-foreground sm:grid sm:grid-cols-[minmax(0,1fr)_110px_170px_36px]"><span>Désignation</span><span>Quantité</span><span class="text-end">Total patient</span><span></span></div>
                                <div class="divide-y divide-border">
                                    <div v-for="(line, index) in invoiceForm.catalog_lines" :key="index" class="grid grid-cols-1 gap-2 p-3 sm:grid-cols-[minmax(0,1fr)_110px_170px_36px] sm:items-center">
                                        <Select v-model="line.catalog_item_uuid" class="w-full" :options="billingCatalogOptions" :aria-label="`Désignation ${index + 1}`" placeholder="Choisir une prestation" />
                                        <Input v-model="line.quantity" type="number" min="0.01" step="0.01" aria-label="Quantité" placeholder="Quantité" required />
                                        <div class="flex h-10 items-center justify-end rounded-lg border border-border bg-muted/40 px-3 text-sm font-bold tabular-nums text-foreground">
                                            {{ formatMoney((Number(line.quantity) || 0) * Number(catalogByUuid.get(line.catalog_item_uuid)?.patient_amount ?? catalogByUuid.get(line.catalog_item_uuid)?.tariff_amount ?? 0)) }}
                                        </div>
                                        <Button icon size="sm" variant="danger-outline" type="button" aria-label="Retirer cette ligne" @click="removeInvoiceLine(index)"><Trash2 class="h-4 w-4" /></Button>
                                    </div>
                                </div>
                            </div>
                            <p v-if="invoiceForm.errors.catalog_lines" class="mt-2 text-xs text-destructive">{{ invoiceForm.errors.catalog_lines }}</p>
                            <p v-if="invoiceForm.errors['catalog_lines.0.catalog_item_uuid']" class="mt-2 text-xs text-destructive">{{ invoiceForm.errors['catalog_lines.0.catalog_item_uuid'] }}</p>

                            <button v-if="billingCatalog?.length" type="button" class="mt-3 flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-border py-2.5 text-xs font-bold text-primary transition-colors hover:border-primary/50 hover:bg-primary/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30" @click="addInvoiceLine"><Plus class="h-4 w-4" />Ajouter depuis le référentiel</button>
                            <p v-else class="mt-3 text-xs text-muted-foreground">Aucune prestation avec tarif actif. Le Super Administrateur doit compléter le référentiel.</p>

                            <div class="mt-5 flex flex-col-reverse gap-3 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-xs text-muted-foreground">Le brouillon peut être complété ou corrigé avant validation.</p>
                                <Button size="lg" type="submit" :disabled="invoiceForm.processing"><FileText class="h-4 w-4" />{{ invoiceForm.processing ? 'Création…' : 'Créer le brouillon' }}</Button>
                            </div>
                        </form>

                        <article v-for="invoice in group.invoices" :key="invoice.uuid" class="overflow-hidden rounded-lg border border-border">
                            <div class="flex flex-col gap-3 border-b border-border bg-muted/40 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-bold text-foreground">{{ invoice.invoice_number }}</h3>
                                    <Badge :variant="invoiceVariant(invoice.status)">{{ invoiceStatusLabels[invoice.status] }}</Badge>
                                    <span class="text-xs text-muted-foreground">{{ formatDateTime(invoice.created_at) }}</span>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <Button v-if="invoice.status === 'DRAFT' && can('billing.validate')" size="sm" variant="white-outline" type="button" :disabled="validatingInvoice === invoice.uuid" @click="validateInvoice(invoice)"><Check class="h-4 w-4" />Valider</Button>
                                    <Button v-if="can('billing.print')" :as="Link" :href="`/invoices/${invoice.uuid}`" size="sm" variant="white-outline"><Printer class="h-4 w-4" />Facture</Button>
                                    <Button v-if="['VALIDATED', 'PARTIALLY_PAID'].includes(invoice.status) && can('payments.create') && openCashSessions.length > 0" size="sm" variant="success" type="button" @click="openPaymentDialog(invoice)"><Banknote class="h-4 w-4" />Encaisser</Button>
                                    <Button v-else-if="['VALIDATED', 'PARTIALLY_PAID'].includes(invoice.status) && can('payments.create') && openCashSessions.length === 0" :as="Link" href="/cash" size="sm" variant="white-outline">Ouvrir la caisse</Button>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full min-w-[760px] border-collapse">
                                    <caption class="sr-only">Désignations de la facture {{ invoice.invoice_number }}</caption>
                                    <thead>
                                        <tr class="border-b border-border bg-card">
                                            <th class="px-4 py-2.5 text-start text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Désignation</th>
                                            <th class="w-40 px-4 py-2.5 text-start text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Service</th>
                                            <th class="w-24 px-4 py-2.5 text-center text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Qté</th>
                                            <th class="w-40 px-4 py-2.5 text-end text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Tarif unitaire</th>
                                            <th class="w-40 px-4 py-2.5 text-end text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Part patient</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border">
                                        <tr v-for="line in invoice.lines" :key="line.id">
                                            <td class="px-4 py-3 text-sm font-semibold text-foreground">{{ line.description }}</td>
                                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ moduleLabels[line.source_module] ?? line.source_module }}</td>
                                            <td class="px-4 py-3 text-center text-sm tabular-nums text-foreground">{{ formatQuantity(line.quantity) }}</td>
                                            <td class="px-4 py-3 text-end text-sm tabular-nums text-foreground">{{ formatMoney(line.unit_price) }}</td>
                                            <td class="px-4 py-3 text-end"><p class="text-sm font-bold tabular-nums text-foreground">{{ formatMoney(line.line_total) }}</p><p v-if="Number(line.coverage_amount) > 0" class="mt-0.5 text-[10px] text-muted-foreground">Brut {{ formatMoney(line.gross_line_total) }} · {{ invoice.financial_mode === 'STAFF' ? 'Personnel' : 'mutuelle' }} −{{ formatMoney(line.coverage_amount) }}<span v-if="Number(line.staff_block_credit_used) > 0"> (Bloc {{ formatMoney(line.staff_block_credit_used) }})</span></p></td>
                                        </tr>
                                    </tbody>
                                    <tfoot class="border-t border-border bg-muted/30">
                                        <tr v-if="Number(invoice.coverage_amount) > 0"><th colspan="4" class="px-4 pt-3 pb-1 text-end text-xs font-medium text-muted-foreground">Total brut</th><td class="px-4 pt-3 pb-1 text-end text-sm font-semibold tabular-nums text-foreground">{{ formatMoney(invoice.subtotal_amount) }}</td></tr>
                                        <tr v-if="Number(invoice.coverage_amount) > 0"><th colspan="4" class="px-4 py-1 text-end text-xs font-medium text-muted-foreground">{{ invoice.financial_mode === 'STAFF' ? 'Prise en charge Personnel' : `Pris en charge · ${invoice.mutual_organization_name}` }}</th><td class="px-4 py-1 text-end text-sm font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">− {{ formatMoney(invoice.coverage_amount) }}</td></tr>
                                        <tr v-if="Number(invoice.staff_block_credit_used) > 0"><th colspan="4" class="px-4 py-1 text-end text-[10px] font-medium text-muted-foreground">dont crédit forfaitaire Bloc</th><td class="px-4 py-1 text-end text-xs font-medium tabular-nums text-muted-foreground">{{ formatMoney(invoice.staff_block_credit_used) }}</td></tr>
                                        <tr>
                                            <th colspan="4" class="px-4 pt-3 pb-1 text-end text-xs font-medium text-muted-foreground">À charge patient</th>
                                            <td class="px-4 pt-3 pb-1 text-end text-sm font-bold tabular-nums text-foreground">{{ formatMoney(invoice.total_amount) }}</td>
                                        </tr>
                                        <tr>
                                            <th colspan="4" class="px-4 py-1 text-end text-xs font-medium text-muted-foreground">Montant payé</th>
                                            <td class="px-4 py-1 text-end text-sm font-semibold tabular-nums text-foreground">{{ formatMoney(invoice.paid_amount) }}</td>
                                        </tr>
                                        <tr>
                                            <th colspan="4" class="px-4 pt-1 pb-3 text-end text-xs font-bold text-foreground">Reste à payer</th>
                                            <td :class="['px-4 pt-1 pb-3 text-end text-base font-bold tabular-nums', invoice.balance_amount > 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400']">{{ formatMoney(invoice.balance_amount) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <div v-if="invoice.payments.length" class="border-t border-border">
                                <div class="px-4 pt-2.5 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Paiements</div>
                                <div v-for="payment in invoice.payments" :key="payment.uuid" class="flex flex-col gap-2 border-b border-border px-4 py-2.5 last:border-0 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="flex items-start gap-2 text-xs text-muted-foreground">
                                        <CircleCheck :class="['mt-0.5 h-4 w-4 shrink-0', payment.status === 'CANCELLED' ? 'text-muted-foreground/40' : 'text-emerald-500']" />
                                        <span>
                                            <span :class="['font-bold', payment.status === 'CANCELLED' ? 'text-muted-foreground line-through' : 'text-foreground']">{{ formatMoney(payment.amount) }}</span>
                                            <span> · {{ payment.method }} · {{ formatDateTime(payment.paid_at) }}</span>
                                            <span class="block sm:ms-2 sm:inline">par {{ payment.cashier }}</span>
                                            <Badge v-if="payment.status === 'CANCELLED'" variant="destructive" class="ms-2 px-1.5 py-0.5 text-[11px]" :title="payment.cancellation_reason">Annulé</Badge>
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-3 ps-6 sm:ps-0">
                                        <button v-if="payment.status === 'COMPLETED' && can('payments.cancel') && openCashSessions.some((s) => s.uuid === payment.cash_session_uuid)" type="button" class="text-xs font-medium text-destructive hover:underline" @click="openCancellationDialog(payment)">Annuler</button>
                                        <Link v-if="payment.receipt" :href="`/receipts/${payment.receipt.uuid}`" class="inline-flex items-center gap-1 text-xs font-bold text-primary hover:underline"><FileText class="h-3.5 w-3.5" />{{ payment.receipt.receipt_number }}</Link>
                                    </div>
                                </div>
                            </div>
                        </article>
                    </div>
                </div>

                <aside class="xl:col-span-1">
                    <div class="overflow-hidden rounded-lg border border-border">
                        <div class="flex items-center gap-2 border-b border-border bg-muted/40 px-4 py-2.5 text-xs font-bold uppercase tracking-wide text-muted-foreground"><CircleCheck class="h-4 w-4 text-emerald-500" />Factures réglées<Badge variant="secondary" class="ms-auto">{{ settledInvoices.length }}</Badge></div>
                        <div v-if="settledInvoices.length" class="max-h-[640px] divide-y divide-border overflow-y-auto">
                            <div v-for="invoice in settledInvoices" :key="invoice.uuid" class="px-4 py-3">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-mono text-sm font-semibold text-foreground">{{ invoice.invoice_number }}</span>
                                    <Badge :variant="invoiceVariant(invoice.status)" class="px-1.5 py-0.5 text-[10px]">{{ invoiceStatusLabels[invoice.status] }}</Badge>
                                </div>
                                <p class="mt-1 text-xs text-muted-foreground">Passage {{ invoice.episode.episode_number }} · {{ formatDateTime(invoice.created_at) }}</p>
                                <div class="mt-1.5 flex items-center justify-between gap-2">
                                    <span class="text-sm font-bold tabular-nums text-foreground">{{ formatMoney(invoice.total_amount) }}</span>
                                    <Link v-if="can('billing.print')" :href="`/invoices/${invoice.uuid}`" class="inline-flex items-center gap-1 text-xs font-bold text-primary hover:underline"><Printer class="h-3.5 w-3.5" />Voir</Link>
                                </div>
                            </div>
                        </div>
                        <p v-else class="px-4 py-6 text-center text-xs text-muted-foreground">Aucune facture réglée pour l’instant.</p>
                    </div>
                </aside>
            </div>
        </Card>

        <div v-if="activeSection === 'overview'" class="grid grid-cols-1 gap-4 xl:grid-cols-3">
            <div class="space-y-4 xl:col-span-2">
                <Card class="overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-border px-5 py-4">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><UserRound class="h-4 w-4" /></span>
                        <div><h2 class="text-sm font-bold text-foreground">Informations administratives</h2><p class="mt-0.5 text-xs text-muted-foreground">Coordonnées et document d’identité du patient.</p></div>
                    </div>
                    <dl class="grid grid-cols-1 gap-px bg-border sm:grid-cols-2">
                        <div v-for="field in administrativeFields" :key="field.label" class="bg-card px-5 py-3.5">
                            <dt class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"><component :is="field.icon" v-if="field.icon" class="h-3.5 w-3.5" />{{ field.label }}</dt>
                            <dd :class="['mt-1.5 text-sm font-medium leading-5 text-foreground', field.breakAll && 'break-all']">{{ field.value }}</dd>
                        </div>
                    </dl>
                </Card>
            </div>

            <aside class="space-y-4">
                <Card class="p-5">
                    <div class="flex items-center justify-between gap-3"><div class="flex items-center gap-2"><History class="h-4 w-4 text-primary" /><h2 class="text-sm font-bold text-foreground">Passages récents</h2></div><Button v-if="patient.episodes.length" size="xs" variant="ghost" type="button" @click="activeSection = 'episodes'">Voir tout<ArrowRight class="h-3.5 w-3.5" /></Button></div>
                    <ul v-if="recentEpisodes.length" class="mt-3 space-y-3">
                        <li v-for="episode in recentEpisodes" :key="episode.uuid" class="flex gap-2.5 border-t border-border pt-3 first:border-t-0 first:pt-0">
                            <span :class="['grid h-7 w-7 shrink-0 place-items-center rounded-full', episodeStatus(episode.status).bubble]"><component :is="episodeStatus(episode.status).icon" class="h-3.5 w-3.5" /></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <Link :href="`/passages/${episode.uuid}`" class="font-mono text-xs font-bold text-foreground hover:text-primary hover:underline">{{ episode.episode_number }}</Link>
                                    <Badge :variant="episodeStatus(episode.status).variant" class="px-1.5 py-0.5 text-[10px]">{{ statusLabels[episode.status] }}</Badge>
                                    <span v-if="episode.priority === 'EMERGENCY'" class="inline-flex items-center gap-1 text-[10px] font-bold uppercase text-red-600 dark:text-red-300"><span class="h-1 w-1 rounded-full bg-red-500"></span> Urgence</span>
                                </div>
                                <p class="mt-1"><Badge :variant="pathwayBadge(pathwayStatus(episode)).variant" class="px-1.5 py-0.5 text-[10px]"><component :is="pathwayBadge(pathwayStatus(episode)).icon" class="h-3 w-3" />{{ pathwayStatus(episode) }}</Badge></p>
                                <!-- Un reste à payer se voit ici sans avoir à ouvrir l'onglet Passages. -->
                                <p v-if="can('billing.view') && episodeBilling(episode)?.balanceAmount > 0" class="mt-1 text-[11px] font-semibold text-red-600 dark:text-red-400">
                                    Reste à payer : {{ formatMoney(episodeBilling(episode).balanceAmount) }}
                                </p>
                                <p class="mt-1 text-[11px] text-muted-foreground">{{ formatDateTime(episode.started_at) }}</p>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="mt-3 text-sm text-muted-foreground">Aucun passage enregistré.</p>
                </Card>

                <Card v-if="patient.patient_type === 'MUTUAL' && can('patient_coverages.view')" class="p-5">
                    <h2 class="text-sm font-bold text-foreground">Couverture mutuelle</h2>
                    <dl v-if="activeMutualCoverage" class="mt-3 space-y-2.5 text-xs">
                        <div class="flex justify-between gap-4"><dt class="text-muted-foreground">Organisme</dt><dd class="text-end font-semibold text-foreground">{{ activeMutualCoverage.organization?.name }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-muted-foreground">Bénéficiaire</dt><dd class="text-end font-semibold text-foreground">{{ beneficiaryTypeLabels[activeMutualCoverage.beneficiary_type] ?? activeMutualCoverage.beneficiary_type }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-muted-foreground">Matricule</dt><dd class="text-end font-mono font-semibold text-foreground">{{ activeMutualCoverage.membership_number }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-muted-foreground">Entreprise</dt><dd class="text-end font-semibold text-foreground">{{ activeMutualCoverage.employer_name }}</dd></div>
                    </dl>
                    <p v-else class="mt-3 text-sm text-muted-foreground">Aucune couverture active.</p>
                    <div v-if="activeMutualCoverage?.attachments?.length && can('patient_coverage_documents.view')" class="mt-4 border-t border-border pt-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Justificatifs</p>
                            <span class="text-[11px] text-muted-foreground">{{ activeMutualCoverage.attachments.length }}/5</span>
                        </div>
                        <button
                            type="button"
                            class="mt-2 flex w-full items-center gap-3 rounded-lg border border-border p-2.5 text-start transition-colors hover:border-primary/40 hover:bg-accent/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30"
                            :aria-label="`Voir les ${activeMutualCoverage.attachments.length} justificatifs de mutuelle`"
                            @click="mutualAttachmentsOpen = true"
                        >
                            <span class="relative flex h-16 w-20 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border bg-muted/40">
                                <img
                                    v-if="activeMutualCoverage.attachments[0].is_image"
                                    :src="mutualAttachmentUrl(activeMutualCoverage.attachments[0])"
                                    :alt="activeMutualCoverage.attachments[0].original_name"
                                    loading="lazy"
                                    class="h-full w-full object-cover"
                                />
                                <span v-else class="flex flex-col items-center text-muted-foreground">
                                    <FileText class="h-5 w-5" />
                                    <span class="mt-0.5 text-[9px] font-bold">PDF</span>
                                </span>
                                <span v-if="activeMutualCoverage.attachments.length > 1" class="absolute inset-0 flex items-center justify-center bg-foreground/65 text-sm font-bold text-background">
                                    +{{ activeMutualCoverage.attachments.length - 1 }}
                                </span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-xs font-bold text-foreground">{{ activeMutualCoverage.attachments[0].original_name }}</span>
                                <span class="mt-1 block text-[11px] text-muted-foreground">{{ activeMutualCoverage.attachments.length }} fichier{{ activeMutualCoverage.attachments.length > 1 ? 's' : '' }} · Voir les justificatifs</span>
                            </span>
                            <Eye class="h-4 w-4 shrink-0 text-muted-foreground" />
                        </button>
                    </div>
                </Card>

                <!-- ADR-146 : ses bébés nés ici, qu'ils soient déjà patients ou non. Un bébé consigné à la
                     Maternité n'a pas de dossier patient avant son premier accueil : sans cette carte, il
                     n'apparaîtrait nulle part dans le dossier de sa mère. -->
                <Card v-if="family.children.length" class="overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-border px-5 py-4">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Baby class="h-4 w-4" /></span>
                        <div>
                            <h2 class="text-sm font-bold text-foreground">Nouveau-né{{ family.children.length > 1 ? 's' : '' }} né{{ family.children.length > 1 ? 's' : '' }} à la clinique</h2>
                            <p class="mt-0.5 text-xs leading-5 text-muted-foreground">Un bébé devient patient à l’accueil ; ses soins restent sur le compte de sa mère.</p>
                        </div>
                    </div>
                    <ul class="divide-y divide-border">
                        <li v-for="child in family.children" :key="child.birth_rank" class="px-5 py-3.5">
                            <p class="text-sm font-semibold text-foreground">{{ child.name }}</p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                <span v-if="child.patient_number" class="font-mono">{{ child.patient_number }}</span>
                                <span v-else>Pas encore patient</span>
                                <template v-if="child.born_at"> · né(e) le {{ formatDateTime(child.born_at) }}</template>
                            </p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <Button v-if="child.medical_record_url" :as="Link" :href="child.medical_record_url" size="xs" variant="outline"><FileText class="h-3.5 w-3.5" />Dossier médical</Button>
                                <Button v-if="child.uuid" :as="Link" :href="`/patients/${child.uuid}`" size="xs" variant="ghost"><UserRound class="h-3.5 w-3.5" />Dossier patient</Button>
                            </div>
                        </li>
                    </ul>
                </Card>

                <Card v-if="patient.patient_type === 'STAFF' && can('patient_staff_links.view')" class="p-5">
                    <h2 class="text-sm font-bold text-foreground">Dossier personnel lié</h2>
                    <template v-if="activeStaffLink?.employee">
                        <p class="mt-3 text-sm font-semibold text-foreground">{{ formatPatientName(activeStaffLink.employee) }}</p>
                        <p class="mt-1 font-mono text-xs text-muted-foreground">{{ activeStaffLink.employee.employee_number }}</p>
                        <p class="mt-2 text-xs text-muted-foreground">{{ activeStaffLink.employee.profession || 'Fonction non renseignée' }}</p>
                        <p class="mt-3 border-t border-border pt-3 text-xs leading-5 text-muted-foreground">Prise en charge hors bloc et crédit bloc calculés par RH / Finance avant facturation.</p>
                    </template>
                    <p v-else class="mt-3 text-sm text-muted-foreground">Aucun lien actif avec un dossier RH.</p>
                </Card>

                <Card v-if="account" class="p-5">
                    <div class="flex items-center justify-between gap-3"><div class="flex items-center gap-2"><CircleDollarSign class="h-4 w-4 text-primary" /><h2 class="text-sm font-bold text-foreground">Situation financière</h2></div><Button size="xs" variant="ghost" type="button" @click="activeSection = 'billing'">Détails<ArrowRight class="h-3.5 w-3.5" /></Button></div>
                    <p class="mt-4 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Reste à payer</p><p :class="['mt-1 text-2xl font-bold tabular-nums', account.balance_amount > 0 ? 'text-red-600 dark:text-red-400' : 'text-foreground']">{{ formatMoney(account.balance_amount) }}</p>
                    <div class="mt-4 grid grid-cols-2 gap-4 border-t border-border pt-3 text-xs"><div><p class="text-muted-foreground">Facturé</p><p class="mt-1 font-bold text-foreground">{{ formatMoney(account.total_amount) }}</p></div><div><p class="text-muted-foreground">Payé</p><p class="mt-1 font-bold text-emerald-600 dark:text-emerald-400">{{ formatMoney(account.paid_amount) }}</p></div></div>
                </Card>
            </aside>
        </div>

        <div v-else-if="activeSection === 'episodes'" class="grid grid-cols-1 gap-4 xl:grid-cols-3">
            <Card class="overflow-hidden xl:order-2 xl:col-span-1">
                <div class="flex items-start gap-3 border-b border-border px-5 py-4">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><FileHeart class="h-4 w-4" /></span>
                    <div><h2 class="text-sm font-bold text-foreground">Repères médicaux déclarés</h2><p class="mt-0.5 text-xs leading-5 text-muted-foreground">Dossier permanent, enrichi au fil des passages.</p></div>
                </div>
                <div class="divide-y divide-border">
                    <div class="px-5 py-4">
                        <h3 class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-muted-foreground"><CircleAlert class="h-3.5 w-3.5" /> Allergies</h3>
                        <ul v-if="patient.allergies.length" class="mt-3 space-y-2.5 text-sm"><li v-for="allergy in patient.allergies" :key="allergy.id" class="text-foreground"><span class="font-semibold">{{ allergy.substance }}</span><span v-if="allergy.severity" class="ms-1.5 text-xs text-red-600 dark:text-red-300">{{ severityLabels[allergy.severity] }}</span><p v-if="allergy.reaction" class="mt-0.5 text-xs text-muted-foreground">{{ allergy.reaction }}</p></li></ul>
                        <p v-else class="mt-3 text-sm text-muted-foreground">Aucune allergie connue.</p>
                    </div>
                    <div class="px-5 py-4">
                        <h3 class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Antécédents médicaux</h3>
                        <ul v-if="patient.antecedents.length" class="mt-3 space-y-2 text-sm text-foreground"><li v-for="antecedent in patient.antecedents" :key="antecedent.id" class="flex gap-2"><span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-muted-foreground"></span><span>{{ antecedent.description }}</span></li></ul>
                        <p v-else class="mt-3 text-sm text-muted-foreground">Aucun antécédent connu.</p>
                    </div>
                </div>
            </Card>

            <Card class="overflow-hidden xl:order-1 xl:col-span-2">
                <div class="flex flex-col gap-4 border-b border-border px-5 py-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div><h2 class="text-sm font-bold text-foreground">Historique des passages</h2><p class="mt-0.5 text-xs text-muted-foreground">Les arrivées sont créées depuis la Réception patient.</p></div>
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge variant="secondary">{{ filteredEpisodes.length }} sur {{ patient.episodes.length }}</Badge>
                            <Button v-if="canOpenJournals" :as="Link" :href="journalsUrl" size="sm" variant="outline" title="Voir tous les journaux de traitement et les télécharger en un seul PDF"><NotebookText class="h-4 w-4" />Tous les journaux (PDF)</Button>
                        </div>
                    </div>
                    <div v-if="patient.episodes.length" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <div class="flex flex-1 flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                            <div class="w-full sm:max-w-xs sm:flex-1">
                                <IconInput v-model="episodeQuery" :icon="Search" type="search" placeholder="Numéro de passage…" autocomplete="off" aria-label="Rechercher un passage" />
                            </div>
                            <Select v-model="episodeStatusFilter" :options="episodeStatusOptions" class="w-full sm:w-[175px]" aria-label="Filtrer par statut" />
                            <Select v-model="episodeUrgencyFilter" :options="episodeUrgencyOptions" class="w-full sm:w-[190px]" aria-label="Filtrer par priorité" />
                        </div>
                        <div class="inline-flex shrink-0 self-start rounded-lg border border-border bg-muted/30 p-1 sm:self-auto" role="group" aria-label="Mode d’affichage">
                            <Button icon size="sm" :variant="episodeViewMode === 'list' ? 'secondary' : 'ghost'" type="button" aria-label="Vue liste" :aria-pressed="episodeViewMode === 'list'" @click="setEpisodeViewMode('list')"><List class="h-4 w-4" /></Button>
                            <Button icon size="sm" :variant="episodeViewMode === 'grid' ? 'secondary' : 'ghost'" type="button" aria-label="Vue grille" :aria-pressed="episodeViewMode === 'grid'" @click="setEpisodeViewMode('grid')"><LayoutGrid class="h-4 w-4" /></Button>
                        </div>
                    </div>
                </div>
                <EmptyState v-if="patient.episodes.length === 0" icon="history" title="Aucun passage enregistré" description="Le premier passage sera créé depuis la Réception." />
                <EmptyState v-else-if="filteredEpisodes.length === 0" icon="search" title="Aucun passage ne correspond" description="Modifiez la recherche ou les filtres." />
                <div v-else :class="episodeViewMode === 'grid' ? 'grid grid-cols-1 gap-4 p-5 lg:grid-cols-2' : 'divide-y divide-border'">
                    <article v-for="episode in filteredEpisodes" :key="episode.uuid" :class="episodeViewMode === 'grid' ? 'rounded-xl border border-border p-5' : 'p-5'">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex min-w-0 items-start gap-3">
                                <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-full', episodeStatus(episode.status).bubble]"><component :is="episodeStatus(episode.status).icon" class="h-4 w-4" /></span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-sm font-bold text-foreground">{{ episode.episode_number }}</span>
                                        <Badge :variant="episodeStatus(episode.status).variant">{{ statusLabels[episode.status] }}</Badge>
                                        <Badge v-if="episode.priority === 'EMERGENCY'" variant="destructive"><span class="h-1.5 w-1.5 rounded-full bg-red-500" />Urgence</Badge>
                                        <span class="text-xs text-muted-foreground">{{ episode.status === 'OPEN' ? 'Passage actif' : 'Passage terminé' }}</span>
                                    </div>
                                    <p class="mt-1 text-xs text-muted-foreground">Démarré le {{ formatDateTime(episode.started_at) }}</p>
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-col items-start gap-2 sm:items-end">
                                <div class="sm:text-end">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Parcours clinique</p>
                                    <p class="mt-1"><Badge :variant="pathwayBadge(pathwayStatus(episode)).variant"><component :is="pathwayBadge(pathwayStatus(episode)).icon" class="h-3 w-3" />{{ pathwayStatus(episode) }}</Badge></p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <Button v-if="can('treatment_journal.view')" :as="Link" :href="passageJournalUrl(episode)" size="xs" variant="outline" title="Journal de traitement de ce passage"><NotebookText class="h-3.5 w-3.5" />Journal</Button>
                                    <Button :as="Link" :href="`/passages/${episode.uuid}`" size="xs" variant="outline">Voir le détail<ArrowRight class="h-3.5 w-3.5" /></Button>
                                </div>
                            </div>
                        </div>

                        <div v-if="episode.emergency_contact_name" class="mt-3 inline-flex flex-wrap items-center gap-x-2 gap-y-1 rounded-md border border-border bg-muted/30 px-3 py-1.5 text-xs text-muted-foreground">
                            <Phone class="h-3.5 w-3.5" />
                            <span class="font-semibold text-foreground">{{ episode.emergency_contact_name }}</span>
                            <span v-if="episode.emergency_contact_relationship">· {{ episode.emergency_contact_relationship }}</span>
                            <span v-if="episode.emergency_contact_phone">· {{ episode.emergency_contact_phone }}</span>
                        </div>

                        <!-- « Quelles étapes sont déjà faites, laquelle reste ? » — le parcours
                             composé par Laravel (ADR-117) : Réception, chaque demande de
                             service distincte, Pharmacie, Caisse et sortie. Le même que la
                             liste du « Détail du passage ». -->
                        <EpisodePathwayTrail v-if="episode.pathway?.length > 1" class="mt-3" :steps="episode.pathway" />

                        <!-- « Y a-t-il un problème de paiement ? » — pour ce seul
                             passage, jamais l'agrégat du patient entier. -->
                        <div v-if="can('billing.view')" class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-md border px-3 py-1.5 text-xs" :class="episodeBilling(episode)?.balanceAmount > 0 ? 'border-red-200 bg-red-50/60 text-red-700 dark:border-red-900 dark:bg-red-950/20 dark:text-red-300' : 'border-border bg-muted/30 text-muted-foreground'">
                            <component :is="episodeBilling(episode)?.balanceAmount > 0 ? CircleAlert : WalletCards" class="h-3.5 w-3.5" />
                            <template v-if="!episodeBilling(episode)?.hasActivity">Aucune facturation pour ce passage.</template>
                            <template v-else-if="episodeBilling(episode).balanceAmount > 0"><span class="font-bold">Reste à payer : {{ formatMoney(episodeBilling(episode).balanceAmount) }}</span></template>
                            <template v-else><span class="font-semibold text-foreground">Facturé et réglé : {{ formatMoney(episodeBilling(episode).totalAmount) }}</span></template>
                            <span v-if="episodeBilling(episode)?.pendingCount" class="text-amber-600 dark:text-amber-300">· {{ episodeBilling(episode).pendingCount }} prestation{{ episodeBilling(episode).pendingCount > 1 ? 's' : '' }} pas encore facturée{{ episodeBilling(episode).pendingCount > 1 ? 's' : '' }} ({{ formatMoney(episodeBilling(episode).pendingAmount) }})</span>
                        </div>

                        <div v-if="can('care.view')" class="mt-4 overflow-hidden rounded-lg border border-border">
                            <div class="flex items-center gap-2 border-b border-border bg-muted/40 px-3 py-2"><UserRoundCheck class="h-3.5 w-3.5 text-muted-foreground" /><h3 class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Soins de ce passage</h3></div>
                            <div v-if="episode.care_record" class="space-y-3 p-3">
                                <dl v-if="careVitalsSummary(episode.care_record).length" class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs sm:grid-cols-3 lg:grid-cols-4">
                                    <div v-for="row in careVitalsSummary(episode.care_record)" :key="row.label"><dt class="text-muted-foreground">{{ row.label }}</dt><dd class="font-semibold text-foreground">{{ row.value }}</dd></div>
                                </dl>
                                <div v-if="episode.care_record.allergy_snapshot?.length" class="flex flex-wrap items-center gap-1.5 text-xs"><span class="font-medium text-muted-foreground">Allergies signalées :</span><Badge v-for="allergy in episode.care_record.allergy_snapshot" :key="allergy.uuid ?? allergy.substance" variant="destructive" class="px-1.5 py-0.5">{{ allergy.substance }}</Badge></div>
                                <ul v-if="episode.care_record.procedures?.length" class="space-y-1 text-xs">
                                    <li v-for="procedure in episode.care_record.procedures" :key="procedure.uuid" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5">
                                        <span class="text-muted-foreground"><span class="font-semibold text-foreground">{{ procedure.procedure_name }}</span> · {{ procedure.quantity }} · {{ procedure.performer?.name ?? '—' }}</span>
                                        <span class="text-muted-foreground">{{ formatDateTime(procedure.performed_at) }}</span>
                                    </li>
                                </ul>
                                <div v-if="episode.care_record.transmission_reason_html" class="grid gap-1 border-t border-border pt-2 text-xs leading-5 text-muted-foreground sm:grid-cols-[130px_minmax(0,1fr)]"><span class="font-semibold text-foreground">Transmis à Médecine :</span><ClinicalRichTextDisplay :html="episode.care_record.transmission_reason_html" /></div>
                            </div>
                            <p v-else class="px-3 py-3 text-xs text-muted-foreground">Aucune fiche de soins pour ce passage.</p>
                        </div>
                    </article>
                </div>
            </Card>
        </div>

        <Dialog
            :open="Boolean(paymentTarget)"
            title="Encaisser un paiement"
            :description="paymentTarget ? `${paymentTarget.invoice_number} · solde ${formatMoney(paymentTarget.balance_amount)}` : ''"
            @update:open="handlePaymentDialogOpen"
        >
            <template #icon><span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"><CircleDollarSign class="h-5 w-5" /></span></template>
            <form v-if="paymentTarget" class="space-y-4" @submit.prevent="recordPayment">
                <FormField v-if="openCashSessions.length > 1" label="Caisse" required :error="paymentForm.errors.cash_register_uuid">
                    <Select v-model="paymentForm.cash_register_uuid" class="w-full" :options="cashRegisterOptions" placeholder="Choisir…" />
                </FormField>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField label="Montant" required :error="paymentForm.errors.amount">
                        <Input v-model="paymentForm.amount" type="number" min="0.01" :max="paymentTarget.balance_amount" step="0.01" required autofocus />
                    </FormField>
                    <FormField label="Mode de paiement" required :error="paymentForm.errors.payment_method_id">
                        <Select class="w-full" :options="paymentMethodOptions" :model-value="String(paymentForm.payment_method_id ?? '')" placeholder="Choisir…" @update:model-value="setPaymentMethod" />
                    </FormField>
                </div>
                <FormField label="Référence" :error="paymentForm.errors.reference">
                    <Input v-model="paymentForm.reference" placeholder="Référence mobile money, virement…" />
                </FormField>
                <FormField label="Note" :error="paymentForm.errors.notes">
                    <Input v-model="paymentForm.notes" placeholder="Observation facultative" />
                </FormField>
                <p v-if="paymentForm.errors.cash_session" class="text-xs text-destructive">{{ paymentForm.errors.cash_session }}</p>
                <p v-if="paymentForm.errors.invoice_uuid" class="text-xs text-destructive">{{ paymentForm.errors.invoice_uuid }}</p>
                <p v-if="openCashSessions.length <= 1 && paymentForm.errors.cash_register_uuid" class="text-xs text-destructive">{{ paymentForm.errors.cash_register_uuid }}</p>
                <div class="flex flex-col-reverse gap-2 border-t border-border pt-5 sm:flex-row sm:justify-end">
                    <Button size="rg" variant="white-outline" type="button" :disabled="paymentForm.processing" @click="closePaymentDialog">Annuler</Button>
                    <Button size="rg" variant="success" type="submit" :disabled="paymentForm.processing"><CircleDollarSign class="h-4 w-4" />{{ paymentForm.processing ? 'Encaissement…' : 'Confirmer et générer le reçu' }}</Button>
                </div>
            </form>
        </Dialog>

        <Dialog
            :open="Boolean(cancellationTarget)"
            title="Annuler le paiement"
            :description="cancellationTarget ? `${cancellationTarget.payment_number} · ${formatMoney(cancellationTarget.amount)}` : ''"
            @update:open="handleCancellationDialogOpen"
        >
            <template #icon><span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300"><AlertTriangle class="h-5 w-5" /></span></template>
            <form v-if="cancellationTarget" @submit.prevent="cancelPayment">
                <FormField label="Motif" required :error="cancellationForm.errors.reason || cancellationForm.errors.payment">
                    <Textarea v-model="cancellationForm.reason" rows="4" maxlength="1000" required placeholder="Expliquez la correction à apporter…" />
                </FormField>
                <p class="mt-3 text-xs leading-5 text-muted-foreground">Le paiement, son reçu et la trace d’origine resteront visibles. Un mouvement inverse sera ajouté à la caisse ouverte.</p>
                <div class="mt-5 flex flex-col-reverse gap-2 border-t border-border pt-5 sm:flex-row sm:justify-end">
                    <Button size="rg" variant="white-outline" type="button" :disabled="cancellationForm.processing" @click="closeCancellationDialog">Retour</Button>
                    <Button size="rg" variant="danger-outline" type="submit" :disabled="cancellationForm.processing">{{ cancellationForm.processing ? 'Annulation…' : 'Confirmer l’annulation' }}</Button>
                </div>
            </form>
        </Dialog>
    </div>

    <Dialog
        :open="mutualAttachmentsOpen"
        title="Justificatifs de mutuelle"
        :description="activeMutualCoverage ? `${activeMutualCoverage.organization?.name} · ${activeMutualCoverage.attachments?.length ?? 0} fichier(s)` : ''"
        size="xl"
        content-class="max-h-[90vh] overflow-hidden"
        body-class="max-h-[calc(90vh-96px)] overflow-y-auto"
        @update:open="mutualAttachmentsOpen = $event"
    >
        <template #icon><span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><FileText class="h-5 w-5" /></span></template>
        <div v-if="activeMutualCoverage?.attachments?.length" class="grid gap-3 sm:grid-cols-2">
            <a
                v-for="attachment in activeMutualCoverage.attachments"
                :key="attachment.uuid"
                :href="mutualAttachmentUrl(attachment)"
                target="_blank"
                rel="noopener"
                class="overflow-hidden rounded-lg border border-border bg-card transition-all hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
            >
                <span class="flex h-48 items-center justify-center bg-muted/40">
                    <img v-if="attachment.is_image" :src="mutualAttachmentUrl(attachment)" :alt="attachment.original_name" loading="lazy" class="h-full w-full object-contain" />
                    <span v-else class="flex flex-col items-center text-muted-foreground">
                        <FileText class="h-9 w-9" />
                        <span class="mt-2 text-xs font-bold">Document PDF</span>
                    </span>
                </span>
                <span class="flex items-center gap-3 border-t border-border p-3">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-xs font-bold text-foreground">{{ attachment.original_name }}</span>
                        <span class="mt-0.5 block text-[11px] text-muted-foreground">{{ formatFileSize(attachment.size) }} · Ouvrir</span>
                    </span>
                    <ArrowRight class="h-4 w-4 shrink-0 text-muted-foreground" />
                </span>
            </a>
        </div>
        <p v-else class="py-8 text-center text-sm text-muted-foreground">Aucun justificatif disponible.</p>
    </Dialog>
</template>
