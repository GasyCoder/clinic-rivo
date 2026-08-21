<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({ invoice: Object });
const page = usePage();
const siteName = computed(() => page.props.site?.name ?? page.props.site?.brand ?? 'Clinique Saint Georges');
const status = computed(() => page.props.flash?.status);
const isPaid = computed(() => props.invoice.status === 'PAID');
const printInvoice = () => window.print();
</script>

<template>
    <Head :title="`Facture ${invoice.invoice_number}`" />

    <div class="invoice-page mx-auto w-full max-w-4xl space-y-4">
        <div v-if="status" class="invoice-actions flex items-center gap-3 rounded border border-gray-200 bg-white px-4 py-3 text-sm text-slate-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300" role="status"><Icon class="text-lg text-green-600" name="check-circle" /><span>{{ status }}</span></div>
        <div class="invoice-actions flex flex-wrap items-center justify-between gap-3">
            <Button :as="Link" :href="`/patients/${invoice.patient.uuid}`" size="rg" variant="white-outline">
                <Icon class="text-lg" name="arrow-left" />
                <span class="ms-2">Retour au patient</span>
            </Button>
            <Button size="rg" variant="primary" type="button" @click="printInvoice">
                <Icon class="text-lg" name="printer" />
                <span class="ms-2">Imprimer la facture</span>
            </Button>
        </div>

        <article class="invoice-paper rounded-lg border border-gray-200 bg-white p-7 shadow-sm dark:border-gray-900 dark:bg-gray-950 sm:p-10">
            <header class="flex flex-col gap-5 border-b border-gray-200 pb-6 dark:border-gray-900 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">{{ siteName }}</p>
                    <h1 class="mt-2 font-heading text-2xl font-bold text-slate-800 dark:text-white">Facture patient</h1>
                    <p class="mt-1 text-sm text-slate-400">Passage {{ invoice.episode.episode_number }}</p>
                </div>
                <div class="sm:text-end">
                    <p class="text-lg font-bold text-slate-700 dark:text-white">{{ invoice.invoice_number }}</p>
                    <p class="mt-1 text-sm text-slate-400">{{ formatDateTime(invoice.validated_at ?? invoice.created_at) }}</p>
                    <span class="mt-2 inline-flex rounded border border-gray-200 px-2 py-1 text-xs font-bold uppercase tracking-wide text-slate-600 dark:border-gray-800 dark:text-slate-300">
                        {{ isPaid ? 'Acquittée' : 'À payer' }}
                    </span>
                </div>
            </header>

            <section class="grid grid-cols-1 gap-5 border-b border-gray-200 py-6 dark:border-gray-900 sm:grid-cols-2">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Patient</p>
                    <p class="mt-1.5 font-bold text-slate-700 dark:text-white">{{ formatPatientName(invoice.patient) }}</p>
                    <p class="mt-0.5 text-sm text-slate-500">{{ invoice.patient.patient_number }}</p>
                </div>
                <div class="sm:text-end">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Émise par</p>
                    <p class="mt-1.5 font-medium text-slate-700 dark:text-white">{{ invoice.creator.name }}</p>
                    <p v-if="invoice.validator" class="mt-0.5 text-sm text-slate-500">Validée par {{ invoice.validator.name }}</p>
                </div>
            </section>

            <section class="py-6">
                <div class="overflow-hidden rounded border border-gray-200 dark:border-gray-800">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-slate-400 dark:bg-gray-900">
                            <tr>
                                <th class="px-4 py-3 text-start font-medium">Désignation</th>
                                <th class="px-4 py-3 text-end font-medium">Qté</th>
                                <th class="px-4 py-3 text-end font-medium">Tarif</th>
                                <th class="px-4 py-3 text-end font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                            <tr v-for="line in invoice.lines" :key="line.id">
                                <td class="px-4 py-3 text-slate-700 dark:text-slate-200">
                                    {{ line.description }}
                                    <span class="ms-1 text-xs text-slate-400">{{ line.billable_item?.source_module }}</span>
                                </td>
                                <td class="px-4 py-3 text-end text-slate-500">{{ line.quantity }}</td>
                                <td class="px-4 py-3 text-end text-slate-500">{{ formatMoney(line.unit_price) }}</td>
                                <td class="px-4 py-3 text-end font-bold text-slate-700 dark:text-white">{{ formatMoney(line.line_total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <dl class="ms-auto mt-6 w-full max-w-sm space-y-3 text-sm">
                    <div class="flex items-center justify-between"><dt class="text-slate-400">Sous-total</dt><dd class="font-medium text-slate-700 dark:text-white">{{ formatMoney(invoice.subtotal_amount) }}</dd></div>
                    <div class="flex items-center justify-between"><dt class="text-slate-400">Payé</dt><dd class="font-medium text-slate-700 dark:text-white">{{ formatMoney(invoice.paid_amount) }}</dd></div>
                    <div class="flex items-center justify-between border-t border-gray-200 pt-3 dark:border-gray-800"><dt class="font-bold text-slate-600 dark:text-slate-300">Reste à payer</dt><dd class="text-lg font-bold text-slate-800 dark:text-white">{{ formatMoney(invoice.balance_amount) }}</dd></div>
                </dl>
            </section>

            <footer class="border-t border-gray-200 pt-5 text-xs leading-5 text-slate-400 dark:border-gray-900">
                Cette facture reprend les tarifs actifs enregistrés au moment de l’arrivée. Un reçu de paiement est émis uniquement lors d’un encaissement réel.
            </footer>
        </article>
    </div>
</template>

<style>
@media print {
    .nk-sidebar,
    .nk-header,
    .nk-footer,
    .invoice-actions {
        display: none !important;
    }

    .nk-wrap { padding-inline-start: 0 !important; }
    .nk-content { margin: 0 !important; padding: 0 !important; }
    .invoice-page { max-width: none !important; }
    .invoice-paper { border: 0 !important; box-shadow: none !important; }
}
</style>
