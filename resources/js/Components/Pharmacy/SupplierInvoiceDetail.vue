<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { formatDate } from '@/utilities/date';
import { formatMoney } from '@/utilities/pharmacyStatus';

/**
 * ADR-098 — one supplier invoice, shared by the clinic and the central
 * portal. The document itself opens only where it is stored (the site).
 */
const props = defineProps({
    invoice: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    links: { type: Object, required: true }, // { archive, restore, attachment?, order?(uuid) }
    attachmentNote: { type: String, default: null },
});

const archiving = ref(false);
const archiveForm = useForm({ reason: '' });
const confirmArchive = () => archiveForm.delete(props.links.archive, {
    preserveScroll: true,
    onSuccess: () => { archiving.value = false; archiveForm.reset(); },
});
const restore = () => router.post(props.links.restore, {}, { preserveScroll: true });
</script>

<template>
    <div class="space-y-5">
        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Facture {{ invoice.invoice_number }}</h1>
                        <Badge v-if="invoice.archived" tone="neutral">Archivée</Badge>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">{{ invoice.supplier }} · {{ formatDate(invoice.invoice_date) }}<span v-if="invoice.created_by_name"> · enregistrée par {{ invoice.created_by_name }}</span></p>
                    <p v-if="invoice.purchase_order_number || invoice.goods_receipt_number" class="mt-1 text-sm text-slate-500">
                        <template v-if="invoice.purchase_order_number">
                            Commande
                            <Link v-if="links.order && invoice.purchase_order_uuid" :href="links.order(invoice.purchase_order_uuid)" class="font-semibold text-primary-600 hover:underline">{{ invoice.purchase_order_number }}</Link>
                            <span v-else>{{ invoice.purchase_order_number }}</span>
                        </template>
                        <span v-if="invoice.goods_receipt_number"> · Réception {{ invoice.goods_receipt_number }}</span>
                    </p>
                    <p v-if="invoice.notes" class="mt-1 text-sm text-slate-500">{{ invoice.notes }}</p>
                    <p v-if="invoice.archived && invoice.delete_reason" class="mt-1 text-sm text-slate-500">Motif d’archivage : {{ invoice.delete_reason }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="invoice.has_attachment && links.attachment" as="a" :href="links.attachment" target="_blank" size="rg" variant="white-outline"><Icon name="clip" /><span class="ms-2">Voir le document</span></Button>
                    <Button v-if="can.update && links.edit && !invoice.archived" :as="Link" :href="links.edit" size="rg" variant="white-outline"><Icon name="edit" /><span class="ms-2">Modifier</span></Button>
                    <Button v-if="can.delete && !invoice.archived" size="rg" variant="white-outline" class="text-red-600" @click="archiving = true">Archiver</Button>
                    <Button v-if="can.restore && invoice.archived" size="rg" variant="white-outline" @click="restore">Restaurer</Button>
                </div>
            </div>
            <p v-if="invoice.has_attachment && !links.attachment && attachmentNote" class="mt-3 flex items-center gap-2 text-sm text-slate-500"><Icon name="clip" />{{ attachmentNote }}</p>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[600px] text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold text-slate-500 dark:bg-gray-1000">
                        <tr>
                            <th class="px-5 py-3 text-start">Médicament</th>
                            <th class="px-4 py-3 text-start">Libellé facturé</th>
                            <th class="px-4 py-3 text-end">Qté</th>
                            <th class="px-4 py-3 text-end">Prix unitaire</th>
                            <th class="px-5 py-3 text-end">Total</th>
                            <th v-if="links.stock" class="px-5 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                        <tr v-for="(line, index) in invoice.lines" :key="index">
                            <td class="px-5 py-3.5 font-semibold text-slate-800 dark:text-white">{{ line.medicine_name }}</td>
                            <td class="px-4 py-3.5 text-slate-600 dark:text-slate-300">{{ line.description }}</td>
                            <td class="px-4 py-3.5 text-end tabular-nums">{{ line.quantity }}</td>
                            <td class="px-4 py-3.5 text-end tabular-nums">{{ formatMoney(line.unit_price) }}</td>
                            <td class="px-5 py-3.5 text-end font-semibold tabular-nums">{{ formatMoney(line.line_total) }}</td>
                            <td v-if="links.stock" class="px-5 py-3.5 text-end"><Link :href="links.stock(line.medicine_uuid)" class="text-sm font-semibold text-primary-600 hover:underline">Voir le stock</Link></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-gray-200 dark:border-gray-900">
                            <td colspan="4" class="px-5 py-3 text-end text-sm text-slate-500">Total de la facture</td>
                            <td class="px-5 py-3 text-end text-base font-bold tabular-nums text-slate-800 dark:text-white">{{ formatMoney(invoice.total_amount) }}</td>
                            <td v-if="links.stock" />
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <div v-if="archiving" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/60 p-4" role="presentation" @click.self="archiving = false">
            <section class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="archive-invoice-title">
                <h2 id="archive-invoice-title" class="font-heading text-lg font-bold text-slate-800 dark:text-white">Archiver la facture</h2>
                <p class="mt-1 text-sm text-slate-500">Elle reste consultable et pourra être restaurée.</p>
                <form class="mt-4 space-y-4" @submit.prevent="confirmArchive">
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif <span class="text-red-500">*</span></span>
                        <textarea v-model="archiveForm.reason" required rows="3" class="block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" />
                        <span v-if="archiveForm.errors.reason || archiveForm.errors.site" class="mt-1 block text-xs text-red-600">{{ archiveForm.errors.reason || archiveForm.errors.site }}</span>
                    </label>
                    <div class="flex justify-end gap-2">
                        <Button type="button" size="rg" variant="white-outline" @click="archiving = false">Retour</Button>
                        <Button type="submit" size="rg" variant="danger" :disabled="archiveForm.processing">Archiver</Button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</template>
