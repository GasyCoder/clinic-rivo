<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import ExplorerTile from '@/Components/UI/ExplorerTile.vue';
import ExplorerView from '@/Components/UI/ExplorerView.vue';
import Icon from '@/Components/UI/Icon.vue';
import { formatDate } from '@/utilities/date';
import { formatMoney, statusTone } from '@/utilities/pharmacyStatus';

defineOptions({ layout: AppLayout });

const props = defineProps({
    targetSite: { type: Object, required: true },
    supplier: { type: Object, default: null },
    orders: { type: Array, default: () => [] },
    error: { type: String, default: null },
    can: { type: Object, default: () => ({}) },
});

const listHref = computed(() => `/super-admin/pharmacy-suppliers?site=${props.targetSite.code}`);
const folderHref = computed(() => `/super-admin/pharmacy-suppliers/${props.targetSite.code}/${props.supplier?.uuid}`);
const openCount = computed(() => props.orders.filter((order) => ['ORDERED', 'PARTIALLY_RECEIVED'].includes(order.status)).length);
const TILE_TONES = { DRAFT: 'slate', ORDERED: 'sky', PARTIALLY_RECEIVED: 'amber', RECEIVED: 'emerald', CANCELLED: 'rose' };
</script>

<template>
    <Head :title="supplier ? `Commandes · ${supplier.name}` : 'Commandes'" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[
            { label: 'Fournisseurs pharmacie', href: listHref },
            { label: supplier?.name ?? 'Dossier', href: supplier ? folderHref : null },
            { label: 'Commandes' },
        ]" />

        <section v-if="error" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
            <Icon name="alert" class="mt-0.5 text-lg" /><p>{{ error }}</p>
        </section>

        <template v-else-if="supplier">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <Icon name="folder-fill" class="text-5xl leading-none text-violet-400" />
                    <div>
                        <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Commandes</h1>
                        <p class="text-sm text-slate-500">{{ supplier.name }} · {{ targetSite.name }}<span v-if="openCount"> · {{ openCount }} en attente de réception</span></p>
                    </div>
                </div>
                <Button v-if="can.create_order" :as="Link" :href="`${folderHref}/orders/create`" size="rg"><Icon name="plus" /><span class="ms-2">Passer une commande</span></Button>
            </div>

            <ExplorerView storage-key="portal-orders" :count="orders.length" count-label="commande" empty-icon="truck" empty-title="Aucune commande" empty-description="Aucune commande n’a encore été passée à ce fournisseur sur ce site.">
                <template #toolbar>
                    <span class="text-xs text-slate-400">La réception se fait à la pharmacie du site.</span>
                </template>
                <template #grid>
                    <ExplorerTile
                        v-for="order in orders"
                        :key="order.uuid"
                        :href="`${folderHref}/orders/${order.uuid}`"
                        icon="truck"
                        :tone="TILE_TONES[order.status] ?? 'primary'"
                        :badge="order.status_label"
                        :title="order.order_number"
                        :highlight="formatMoney(order.total_amount)"
                        :meta="formatDate(order.ordered_at || order.created_at)"
                        :muted="order.status === 'CANCELLED'"
                    />
                </template>
                <template #list>
                    <table class="w-full min-w-[560px] text-sm">
                        <thead class="bg-gray-50 text-xs font-semibold text-slate-500 dark:bg-gray-1000">
                            <tr>
                                <th class="px-5 py-3 text-start">Commande</th>
                                <th class="px-4 py-3 text-start">État</th>
                                <th class="px-4 py-3 text-end">Montant</th>
                                <th class="px-4 py-3 text-start">Date</th>
                                <th class="px-5 py-3 text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                            <tr v-for="order in orders" :key="order.uuid" class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-900/40">
                                <td class="px-5 py-3.5 font-mono font-semibold text-slate-800 dark:text-white">{{ order.order_number }}</td>
                                <td class="px-4 py-3.5"><Badge :tone="statusTone(order.status)" dot>{{ order.status_label }}</Badge></td>
                                <td class="px-4 py-3.5 text-end tabular-nums text-slate-700 dark:text-white">{{ formatMoney(order.total_amount) }}</td>
                                <td class="px-4 py-3.5 text-slate-500">{{ formatDate(order.ordered_at || order.created_at) }}</td>
                                <td class="px-5 py-3.5 text-end"><Button :as="Link" :href="`${folderHref}/orders/${order.uuid}`" size="sm" variant="white-outline">Voir</Button></td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ExplorerView>
        </template>
    </div>
</template>
