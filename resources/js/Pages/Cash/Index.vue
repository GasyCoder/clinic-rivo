<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
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
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    cashSession: Object,
    summary: Object,
    outstandingInvoices: Array,
    outstandingSummary: Object,
    paymentMethods: Array,
    recentPayments: Array,
    recentSessions: Array,
});

const page = usePage();
const { can } = usePermissions();
const status = computed(() => page.props.flash?.status);
const activeLedgerTab = ref(can('billing.view') ? 'invoices' : 'payments');
const invoiceSearch = ref('');
const showCloseForm = ref(false);
const paymentTarget = ref(null);

const openForm = useForm({ opening_amount: 0, notes: '' });
const closeForm = useForm({ actual_closing_amount: '', notes: '' });
const paymentForm = useForm({
    invoice_uuid: '',
    payment_method_id: props.paymentMethods?.[0]?.id ?? '',
    amount: '',
    reference: '',
    notes: '',
});

const normalizedInvoiceSearch = computed(() => invoiceSearch.value.trim().toLocaleLowerCase('fr'));
const filteredOutstandingInvoices = computed(() => {
    if (!normalizedInvoiceSearch.value) return props.outstandingInvoices ?? [];

    return (props.outstandingInvoices ?? []).filter((invoice) => [
        invoice.invoice_number,
        invoice.episode?.episode_number,
        invoice.patient?.patient_number,
        formatPatientName(invoice.patient),
    ].some((value) => String(value ?? '').toLocaleLowerCase('fr').includes(normalizedInvoiceSearch.value)));
});

const invoiceStatusLabel = (invoice) => (
    invoice.status === 'PARTIALLY_PAID' ? 'Paiement partiel' : 'À payer'
);

const openCash = () => openForm.post('/cash/open', { preserveScroll: true });
const closeCash = () => closeForm.post('/cash/close', {
    preserveScroll: true,
    onError: () => { showCloseForm.value = true; },
});

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
    paymentForm.post(`/patients/${paymentTarget.value.patient.uuid}/payments`, {
        preserveScroll: true,
        onSuccess: () => {
            paymentTarget.value = null;
            activeLedgerTab.value = 'payments';
        },
    });
};
</script>

