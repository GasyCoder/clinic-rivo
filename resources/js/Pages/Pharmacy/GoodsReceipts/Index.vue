<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, PackageCheck, Receipt } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ExplorerTile from '@/Components/UI/ExplorerTile.vue';
import ExplorerView from '@/Components/UI/ExplorerView.vue';
import PurchasesHeader from '@/Components/Pharmacy/PurchasesHeader.vue';
import { formatDateTime } from '@/utilities/date';

defineOptions({ layout: AppLayout });

/*
 * ADR-113 — une réception a désormais deux suites : entrer au stock, et
 * être facturée. La liste dit où chacune en est, plutôt que de laisser
 * croire qu'une livraison enregistrée est une livraison rangée.
 */
defineProps({ receipts: Object, purchases: Object, can: { type: Object, default: () => ({}) } });

const stockHref = (receipt) => `/pharmacy/stock/entries/create?fournisseur=${receipt.supplier_uuid}&commande=${receipt.order_uuid}`;
</script>

<template>
    <Head title="Réceptions" />

    <div class="w-full space-y-5">
        <PurchasesHeader active="receipts" :purchases="purchases" />

        <ExplorerView
            storage-key="pharmacy-receipts"
            :count="receipts.data.length"
            count-label="réception"
            empty-icon="package"
            empty-title="Aucune réception"
            empty-description="Réceptionnez une commande envoyée : la marchandise pourra ensuite entrer au stock."
        >
            <template #grid>
                <ExplorerTile
                    v-for="receipt in receipts.data"
                    :key="receipt.uuid"
                    :href="`/pharmacy/receipts/${receipt.uuid}`"
                    icon="package"
                    :tone="receipt.awaiting_stock_count ? 'amber' : 'emerald'"
                    :badge="receipt.awaiting_stock_count ? `${receipt.awaiting_stock_count} à ranger` : 'Rangée'"
                    :title="receipt.receipt_number"
                    :subtitle="receipt.supplier"
                    :highlight="`${receipt.lines_count} produit${receipt.lines_count > 1 ? 's' : ''}`"
                    :meta="formatDateTime(receipt.received_at)"
                >
                    <template #actions>
                        <Button v-if="can.stock && receipt.awaiting_stock_count" :as="Link" :href="stockHref(receipt)" size="sm"><PackageCheck class="h-4 w-4" />Entrer en stock</Button>
                        <Button v-else-if="can.record_invoice && receipt.invoice_pending" :as="Link" :href="`/pharmacy/receipts/${receipt.uuid}/invoice`" size="sm" variant="outline"><Receipt class="h-4 w-4" />Facture</Button>
                    </template>
                </ExplorerTile>
            </template>

            <template #list>
                <table class="w-full min-w-[860px] text-sm">
                    <thead class="bg-muted/50 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-5 py-3 text-start">Réception</th>
                            <th class="px-4 py-3 text-start">Fournisseur</th>
                            <th class="px-4 py-3 text-start">Commande</th>
                            <th class="px-4 py-3 text-end">Produits</th>
                            <th class="px-4 py-3 text-start">Entrée en stock</th>
                            <th class="px-4 py-3 text-start">Facture</th>
                            <th class="px-4 py-3 text-start">Reçue le</th>
                            <th class="px-5 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="receipt in receipts.data" :key="receipt.uuid" class="transition-colors hover:bg-muted/20">
                            <td class="px-5 py-3.5 font-mono font-semibold text-foreground">{{ receipt.receipt_number }}</td>
                            <td class="px-4 py-3.5 text-foreground">{{ receipt.supplier }}</td>
                            <td class="px-4 py-3.5 font-mono text-muted-foreground">{{ receipt.order_number }}</td>
                            <td class="px-4 py-3.5 text-end tabular-nums text-foreground">{{ receipt.lines_count }}</td>
                            <td class="px-4 py-3.5">
                                <Badge v-if="receipt.awaiting_stock_count" tone="warning">{{ receipt.awaiting_stock_count }} en attente</Badge>
                                <Badge v-else tone="success"><CheckCircle2 class="h-3 w-3" />Rangée</Badge>
                            </td>
                            <td class="px-4 py-3.5">
                                <Badge v-if="receipt.invoice_pending" tone="warning">En attente</Badge>
                                <Badge v-else tone="success">Reçue</Badge>
                            </td>
                            <td class="px-4 py-3.5 text-muted-foreground">{{ formatDateTime(receipt.received_at) }}<span v-if="receipt.received_by" class="block text-xs">{{ receipt.received_by }}</span></td>
                            <td class="px-5 py-3.5">
                                <div class="flex justify-end gap-1.5 whitespace-nowrap">
                                    <Button :as="Link" :href="`/pharmacy/receipts/${receipt.uuid}`" size="sm" variant="outline">Voir</Button>
                                    <Button v-if="can.stock && receipt.awaiting_stock_count" :as="Link" :href="stockHref(receipt)" size="sm"><PackageCheck class="h-4 w-4" />Entrer en stock</Button>
                                    <Button v-else-if="can.record_invoice && receipt.invoice_pending" :as="Link" :href="`/pharmacy/receipts/${receipt.uuid}/invoice`" size="sm" variant="outline"><Receipt class="h-4 w-4" />Facture</Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>

            <template #footer>
                <nav v-if="receipts.prev_page_url || receipts.next_page_url" class="flex justify-between border-t border-border bg-card px-5 py-3 text-sm">
                    <Link v-if="receipts.prev_page_url" :href="receipts.prev_page_url" class="font-semibold text-primary" preserve-scroll>← Précédentes</Link><span v-else />
                    <Link v-if="receipts.next_page_url" :href="receipts.next_page_url" class="font-semibold text-primary" preserve-scroll>Suivantes →</Link>
                </nav>
            </template>
        </ExplorerView>
    </div>
</template>
