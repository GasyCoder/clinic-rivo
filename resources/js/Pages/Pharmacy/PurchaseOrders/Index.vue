<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowUpDown, Building2, CalendarDays, Package, PackageCheck, Pencil, Plus, Search, Send, Trash2, Truck, X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PurchasesHeader from '@/Components/Pharmacy/PurchasesHeader.vue';
import { cn } from '@/lib/cn';
import { formatDate } from '@/utilities/date';
import { formatMoney, formatNumber, statusTone } from '@/utilities/pharmacyStatus';

defineOptions({ layout: AppLayout });

/*
 * ADR-175 — la liste des commandes d'achat : on y cherche une commande, on
 * voit d'un coup d'œil combien en attendent une marchandise, et chaque ligne
 * porte ce qu'on peut réellement en faire. Un brouillon jamais envoyé peut
 * partir à la corbeille ; une commande envoyée s'annule, elle ne se jette pas.
 */
const props = defineProps({
    orders: Object,
    filters: Object,
    counts: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    purchases: Object,
});

const toReceive = computed(() => props.filters.status === 'TO_RECEIVE');

const search = ref(props.filters.q ?? '');
const apply = (changes = {}) => router.get('/pharmacy/purchase-orders', {
    q: search.value || undefined,
    status: (changes.status ?? props.filters.status) || undefined,
    supplier: (changes.supplier ?? props.filters.supplier) || undefined,
    from: (changes.from ?? props.filters.from) || undefined,
    to: (changes.to ?? props.filters.to) || undefined,
    sort: (changes.sort ?? props.filters.sort) === 'recent' ? undefined : (changes.sort ?? props.filters.sort),
}, { preserveState: true, replace: true, preserveScroll: true });

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(apply, 350);
});

const filtered = computed(() => Boolean(props.filters.q || props.filters.status || props.filters.supplier || props.filters.from || props.filters.to));
const reset = () => { search.value = ''; router.get('/pharmacy/purchase-orders', {}, { preserveState: true, replace: true }); };

// Les compteurs sont le filtre : cliquer une carte filtre la liste.
const cards = computed(() => [
    { key: '', label: 'Toutes', count: props.counts.all, icon: Package, tone: 'text-muted-foreground' },
    { key: 'DRAFT', label: 'Brouillons', count: props.counts.DRAFT, icon: Pencil, tone: 'text-muted-foreground' },
    { key: 'TO_RECEIVE', label: 'À réceptionner', count: props.counts.TO_RECEIVE, icon: Truck, tone: 'text-amber-600 dark:text-amber-400' },
    { key: 'RECEIVED', label: 'Reçues', count: props.counts.RECEIVED, icon: PackageCheck, tone: 'text-emerald-600 dark:text-emerald-400' },
    { key: 'CANCELLED', label: 'Annulées', count: props.counts.CANCELLED, icon: X, tone: 'text-muted-foreground' },
]);

const supplierOptions = computed(() => [
    { value: '', label: 'Tous les fournisseurs' },
    ...props.suppliers.map((supplier) => ({ value: supplier.uuid, label: supplier.name })),
]);
const sortOptions = [
    { value: 'recent', label: 'Plus récentes d’abord' },
    { value: 'oldest', label: 'Plus anciennes d’abord' },
    { value: 'amount', label: 'Montant décroissant' },
    { value: 'number', label: 'Numéro de commande' },
    { value: 'supplier', label: 'Fournisseur (A→Z)' },
];

const awaitingGoods = (order) => ['ORDERED', 'PARTIALLY_RECEIVED'].includes(order.status);
const isDraft = (order) => order.status === 'DRAFT';
// ADR-176 — un brouillon n'a engagé personne, une commande annulée n'engage
// plus : les deux se rangent à la corbeille, restaurables. Une commande vivante
// s'annule d'abord.
const canTrash = (order) => ['DRAFT', 'CANCELLED'].includes(order.status);
const hasRowAction = (order) => (props.can.update && isDraft(order))
    || (props.can.submit && isDraft(order))
    || (props.can.receive && awaitingGoods(order))
    || (props.can.delete && canTrash(order));
