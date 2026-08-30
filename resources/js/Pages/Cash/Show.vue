<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
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
import CashOriginFilter from '@/Pages/Cash/Partials/CashOriginFilter.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    cashRegister: Object,
    cashSession: Object,
    blockingSession: Object,
    summary: Object,
    outstandingInvoices: Array,
    outstandingSummary: Object,
    paymentMethods: Array,
    recentPayments: Array,
    recentSessions: Array,
    pharmacyLookup: Object,
});

const { can } = usePermissions();
const activeLedgerTab = ref(props.pharmacyLookup?.reference
    ? 'pharmacy'
    : (can('billing.view') ? 'invoices' : 'payments'));
const invoiceSearch = ref('');
const showCloseForm = ref(false);
const sessionLocked = computed(() => props.cashSession?.status === 'LOCKED');
const cashOperational = computed(() => props.cashSession?.status === 'OPEN');

/** Referral-only destinations (hospitalisation, pédiatrie) have no billing workspace yet, but the
 * caisse filter groups them with Médecine now so it doesn't need to change once they get one. */
const moduleGroups = {
    MEDICINE: ['MEDICINE', 'CARE', 'HOSPITALIZATION', 'PEDIATRICS'],
    SURGERY: ['SURGERY'],
    PHARMACY: ['PHARMACY'],
    LABORATORY: ['LABORATORY'],
};
const moduleFilterOptions = [
    { value: 'all', label: 'Tous' },
    { value: 'MEDICINE', label: 'Médecine' },
    { value: 'SURGERY', label: 'Chirurgie' },
    { value: 'PHARMACY', label: 'Pharmacie' },
    { value: 'LABORATORY', label: 'Laboratoire' },
];
const moduleFilter = ref('all');
const paymentSearch = ref('');
const visibleModuleFilterOptions = computed(() => activeLedgerTab.value === 'payments'
    ? moduleFilterOptions
    : moduleFilterOptions.filter((option) => option.value !== 'PHARMACY'));
const matchesModuleFilter = (sourceModule) => moduleFilter.value === 'all'
    || (moduleGroups[moduleFilter.value] ?? []).includes(sourceModule);
const moduleFilterCounts = computed(() => {
    const sourceModules = activeLedgerTab.value === 'payments'
        ? (props.recentPayments ?? []).map((payment) => payment.invoice?.source_module)
        : (props.outstandingInvoices ?? []).map((invoice) => invoice.source_module);

    return moduleFilterOptions.reduce((counts, option) => {
        counts[option.value] = option.value === 'all'
            ? sourceModules.length
            : sourceModules.filter((sourceModule) => (moduleGroups[option.value] ?? []).includes(sourceModule)).length;

        return counts;
    }, {});
});
const paymentTarget = ref(null);
const pharmacyReference = ref(props.pharmacyLookup?.reference ?? '');
const pharmacyLookupMode = ref('manual');
const scannerActive = ref(false);
const scannerError = ref('');
const scannerVideo = ref(null);
let qrScanner = null;
let pharmacyLookupTimer = null;

const openForm = useForm({ opening_amount: 0, notes: '', cash_register_uuid: props.cashRegister?.uuid ?? '' });
const closeForm = useForm({ actual_closing_amount: '', notes: '', cash_register_uuid: props.cashRegister?.uuid ?? '' });
const paymentForm = useForm({
    invoice_uuid: '',
    payment_method_id: props.paymentMethods?.[0]?.id ?? '',
    amount: '',
    reference: '',
    notes: '',
    cash_register_uuid: props.cashRegister?.uuid ?? '',
});

const normalizedInvoiceSearch = computed(() => invoiceSearch.value.trim().toLocaleLowerCase('fr'));
const filteredOutstandingInvoices = computed(() => (props.outstandingInvoices ?? [])
    .filter((invoice) => matchesModuleFilter(invoice.source_module))
    .filter((invoice) => !normalizedInvoiceSearch.value || [
        invoice.invoice_number,
        invoice.episode?.episode_number,
        invoice.patient?.patient_number,
        invoice.customer_name,
        invoice.source_module,
        formatPatientName(invoice.patient),
    ].some((value) => String(value ?? '').toLocaleLowerCase('fr').includes(normalizedInvoiceSearch.value))));
const filteredRecentPayments = computed(() => (props.recentPayments ?? [])
    .filter((payment) => matchesModuleFilter(payment.invoice?.source_module))
    .filter((payment) => {
        const search = paymentSearch.value.trim().toLocaleLowerCase('fr');
        if (!search) return true;

        return [
            payment.payment_number,
            payment.invoice?.invoice_number,
            payment.invoice?.episode?.episode_number,
            payment.invoice?.patient?.patient_number,
            payment.invoice?.customer_name,
            payment.method?.name,
            formatPatientName(payment.invoice?.patient),
        ].some((value) => String(value ?? '').toLocaleLowerCase('fr').includes(search));
    }));

const selectedInvoiceUuids = ref(new Set());
const selectedPaymentUuids = ref(new Set());

const toggleInvoiceSelection = (uuid) => {
    const next = new Set(selectedInvoiceUuids.value);
    if (next.has(uuid)) next.delete(uuid); else next.add(uuid);
    selectedInvoiceUuids.value = next;
};
const togglePaymentSelection = (uuid) => {
    const next = new Set(selectedPaymentUuids.value);
    if (next.has(uuid)) next.delete(uuid); else next.add(uuid);
    selectedPaymentUuids.value = next;
};

const allVisibleInvoicesSelected = computed(() => filteredOutstandingInvoices.value.length > 0
    && filteredOutstandingInvoices.value.every((invoice) => selectedInvoiceUuids.value.has(invoice.uuid)));
const toggleAllVisibleInvoices = () => {
    selectedInvoiceUuids.value = allVisibleInvoicesSelected.value
        ? new Set()
        : new Set(filteredOutstandingInvoices.value.map((invoice) => invoice.uuid));
};

const allVisiblePaymentsSelected = computed(() => filteredRecentPayments.value.length > 0
    && filteredRecentPayments.value.every((payment) => selectedPaymentUuids.value.has(payment.uuid)));
const toggleAllVisiblePayments = () => {
    selectedPaymentUuids.value = allVisiblePaymentsSelected.value
        ? new Set()
        : new Set(filteredRecentPayments.value.map((payment) => payment.uuid));
};

const selectedInvoices = computed(() => filteredOutstandingInvoices.value
    .filter((invoice) => selectedInvoiceUuids.value.has(invoice.uuid)));
const selectedPayments = computed(() => filteredRecentPayments.value
    .filter((payment) => selectedPaymentUuids.value.has(payment.uuid)));

watch([activeLedgerTab, moduleFilter], () => {
    selectedInvoiceUuids.value = new Set();
    selectedPaymentUuids.value = new Set();
});

watch(activeLedgerTab, (tab) => {
    if (tab === 'invoices' && moduleFilter.value === 'PHARMACY') moduleFilter.value = 'all';
});

