<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import ExplorerTile from '@/Components/UI/ExplorerTile.vue';
import ExplorerView from '@/Components/UI/ExplorerView.vue';
import Icon from '@/Components/UI/Icon.vue';
import PurchasesHeader from '@/Components/Pharmacy/PurchasesHeader.vue';
import { formatDate } from '@/utilities/date';
import { formatMoney, statusTone } from '@/utilities/pharmacyStatus';

defineOptions({ layout: AppLayout });

const props = defineProps({ orders: Object, filters: Object, can: Object, purchases: Object });

const toReceive = computed(() => props.filters.status === 'TO_RECEIVE');

const statuses = [
    { value: '', label: 'Toutes' },
    { value: 'DRAFT', label: 'Brouillons' },
    { value: 'ORDERED', label: 'Envoyées' },
    { value: 'PARTIALLY_RECEIVED', label: 'Reçues en partie' },
    { value: 'RECEIVED', label: 'Reçues' },
    { value: 'CANCELLED', label: 'Annulées' },
];

const TILE_TONES = { DRAFT: 'slate', ORDERED: 'sky', PARTIALLY_RECEIVED: 'amber', RECEIVED: 'emerald', CANCELLED: 'rose' };
const awaitingGoods = (order) => ['ORDERED', 'PARTIALLY_RECEIVED'].includes(order.status);

const filterByStatus = (status) => router.get('/pharmacy/purchase-orders', {
    status: status || undefined,
    supplier: props.filters.supplier || undefined,
}, { preserveState: true, replace: true });
</script>

