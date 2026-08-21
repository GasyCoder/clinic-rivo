<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({ receipt: Object });
const page = usePage();
const { can } = usePermissions();
const siteName = computed(() => page.props.site?.name ?? page.props.site?.brand ?? 'Clinique Saint Georges');
const status = computed(() => page.props.flash?.status);
const payment = computed(() => props.receipt.payment);
const invoice = computed(() => payment.value.invoice);
const printReceipt = () => window.print();
</script>

<template>
    <Head :title="`Reçu ${receipt.receipt_number}`" />

    <div class="receipt-page mx-auto w-full max-w-3xl space-y-4">
        <div v-if="status" class="receipt-actions flex items-center gap-3 rounded border border-gray-200 bg-white px-4 py-3 text-sm text-slate-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300" role="status"><Icon class="text-lg text-green-600" name="check-circle" /><span>{{ status }}</span></div>
        <div class="receipt-actions flex flex-wrap items-center justify-between gap-3">
            <Button :as="Link" :href="`/patients/${invoice.patient.uuid}`" size="rg" variant="white-outline">
                <Icon class="text-lg" name="arrow-left" />
                <span class="ms-2">Retour au patient</span>
            </Button>
            <Button v-if="can('receipts.print')" size="rg" variant="primary" type="button" @click="printReceipt">
                <Icon class="text-lg" name="printer" />
                <span class="ms-2">Imprimer le reçu</span>
            </Button>
        </div>

        <article class="receipt-paper rounded-lg border border-gray-200 bg-white p-7 shadow-sm dark:border-gray-900 dark:bg-gray-950 sm:p-10">
            <div v-if="payment.status === 'CANCELLED'" class="mb-6 border border-red-200 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:text-red-300">
                <p class="font-bold uppercase tracking-wide">Paiement annulé</p>
                <p class="mt-1 text-xs">Annulé le {{ formatDateTime(payment.cancelled_at) }}. Ce reçu est conservé uniquement comme trace historique.</p>
                <p v-if="payment.cancellation_reason" class="mt-1 text-xs">Motif : {{ payment.cancellation_reason }}</p>
            </div>
            <header class="flex flex-col gap-5 border-b border-gray-200 pb-6 dark:border-gray-900 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-primary-600">{{ siteName }}</p>
                    <h1 class="mt-2 font-heading text-2xl font-bold text-slate-800 dark:text-white">Reçu de paiement</h1>
                    <p class="mt-1 text-sm text-slate-400">Document original remis au patient.</p>
                </div>
                <div class="sm:text-end">
                    <p class="text-lg font-bold text-slate-700 dark:text-white">{{ receipt.receipt_number }}</p>
                    <p class="mt-1 text-sm text-slate-400">{{ formatDateTime(receipt.issued_at) }}</p>
                </div>
            </header>

            <section class="grid grid-cols-1 gap-5 border-b border-gray-200 py-6 dark:border-gray-900 sm:grid-cols-2">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Patient</p>
                    <p class="mt-1.5 font-bold text-slate-700 dark:text-white">{{ formatPatientName(invoice.patient) }}</p>
                    <p class="mt-0.5 text-sm text-slate-500">{{ invoice.patient.patient_number }}</p>
                </div>
                <div class="sm:text-end">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Référence</p>
                    <p class="mt-1.5 font-bold text-slate-700 dark:text-white">Facture {{ invoice.invoice_number }}</p>
                    <p class="mt-0.5 text-sm text-slate-500">Passage {{ invoice.episode.episode_number }}</p>
                </div>
            </section>

            <section class="py-7">
                <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-6 text-center dark:border-gray-800 dark:bg-gray-900/50">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Montant reçu</p>
                    <p :class="['mt-2 text-3xl font-bold', payment.status === 'CANCELLED' ? 'text-slate-400 line-through' : 'text-slate-800 dark:text-white']">{{ formatMoney(payment.amount) }}</p>
                    <p class="mt-2 text-sm text-slate-500">{{ payment.method.name }}</p>
                </div>

                <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-4 text-sm">
                    <div>
                        <dt class="text-slate-400">N° paiement</dt>
                        <dd class="mt-1 font-medium text-slate-700 dark:text-white">{{ payment.payment_number }}</dd>
                    </div>
                    <div class="text-end">
                        <dt class="text-slate-400">Caissier</dt>
                        <dd class="mt-1 font-medium text-slate-700 dark:text-white">{{ payment.cashier.name }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Total facture</dt>
                        <dd class="mt-1 font-medium text-slate-700 dark:text-white">{{ formatMoney(invoice.total_amount) }}</dd>
                    </div>
                    <div class="text-end">
                        <dt class="text-slate-400">Reste à payer</dt>
                        <dd class="mt-1 font-bold text-slate-700 dark:text-white">{{ formatMoney(invoice.balance_amount) }}</dd>
                    </div>
                    <div v-if="payment.reference" class="col-span-2">
                        <dt class="text-slate-400">Référence externe</dt>
                        <dd class="mt-1 font-medium text-slate-700 dark:text-white">{{ payment.reference }}</dd>
                    </div>
                </dl>
            </section>

            <footer class="border-t border-gray-200 pt-5 text-xs leading-5 text-slate-400 dark:border-gray-900">
                Paiement enregistré le {{ formatDateTime(payment.paid_at) }} et reçu émis par {{ receipt.issuer.name }}.
            </footer>
        </article>
    </div>
</template>

<style>
@media print {
    .nk-sidebar,
    .nk-header,
    .nk-footer,
    .receipt-actions {
        display: none !important;
    }

    .nk-wrap {
        padding-inline-start: 0 !important;
    }

    .nk-content {
        margin: 0 !important;
        padding: 0 !important;
    }

    .receipt-page {
        max-width: none !important;
    }

    .receipt-paper {
        border: 0 !important;
        box-shadow: none !important;
    }
}
</style>
