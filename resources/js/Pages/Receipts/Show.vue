<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    receipt: Object,
    returnToCash: Boolean,
});

const page = usePage();
const { can } = usePermissions();
const brandName = computed(() => page.props.site?.brand ?? 'Clinique Saint Georges');
const siteName = computed(() => page.props.site?.name ?? brandName.value);
const legalDetails = computed(() => page.props.site?.documents ?? {});
const publicUrl = computed(() => page.props.site?.publicUrl ?? 'https://cliniquesaintgeorges.mg');
const publicSiteLabel = computed(() => publicUrl.value.replace(/^https?:\/\//, '').replace(/\/$/, ''));
const payment = computed(() => props.receipt.payment);
const invoice = computed(() => payment.value.invoice);
const isCancelled = computed(() => payment.value.status === 'CANCELLED');
const returnHref = computed(() => (props.returnToCash ? '/cash' : `/patients/${invoice.value.patient.uuid}`));
const returnLabel = computed(() => (props.returnToCash ? 'Retour à la caisse' : 'Retour au patient'));
const qrCodeDataUrl = ref('');
const ticketRef = ref(null);
const printPageStyleId = 'receipt-print-page-size';
const millimetersPerCssPixel = 25.4 / 96;

const generateQrCode = async () => {
    try {
        qrCodeDataUrl.value = await QRCode.toDataURL(publicUrl.value, {
            errorCorrectionLevel: 'M',
            margin: 1,
            width: 240,
            color: { dark: '#000000', light: '#ffffff' },
        });
    } catch {
        qrCodeDataUrl.value = '';
    }
};

const preparePrint = (mode) => {
    document.body.dataset.receiptPrint = mode;

    let pageStyle = document.getElementById(printPageStyleId);
    if (!pageStyle) {
        pageStyle = document.createElement('style');
        pageStyle.id = printPageStyleId;
        document.head.appendChild(pageStyle);
    }

    if (mode === 'ticket') {
        const measuredHeight = ticketRef.value?.getBoundingClientRect().height ?? 0;
        const pageHeight = Math.max(50, Math.ceil(measuredHeight * millimetersPerCssPixel) + 2);
        pageStyle.textContent = `@page { size: 80mm ${pageHeight}mm; margin: 0; }`;
        return;
    }

    pageStyle.textContent = '@page { size: B5 portrait; margin: 8mm; }';
};

const clearPrint = () => {
    delete document.body.dataset.receiptPrint;
    document.getElementById(printPageStyleId)?.remove();
};

const printDocument = async (mode) => {
    if (!qrCodeDataUrl.value) await generateQrCode();
    await nextTick();
    preparePrint(mode);
    await nextTick();
    window.print();
};

const handleBeforePrint = () => {
    if (!document.body.dataset.receiptPrint) preparePrint('receipt');
};

onMounted(() => {
    generateQrCode();
    window.addEventListener('beforeprint', handleBeforePrint);
    window.addEventListener('afterprint', clearPrint);
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeprint', handleBeforePrint);
    window.removeEventListener('afterprint', clearPrint);
    clearPrint();
});
</script>

<template>
    <Head :title="`Reçu ${receipt.receipt_number}`" />

    <div class="receipt-page w-full space-y-3">
        <div class="receipt-actions flex flex-wrap items-center justify-between gap-3">
            <Button :as="Link" :href="returnHref" size="rg" variant="white-outline">
                <Icon class="text-lg" name="arrow-left" />
                <span class="ms-2">{{ returnLabel }}</span>
            </Button>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <Button v-if="can('billing.print')" :as="Link" :href="`/invoices/${invoice.uuid}${returnToCash ? '?from=cash' : ''}`" size="rg" variant="white-outline">
                    <Icon class="text-lg" name="file-text" />
                    <span class="ms-2">Voir la facture</span>
                </Button>
                <Button v-if="can('receipts.print')" size="rg" title="Imprimer le reçu B5 ou l’enregistrer en PDF" variant="white-outline" type="button" @click="printDocument('receipt')">
                    <Icon class="text-lg" name="file-text" />
                    <span class="ms-2">Reçu B5 / PDF</span>
                </Button>
                <Button v-if="can('receipts.print')" size="rg" variant="primary" type="button" @click="printDocument('ticket')">
                    <Icon class="text-lg" name="printer" />
                    <span class="ms-2">Ticket thermique</span>
                </Button>
            </div>
        </div>

        <div class="receipt-layout-scroll">
            <div class="receipt-workspace">
                <article class="receipt-paper overflow-hidden rounded border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                    <header class="receipt-brand-header border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:px-6">
                        <div class="flex items-start justify-between gap-5">
                            <div class="flex min-w-0 items-start gap-3">
                                <div class="receipt-logo flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded border border-gray-200 bg-white dark:border-gray-800">
                                    <img v-if="legalDetails.logo_url" :src="legalDetails.logo_url" :alt="`Logo ${brandName}`" class="h-full w-full object-contain p-1" />
                                    <span v-else class="font-heading text-base font-black tracking-tight text-slate-700">CSG</span>
                                </div>
                                <div class="min-w-0">
                                    <p class="receipt-brand-name font-heading text-base font-bold text-slate-800 dark:text-white">{{ brandName }}</p>
                                    <p v-if="siteName !== brandName" class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Site {{ siteName }}</p>
                                    <div class="mt-1 space-y-0.5 text-[11px] leading-4 text-slate-500">
                                        <p v-if="legalDetails.address">{{ legalDetails.address }}</p>
                                        <p v-if="legalDetails.phone || legalDetails.email"><span v-if="legalDetails.phone">Tél. {{ legalDetails.phone }}</span><span v-if="legalDetails.phone && legalDetails.email" class="mx-1.5">·</span><span v-if="legalDetails.email">{{ legalDetails.email }}</span></p>
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-x-3 text-[10px] font-bold uppercase tracking-wide text-slate-500"><span>NIF : {{ legalDetails.nif || '—' }}</span><span>STAT : {{ legalDetails.stat || '—' }}</span></div>
                                </div>
                            </div>

                            <div class="shrink-0 text-end">
                                <p class="receipt-title font-heading text-xl font-black uppercase tracking-[0.12em] text-slate-800 dark:text-white">Reçu de paiement</p>
                                <p class="receipt-number font-mono text-sm font-bold text-slate-700 dark:text-slate-200">{{ receipt.receipt_number }}</p>
                                <p class="receipt-invoice-ref text-[10px] font-medium text-slate-400">Facture {{ invoice.invoice_number }}</p>
                                <div class="mt-1 flex items-center justify-end gap-2">
                                    <p class="receipt-date text-[10px] text-slate-500">{{ formatDateTime(receipt.issued_at) }}</p>
                                    <span :class="['receipt-status inline-flex rounded-sm border px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide', isCancelled ? 'border-red-300 text-red-700 dark:border-red-900 dark:text-red-300' : 'border-gray-300 text-slate-600 dark:border-gray-700 dark:text-slate-300']">{{ isCancelled ? 'Annulé' : 'Encaissé' }}</span>
                                </div>
                            </div>
                        </div>
                    </header>

                    <div v-if="isCancelled" class="receipt-cancelled border-b border-red-200 bg-red-50/60 px-5 py-2.5 text-xs text-red-700 dark:border-red-900 dark:bg-red-950/20 dark:text-red-300 sm:px-6">
                        <strong class="font-bold uppercase tracking-wide">Paiement annulé.</strong> Ce reçu est conservé uniquement comme trace historique.
                        <span v-if="payment.cancellation_reason"> Motif : {{ payment.cancellation_reason }}</span>
                    </div>

                    <section class="receipt-meta-grid border-b border-gray-200 px-5 py-3 dark:border-gray-900 sm:px-6">
                        <div><p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Reçu de</p><p class="mt-1 font-heading text-sm font-bold text-slate-800 dark:text-white">{{ formatPatientName(invoice.patient) }}</p><p class="font-mono text-[11px] text-slate-500">Patient {{ invoice.patient.patient_number }}</p></div>
                        <div><p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Référence</p><p class="mt-1 font-mono text-xs font-bold text-slate-700 dark:text-slate-200">Facture {{ invoice.invoice_number }}</p><p class="text-[10px] text-slate-400">Passage {{ invoice.episode.episode_number }}</p></div>
                        <div><p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Encaissement</p><p class="mt-1 font-mono text-xs font-bold text-slate-700 dark:text-slate-200">{{ payment.payment_number }}</p><p class="text-[10px] text-slate-400">{{ payment.method.name }} · {{ payment.cashier.name }}</p></div>
                    </section>

                    <section class="receipt-content px-5 py-4 sm:px-6">
                        <div class="receipt-amount border-y border-gray-200 py-4 text-center dark:border-gray-800">
                            <p class="text-[9px] font-bold uppercase tracking-[0.16em] text-slate-400">Montant reçu</p>
                            <p :class="['mt-1 font-heading text-3xl font-black tracking-tight', isCancelled ? 'text-slate-400 line-through' : 'text-slate-800 dark:text-white']">{{ formatMoney(payment.amount) }}</p>
                            <p class="mt-1 text-xs text-slate-500">Réglé par {{ payment.method.name }}</p>
                        </div>

                        <div class="receipt-details-grid mt-4">
                            <div class="receipt-note text-[11px] leading-5 text-slate-500">
                                <p>{{ isCancelled ? 'Ce document atteste un encaissement ensuite annulé et conservé dans l’historique financier.' : 'Ce document atteste le versement réellement encaissé pour la facture indiquée.' }}</p>
                                <p class="mt-2 text-slate-400">Enregistré le {{ formatDateTime(payment.paid_at) }} par {{ payment.cashier.name }}.</p>
                                <p v-if="payment.reference" class="mt-1"><span class="text-slate-400">Référence externe :</span> <strong class="font-medium text-slate-600 dark:text-slate-300">{{ payment.reference }}</strong></p>
                            </div>
                            <dl class="receipt-totals space-y-1.5 text-xs">
                                <div class="flex items-center justify-between gap-4"><dt class="text-slate-400">Total facture</dt><dd class="font-medium text-slate-700 dark:text-white">{{ formatMoney(invoice.total_amount) }}</dd></div>
                                <div class="flex items-center justify-between gap-4"><dt class="text-slate-400">Total réglé</dt><dd class="font-medium text-slate-700 dark:text-white">{{ formatMoney(invoice.paid_amount) }}</dd></div>
                                <div class="flex items-center justify-between gap-4 border-t border-slate-700 pt-1.5"><dt class="font-bold text-slate-700 dark:text-white">Reste à payer</dt><dd class="text-sm font-black text-slate-800 dark:text-white">{{ formatMoney(invoice.balance_amount) }}</dd></div>
                            </dl>
                        </div>
                    </section>

                    <footer class="receipt-document-footer flex items-center justify-between gap-4 border-t border-gray-200 px-5 py-2 text-[9px] leading-3 text-slate-400 dark:border-gray-900 sm:px-6">
                        <div><p>{{ brandName }} — {{ siteName }}</p><p class="mt-0.5">Reçu émis par {{ receipt.issuer.name }} · <a :href="publicUrl" class="font-semibold text-slate-500">{{ publicSiteLabel }}</a></p></div>
                        <img v-if="qrCodeDataUrl" :src="qrCodeDataUrl" alt="QR code du site officiel" class="receipt-document-qr h-11 w-11 shrink-0" />
                    </footer>
                </article>

                <aside class="receipt-ticket-panel overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                    <div class="ticket-panel-header flex items-center justify-between gap-3 border-b border-gray-200 px-3 py-2.5 dark:border-gray-900">
                        <div class="flex min-w-0 items-center gap-3"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="printer" /></span><div class="min-w-0"><h2 class="text-xs font-bold text-slate-700 dark:text-white">Aperçu ticket</h2><p class="text-[10px] text-slate-400">Imprimante thermique</p></div></div>
                        <span class="rounded-sm border border-gray-200 px-2 py-0.5 text-[9px] font-bold text-slate-500 dark:border-gray-800">80 mm</span>
                    </div>

                    <div class="ticket-preview-frame bg-gray-50 p-2 dark:bg-gray-900/40">
                        <article ref="ticketRef" class="receipt-ticket mx-auto w-full max-w-[80mm] bg-white text-slate-800 shadow-sm">
                            <header class="border-b border-dashed border-slate-400 pb-2.5 text-center">
                                <p class="text-xs font-black uppercase tracking-wide">{{ brandName }}</p>
                                <p v-if="siteName !== brandName" class="text-[9px] font-bold uppercase tracking-[0.12em]">Site {{ siteName }}</p>
                                <h1 class="mt-2 text-base font-black uppercase tracking-wide">Reçu de paiement</h1>
                                <p class="font-mono text-sm font-bold">{{ receipt.receipt_number }}</p>
                                <p class="text-[9px] text-slate-500">Facture {{ invoice.invoice_number }}</p>
                                <p class="text-[9px]">{{ formatDateTime(receipt.issued_at) }}</p>
                                <span :class="['mt-1 inline-flex border px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wide', isCancelled ? 'border-red-700 text-red-700' : 'border-slate-700']">{{ isCancelled ? 'Annulé' : 'Encaissé' }}</span>
                            </header>

                            <div v-if="isCancelled" class="border-b border-dashed border-slate-400 py-2 text-center text-[10px] font-bold uppercase">Paiement annulé — trace historique</div>

                            <section class="space-y-1 border-b border-dashed border-slate-400 py-2.5 text-[11px]">
                                <div class="flex items-start justify-between gap-3"><span class="shrink-0">Patient</span><span class="text-end font-bold">{{ formatPatientName(invoice.patient) }}</span></div>
                                <div class="flex items-start justify-between gap-3"><span class="shrink-0">N° patient</span><span class="text-end font-mono font-bold">{{ invoice.patient.patient_number }}</span></div>
                                <div class="flex items-start justify-between gap-3"><span class="shrink-0">Facture</span><span class="text-end font-mono font-bold">{{ invoice.invoice_number }}</span></div>
                                <div class="flex items-start justify-between gap-3"><span class="shrink-0">Passage</span><span class="text-end font-mono font-bold">{{ invoice.episode.episode_number }}</span></div>
                                <div class="flex items-start justify-between gap-3"><span class="shrink-0">Paiement</span><span class="text-end font-mono font-bold">{{ payment.payment_number }}</span></div>
                                <div class="flex items-start justify-between gap-3"><span class="shrink-0">Caissier</span><span class="text-end font-medium">{{ payment.cashier.name }}</span></div>
                            </section>

                            <section class="border-b border-dashed border-slate-400 py-3 text-center">
                                <p class="text-[9px] font-black uppercase tracking-wider">Montant reçu</p>
                                <p :class="['mt-1 text-xl font-black', isCancelled ? 'line-through' : '']">{{ formatMoney(payment.amount) }}</p>
                                <p class="mt-0.5 text-[10px]">{{ payment.method.name }}</p>
                                <p v-if="payment.reference" class="mt-1 break-all text-[9px]">Réf. {{ payment.reference }}</p>
                            </section>

                            <section class="border-b border-dashed border-slate-400 py-2.5">
                                <dl class="space-y-1 text-[11px]"><div class="flex items-center justify-between gap-3"><dt>Total facture</dt><dd class="font-bold">{{ formatMoney(invoice.total_amount) }}</dd></div><div class="flex items-center justify-between gap-3"><dt>Total réglé</dt><dd class="font-bold">{{ formatMoney(invoice.paid_amount) }}</dd></div><div class="mt-1.5 flex items-center justify-between gap-3 border-y border-slate-800 py-1.5"><dt class="font-black uppercase">Reste à payer</dt><dd class="text-sm font-black">{{ formatMoney(invoice.balance_amount) }}</dd></div></dl>
                            </section>

                            <section class="py-2.5 text-center"><img v-if="qrCodeDataUrl" :src="qrCodeDataUrl" alt="QR code du site officiel" class="receipt-qr mx-auto h-[22mm] w-[22mm]" /><p class="text-[9px] font-bold uppercase tracking-wide">Site officiel</p></section>

                            <footer class="border-t border-dashed border-slate-400 pt-2 text-center text-[9px] leading-3.5"><a :href="publicUrl" class="break-all font-bold underline">{{ publicSiteLabel }}</a><p class="mt-1.5">Ce reçu correspond à un paiement réellement enregistré.</p><p class="mt-1.5">Merci de votre confiance.</p></footer>
                        </article>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</template>

<style>
.receipt-layout-scroll {
    overflow-x: auto;
    padding-bottom: 0.25rem;
}

.receipt-workspace {
    display: grid;
    grid-template-columns: minmax(760px, 900px) 360px;
    align-items: start;
    justify-content: space-between;
    gap: 1.25rem;
    width: 100%;
    min-width: 1140px;
}

.receipt-paper {
    width: 100%;
    min-width: 760px;
    max-width: 900px;
}

.receipt-meta-grid {
    display: grid;
    grid-template-columns: 1.15fr 0.9fr 1fr;
    gap: 1.5rem;
}

.receipt-details-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 260px;
    gap: 1.5rem;
}

.receipt-ticket-panel {
    position: sticky;
    top: 1.25rem;
    align-self: start;
    width: 360px;
}

.receipt-ticket {
    padding: 4mm;
}

.receipt-qr,
.receipt-document-qr {
    image-rendering: crisp-edges;
}

@media print {
    html,
    body {
        min-width: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }

    .nk-sidebar,
    .nk-header,
    .nk-footer,
    .receipt-actions,
    .ticket-panel-header {
        display: none !important;
    }

    .nk-wrap,
    .nk-content,
    .receipt-page,
    .receipt-layout-scroll,
    .receipt-workspace {
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .receipt-layout-scroll {
        overflow: visible !important;
    }

    .receipt-workspace {
        display: block !important;
    }

    body[data-receipt-print="receipt"] .receipt-ticket-panel {
        display: none !important;
    }

    body[data-receipt-print="receipt"] .receipt-paper {
        display: block !important;
        width: 100% !important;
        min-width: 0 !important;
        max-width: none !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: #fff !important;
        color: #000 !important;
        font-family: Arial, Helvetica, sans-serif !important;
        font-size: 8pt !important;
        line-height: 1.25 !important;
    }

    body[data-receipt-print="receipt"] .receipt-paper,
    body[data-receipt-print="receipt"] .receipt-paper * {
        color: #000 !important;
    }

    body[data-receipt-print="receipt"] .receipt-brand-header {
        padding: 0 0 4mm !important;
        border-bottom: 1pt solid #111 !important;
    }

    body[data-receipt-print="receipt"] .receipt-logo {
        width: 14mm !important;
        height: 14mm !important;
        border: 0 !important;
        border-radius: 0 !important;
    }

    body[data-receipt-print="receipt"] .receipt-brand-name {
        font-size: 12pt !important;
        line-height: 1.1 !important;
    }

    body[data-receipt-print="receipt"] .receipt-title {
        font-size: 15pt !important;
        line-height: 1 !important;
        letter-spacing: 0.06em !important;
    }

    body[data-receipt-print="receipt"] .receipt-number {
        margin-top: 1mm !important;
        font-size: 10pt !important;
    }

    body[data-receipt-print="receipt"] .receipt-invoice-ref {
        margin-top: 0.5mm !important;
        font-size: 6.5pt !important;
    }

    body[data-receipt-print="receipt"] .receipt-date,
    body[data-receipt-print="receipt"] .receipt-status {
        font-size: 6.5pt !important;
    }

    body[data-receipt-print="receipt"] .receipt-cancelled {
        padding: 2mm 0 !important;
        border-bottom: 1pt solid #111 !important;
        background: #fff !important;
        font-size: 7pt !important;
    }

    body[data-receipt-print="receipt"] .receipt-meta-grid {
        grid-template-columns: 1.15fr 0.9fr 1fr !important;
        gap: 5mm !important;
        padding: 3mm 0 !important;
    }

    body[data-receipt-print="receipt"] .receipt-content {
        padding: 3.5mm 0 0 !important;
    }

    body[data-receipt-print="receipt"] .receipt-amount {
        padding: 3mm 0 !important;
        border-color: #111 !important;
    }

    body[data-receipt-print="receipt"] .receipt-amount p:nth-child(2) {
        font-size: 20pt !important;
    }

    body[data-receipt-print="receipt"] .receipt-details-grid {
        grid-template-columns: minmax(0, 1fr) 55mm !important;
        gap: 6mm !important;
        margin-top: 3mm !important;
    }

    body[data-receipt-print="receipt"] .receipt-note {
        font-size: 6.5pt !important;
        line-height: 1.35 !important;
    }

    body[data-receipt-print="receipt"] .receipt-totals {
        font-size: 7.5pt !important;
    }

    body[data-receipt-print="receipt"] .receipt-document-footer {
        margin-top: 3mm !important;
        padding: 2mm 0 0 !important;
        font-size: 5.75pt !important;
        line-height: 1.2 !important;
    }

    body[data-receipt-print="receipt"] .receipt-document-qr {
        width: 12mm !important;
        height: 12mm !important;
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }

    body[data-receipt-print="receipt"] .receipt-paper section,
    body[data-receipt-print="receipt"] .receipt-paper footer,
    body[data-receipt-print="receipt"] .receipt-paper dl {
        break-inside: avoid;
    }

    body[data-receipt-print="ticket"] {
        width: 80mm !important;
    }

    body[data-receipt-print="ticket"] .receipt-paper {
        display: none !important;
    }

    body[data-receipt-print="ticket"] .receipt-page,
    body[data-receipt-print="ticket"] .receipt-layout-scroll,
    body[data-receipt-print="ticket"] .receipt-workspace,
    body[data-receipt-print="ticket"] .receipt-ticket-panel,
    body[data-receipt-print="ticket"] .ticket-preview-frame,
    body[data-receipt-print="ticket"] .receipt-ticket {
        width: 80mm !important;
        max-width: 80mm !important;
        margin: 0 !important;
        box-sizing: border-box !important;
    }

    body[data-receipt-print="ticket"] .receipt-ticket-panel {
        display: block !important;
        position: static !important;
        overflow: visible !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: #fff !important;
    }

    body[data-receipt-print="ticket"] .ticket-preview-frame {
        padding: 0 !important;
        background: #fff !important;
    }

    body[data-receipt-print="ticket"] .receipt-ticket {
        padding: 4mm !important;
        border: 0 !important;
        box-shadow: none !important;
        background: #fff !important;
        color: #000 !important;
    }

    body[data-receipt-print="ticket"] .receipt-ticket,
    body[data-receipt-print="ticket"] .receipt-ticket * {
        color: #000 !important;
    }

    body[data-receipt-print="ticket"] .receipt-ticket .font-mono {
        font-family: "Courier New", monospace !important;
    }

    body[data-receipt-print="ticket"] .receipt-ticket section,
    body[data-receipt-print="ticket"] .receipt-ticket footer,
    body[data-receipt-print="ticket"] .receipt-qr {
        break-inside: avoid;
    }

    body[data-receipt-print="ticket"] .receipt-qr {
        width: 22mm !important;
        height: 22mm !important;
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
}
</style>