const csvCell = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`;

const downloadCsv = (filename, rows) => {
    const csvContent = rows.map((row) => row.map(csvCell).join(';')).join('\r\n');
    const blob = new Blob(['﻿' + csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
};

/** Opens a standalone printable window rather than fighting the dashboard's own
 * layout/print rules — the same "separate document" approach as PrescriptionPrint.vue. */
const printRows = (title, headers, rows) => {
    const printWindow = window.open('', '_blank', 'width=900,height=700');
    if (!printWindow) return;

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const tableHead = `<tr>${headers.map((header) => `<th>${escapeHtml(header)}</th>`).join('')}</tr>`;
    const tableRows = rows.map((row) => `<tr>${row.map((cell) => `<td>${escapeHtml(cell)}</td>`).join('')}</tr>`).join('');

    printWindow.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${escapeHtml(title)}</title>
        <style>
            body { font-family: Arial, Helvetica, sans-serif; padding: 24px; color: #1e293b; }
            h1 { font-size: 16px; margin: 0 0 4px; }
            p { margin: 0 0 16px; font-size: 11px; color: #64748b; }
            table { width: 100%; border-collapse: collapse; font-size: 11px; }
            th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
            th { background: #f1f5f9; text-transform: uppercase; font-size: 9px; letter-spacing: .04em; }
        </style>
    </head><body>
        <h1>${escapeHtml(title)}</h1>
        <p>${rows.length} ligne(s) — imprimé le ${escapeHtml(formatDateTime(new Date()))}</p>
        <table><thead>${tableHead}</thead><tbody>${tableRows}</tbody></table>
    </body></html>`);
    printWindow.document.close();
    printWindow.focus();
    printWindow.onload = () => printWindow.print();
};

const exportSelectedInvoices = () => downloadCsv(`factures-caisse-${Date.now()}.csv`, [
    ['N° facture', 'Client', 'N° patient', 'Passage', 'Origine', 'Statut', 'Total', 'Déjà payé', 'Reste à payer'],
    ...selectedInvoices.value.map((invoice) => [
        invoice.invoice_number, invoiceCustomerName(invoice), invoice.patient?.patient_number ?? '',
        invoice.episode?.episode_number ?? '', invoice.source_module ?? '', invoiceStatusLabel(invoice),
        invoice.total_amount, invoice.paid_amount, invoice.balance_amount,
    ]),
]);

const printSelectedInvoices = () => printRows(
    'Factures sélectionnées — Caisse',
    ['N° facture', 'Client', 'N° patient', 'Passage', 'Origine', 'Statut', 'Total', 'Déjà payé', 'Reste à payer'],
    selectedInvoices.value.map((invoice) => [
        invoice.invoice_number, invoiceCustomerName(invoice), invoice.patient?.patient_number ?? '',
        invoice.episode?.episode_number ?? '', invoice.source_module ?? '', invoiceStatusLabel(invoice),
        formatMoney(invoice.total_amount), formatMoney(invoice.paid_amount), formatMoney(invoice.balance_amount),
    ]),
);

const exportSelectedPayments = () => downloadCsv(`paiements-caisse-${Date.now()}.csv`, [
    ['N° paiement', 'Client', 'N° patient', 'Facture', 'Date', 'Mode', 'Montant', 'Statut'],
    ...selectedPayments.value.map((payment) => [
        payment.payment_number, invoiceCustomerName(payment.invoice), payment.invoice.patient?.patient_number ?? '',
        payment.invoice.invoice_number, formatDateTime(payment.paid_at), payment.method.name,
        payment.amount, payment.status,
    ]),
]);

const printSelectedPayments = () => printRows(
    'Paiements sélectionnés — Caisse',
    ['N° paiement', 'Client', 'N° patient', 'Facture', 'Date', 'Mode', 'Montant', 'Statut'],
    selectedPayments.value.map((payment) => [
        payment.payment_number, invoiceCustomerName(payment.invoice), payment.invoice.patient?.patient_number ?? '',
        payment.invoice.invoice_number, formatDateTime(payment.paid_at), payment.method.name,
        formatMoney(payment.amount), payment.status,
    ]),
);

const invoiceStatusLabel = (invoice) => ({
    DRAFT: 'Brouillon',
    VALIDATED: 'À payer',
    PARTIALLY_PAID: 'Paiement partiel',
    PAID: 'Payée',
    COVERED: 'Prise en charge',
    CANCELLED: 'Annulée',
}[invoice.status] ?? invoice.status);
const invoiceCanBePaid = (invoice) => ['VALIDATED', 'PARTIALLY_PAID'].includes(invoice.status)
    && Number(invoice.balance_amount) > 0;
const invoiceCustomerName = (invoice) => invoice.patient
    ? formatPatientName(invoice.patient)
    : (invoice.customer_name || 'Client comptoir');
const invoiceCustomerInitials = (invoice) => invoice.patient
    ? formatPatientInitials(invoice.patient)
    : invoiceCustomerName(invoice).split(/\s+/).slice(0, 2).map((word) => word[0]).join('').toUpperCase();

const openCash = () => openForm.post('/cash/open', { preserveScroll: true });
const closeCash = () => closeForm.post('/cash/close', {
    preserveScroll: true,
    onError: () => { showCloseForm.value = true; },
});

const toggleCloseForm = () => {
    if (!showCloseForm.value) {
        closeForm.clearErrors();
        closeForm.actual_closing_amount = props.summary?.expected_cash ?? '';
        closeForm.notes = '';
    }
    showCloseForm.value = !showCloseForm.value;
};

/** UI-only heads-up so the cashier sees the notes field is about to become mandatory;
 * the server re-derives the écart itself and is the actual source of truth. */
const closeFormHasVariance = computed(() => {
    const actual = closeForm.actual_closing_amount;
    if (!props.summary || actual === '' || actual === null || Number.isNaN(Number(actual))) return false;

    return Math.round(Number(actual) * 100) !== Math.round(Number(props.summary.expected_cash) * 100);
});

const openPaymentDialog = (invoice) => {
    if (!cashOperational.value) return;
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

const stopQrScanner = () => {
    scannerActive.value = false;
    qrScanner?.destroy();
    qrScanner = null;
    if (scannerVideo.value) scannerVideo.value.srcObject = null;
};

const submitPharmacyLookup = () => {
    if (pharmacyLookupTimer !== null) clearTimeout(pharmacyLookupTimer);
    pharmacyLookupTimer = null;
    const reference = pharmacyReference.value.trim();
    if (reference === (props.pharmacyLookup?.reference ?? '')) return;

    stopQrScanner();
    router.get(window.location.pathname, reference ? { pharmacy_reference: reference } : {}, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        only: ['pharmacyLookup'],
    });
};

