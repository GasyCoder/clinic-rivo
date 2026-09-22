<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Building2, CheckCircle2, FileText, Hash, PackageCheck, Receipt, Truck, User } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { cn } from '@/lib/cn';
import { formatDate, formatDateTime } from '@/utilities/date';
import { formatMoney, formatNumber } from '@/utilities/pharmacyStatus';

defineOptions({ layout: AppLayout });

/*
 * ADR-113 — une réception constate une livraison ; elle n'entre rien au
 * stock. Cette fiche dit donc deux choses que l'ancienne taisait : ce qui
 * attend encore l'entrée en stock, et si la facture est arrivée.
 */
const props = defineProps({
    receipt: Object,
    canViewCost: { type: Boolean, default: false },
    can: { type: Object, default: () => ({}) },
});

const awaiting = computed(() => props.receipt.lines.filter((line) => !line.stocked_at));
const units = computed(() => props.receipt.lines.reduce((sum, line) => sum + line.quantity_received, 0));
const value = computed(() => props.receipt.lines.reduce((sum, line) => sum + line.quantity_received * Number(line.unit_purchase_price || 0), 0));
const invoice = computed(() => props.receipt.invoices[0] ?? null);
</script>

<template>
    <Head :title="`Réception ${receipt.receipt_number}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Achats', href: '/pharmacy/purchase-orders' }, { label: 'Réceptions', href: '/pharmacy/receipts' }, { label: receipt.receipt_number }]" />

        <PageHeader
            eyebrow="Réception"
            :title="receipt.receipt_number"
            :description="`${receipt.lines.length} produit${receipt.lines.length > 1 ? 's' : ''} · ${formatNumber(units)} unité${units > 1 ? 's' : ''} reçues le ${formatDateTime(receipt.received_at)}${receipt.received_by ? ` par ${receipt.received_by}` : ''}.`"
            icon="package"
            tone="violet"
        >
            <template #actions>
                <Button v-if="can.stock && awaiting.length" :as="Link" :href="`/pharmacy/stock/entries/create?fournisseur=${receipt.supplier_uuid}&commande=${receipt.order_uuid}`">
                    <PackageCheck class="h-4 w-4" />Entrer en stock
                </Button>
                <Button v-if="can.record_invoice && !invoice" :as="Link" :href="`/pharmacy/receipts/${receipt.uuid}/invoice`" variant="outline">
                    <Receipt class="h-4 w-4" />Enregistrer la facture
                </Button>
            </template>
        </PageHeader>

        <!-- Où en est cette livraison -->
        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="flex items-start gap-3 rounded-xl border border-border bg-card p-4 shadow-sm">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary"><Building2 class="h-5 w-5" /></span>
                <div class="min-w-0"><p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Fournisseur</p><p class="truncate font-semibold text-foreground">{{ receipt.supplier }}</p></div>
            </div>
            <div class="flex items-start gap-3 rounded-xl border border-border bg-card p-4 shadow-sm">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-muted text-muted-foreground"><Hash class="h-5 w-5" /></span>
                <div><p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Commande</p><Link :href="`/pharmacy/purchase-orders/${receipt.order_uuid}`" class="font-mono font-semibold text-primary hover:underline">{{ receipt.order_number }}</Link></div>
            </div>
            <div :class="cn('flex items-start gap-3 rounded-xl border p-4 shadow-sm', awaiting.length ? 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/20' : 'border-border bg-card')">
                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-xl', awaiting.length ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300' : 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300')">
                    <component :is="awaiting.length ? PackageCheck : CheckCircle2" class="h-5 w-5" />
                </span>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Entrée en stock</p>
                    <p class="font-semibold text-foreground">{{ awaiting.length ? `${awaiting.length} ligne${awaiting.length > 1 ? 's' : ''} en attente` : 'Tout est entré' }}</p>
                </div>
            </div>
            <div :class="cn('flex items-start gap-3 rounded-xl border p-4 shadow-sm', invoice ? 'border-border bg-card' : 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/20')">
                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-xl', invoice ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300')"><FileText class="h-5 w-5" /></span>
                <div class="min-w-0">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Facture</p>
                    <template v-if="invoice">
                        <Link v-if="can.view_invoices" :href="`/pharmacy/supplier-invoices/${invoice.uuid}`" class="truncate font-semibold text-primary hover:underline">{{ invoice.invoice_number }}</Link>
                        <span v-else class="font-semibold text-foreground">{{ invoice.invoice_number }}</span>
                        <p class="text-xs text-muted-foreground">{{ formatMoney(invoice.total_amount) }}<span v-if="invoice.due_date"> · échéance {{ formatDate(invoice.due_date) }}</span></p>
                    </template>
                    <p v-else class="font-semibold text-foreground">En attente</p>
                </div>
            </div>
        </section>

        <p v-if="receipt.notes" class="rounded-xl border border-border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">« {{ receipt.notes }} »</p>

        <section class="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
            <header class="flex flex-wrap items-center justify-between gap-2 border-b border-border p-5">
                <h2 class="font-heading text-base font-bold text-foreground">Produits reçus</h2>
                <p v-if="canViewCost && value" class="text-sm text-muted-foreground">Valeur reçue <span class="font-semibold tabular-nums text-foreground">{{ formatMoney(value) }}</span></p>
            </header>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="bg-muted/50 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-5 py-3 text-start">Produit</th>
                            <th class="px-4 py-3 text-start">Lot</th>
                            <th class="px-4 py-3 text-start">Péremption</th>
                            <th class="px-4 py-3 text-end">Quantité</th>
                            <th v-if="canViewCost" class="px-4 py-3 text-end">Prix d’achat</th>
                            <th class="px-4 py-3 text-start">Entrée en stock</th>
                            <th class="px-5 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="line in receipt.lines" :key="line.uuid" class="transition-colors hover:bg-muted/20">
                            <td class="px-5 py-3.5">
                                <p class="font-semibold text-foreground">{{ line.medicine_name }}</p>
                                <p v-if="line.notes" class="text-xs italic text-muted-foreground">« {{ line.notes }} »</p>
                            </td>
                            <td class="px-4 py-3.5 font-mono text-muted-foreground">{{ line.lot_number }}</td>
                            <td class="px-4 py-3.5 text-muted-foreground">{{ formatDate(line.expires_at) }}</td>
                            <td class="px-4 py-3.5 text-end font-semibold tabular-nums text-foreground">{{ formatNumber(line.quantity_received) }}<span v-if="line.unit" class="ms-1 text-xs font-normal text-muted-foreground">{{ line.unit }}</span></td>
                            <td v-if="canViewCost" class="px-4 py-3.5 text-end tabular-nums text-muted-foreground">{{ line.unit_purchase_price ? formatMoney(line.unit_purchase_price) : '—' }}</td>
                            <td class="px-4 py-3.5">
                                <Badge v-if="line.stocked_at" tone="success"><CheckCircle2 class="h-3 w-3" />{{ formatDate(line.stocked_at) }}</Badge>
                                <Badge v-else tone="warning">En attente</Badge>
                                <p v-if="line.stocked_by" class="mt-1 inline-flex items-center gap-1 text-[11px] text-muted-foreground"><User class="h-3 w-3" />{{ line.stocked_by }}</p>
                            </td>
                            <td class="px-5 py-3.5 text-end">
                                <Button :as="Link" :href="`/pharmacy/stock/${line.medicine_uuid}`" size="sm" variant="outline">Voir le stock</Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer v-if="awaiting.length" class="flex flex-wrap items-center justify-between gap-3 border-t border-border bg-amber-50/60 px-5 py-4 text-sm dark:bg-amber-950/10">
                <p class="text-amber-800 dark:text-amber-200">Cette marchandise n’est pas encore disponible : elle entre au stock quand elle est rangée.</p>
                <Button v-if="can.stock" :as="Link" :href="`/pharmacy/stock/entries/create?fournisseur=${receipt.supplier_uuid}&commande=${receipt.order_uuid}`" size="sm">
                    <PackageCheck class="h-4 w-4" />Entrer en stock
                </Button>
            </footer>
        </section>

        <div class="flex justify-end">
            <Button :as="Link" href="/pharmacy/receipts" variant="outline"><Truck class="h-4 w-4" />Toutes les réceptions</Button>
        </div>
    </div>
</template>
