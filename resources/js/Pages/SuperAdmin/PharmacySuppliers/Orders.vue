<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import ExplorerTile from '@/Components/UI/ExplorerTile.vue';
import ExplorerView from '@/Components/UI/ExplorerView.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { Ban, Folder, Pencil, Plus, TriangleAlert } from 'lucide-vue-next';
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

// Une commande n'est jamais supprimée (ADR-010) : un brouillon se corrige,
// une commande partie s'annule avec un motif, et une commande reçue ne se
// défait plus — la marchandise est entrée en stock.
const cancellable = (order) => !['RECEIVED', 'CANCELLED'].includes(order.status);

// Une colonne d'actions vide laisse croire à un droit manquant. Elle dit
// donc pourquoi il n'y a rien à faire — c'est l'état de la commande.
const WHY_LOCKED = {
    RECEIVED: 'Reçue : la marchandise est entrée en stock',
    CANCELLED: 'Annulée',
    ORDERED: 'Envoyée au fournisseur : elle s’annule, elle ne se modifie plus',
    PARTIALLY_RECEIVED: 'Partiellement reçue : elle ne se modifie plus',
};
const lockedReason = (order) => (order.status === 'DRAFT' ? null : WHY_LOCKED[order.status] ?? null);

const cancelling = ref(null);
const cancelForm = useForm({ reason: '' });

const askCancel = (order) => {
    cancelForm.reset();
    cancelForm.clearErrors();
    cancelling.value = order;
};

const confirmCancel = () => cancelForm.post(`${folderHref.value}/orders/${cancelling.value.uuid}/cancel`, {
    preserveScroll: true,
    onSuccess: () => { cancelling.value = null; },
});
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
            <TriangleAlert class="mt-0.5 h-4.5 w-4.5" /><p>{{ error }}</p>
        </section>

        <template v-else-if="supplier">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <Folder class="h-11 w-11 shrink-0 fill-violet-200 text-violet-500 dark:fill-violet-500/20" />
                    <div>
                        <h1 class="font-heading text-2xl font-bold text-foreground">Commandes</h1>
                        <p class="text-sm text-muted-foreground">{{ supplier.name }} · {{ targetSite.name }}<span v-if="openCount"> · {{ openCount }} en attente de réception</span></p>
                    </div>
                </div>
                <Button v-if="can.create_order" :as="Link" :href="`${folderHref}/orders/create`" size="rg"><Plus class="h-4 w-4" />Passer une commande</Button>
            </div>

            <ExplorerView storage-key="portal-orders" :count="orders.length" count-label="commande" empty-icon="truck" empty-title="Aucune commande" empty-description="Aucune commande n’a encore été passée à ce fournisseur sur ce site.">
                <template #toolbar>
                    <span class="text-xs text-muted-foreground">La réception se fait à la pharmacie du site.</span>
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
                        <thead class="bg-muted text-xs font-semibold text-muted-foreground">
                            <tr>
                                <th class="px-5 py-3 text-start">Commande</th>
                                <th class="px-4 py-3 text-start">État</th>
                                <th class="px-4 py-3 text-end">Montant</th>
                                <th class="px-4 py-3 text-start">Date</th>
                                <th class="px-5 py-3 text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="order in orders" :key="order.uuid" class="transition-colors hover:bg-accent/50">
                                <td class="px-5 py-3.5 font-mono font-semibold text-foreground">{{ order.order_number }}</td>
                                <td class="px-4 py-3.5"><Badge :tone="statusTone(order.status)" dot>{{ order.status_label }}</Badge></td>
                                <td class="px-4 py-3.5 text-end tabular-nums text-foreground">{{ formatMoney(order.total_amount) }}</td>
                                <td class="px-4 py-3.5 text-muted-foreground">{{ formatDate(order.ordered_at || order.created_at) }}</td>
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <Button :as="Link" :href="`${folderHref}/orders/${order.uuid}`" size="sm" variant="white-outline">Voir</Button>
                                        <Button
                                            v-if="can.update_order && order.status === 'DRAFT'"
                                            :as="Link"
                                            :href="`${folderHref}/orders/${order.uuid}/edit`"
                                            size="sm"
                                            variant="white-outline"
                                            :title="`Modifier ${order.order_number}`"
                                        ><Pencil class="h-4 w-4" /></Button>
                                        <Button
                                            v-if="can.cancel_order && cancellable(order)"
                                            size="sm"
                                            variant="white-outline"
                                            type="button"
                                            :title="`Annuler ${order.order_number}`"
                                            @click="askCancel(order)"
                                        ><Ban class="h-4 w-4" /></Button>
                                        <span v-if="!cancellable(order)" class="text-xs text-muted-foreground" :title="lockedReason(order)">{{ lockedReason(order) }}</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ExplorerView>
        </template>

        <Dialog
            :open="cancelling !== null"
            title="Annuler la commande"
            :description="cancelling ? `${cancelling.order_number} sera annulée. Elle reste visible avec son motif : une commande n’est jamais supprimée.` : ''"
            @update:open="cancelling = $event ? cancelling : null"
        >
            <Textarea v-model="cancelForm.reason" :rows="3" placeholder="Motif de l’annulation" />
            <p v-if="cancelForm.errors.reason" class="mt-1.5 text-sm text-destructive">{{ cancelForm.errors.reason }}</p>
            <template #footer>
                <Button type="button" variant="outline" @click="cancelling = null">Revenir</Button>
                <Button type="button" variant="destructive" :disabled="cancelForm.processing || cancelForm.reason.trim().length < 3" @click="confirmCancel">
                    <Ban class="h-4 w-4" />Annuler la commande
                </Button>
            </template>
        </Dialog>
    </div>
</template>
