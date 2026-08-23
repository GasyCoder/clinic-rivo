<script setup>
import { computed, ref } from 'vue';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormLabel from '@/Components/UI/FormLabel.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconInput from '@/Components/UI/IconInput.vue';
import Input from '@/Components/UI/Input.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    patient: Object,
    account: Object,
    paymentMethods: Array,
    openCashSession: Object,
    billingCatalog: Array,
});

const { can } = usePermissions();
const activeSection = ref('overview');
const showInvoiceForm = ref(false);
const paymentTarget = ref(null);
const cancellationTarget = ref(null);
const validatingInvoice = ref(null);
const mutualAttachmentsOpen = ref(false);
const episodeQuery = ref('');
const episodeStatusFilter = ref('');
const episodeUrgencyFilter = ref('');
const storedEpisodeViewMode = (() => {
    try {
        return localStorage.getItem('rivo:patient-episodes:view');
    } catch {
        return null;
    }
})();
const episodeViewMode = ref(storedEpisodeViewMode ?? 'list');
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
});

const cancellationForm = useForm({ reason: '' });

const pendingItemsForEpisode = computed(() => (props.account?.billable_items ?? []).filter(
    (item) => item.status === 'PENDING' && item.episode.uuid === invoiceForm.episode_uuid,
));
const cancelledBillableItems = computed(() => (props.account?.billable_items ?? []).filter(
    (item) => item.status === 'CANCELLED',
));
const catalogByUuid = computed(() => new Map(
    (props.billingCatalog ?? []).map((item) => [item.uuid, item]),
));

const invoiceDraftTotal = computed(() => {
    const selectedItemsTotal = pendingItemsForEpisode.value
        .filter((item) => invoiceForm.billable_item_uuids.includes(item.uuid))
        .reduce((total, item) => total + Number(item.total_amount), 0);
    const catalogLinesTotal = invoiceForm.catalog_lines.reduce(
        (total, line) => total
            + (Number(line.quantity) || 0) * Number(catalogByUuid.value.get(line.catalog_item_uuid)?.tariff_amount ?? 0),
        0,
    );

    return selectedItemsTotal + catalogLinesTotal;
});

const addInvoiceLine = () => invoiceForm.catalog_lines.push({ catalog_item_uuid: '', quantity: 1 });
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
};