const startQrScanner = async () => {
    scannerError.value = '';

    if (!window.isSecureContext) {
        scannerError.value = 'L’accès à la caméra exige une connexion sécurisée HTTPS.';
        return;
    }

    if (!navigator.mediaDevices?.getUserMedia) {
        scannerError.value = 'Ce navigateur ne permet pas l’accès à la caméra. Vous pouvez toujours saisir la référence du ticket.';
        return;
    }

    try {
        scannerActive.value = true;
        await nextTick();
        const { default: QrScanner } = await import('qr-scanner');

        if (!scannerActive.value || !scannerVideo.value) return;

        qrScanner = new QrScanner(scannerVideo.value, (result) => {
            const value = result?.data?.trim();
            if (!value) return;

            pharmacyReference.value = value;
            stopQrScanner();
            submitPharmacyLookup();
        }, {
            preferredCamera: 'environment',
            maxScansPerSecond: 10,
            returnDetailedScanResult: true,
            onDecodeError: () => {},
        });

        await qrScanner.start();
    } catch (error) {
        stopQrScanner();
        if (['NotAllowedError', 'SecurityError'].includes(error?.name)) {
            scannerError.value = 'Accès à la caméra refusé. Autorisez la caméra pour ce site, puis réessayez.';
        } else if (error?.name === 'NotFoundError') {
            scannerError.value = 'Aucune caméra disponible sur cet appareil. Vous pouvez saisir la référence du ticket.';
        } else if (['NotReadableError', 'TrackStartError'].includes(error?.name)) {
            scannerError.value = 'La caméra est déjà utilisée par une autre application. Fermez-la, puis réessayez.';
        } else {
            scannerError.value = 'Impossible de démarrer la caméra. Vérifiez son autorisation, puis réessayez ou saisissez la référence.';
        }
    }
};

const selectPharmacyLookupMode = (mode) => {
    if (mode !== 'scan') stopQrScanner();
    scannerError.value = '';
    pharmacyLookupMode.value = mode;
};

watch(activeLedgerTab, (tab) => {
    if (tab !== 'pharmacy') {
        if (pharmacyLookupTimer !== null) clearTimeout(pharmacyLookupTimer);
        pharmacyLookupTimer = null;
        stopQrScanner();
    }
});

watch(pharmacyReference, () => {
    if (pharmacyLookupTimer !== null) clearTimeout(pharmacyLookupTimer);
    pharmacyLookupTimer = null;

    if (activeLedgerTab.value !== 'pharmacy' || pharmacyLookupMode.value !== 'manual') return;

    pharmacyLookupTimer = setTimeout(submitPharmacyLookup, 350);
});

const recordPayment = () => {
    paymentForm.post(`/invoices/${paymentTarget.value.uuid}/payments`, {
        preserveScroll: true,
        onSuccess: () => {
            paymentTarget.value = null;
            activeLedgerTab.value = 'payments';
        },
    });
};

onBeforeUnmount(() => {
    if (pharmacyLookupTimer !== null) clearTimeout(pharmacyLookupTimer);
    stopQrScanner();
});
</script>