// Une colonne d'actions vide dit pourquoi (ADR-098). Une commande qui attend
// encore sa marchandise n'est jamais « terminée » : si aucun bouton n'apparaît,
// c'est le droit de réceptionner qui manque, pas l'étape (ADR-154).
const idleReason = (order) => {
    if (awaitingGoods(order) && !props.can.receive) {
        return 'Réceptionner demande le droit « goods_receipts.create »';
    }

    return {
        RECEIVED: 'Reçue',
        CANCELLED: 'Annulée',
        ORDERED: 'Envoyée au fournisseur',
        PARTIALLY_RECEIVED: 'Reçue en partie',
    }[order.status] ?? '';
};

// --- Envoyer ------------------------------------------------------------------
const sending = ref(null);
const sendForm = useForm({});
const send = () => sendForm.post(`/pharmacy/purchase-orders/${sending.value.uuid}/submit`, {
    preserveScroll: true,
    onSuccess: () => { sending.value = null; },
});

// --- Corbeille ----------------------------------------------------------------
const trashing = ref(null);
const trashForm = useForm({ reason: '' });
const trash = () => trashForm.delete(`/pharmacy/purchase-orders/${trashing.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { trashing.value = null; trashForm.reset(); },
});
</script>

<template>
    <Head :title="toReceive ? 'À réceptionner' : 'Commandes fournisseurs'" />

    <div class="w-full space-y-5">
        <PurchasesHeader :active="toReceive ? 'to-receive' : 'orders'" :purchases="purchases">
            <template #actions>
                <Button v-if="can.create" :as="Link" :href="filters.supplier ? `/pharmacy/purchase-orders/create?supplier=${filters.supplier}` : '/pharmacy/purchase-orders/create'">
                    <Plus class="h-4 w-4" />Nouvelle commande
                </Button>
            </template>
        </PurchasesHeader>

        <div v-if="filters.supplier_name" class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-primary/30 bg-primary/5 px-4 py-3 text-sm">
            <span class="inline-flex items-center gap-2 text-foreground"><Building2 class="h-4 w-4 text-primary" />Commandes de <strong>{{ filters.supplier_name }}</strong></span>
            <span class="flex gap-4">
                <Link :href="`/pharmacy/suppliers/${filters.supplier}`" class="font-semibold text-primary hover:underline">Ouvrir son dossier</Link>
                <Link href="/pharmacy/purchase-orders" class="font-semibold text-primary hover:underline">Toutes les commandes</Link>
            </span>
        </div>

        <!-- Compteurs cliquables -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <button
                v-for="card in cards"
                :key="card.key || 'all'"
                type="button"
                :class="cn('rounded-xl border bg-card p-4 text-start shadow-sm transition hover:border-primary/40',
                           (filters.status || '') === card.key ? 'border-primary ring-2 ring-primary/20' : 'border-border')"
                @click="apply({ status: card.key })"
            >
                <span class="flex items-center gap-2 text-xs font-semibold text-muted-foreground"><component :is="card.icon" :class="cn('h-4 w-4', card.tone)" />{{ card.label }}</span>
                <span class="mt-1.5 block font-heading text-2xl font-bold tabular-nums text-foreground">{{ formatNumber(card.count ?? 0) }}</span>
            </button>
        </div>

        <!-- Recherche et filtres -->
        <section class="flex flex-col gap-3 rounded-xl border border-border bg-card p-4 shadow-sm lg:flex-row lg:items-center">
            <IconInput v-model="search" :icon="Search" class="lg:max-w-sm" placeholder="N° de commande ou fournisseur…" autocomplete="off" @keydown.enter="apply()" />
            <div class="flex flex-wrap items-center gap-2">
                <Select v-if="suppliers.length" :model-value="filters.supplier ?? ''" :options="supplierOptions" :icon="Building2" placeholder="Tous les fournisseurs" @update:model-value="(value) => apply({ supplier: value })" />
                <div class="flex items-center gap-1.5 rounded-lg border border-border px-2 py-1">
                    <CalendarDays class="h-4 w-4 text-muted-foreground" />
                    <DatePicker :model-value="filters.from ?? ''" size="sm" placeholder="À partir du" :max="filters.to || undefined" @update:model-value="(value) => apply({ from: value })" />
                    <span class="text-xs text-muted-foreground">→</span>
                    <DatePicker :model-value="filters.to ?? ''" size="sm" placeholder="Jusqu’au" :min="filters.from || undefined" @update:model-value="(value) => apply({ to: value })" />
                </div>
                <Select :model-value="filters.sort ?? 'recent'" :options="sortOptions" :icon="ArrowUpDown" @update:model-value="(value) => apply({ sort: value })" />
                <Button v-if="filtered" variant="ghost" size="sm" @click="reset"><X class="h-4 w-4" />Tout effacer</Button>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
            <div v-if="orders.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[920px] text-sm">
                    <thead class="bg-muted/50 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-5 py-3 text-start">Commande</th>
                            <th class="px-4 py-3 text-start">Fournisseur</th>
                            <th class="px-4 py-3 text-start">Date</th>
                            <th class="px-4 py-3 text-start">État</th>
                            <th class="px-4 py-3 text-end">Lignes</th>
                            <th class="px-4 py-3 text-end">Montant</th>
                            <th class="px-5 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="order in orders.data" :key="order.uuid" :class="cn('transition-colors hover:bg-muted/20', order.status === 'CANCELLED' && 'opacity-60')">
                            <td class="px-5 py-3.5">
                                <Link :href="`/pharmacy/purchase-orders/${order.uuid}`" class="font-mono font-semibold text-foreground hover:text-primary hover:underline">{{ order.order_number }}</Link>
                            </td>
                            <td class="px-4 py-3.5 text-foreground">{{ order.supplier }}</td>
                            <td class="px-4 py-3.5 text-muted-foreground">
                                {{ formatDate(order.ordered_at || order.created_at) }}
                                <span class="block text-xs">{{ order.ordered_at ? 'envoyée' : 'créée' }}</span>
                            </td>
                            <td class="px-4 py-3.5"><Badge :tone="statusTone(order.status)">{{ order.status_label }}</Badge></td>
                            <td class="px-4 py-3.5 text-end tabular-nums text-muted-foreground">{{ order.lines_count }}</td>
                            <td class="px-4 py-3.5 text-end font-semibold tabular-nums text-foreground">{{ formatMoney(order.total_amount) }}</td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-1.5 whitespace-nowrap">
                                    <Button :as="Link" :href="`/pharmacy/purchase-orders/${order.uuid}`" size="sm" variant="outline">Voir</Button>
                                    <Button v-if="can.update && isDraft(order)" :as="Link" :href="`/pharmacy/purchase-orders/${order.uuid}/edit`" size="icon" variant="outline" :title="`Modifier ${order.order_number}`"><Pencil class="h-4 w-4" /></Button>
                                    <Button v-if="can.submit && isDraft(order)" size="sm" @click="sending = order"><Send class="h-4 w-4" />Envoyer</Button>
                                    <Button v-if="can.receive && awaitingGoods(order)" :as="Link" :href="`/pharmacy/purchase-orders/${order.uuid}/receive`" size="sm"><PackageCheck class="h-4 w-4" />Réceptionner</Button>
                                    <Button v-if="can.delete && canTrash(order)" size="icon" variant="ghost" class="text-muted-foreground hover:text-red-600" :title="`Mettre ${order.order_number} à la corbeille`" @click="trashing = order"><Trash2 class="h-4 w-4" /></Button>
                                    <span v-if="!hasRowAction(order)" class="text-xs text-muted-foreground">{{ idleReason(order) }}</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <EmptyState
                v-else
                icon="truck"
                :title="filtered ? 'Aucune commande ne correspond' : 'Aucune commande'"
                :description="filtered ? 'Modifiez la recherche, la période ou l’état.' : 'Les commandes passées aux fournisseurs apparaîtront ici.'"
            />

            <nav v-if="orders.prev_page_url || orders.next_page_url" class="flex justify-between border-t border-border px-5 py-3 text-sm">
                <Link v-if="orders.prev_page_url" :href="orders.prev_page_url" class="font-semibold text-primary" preserve-scroll>← Précédentes</Link><span v-else />
                <Link v-if="orders.next_page_url" :href="orders.next_page_url" class="font-semibold text-primary" preserve-scroll>Suivantes →</Link>
            </nav>
        </section>

        <ConfirmModal
            :open="Boolean(sending)"
            title="Envoyer cette commande au fournisseur ?"
            description="La commande quitte le brouillon : elle ne se modifie plus, elle s’annule avec un motif. Sa date d’envoi est enregistrée maintenant."
            confirm-label="Envoyer la commande"
            :processing="sendForm.processing"
            :dismissible="!sendForm.processing"
            @update:open="(value) => { if (!value) sending = null; }"
            @confirm="send"
        >
            <template #confirm-icon><Send class="h-4 w-4" /></template>
            <dl v-if="sending" class="divide-y divide-border rounded-xl border border-border text-sm">
                <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-muted-foreground">Commande</dt><dd class="font-mono font-semibold text-foreground">{{ sending.order_number }}</dd></div>
                <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-muted-foreground">Fournisseur</dt><dd class="text-end font-semibold text-foreground">{{ sending.supplier }}</dd></div>
                <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-muted-foreground">Montant</dt><dd class="font-semibold tabular-nums text-foreground">{{ formatMoney(sending.total_amount) }}</dd></div>
            </dl>
        </ConfirmModal>

        <ConfirmModal
            :open="Boolean(trashing)"
            :title="trashing && trashing.status === 'CANCELLED' ? 'Mettre cette commande annulée à la corbeille ?' : 'Mettre ce brouillon à la corbeille ?'"
            :description="trashing && trashing.status === 'CANCELLED'
                ? 'Elle quitte la liste des commandes et reste restaurable depuis la Corbeille. Son annulation et son motif sont conservés.'
                : 'Il quitte la liste des commandes et reste restaurable depuis la Corbeille. Rien n’a été envoyé au fournisseur.'"
            confirm-label="Mettre à la corbeille"
            tone="danger"
            :processing="trashForm.processing"
            :disabled="trashForm.reason.trim().length < 3"
            :dismissible="!trashForm.processing"
            @update:open="(value) => { if (!value) { trashing = null; trashForm.reset(); trashForm.clearErrors(); } }"
            @confirm="trash"
        >
            <template #confirm-icon><Trash2 class="h-4 w-4" /></template>
            <div class="space-y-3">
                <p v-if="trashing" class="text-sm text-muted-foreground">Commande <span class="font-mono font-semibold text-foreground">{{ trashing.order_number }}</span> · {{ trashing.supplier }} · {{ formatMoney(trashing.total_amount) }}</p>
                <label class="block">
                    <span class="mb-1.5 block text-sm font-semibold text-foreground">Pourquoi ? <span class="text-red-500">*</span></span>
                    <Textarea v-model="trashForm.reason" rows="2" maxlength="1000" placeholder="Ex. saisi en double, fournisseur changé" />
                    <span v-if="trashForm.errors.reason" class="mt-1 block text-xs text-destructive">{{ trashForm.errors.reason }}</span>
                </label>
            </div>
        </ConfirmModal>
    </div>
</template>
