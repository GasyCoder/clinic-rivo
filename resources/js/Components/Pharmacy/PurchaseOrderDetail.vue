<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { formatDate, formatDateTime } from '@/utilities/date';
import { formatMoney, statusTone } from '@/utilities/pharmacyStatus';

/**
 * ADR-098 — one purchase order, shared by the clinic and the central portal.
 * Each page passes its own addresses; an action without an address (or
 * without its permission) is simply not offered.
 */
const props = defineProps({
    order: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    links: { type: Object, required: true }, // { submit, cancel, receive?, supplier?, receipt?(uuid), invoice?(uuid), newInvoice? }
    receptionNote: { type: String, default: null },
});

const submitOrder = () => {
    if (!confirm('Envoyer cette commande au fournisseur ? Ses lignes ne pourront plus être modifiées.')) return;
    router.post(props.links.submit, {}, { preserveScroll: true });
};

const cancelling = ref(false);
const cancelForm = useForm({ reason: '' });
const confirmCancel = () => cancelForm.post(props.links.cancel, {
    preserveScroll: true,
    onSuccess: () => { cancelling.value = false; cancelForm.reset(); },
});

const awaitingGoods = () => ['ORDERED', 'PARTIALLY_RECEIVED'].includes(props.order.status);
</script>

<template>
    <div class="space-y-5">
        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Commande {{ order.order_number }}</h1>
                        <Badge :tone="statusTone(order.status)" dot>{{ order.status_label }}</Badge>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">
                        <Link v-if="links.supplier" :href="links.supplier" class="font-semibold text-primary-600 hover:underline">{{ order.supplier }}</Link>
                        <span v-else class="font-semibold">{{ order.supplier }}</span>
                        <span v-if="order.ordered_at"> · envoyée le {{ formatDate(order.ordered_at) }}</span>
                        <span v-if="order.expected_delivery_at"> · livraison attendue le {{ formatDate(order.expected_delivery_at) }}</span>
                        <span v-if="order.created_by_name"> · créée par {{ order.created_by_name }}</span>
                    </p>
                    <p v-if="order.notes" class="mt-1 text-sm text-slate-500">{{ order.notes }}</p>
                    <p v-if="order.cancellation_reason" class="mt-2 text-sm text-red-600">Motif d’annulation : {{ order.cancellation_reason }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="can.cancel && !['RECEIVED', 'CANCELLED'].includes(order.status)" size="rg" variant="white-outline" class="text-red-600" @click="cancelling = true">Annuler la commande</Button>
                    <Button v-if="can.update && links.edit && order.status === 'DRAFT'" :as="Link" :href="links.edit" size="rg" variant="white-outline"><Icon name="edit" /><span class="ms-2">Modifier</span></Button>
                    <Button v-if="can.submit && order.status === 'DRAFT'" size="rg" @click="submitOrder"><Icon name="send" /><span class="ms-2">Envoyer la commande</span></Button>
                    <Button v-if="can.receive && links.receive && awaitingGoods()" :as="Link" :href="links.receive" size="rg"><Icon name="package" /><span class="ms-2">Réceptionner</span></Button>
                    <Button v-if="can.create_invoice && links.newInvoice && order.status !== 'CANCELLED' && order.status !== 'DRAFT'" :as="Link" :href="links.newInvoice" size="rg" variant="white-outline"><Icon name="file-text" /><span class="ms-2">Enregistrer la facture</span></Button>
                </div>
            </div>
            <p v-if="receptionNote && awaitingGoods()" class="mt-4 flex items-start gap-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950/20 dark:text-sky-100">
                <Icon name="info" class="mt-0.5" />{{ receptionNote }}
            </p>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[680px] text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold text-slate-500 dark:bg-gray-1000">
                        <tr>
                            <th class="px-5 py-3 text-start">Médicament</th>
                            <th class="px-4 py-3 text-end">Commandé</th>
                            <th class="px-4 py-3 text-end">Reçu</th>
                            <th class="px-4 py-3 text-end">Reste à recevoir</th>
                            <th class="px-4 py-3 text-end">Prix unitaire</th>
                            <th class="px-5 py-3 text-end">Total</th>
                            <th v-if="links.stock" class="px-5 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                        <tr v-for="line in order.lines" :key="line.id">
                            <td class="px-5 py-3.5"><span class="font-semibold text-slate-800 dark:text-white">{{ line.medicine_name }}</span> <span class="font-mono text-xs text-slate-400">{{ line.medicine_code }}</span></td>
                            <td class="px-4 py-3.5 text-end tabular-nums">{{ line.quantity_ordered }}</td>
                            <td class="px-4 py-3.5 text-end tabular-nums text-emerald-600">{{ line.quantity_received }}</td>
                            <td :class="['px-4 py-3.5 text-end font-semibold tabular-nums', line.quantity_remaining ? 'text-amber-600' : 'text-slate-400']">{{ line.quantity_remaining }}</td>
                            <td class="px-4 py-3.5 text-end tabular-nums">{{ formatMoney(line.unit_price) }}</td>
                            <td class="px-5 py-3.5 text-end font-semibold tabular-nums">{{ formatMoney(line.line_total) }}</td>
                            <td v-if="links.stock" class="px-5 py-3.5 text-end"><Link :href="links.stock(line.medicine_uuid)" class="text-sm font-semibold text-primary-600 hover:underline">Voir le stock</Link></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-gray-200 dark:border-gray-900">
                            <td colspan="5" class="px-5 py-3 text-end text-sm text-slate-500">Total de la commande</td>
                            <td class="px-5 py-3 text-end text-base font-bold tabular-nums text-slate-800 dark:text-white">{{ formatMoney(order.total_amount) }}</td>
                            <td v-if="links.stock" />
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <h2 class="font-heading text-base font-bold text-slate-800 dark:text-white">Réceptions</h2>
                <ul v-if="order.receipts.length" class="mt-3 divide-y divide-gray-100 dark:divide-gray-900">
                    <li v-for="receipt in order.receipts" :key="receipt.uuid">
                        <component :is="links.receipt ? Link : 'div'" :href="links.receipt ? links.receipt(receipt.uuid) : undefined" class="flex items-center justify-between gap-3 py-3 text-sm hover:text-primary-600">
                            <span><span class="font-mono font-semibold">{{ receipt.receipt_number }}</span> · {{ receipt.lines_count }} médicament{{ receipt.lines_count > 1 ? 's' : '' }}<span v-if="receipt.received_by"> · {{ receipt.received_by }}</span></span>
                            <span class="shrink-0 text-slate-400">{{ formatDateTime(receipt.received_at) }}</span>
                        </component>
                    </li>
                </ul>
                <p v-else class="mt-2 text-sm text-slate-500">Aucune marchandise reçue pour cette commande.</p>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <h2 class="font-heading text-base font-bold text-slate-800 dark:text-white">Factures du fournisseur</h2>
                <ul v-if="order.invoices.length" class="mt-3 divide-y divide-gray-100 dark:divide-gray-900">
                    <li v-for="invoice in order.invoices" :key="invoice.uuid">
                        <component :is="links.invoice ? Link : 'div'" :href="links.invoice ? links.invoice(invoice.uuid) : undefined" class="flex items-center justify-between gap-3 py-3 text-sm hover:text-primary-600">
                            <span class="font-mono font-semibold">{{ invoice.invoice_number }}</span>
                            <span class="tabular-nums text-slate-500">{{ formatMoney(invoice.total_amount) }}</span>
                        </component>
                    </li>
                </ul>
                <p v-else class="mt-2 text-sm text-slate-500">Aucune facture enregistrée pour cette commande.</p>
            </section>
        </div>

        <div v-if="cancelling" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/60 p-4" role="presentation" @click.self="cancelling = false">
            <section class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="cancel-order-title">
                <h2 id="cancel-order-title" class="font-heading text-lg font-bold text-slate-800 dark:text-white">Annuler la commande {{ order.order_number }}</h2>
                <p class="mt-1 text-sm text-slate-500">La commande reste visible dans l’historique, avec votre motif.</p>
                <form class="mt-4 space-y-4" @submit.prevent="confirmCancel">
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif <span class="text-red-500">*</span></span>
                        <textarea v-model="cancelForm.reason" required rows="3" class="block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" />
                        <span v-if="cancelForm.errors.reason || cancelForm.errors.status || cancelForm.errors.site" class="mt-1 block text-xs text-red-600">{{ cancelForm.errors.reason || cancelForm.errors.status || cancelForm.errors.site }}</span>
                    </label>
                    <div class="flex justify-end gap-2">
                        <Button type="button" size="rg" variant="white-outline" @click="cancelling = false">Retour</Button>
                        <Button type="submit" size="rg" variant="danger" :disabled="cancelForm.processing">Confirmer l’annulation</Button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</template>
