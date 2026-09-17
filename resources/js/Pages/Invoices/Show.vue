<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import {
    ArrowLeft,
    Banknote,
    Building2,
    Check,
    ChevronRight,
    CircleAlert,
    Clock,
    CreditCard,
    FileText,
    Lock,
    Printer,
    ShieldCheck,
    Smartphone,
    UserRound,
    UserRoundPlus,
    Wallet,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import FormError from '@/Components/UI/FormError.vue';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { formatPatientName } from '@/utilities/patient';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const props = defineProps({
    invoice: Object,
    returnToCash: Boolean,
    returnToPharmacy: Boolean,
    ticketOnly: Boolean,
    autoPrint: Boolean,
    closeAfterPrint: Boolean,
    externalPrescriber: String,
    capabilities: { type: Object, default: () => ({}) },
    paymentMethods: { type: Array, default: () => [] },
    openCashSessions: { type: Array, default: () => [] },
});
const page = usePage();
const brandName = computed(() => page.props.site?.brand ?? 'Clinique Saint Georges');
const siteName = computed(() => page.props.site?.name ?? brandName.value);
const legalDetails = computed(() => page.props.site?.documents ?? {});
const publicUrl = computed(() => page.props.site?.publicUrl ?? 'https://cliniquesaintgeorges.mg');
const publicSiteLabel = computed(() => publicUrl.value.replace(/^https?:\/\//, '').replace(/\/$/, ''));
const hasDiscount = computed(() => Number(props.invoice.discount_amount ?? 0) > 0);
const hasCoverage = computed(() => Number(props.invoice.coverage_amount ?? 0) > 0);
const isStaffInvoice = computed(() => props.invoice.financial_mode === 'STAFF');
const hasStaffBlockCredit = computed(() => Number(props.invoice.staff_block_credit_used ?? 0) > 0);
const coverageLabel = computed(() => isStaffInvoice.value
    ? 'Prise en charge Personnel'
    : `Pris en charge · ${props.invoice.mutual_organization_name}`);
const customerName = computed(() => props.invoice.patient
    ? formatPatientName(props.invoice.patient)
    : (props.invoice.customer_name || 'Client comptoir'));
const customerReference = computed(() => props.invoice.patient?.patient_number
    ? `Patient ${props.invoice.patient.patient_number}`
    : (props.invoice.source_module === 'PHARMACY' ? 'Vente directe Pharmacie' : 'Client externe'));
const returnHref = computed(() => {
    if (props.returnToPharmacy) return '/pharmacy/dispenses';
    return props.returnToCash || !props.invoice.patient ? '/cash' : `/patients/${props.invoice.patient.uuid}`;
});
const returnLabel = computed(() => {
    if (props.returnToPharmacy) return 'Retour aux ordonnances';
    return props.returnToCash || !props.invoice.patient ? 'Retour à la caisse' : 'Retour au patient';
});
const isPharmacyInvoice = computed(() => props.invoice.source_module === 'PHARMACY');
const isPayable = computed(() => ['VALIDATED', 'PARTIALLY_PAID'].includes(props.invoice.status)
    && Number(props.invoice.balance_amount) > 0);

// Arriving on a payable invoice is a single decision — pay now (cash
// register required) or pay later (print an unpaid document) — never both
// paths shown at once. `null` means the decision hasn't been made yet; the
// document buttons stay hidden until either branch resolves, so "choose
// facture/ticket" is always a *consequence* of a choice, never a competing
// option sitting next to it.
const paymentChoice = ref(null);
const canPayNow = computed(() => props.capabilities.can_pay && props.openCashSessions.length > 0);
// The gated pay-now/pay-later choice only exists for the normal invoice
// screen — a Pharmacy ticketOnly view never renders that decision panel at
// all, so it must never be the thing blocking its own print button.
const documentsUnlocked = computed(() => props.ticketOnly || !isPayable.value || paymentChoice.value === 'later');
const justSettled = computed(() => paymentChoice.value === 'now' && !isPayable.value);
const chooseNow = () => { if (canPayNow.value) paymentChoice.value = 'now'; };
const chooseLater = () => { paymentChoice.value = 'later'; };
const changeChoice = () => { paymentChoice.value = null; };

const paymentForm = useForm({
    invoice_uuid: props.invoice.uuid,
    payment_method_id: '',
    amount: props.invoice.balance_amount,
    reference: '',
    notes: '',
    cash_register_uuid: props.openCashSessions.length === 1 ? props.openCashSessions[0].register_uuid ?? '' : '',
});
const selectedMethod = computed(() => props.paymentMethods
    .find((method) => method.id === paymentForm.payment_method_id) ?? null);
// Grouped by category: "mobile money" is never one tender in Madagascar —
// MVola, Orange Money and Airtel Money are reconciled separately, so the
// cashier picks the operator under a shared heading.
// A named desk may accept only part of the site's tenders (an empty list
// means no restriction). The refusal itself lives in RecordPaymentAction —
// this only spares the cashier a rejected attempt.
const activeSession = computed(() => props.openCashSessions.find(
    (session) => session.register_uuid === paymentForm.cash_register_uuid,
) ?? (props.openCashSessions.length === 1 ? props.openCashSessions[0] : null));
const acceptedMethods = computed(() => {
    const accepted = activeSession.value?.accepted_payment_method_ids ?? [];

    return accepted.length === 0
        ? props.paymentMethods
        : props.paymentMethods.filter((method) => accepted.includes(method.id));
});
const methodGroups = computed(() => {
    const groups = new Map();

    acceptedMethods.value.forEach((method) => {
        const key = method.category ?? 'OTHER';
        const group = groups.get(key) ?? {
            key,
            label: method.category_label ?? 'Autre',
            icon: method.category_icon ?? 'card-view',
            position: method.category_position ?? 99,
            methods: [],
        };
        group.methods.push(method);
        groups.set(key, group);
    });

    return Array.from(groups.values()).sort((left, right) => left.position - right.position);
});
const referenceLabel = computed(() => ({
    CHECK: 'N° du chèque',
    BANK_TRANSFER: 'Référence du virement',
    MOBILE_MONEY_ORANGE: 'N° de transaction Orange Money',
    MOBILE_MONEY_MVOLA: 'N° de transaction MVola',
    MOBILE_MONEY_AIRTEL: 'N° de transaction Airtel Money',
}[selectedMethod.value?.code] ?? 'Référence'));
const fillExactAmount = () => { paymentForm.amount = props.invoice.balance_amount; };

// Le champ de référence n'apparaît que pour les modes qui en exigent une, et
// il vit dans la colonne de droite alors que le mode se choisit à gauche :
// il est facile de ne pas le voir apparaître. Le bouton reflète donc ce
// manque, au lieu de laisser une bulle native du navigateur être le seul
// signal — et `RecordPaymentAction` refuse de toute façon côté serveur.
const referenceMissing = computed(() => Boolean(selectedMethod.value?.requires_reference)
    && String(paymentForm.reference ?? '').trim() === '');
/* ------------------------------------------------------------------ *
 * Argent remis / à rendre — une aide au comptoir, jamais une donnée.
 *
 * Aucun de ces montants n'est envoyé au serveur : le paiement enregistré
 * reste le montant réellement encaissé (ADR-012). Ce que le patient tend
 * et ce qu'on lui rend sont deux gestes physiques du guichet, pas des
 * écritures — les consigner laisserait croire à un mouvement de caisse
 * supplémentaire qui n'existe pas.
 *
 * Même contrat que l'espace Caisse, afin qu'un même geste se fasse de la
 * même façon aux deux endroits.
 * ------------------------------------------------------------------ */
const tenderedAmount = ref('');
const ARIARY_NOTES = [500, 1000, 2000, 5000, 10000, 20000];
// Uniquement les tenders qui font circuler des espèces : on ne rend jamais
// la monnaie sur un Mobile Money ou un virement.
const isCashTender = computed(() => Boolean(selectedMethod.value?.affects_cash_balance));
const amountDue = computed(() => Number(paymentForm.amount) || 0);
const tenderedValue = computed(() => Number(tenderedAmount.value) || 0);
const changeDue = computed(() => Math.max(0, tenderedValue.value - amountDue.value));
// Borné aux espèces : `selectMethod` remet déjà le comptage à zéro en
// changeant de mode, mais un bouton désactivé sans bloc visible à
// l'écran serait indéchiffrable pour le caissier.
const tenderedIsShort = computed(() => isCashTender.value
    && tenderedAmount.value !== ''
    && tenderedValue.value < amountDue.value);
const addTendered = (note) => { tenderedAmount.value = String(tenderedValue.value + note); };
const setExactTender = () => { tenderedAmount.value = amountDue.value ? String(amountDue.value) : ''; };
const resetTender = () => { tenderedAmount.value = ''; };
// Changer de mode remet le comptage à zéro : un montant tendu en espèces
// n'a aucun sens une fois passé sur un virement.
const selectMethod = (id) => { paymentForm.payment_method_id = id; resetTender(); };

const submitPayment = () => paymentForm.post(`/invoices/${props.invoice.uuid}/payments`, {
    preserveScroll: true,
    onSuccess: () => {
        paymentForm.reset('reference', 'notes', 'payment_method_id');
        paymentForm.amount = props.invoice.balance_amount;
        resetTender();
    },
});
const qrCodeDataUrl = ref('');
const ticketRef = ref(null);
const printPageStyleId = 'invoice-print-page-size';
const millimetersPerCssPixel = 25.4 / 96;

const invoiceStatusLabel = computed(() => ({
    DRAFT: 'Brouillon',
    VALIDATED: 'À payer',
    PARTIALLY_PAID: 'Paiement partiel',
    PAID: 'Acquittée',
    COVERED: 'Prise en charge',
    CANCELLED: 'Annulée',
})[props.invoice.status] ?? props.invoice.status);

// PaymentMethodCategory::icon() renvoie des noms nioicon, encore consommés
// tels quels par l'écran Caisse. La correspondance vit donc ici, le temps
// que cet écran migre lui aussi.
const CATEGORY_ICONS = {
    coins: Banknote,
    mobile: Smartphone,
    building: Building2,
    'shield-check': ShieldCheck,
    'card-view': CreditCard,
};
const categoryIcon = (name) => CATEGORY_ICONS[name] ?? CreditCard;

const cashRegisterOptions = computed(() => props.openCashSessions.map((session) => ({
    value: session.register_uuid ?? '',
    label: session.register_name ?? session.session_number,
})));

const invoiceStatusBadgeClass = computed(() => ({
    DRAFT: 'border-gray-300 bg-gray-100 text-slate-600 dark:border-gray-700 dark:bg-gray-800 dark:text-slate-300',
    VALIDATED: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
    PARTIALLY_PAID: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-800 dark:bg-sky-900/30 dark:text-sky-300',
    PAID: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
    COVERED: 'border-teal-200 bg-teal-50 text-teal-700 dark:border-teal-800 dark:bg-teal-900/30 dark:text-teal-300',
    CANCELLED: 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300',
}[props.invoice.status] ?? 'border-gray-300 bg-gray-100 text-slate-600'));

const generateQrCode = async () => {
    try {
        qrCodeDataUrl.value = await QRCode.toDataURL(props.invoice.invoice_number, {
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

const handleAfterPrint = () => {
    clearPrint();
    if (props.closeAfterPrint) window.close();
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

onMounted(async () => {
    window.addEventListener('beforeprint', handleBeforePrint);
    window.addEventListener('afterprint', handleAfterPrint);
    await generateQrCode();
    if (props.autoPrint) await printDocument('ticket');
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeprint', handleBeforePrint);
    window.removeEventListener('afterprint', handleAfterPrint);
    clearPrint();
});
</script>

<template>
    <Head :title="`${ticketOnly ? 'Ticket Pharmacie' : 'Facture'} ${invoice.invoice_number}`" />

    <div class="invoice-page w-full space-y-4 rounded-2xl bg-gradient-to-b from-gray-100/70 to-transparent p-3 dark:from-gray-900/30 sm:p-5">

        <div v-if="!closeAfterPrint" class="invoice-actions sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card/90 px-4 py-3 shadow-sm backdrop-blur-sm">
            <div class="flex min-w-0 items-center gap-3">
                <Button :as="Link" :href="returnHref" variant="outline">
                    <ArrowLeft class="h-4 w-4" />
                    <span class="hidden sm:inline">{{ returnLabel }}</span>
                </Button>
                <div class="min-w-0 border-s border-border ps-3">
                    <p class="flex flex-wrap items-center gap-2 text-sm font-bold text-foreground">
                        <span>{{ ticketOnly ? 'Ticket' : 'Facture' }}</span>
                        <span class="font-mono">{{ invoice.invoice_number }}</span>
                        <!-- Classe partagée avec le document imprimé (§ .invoice-status) :
                             ses couleurs de statut restent celles du papier. -->
                        <span :class="['rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide', invoiceStatusBadgeClass]">{{ invoiceStatusLabel }}</span>
                    </p>
                    <p class="truncate text-xs text-muted-foreground">{{ customerName }}<template v-if="isPayable"> · reste à payer <strong class="text-foreground">{{ formatMoney(invoice.balance_amount) }}</strong></template></p>
                </div>
            </div>
            <div v-if="documentsUnlocked" class="flex flex-wrap items-center justify-end gap-2">
                <Button v-if="!ticketOnly" title="Imprimer la facture B5 ou choisir Enregistrer au format PDF" variant="outline" type="button" @click="printDocument('invoice')">
                    <FileText class="h-4 w-4" />Facture B5 / PDF
                </Button>
                <Button type="button" @click="printDocument('ticket')">
                    <Printer class="h-4 w-4" />Imprimer le ticket
                </Button>
            </div>
            <Badge v-else variant="warning"><Lock class="h-3.5 w-3.5" />Choisissez un mode de règlement pour débloquer l’impression</Badge>
        </div>

        <Card v-if="!closeAfterPrint && !ticketOnly && isPayable" class="invoice-actions overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3.5">
                <div class="flex items-center gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary"><Wallet class="h-5 w-5" /></span>
                    <div>
                        <h2 class="text-base font-bold text-foreground">Règlement</h2>
                        <p class="text-xs text-muted-foreground">{{ paymentChoice === null ? 'Comment ce passage est-il réglé ?' : 'Le document à remettre se choisit juste après.' }}</p>
                    </div>
                </div>
                <div class="text-end">
                    <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Reste à payer</p>
                    <p class="font-heading text-2xl font-bold text-foreground">{{ formatMoney(invoice.balance_amount) }}</p>
                </div>
            </div>

            <!-- Étape 1 : la décision elle-même — maintenant ou plus tard, jamais les deux à la fois. -->
            <div v-if="paymentChoice === null" class="grid gap-3 p-4 sm:grid-cols-2">
                <button
                    type="button"
                    :disabled="!canPayNow"
                    :class="[
                        'flex items-start gap-3 rounded-xl border p-4 text-start transition',
                        canPayNow ? 'border-border hover:border-primary/40 hover:bg-primary/5' : 'cursor-not-allowed border-border opacity-50',
                    ]"
                    @click="chooseNow"
                >
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-primary text-primary-foreground"><Wallet class="h-5 w-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center justify-between gap-2">
                            <span class="block text-base font-bold text-foreground">Payer maintenant</span>
                            <ChevronRight class="h-4 w-4 shrink-0 text-muted-foreground" />
                        </span>
                        <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">Encaisser à la caisse, choisir le mode de paiement, puis remettre le document acquitté.</span>
                        <span v-if="!capabilities.can_pay" class="mt-2 block text-xs font-semibold text-amber-700 dark:text-amber-300">Votre compte ne dispose pas du droit d’encaissement.</span>
                        <span v-else-if="openCashSessions.length === 0" class="mt-2 block text-xs font-semibold text-amber-700 dark:text-amber-300">Ouvrez une caisse pour activer cette option.</span>
                        <Badge v-else class="mt-2" variant="secondary">Encaisser {{ formatMoney(invoice.balance_amount) }}</Badge>
                    </span>
                </button>
                <button
                    type="button"
                    class="flex items-start gap-3 rounded-xl border border-border p-4 text-start transition hover:border-primary/40 hover:bg-primary/5"
                    @click="chooseLater"
                >
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground"><Clock class="h-5 w-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center justify-between gap-2">
                            <span class="block text-base font-bold text-foreground">Payer plus tard</span>
                            <ChevronRight class="h-4 w-4 shrink-0 text-muted-foreground" />
                        </span>
                        <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">Remettre une facture ou un ticket impayé ; le règlement se fera ultérieurement à la caisse.</span>
                        <Badge class="mt-2" variant="outline">Aucun encaissement · aucun reçu</Badge>
                    </span>
                </button>
            </div>

            <!-- Étape 2a : l'encaissement réel. Deux colonnes — les modes à
                 gauche, le montant et l'action à droite : la moitié droite
                 restait vide, et le bouton se perdait sous une grille de
                 quatre colonnes dont trois étaient souvent absentes. -->
            <div v-else-if="paymentChoice === 'now'">
                <div class="flex items-center justify-between gap-3 border-b border-border bg-muted/25 px-4 py-2.5">
                    <Badge variant="secondary"><Wallet class="h-3.5 w-3.5" />Payer maintenant</Badge>
                    <button type="button" class="text-xs font-semibold text-muted-foreground transition-colors hover:text-primary" @click="changeChoice">‹ Changer de choix</button>
                </div>
                <form class="grid gap-5 p-4 lg:grid-cols-[minmax(0,1fr)_380px] xl:grid-cols-[minmax(0,1fr)_420px]" @submit.prevent="submitPayment">
                    <section class="min-w-0">
                        <p class="mb-3 text-xs font-bold uppercase tracking-[0.12em] text-muted-foreground">Mode de paiement <span class="text-red-500">*</span></p>
                        <div class="space-y-4">
                            <div v-for="group in methodGroups" :key="group.key">
                                <!-- Libellé + filet : une catégorie à une seule
                                     carte ne ressemble plus à une ligne ratée,
                                     le filet tient la largeur à sa place. -->
                                <div class="mb-2 flex items-center gap-2">
                                    <component :is="categoryIcon(group.icon)" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                    <span class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">{{ group.label }}</span>
                                    <span class="h-px flex-1 bg-border" aria-hidden="true" />
                                </div>
                                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                    <button
                                        v-for="method in group.methods"
                                        :key="method.id"
                                        type="button"
                                        :aria-pressed="paymentForm.payment_method_id === method.id"
                                        :class="[
                                            'flex h-14 w-full items-center gap-2.5 rounded-lg border px-3 text-start transition',
                                            paymentForm.payment_method_id === method.id
                                                ? 'border-primary bg-primary/5 ring-1 ring-ring/25'
                                                : 'border-border hover:border-primary/40 hover:bg-accent',
                                        ]"
                                        @click="selectMethod(method.id)"
                                    >
                                        <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-full transition-colors', paymentForm.payment_method_id === method.id ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground']"><component :is="categoryIcon(group.icon)" class="h-4 w-4" /></span>
                                        <span class="min-w-0 flex-1 truncate text-sm font-semibold text-foreground">{{ method.name }}</span>
                                        <Check v-if="paymentForm.payment_method_id === method.id" class="h-4 w-4 shrink-0 text-primary" />
                                    </button>
                                </div>
                            </div>
                        </div>
                        <FormError :message="paymentForm.errors.payment_method_id" />
                    </section>

                    <aside class="space-y-3 self-start rounded-xl border border-border bg-muted/25 p-4">
                        <div v-if="openCashSessions.length > 1">
                            <label class="mb-1.5 block text-xs font-medium text-foreground" for="payment-cash-register">Caisse <span class="text-red-500">*</span></label>
                            <Select
                                id="payment-cash-register"
                                v-model="paymentForm.cash_register_uuid"
                                class="w-full"
                                :options="cashRegisterOptions"
                                placeholder="Choisir…"
                            />
                            <FormError :message="paymentForm.errors.cash_register_uuid" />
                        </div>

                        <div>
                            <div class="mb-1.5 flex items-center justify-between gap-2">
                                <label class="block text-xs font-medium text-foreground" for="payment-amount">Montant <span class="text-red-500">*</span></label>
                                <button v-if="Number(paymentForm.amount) !== Number(invoice.balance_amount)" type="button" class="text-[11px] font-bold text-primary hover:underline" @click="fillExactAmount">Solde exact</button>
                            </div>
                            <div class="relative">
                                <Input id="payment-amount" v-model="paymentForm.amount" class="h-12 pe-10 text-end font-heading text-xl font-bold" type="number" min="0.01" :max="invoice.balance_amount" step="0.01" />
                                <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-sm font-bold text-muted-foreground">Ar</span>
                            </div>
                            <FormError :message="paymentForm.errors.amount" />
                        </div>

                        <div v-if="selectedMethod?.requires_reference" :class="['rounded-lg border p-3 transition-colors', referenceMissing ? 'border-amber-300 bg-amber-50/60 dark:border-amber-900 dark:bg-amber-950/20' : 'border-border bg-card']">
                            <label class="mb-1.5 block text-xs font-medium text-foreground" for="payment-reference">{{ referenceLabel }} <span class="text-red-500">*</span></label>
                            <Input id="payment-reference" v-model="paymentForm.reference" placeholder="N° de transaction" required />
                            <p v-if="referenceMissing" class="mt-1.5 flex items-center gap-1.5 text-[11px] font-semibold text-amber-700 dark:text-amber-300">
                                <CircleAlert class="h-3.5 w-3.5 shrink-0" />{{ selectedMethod.name }} exige sa référence pour être rapproché.
                            </p>
                            <FormError :message="paymentForm.errors.reference" />
                        </div>
                        <p v-else class="text-xs leading-5 text-muted-foreground">Aucune référence à saisir : le numéro de paiement généré en fait office.<FormError :message="paymentForm.errors.reference" /></p>

                        <!-- Espèces seulement : on ne rend rien sur un Mobile
                             Money ni un virement. Ces deux montants ne sont
                             jamais enregistrés (voir le script). -->
                        <div v-if="isCashTender" class="rounded-lg border border-border bg-card p-3">
                            <div class="mb-1.5 flex items-center justify-between gap-2">
                                <label class="text-xs font-medium text-foreground" for="payment-tendered">Argent reçu du patient</label>
                                <span class="flex items-center gap-2 text-[11px] font-bold">
                                    <button type="button" class="text-primary hover:underline" @click="setExactTender">Compte juste</button>
                                    <button v-if="tenderedAmount !== ''" type="button" class="text-muted-foreground hover:text-foreground" @click="resetTender">Effacer</button>
                                </span>
                            </div>
                            <div class="relative">
                                <Input id="payment-tendered" v-model="tenderedAmount" class="h-11 pe-10 text-end text-lg font-bold" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0" />
                                <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-sm font-bold text-muted-foreground">Ar</span>
                            </div>
                            <div class="mt-2 grid grid-cols-3 gap-1.5">
                                <button v-for="note in ARIARY_NOTES" :key="note" type="button" class="rounded-md border border-border py-1.5 text-xs font-bold tabular-nums text-muted-foreground transition-colors hover:border-primary/40 hover:bg-primary/5 hover:text-primary" @click="addTendered(note)">+{{ note.toLocaleString('fr-FR') }}</button>
                            </div>

                            <p v-if="tenderedIsShort" class="mt-3 flex items-center gap-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
                                <CircleAlert class="h-4 w-4 shrink-0" />Il manque {{ formatMoney(amountDue - tenderedValue) }}
                            </p>
                            <div v-else-if="tenderedAmount !== ''" :class="['mt-3 flex items-center justify-between gap-3 rounded px-3 py-2.5', changeDue > 0 ? 'bg-emerald-50 dark:bg-emerald-950/25' : 'bg-muted']">
                                <span :class="['text-[10px] font-bold uppercase tracking-[0.14em]', changeDue > 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-muted-foreground']">À rendre au patient</span>
                                <span :class="['font-heading text-2xl font-bold tabular-nums', changeDue > 0 ? 'text-emerald-800 dark:text-emerald-200' : 'text-muted-foreground']">{{ formatMoney(changeDue) }}</span>
                            </div>
                        </div>

                        <Button
                            class="w-full"
                            size="lg"
                            type="submit"
                            :disabled="paymentForm.processing || !paymentForm.payment_method_id || !paymentForm.amount || tenderedIsShort || referenceMissing"
                            :title="referenceMissing ? `Saisissez ${referenceLabel} pour encaisser` : (tenderedIsShort ? 'L’argent reçu est inférieur au montant à encaisser' : undefined)"
                        >
                            <Check class="h-4 w-4" />{{ paymentForm.processing ? 'Encaissement…' : `Encaisser ${formatMoney(paymentForm.amount || 0)}` }}
                        </Button>
                        <FormError :message="paymentForm.errors.invoice_uuid" />
                        <FormError v-if="openCashSessions.length <= 1" :message="paymentForm.errors.cash_register_uuid" />
                    </aside>
                </form>
            </div>

            <!-- Étape 2b : « plus tard » — confirme le choix et débloque l'impression ci-dessus. -->
            <div v-else class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-start gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground"><Clock class="h-4 w-4" /></span>
                    <div><p class="text-sm font-bold text-foreground">Réglée plus tard</p><p class="text-xs text-muted-foreground">La facture reste impayée jusqu’à l’encaissement à la caisse. Choisissez le document à remettre ci-dessous.</p></div>
                </div>
                <button type="button" class="shrink-0 text-xs font-semibold text-muted-foreground transition-colors hover:text-primary" @click="changeChoice">‹ Changer de choix</button>
            </div>

            <div v-if="invoice.payments?.length" class="border-t border-border px-4 py-3">
                <p class="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Paiements enregistrés</p>
                <ul class="space-y-1 text-xs">
                    <li v-for="payment in invoice.payments" :key="payment.uuid" class="flex items-center justify-between gap-3 text-muted-foreground">
                        <span>{{ formatDateTime(payment.paid_at) }} · {{ payment.method?.name }}<template v-if="payment.reference"> · {{ payment.reference }}</template></span>
                        <span class="font-semibold text-foreground">{{ formatMoney(payment.amount) }}</span>
                    </li>
                </ul>
            </div>
        </Card>

        <!-- Document remis au patient — toujours une conséquence de la
             décision de règlement ci-dessus, jamais affiché avant. -->
        <Card v-if="!closeAfterPrint && !ticketOnly && documentsUnlocked" class="invoice-actions overflow-hidden">
            <div class="flex items-center gap-3 border-b border-border px-4 py-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground"><Printer class="h-4 w-4" /></span>
                <div>
                    <h2 class="text-sm font-bold text-foreground">Document à remettre</h2>
                    <p class="text-xs text-muted-foreground">{{ justSettled ? 'Encaissement enregistré. Choisissez le document acquitté à remettre.' : 'Choisissez le format imprimé remis au patient.' }}</p>
                </div>
            </div>
            <div class="grid gap-3 p-4 sm:grid-cols-2">
                <button type="button" class="flex items-start gap-3 rounded-xl border border-border p-4 text-start transition hover:border-primary/40 hover:bg-primary/5" @click="printDocument('invoice')">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground"><FileText class="h-5 w-5" /></span>
                    <span>
                        <span class="block text-sm font-bold text-foreground">Facture</span>
                        <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">Format B5 ou enregistrement PDF. Document détaillé complet.</span>
                    </span>
                </button>
                <button type="button" class="flex items-start gap-3 rounded-xl border border-border p-4 text-start transition hover:border-primary/40 hover:bg-primary/5" @click="printDocument('ticket')">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary"><Printer class="h-5 w-5" /></span>
                    <span>
                        <span class="block text-sm font-bold text-foreground">Ticket</span>
                        <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">Imprimante thermique 80 mm. Remise rapide au guichet.</span>
                    </span>
                </button>
            </div>

            <!-- Le document remis, le guichet enchaîne. Une rangée à part,
                 en retrait : imprimer et quitter la page ne sont pas deux
                 choix du même ordre, et les mettre côte à côte dans la
                 grille ferait cliquer « Nouveau patient » à la place de
                 « Ticket ». La Card qui les porte est déjà exclue du papier
                 (`invoice-actions`). -->
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-border bg-muted/25 px-4 py-3">
                <span class="text-xs font-semibold text-muted-foreground">Et ensuite :</span>
                <Button v-if="can('episodes.create')" :as="Link" href="/reception/patients" size="sm" variant="outline">
                    <UserRoundPlus class="h-4 w-4" />Nouveau patient
                </Button>
                <Button v-if="can('cash.view')" :as="Link" href="/cash" size="sm" variant="outline">
                    <Wallet class="h-4 w-4" />Caisse
                </Button>
                <Button v-if="invoice.patient" :as="Link" :href="`/patients/${invoice.patient.uuid}`" size="sm" variant="ghost">
                    <UserRound class="h-4 w-4" />Dossier du patient
                </Button>
            </div>
        </Card>

        <!-- Les aperçus suivent le même verrou que l'impression : tant que le
             mode de règlement n'est pas choisi, il n'y a pas de document à
             remettre, donc rien à prévisualiser. `invoice-actions` le retire
             aussi d'une impression déclenchée au clavier. -->
        <div v-if="!documentsUnlocked" class="invoice-actions rounded-xl border border-dashed border-border bg-muted/25 px-6 py-10 text-center">
            <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-muted text-muted-foreground"><FileText class="h-5 w-5" /></span>
            <p class="mt-3 text-sm font-bold text-foreground">Le document s’affichera après votre choix</p>
            <p class="mx-auto mt-1 max-w-md text-xs leading-5 text-muted-foreground">
                Choisissez « Payer maintenant » ou « Payer plus tard » ci-dessus : la facture et le ticket apparaîtront alors, prêts à imprimer.
            </p>
        </div>

        <div v-if="documentsUnlocked" class="invoice-layout-scroll">
            <div :class="['invoice-workspace', ticketOnly ? 'invoice-workspace-ticket-only' : '']">
                <article v-if="!ticketOnly" class="invoice-document overflow-hidden rounded-xl border border-gray-200 bg-white shadow-md ring-1 ring-black/[0.03] dark:border-gray-900 dark:bg-gray-950">
                <header class="invoice-brand-header border-b border-gray-200 bg-gradient-to-br from-primary-50/70 via-white to-white px-5 py-5 dark:border-gray-900 dark:from-gray-900/40 dark:via-gray-950 dark:to-gray-950 sm:px-6">
                    <div class="flex items-start justify-between gap-5">
                        <div class="flex min-w-0 items-start gap-3">
                            <div class="invoice-logo flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-800">
                                <img v-if="legalDetails.logo_url" :src="legalDetails.logo_url" :alt="`Logo ${brandName}`" class="h-full w-full object-contain p-1" />
                                <span v-else class="font-heading text-base font-black tracking-tight text-primary-600">CSG</span>
                            </div>
                            <div class="min-w-0">
                                <p class="invoice-brand-name font-heading text-base font-bold text-slate-800 dark:text-white">{{ brandName }}</p>
                                <p v-if="siteName !== brandName" class="text-[10px] font-bold uppercase tracking-[0.14em] text-primary-600">Site {{ siteName }}</p>
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
                            <p class="invoice-number font-mono text-sm font-bold text-primary-600">{{ invoice.invoice_number }}</p>
                            <div class="mt-1.5 flex items-center justify-end gap-2">
                                <p class="invoice-date text-[10px] text-slate-500">{{ formatDateTime(invoice.validated_at ?? invoice.created_at) }}</p>
                                <span :class="['invoice-status inline-flex rounded-full border px-2.5 py-0.5 text-[9px] font-bold uppercase tracking-wide', invoiceStatusBadgeClass]">{{ invoiceStatusLabel }}</span>
                            </div>
                        </div>
                    </div>
                </header>

                <section class="invoice-meta-grid border-b border-gray-200 px-5 py-3 dark:border-gray-900 sm:px-6">
                    <div>
                        <p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Facturé à</p>
                        <p class="mt-1 font-heading text-sm font-bold text-slate-800 dark:text-white">{{ customerName }}</p>
                        <p v-if="invoice.customer_phone" class="font-mono text-[11px] text-slate-500">{{ invoice.customer_phone }}</p>
                        <p v-if="externalPrescriber" class="font-mono text-[11px] text-slate-500">Prescripteur : {{ externalPrescriber }}</p>
                        <p class="font-mono text-[11px] text-slate-500">{{ customerReference }}</p>
                        <p v-if="isStaffInvoice" class="mt-1 text-[10px] font-semibold text-emerald-700">Régime Personnel<span v-if="hasStaffBlockCredit"> · crédit Bloc utilisé {{ formatMoney(invoice.staff_block_credit_used) }}</span></p>
                        <p v-else-if="hasCoverage" class="mt-1 text-[10px] font-semibold text-emerald-700">{{ invoice.mutual_organization_name }} · couverture {{ Number(invoice.coverage_rate).toLocaleString('fr-FR', { maximumFractionDigits: 2 }) }} %</p>
                    </div>
                    <div>
                        <p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Passage</p>
                        <p class="mt-1 font-mono text-xs font-bold text-slate-700 dark:text-slate-200">{{ invoice.episode?.episode_number ?? 'Sans passage patient' }}</p>
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
                        <table class="invoice-table w-full min-w-[700px] text-xs">
                            <colgroup>
                                <col class="invoice-col-description" />
                                <col class="invoice-col-quantity" />
                                <col class="invoice-col-price" />
                                <col class="invoice-col-total" />
                                <col v-if="hasCoverage" class="invoice-col-total" />
                            </colgroup>
                            <thead class="border-y border-gray-200 text-[9px] uppercase tracking-wide text-slate-400 dark:border-gray-800">
                                <tr>
                                    <th class="px-2.5 py-2 text-start font-bold">Désignation</th>
                                    <th class="px-2.5 py-2 text-end font-bold">Qté</th>
                                    <th class="px-2.5 py-2 text-end font-bold">Tarif</th>
                                    <th class="px-2.5 py-2 text-end font-bold">Total brut</th>
                                    <th v-if="hasCoverage" class="px-2.5 py-2 text-end font-bold">Part patient</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                                <tr v-for="line in invoice.lines" :key="line.id" class="transition-colors even:bg-gray-50/60 hover:bg-primary-50/40 dark:even:bg-gray-900/30 dark:hover:bg-primary-900/10">
                                    <td class="px-2.5 py-2.5 font-medium text-slate-700 dark:text-slate-200">{{ line.description }} <span v-if="line.billable_item?.source_module" class="ms-1 text-[9px] font-normal uppercase tracking-wide text-slate-400">{{ line.billable_item.source_module }}</span></td>
                                    <td class="px-2.5 py-2.5 text-end text-slate-500">{{ line.quantity }}</td>
                                    <td class="px-2.5 py-2.5 text-end text-slate-500">{{ formatMoney(line.unit_price) }}</td>
                                    <td class="px-2.5 py-2.5 text-end font-bold text-slate-700 dark:text-white">{{ formatMoney(line.gross_line_total ?? line.line_total) }}</td>
                                    <td v-if="hasCoverage" class="px-2.5 py-2.5 text-end font-bold text-slate-700 dark:text-white">{{ formatMoney(line.line_total) }}</td>
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
                            <div class="flex items-center justify-between gap-4"><dt class="text-slate-400">Total brut</dt><dd class="font-medium text-slate-700 dark:text-white">{{ formatMoney(invoice.subtotal_amount) }}</dd></div>
                            <div v-if="hasDiscount" class="flex items-center justify-between gap-4"><dt class="text-slate-400">Remise</dt><dd class="font-medium text-slate-700 dark:text-white">− {{ formatMoney(invoice.discount_amount) }}</dd></div>
                            <div v-if="hasCoverage" class="flex items-center justify-between gap-4"><dt class="text-slate-400">{{ coverageLabel }}</dt><dd class="font-medium text-emerald-700">− {{ formatMoney(invoice.coverage_amount) }}</dd></div>
                            <div v-if="hasStaffBlockCredit" class="flex items-center justify-between gap-4 text-[10px]"><dt class="text-slate-400">dont crédit forfaitaire Bloc</dt><dd class="font-medium text-slate-500">{{ formatMoney(invoice.staff_block_credit_used) }}</dd></div>
                            <div class="flex items-center justify-between gap-4 border-t border-gray-200 pt-1.5 dark:border-gray-800"><dt class="font-bold text-slate-600 dark:text-slate-300">À charge patient</dt><dd class="text-sm font-bold text-slate-800 dark:text-white">{{ formatMoney(invoice.total_amount) }}</dd></div>
                            <div class="flex items-center justify-between gap-4"><dt class="text-slate-400">Payé</dt><dd class="font-medium text-slate-700 dark:text-white">{{ formatMoney(invoice.paid_amount) }}</dd></div>
                            <div :class="['invoice-balance-row flex items-center justify-between gap-4 rounded-md border-t border-slate-700 px-2.5 pt-1.5', invoice.balance_amount > 0 ? 'bg-rose-50 dark:bg-rose-900/20' : 'bg-emerald-50 dark:bg-emerald-900/20']"><dt :class="['font-bold', invoice.balance_amount > 0 ? 'text-rose-700 dark:text-rose-300' : 'text-emerald-700 dark:text-emerald-300']">Reste à payer</dt><dd :class="['text-base font-black', invoice.balance_amount > 0 ? 'text-rose-700 dark:text-rose-300' : 'text-emerald-700 dark:text-emerald-300']">{{ formatMoney(invoice.balance_amount) }}</dd></div>
                        </dl>
                    </div>
                </section>

                <footer class="invoice-document-footer flex items-center justify-between gap-3 border-t border-gray-200 px-5 py-2 text-[9px] leading-3 text-slate-400 dark:border-gray-900 sm:px-6">
                    <span>{{ brandName }} — {{ siteName }}</span>
                    <span>Merci de votre confiance · <a :href="publicUrl" class="font-semibold text-slate-500 hover:text-primary-600 hover:underline">{{ publicSiteLabel }}</a></span>
                </footer>
            </article>

                <aside class="invoice-ticket-panel overflow-hidden rounded-xl border border-gray-200 bg-white shadow-md dark:border-gray-900 dark:bg-gray-950">
                <div class="ticket-panel-header flex items-center justify-between gap-3 border-b border-gray-200 bg-gradient-to-r from-primary-50/60 to-white px-3 py-2.5 dark:border-gray-900 dark:from-gray-900/40 dark:to-gray-950">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary-100 text-primary-600 dark:bg-primary-900/30 dark:text-primary-300"><Printer class="h-4 w-4" /></span>
                        <div class="min-w-0"><h2 class="text-xs font-bold text-slate-700 dark:text-white">Aperçu ticket</h2><p class="text-[10px] text-slate-400">Imprimante thermique</p></div>
                    </div>
                    <span class="rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-[9px] font-bold text-slate-500 dark:border-gray-800 dark:bg-gray-900">80 mm</span>
                </div>

                <div class="ticket-preview-frame bg-gradient-to-b from-gray-100 to-gray-50 p-4 dark:from-gray-900/60 dark:to-gray-900/20">
                    <article ref="ticketRef" class="invoice-ticket mx-auto w-full max-w-[80mm] rounded-lg bg-white p-3 text-slate-800 shadow-md ring-1 ring-black/5">
                        <header class="border-b border-dashed border-slate-400 pb-2.5 text-center">
                            <p class="text-xs font-black uppercase tracking-wide">{{ brandName }}</p>
                            <p v-if="siteName !== brandName" class="text-[9px] font-bold uppercase tracking-[0.12em]">Site {{ siteName }}</p>
                            <h1 class="mt-2 text-base font-black uppercase tracking-wide">{{ isPharmacyInvoice ? 'Ticket Pharmacie' : 'Facture patient' }}</h1>
                            <p class="font-mono text-sm font-bold">{{ invoice.invoice_number }}</p>
                            <p class="text-[9px]">{{ formatDateTime(invoice.validated_at ?? invoice.created_at) }}</p>
                            <span class="mt-1 inline-flex border border-slate-700 px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wide">{{ invoiceStatusLabel }}</span>
                        </header>

                        <section class="space-y-1 border-b border-dashed border-slate-400 py-2.5 text-[11px]">
                            <div class="flex items-start justify-between gap-3"><span class="shrink-0">Client</span><span class="text-end font-bold">{{ customerName }}</span></div>
                            <div v-if="invoice.customer_phone" class="flex items-start justify-between gap-3"><span class="shrink-0">Téléphone</span><span class="text-end font-bold">{{ invoice.customer_phone }}</span></div>
                            <div v-if="externalPrescriber" class="flex items-start justify-between gap-3"><span class="shrink-0">Prescripteur</span><span class="text-end font-bold">{{ externalPrescriber }}</span></div>
                            <div class="flex items-start justify-between gap-3"><span class="shrink-0">Référence</span><span class="text-end font-mono font-bold">{{ customerReference }}</span></div>
                            <div class="flex items-start justify-between gap-3"><span class="shrink-0">Passage</span><span class="text-end font-mono font-bold">{{ invoice.episode?.episode_number ?? '—' }}</span></div>
                            <div class="flex items-start justify-between gap-3"><span class="shrink-0">Émise par</span><span class="text-end font-medium">{{ invoice.creator.name }}</span></div>
                            <div v-if="isStaffInvoice" class="flex items-start justify-between gap-3"><span class="shrink-0">Couverture</span><span class="text-end font-bold">Régime Personnel</span></div>
                            <div v-else-if="hasCoverage" class="flex items-start justify-between gap-3"><span class="shrink-0">Mutuelle</span><span class="text-end font-bold">{{ invoice.mutual_organization_name }} · {{ Number(invoice.coverage_rate).toLocaleString('fr-FR', { maximumFractionDigits: 2 }) }} %</span></div>
                        </section>

                        <section class="border-b border-dashed border-slate-400 py-2.5">
                            <h2 class="mb-1.5 text-center text-[9px] font-black uppercase tracking-wider">Détail des prestations</h2>
                            <div class="divide-y divide-dashed divide-slate-300">
                                <div v-for="line in invoice.lines" :key="line.id" class="ticket-line py-1.5 text-[11px] first:pt-0 last:pb-0">
                                    <div class="flex items-start justify-between gap-3"><p class="min-w-0 font-bold leading-3.5">{{ line.description }}</p><p class="shrink-0 font-black">{{ formatMoney(line.gross_line_total ?? line.line_total) }}</p></div>
                                    <div class="flex items-center justify-between gap-3 text-[9px] text-slate-500"><span>{{ line.quantity }} × {{ formatMoney(line.unit_price) }}</span><span v-if="line.billable_item?.source_module" class="uppercase">{{ line.billable_item.source_module }}</span></div>
                                    <div v-if="hasCoverage" class="mt-0.5 flex items-center justify-between gap-3 text-[9px]"><span>{{ isStaffInvoice ? 'Personnel' : 'Mutuelle' }} −{{ formatMoney(line.coverage_amount) }}</span><span>Patient {{ formatMoney(line.line_total) }}</span></div>
                                </div>
                            </div>
                        </section>

                        <section class="border-b border-dashed border-slate-400 py-2.5">
                            <dl class="space-y-1 text-[11px]">
                                <div class="flex items-center justify-between gap-3"><dt>Total brut</dt><dd class="font-bold">{{ formatMoney(invoice.subtotal_amount) }}</dd></div>
                                <div v-if="hasCoverage" class="flex items-center justify-between gap-3"><dt>{{ isStaffInvoice ? 'Part Personnel' : 'Part mutuelle' }}</dt><dd class="font-bold">− {{ formatMoney(invoice.coverage_amount) }}</dd></div>
                                <div v-if="hasStaffBlockCredit" class="flex items-center justify-between gap-3 text-[9px]"><dt>dont crédit Bloc</dt><dd>{{ formatMoney(invoice.staff_block_credit_used) }}</dd></div>
                                <div class="flex items-center justify-between gap-3"><dt>Part patient</dt><dd class="font-bold">{{ formatMoney(invoice.total_amount) }}</dd></div>
                                <div class="flex items-center justify-between gap-3"><dt>Payé</dt><dd class="font-bold">{{ formatMoney(invoice.paid_amount) }}</dd></div>
                                <div class="mt-1.5 flex items-center justify-between gap-3 border-y border-slate-800 py-1.5"><dt class="font-black uppercase">Reste à payer</dt><dd class="text-sm font-black">{{ formatMoney(invoice.balance_amount) }}</dd></div>
                            </dl>
                        </section>

                        <section class="py-2.5 text-center">
                            <img v-if="qrCodeDataUrl" :src="qrCodeDataUrl" :alt="`QR de la référence caisse ${invoice.invoice_number}`" class="invoice-qr mx-auto h-[22mm] w-[22mm]" />
                            <p class="text-[9px] font-bold uppercase tracking-wide">Référence caisse</p>
                            <p class="mt-0.5 font-mono text-[10px] font-black">{{ invoice.invoice_number }}</p>
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
    /* 1fr, not a capped max: a fixed 900px document next to a 360px ticket
       left a dead gap in the middle of wide screens. */
    grid-template-columns: minmax(700px, 1fr) 360px;
    align-items: start;
    gap: 1.25rem;
    width: 100%;
    min-width: 1100px;
    margin-inline: 0;
}

.invoice-workspace-ticket-only {
    grid-template-columns: 360px;
    justify-content: center;
    min-width: 0;
}

.invoice-document {
    width: 100%;
    min-width: 700px;
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

/*
 * Safety net for the screen-only redesign above (gradients, tinted rows, colored
 * status pill, rounded cards): neutralizes every new decorative background/shadow/
 * radius inside the printable areas so the generated invoice/ticket stays exactly
 * as before. Nothing here changes what already prints today.
 */
@media print {
    .invoice-page {
        background: transparent !important;
    }

    body[data-invoice-print="invoice"] .invoice-document,
    body[data-invoice-print="invoice"] .invoice-document * {
        background: transparent !important;
        background-image: none !important;
        box-shadow: none !important;
    }

    body[data-invoice-print="invoice"] .invoice-document,
    body[data-invoice-print="invoice"] .invoice-document footer,
    body[data-invoice-print="invoice"] .invoice-document thead {
        background: #fff !important;
    }

    body[data-invoice-print="invoice"] .invoice-status {
        border-radius: 2px !important;
    }

    body[data-invoice-print="invoice"] .invoice-balance-row {
        padding: 6px 0 0 !important;
        border-radius: 0 !important;
    }

    body[data-invoice-print="ticket"] .invoice-ticket * {
        background: transparent !important;
        box-shadow: none !important;
    }

    body[data-invoice-print="ticket"] .invoice-ticket {
        border-radius: 0 !important;
    }
}
</style>
