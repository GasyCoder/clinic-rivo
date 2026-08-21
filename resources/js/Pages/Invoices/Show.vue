<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    invoice: Object,
    returnToCash: Boolean,
});
const page = usePage();
const brandName = computed(() => page.props.site?.brand ?? 'Clinique Saint Georges');
const siteName = computed(() => page.props.site?.name ?? brandName.value);
const legalDetails = computed(() => page.props.site?.documents ?? {});
const publicUrl = computed(() => page.props.site?.publicUrl ?? 'https://cliniquesaintgeorges.mg');
const publicSiteLabel = computed(() => publicUrl.value.replace(/^https?:\/\//, '').replace(/\/$/, ''));
const status = computed(() => page.props.flash?.status);
const hasDiscount = computed(() => Number(props.invoice.discount_amount ?? 0) > 0);
const returnHref = computed(() => (props.returnToCash ? '/cash' : `/patients/${props.invoice.patient.uuid}`));
const returnLabel = computed(() => (props.returnToCash ? 'Retour à la caisse' : 'Retour au patient'));
const qrCodeDataUrl = ref('');
const ticketRef = ref(null);
const printPageStyleId = 'invoice-print-page-size';
const millimetersPerCssPixel = 25.4 / 96;

const invoiceStatusLabel = computed(() => ({
    DRAFT: 'Brouillon',
    VALIDATED: 'À payer',
    PARTIALLY_PAID: 'Paiement partiel',
    PAID: 'Acquittée',
    CANCELLED: 'Annulée',
})[props.invoice.status] ?? props.invoice.status);

const generateQrCode = async () => {
    try {
        qrCodeDataUrl.value = await QRCode.toDataURL(publicUrl.value, {
            errorCorrectionLevel: 'M',
            margin: 1,
            width: 240,
            color: {
                dark: '#000000',
                light: '#ffffff',
            },
        });
    } catch {
        qrCodeDataUrl.value = '';
    }
};

const preparePrint = (mode) => {
    document.body.dataset.invoicePrint = mode;

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
    delete document.body.dataset.invoicePrint;
    document.getElementById(printPageStyleId)?.remove();
};

const printDocument = async (mode) => {
    if (mode === 'ticket' && !qrCodeDataUrl.value) await generateQrCode();
    await nextTick();
    preparePrint(mode);
    await nextTick();
    window.print();
};

const handleBeforePrint = () => {
    if (!document.body.dataset.invoicePrint) preparePrint('invoice');
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
    <Head :title="`Facture ${invoice.invoice_number}`" />

    <div class="invoice-page w-full space-y-3">
        <div v-if="status" class="invoice-actions flex items-start gap-3 rounded border border-gray-200 bg-white px-4 py-2.5 text-sm leading-5 text-slate-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300" role="status">
            <Icon class="mt-0.5 shrink-0 text-lg text-green-600" name="check-circle" />
            <span>{{ status }}</span>
        </div>

        <div class="invoice-actions flex flex-wrap items-center justify-between gap-3">
            <Button :as="Link" :href="returnHref" size="rg" variant="white-outline">
                <Icon class="text-lg" name="arrow-left" />
                <span class="ms-2">{{ returnLabel }}</span>
            </Button>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <Button size="rg" title="Imprimer la facture B5 ou choisir Enregistrer au format PDF" variant="white-outline" type="button" @click="printDocument('invoice')">
                    <Icon class="text-lg" name="file-text" />
                    <span class="ms-2">Facture B5 / PDF</span>
                </Button>
                <Button size="rg" variant="primary" type="button" @click="printDocument('ticket')">
                    <Icon class="text-lg" name="printer" />
                    <span class="ms-2">Ticket thermique</span>
                </Button>
            </div>
        </div>

        <div class="invoice-layout-scroll">
            <div class="invoice-workspace">
                <article class="invoice-document overflow-hidden rounded border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <header class="invoice-brand-header border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:px-6">
                    <div class="flex items-start justify-between gap-5">
                        <div class="flex min-w-0 items-start gap-3">
                            <div class="invoice-logo flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded border border-gray-200 bg-white dark:border-gray-800">
                                <img v-if="legalDetails.logo_url" :src="legalDetails.logo_url" :alt="`Logo ${brandName}`" class="h-full w-full object-contain p-1" />
                                <span v-else class="font-heading text-base font-black tracking-tight text-slate-700">CSG</span>
                            </div>
                            <div class="min-w-0">
                                <p class="invoice-brand-name font-heading text-base font-bold text-slate-800 dark:text-white">{{ brandName }}</p>
                                <p v-if="siteName !== brandName" class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Site {{ siteName }}</p>
                                <div class="mt-1.5 space-y-0.5 text-[11px] leading-4 text-slate-500">
                                    <p v-if="legalDetails.address">{{ legalDetails.address }}</p>
                                    <p v-if="legalDetails.phone || legalDetails.email">
                                        <span v-if="legalDetails.phone">Tél. {{ legalDetails.phone }}</span>
                                        <span v-if="legalDetails.phone && legalDetails.email" class="mx-1.5">·</span>
                                        <span v-if="legalDetails.email">{{ legalDetails.email }}</span>
                                    </p>
                                </div>
                                <div class="mt-1 flex flex-wrap gap-x-3 text-[10px] font-bold uppercase tracking-wide text-slate-500">
                                    <span>NIF : {{ legalDetails.nif || '—' }}</span>
                                    <span>STAT : {{ legalDetails.stat || '—' }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="shrink-0 text-end">
                            <p class="invoice-title font-heading text-xl font-black uppercase tracking-[0.12em] text-slate-800 dark:text-white">Facture</p>
                            <p class="invoice-number font-mono text-sm font-bold text-slate-700 dark:text-slate-200">{{ invoice.invoice_number }}</p>
                            <div class="mt-1 flex items-center justify-end gap-2">
                                <p class="invoice-date text-[10px] text-slate-500">{{ formatDateTime(invoice.validated_at ?? invoice.created_at) }}</p>
                                <span class="invoice-status inline-flex rounded-sm border border-gray-300 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide text-slate-600 dark:border-gray-700 dark:text-slate-300">{{ invoiceStatusLabel }}</span>
                            </div>
                        </div>
                    </div>
                </header>

                <section class="invoice-meta-grid border-b border-gray-200 px-5 py-3 dark:border-gray-900 sm:px-6">
                    <div>
                        <p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Facturé à</p>
                        <p class="mt-1 font-heading text-sm font-bold text-slate-800 dark:text-white">{{ formatPatientName(invoice.patient) }}</p>
                        <p class="font-mono text-[11px] text-slate-500">Patient {{ invoice.patient.patient_number }}</p>
                    </div>
                    <div>
                        <p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Passage</p>
                        <p class="mt-1 font-mono text-xs font-bold text-slate-700 dark:text-slate-200">{{ invoice.episode.episode_number }}</p>
                    </div>
                    <div>
                        <p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Émission</p>
                        <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">Par <strong class="font-semibold">{{ invoice.creator.name }}</strong></p>
                        <p v-if="invoice.validator" class="text-[10px] text-slate-400">Validée par {{ invoice.validator.name }}</p>
                    </div>
                </section>

                <section class="invoice-services px-5 py-4 sm:px-6">
                    <div class="mb-2 flex items-end justify-between gap-4">
                        <div>
                            <h2 class="font-heading text-sm font-bold text-slate-800 dark:text-white">Prestations facturées</h2>
                            <p class="text-[10px] text-slate-400">Tarifs conservés lors de la validation.</p>
                        </div>
                        <span class="shrink-0 text-[10px] font-medium text-slate-500">{{ invoice.lines.length }} ligne{{ invoice.lines.length > 1 ? 's' : '' }}</span>
                    </div>

                    <div class="invoice-table-wrap overflow-x-auto">
                        <table class="invoice-table w-full min-w-[620px] text-xs">
                            <colgroup>
                                <col class="invoice-col-description" />
                                <col class="invoice-col-quantity" />
                                <col class="invoice-col-price" />
                                <col class="invoice-col-total" />
                            </colgroup>
                            <thead class="border-y border-gray-200 text-[9px] uppercase tracking-wide text-slate-400 dark:border-gray-800">
                                <tr>
                                    <th class="px-2.5 py-2 text-start font-bold">Désignation</th>
                                    <th class="px-2.5 py-2 text-end font-bold">Qté</th>
                                    <th class="px-2.5 py-2 text-end font-bold">Tarif</th>
                                    <th class="px-2.5 py-2 text-end font-bold">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                                <tr v-for="line in invoice.lines" :key="line.id">
                                    <td class="px-2.5 py-2.5 font-medium text-slate-700 dark:text-slate-200">{{ line.description }} <span v-if="line.billable_item?.source_module" class="ms-1 text-[9px] font-normal uppercase tracking-wide text-slate-400">{{ line.billable_item.source_module }}</span></td>
                                    <td class="px-2.5 py-2.5 text-end text-slate-500">{{ line.quantity }}</td>
                                    <td class="px-2.5 py-2.5 text-end text-slate-500">{{ formatMoney(line.unit_price) }}</td>
                                    <td class="px-2.5 py-2.5 text-end font-bold text-slate-700 dark:text-white">{{ formatMoney(line.line_total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="invoice-summary-grid mt-4 gap-6">
                        <div class="invoice-notes text-[10px] leading-4 text-slate-400">
                            <p>Cette facture reprend les prestations et tarifs enregistrés au moment de l’arrivée.</p>
                            <p class="mt-1 font-medium text-slate-500">Un reçu est émis uniquement après un encaissement réel.</p>
                        </div>
                        <dl class="invoice-totals space-y-1 text-xs">
                            <div class="flex items-center justify-between gap-4"><dt class="text-slate-400">Sous-total</dt><dd class="font-medium text-slate-700 dark:text-white">{{ formatMoney(invoice.subtotal_amount) }}</dd></div>
                            <div v-if="hasDiscount" class="flex items-center justify-between gap-4"><dt class="text-slate-400">Remise</dt><dd class="font-medium text-slate-700 dark:text-white">− {{ formatMoney(invoice.discount_amount) }}</dd></div>
                            <div class="flex items-center justify-between gap-4 border-t border-gray-200 pt-1.5 dark:border-gray-800"><dt class="font-bold text-slate-600 dark:text-slate-300">Total</dt><dd class="text-sm font-bold text-slate-800 dark:text-white">{{ formatMoney(invoice.total_amount) }}</dd></div>
                            <div class="flex items-center justify-between gap-4"><dt class="text-slate-400">Payé</dt><dd class="font-medium text-slate-700 dark:text-white">{{ formatMoney(invoice.paid_amount) }}</dd></div>
                            <div class="flex items-center justify-between gap-4 border-t border-slate-700 pt-1.5"><dt class="font-bold text-slate-700 dark:text-white">Reste à payer</dt><dd class="text-base font-black text-slate-800 dark:text-white">{{ formatMoney(invoice.balance_amount) }}</dd></div>
                        </dl>
                    </div>
                </section>

                <footer class="invoice-document-footer flex items-center justify-between gap-3 border-t border-gray-200 px-5 py-2 text-[9px] leading-3 text-slate-400 dark:border-gray-900 sm:px-6">
                    <span>{{ brandName }} — {{ siteName }}</span>
                    <span>Merci de votre confiance · <a :href="publicUrl" class="font-semibold text-slate-500 hover:text-primary-600 hover:underline">{{ publicSiteLabel }}</a></span>
                </footer>
            </article>

                <aside class="invoice-ticket-panel overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <div class="ticket-panel-header flex items-center justify-between gap-3 border-b border-gray-200 px-3 py-2.5 dark:border-gray-900">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-base" name="printer" /></span>
                        <div class="min-w-0"><h2 class="text-xs font-bold text-slate-700 dark:text-white">Aperçu ticket</h2><p class="text-[10px] text-slate-400">Imprimante thermique</p></div>
                    </div>
                    <span class="rounded-sm border border-gray-200 px-2 py-0.5 text-[9px] font-bold text-slate-500 dark:border-gray-800">80 mm</span>
                </div>

                <div class="ticket-preview-frame bg-gray-50 p-2 dark:bg-gray-900/40">
                    <article ref="ticketRef" class="invoice-ticket mx-auto w-full max-w-[80mm] bg-white p-3 text-slate-800 shadow-sm">
                        <header class="border-b border-dashed border-slate-400 pb-2.5 text-center">
                            <p class="text-xs font-black uppercase tracking-wide">{{ brandName }}</p>
                            <p v-if="siteName !== brandName" class="text-[9px] font-bold uppercase tracking-[0.12em]">Site {{ siteName }}</p>
                            <h1 class="mt-2 text-base font-black uppercase tracking-wide">Facture patient</h1>
                            <p class="font-mono text-sm font-bold">{{ invoice.invoice_number }}</p>
                            <p class="text-[9px]">{{ formatDateTime(invoice.validated_at ?? invoice.created_at) }}</p>
                            <span class="mt-1 inline-flex border border-slate-700 px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wide">{{ invoiceStatusLabel }}</span>
                        </header>

                        <section class="space-y-1 border-b border-dashed border-slate-400 py-2.5 text-[11px]">
                            <div class="flex items-start justify-between gap-3"><span class="shrink-0">Patient</span><span class="text-end font-bold">{{ formatPatientName(invoice.patient) }}</span></div>
                            <div class="flex items-start justify-between gap-3"><span class="shrink-0">N° patient</span><span class="text-end font-mono font-bold">{{ invoice.patient.patient_number }}</span></div>
                            <div class="flex items-start justify-between gap-3"><span class="shrink-0">Passage</span><span class="text-end font-mono font-bold">{{ invoice.episode.episode_number }}</span></div>
                            <div class="flex items-start justify-between gap-3"><span class="shrink-0">Émise par</span><span class="text-end font-medium">{{ invoice.creator.name }}</span></div>
                        </section>

                        <section class="border-b border-dashed border-slate-400 py-2.5">
                            <h2 class="mb-1.5 text-center text-[9px] font-black uppercase tracking-wider">Détail des prestations</h2>
                            <div class="divide-y divide-dashed divide-slate-300">
                                <div v-for="line in invoice.lines" :key="line.id" class="ticket-line py-1.5 text-[11px] first:pt-0 last:pb-0">
                                    <div class="flex items-start justify-between gap-3"><p class="min-w-0 font-bold leading-3.5">{{ line.description }}</p><p class="shrink-0 font-black">{{ formatMoney(line.line_total) }}</p></div>
                                    <div class="flex items-center justify-between gap-3 text-[9px] text-slate-500"><span>{{ line.quantity }} × {{ formatMoney(line.unit_price) }}</span><span v-if="line.billable_item?.source_module" class="uppercase">{{ line.billable_item.source_module }}</span></div>
                                </div>
                            </div>
                        </section>

                        <section class="border-b border-dashed border-slate-400 py-2.5">
                            <dl class="space-y-1 text-[11px]">
                                <div class="flex items-center justify-between gap-3"><dt>Total</dt><dd class="font-bold">{{ formatMoney(invoice.total_amount) }}</dd></div>
                                <div class="flex items-center justify-between gap-3"><dt>Payé</dt><dd class="font-bold">{{ formatMoney(invoice.paid_amount) }}</dd></div>
                                <div class="mt-1.5 flex items-center justify-between gap-3 border-y border-slate-800 py-1.5"><dt class="font-black uppercase">Reste à payer</dt><dd class="text-sm font-black">{{ formatMoney(invoice.balance_amount) }}</dd></div>
                            </dl>
                        </section>

                        <section class="py-2.5 text-center">
                            <img v-if="qrCodeDataUrl" :src="qrCodeDataUrl" alt="QR code du site officiel de la Clinique Saint Georges" class="invoice-qr mx-auto h-[22mm] w-[22mm]" />
                            <p class="text-[9px] font-bold uppercase tracking-wide">Site officiel</p>
                        </section>

                        <footer class="border-t border-dashed border-slate-400 pt-2 text-center text-[9px] leading-3.5">
                            <a :href="publicUrl" class="break-all font-bold underline">{{ publicSiteLabel }}</a>
                            <p class="mt-1.5">Facture établie selon les tarifs enregistrés à l’arrivée.</p>
                            <p class="font-bold">Un reçu est émis uniquement après un encaissement réel.</p>
                            <p class="mt-1.5">Merci de votre confiance.</p>
                        </footer>
                    </article>
                </div>
                </aside>
            </div>
        </div>
    </div>
</template>

<style>
.invoice-layout-scroll {
    overflow-x: auto;
    padding-bottom: 0.25rem;
}

.invoice-workspace {
    display: grid;
    grid-template-columns: minmax(760px, 900px) 360px;
    align-items: start;
    justify-content: space-between;
    gap: 1.25rem;
    width: 100%;
    min-width: 1140px;
    margin-inline: 0;
}

.invoice-document {
    width: 100%;
    min-width: 760px;
    max-width: 900px;
}

.invoice-meta-grid {
    display: grid;
    grid-template-columns: 1.2fr 0.7fr 1fr;
    gap: 1.5rem;
}

.invoice-summary-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 260px;
}

.invoice-ticket-panel {
    position: sticky;
    top: 1.25rem;
    align-self: start;
    width: 360px;
}

.invoice-ticket {
    padding: 4mm;
}

.invoice-qr {
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
    .invoice-actions,
    .ticket-panel-header {
        display: none !important;
    }

    .nk-wrap {
        min-height: 0 !important;
        padding: 0 !important;
    }

    .nk-content {
        margin: 0 !important;
        padding: 0 !important;
    }

    .invoice-page,
    .invoice-layout-scroll,
    .invoice-workspace {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .invoice-workspace {
        display: block !important;
        min-width: 0 !important;
    }

    .invoice-layout-scroll {
        overflow: visible !important;
    }

    body[data-invoice-print="invoice"] .invoice-ticket-panel {
        display: none !important;
    }

    body[data-invoice-print="invoice"] .invoice-document {
        display: block !important;
        width: 100% !important;
        min-width: 0 !important;
        max-width: none !important;
        margin: 0 !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: #fff !important;
        color: #000 !important;
        font-family: Arial, Helvetica, sans-serif !important;
        font-size: 8pt !important;
        line-height: 1.25 !important;
    }

    body[data-invoice-print="invoice"] .invoice-document,
    body[data-invoice-print="invoice"] .invoice-document * {
        color: #000 !important;
    }

    body[data-invoice-print="invoice"] .invoice-document footer,
    body[data-invoice-print="invoice"] .invoice-document thead {
        background: #fff !important;
    }

    body[data-invoice-print="invoice"] .invoice-brand-header {
        padding: 0 0 4mm !important;
        border-bottom: 1pt solid #111 !important;
    }

    body[data-invoice-print="invoice"] .invoice-logo {
        width: 14mm !important;
        height: 14mm !important;
        border: 0 !important;
        border-radius: 0 !important;
    }

    body[data-invoice-print="invoice"] .invoice-brand-name {
        font-size: 12pt !important;
        line-height: 1.1 !important;
    }

    body[data-invoice-print="invoice"] .invoice-title {
        font-size: 16pt !important;
        line-height: 1 !important;
        letter-spacing: 0.08em !important;
    }

    body[data-invoice-print="invoice"] .invoice-number {
        margin-top: 1mm !important;
        font-size: 10pt !important;
    }

    body[data-invoice-print="invoice"] .invoice-date,
    body[data-invoice-print="invoice"] .invoice-status {
        font-size: 6.5pt !important;
    }

    body[data-invoice-print="invoice"] .invoice-meta-grid {
        grid-template-columns: 1.2fr 0.7fr 1fr !important;
        gap: 5mm !important;
        padding: 3mm 0 !important;
    }

    body[data-invoice-print="invoice"] .invoice-services {
        padding: 3.5mm 0 0 !important;
    }

    body[data-invoice-print="invoice"] .invoice-table-wrap {
        overflow: visible !important;
    }

    body[data-invoice-print="invoice"] .invoice-table {
        width: 100% !important;
        min-width: 0 !important;
        table-layout: fixed !important;
    }

    body[data-invoice-print="invoice"] .invoice-col-description {
        width: 52% !important;
    }

    body[data-invoice-print="invoice"] .invoice-col-quantity {
        width: 10% !important;
    }

    body[data-invoice-print="invoice"] .invoice-col-price {
        width: 18% !important;
    }

    body[data-invoice-print="invoice"] .invoice-col-total {
        width: 20% !important;
    }

    body[data-invoice-print="invoice"] .invoice-services table th {
        padding: 1.5mm 2mm !important;
        font-size: 6.5pt !important;
    }

    body[data-invoice-print="invoice"] .invoice-services table td {
        padding: 2mm !important;
        font-size: 7.5pt !important;
    }

    body[data-invoice-print="invoice"] .invoice-services table th:not(:first-child),
    body[data-invoice-print="invoice"] .invoice-services table td:not(:first-child) {
        white-space: nowrap !important;
    }

    body[data-invoice-print="invoice"] .invoice-services table th:first-child,
    body[data-invoice-print="invoice"] .invoice-services table td:first-child {
        overflow-wrap: anywhere !important;
        white-space: normal !important;
    }

    body[data-invoice-print="invoice"] .invoice-summary-grid {
        grid-template-columns: minmax(0, 1fr) 55mm !important;
        gap: 6mm !important;
        margin-top: 3mm !important;
    }

    body[data-invoice-print="invoice"] .invoice-notes {
        font-size: 6.25pt !important;
        line-height: 1.25 !important;
    }

    body[data-invoice-print="invoice"] .invoice-totals {
        font-size: 7.5pt !important;
    }

    body[data-invoice-print="invoice"] .invoice-document-footer {
        margin-top: 3mm !important;
        padding: 2mm 0 0 !important;
        font-size: 5.75pt !important;
        line-height: 1.2 !important;
    }

    body[data-invoice-print="invoice"] .invoice-document tr,
    body[data-invoice-print="invoice"] .invoice-document dl {
        break-inside: avoid;
    }

    body[data-invoice-print] .nk-footer {
        display: none !important;
    }

    body[data-invoice-print="ticket"] {
        width: 80mm !important;
    }

    body[data-invoice-print="ticket"] .invoice-document {
        display: none !important;
    }

    body[data-invoice-print="ticket"] .invoice-page,
    body[data-invoice-print="ticket"] .invoice-layout-scroll,
    body[data-invoice-print="ticket"] .invoice-workspace,
    body[data-invoice-print="ticket"] .invoice-ticket-panel,
    body[data-invoice-print="ticket"] .ticket-preview-frame,
    body[data-invoice-print="ticket"] .invoice-ticket {
        width: 80mm !important;
        max-width: 80mm !important;
        margin: 0 !important;
        box-sizing: border-box !important;
    }

    body[data-invoice-print="ticket"] .invoice-ticket-panel {
        display: block !important;
        position: static !important;
        overflow: visible !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: #fff !important;
    }

    body[data-invoice-print="ticket"] .ticket-preview-frame {
        padding: 0 !important;
        background: #fff !important;
    }

    body[data-invoice-print="ticket"] .invoice-ticket {
        padding: 4mm !important;
        border: 0 !important;
        box-shadow: none !important;
        background: #fff !important;
        color: #000 !important;
    }

    body[data-invoice-print="ticket"] .invoice-ticket,
    body[data-invoice-print="ticket"] .invoice-ticket * {
        color: #000 !important;
    }

    body[data-invoice-print="ticket"] .invoice-ticket .font-mono {
        font-family: "Courier New", monospace !important;
    }

    body[data-invoice-print="ticket"] .ticket-line,
    body[data-invoice-print="ticket"] .invoice-ticket footer,
    body[data-invoice-print="ticket"] .invoice-qr {
        break-inside: avoid;
    }

    body[data-invoice-print="ticket"] .invoice-qr {
        width: 22mm !important;
        height: 22mm !important;
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
}
</style>