<template>
    <Head :title="cashRegister ? `Caisse — ${cashRegister.name}` : 'Caisse'" />

    <div class="mx-auto w-full max-w-[1500px] space-y-4">
        <header class="border-b border-gray-200 pb-4 dark:border-gray-900">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="min-w-0">
                    <p class="mb-1 text-[10px] font-bold uppercase tracking-[0.18em] text-primary-600 dark:text-primary-300">Réception · Encaissement</p>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                        <h1 class="font-heading text-2xl font-bold text-slate-700 dark:text-white">{{ cashRegister ? cashRegister.name : 'Caisse' }}</h1>
                        <span :class="['inline-flex items-center gap-1.5 border-s-2 ps-2 text-xs font-bold', sessionLocked ? 'border-amber-500 text-amber-700 dark:text-amber-300' : cashSession ? 'border-emerald-500 text-emerald-700 dark:text-emerald-300' : 'border-slate-300 text-slate-500 dark:border-slate-700 dark:text-slate-400']">
                            <Icon :name="cashSession && !sessionLocked ? 'unlock' : 'lock'" />{{ sessionLocked ? 'Session verrouillée' : cashSession ? 'Session ouverte' : 'Session fermée' }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-slate-400">Factures validées, tickets Pharmacie et reçus sont traités depuis la caisse centrale du site.</p>
                </div>

                <nav class="flex flex-wrap items-center gap-2" aria-label="Raccourcis de la caisse">
                    <Button v-if="cashRegister" :as="Link" href="/cash" size="rg" variant="white-outline"><Icon class="text-lg" name="wallet" /><span class="ms-2">Postes de caisse</span></Button>
                    <Button :as="Link" href="/reception" size="rg" variant="white-outline"><Icon class="text-lg" name="arrow-left" /><span class="ms-2">Accueil réception</span></Button>
                    <Button v-if="can('patients.view')" :as="Link" href="/patients" size="rg" variant="white-outline"><Icon class="text-lg" name="users" /><span class="ms-2">Patients</span></Button>
                </nav>
            </div>
        </header>

        <section v-if="blockingSession" class="overflow-hidden rounded-lg border border-amber-200 bg-amber-50 shadow-sm dark:border-amber-900 dark:bg-amber-950/20">
            <div class="flex items-start gap-3 px-4 py-4">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-amber-100 text-amber-600 dark:bg-amber-900 dark:text-amber-300"><Icon class="text-lg" name="lock" /></span>
                <div class="min-w-0">
                    <h2 class="text-sm font-bold text-amber-800 dark:text-amber-200">Cette caisse est utilisée par une autre personne</h2>
                    <p class="mt-0.5 text-xs leading-5 text-amber-700 dark:text-amber-300">{{ blockingSession.opener_name }} a une session en cours sur {{ cashRegister?.name ?? 'cette caisse' }}, pas encore clôturée. Pour votre sécurité, seul son ouvreur peut l’utiliser — choisissez une autre caisse disponible.</p>
                    <Button :as="Link" href="/cash" size="sm" variant="white-outline" class="mt-3"><Icon class="text-sm" name="wallet" /><span class="ms-1.5">Choisir une autre caisse</span></Button>
                </div>
            </div>
        </section>

        <section v-else-if="cashSession" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-900 lg:flex-row lg:items-center lg:justify-between">
                <div :class="['min-w-0 border-s-2 ps-3', sessionLocked ? 'border-amber-500' : 'border-emerald-500']">
                    <p :class="['text-[10px] font-bold uppercase tracking-[0.14em]', sessionLocked ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-700 dark:text-emerald-300']">{{ sessionLocked ? 'Session suspendue' : 'Session active' }}</p>
                    <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                        <h2 class="text-sm font-bold text-slate-700 dark:text-white">{{ cashSession.session_number }}</h2>
                        <span v-if="cashSession.opener" class="text-xs text-slate-400">ouverte par {{ cashSession.opener.name }} · {{ formatDateTime(cashSession.opened_at) }}</span>
                        <span v-else class="text-xs font-bold text-primary-600 dark:text-primary-300">sans titulaire depuis {{ formatDateTime(cashSession.opened_at) }} — la première action vous l’attribue</span>
                    </div>
                </div>
                <Button v-if="can('cash.close') && !sessionLocked" size="sm" :variant="showCloseForm ? 'danger-outline' : 'warning'" type="button" @click="toggleCloseForm"><Icon class="text-base" :name="showCloseForm ? 'cross' : 'lock'" /><span class="ms-1.5">{{ showCloseForm ? 'Annuler' : 'Clôturer la caisse' }}</span></Button>
            </div>

            <div v-if="sessionLocked" class="border-b border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900 dark:bg-amber-950/20">
                <p class="flex items-center gap-2 text-sm font-bold text-amber-800 dark:text-amber-200"><Icon name="lock" />Encaissements suspendus par la Super Administration</p>
                <p class="mt-1 text-xs leading-5 text-amber-700 dark:text-amber-300">{{ cashSession.lock_reason }} · {{ formatDateTime(cashSession.locked_at) }}. La session et son historique restent consultables ; seul le déverrouillage central autorise la reprise.</p>
            </div>

            <dl class="grid grid-cols-2 divide-x divide-y divide-gray-200 dark:divide-gray-900 lg:grid-cols-4 lg:divide-y-0">
                <div class="px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Fond initial</dt><dd class="mt-1 text-base font-bold text-slate-700 dark:text-white">{{ formatMoney(cashSession.opening_amount) }}</dd></div>
                <div class="px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Total encaissé</dt><dd class="mt-1 text-base font-bold text-slate-700 dark:text-white">{{ formatMoney(summary.total_collected) }}</dd></div>
                <div class="px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Espèces encaissées</dt><dd class="mt-1 text-base font-bold text-slate-700 dark:text-white">{{ formatMoney(summary.cash_collected) }}</dd></div>
                <div class="px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Espèces attendues</dt><dd class="mt-1 text-base font-bold text-slate-800 dark:text-white">{{ formatMoney(summary.expected_cash) }}</dd></div>
            </dl>

            <form v-if="showCloseForm && can('cash.close')" class="border-t border-gray-200 bg-gray-50/60 px-4 py-4 dark:border-gray-900 dark:bg-gray-1000/30" @submit.prevent="closeCash">
                <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <div class="rounded-lg border border-gray-200 bg-white p-3.5 dark:border-gray-800 dark:bg-gray-950">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-1.5 text-slate-400">
                                <Icon class="text-sm" name="calc" />
                                <span class="text-[10px] font-bold uppercase tracking-wide">Montant attendu</span>
                            </div>
                            <span class="text-sm font-bold text-slate-600 dark:text-slate-300">{{ formatMoney(summary.expected_cash) }}</span>
                        </div>
                        <FormGroup class="!mb-0 mt-3">
                            <FormLabel class="mb-1.5" for="actual_closing_amount">Espèces comptées <span class="text-red-500">*</span></FormLabel>
                            <Input id="actual_closing_amount" v-model="closeForm.actual_closing_amount" type="number" min="0" step="0.01" size="lg" class="font-bold" required />
                            <FormError v-if="closeForm.errors.actual_closing_amount">{{ closeForm.errors.actual_closing_amount }}</FormError>
                        </FormGroup>
                        <p v-if="closeFormHasVariance" class="mt-2.5 flex items-center gap-1.5 rounded border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs font-bold text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300">
                            <Icon class="shrink-0" name="alert" />
                            <span>Écart {{ Number(closeForm.actual_closing_amount) - Number(summary.expected_cash) > 0 ? '+' : '−' }}{{ formatMoney(Math.abs(Number(closeForm.actual_closing_amount) - Number(summary.expected_cash)).toFixed(2)) }}</span>
                        </p>
                        <p v-else class="mt-2.5 flex items-center gap-1.5 rounded border border-green-200 bg-green-50 px-2.5 py-1.5 text-xs font-bold text-green-700 dark:border-green-900 dark:bg-green-950/20 dark:text-green-300">
                            <Icon class="shrink-0" name="check" /><span>Montant conforme</span>
                        </p>
                    </div>

                    <div class="flex flex-col">
                        <FormGroup class="!mb-0">
                            <FormLabel class="mb-1.5" for="close_notes">
                                Observation
                                <span v-if="closeFormHasVariance" class="text-red-500">obligatoire *</span>
                                <span v-else class="font-normal text-slate-400">(facultative)</span>
                            </FormLabel>
                            <Input id="close_notes" v-model="closeForm.notes" :required="closeFormHasVariance" :aria-invalid="closeFormHasVariance && !closeForm.notes ? 'true' : undefined" placeholder="Écart constaté, remise en banque, incident…" />
                            <FormError v-if="closeForm.errors.notes">{{ closeForm.errors.notes }}</FormError>
                        </FormGroup>
                        <p class="mt-1.5 text-[11px] leading-4.5 text-slate-400">{{ closeFormHasVariance ? 'Un écart a été détecté : précisez son origine avant de confirmer.' : 'Ajoutez un repère si utile (remise en banque, incident, relève partielle…).' }}</p>
                    </div>
                </div>
                <div class="mt-3 flex items-center justify-end gap-2">
                    <FormError v-if="closeForm.errors.cash_session">{{ closeForm.errors.cash_session }}</FormError>
                    <Button size="rg" variant="warning" type="submit" :disabled="closeForm.processing"><Icon class="text-lg" name="lock" /><span class="ms-2">{{ closeForm.processing ? 'Clôture…' : 'Confirmer la clôture' }}</span></Button>
                </div>
            </form>
        </section>

        <section v-else class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-900">
                <h2 class="text-sm font-bold text-slate-700 dark:text-white">Ouvrir une session</h2>
                <p class="mt-0.5 text-xs leading-5 text-slate-400">Une session ouverte est obligatoire avant tout encaissement et toute émission de reçu.</p>
            </div>
            <form v-if="can('cash.open')" class="flex flex-wrap items-end gap-3 px-4 py-4" @submit.prevent="openCash">
                <FormGroup class="!mb-0 w-full sm:w-52"><FormLabel class="mb-1.5" for="opening_amount">Fond de caisse <span class="text-red-500">*</span></FormLabel><Input id="opening_amount" v-model="openForm.opening_amount" type="number" min="0" step="0.01" required /><FormError v-if="openForm.errors.opening_amount">{{ openForm.errors.opening_amount }}</FormError></FormGroup>
                <FormGroup class="!mb-0 w-full flex-1 sm:min-w-56"><FormLabel class="mb-1.5" for="open_notes">Note d’ouverture</FormLabel><Input id="open_notes" v-model="openForm.notes" placeholder="Observation facultative" /><FormError v-if="openForm.errors.notes">{{ openForm.errors.notes }}</FormError></FormGroup>
                <Button size="rg" variant="primary" type="submit" :disabled="openForm.processing"><Icon class="text-lg" name="unlock" /><span class="ms-2">{{ openForm.processing ? 'Ouverture…' : 'Ouvrir la caisse' }}</span></Button>
                <FormError v-if="openForm.errors.cash_register_uuid" class="w-full">{{ openForm.errors.cash_register_uuid }}</FormError>
                <FormError v-if="openForm.errors.cash_session" class="w-full">{{ openForm.errors.cash_session }}</FormError>
            </form>
        </section>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="border-b border-gray-200 px-4 dark:border-gray-900">
                <div class="grid grid-cols-3 items-stretch gap-1 sm:flex sm:items-center sm:gap-6" role="tablist" aria-label="Opérations de la caisse">
                    <button
                        v-if="can('billing.view')"
                        id="cash-invoices-tab"
                        type="button"
                        role="tab"
                        :aria-selected="activeLedgerTab === 'invoices'"
                        aria-controls="cash-invoices-panel"
                        :class="['-mb-px flex h-12 min-w-0 items-center justify-center gap-1.5 border-b-2 px-1 text-xs font-bold transition-colors sm:justify-start sm:gap-2 sm:px-0', activeLedgerTab === 'invoices' ? 'border-primary-600 text-slate-800 dark:border-primary-400 dark:text-white' : 'border-transparent text-slate-500 hover:border-gray-300 hover:text-slate-700 dark:text-slate-400 dark:hover:border-gray-700 dark:hover:text-white']"
                        @click="activeLedgerTab = 'invoices'"
                    >
                        <Icon class="text-base" name="file-text" />
                        <span class="truncate">À encaisser</span>
                        <span class="border-s border-gray-200 ps-2 tabular-nums text-slate-400 dark:border-gray-800">{{ outstandingSummary?.count ?? 0 }}</span>
                    </button>
                    <button
                        v-if="can('billing.view')"
                        id="cash-pharmacy-ticket-tab"
                        type="button"
                        role="tab"
                        :aria-selected="activeLedgerTab === 'pharmacy'"
                        aria-controls="cash-pharmacy-ticket-panel"
                        :class="['-mb-px flex h-12 min-w-0 items-center justify-center gap-1.5 border-b-2 px-1 text-xs font-bold transition-colors sm:justify-start sm:gap-2 sm:px-0', activeLedgerTab === 'pharmacy' ? 'border-primary-600 text-slate-800 dark:border-primary-400 dark:text-white' : 'border-transparent text-slate-500 hover:border-gray-300 hover:text-slate-700 dark:text-slate-400 dark:hover:border-gray-700 dark:hover:text-white']"
                        @click="activeLedgerTab = 'pharmacy'"
                    >
                        <Icon class="text-base" name="scan" />
                        <span class="truncate">Tickets Pharmacie</span>
                        <span class="border-s border-gray-200 ps-2 tabular-nums text-slate-400 dark:border-gray-800">{{ pharmacyLookup?.matches?.length ?? 0 }}</span>
                    </button>
                    <button
                        v-if="can('payments.view')"
                        id="cash-payments-tab"
                        type="button"
                        role="tab"
                        :aria-selected="activeLedgerTab === 'payments'"
                        aria-controls="cash-payments-panel"
                        :class="['-mb-px flex h-12 min-w-0 items-center justify-center gap-1.5 border-b-2 px-1 text-xs font-bold transition-colors sm:justify-start sm:gap-2 sm:px-0', activeLedgerTab === 'payments' ? 'border-primary-600 text-slate-800 dark:border-primary-400 dark:text-white' : 'border-transparent text-slate-500 hover:border-gray-300 hover:text-slate-700 dark:text-slate-400 dark:hover:border-gray-700 dark:hover:text-white']"
                        @click="activeLedgerTab = 'payments'"
                    >
                        <Icon class="text-base" name="money" />
                        <span class="truncate">Paiements</span>
                        <span class="border-s border-gray-200 ps-2 tabular-nums text-slate-400 dark:border-gray-800">{{ recentPayments.length }}</span>
                    </button>
                </div>
            </div>

            <div v-if="activeLedgerTab === 'invoices' && can('billing.view')" class="flex flex-col gap-3 border-b border-gray-200 bg-gray-50/50 px-4 py-3 dark:border-gray-900 dark:bg-gray-1000/30 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-baseline gap-3">
                    <p class="text-xs font-bold text-slate-600 dark:text-slate-300">{{ filteredOutstandingInvoices.length }} facture{{ filteredOutstandingInvoices.length > 1 ? 's' : '' }}</p>
                    <p class="text-xs text-slate-400">Solde <strong class="ms-1 font-bold text-slate-700 dark:text-white">{{ formatMoney(outstandingSummary?.balance_amount ?? 0) }}</strong></p>
                </div>
                <div class="flex w-full flex-col gap-2 sm:flex-row lg:w-auto">
                    <CashOriginFilter v-model="moduleFilter" :options="visibleModuleFilterOptions" :counts="moduleFilterCounts" />
                    <div class="relative w-full sm:w-80">
                        <IconInput id="cash_invoice_search" v-model="invoiceSearch" icon="search" type="text" inputmode="search" class="!pe-10" placeholder="Patient, facture ou passage" aria-label="Rechercher une facture" />
                        <button v-if="invoiceSearch" type="button" class="absolute inset-y-0 end-0 flex w-9 items-center justify-center text-slate-400 transition-colors hover:text-slate-600 dark:hover:text-slate-200" aria-label="Effacer la recherche" title="Effacer la recherche" @click="invoiceSearch = ''"><Icon class="text-sm" name="cross" /></button>
                    </div>
                </div>
            </div>

            <div v-if="activeLedgerTab === 'payments' && can('payments.view')" class="flex flex-col gap-3 border-b border-gray-200 bg-gray-50/50 px-4 py-3 dark:border-gray-900 dark:bg-gray-1000/30 lg:flex-row lg:items-center lg:justify-between">
                <p class="text-xs font-bold text-slate-600 dark:text-slate-300">{{ filteredRecentPayments.length }} paiement{{ filteredRecentPayments.length > 1 ? 's' : '' }} affiché{{ filteredRecentPayments.length > 1 ? 's' : '' }}</p>
                <div class="flex w-full flex-col gap-2 sm:flex-row lg:w-auto">
                    <CashOriginFilter v-model="moduleFilter" :options="visibleModuleFilterOptions" :counts="moduleFilterCounts" />
                    <div class="relative w-full sm:w-80">
                        <IconInput id="cash_payment_search" v-model="paymentSearch" icon="search" type="text" inputmode="search" class="!pe-10" placeholder="Patient, paiement ou facture" aria-label="Rechercher un paiement" />
                        <button v-if="paymentSearch" type="button" class="absolute inset-y-0 end-0 flex w-9 items-center justify-center text-slate-400 transition-colors hover:text-slate-600 dark:hover:text-slate-200" aria-label="Effacer la recherche" title="Effacer la recherche" @click="paymentSearch = ''"><Icon class="text-sm" name="cross" /></button>
                    </div>
                </div>
            </div>

            <form v-if="activeLedgerTab === 'pharmacy' && can('billing.view')" class="flex flex-col gap-2 border-b border-gray-200 bg-gray-50/50 px-4 py-3 dark:border-gray-900 dark:bg-gray-1000/30 lg:flex-row lg:items-center lg:justify-between" @submit.prevent="submitPharmacyLookup">
                <div class="inline-flex w-full shrink-0 divide-x divide-gray-200 overflow-hidden rounded border border-gray-200 bg-white dark:divide-gray-800 dark:border-gray-800 dark:bg-gray-950 sm:w-auto" role="group" aria-label="Mode de contrôle du ticket Pharmacie">
                    <button type="button" :class="['inline-flex h-9 flex-1 items-center justify-center gap-1.5 px-3 text-xs font-bold transition-colors sm:flex-none', pharmacyLookupMode === 'manual' ? 'bg-slate-700 text-white dark:bg-slate-200 dark:text-slate-900' : 'text-slate-500 hover:bg-gray-50 dark:text-slate-300 dark:hover:bg-gray-900']" @click="selectPharmacyLookupMode('manual')"><Icon name="edit" /> Saisie</button>
                    <button type="button" :class="['inline-flex h-9 flex-1 items-center justify-center gap-1.5 px-3 text-xs font-bold transition-colors sm:flex-none', pharmacyLookupMode === 'scan' ? 'bg-slate-700 text-white dark:bg-slate-200 dark:text-slate-900' : 'text-slate-500 hover:bg-gray-50 dark:text-slate-300 dark:hover:bg-gray-900']" @click="selectPharmacyLookupMode('scan')"><Icon name="scan" /> Scanner QR</button>
                </div>
                <div class="flex w-full min-w-0 gap-2 lg:max-w-xl">
                    <div class="relative min-w-0 flex-1">
                        <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 start-0 flex w-10 items-center justify-center text-slate-400">
                            <Icon class="text-base leading-none" :name="pharmacyLookupMode === 'scan' ? 'scan' : 'search'" />
                        </span>
                        <input v-model="pharmacyReference" type="search" maxlength="100" autocomplete="off" class="h-9 w-full rounded border border-gray-200 bg-white ps-10 pe-3 text-sm text-slate-700 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" :placeholder="pharmacyLookupMode === 'scan' ? 'Présentez ou saisissez le QR du ticket' : 'Référence, client, patient ou passage'">
                    </div>
                    <Button v-if="pharmacyLookupMode === 'scan'" icon size="rg" variant="white-outline" type="button" :disabled="scannerActive" title="Ouvrir la caméra" aria-label="Ouvrir la caméra QR" @click="startQrScanner"><Icon name="camera" /></Button>
                    <Button size="rg" type="submit"><Icon name="search" /><span class="ms-2 hidden sm:inline">Rechercher</span></Button>
                </div>
            </form>

            <div v-if="activeLedgerTab === 'pharmacy' && can('billing.view')" id="cash-pharmacy-ticket-panel" role="tabpanel" aria-labelledby="cash-pharmacy-ticket-tab">
                <div v-if="scannerActive || scannerError" class="border-b border-gray-200 p-4 dark:border-gray-900">
                    <div v-if="scannerActive" class="relative mx-auto max-w-2xl overflow-hidden rounded-lg border border-primary-200 bg-slate-950 dark:border-primary-900">
                        <video ref="scannerVideo" class="aspect-video w-full object-cover" playsinline muted />
                        <div class="pointer-events-none absolute inset-0 flex items-center justify-center"><span class="h-44 w-44 rounded-lg border-2 border-white/80 shadow-[0_0_0_999px_rgba(15,23,42,.35)]" /></div>
                        <button type="button" class="absolute end-3 top-3 inline-flex h-8 items-center gap-1.5 rounded bg-white px-2.5 text-xs font-bold text-slate-700 shadow" @click="stopQrScanner"><Icon name="cross" /> Fermer</button>
                    </div>
                    <p v-if="scannerError" class="mx-auto flex max-w-2xl items-start gap-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200"><Icon class="mt-0.5 shrink-0" name="alert-triangle" />{{ scannerError }}</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1050px] border-collapse">
                        <thead class="bg-gray-50/70 dark:bg-gray-1000/40"><tr><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Patient / client</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Facture / passage</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Validation</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Total</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Déjà payé</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Reste à payer</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Statut</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Actions</th></tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                            <template v-if="pharmacyLookup?.found">
                                <tr v-for="invoice in pharmacyLookup.matches" :key="invoice.uuid" class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-1000/30">
                                    <td class="px-4 py-3"><div class="flex items-center gap-2.5"><Avatar rounded size="sm" variant="slate-pale" :text="invoiceCustomerInitials(invoice)" /><div class="min-w-0"><Link v-if="invoice.patient && can('patients.view')" :href="`/patients/${invoice.patient.uuid}`" class="block max-w-56 truncate text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white">{{ invoiceCustomerName(invoice) }}</Link><span v-else class="block max-w-56 truncate text-sm font-bold text-slate-700 dark:text-white">{{ invoiceCustomerName(invoice) }}</span><span class="text-xs text-slate-400">{{ invoice.patient?.patient_number ?? 'Vente Pharmacie' }}</span></div></div></td>
                                    <td class="px-4 py-3"><Link v-if="can('billing.print')" :href="`/invoices/${invoice.uuid}?from=cash`" class="text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white">{{ invoice.invoice_number }}</Link><span v-else class="text-sm font-bold text-slate-700 dark:text-white">{{ invoice.invoice_number }}</span><p class="mt-0.5 text-xs text-slate-400">{{ invoice.episode?.episode_number ?? 'Sans passage patient' }} · {{ invoice.lines_count }} ligne{{ invoice.lines_count > 1 ? 's' : '' }}</p></td>
                                    <td class="px-4 py-3 text-sm text-slate-500">{{ formatDateTime(invoice.validated_at ?? invoice.created_at) }}</td><td class="px-4 py-3 text-end text-sm text-slate-500">{{ formatMoney(invoice.total_amount) }}</td><td class="px-4 py-3 text-end text-sm text-slate-500">{{ formatMoney(invoice.paid_amount) }}</td><td class="px-4 py-3 text-end text-sm font-bold text-slate-800 dark:text-white">{{ formatMoney(invoice.balance_amount) }}</td>
                                    <td class="px-4 py-3"><span class="inline-flex rounded border border-gray-200 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:border-gray-800 dark:text-slate-300">{{ invoiceStatusLabel(invoice) }}</span></td>
                                    <td class="px-4 py-3"><div class="flex items-center justify-end gap-2"><Button v-if="can('billing.print')" :as="Link" :href="`/invoices/${invoice.uuid}?from=cash`" icon size="rg" title="Voir et imprimer la facture" variant="white-outline" aria-label="Voir et imprimer la facture"><Icon class="text-base" name="file-text" /></Button><Button v-if="invoiceCanBePaid(invoice) && can('payments.create')" size="sm" variant="success" type="button" :disabled="!cashOperational || paymentMethods.length === 0" :title="sessionLocked ? 'Session verrouillée par la Super Administration' : undefined" @click="openPaymentDialog(invoice)"><Icon name="money" /><span class="ms-1.5">Encaisser</span></Button></div></td>
                                </tr>
                            </template>
                            <tr v-else-if="pharmacyLookup"><td colspan="8" class="px-5 py-10 text-center"><Icon class="text-2xl text-slate-300" :name="pharmacyLookup.reference ? 'cross-circle' : 'file-text'" /><p class="mt-2 text-sm font-medium text-slate-500"><template v-if="pharmacyLookup.reference">Aucun ticket Pharmacie trouvé pour <strong class="font-mono">{{ pharmacyLookup.reference }}</strong>.</template><template v-else>Aucune facture Pharmacie enregistrée.</template></p></td></tr>
                            <tr v-else><td colspan="8" class="px-5 py-10 text-center"><Icon class="text-2xl text-slate-300" name="search" /><p class="mt-2 text-sm font-medium text-slate-500">Saisissez ou scannez une référence pour afficher les factures Pharmacie.</p></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="activeLedgerTab === 'invoices' && can('billing.view')" id="cash-invoices-panel" role="tabpanel" aria-labelledby="cash-invoices-tab">
                <div v-if="selectedInvoices.length > 0" class="flex flex-wrap items-center gap-2 border-b border-gray-200 bg-primary-50/60 px-4 py-2 dark:border-gray-900 dark:bg-primary-950/20">
                    <span class="text-xs font-bold text-primary-700 dark:text-primary-300">{{ selectedInvoices.length }} facture{{ selectedInvoices.length > 1 ? 's' : '' }} sélectionnée{{ selectedInvoices.length > 1 ? 's' : '' }}</span>
                    <Button size="sm" variant="white-outline" type="button" @click="printSelectedInvoices"><Icon class="text-sm" name="printer" /><span class="ms-1.5">Imprimer</span></Button>
                    <Button size="sm" variant="white-outline" type="button" @click="exportSelectedInvoices"><Icon class="text-sm" name="download" /><span class="ms-1.5">Exporter CSV</span></Button>
                    <button type="button" class="ms-auto text-xs font-medium text-slate-500 hover:text-slate-700 dark:hover:text-slate-200" @click="selectedInvoiceUuids = new Set()">Désélectionner</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1090px] border-collapse">
                        <thead class="bg-gray-50/70 dark:bg-gray-1000/40"><tr><th class="w-10 px-4 py-2.5"><input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500" aria-label="Tout sélectionner" :checked="allVisibleInvoicesSelected" @change="toggleAllVisibleInvoices"></th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Patient</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Facture / passage</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Validation</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Total</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Déjà payé</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Reste à payer</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Statut</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Actions</th></tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                            <tr v-for="invoice in filteredOutstandingInvoices" :key="invoice.uuid" :class="['transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-1000/30', selectedInvoiceUuids.has(invoice.uuid) && 'bg-primary-50/40 dark:bg-primary-950/10']">
                                <td class="px-4 py-3"><input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500" :aria-label="`Sélectionner la facture ${invoice.invoice_number}`" :checked="selectedInvoiceUuids.has(invoice.uuid)" @change="toggleInvoiceSelection(invoice.uuid)"></td>
                                <td class="px-4 py-3"><div class="flex items-center gap-2.5"><Avatar rounded size="sm" variant="slate-pale" :text="invoiceCustomerInitials(invoice)" /><div class="min-w-0"><Link v-if="invoice.patient && can('patients.view')" :href="`/patients/${invoice.patient.uuid}`" class="block max-w-56 truncate text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white">{{ invoiceCustomerName(invoice) }}</Link><span v-else class="block max-w-56 truncate text-sm font-bold text-slate-700 dark:text-white">{{ invoiceCustomerName(invoice) }}</span><span class="text-xs text-slate-400">{{ invoice.patient?.patient_number ?? (invoice.source_module === 'PHARMACY' ? 'Vente Pharmacie' : 'Client externe') }}</span></div></div></td>
                                <td class="px-4 py-3"><Link v-if="can('billing.print')" :href="`/invoices/${invoice.uuid}?from=cash`" class="text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white">{{ invoice.invoice_number }}</Link><span v-else class="text-sm font-bold text-slate-700 dark:text-white">{{ invoice.invoice_number }}</span><p class="mt-0.5 text-xs text-slate-400">{{ invoice.episode?.episode_number ?? 'Sans passage patient' }} · {{ invoice.lines_count }} ligne{{ invoice.lines_count > 1 ? 's' : '' }}</p></td>
                                <td class="px-4 py-3 text-sm text-slate-500">{{ formatDateTime(invoice.validated_at ?? invoice.created_at) }}</td><td class="px-4 py-3 text-end text-sm text-slate-500">{{ formatMoney(invoice.total_amount) }}</td><td class="px-4 py-3 text-end text-sm text-slate-500">{{ formatMoney(invoice.paid_amount) }}</td><td class="px-4 py-3 text-end text-sm font-bold text-slate-800 dark:text-white">{{ formatMoney(invoice.balance_amount) }}</td>
                                <td class="px-4 py-3"><span class="inline-flex rounded border border-gray-200 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:border-gray-800 dark:text-slate-300">{{ invoiceStatusLabel(invoice) }}</span></td>
                                <td class="px-4 py-3"><div class="flex items-center justify-end gap-2"><Button v-if="can('billing.print')" :as="Link" :href="`/invoices/${invoice.uuid}?from=cash`" icon size="rg" title="Voir et imprimer la facture" variant="white-outline" aria-label="Voir et imprimer la facture"><Icon class="text-base" name="file-text" /></Button><Button v-if="can('payments.create')" size="sm" variant="success" type="button" :disabled="!cashOperational || paymentMethods.length === 0" :title="sessionLocked ? 'Session verrouillée par la Super Administration' : !cashSession ? 'Ouvrez la caisse avant l’encaissement' : paymentMethods.length === 0 ? 'Aucun mode de paiement actif' : 'Encaisser cette facture'" @click="openPaymentDialog(invoice)"><Icon class="text-base" name="money" /><span class="ms-1.5">Encaisser</span></Button></div></td>
                            </tr>
                            <tr v-if="filteredOutstandingInvoices.length === 0"><td colspan="9" class="px-5 py-10 text-center"><Icon class="text-2xl text-slate-300" name="file-text" /><p class="mt-2 text-sm font-medium text-slate-500">{{ invoiceSearch || moduleFilter !== 'all' ? 'Aucune facture ne correspond au filtre.' : 'Aucune facture en attente de paiement.' }}</p></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="activeLedgerTab === 'payments' && can('payments.view')" id="cash-payments-panel" role="tabpanel" aria-labelledby="cash-payments-tab">
                <div v-if="selectedPayments.length > 0" class="flex flex-wrap items-center gap-2 border-b border-gray-200 bg-primary-50/60 px-4 py-2 dark:border-gray-900 dark:bg-primary-950/20">
                    <span class="text-xs font-bold text-primary-700 dark:text-primary-300">{{ selectedPayments.length }} paiement{{ selectedPayments.length > 1 ? 's' : '' }} sélectionné{{ selectedPayments.length > 1 ? 's' : '' }}</span>
                    <Button size="sm" variant="white-outline" type="button" @click="printSelectedPayments"><Icon class="text-sm" name="printer" /><span class="ms-1.5">Imprimer</span></Button>
                    <Button size="sm" variant="white-outline" type="button" @click="exportSelectedPayments"><Icon class="text-sm" name="download" /><span class="ms-1.5">Exporter CSV</span></Button>
                    <button type="button" class="ms-auto text-xs font-medium text-slate-500 hover:text-slate-700 dark:hover:text-slate-200" @click="selectedPaymentUuids = new Set()">Désélectionner</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1010px] border-collapse">
                        <thead class="bg-gray-50/70 dark:bg-gray-1000/40"><tr><th class="w-10 px-4 py-2.5"><input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500" aria-label="Tout sélectionner" :checked="allVisiblePaymentsSelected" @change="toggleAllVisiblePayments"></th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Patient</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Paiement</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Date / heure</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Mode</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Montant</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Documents</th></tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                            <tr v-for="payment in filteredRecentPayments" :key="payment.uuid" :class="['transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-1000/30', selectedPaymentUuids.has(payment.uuid) && 'bg-primary-50/40 dark:bg-primary-950/10']">
                                <td class="px-4 py-3"><input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500" :aria-label="`Sélectionner le paiement ${payment.payment_number}`" :checked="selectedPaymentUuids.has(payment.uuid)" @change="togglePaymentSelection(payment.uuid)"></td>
                                <td class="px-4 py-3"><div class="flex items-center gap-2.5"><Avatar rounded size="sm" variant="slate-pale" :text="invoiceCustomerInitials(payment.invoice)" /><div><Link v-if="payment.invoice.patient && can('patients.view')" :href="`/patients/${payment.invoice.patient.uuid}`" class="text-sm font-bold text-slate-700 hover:text-primary-600 dark:text-white">{{ invoiceCustomerName(payment.invoice) }}</Link><span v-else class="text-sm font-bold text-slate-700 dark:text-white">{{ invoiceCustomerName(payment.invoice) }}</span><span class="block text-xs text-slate-400">{{ payment.invoice.patient?.patient_number ?? 'Vente Pharmacie' }}</span></div></div></td>
                                <td class="px-4 py-3"><p class="text-sm font-bold text-slate-700 dark:text-white">{{ payment.payment_number }}</p><p class="mt-0.5 text-xs text-slate-400">Facture {{ payment.invoice.invoice_number }}</p></td><td class="px-4 py-3 text-sm text-slate-500">{{ formatDateTime(payment.paid_at) }}</td><td class="px-4 py-3"><p class="text-sm text-slate-600 dark:text-slate-300">{{ payment.method.name }}</p><p class="mt-0.5 text-xs text-slate-400">Par {{ payment.cashier.name }}</p></td>
                                <td class="px-4 py-3 text-end text-sm"><span :class="['font-bold', payment.status === 'CANCELLED' ? 'text-slate-400 line-through' : 'text-slate-800 dark:text-white']">{{ formatMoney(payment.amount) }}</span><span v-if="payment.status === 'CANCELLED'" class="ms-2 rounded border border-red-200 px-1.5 py-0.5 text-[10px] font-medium text-red-600 dark:border-red-900 dark:text-red-300">Annulé</span></td>
                                <td class="px-4 py-3"><div class="flex items-center justify-end gap-2"><Button v-if="can('billing.print')" :as="Link" :href="`/invoices/${payment.invoice.uuid}?from=cash`" icon size="rg" title="Imprimer la facture" variant="white-outline" aria-label="Imprimer la facture"><Icon class="text-base" name="file-text" /></Button><Button v-if="payment.receipt && can('receipts.view')" :as="Link" :href="`/receipts/${payment.receipt.uuid}?from=cash`" size="sm" variant="white-outline"><Icon class="text-base" name="printer" /><span class="ms-1.5">{{ payment.receipt.receipt_number }}</span></Button></div></td>
                            </tr>
                            <tr v-if="filteredRecentPayments.length === 0"><td colspan="7" class="px-5 py-10 text-center text-sm text-slate-400">{{ moduleFilter !== 'all' ? 'Aucun paiement ne correspond au filtre.' : 'Aucun paiement enregistré.' }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <details class="group overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 marker:hidden">
                <div class="border-s-2 border-slate-300 ps-3 dark:border-slate-700"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Journal des sessions</h2><p class="mt-0.5 text-xs text-slate-400">{{ recentSessions.length }} dernières ouvertures et clôtures{{ cashRegister ? ` de ${cashRegister.name}` : '' }}</p></div>
                <Icon class="text-lg text-slate-400 transition-transform group-open:rotate-180" name="chevron-down" />
            </summary>
            <div class="overflow-x-auto border-t border-gray-200 dark:border-gray-900">
                <table class="w-full min-w-[860px] border-collapse">
                    <thead class="bg-gray-50/70 dark:bg-gray-1000/40"><tr><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Session</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Caisse</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Ouverture</th><th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-slate-400">Clôture</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Attendu</th><th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-slate-400">Écart</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900"><tr v-for="session in recentSessions" :key="session.uuid"><td class="px-4 py-3 text-sm font-bold text-slate-700 dark:text-white">{{ session.session_number }}</td><td class="px-4 py-3 text-sm text-slate-500">{{ session.register?.name ?? '—' }}</td><td class="px-4 py-3 text-sm text-slate-500">{{ formatDateTime(session.opened_at) }} · {{ session.opener?.name ?? 'sans titulaire' }}</td><td class="px-4 py-3 text-sm text-slate-500">{{ session.closed_at ? `${formatDateTime(session.closed_at)} · ${session.closer?.name}` : 'En cours' }}</td><td class="px-4 py-3 text-end text-sm text-slate-500">{{ session.expected_closing_amount == null ? '—' : formatMoney(session.expected_closing_amount) }}</td><td :class="['px-4 py-3 text-end text-sm font-bold', session.variance_amount == null || Number(session.variance_amount) === 0 ? 'text-slate-600' : 'text-red-600']">{{ session.variance_amount == null ? '—' : formatMoney(session.variance_amount) }}</td></tr></tbody>
                </table>
            </div>
        </details>

        <div v-if="paymentTarget" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/55 p-4" role="presentation" @click.self="closePaymentDialog">
            <section class="w-full max-w-xl overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="cash-payment-dialog-title">
                <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-900"><div class="flex min-w-0 items-start gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-600 dark:bg-slate-900 dark:text-slate-300"><Icon class="text-xl" name="money" /></span><div class="min-w-0"><h2 id="cash-payment-dialog-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">Encaisser la facture</h2><p class="mt-0.5 truncate text-sm text-slate-400">{{ paymentTarget.invoice_number }} · {{ invoiceCustomerName(paymentTarget) }}</p></div></div><button type="button" class="text-slate-400 hover:text-slate-600" aria-label="Fermer" @click="closePaymentDialog"><Icon class="text-xl" name="cross" /></button></header>
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
                    <div class="flex flex-col-reverse gap-2 border-t border-gray-200 pt-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between"><Button v-if="can('billing.print')" :as="Link" :href="`/invoices/${paymentTarget.uuid}?from=cash`" size="rg" variant="white-outline"><Icon class="text-lg" name="file-text" /><span class="ms-2">Voir la facture</span></Button><div class="flex justify-end gap-2"><Button size="rg" variant="white-outline" type="button" :disabled="paymentForm.processing" @click="closePaymentDialog">Annuler</Button><Button size="rg" variant="success" type="submit" :disabled="paymentForm.processing"><Icon class="text-lg" name="check" /><span class="ms-2">{{ paymentForm.processing ? 'Encaissement…' : 'Encaisser et générer le reçu' }}</span></Button></div></div>
                </form>
            </section>
        </div>
    </div>
</template>