<template>
    <Head title="Caisse" />

    <div class="mx-auto w-full max-w-[1500px] space-y-4">
        <header class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 items-center gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-600 dark:bg-slate-900 dark:text-slate-300"><Icon class="text-2xl" name="wallet" /></span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h1 class="font-heading text-2xl font-bold text-slate-700 dark:text-white">Caisse</h1>
                        <span :class="['inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-bold', cashSession ? 'border-green-200 text-green-700 dark:border-green-900 dark:text-green-300' : 'border-gray-200 text-slate-500 dark:border-gray-800 dark:text-slate-400']">
                            <span :class="['h-1.5 w-1.5 rounded-full', cashSession ? 'bg-green-500' : 'bg-slate-300']"></span>{{ cashSession ? 'Ouverte' : 'Fermée' }}
                        </span>
                    </div>
                    <p class="mt-0.5 text-sm text-slate-400">Encaissez les factures validées et remettez les reçus de paiement.</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button :as="Link" href="/reception" size="rg" variant="white-outline"><Icon class="text-lg" name="arrow-left" /><span class="ms-2">Accueil réception</span></Button>
                <Button v-if="can('patients.view')" :as="Link" href="/patients" size="rg" variant="white-outline"><Icon class="text-lg" name="users" /><span class="ms-2">Patients</span></Button>
            </div>
        </header>

        <div v-if="status" class="flex items-start gap-3 rounded border border-green-200 bg-white px-4 py-2.5 text-sm text-slate-600 dark:border-green-900 dark:bg-gray-950 dark:text-slate-300" role="status">
            <Icon class="mt-0.5 shrink-0 text-lg text-green-600" name="check-circle" /><span>{{ status }}</span>
        </div>

        <section v-if="cashSession" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-900 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-500 dark:bg-slate-900 dark:text-slate-300"><Icon class="text-lg" name="unlock" /></span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Session {{ cashSession.session_number }}</h2><span class="text-xs text-slate-400">ouverte par {{ cashSession.opener.name }}</span></div>
                        <p class="mt-0.5 text-xs text-slate-400">{{ formatDateTime(cashSession.opened_at) }} · Une seule caisse active sur ce site</p>
                    </div>
                </div>
                <Button v-if="can('cash.close')" size="sm" variant="white-outline" type="button" @click="showCloseForm = !showCloseForm"><Icon class="text-base" :name="showCloseForm ? 'cross' : 'lock'" /><span class="ms-1.5">{{ showCloseForm ? 'Annuler' : 'Clôturer la caisse' }}</span></Button>
            </div>

            <dl class="grid grid-cols-2 divide-x divide-y divide-gray-200 dark:divide-gray-900 lg:grid-cols-4 lg:divide-y-0">
                <div class="px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Fond initial</dt><dd class="mt-1 text-base font-bold text-slate-700 dark:text-white">{{ formatMoney(cashSession.opening_amount) }}</dd></div>
                <div class="px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Total encaissé</dt><dd class="mt-1 text-base font-bold text-slate-700 dark:text-white">{{ formatMoney(summary.total_collected) }}</dd></div>
                <div class="px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Espèces encaissées</dt><dd class="mt-1 text-base font-bold text-slate-700 dark:text-white">{{ formatMoney(summary.cash_collected) }}</dd></div>
                <div class="px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Espèces attendues</dt><dd class="mt-1 text-base font-bold text-slate-800 dark:text-white">{{ formatMoney(summary.expected_cash) }}</dd></div>
            </dl>

            <form v-if="showCloseForm && can('cash.close')" class="border-t border-gray-200 bg-gray-50/60 px-4 py-4 dark:border-gray-900 dark:bg-gray-1000/30" @submit.prevent="closeCash">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-[220px_minmax(0,1fr)_auto] md:items-end">
                    <FormGroup class="!mb-0"><FormLabel class="mb-1.5" for="actual_closing_amount">Espèces comptées <span class="text-red-500">*</span></FormLabel><Input id="actual_closing_amount" v-model="closeForm.actual_closing_amount" type="number" min="0" step="0.01" required /><FormError v-if="closeForm.errors.actual_closing_amount">{{ closeForm.errors.actual_closing_amount }}</FormError></FormGroup>
                    <FormGroup class="!mb-0"><FormLabel class="mb-1.5" for="close_notes">Note de clôture</FormLabel><Input id="close_notes" v-model="closeForm.notes" placeholder="Observation facultative" /><FormError v-if="closeForm.errors.notes">{{ closeForm.errors.notes }}</FormError></FormGroup>
                    <Button size="rg" variant="secondary" type="submit" :disabled="closeForm.processing"><Icon class="text-lg" name="lock" /><span class="ms-2">{{ closeForm.processing ? 'Clôture…' : 'Confirmer la clôture' }}</span></Button>
                </div>
                <FormError v-if="closeForm.errors.cash_session" class="mt-2">{{ closeForm.errors.cash_session }}</FormError>
            </form>
        </section>

        <section v-else class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="flex items-start gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-900">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-500 dark:bg-slate-900 dark:text-slate-300"><Icon class="text-lg" name="lock" /></span>
                <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Ouvrir la caisse</h2><p class="mt-0.5 text-xs leading-5 text-slate-400">Une session ouverte est obligatoire avant tout encaissement et toute émission de reçu.</p></div>
            </div>
            <form v-if="can('cash.open')" class="grid grid-cols-1 gap-3 px-4 py-4 md:grid-cols-[220px_minmax(0,1fr)_auto] md:items-end" @submit.prevent="openCash">
                <FormGroup class="!mb-0"><FormLabel class="mb-1.5" for="opening_amount">Fond de caisse <span class="text-red-500">*</span></FormLabel><Input id="opening_amount" v-model="openForm.opening_amount" type="number" min="0" step="0.01" required /><FormError v-if="openForm.errors.opening_amount">{{ openForm.errors.opening_amount }}</FormError></FormGroup>
                <FormGroup class="!mb-0"><FormLabel class="mb-1.5" for="open_notes">Note d’ouverture</FormLabel><Input id="open_notes" v-model="openForm.notes" placeholder="Observation facultative" /><FormError v-if="openForm.errors.notes">{{ openForm.errors.notes }}</FormError></FormGroup>
                <Button size="rg" variant="primary" type="submit" :disabled="openForm.processing"><Icon class="text-lg" name="unlock" /><span class="ms-2">{{ openForm.processing ? 'Ouverture…' : 'Ouvrir la caisse' }}</span></Button>
                <FormError v-if="openForm.errors.cash_session" class="md:col-span-3">{{ openForm.errors.cash_session }}</FormError>
            </form>
        </section>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-900 xl:flex-row xl:items-center xl:justify-between">
                <div class="inline-flex w-full rounded-md bg-gray-100 p-1 dark:bg-gray-900 sm:w-auto" role="tablist" aria-label="Facturation de la caisse">
                    <button
                        v-if="can('billing.view')"
                        id="cash-invoices-tab"
                        type="button"
                        role="tab"
                        :aria-selected="activeLedgerTab === 'invoices'"
                        aria-controls="cash-invoices-panel"
                        :class="['flex h-9 min-w-0 flex-1 items-center justify-center gap-2 rounded px-3 text-xs font-bold transition-all sm:min-w-48 sm:flex-none', activeLedgerTab === 'invoices' ? 'border border-gray-200 bg-white text-slate-700 shadow-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white' : 'border border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-white']"
                        @click="activeLedgerTab = 'invoices'"
                    >
                        <Icon :class="['text-base', activeLedgerTab === 'invoices' ? 'text-primary-600' : 'text-slate-400']" name="file-text" />
                        <span class="truncate">Factures à encaisser</span>
                        <span :class="['inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[10px]', activeLedgerTab === 'invoices' ? 'bg-primary-50 text-primary-600 dark:bg-primary-950 dark:text-primary-300' : 'bg-gray-200 text-slate-500 dark:bg-gray-800 dark:text-slate-400']">{{ outstandingSummary?.count ?? 0 }}</span>
                    </button>
                    <button
                        v-if="can('payments.view')"
                        id="cash-payments-tab"
                        type="button"
                        role="tab"
                        :aria-selected="activeLedgerTab === 'payments'"
                        aria-controls="cash-payments-panel"
                        :class="['flex h-9 min-w-0 flex-1 items-center justify-center gap-2 rounded px-3 text-xs font-bold transition-all sm:min-w-48 sm:flex-none', activeLedgerTab === 'payments' ? 'border border-gray-200 bg-white text-slate-700 shadow-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white' : 'border border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-white']"
                        @click="activeLedgerTab = 'payments'"
                    >
                        <Icon :class="['text-base', activeLedgerTab === 'payments' ? 'text-primary-600' : 'text-slate-400']" name="money" />
                        <span class="truncate">Paiements récents</span>
                        <span :class="['inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[10px]', activeLedgerTab === 'payments' ? 'bg-primary-50 text-primary-600 dark:bg-primary-950 dark:text-primary-300' : 'bg-gray-200 text-slate-500 dark:bg-gray-800 dark:text-slate-400']">{{ recentPayments.length }}</span>
                    </button>
                </div>

                <div v-if="activeLedgerTab === 'invoices' && can('billing.view')" class="flex w-full flex-col gap-3 sm:flex-row sm:items-center xl:w-auto">
                    <div class="flex shrink-0 items-center justify-between gap-4 sm:block sm:border-e sm:border-gray-200 sm:pe-4 sm:text-end dark:sm:border-gray-800">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Solde à encaisser</p>
                        <p class="text-sm font-bold text-slate-700 dark:text-white">{{ formatMoney(outstandingSummary?.balance_amount ?? 0) }}</p>
                    </div>
                    <div class="relative w-full sm:w-80">
                        <IconInput id="cash_invoice_search" v-model="invoiceSearch" icon="search" type="text" inputmode="search" class="!pe-10" placeholder="Patient, n° facture ou passage" aria-label="Rechercher une facture" />
                        <button v-if="invoiceSearch" type="button" class="absolute inset-y-0 end-0 flex w-9 items-center justify-center text-slate-400 transition-colors hover:text-slate-600 dark:hover:text-slate-200" aria-label="Effacer la recherche" title="Effacer la recherche" @click="invoiceSearch = ''"><Icon class="text-sm" name="cross" /></button>
                    </div>
                </div>
            </div>

            <div v-if="activeLedgerTab === 'invoices' && can('billing.view')" id="cash-invoices-panel" class="overflow-x-auto" role="tabpanel" aria-labelledby="cash-invoices-tab">
                <table class="w-full min-w-[1050px] border-collapse">
                    <thead class="bg-gray-50/70 dark:bg-gray-1000/40"><tr><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Patient</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Facture / passage</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Validation</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Total</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Déjà payé</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Reste à payer</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Statut</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Actions</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                        <tr v-for="invoice in filteredOutstandingInvoices" :key="invoice.uuid" class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-1000/30">
                            <td class="px-4 py-3"><div class="flex items-center gap-2.5"><Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(invoice.patient)" /><div class="min-w-0"><Link v-if="can('patients.view')" :href="`/patients/${invoice.patient.uuid}`" class="block max-w-56 truncate text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white">{{ formatPatientName(invoice.patient) }}</Link><span v-else class="block max-w-56 truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(invoice.patient) }}</span><span class="text-xs text-slate-400">{{ invoice.patient.patient_number }}</span></div></div></td>
                            <td class="px-4 py-3"><Link v-if="can('billing.print')" :href="`/invoices/${invoice.uuid}?from=cash`" class="text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white">{{ invoice.invoice_number }}</Link><span v-else class="text-sm font-bold text-slate-700 dark:text-white">{{ invoice.invoice_number }}</span><p class="mt-0.5 text-xs text-slate-400">{{ invoice.episode.episode_number }} · {{ invoice.lines_count }} ligne{{ invoice.lines_count > 1 ? 's' : '' }}</p></td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ formatDateTime(invoice.validated_at ?? invoice.created_at) }}</td><td class="px-4 py-3 text-end text-sm text-slate-500">{{ formatMoney(invoice.total_amount) }}</td><td class="px-4 py-3 text-end text-sm text-slate-500">{{ formatMoney(invoice.paid_amount) }}</td><td class="px-4 py-3 text-end text-sm font-bold text-slate-800 dark:text-white">{{ formatMoney(invoice.balance_amount) }}</td>
                            <td class="px-4 py-3"><span class="inline-flex rounded border border-gray-200 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:border-gray-800 dark:text-slate-300">{{ invoiceStatusLabel(invoice) }}</span></td>
                            <td class="px-4 py-3"><div class="flex items-center justify-end gap-2"><Button v-if="can('billing.print')" :as="Link" :href="`/invoices/${invoice.uuid}?from=cash`" icon size="rg" title="Voir et imprimer la facture" variant="white-outline" aria-label="Voir et imprimer la facture"><Icon class="text-base" name="file-text" /></Button><Button v-if="can('payments.create')" size="sm" variant="primary" type="button" :disabled="!cashSession || paymentMethods.length === 0" :title="!cashSession ? 'Ouvrez la caisse avant l’encaissement' : paymentMethods.length === 0 ? 'Aucun mode de paiement actif' : 'Encaisser cette facture'" @click="openPaymentDialog(invoice)"><Icon class="text-base" name="money" /><span class="ms-1.5">Encaisser</span></Button></div></td>
                        </tr>
                        <tr v-if="filteredOutstandingInvoices.length === 0"><td colspan="8" class="px-5 py-10 text-center"><Icon class="text-2xl text-slate-300" name="file-text" /><p class="mt-2 text-sm font-medium text-slate-500">{{ invoiceSearch ? 'Aucune facture ne correspond à la recherche.' : 'Aucune facture en attente de paiement.' }}</p></td></tr>
                    </tbody>
                </table>
            </div>

            <div v-if="activeLedgerTab === 'payments' && can('payments.view')" id="cash-payments-panel" class="overflow-x-auto" role="tabpanel" aria-labelledby="cash-payments-tab">
                <table class="w-full min-w-[980px] border-collapse">
                    <thead class="bg-gray-50/70 dark:bg-gray-1000/40"><tr><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Patient</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Paiement</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Date / heure</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Mode</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Montant</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Documents</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                        <tr v-for="payment in recentPayments" :key="payment.uuid" class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-1000/30">
                            <td class="px-4 py-3"><div class="flex items-center gap-2.5"><Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(payment.invoice.patient)" /><div><Link v-if="can('patients.view')" :href="`/patients/${payment.invoice.patient.uuid}`" class="text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white">{{ formatPatientName(payment.invoice.patient) }}</Link><span v-else class="text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(payment.invoice.patient) }}</span><span class="block text-xs text-slate-400">{{ payment.invoice.patient.patient_number }}</span></div></div></td>
                            <td class="px-4 py-3"><p class="text-sm font-bold text-slate-700 dark:text-white">{{ payment.payment_number }}</p><p class="mt-0.5 text-xs text-slate-400">Facture {{ payment.invoice.invoice_number }}</p></td><td class="px-4 py-3 text-sm text-slate-500">{{ formatDateTime(payment.paid_at) }}</td><td class="px-4 py-3"><p class="text-sm text-slate-600 dark:text-slate-300">{{ payment.method.name }}</p><p class="mt-0.5 text-xs text-slate-400">Par {{ payment.cashier.name }}</p></td>
                            <td class="px-4 py-3 text-end text-sm"><span :class="['font-bold', payment.status === 'CANCELLED' ? 'text-slate-400 line-through' : 'text-slate-800 dark:text-white']">{{ formatMoney(payment.amount) }}</span><span v-if="payment.status === 'CANCELLED'" class="ms-2 rounded border border-red-200 px-1.5 py-0.5 text-[10px] font-medium text-red-600 dark:border-red-900 dark:text-red-300">Annulé</span></td>
                            <td class="px-4 py-3"><div class="flex items-center justify-end gap-2"><Button v-if="can('billing.print')" :as="Link" :href="`/invoices/${payment.invoice.uuid}?from=cash`" icon size="rg" title="Imprimer la facture" variant="white-outline" aria-label="Imprimer la facture"><Icon class="text-base" name="file-text" /></Button><Button v-if="payment.receipt && can('receipts.view')" :as="Link" :href="`/receipts/${payment.receipt.uuid}?from=cash`" size="sm" variant="white-outline"><Icon class="text-base" name="printer" /><span class="ms-1.5">{{ payment.receipt.receipt_number }}</span></Button></div></td>
                        </tr>
                        <tr v-if="recentPayments.length === 0"><td colspan="6" class="px-5 py-10 text-center text-sm text-slate-400">Aucun paiement enregistré.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <details class="group overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 marker:hidden">
                <div class="flex items-center gap-3"><span class="flex h-8 w-8 items-center justify-center rounded bg-slate-100 text-slate-500 dark:bg-slate-900 dark:text-slate-300"><Icon name="history" /></span><div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Historique des sessions</h2><p class="mt-0.5 text-xs text-slate-400">{{ recentSessions.length }} dernières ouvertures et clôtures</p></div></div>
                <Icon class="text-lg text-slate-400 transition-transform group-open:rotate-180" name="chevron-down" />
            </summary>
            <div class="overflow-x-auto border-t border-gray-200 dark:border-gray-900">
                <table class="w-full min-w-[760px] border-collapse">
                    <thead class="bg-gray-50/70 dark:bg-gray-1000/40"><tr><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Session</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Ouverture</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Clôture</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Attendu</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Écart</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900"><tr v-for="session in recentSessions" :key="session.uuid"><td class="px-4 py-3 text-sm font-bold text-slate-700 dark:text-white">{{ session.session_number }}</td><td class="px-4 py-3 text-sm text-slate-500">{{ formatDateTime(session.opened_at) }} · {{ session.opener.name }}</td><td class="px-4 py-3 text-sm text-slate-500">{{ session.closed_at ? `${formatDateTime(session.closed_at)} · ${session.closer?.name}` : 'En cours' }}</td><td class="px-4 py-3 text-end text-sm text-slate-500">{{ session.expected_closing_amount == null ? '—' : formatMoney(session.expected_closing_amount) }}</td><td :class="['px-4 py-3 text-end text-sm font-bold', session.variance_amount == null || Number(session.variance_amount) === 0 ? 'text-slate-600' : 'text-red-600']">{{ session.variance_amount == null ? '—' : formatMoney(session.variance_amount) }}</td></tr></tbody>
                </table>
            </div>
        </details>

        <div v-if="paymentTarget" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/55 p-4" role="presentation" @click.self="closePaymentDialog">
            <section class="w-full max-w-xl overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="cash-payment-dialog-title">
                <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-900"><div class="flex min-w-0 items-start gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-600 dark:bg-slate-900 dark:text-slate-300"><Icon class="text-xl" name="money" /></span><div class="min-w-0"><h2 id="cash-payment-dialog-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">Encaisser la facture</h2><p class="mt-0.5 truncate text-sm text-slate-400">{{ paymentTarget.invoice_number }} · {{ formatPatientName(paymentTarget.patient) }}</p></div></div><button type="button" class="text-slate-400 hover:text-slate-600" aria-label="Fermer" @click="closePaymentDialog"><Icon class="text-xl" name="cross" /></button></header>
                <dl class="grid grid-cols-3 divide-x divide-gray-200 border-b border-gray-200 bg-gray-50/60 dark:divide-gray-900 dark:border-gray-900 dark:bg-gray-1000/30"><div class="px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Total</dt><dd class="mt-1 text-sm font-bold text-slate-700 dark:text-white">{{ formatMoney(paymentTarget.total_amount) }}</dd></div><div class="px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Déjà payé</dt><dd class="mt-1 text-sm font-bold text-slate-700 dark:text-white">{{ formatMoney(paymentTarget.paid_amount) }}</dd></div><div class="px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Reste à payer</dt><dd class="mt-1 text-sm font-black text-slate-800 dark:text-white">{{ formatMoney(paymentTarget.balance_amount) }}</dd></div></dl>

                <form class="space-y-4 p-5" @submit.prevent="recordPayment">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormGroup class="!mb-0"><FormLabel class="mb-1.5" for="cash_payment_amount">Montant <span class="text-red-500">*</span></FormLabel><Input id="cash_payment_amount" v-model="paymentForm.amount" type="number" min="0.01" :max="paymentTarget.balance_amount" step="0.01" required autofocus /><FormError v-if="paymentForm.errors.amount">{{ paymentForm.errors.amount }}</FormError></FormGroup>
                        <FormGroup class="!mb-0"><FormLabel class="mb-1.5" for="cash_payment_method">Mode de paiement <span class="text-red-500">*</span></FormLabel><select id="cash_payment_method" v-model="paymentForm.payment_method_id" class="block h-9 w-full rounded border border-gray-200 bg-white px-4 py-1.5 text-sm text-slate-700 outline-none transition-all focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950" required><option value="" disabled>Choisir</option><option v-for="method in paymentMethods" :key="method.id" :value="method.id">{{ method.name }}</option></select><FormError v-if="paymentForm.errors.payment_method_id">{{ paymentForm.errors.payment_method_id }}</FormError></FormGroup>
                    </div>
                    <FormGroup class="!mb-0"><FormLabel class="mb-1.5" for="cash_payment_reference">Référence</FormLabel><Input id="cash_payment_reference" v-model="paymentForm.reference" placeholder="Mobile money, virement…" /><FormError v-if="paymentForm.errors.reference">{{ paymentForm.errors.reference }}</FormError></FormGroup>
                    <FormGroup class="!mb-0"><FormLabel class="mb-1.5" for="cash_payment_notes">Note</FormLabel><Input id="cash_payment_notes" v-model="paymentForm.notes" placeholder="Observation facultative" /><FormError v-if="paymentForm.errors.notes">{{ paymentForm.errors.notes }}</FormError></FormGroup>
                    <FormError v-if="paymentForm.errors.cash_session">{{ paymentForm.errors.cash_session }}</FormError><FormError v-if="paymentForm.errors.invoice_uuid">{{ paymentForm.errors.invoice_uuid }}</FormError>
                    <div class="flex items-start gap-2.5 rounded border border-gray-200 bg-gray-50/60 px-3 py-2.5 text-xs leading-5 text-slate-500 dark:border-gray-800 dark:bg-gray-1000/30"><Icon class="mt-0.5 shrink-0 text-base text-slate-400" name="info" /><p>La confirmation enregistre le paiement dans la session ouverte et génère son reçu. La facture restera disponible en formats B5 et ticket thermique.</p></div>
                    <div class="flex flex-col-reverse gap-2 border-t border-gray-200 pt-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between"><Button v-if="can('billing.print')" :as="Link" :href="`/invoices/${paymentTarget.uuid}?from=cash`" size="rg" variant="white-outline"><Icon class="text-lg" name="file-text" /><span class="ms-2">Voir la facture</span></Button><div class="flex justify-end gap-2"><Button size="rg" variant="white-outline" type="button" :disabled="paymentForm.processing" @click="closePaymentDialog">Annuler</Button><Button size="rg" variant="primary" type="submit" :disabled="paymentForm.processing"><Icon class="text-lg" name="check" /><span class="ms-2">{{ paymentForm.processing ? 'Encaissement…' : 'Encaisser et générer le reçu' }}</span></Button></div></div>
                </form>
            </section>
        </div>
    </div>
</template>
