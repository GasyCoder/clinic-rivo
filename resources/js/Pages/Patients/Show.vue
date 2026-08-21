<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormLabel from '@/Components/UI/FormLabel.vue';
import Icon from '@/Components/UI/Icon.vue';
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

const page = usePage();
const { can } = usePermissions();
const status = computed(() => page.props.flash?.status);
const showInvoiceForm = ref(false);
const paymentTarget = ref(null);
const cancellationTarget = ref(null);
const validatingInvoice = ref(null);

const activeEmergencyEpisode = computed(() => props.patient.episodes.find(
    (episode) => episode.status === 'OPEN' && episode.priority === 'EMERGENCY',
));

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
const administrativeStatusLabels = {
    PENDING_ORIENTATION: 'En attente d’orientation',
    ORIENTED: 'Orienté',
    IN_CARE: 'En cours de soins',
    PENDING_SETTLEMENT: 'En attente de règlement',
    DISCHARGED: 'Sorti',
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

const episodeStatusBadgeClass = (statusValue) => ({
    OPEN: 'border-gray-200 text-slate-600 dark:border-gray-800 dark:text-slate-300',
    CLOSED: 'border-gray-200 text-slate-500 dark:border-gray-800 dark:text-slate-400',
    CANCELLED: 'border-red-200 text-red-600 dark:border-red-900 dark:text-red-300',
}[statusValue] ?? 'border-gray-200 text-slate-600');

const invoiceStatusBadgeClass = (statusValue) => ({
    DRAFT: 'border-gray-200 text-slate-500 dark:border-gray-800 dark:text-slate-400',
    VALIDATED: 'border-gray-300 text-slate-700 dark:border-gray-700 dark:text-slate-200',
    PARTIALLY_PAID: 'border-gray-300 text-slate-700 dark:border-gray-700 dark:text-slate-200',
    PAID: 'border-gray-200 text-slate-600 dark:border-gray-800 dark:text-slate-300',
    CANCELLED: 'border-red-200 text-red-600 dark:border-red-900 dark:text-red-300',
}[statusValue] ?? 'border-gray-200 text-slate-600');
</script>

<template>
    <Head :title="formatPatientName(patient)" />

    <div class="w-full space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 items-center gap-4">
                <Avatar rounded size="lg" variant="slate-pale" :text="formatPatientInitials(patient)" />
                <div class="min-w-0">
                    <h1 class="truncate font-heading text-2xl font-bold text-slate-700 dark:text-white">
                        <span v-if="patient.civility">{{ civilityLabels[patient.civility] }}</span>
                        {{ formatPatientName(patient) }}
                    </h1>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ patient.patient_number }} · {{ sexLabel(patient.sex) }} · né(e) le {{ formatDate(patient.birth_date) }}
                        <span v-if="patient.birth_date_is_approximate" class="text-xs text-slate-400">(date estimée)</span>
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button :as="Link" href="/patients" size="rg" variant="white-outline"><Icon class="text-lg" name="arrow-left" /><span class="ms-2">Patients</span></Button>
                <Button v-if="can('patients.update')" :as="Link" :href="`/patients/${patient.uuid}/edit`" size="rg" variant="white-outline"><Icon class="text-lg" name="edit" /><span class="ms-2">Modifier</span></Button>
                <Button v-if="can('cash.view')" :as="Link" href="/cash" size="rg" variant="white-outline"><Icon class="text-lg" name="wallet" /><span class="ms-2">Caisse</span></Button>
            </div>
        </div>

        <div v-if="status" class="flex items-center gap-3 rounded border border-gray-200 bg-white px-4 py-3 text-sm text-slate-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300" role="status"><Icon class="text-lg text-green-600" name="check-circle" /><span>{{ status }}</span></div>

        <div v-if="activeEmergencyEpisode" class="flex items-start gap-3 rounded border border-gray-200 border-s-4 border-s-red-500 bg-white p-4 dark:border-gray-800 dark:border-s-red-500 dark:bg-gray-950" role="status">
            <Icon class="mt-0.5 shrink-0 text-xl text-red-600 dark:text-red-400" name="alert-circle" />
            <div><p class="text-sm font-bold uppercase tracking-wide text-red-700 dark:text-red-300">Patient en urgence</p><p class="mt-0.5 text-xs leading-5 text-slate-500 dark:text-slate-400">Passage {{ activeEmergencyEpisode.episode_number }} — les soins urgents restent prioritaires et ne sont jamais bloqués par le paiement.</p></div>
        </div>

        <section v-if="account" class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded border border-gray-200 text-slate-500 dark:border-gray-800 dark:text-slate-400"><Icon class="text-lg" name="wallet" /></span>
                    <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Compte patient</h2><p class="mt-0.5 text-xs text-slate-400">Factures, paiements successifs et reçus.</p></div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span v-if="openCashSession" class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-300"><span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> Caisse ouverte</span>
                    <span v-else-if="can('payments.create')" class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 dark:text-slate-400"><span class="h-1.5 w-1.5 rounded-full bg-slate-300 dark:bg-slate-600"></span> Caisse fermée</span>
                    <Button v-if="can('billing.create') && patient.episodes.length" size="sm" :variant="showInvoiceForm ? 'white-outline' : 'primary'" type="button" @click="showInvoiceForm = !showInvoiceForm"><Icon class="text-base" :name="showInvoiceForm ? 'cross' : 'plus'" /><span class="ms-1.5">{{ showInvoiceForm ? 'Fermer' : 'Nouvelle facture' }}</span></Button>
                </div>
            </div>

            <div class="grid grid-cols-2 divide-x divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-900 dark:border-gray-900 lg:grid-cols-4 lg:divide-y-0">
                <div class="px-5 py-4"><p class="text-xs font-medium uppercase tracking-wide text-slate-400">À facturer</p><p class="mt-1.5 text-xl font-bold text-slate-700 dark:text-white">{{ formatMoney(account.unbilled_amount) }}</p></div>
                <div class="px-5 py-4"><p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total facturé</p><p class="mt-1.5 text-xl font-bold text-slate-700 dark:text-white">{{ formatMoney(account.total_amount) }}</p></div>
                <div class="px-5 py-4"><p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total payé</p><p class="mt-1.5 text-xl font-bold text-slate-700 dark:text-white">{{ formatMoney(account.paid_amount) }}</p></div>
                <div class="px-5 py-4"><p class="text-xs font-medium uppercase tracking-wide text-slate-500">Reste à payer</p><p class="mt-1.5 text-xl font-bold text-slate-800 dark:text-white">{{ formatMoney(account.balance_amount) }}</p></div>
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
                                <span class="text-xs font-normal text-slate-400">{{ item.source_module }}</span>
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
                        <span><span class="line-through">{{ item.description }} · {{ formatMoney(item.total_amount) }}</span> · {{ item.source_module }} · passage {{ item.episode.episode_number }}</span>
                        <span :title="item.cancellation_reason">Annulée le {{ formatDateTime(item.cancelled_at) }}</span>
                    </li>
                </ul>
            </details>

            <div v-if="account.invoices.length === 0" class="px-5 py-10 text-center">
                <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-xl" name="file-text" /></span>
                <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-200">Aucune facture</p><p class="mt-1 text-xs text-slate-400">Les factures liées aux passages apparaîtront ici.</p>
            </div>

            <div v-else class="divide-y divide-gray-200 dark:divide-gray-900">
                <article v-for="invoice in account.invoices" :key="invoice.uuid" class="p-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-bold text-slate-700 dark:text-white">{{ invoice.invoice_number }}</h3>
                                <span :class="['rounded border px-2 py-0.5 text-xs font-medium', invoiceStatusBadgeClass(invoice.status)]">{{ invoiceStatusLabels[invoice.status] }}</span>
                                <span class="text-xs text-slate-400">Passage {{ invoice.episode.episode_number }} · {{ formatDateTime(invoice.created_at) }}</span>
                            </div>
                            <ul class="mt-2 space-y-1 text-xs text-slate-500">
                                <li v-for="line in invoice.lines" :key="line.id" class="flex flex-wrap gap-x-2"><span>{{ line.description }}</span><span class="text-slate-400">{{ line.source_module }} · {{ line.quantity }} × {{ formatMoney(line.unit_price) }} = {{ formatMoney(line.line_total) }}</span></li>
                            </ul>
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-4 lg:justify-end">
                            <dl class="grid grid-cols-3 gap-4 text-end text-xs">
                                <div><dt class="text-slate-400">Total</dt><dd class="mt-1 font-bold text-slate-700 dark:text-white">{{ formatMoney(invoice.total_amount) }}</dd></div>
                                <div><dt class="text-slate-400">Payé</dt><dd class="mt-1 font-bold text-slate-700 dark:text-white">{{ formatMoney(invoice.paid_amount) }}</dd></div>
                                <div><dt class="text-slate-400">Solde</dt><dd class="mt-1 font-bold text-slate-800 dark:text-white">{{ formatMoney(invoice.balance_amount) }}</dd></div>
                            </dl>
                            <Button v-if="invoice.status === 'DRAFT' && can('billing.validate')" size="sm" variant="white-outline" type="button" :disabled="validatingInvoice === invoice.uuid" @click="validateInvoice(invoice)"><Icon class="text-base" name="check" /><span class="ms-1.5">Valider</span></Button>
                            <Button v-if="can('billing.print')" :as="Link" :href="`/invoices/${invoice.uuid}`" size="sm" variant="white-outline"><Icon class="text-base" name="printer" /><span class="ms-1.5">Facture</span></Button>
                            <Button v-if="['VALIDATED', 'PARTIALLY_PAID'].includes(invoice.status) && can('payments.create') && openCashSession" size="sm" variant="primary" type="button" @click="openPaymentDialog(invoice)"><Icon class="text-base" name="money" /><span class="ms-1.5">Encaisser</span></Button>
                            <Button v-else-if="['VALIDATED', 'PARTIALLY_PAID'].includes(invoice.status) && can('payments.create') && !openCashSession" :as="Link" href="/cash" size="sm" variant="white-outline">Ouvrir la caisse</Button>
                        </div>
                    </div>

                    <div v-if="invoice.payments.length" class="mt-4 overflow-hidden rounded border border-gray-200 dark:border-gray-900">
                        <div v-for="payment in invoice.payments" :key="payment.uuid" class="flex flex-col gap-2 border-b border-gray-200 px-3 py-2.5 last:border-0 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-xs text-slate-500">
                                <span :class="['font-bold', payment.status === 'CANCELLED' ? 'text-slate-400 line-through' : 'text-slate-700 dark:text-slate-200']">{{ formatMoney(payment.amount) }}</span>
                                <span> · {{ payment.method }} · {{ formatDateTime(payment.paid_at) }}</span>
                                <span class="block text-slate-400 sm:ms-2 sm:inline">par {{ payment.cashier }}</span>
                                <span v-if="payment.status === 'CANCELLED'" class="ms-2 rounded border border-red-200 px-1.5 py-0.5 font-medium text-red-600 dark:border-red-900 dark:text-red-300" :title="payment.cancellation_reason">Annulé</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <button v-if="payment.status === 'COMPLETED' && can('payments.cancel') && openCashSession?.uuid === payment.cash_session_uuid" type="button" class="text-xs font-medium text-red-600 hover:underline" @click="openCancellationDialog(payment)">Annuler</button>
                                <Link v-if="payment.receipt" :href="`/receipts/${payment.receipt.uuid}`" class="inline-flex items-center gap-1 text-xs font-bold text-primary-600 hover:underline"><Icon name="file-text" /> {{ payment.receipt.receipt_number }}</Link>
                            </div>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <div class="space-y-5 xl:col-span-1">
                <section class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950">
                    <h2 class="mb-4 text-sm font-bold uppercase tracking-wide text-slate-500">Identité et contact</h2>
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-center gap-2 text-slate-600 dark:text-slate-300"><Icon class="text-slate-400" name="call" /><span>{{ patient.phone ?? 'Non renseigné' }}</span></div>
                        <div class="flex items-center gap-2 text-slate-600 dark:text-slate-300"><Icon class="text-slate-400" name="mail" /><span>{{ patient.email ?? 'Non renseigné' }}</span></div>
                        <div class="flex items-start gap-2 text-slate-600 dark:text-slate-300"><Icon class="mt-0.5 text-slate-400" name="map-pin" /><span>{{ patient.address ?? 'Non renseignée' }}</span></div>
                        <div v-if="patient.identity_document_type" class="flex items-center gap-2 text-slate-600 dark:text-slate-300"><Icon class="text-slate-400" name="cards" /><span>{{ patient.identity_document_type === 'CIN' ? 'CIN' : 'Passeport' }} {{ patient.identity_document_number }}</span></div>
                    </dl>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950">
                    <h2 class="mb-4 text-sm font-bold uppercase tracking-wide text-slate-500">Personne à contacter</h2>
                    <div v-if="patient.emergency_contact_name" class="space-y-1 text-sm text-slate-600 dark:text-slate-300"><p class="font-medium text-slate-700 dark:text-white">{{ patient.emergency_contact_name }}</p><p v-if="patient.emergency_contact_relationship">{{ patient.emergency_contact_relationship }}</p><p v-if="patient.emergency_contact_phone">{{ patient.emergency_contact_phone }}</p><p v-if="patient.emergency_contact_email">{{ patient.emergency_contact_email }}</p></div>
                    <p v-else class="text-sm text-slate-400">Non renseignée.</p>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500"><Icon class="text-slate-400" name="alert-circle" />Allergies</h2>
                    <ul v-if="patient.allergies.length" class="space-y-2 text-sm"><li v-for="allergy in patient.allergies" :key="allergy.id" class="text-slate-600 dark:text-slate-300"><span class="font-medium text-slate-700 dark:text-white">{{ allergy.substance }}</span><span v-if="allergy.severity" class="ms-1 text-xs text-red-500">({{ severityLabels[allergy.severity] }})</span><p v-if="allergy.reaction" class="text-xs text-slate-400">{{ allergy.reaction }}</p></li></ul>
                    <p v-else class="text-sm text-slate-400">Aucune allergie connue.</p>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950">
                    <h2 class="mb-4 text-sm font-bold uppercase tracking-wide text-slate-500">Antécédents médicaux</h2>
                    <ul v-if="patient.antecedents.length" class="space-y-2 text-sm text-slate-600 dark:text-slate-300"><li v-for="antecedent in patient.antecedents" :key="antecedent.id">{{ antecedent.description }}</li></ul>
                    <p v-else class="text-sm text-slate-400">Aucun antécédent connu.</p>
                </section>
            </div>

            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950 xl:col-span-2">
                <div class="border-b border-gray-200 p-5 dark:border-gray-900"><h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Historique des passages</h2><p class="mt-1 text-xs text-slate-400">Un nouveau passage se crée uniquement depuis la Réception.</p></div>
                <div v-if="patient.episodes.length === 0" class="p-10 text-center text-sm text-slate-400">Aucun passage enregistré.</div>
                <ul v-else class="divide-y divide-gray-200 dark:divide-gray-900">
                    <li v-for="episode in patient.episodes" :key="episode.uuid" class="flex flex-wrap items-center justify-between gap-3 p-5">
                        <div><p class="text-sm font-medium text-slate-700 dark:text-white">{{ episode.episode_number }}<span :class="['ms-2 rounded border px-2 py-0.5 text-xs font-medium', episodeStatusBadgeClass(episode.status)]">{{ statusLabels[episode.status] }}</span><span v-if="episode.priority === 'EMERGENCY'" class="ms-2 inline-flex items-center gap-1.5 rounded border border-red-200 px-2 py-0.5 text-xs font-bold uppercase text-red-600 dark:border-red-900 dark:text-red-300"><span class="h-1.5 w-1.5 rounded-full bg-red-500"></span> Urgence</span></p><p class="mt-1 text-xs text-slate-400">Démarré le {{ formatDateTime(episode.started_at) }} · {{ administrativeStatusLabels[episode.administrative_status] }}</p></div>
                        <Link v-if="episode.status === 'OPEN' && episode.administrative_status === 'PENDING_ORIENTATION' && can('episodes.update')" :href="`/episodes/${episode.uuid}/orient`" method="post" as="button"><Button size="sm" variant="white-outline">Orienter</Button></Link>
                    </li>
                </ul>
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
</template>