<template>
    <Head :title="toReceive ? 'À réceptionner' : 'Commandes fournisseurs'" />

    <div class="w-full space-y-5">
        <PurchasesHeader :active="toReceive ? 'to-receive' : 'orders'" :purchases="purchases">
            <template #actions>
                <Button v-if="props.can.create" :as="Link" :href="filters.supplier ? `/pharmacy/purchase-orders/create?supplier=${filters.supplier}` : '/pharmacy/purchase-orders/create'" size="rg">
                    <Icon name="plus" /><span class="ms-2">Nouvelle commande</span>
                </Button>
            </template>
        </PurchasesHeader>

        <div v-if="filters.supplier_name" class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-primary-200 bg-primary-50/60 px-4 py-3 text-sm dark:border-primary-900 dark:bg-primary-950/20">
            <span class="text-primary-900 dark:text-primary-100">Commandes du fournisseur <strong>{{ filters.supplier_name }}</strong></span>
            <span class="flex gap-4">
                <Link :href="`/pharmacy/suppliers/${filters.supplier}`" class="font-semibold text-primary-700 hover:underline dark:text-primary-300">Ouvrir son dossier</Link>
                <Link href="/pharmacy/purchase-orders" class="font-semibold text-primary-700 hover:underline dark:text-primary-300">Toutes les commandes</Link>
            </span>
        </div>

        <ExplorerView
            storage-key="pharmacy-orders"
            :count="orders.data.length"
            count-label="commande"
            empty-icon="truck"
            :empty-title="toReceive ? 'Rien à réceptionner' : 'Aucune commande'"
            :empty-description="toReceive ? 'Aucune commande envoyée n’attend de marchandise.' : 'Les commandes passées aux fournisseurs apparaîtront ici.'"
        >
            <template v-if="!toReceive" #toolbar>
                <div class="flex max-w-full gap-1 overflow-x-auto">
                    <button
                        v-for="status in statuses"
                        :key="status.value"
                        type="button"
                        :class="['shrink-0 rounded-full px-3 py-1 text-xs font-semibold transition', (filters.status || '') === status.value ? 'bg-slate-800 text-white dark:bg-white dark:text-slate-800' : 'text-slate-500 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-gray-900']"
                        @click="filterByStatus(status.value)"
                    >{{ status.label }}</button>
                </div>
            </template>
            <template v-else #toolbar>
                <span class="flex items-center gap-1.5 text-sm text-sky-700 dark:text-sky-300"><Icon name="info" />À réceptionner à l’arrivée des boîtes, en lisant lots et péremptions.</span>
            </template>

            <template #grid>
                <ExplorerTile
                    v-for="order in orders.data"
                    :key="order.uuid"
                    :href="`/pharmacy/purchase-orders/${order.uuid}`"
                    icon="truck"
                    :tone="TILE_TONES[order.status] ?? 'primary'"
                    :badge="order.status_label"
                    :title="order.order_number"
                    :subtitle="order.supplier"
                    :highlight="formatMoney(order.total_amount)"
                    :meta="formatDate(order.ordered_at || order.created_at)"
                    :muted="order.status === 'CANCELLED'"
                >
                    <template #actions>
                        <Button v-if="purchases.can.receive && awaitingGoods(order)" :as="Link" :href="`/pharmacy/purchase-orders/${order.uuid}/receive`" size="sm"><Icon name="package" /><span class="ms-1">Réceptionner</span></Button>
                        <Button v-if="props.can.update && order.status === 'DRAFT'" :as="Link" :href="`/pharmacy/purchase-orders/${order.uuid}/edit`" size="sm" variant="white-outline"><Icon name="edit" /></Button>
                    </template>
                </ExplorerTile>
            </template>

            <template #list>
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold text-slate-500 dark:bg-gray-1000">
                        <tr>
                            <th class="px-5 py-3 text-start">Commande</th>
                            <th class="px-4 py-3 text-start">Fournisseur</th>
                            <th class="px-4 py-3 text-start">État</th>
                            <th class="px-4 py-3 text-end">Montant</th>
                            <th class="px-4 py-3 text-start">Date</th>
                            <th class="px-5 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                        <tr v-for="order in orders.data" :key="order.uuid" class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-900/40">
                            <td class="px-5 py-3.5 font-mono font-semibold text-slate-800 dark:text-white">{{ order.order_number }}</td>
                            <td class="px-4 py-3.5 text-slate-700 dark:text-slate-200">{{ order.supplier }}</td>
                            <td class="px-4 py-3.5"><Badge :tone="statusTone(order.status)" dot>{{ order.status_label }}</Badge></td>
                            <td class="px-4 py-3.5 text-end tabular-nums text-slate-700 dark:text-white">{{ formatMoney(order.total_amount) }}</td>
                            <td class="px-4 py-3.5 text-slate-500">{{ formatDate(order.ordered_at || order.created_at) }}</td>
                            <td class="px-5 py-3.5">
                                <div class="flex justify-end gap-1.5 whitespace-nowrap">
                                    <Button :as="Link" :href="`/pharmacy/purchase-orders/${order.uuid}`" size="sm" variant="white-outline">Voir</Button>
                                    <Button v-if="props.can.update && order.status === 'DRAFT'" :as="Link" :href="`/pharmacy/purchase-orders/${order.uuid}/edit`" size="sm" variant="white-outline" :title="`Modifier ${order.order_number}`"><Icon name="edit" /></Button>
                                    <Button v-if="purchases.can.receive && awaitingGoods(order)" :as="Link" :href="`/pharmacy/purchase-orders/${order.uuid}/receive`" size="sm"><Icon name="package" /><span class="ms-1.5">Réceptionner</span></Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>

            <template #footer>
                <nav v-if="orders.prev_page_url || orders.next_page_url" class="flex justify-between border-t border-gray-200 bg-white px-5 py-3 text-sm dark:border-gray-900 dark:bg-gray-950">
                    <Link v-if="orders.prev_page_url" :href="orders.prev_page_url" class="font-semibold text-primary-600" preserve-scroll>← Précédentes</Link><span v-else />
                    <Link v-if="orders.next_page_url" :href="orders.next_page_url" class="font-semibold text-primary-600" preserve-scroll>Suivantes →</Link>
                </nav>
            </template>
        </ExplorerView>
    </div>
</template>