const closePaymentDialog = () => {
    if (!paymentForm.processing) paymentTarget.value = null;
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
    DISCHARGED: 'Sorti',
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
const pathwayStatusBadgeClass = (status) => ({
    'Soins terminés': 'border-green-200 text-green-700 dark:border-green-900 dark:text-green-300',
    'En consultation': 'border-primary-200 text-primary-700 dark:border-primary-900 dark:text-primary-300',
    'Pris en charge aux Soins': 'border-primary-200 text-primary-700 dark:border-primary-900 dark:text-primary-300',
    'En attente en Médecine': 'border-amber-200 text-amber-700 dark:border-amber-900 dark:text-amber-300',
    'En attente aux Soins': 'border-amber-200 text-amber-700 dark:border-amber-900 dark:text-amber-300',
}[status] ?? 'border-gray-200 text-slate-500 dark:border-gray-800 dark:text-slate-400');
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
    const left = bloodPressure(record.blood_pressure_left_systolic, record.blood_pressure_left_diastolic);
    const right = bloodPressure(record.blood_pressure_right_systolic, record.blood_pressure_right_diastolic);
    if (left) rows.push({ label: 'Tension bras gauche', value: left });
    if (right) rows.push({ label: 'Tension bras droit', value: right });
    if (record.temperature_celsius) rows.push({ label: 'Température', value: `${record.temperature_celsius} °C` });
    if (record.height_cm) rows.push({ label: 'Taille', value: `${record.height_cm} cm` });
    if (record.weight_kg) rows.push({ label: 'Poids', value: `${record.weight_kg} kg` });
    if (record.bmi) rows.push({ label: 'IMC', value: record.bmi });
    if (record.blood_group) rows.push({ label: 'Groupe sanguin', value: record.blood_group });
    if (record.known_diabetes !== null && record.known_diabetes !== undefined) rows.push({ label: 'Diabète connu', value: record.known_diabetes ? 'Oui' : 'Non' });
    if (record.smoker !== null && record.smoker !== undefined) rows.push({ label: 'Tabac', value: record.smoker ? 'Oui' : 'Non' });
    return rows;
};
const statusLabels = { OPEN: 'Ouvert', CLOSED: 'Clos', CANCELLED: 'Annulé' };
const invoiceStatusLabels = {
    DRAFT: 'Brouillon',
    VALIDATED: 'À payer',
    PARTIALLY_PAID: 'Paiement partiel',
    PAID: 'Payée',
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

const episodeStatusBadgeClass = (statusValue) => ({
    OPEN: 'border-gray-200 text-slate-600 dark:border-gray-800 dark:text-slate-300',
    CLOSED: 'border-gray-200 text-slate-500 dark:border-gray-800 dark:text-slate-400',
    CANCELLED: 'border-red-200 text-red-600 dark:border-red-900 dark:text-red-300',
}[statusValue] ?? 'border-gray-200 text-slate-600');

const invoiceStatusBadgeClass = (statusValue) => ({
    DRAFT: 'border-gray-200 text-slate-500 dark:border-gray-800 dark:text-slate-400',
    VALIDATED: 'border-amber-200 text-amber-700 dark:border-amber-900 dark:text-amber-300',
    PARTIALLY_PAID: 'border-orange-200 text-orange-700 dark:border-orange-900 dark:text-orange-300',
    PAID: 'border-green-200 text-green-700 dark:border-green-900 dark:text-green-300',
    CANCELLED: 'border-red-200 text-red-600 dark:border-red-900 dark:text-red-300',
}[statusValue] ?? 'border-gray-200 text-slate-600');
</script>

<template>
    <Head :title="formatPatientName(patient)" />

    <div class="mx-auto w-full max-w-[1480px] space-y-4">
        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-4 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex min-w-0 items-center gap-4">
                    <Avatar rounded size="lg" variant="slate-pale" :text="formatPatientInitials(patient)" aria-hidden="true" />
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">{{ patient.patient_number }}</span>
                            <span class="inline-flex rounded border border-gray-200 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:border-gray-800 dark:text-slate-300">
                                {{ patientTypeLabel }}
                            </span>
                            <span v-if="activeEmergencyEpisode" class="inline-flex items-center gap-1.5 rounded border border-red-200 px-2 py-0.5 text-[11px] font-bold uppercase text-red-600 dark:border-red-900 dark:text-red-300">
                                <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span> Urgence en cours
                            </span>
                        </div>
                        <h1 class="mt-1 truncate font-heading text-2xl font-bold text-slate-800 dark:text-white">
                            <span v-if="patient.civility">{{ civilityLabels[patient.civility] }}</span>
                            {{ formatPatientName(patient) }}
                        </h1>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button :as="Link" href="/patients" size="rg" variant="white-outline"><Icon class="text-lg" name="arrow-left" /><span class="ms-2">Liste des patients</span></Button>
                    <Button v-if="can('patients.update') && patient.patient_type !== 'STAFF'" :as="Link" :href="`/patients/${patient.uuid}/edit`" size="rg" variant="white-outline"><Icon class="text-lg" name="edit" /><span class="ms-2">Modifier</span></Button>
                    <Button v-if="can('cash.view')" :as="Link" href="/cash" size="rg" variant="white-outline"><Icon class="text-lg" name="wallet" /><span class="ms-2">Caisse</span></Button>
                </div>
            </div>

            <dl class="grid grid-cols-2 border-t border-gray-200 bg-gray-50/50 dark:border-gray-900 dark:bg-gray-1000/30 sm:grid-cols-4">
                <div class="border-e border-gray-200 px-5 py-3 dark:border-gray-900"><dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">{{ birthLabel }}</dt><dd class="mt-1 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ birthSummary }}</dd></div>
                <div class="px-5 py-3 sm:border-e sm:border-gray-200 sm:dark:border-gray-900"><dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Sexe</dt><dd class="mt-1 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ sexLabel(patient.sex) }}</dd></div>
                <div class="border-e border-t border-gray-200 px-5 py-3 dark:border-gray-900 sm:border-t-0"><dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Téléphone</dt><dd class="mt-1 truncate text-sm font-semibold text-slate-700 dark:text-slate-200">{{ patient.phone ?? 'Non renseigné' }}</dd></div>
                <div class="border-t border-gray-200 px-5 py-3 dark:border-gray-900 sm:border-t-0"><dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Dernier passage</dt><dd class="mt-1 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ latestEpisode?.episode_number ?? 'Aucun passage' }}</dd></div>
            </dl>

            <nav class="flex overflow-x-auto border-t border-gray-200 px-2 dark:border-gray-900" role="tablist" aria-label="Sections du dossier patient">
                <button type="button" :class="['relative flex shrink-0 items-center gap-2 px-4 py-3 text-sm font-semibold transition-colors', activeSection === 'overview' ? 'text-slate-800 dark:text-white' : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-200']" role="tab" :aria-selected="activeSection === 'overview'" @click="activeSection = 'overview'">
                    <Icon class="text-base" name="user" /> Aperçu
                    <span v-if="activeSection === 'overview'" class="absolute inset-x-4 bottom-0 h-0.5 bg-primary-600"></span>
                </button>
                <button v-if="account" type="button" :class="['relative flex shrink-0 items-center gap-2 px-4 py-3 text-sm font-semibold transition-colors', activeSection === 'billing' ? 'text-slate-800 dark:text-white' : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-200']" role="tab" :aria-selected="activeSection === 'billing'" @click="activeSection = 'billing'">
                    <Icon class="text-base" name="wallet" /> Facturation
                    <span class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[11px] text-slate-500 dark:bg-gray-900 dark:text-slate-300">{{ account.invoices.length }}</span>
                    <span v-if="activeSection === 'billing'" class="absolute inset-x-4 bottom-0 h-0.5 bg-primary-600"></span>
                </button>
                <button type="button" :class="['relative flex shrink-0 items-center gap-2 px-4 py-3 text-sm font-semibold transition-colors', activeSection === 'episodes' ? 'text-slate-800 dark:text-white' : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-200']" role="tab" :aria-selected="activeSection === 'episodes'" @click="activeSection = 'episodes'">
                    <Icon class="text-base" name="clock" /> Passages
                    <span class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[11px] text-slate-500 dark:bg-gray-900 dark:text-slate-300">{{ patient.episodes.length }}</span>
                    <span v-if="activeSection === 'episodes'" class="absolute inset-x-4 bottom-0 h-0.5 bg-primary-600"></span>
                </button>
            </nav>
        </section>

        <div v-if="activeEmergencyEpisode" class="flex items-start gap-3 rounded border border-gray-200 border-s-4 border-s-red-500 bg-white px-4 py-3 dark:border-gray-800 dark:border-s-red-500 dark:bg-gray-950" role="status">
            <Icon class="mt-0.5 shrink-0 text-lg text-red-600 dark:text-red-400" name="alert-circle" />
            <div><p class="text-xs font-bold uppercase tracking-wide text-red-700 dark:text-red-300">Passage prioritaire — {{ activeEmergencyEpisode.episode_number }}</p><p class="mt-0.5 text-xs leading-5 text-slate-500 dark:text-slate-400">Les soins urgents restent prioritaires et ne sont jamais bloqués par le paiement.</p></div>
        </div>

        <section v-if="account && activeSection === 'billing'" class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded border border-gray-200 text-slate-500 dark:border-gray-800 dark:text-slate-400"><Icon class="text-lg" name="wallet" /></span>
                    <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Compte patient</h2><p class="mt-0.5 text-xs text-slate-400">Factures, paiements successifs et reçus.</p></div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span v-if="openCashSession" class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-300"><span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> Caisse ouverte</span>
                    <span v-else-if="can('payments.create')" class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 dark:text-slate-400"><span class="h-1.5 w-1.5 rounded-full bg-slate-300 dark:bg-slate-600"></span> Caisse fermée</span>
                    <span v-if="patient.patient_type === 'STAFF'" class="text-xs font-medium text-slate-500">Couverture RH / Finance à calculer</span>
                    <Button v-else-if="can('billing.create') && patient.episodes.length" size="sm" :variant="showInvoiceForm ? 'white-outline' : 'primary'" type="button" @click="showInvoiceForm = !showInvoiceForm"><Icon class="text-base" :name="showInvoiceForm ? 'cross' : 'plus'" /><span class="ms-1.5">{{ showInvoiceForm ? 'Fermer' : 'Nouvelle facture' }}</span></Button>
                </div>
            </div>

            <div class="grid grid-cols-2 divide-x divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-900 dark:border-gray-900 lg:grid-cols-4 lg:divide-y-0">
                <div class="px-5 py-4"><p class="text-xs font-medium uppercase tracking-wide text-slate-400">À facturer</p><p class="mt-1.5 text-xl font-bold text-slate-700 dark:text-white">{{ formatMoney(account.unbilled_amount) }}</p></div>
                <div class="px-5 py-4"><p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total facturé</p><p class="mt-1.5 text-xl font-bold text-slate-700 dark:text-white">{{ formatMoney(account.total_amount) }}</p></div>
                <div class="px-5 py-4"><p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total payé</p><p class="mt-1.5 text-xl font-bold text-slate-700 dark:text-white">{{ formatMoney(account.paid_amount) }}</p></div>
                <div class="px-5 py-4"><p class="text-xs font-medium uppercase tracking-wide text-slate-500">Reste à payer</p><p :class="['mt-1.5 text-xl font-bold', account.balance_amount > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-800 dark:text-white']">{{ formatMoney(account.balance_amount) }}</p></div>
            </div>

            <form v-if="showInvoiceForm" class="border-b border-gray-200 bg-gray-50/60 p-5 dark:border-gray-900 dark:bg-gray-1000/30" @submit.prevent="createInvoice">
                <div class="mb-4 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <div><h3 class="text-sm font-bold text-slate-700 dark:text-white">Créer une facture</h3><p class="mt-0.5 text-xs text-slate-400">Sélectionnez les prestations transmises par les services ou ajoutez une désignation du référentiel.</p></div>
                    <p class="text-sm font-bold text-primary-600">Total : {{ formatMoney(invoiceDraftTotal) }}</p>
                </div>

                <FormGroup class="!mb-4 max-w-sm">
                    <FormLabel class="mb-1.5" for="invoice_episode">Passage concerné <span class="text-red-500">*</span></FormLabel>
                    <select id="invoice_episode" v-model="invoiceForm.episode_uuid" class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm text-slate-700 outline-none transition-all focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950" required>
                        <option value="" disabled>Choisir un passage</option>
                        <option v-for="episode in patient.episodes" :key="episode.uuid" :value="episode.uuid" :disabled="episode.status === 'CANCELLED'">{{ episode.episode_number }} · {{ statusLabels[episode.status] }}</option>
                    </select>
                    <FormError v-if="invoiceForm.errors.episode_uuid">{{ invoiceForm.errors.episode_uuid }}</FormError>
                </FormGroup>

                <div v-if="pendingItemsForEpisode.length" class="mb-4 overflow-hidden rounded border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950">
                    <div class="border-b border-gray-200 px-4 py-2.5 text-xs font-bold uppercase tracking-wide text-slate-500 dark:border-gray-800">Prestations en attente de facturation</div>
                    <label v-for="item in pendingItemsForEpisode" :key="item.uuid" class="flex cursor-pointer items-start gap-3 border-b border-gray-100 px-4 py-3 last:border-0 hover:bg-gray-50 dark:border-gray-900 dark:hover:bg-gray-900/50">
                        <input v-model="invoiceForm.billable_item_uuids" :value="item.uuid" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-medium text-slate-700 dark:text-slate-200">
                                {{ item.description }}
                                <span class="text-xs font-normal text-slate-400">{{ moduleLabels[item.source_module] ?? item.source_module }}</span>
                                <span v-if="item.payment_required_before_fulfillment" class="rounded border border-gray-200 px-1.5 py-0.5 text-[11px] font-medium text-slate-500 dark:border-gray-700 dark:text-slate-400">Paiement préalable requis</span>
                            </span>
                            <span class="mt-0.5 block text-xs text-slate-400">{{ item.quantity }} × {{ formatMoney(item.unit_price) }}</span>
                        </span>
                        <span class="shrink-0 text-sm font-bold text-slate-700 dark:text-white">{{ formatMoney(item.total_amount) }}</span>
                    </label>
                </div>

                <p v-else class="mb-4 rounded border border-dashed border-gray-300 px-4 py-3 text-xs text-slate-400 dark:border-gray-700">Aucune prestation métier en attente pour ce passage.</p>

                <div class="space-y-2">
                    <div v-for="(line, index) in invoiceForm.catalog_lines" :key="index" class="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_110px_170px_36px]">
                        <select v-model="line.catalog_item_uuid" :aria-label="`Désignation ${index + 1}`" class="block h-9 w-full rounded border-gray-200 bg-white py-1.5 ps-3 pe-9 text-sm text-slate-700 focus:border-primary-500 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white" required>
                            <option value="" disabled>Choisir une prestation</option>
                            <option v-for="catalogItem in billingCatalog" :key="catalogItem.uuid" :value="catalogItem.uuid">{{ catalogItem.code }} · {{ catalogItem.name }} — {{ formatMoney(catalogItem.tariff_amount) }}</option>
                        </select>
                        <Input v-model="line.quantity" type="number" min="0.01" step="0.01" aria-label="Quantité" placeholder="Quantité" required />
                        <div class="flex h-9 items-center justify-end rounded border border-gray-200 bg-gray-50 px-3 text-sm font-bold text-slate-600 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-200">
                            {{ formatMoney((Number(line.quantity) || 0) * Number(catalogByUuid.get(line.catalog_item_uuid)?.tariff_amount ?? 0)) }}
                        </div>
                        <Button icon size="rg" variant="danger-outline" type="button" aria-label="Retirer cette ligne" @click="removeInvoiceLine(index)"><Icon class="text-base" name="trash" /></Button>
                    </div>
                </div>
                <FormError v-if="invoiceForm.errors.catalog_lines" class="mt-2">{{ invoiceForm.errors.catalog_lines }}</FormError>
                <FormError v-if="invoiceForm.errors['catalog_lines.0.catalog_item_uuid']" class="mt-2">{{ invoiceForm.errors['catalog_lines.0.catalog_item_uuid'] }}</FormError>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <button v-if="billingCatalog?.length" type="button" class="inline-flex items-center gap-1 text-xs font-bold text-primary-600 hover:text-primary-700" @click="addInvoiceLine"><Icon name="plus" /> Ajouter depuis le référentiel</button>
                    <span v-else class="text-xs text-slate-400">Aucune prestation avec tarif actif. Le Super Administrateur doit compléter le référentiel.</span>
                    <Button size="rg" variant="primary" type="submit" :disabled="invoiceForm.processing"><Icon class="text-lg" name="file-text" /><span class="ms-2">{{ invoiceForm.processing ? 'Création…' : 'Créer le brouillon' }}</span></Button>
                </div>
            </form>

            <details v-if="cancelledBillableItems.length" class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                <summary class="cursor-pointer text-xs font-medium text-slate-500">Prestations annulées ({{ cancelledBillableItems.length }})</summary>
                <ul class="mt-3 space-y-2">
                    <li v-for="item in cancelledBillableItems" :key="item.uuid" class="flex flex-col justify-between gap-1 text-xs text-slate-400 sm:flex-row">
                        <span><span class="line-through">{{ item.description }} · {{ formatMoney(item.total_amount) }}</span> · {{ moduleLabels[item.source_module] ?? item.source_module }} · passage {{ item.episode.episode_number }}</span>
                        <span :title="item.cancellation_reason">Annulée le {{ formatDateTime(item.cancelled_at) }}</span>
                    </li>
                </ul>
            </details>

            <div v-if="account.invoices.length === 0" class="px-5 py-10 text-center">
                <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-xl" name="file-text" /></span>
                <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-200">Aucune facture</p><p class="mt-1 text-xs text-slate-400">Les factures liées aux passages apparaîtront ici.</p>
            </div>

            <div v-else class="space-y-3 p-5">
                <article v-for="invoice in account.invoices" :key="invoice.uuid" class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-900">
                    <div class="flex flex-col gap-3 border-b border-gray-100 bg-gray-50/60 px-4 py-3 dark:border-gray-900 dark:bg-gray-1000/30 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-bold text-slate-700 dark:text-white">{{ invoice.invoice_number }}</h3>
                            <span :class="['rounded border px-2 py-0.5 text-xs font-medium', invoiceStatusBadgeClass(invoice.status)]">{{ invoiceStatusLabels[invoice.status] }}</span>
                            <span class="text-xs text-slate-400">Passage {{ invoice.episode.episode_number }} · {{ formatDateTime(invoice.created_at) }}</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <Button v-if="invoice.status === 'DRAFT' && can('billing.validate')" size="sm" variant="white-outline" type="button" :disabled="validatingInvoice === invoice.uuid" @click="validateInvoice(invoice)"><Icon class="text-base" name="check" /><span class="ms-1.5">Valider</span></Button>
                            <Button v-if="can('billing.print')" :as="Link" :href="`/invoices/${invoice.uuid}`" size="sm" variant="white-outline"><Icon class="text-base" name="printer" /><span class="ms-1.5">Facture</span></Button>
                            <Button v-if="['VALIDATED', 'PARTIALLY_PAID'].includes(invoice.status) && can('payments.create') && openCashSession" size="sm" variant="primary" type="button" @click="openPaymentDialog(invoice)"><Icon class="text-base" name="money" /><span class="ms-1.5">Encaisser</span></Button>
                            <Button v-else-if="['VALIDATED', 'PARTIALLY_PAID'].includes(invoice.status) && can('payments.create') && !openCashSession" :as="Link" href="/cash" size="sm" variant="white-outline">Ouvrir la caisse</Button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[760px] border-collapse">
                            <caption class="sr-only">Désignations de la facture {{ invoice.invoice_number }}</caption>
                            <thead>
                                <tr class="border-b border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                                    <th class="px-4 py-2.5 text-start text-[11px] font-bold uppercase tracking-wide text-slate-400">Désignation</th>
                                    <th class="w-40 px-4 py-2.5 text-start text-[11px] font-bold uppercase tracking-wide text-slate-400">Service</th>
                                    <th class="w-24 px-4 py-2.5 text-center text-[11px] font-bold uppercase tracking-wide text-slate-400">Qté</th>
                                    <th class="w-40 px-4 py-2.5 text-end text-[11px] font-bold uppercase tracking-wide text-slate-400">Tarif unitaire</th>
                                    <th class="w-40 px-4 py-2.5 text-end text-[11px] font-bold uppercase tracking-wide text-slate-400">Montant</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                                <tr v-for="line in invoice.lines" :key="line.id">
                                    <td class="px-4 py-3 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ line.description }}</td>
                                    <td class="px-4 py-3 text-sm text-slate-500 dark:text-slate-400">{{ moduleLabels[line.source_module] ?? line.source_module }}</td>
                                    <td class="px-4 py-3 text-center text-sm tabular-nums text-slate-600 dark:text-slate-300">{{ formatQuantity(line.quantity) }}</td>
                                    <td class="px-4 py-3 text-end text-sm tabular-nums text-slate-600 dark:text-slate-300">{{ formatMoney(line.unit_price) }}</td>
                                    <td class="px-4 py-3 text-end text-sm font-bold tabular-nums text-slate-700 dark:text-white">{{ formatMoney(line.line_total) }}</td>
                                </tr>
                            </tbody>
                            <tfoot class="border-t border-gray-200 bg-gray-50/60 dark:border-gray-900 dark:bg-gray-1000/30">
                                <tr>
                                    <th colspan="4" class="px-4 pt-3 pb-1 text-end text-xs font-medium text-slate-500">Total de la facture</th>
                                    <td class="px-4 pt-3 pb-1 text-end text-sm font-bold tabular-nums text-slate-800 dark:text-white">{{ formatMoney(invoice.total_amount) }}</td>
                                </tr>
                                <tr>
                                    <th colspan="4" class="px-4 py-1 text-end text-xs font-medium text-slate-500">Montant payé</th>
                                    <td class="px-4 py-1 text-end text-sm font-semibold tabular-nums text-slate-700 dark:text-slate-200">{{ formatMoney(invoice.paid_amount) }}</td>
                                </tr>
                                <tr>
                                    <th colspan="4" class="px-4 pt-1 pb-3 text-end text-xs font-bold text-slate-700 dark:text-slate-200">Reste à payer</th>
                                    <td :class="['px-4 pt-1 pb-3 text-end text-base font-bold tabular-nums', invoice.balance_amount > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400']">{{ formatMoney(invoice.balance_amount) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div v-if="invoice.payments.length" class="border-t border-gray-100 dark:border-gray-900">
                        <div class="px-4 pt-2.5 text-[11px] font-bold uppercase tracking-wide text-slate-400">Paiements</div>
                        <div v-for="payment in invoice.payments" :key="payment.uuid" class="flex flex-col gap-2 border-b border-gray-100 px-4 py-2.5 last:border-0 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-start gap-2 text-xs text-slate-500">
                                <Icon :class="['mt-0.5 shrink-0 text-base', payment.status === 'CANCELLED' ? 'text-slate-300 dark:text-slate-700' : 'text-green-500']" name="check-circle" />
                                <span>
                                    <span :class="['font-bold', payment.status === 'CANCELLED' ? 'text-slate-400 line-through' : 'text-slate-700 dark:text-slate-200']">{{ formatMoney(payment.amount) }}</span>
                                    <span> · {{ payment.method }} · {{ formatDateTime(payment.paid_at) }}</span>
                                    <span class="block text-slate-400 sm:ms-2 sm:inline">par {{ payment.cashier }}</span>
                                    <span v-if="payment.status === 'CANCELLED'" class="ms-2 rounded border border-red-200 px-1.5 py-0.5 font-medium text-red-600 dark:border-red-900 dark:text-red-300" :title="payment.cancellation_reason">Annulé</span>
                                </span>
                            </div>
                            <div class="flex items-center gap-3 ps-6 sm:ps-0">
                                <button v-if="payment.status === 'COMPLETED' && can('payments.cancel') && openCashSession?.uuid === payment.cash_session_uuid" type="button" class="text-xs font-medium text-red-600 hover:underline" @click="openCancellationDialog(payment)">Annuler</button>
                                <Link v-if="payment.receipt" :href="`/receipts/${payment.receipt.uuid}`" class="inline-flex items-center gap-1 text-xs font-bold text-primary-600 hover:underline"><Icon name="file-text" /> {{ payment.receipt.receipt_number }}</Link>
                            </div>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <div v-if="activeSection === 'overview'" class="grid grid-cols-1 gap-4 xl:grid-cols-3">
            <div class="space-y-4 xl:col-span-2">
                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                    <div class="flex items-start gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-lg" name="user" /></span>
                        <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Informations administratives</h2><p class="mt-0.5 text-xs text-slate-400">Coordonnées et document d’identité du patient.</p></div>
                    </div>
                    <dl class="grid grid-cols-1 sm:grid-cols-2">
                        <div class="border-b border-gray-100 px-5 py-3.5 dark:border-gray-900 sm:border-e"><dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Type de patient</dt><dd class="mt-1.5 text-sm font-medium text-slate-700 dark:text-slate-200">{{ patientTypeLabel }}</dd></div>
                        <div class="border-b border-gray-100 px-5 py-3.5 dark:border-gray-900"><dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Situation maritale</dt><dd class="mt-1.5 text-sm font-medium text-slate-700 dark:text-slate-200">{{ maritalStatusLabel }}</dd></div>
                        <div class="border-b border-gray-100 px-5 py-3.5 dark:border-gray-900 sm:border-e"><dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Nombre d’enfants</dt><dd class="mt-1.5 text-sm font-medium text-slate-700 dark:text-slate-200">{{ childrenCountLabel }}</dd></div>
                        <div class="border-b border-gray-100 px-5 py-3.5 dark:border-gray-900"><dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Profession</dt><dd class="mt-1.5 text-sm font-medium text-slate-700 dark:text-slate-200">{{ patient.profession || 'Non renseignée' }}</dd></div>
                        <div class="border-b border-gray-100 px-5 py-3.5 dark:border-gray-900 sm:border-e"><dt class="flex items-center gap-2 text-[11px] font-medium uppercase tracking-wide text-slate-400"><Icon name="call" /> Téléphone</dt><dd class="mt-1.5 text-sm font-medium text-slate-700 dark:text-slate-200">{{ patient.phone ?? 'Non renseigné' }}</dd></div>
                        <div class="border-b border-gray-100 px-5 py-3.5 dark:border-gray-900"><dt class="flex items-center gap-2 text-[11px] font-medium uppercase tracking-wide text-slate-400"><Icon name="mail" /> Email</dt><dd class="mt-1.5 break-all text-sm font-medium text-slate-700 dark:text-slate-200">{{ patient.email ?? 'Non renseigné' }}</dd></div>
                        <div class="border-b border-gray-100 px-5 py-3.5 dark:border-gray-900 sm:border-b-0 sm:border-e"><dt class="flex items-center gap-2 text-[11px] font-medium uppercase tracking-wide text-slate-400"><Icon name="map-pin" /> Adresse</dt><dd class="mt-1.5 text-sm font-medium leading-5 text-slate-700 dark:text-slate-200">{{ patient.address_entry?.label ?? patient.address ?? 'Non renseignée' }}</dd></div>
                        <div class="px-5 py-3.5"><dt class="flex items-center gap-2 text-[11px] font-medium uppercase tracking-wide text-slate-400"><Icon name="cards" /> Pièce d’identité</dt><dd class="mt-1.5 text-sm font-medium text-slate-700 dark:text-slate-200"><template v-if="patient.identity_document_type">{{ patient.identity_document_type === 'CIN' ? 'CIN' : 'Passeport' }} · {{ patient.identity_document_number }}</template><template v-else>Non renseignée</template></dd></div>
                    </dl>
                </section>
            </div>

            <aside class="space-y-4">
                <section class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950">
                    <div class="flex items-center justify-between gap-3"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Passages récents</h2><button v-if="patient.episodes.length" type="button" class="text-xs font-semibold text-primary-600 hover:text-primary-700" @click="activeSection = 'episodes'">Voir tout</button></div>
                    <ul v-if="recentEpisodes.length" class="mt-3 space-y-3">
                        <li v-for="episode in recentEpisodes" :key="episode.uuid" class="border-t border-gray-100 pt-3 first:border-t-0 first:pt-0 dark:border-gray-900">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="font-mono text-xs font-bold text-slate-700 dark:text-white">{{ episode.episode_number }}</span>
                                <span :class="['rounded border px-1.5 py-0.5 text-[10px] font-medium', episodeStatusBadgeClass(episode.status)]">{{ statusLabels[episode.status] }}</span>
                                <span v-if="episode.priority === 'EMERGENCY'" class="inline-flex items-center gap-1 text-[10px] font-bold uppercase text-red-600 dark:text-red-300"><span class="h-1 w-1 rounded-full bg-red-500"></span> Urgence</span>
                            </div>
                            <p class="mt-1"><span :class="['rounded border px-1.5 py-0.5 text-[10px] font-medium', pathwayStatusBadgeClass(pathwayStatus(episode))]">{{ pathwayStatus(episode) }}</span></p>
                            <p class="mt-1 text-[11px] text-slate-400">{{ formatDateTime(episode.started_at) }}</p>
                        </li>
                    </ul>
                    <p v-else class="mt-3 text-sm text-slate-400">Aucun passage enregistré.</p>
                </section>

                <section v-if="patient.patient_type === 'MUTUAL' && can('patient_coverages.view')" class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950">
                    <h2 class="text-sm font-bold text-slate-700 dark:text-white">Couverture mutuelle</h2>
                    <dl v-if="activeMutualCoverage" class="mt-3 space-y-2.5 text-xs">
                        <div class="flex justify-between gap-4"><dt class="text-slate-400">Organisme</dt><dd class="text-end font-semibold text-slate-700 dark:text-slate-200">{{ activeMutualCoverage.organization?.name }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-400">Bénéficiaire</dt><dd class="text-end font-semibold text-slate-700 dark:text-slate-200">{{ beneficiaryTypeLabels[activeMutualCoverage.beneficiary_type] ?? activeMutualCoverage.beneficiary_type }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-400">Matricule</dt><dd class="text-end font-mono font-semibold text-slate-700 dark:text-slate-200">{{ activeMutualCoverage.membership_number }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-400">Entreprise</dt><dd class="text-end font-semibold text-slate-700 dark:text-slate-200">{{ activeMutualCoverage.employer_name }}</dd></div>
                    </dl>
                    <p v-else class="mt-3 text-sm text-slate-400">Aucune couverture active.</p>
                    <div v-if="activeMutualCoverage?.attachments?.length && can('patient_coverage_documents.view')" class="mt-4 border-t border-gray-100 pt-3 dark:border-gray-900">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Justificatifs</p>
                            <span class="text-[11px] text-slate-400">{{ activeMutualCoverage.attachments.length }}/5</span>
                        </div>
                        <button
                            type="button"
                            class="mt-2 flex w-full items-center gap-3 rounded-md border border-gray-200 p-2.5 text-start transition-colors hover:border-slate-300 hover:bg-gray-50/60 focus:outline-none focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:hover:border-gray-700 dark:hover:bg-gray-1000/40 dark:focus:ring-primary-950"
                            :aria-label="`Voir les ${activeMutualCoverage.attachments.length} justificatifs de mutuelle`"
                            @click="mutualAttachmentsOpen = true"
                        >
                            <span class="relative flex h-16 w-20 shrink-0 items-center justify-center overflow-hidden rounded border border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900">
                                <img
                                    v-if="activeMutualCoverage.attachments[0].is_image"
                                    :src="mutualAttachmentUrl(activeMutualCoverage.attachments[0])"
                                    :alt="activeMutualCoverage.attachments[0].original_name"
                                    loading="lazy"
                                    class="h-full w-full object-cover"
                                />
                                <span v-else class="flex flex-col items-center text-slate-500 dark:text-slate-300">
                                    <Icon class="text-xl" name="file-text" />
                                    <span class="mt-0.5 text-[9px] font-bold">PDF</span>
                                </span>
                                <span v-if="activeMutualCoverage.attachments.length > 1" class="absolute inset-0 flex items-center justify-center bg-slate-900/65 text-sm font-bold text-white">
                                    +{{ activeMutualCoverage.attachments.length - 1 }}
                                </span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-xs font-bold text-slate-700 dark:text-white">{{ activeMutualCoverage.attachments[0].original_name }}</span>
                                <span class="mt-1 block text-[11px] text-slate-400">{{ activeMutualCoverage.attachments.length }} fichier{{ activeMutualCoverage.attachments.length > 1 ? 's' : '' }} · Voir les justificatifs</span>
                            </span>
                            <Icon class="shrink-0 text-lg text-slate-400" name="eye" />
                        </button>
                    </div>
                </section>

                <section v-if="patient.patient_type === 'STAFF' && can('patient_staff_links.view')" class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950">
                    <h2 class="text-sm font-bold text-slate-700 dark:text-white">Dossier personnel lié</h2>
                    <template v-if="activeStaffLink?.employee">
                        <p class="mt-3 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ formatPatientName(activeStaffLink.employee) }}</p>
                        <p class="mt-1 font-mono text-xs text-slate-400">{{ activeStaffLink.employee.employee_number }}</p>
                        <p class="mt-2 text-xs text-slate-500">{{ activeStaffLink.employee.profession || 'Fonction non renseignée' }}</p>
                        <p class="mt-3 border-t border-gray-100 pt-3 text-xs leading-5 text-slate-400 dark:border-gray-900">Prise en charge hors bloc et crédit bloc calculés par RH / Finance avant facturation.</p>
                    </template>
                    <p v-else class="mt-3 text-sm text-slate-400">Aucun lien actif avec un dossier RH.</p>
                </section>

                <section v-if="account" class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950">
                    <div class="flex items-center justify-between gap-3"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Situation financière</h2><button type="button" class="text-xs font-semibold text-primary-600 hover:text-primary-700" @click="activeSection = 'billing'">Détails</button></div>
                    <p class="mt-4 text-[11px] font-medium uppercase tracking-wide text-slate-400">Reste à payer</p><p class="mt-1 text-2xl font-bold text-slate-800 dark:text-white">{{ formatMoney(account.balance_amount) }}</p>
                    <div class="mt-4 grid grid-cols-2 gap-4 border-t border-gray-200 pt-3 text-xs dark:border-gray-900"><div><p class="text-slate-400">Facturé</p><p class="mt-1 font-bold text-slate-700 dark:text-slate-200">{{ formatMoney(account.total_amount) }}</p></div><div><p class="text-slate-400">Payé</p><p class="mt-1 font-bold text-slate-700 dark:text-slate-200">{{ formatMoney(account.paid_amount) }}</p></div></div>
                </section>
            </aside>
        </div>

        <div v-else-if="activeSection === 'episodes'" class="space-y-4">
            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Repères médicaux déclarés</h2><p class="mt-0.5 text-xs text-slate-400">Dossier médical permanent du patient, confirmé et complété au fil des passages.</p></div>
                <div class="grid grid-cols-1 divide-y divide-gray-200 dark:divide-gray-900 md:grid-cols-2 md:divide-x md:divide-y-0">
                    <div class="px-5 py-4">
                        <h3 class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-500"><Icon class="text-slate-400" name="alert-circle" /> Allergies</h3>
                        <ul v-if="patient.allergies.length" class="mt-3 space-y-2.5 text-sm"><li v-for="allergy in patient.allergies" :key="allergy.id" class="text-slate-600 dark:text-slate-300"><span class="font-semibold text-slate-700 dark:text-white">{{ allergy.substance }}</span><span v-if="allergy.severity" class="ms-1.5 text-xs text-red-600 dark:text-red-300">{{ severityLabels[allergy.severity] }}</span><p v-if="allergy.reaction" class="mt-0.5 text-xs text-slate-400">{{ allergy.reaction }}</p></li></ul>
                        <p v-else class="mt-3 text-sm text-slate-400">Aucune allergie connue.</p>
                    </div>
                    <div class="px-5 py-4">
                        <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Antécédents médicaux</h3>
                        <ul v-if="patient.antecedents.length" class="mt-3 space-y-2 text-sm text-slate-600 dark:text-slate-300"><li v-for="antecedent in patient.antecedents" :key="antecedent.id" class="flex gap-2"><span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-slate-400"></span><span>{{ antecedent.description }}</span></li></ul>
                        <p v-else class="mt-3 text-sm text-slate-400">Aucun antécédent connu.</p>
                    </div>
                </div>
            </section>

            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Historique des passages</h2><p class="mt-0.5 text-xs text-slate-400">Les arrivées sont créées depuis la Réception patient.</p></div>
                    <span class="text-xs font-medium text-slate-400">{{ filteredEpisodes.length }} sur {{ patient.episodes.length }} passage{{ patient.episodes.length > 1 ? 's' : '' }}</span>
                </div>
                <div v-if="patient.episodes.length" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <div class="flex flex-1 flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        <div class="w-full sm:max-w-xs sm:flex-1"><IconInput v-model="episodeQuery" icon="search" type="search" placeholder="Numéro de passage…" autocomplete="off" /></div>
                        <span class="relative"><select v-model="episodeStatusFilter" class="h-9 appearance-none bg-none rounded border border-gray-200 bg-white px-3 pe-9 text-sm text-slate-700 outline-none transition-all focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950"><option value="">Tout statut</option><option value="OPEN">Ouvert</option><option value="CLOSED">Clos</option><option value="CANCELLED">Annulé</option></select><span class="pointer-events-none absolute inset-y-0 end-0 flex w-9 items-center justify-center text-slate-400"><Icon class="text-sm" name="chevron-down" /></span></span>
                        <span class="relative"><select v-model="episodeUrgencyFilter" class="h-9 appearance-none bg-none rounded border border-gray-200 bg-white px-3 pe-9 text-sm text-slate-700 outline-none transition-all focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950"><option value="">Toute priorité</option><option value="emergency">Urgence</option><option value="normal">Normal</option></select><span class="pointer-events-none absolute inset-y-0 end-0 flex w-9 items-center justify-center text-slate-400"><Icon class="text-sm" name="chevron-down" /></span></span>
                    </div>
                    <div class="inline-flex shrink-0 self-start rounded-md border border-gray-200 p-0.5 dark:border-gray-800 sm:self-auto" role="group" aria-label="Mode d’affichage">
                        <button type="button" :class="['flex h-8 w-8 items-center justify-center rounded transition-colors', episodeViewMode === 'list' ? 'bg-gray-100 text-slate-700 dark:bg-gray-900 dark:text-white' : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-300']" aria-label="Vue liste" :aria-pressed="episodeViewMode === 'list'" @click="setEpisodeViewMode('list')"><Icon class="text-lg" name="list" /></button>
                        <button type="button" :class="['flex h-8 w-8 items-center justify-center rounded transition-colors', episodeViewMode === 'grid' ? 'bg-gray-100 text-slate-700 dark:bg-gray-900 dark:text-white' : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-300']" aria-label="Vue grille" :aria-pressed="episodeViewMode === 'grid'" @click="setEpisodeViewMode('grid')"><Icon class="text-lg" name="grid-alt" /></button>
                    </div>
                </div>
            </div>
            <div v-if="patient.episodes.length === 0" class="px-5 py-12 text-center"><span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-xl" name="clock" /></span><p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-200">Aucun passage enregistré</p><p class="mt-1 text-xs text-slate-400">Le premier passage sera créé depuis la Réception.</p></div>
            <div v-else-if="filteredEpisodes.length === 0" class="px-5 py-12 text-center"><span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-xl" name="search" /></span><p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-200">Aucun passage ne correspond</p><p class="mt-1 text-xs text-slate-400">Modifiez la recherche ou les filtres.</p></div>
            <div v-else :class="episodeViewMode === 'grid' ? 'grid grid-cols-1 gap-4 p-5 lg:grid-cols-2' : 'divide-y divide-gray-200 dark:divide-gray-900'">
                <article v-for="episode in filteredEpisodes" :key="episode.uuid" :class="episodeViewMode === 'grid' ? 'rounded-lg border border-gray-200 p-5 dark:border-gray-800' : 'p-5'">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-sm font-bold text-slate-700 dark:text-white">{{ episode.episode_number }}</span>
                                <span :class="['rounded border px-2 py-0.5 text-[11px] font-medium', episodeStatusBadgeClass(episode.status)]">{{ statusLabels[episode.status] }}</span>
                                <span v-if="episode.priority === 'EMERGENCY'" class="inline-flex items-center gap-1.5 text-[11px] font-bold uppercase text-red-600 dark:text-red-300"><span class="h-1.5 w-1.5 rounded-full bg-red-500"></span> Urgence</span>
                                <span class="text-xs text-slate-400">{{ episode.status === 'OPEN' ? 'Passage actif' : 'Passage terminé' }}</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-400">Démarré le {{ formatDateTime(episode.started_at) }}</p>
                        </div>
                        <div class="shrink-0 sm:text-end">
                            <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Parcours clinique</p>
                            <p class="mt-1"><span :class="['rounded border px-2 py-0.5 text-[11px] font-medium', pathwayStatusBadgeClass(pathwayStatus(episode))]">{{ pathwayStatus(episode) }}</span></p>
                        </div>
                    </div>

                    <div v-if="episode.emergency_contact_name" class="mt-3 inline-flex flex-wrap items-center gap-x-2 gap-y-1 rounded border border-gray-200 px-3 py-1.5 text-xs text-slate-500 dark:border-gray-800 dark:text-slate-300">
                        <Icon class="text-slate-400" name="call" />
                        <span class="font-semibold text-slate-700 dark:text-white">{{ episode.emergency_contact_name }}</span>
                        <span v-if="episode.emergency_contact_relationship">· {{ episode.emergency_contact_relationship }}</span>
                        <span v-if="episode.emergency_contact_phone">· {{ episode.emergency_contact_phone }}</span>
                    </div>

                    <div v-if="can('care.view')" class="mt-4 overflow-hidden rounded-md border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center gap-2 border-b border-gray-200 bg-gray-50/70 px-3 py-2 dark:border-gray-800 dark:bg-gray-1000/30"><Icon class="text-sm text-slate-400" name="user-check" /><h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Soins de ce passage</h3></div>
                        <div v-if="episode.care_record" class="space-y-3 p-3">
                            <dl v-if="careVitalsSummary(episode.care_record).length" class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs sm:grid-cols-3 lg:grid-cols-4">
                                <div v-for="row in careVitalsSummary(episode.care_record)" :key="row.label"><dt class="text-slate-400">{{ row.label }}</dt><dd class="font-semibold text-slate-700 dark:text-slate-200">{{ row.value }}</dd></div>
                            </dl>
                            <div v-if="episode.care_record.allergy_snapshot?.length" class="flex flex-wrap items-center gap-1.5 text-xs"><span class="font-medium text-slate-400">Allergies signalées :</span><span v-for="allergy in episode.care_record.allergy_snapshot" :key="allergy.uuid ?? allergy.substance" class="rounded border border-red-200 bg-red-50 px-1.5 py-0.5 font-semibold text-red-700 dark:border-red-900 dark:bg-red-950/20 dark:text-red-200">{{ allergy.substance }}</span></div>
                            <ul v-if="episode.care_record.procedures?.length" class="space-y-1 text-xs">
                                <li v-for="procedure in episode.care_record.procedures" :key="procedure.uuid" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5">
                                    <span class="text-slate-600 dark:text-slate-300"><span class="font-semibold text-slate-700 dark:text-white">{{ procedure.procedure_name }}</span> · {{ procedure.quantity }} · {{ procedure.performer?.name ?? '—' }}</span>
                                    <span class="text-slate-400">{{ formatDateTime(procedure.performed_at) }}</span>
                                </li>
                            </ul>
                            <p v-if="episode.care_record.transmission_reason" class="border-t border-gray-100 pt-2 text-xs leading-5 text-slate-500 dark:border-gray-900 dark:text-slate-300"><span class="font-semibold text-slate-600 dark:text-slate-200">Transmis à Médecine :</span> {{ episode.care_record.transmission_reason }}</p>
                        </div>
                        <p v-else class="px-3 py-3 text-xs text-slate-400">Aucune fiche de soins pour ce passage.</p>
                    </div>
                </article>
            </div>
            </section>
        </div>

        <div v-if="paymentTarget" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="closePaymentDialog">
            <section class="w-full max-w-lg rounded-lg border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="payment-dialog-title">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-start gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-600 dark:bg-slate-900 dark:text-slate-300"><Icon class="text-xl" name="money" /></span><div><h2 id="payment-dialog-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">Encaisser un paiement</h2><p class="mt-1 text-sm text-slate-400">{{ paymentTarget.invoice_number }} · solde {{ formatMoney(paymentTarget.balance_amount) }}</p></div></div>
                    <button type="button" class="text-slate-400 hover:text-slate-600" aria-label="Fermer" @click="closePaymentDialog"><Icon class="text-xl" name="cross" /></button>
                </div>

                <form class="mt-6 space-y-4" @submit.prevent="recordPayment">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormGroup class="!mb-0"><FormLabel class="mb-1.5" for="payment_amount">Montant <span class="text-red-500">*</span></FormLabel><Input id="payment_amount" v-model="paymentForm.amount" type="number" min="0.01" :max="paymentTarget.balance_amount" step="0.01" required autofocus /><FormError v-if="paymentForm.errors.amount">{{ paymentForm.errors.amount }}</FormError></FormGroup>
                        <FormGroup class="!mb-0"><FormLabel class="mb-1.5" for="payment_method">Mode de paiement <span class="text-red-500">*</span></FormLabel><select id="payment_method" v-model="paymentForm.payment_method_id" class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm text-slate-700 outline-none transition-all focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950" required><option v-for="method in paymentMethods" :key="method.id" :value="method.id">{{ method.name }}</option></select><FormError v-if="paymentForm.errors.payment_method_id">{{ paymentForm.errors.payment_method_id }}</FormError></FormGroup>
                    </div>
                    <FormGroup class="!mb-0"><FormLabel class="mb-1.5" for="payment_reference">Référence</FormLabel><Input id="payment_reference" v-model="paymentForm.reference" placeholder="Référence mobile money, virement…" /><FormError v-if="paymentForm.errors.reference">{{ paymentForm.errors.reference }}</FormError></FormGroup>
                    <FormGroup class="!mb-0"><FormLabel class="mb-1.5" for="payment_notes">Note</FormLabel><Input id="payment_notes" v-model="paymentForm.notes" placeholder="Observation facultative" /><FormError v-if="paymentForm.errors.notes">{{ paymentForm.errors.notes }}</FormError></FormGroup>
                    <FormError v-if="paymentForm.errors.cash_session">{{ paymentForm.errors.cash_session }}</FormError><FormError v-if="paymentForm.errors.invoice_uuid">{{ paymentForm.errors.invoice_uuid }}</FormError>
                    <div class="flex flex-col-reverse gap-3 border-t border-gray-200 pt-5 dark:border-gray-900 sm:flex-row sm:justify-end">
                        <Button size="rg" variant="white-outline" type="button" :disabled="paymentForm.processing" @click="closePaymentDialog">Annuler</Button>
                        <Button size="rg" variant="primary" type="submit" :disabled="paymentForm.processing"><Icon class="text-lg" name="check" /><span class="ms-2">{{ paymentForm.processing ? 'Encaissement…' : 'Confirmer et générer le reçu' }}</span></Button>
                    </div>
                </form>
            </section>
        </div>

        <div v-if="cancellationTarget" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="closeCancellationDialog">
            <section class="w-full max-w-lg rounded-lg border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="cancellation-dialog-title">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="cancellation-dialog-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">Annuler le paiement</h2>
                        <p class="mt-1 text-sm text-slate-400">{{ cancellationTarget.payment_number }} · {{ formatMoney(cancellationTarget.amount) }}</p>
                    </div>
                    <button type="button" class="text-slate-400 hover:text-slate-600" aria-label="Fermer" @click="closeCancellationDialog"><Icon class="text-xl" name="cross" /></button>
                </div>

                <form class="mt-6" @submit.prevent="cancelPayment">
                    <FormGroup class="!mb-0">
                        <FormLabel class="mb-1.5" for="payment_cancellation_reason">Motif <span class="text-red-500">*</span></FormLabel>
                        <textarea id="payment_cancellation_reason" v-model="cancellationForm.reason" rows="4" maxlength="1000" required class="block w-full rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none transition-all focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950" placeholder="Expliquez la correction à apporter…"></textarea>
                        <FormError v-if="cancellationForm.errors.reason">{{ cancellationForm.errors.reason }}</FormError>
                        <FormError v-if="cancellationForm.errors.payment">{{ cancellationForm.errors.payment }}</FormError>
                    </FormGroup>
                    <p class="mt-3 text-xs leading-5 text-slate-400">Le paiement, son reçu et la trace d’origine resteront visibles. Un mouvement inverse sera ajouté à la caisse ouverte.</p>
                    <div class="mt-5 flex flex-col-reverse gap-3 border-t border-gray-200 pt-5 dark:border-gray-900 sm:flex-row sm:justify-end">
                        <Button size="rg" variant="white-outline" type="button" :disabled="cancellationForm.processing" @click="closeCancellationDialog">Retour</Button>
                        <Button size="rg" variant="danger-outline" type="submit" :disabled="cancellationForm.processing">{{ cancellationForm.processing ? 'Annulation…' : 'Confirmer l’annulation' }}</Button>
                    </div>
                </form>
            </section>
        </div>
    </div>

    <Dialog :open="mutualAttachmentsOpen" as="div" class="relative z-[1200]" @close="mutualAttachmentsOpen = false">
        <div class="fixed inset-0 bg-slate-950/60" aria-hidden="true"></div>
        <div class="fixed inset-0 overflow-y-auto p-4">
            <div class="flex min-h-full items-center justify-center">
                <DialogPanel v-if="activeMutualCoverage?.attachments?.length" class="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-950">
                <header class="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-gray-200 bg-white px-5 py-4 dark:border-gray-800 dark:bg-gray-950">
                    <div class="min-w-0">
                        <DialogTitle class="text-sm font-bold text-slate-700 dark:text-white">Justificatifs de mutuelle</DialogTitle>
                        <p class="mt-0.5 text-xs text-slate-400">{{ activeMutualCoverage.organization?.name }} · {{ activeMutualCoverage.attachments.length }} fichier{{ activeMutualCoverage.attachments.length > 1 ? 's' : '' }}</p>
                    </div>
                    <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded text-slate-400 hover:bg-gray-100 hover:text-slate-700 dark:hover:bg-gray-900 dark:hover:text-white" aria-label="Fermer" @click="mutualAttachmentsOpen = false">
                        <Icon class="text-xl" name="cross" />
                    </button>
                </header>

                <div class="grid gap-3 p-5 sm:grid-cols-2">
                    <a
                        v-for="attachment in activeMutualCoverage.attachments"
                        :key="attachment.uuid"
                        :href="mutualAttachmentUrl(attachment)"
                        target="_blank"
                        rel="noopener"
                        class="overflow-hidden rounded-md border border-gray-200 transition-colors hover:border-primary-300 dark:border-gray-800 dark:hover:border-primary-800"
                    >
                        <span class="flex h-48 items-center justify-center bg-gray-50 dark:bg-gray-1000/40">
                            <img v-if="attachment.is_image" :src="mutualAttachmentUrl(attachment)" :alt="attachment.original_name" loading="lazy" class="h-full w-full object-contain" />
                            <span v-else class="flex flex-col items-center text-slate-500 dark:text-slate-300">
                                <Icon class="text-4xl" name="file-text" />
                                <span class="mt-2 text-xs font-bold">Document PDF</span>
                            </span>
                        </span>
                        <span class="flex items-center gap-3 border-t border-gray-100 p-3 dark:border-gray-900">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-xs font-bold text-slate-700 dark:text-white">{{ attachment.original_name }}</span>
                                <span class="mt-0.5 block text-[11px] text-slate-400">{{ formatFileSize(attachment.size) }} · Ouvrir</span>
                            </span>
                            <Icon class="shrink-0 text-base text-slate-400" name="external" />
                        </span>
                    </a>
                </div>
                </DialogPanel>
            </div>
        </div>
    </Dialog>
</template>
