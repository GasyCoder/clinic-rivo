<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import ExplorerTile from '@/Components/UI/ExplorerTile.vue';
import ExplorerView from '@/Components/UI/ExplorerView.vue';
import PurchasesHeader from '@/Components/Pharmacy/PurchasesHeader.vue';
import { formatDateTime } from '@/utilities/date';

defineOptions({ layout: AppLayout });

defineProps({ receipts: Object, purchases: Object });
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
            empty-description="Réceptionnez une commande envoyée pour ajouter la marchandise au stock."
        >
            <template #grid>
                <ExplorerTile
                    v-for="receipt in receipts.data"
                    :key="receipt.uuid"
                    :href="`/pharmacy/receipts/${receipt.uuid}`"
                    icon="package"
                    tone="emerald"
                    :title="receipt.receipt_number"
                    :subtitle="receipt.supplier"
                    :highlight="`${receipt.lines_count} médicament${receipt.lines_count > 1 ? 's' : ''}`"
                    :meta="formatDateTime(receipt.received_at)"
                >
                    <template v-if="purchases.can.orders" #actions>
                        <Button :as="Link" :href="`/pharmacy/purchase-orders/${receipt.order_uuid}`" size="sm" variant="white-outline">{{ receipt.order_number }}</Button>
                    </template>
                </ExplorerTile>
            </template>

            <template #list>
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold text-slate-500 dark:bg-gray-1000">
                        <tr>
                            <th class="px-5 py-3 text-start">Réception</th>
                            <th class="px-4 py-3 text-start">Fournisseur</th>
                            <th class="px-4 py-3 text-start">Commande</th>
                            <th class="px-4 py-3 text-end">Médicaments</th>
                            <th class="px-4 py-3 text-start">Reçue le</th>
                            <th class="px-5 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                        <tr v-for="receipt in receipts.data" :key="receipt.uuid" class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-900/40">
                            <td class="px-5 py-3.5 font-mono font-semibold text-slate-800 dark:text-white">{{ receipt.receipt_number }}</td>
                            <td class="px-4 py-3.5 text-slate-700 dark:text-slate-200">{{ receipt.supplier }}</td>
                            <td class="px-4 py-3.5 font-mono text-slate-600 dark:text-slate-300">{{ receipt.order_number }}</td>
                            <td class="px-4 py-3.5 text-end tabular-nums">{{ receipt.lines_count }}</td>
                            <td class="px-4 py-3.5 text-slate-500">{{ formatDateTime(receipt.received_at) }}<span v-if="receipt.received_by" class="block text-xs text-slate-400">{{ receipt.received_by }}</span></td>
                            <td class="px-5 py-3.5">
                                <div class="flex justify-end gap-1.5 whitespace-nowrap">
                                    <Button :as="Link" :href="`/pharmacy/receipts/${receipt.uuid}`" size="sm" variant="white-outline">Voir</Button>
                                    <Button v-if="purchases.can.orders" :as="Link" :href="`/pharmacy/purchase-orders/${receipt.order_uuid}`" size="sm" variant="white-outline">Commande</Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>

            <template #footer>
                <nav v-if="receipts.prev_page_url || receipts.next_page_url" class="flex justify-between border-t border-gray-200 bg-white px-5 py-3 text-sm dark:border-gray-900 dark:bg-gray-950">
                    <Link v-if="receipts.prev_page_url" :href="receipts.prev_page_url" class="font-semibold text-primary-600" preserve-scroll>← Précédentes</Link><span v-else />
                    <Link v-if="receipts.next_page_url" :href="receipts.next_page_url" class="font-semibold text-primary-600" preserve-scroll>Suivantes →</Link>
                </nav>
            </template>
        </ExplorerView>
    </div>
</template>
