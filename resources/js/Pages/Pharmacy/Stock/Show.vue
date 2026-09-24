<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { medicineFamily, medicineSubtitle } from '@/utilities/medicine';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/UI/Badge.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { ArrowLeft, Pencil } from 'lucide-vue-next';
import { formatDate, formatDateTime } from '@/utilities/date';
import { formatMoney, formatNumber, statusTone } from '@/utilities/pharmacyStatus';

defineOptions({ layout: AppLayout });

defineProps({
    capabilities: { type: Object, required: true },
    medicine: { type: Object, required: true },
    movements: { type: Array, default: () => [] },
    supplierNames: { type: Array, default: () => [] },
});
</script>

<template>
    <Head :title="`Stock · ${medicine.name}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Médicaments & stock', href: '/pharmacy/stock' }, { label: medicine.name }]" />

        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">{{ medicine.name }}</h1>
                        <Badge :tone="statusTone(medicine.status)" dot>{{ medicine.status_label }}</Badge>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">{{ medicineSubtitle(medicine) }}</p>
                    <Badge v-if="medicineFamily(medicine)" tone="neutral" class="mt-1.5">{{ medicineFamily(medicine) }}</Badge>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="capabilities.can_update_medicine" :as="Link" :href="`/pharmacy/medicines/${medicine.uuid}/edit`" size="rg" variant="white-outline">
                        <Pencil class="h-4 w-4" /><span class="ms-2">Modifier la fiche</span>
                    </Button>
                </div>
            </div>

            <dl class="mt-5 grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-900">
                    <dt class="text-xs text-slate-500">Disponible à la vente</dt>
                    <dd class="mt-1 text-2xl font-bold tabular-nums text-emerald-600">{{ formatNumber(medicine.available_quantity) }} <span class="text-sm font-normal text-slate-400">{{ medicine.unit }}</span></dd>
                </div>
                <div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-900">
                    <dt class="text-xs text-slate-500">Réservé pour des ordonnances</dt>
                    <dd class="mt-1 text-2xl font-bold tabular-nums text-primary-600">{{ formatNumber(medicine.reserved_quantity) }}</dd>
                </div>
                <div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-900">
                    <dt class="text-xs text-slate-500">Présent sur les étagères</dt>
                    <dd class="mt-1 text-2xl font-bold tabular-nums text-slate-700 dark:text-white">{{ formatNumber(medicine.quantity_on_hand) }}</dd>
                </div>
            </dl>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <header class="border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                <h2 class="font-heading text-base font-bold text-slate-800 dark:text-white">Lots</h2>
                <p class="mt-0.5 text-sm text-slate-500">Chaque entrée garde son lot, sa date de péremption et son fournisseur. Les plus proches de la péremption sortent en premier.</p>
            </header>

            <EmptyState v-if="!capabilities.can_view_lots" icon="lock" title="Détail des lots non accessible" description="Votre compte n’a pas l’autorisation de consulter les lots." />
            <div v-else-if="medicine.lots.length" class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold text-slate-500 dark:bg-gray-1000">
                        <tr>
                            <th class="px-5 py-3 text-start">Lot</th>
                            <th v-if="capabilities.can_view_expiration" class="px-4 py-3 text-start">Péremption</th>
                            <th class="px-4 py-3 text-start">Fournisseur</th>
                            <th class="px-4 py-3 text-end">Disponible</th>
                            <th class="px-4 py-3 text-start">État</th>
                            <th v-if="capabilities.can_adjust_stock" class="px-5 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                        <tr v-for="lot in medicine.lots" :key="lot.uuid">
                            <td class="px-5 py-3.5 font-mono font-semibold text-slate-700 dark:text-white">{{ lot.lot_number }}</td>
                            <td v-if="capabilities.can_view_expiration" class="px-4 py-3.5 text-slate-600 dark:text-slate-300">{{ formatDate(lot.expires_at) }}</td>
                            <td class="px-4 py-3.5 text-slate-600 dark:text-slate-300">{{ lot.supplier?.name ?? '—' }}</td>
                            <td class="px-4 py-3.5 text-end">
                                <span class="font-bold tabular-nums text-slate-800 dark:text-white">{{ formatNumber(lot.available_quantity) }}</span>
                                <span class="block text-xs text-slate-400">sur {{ formatNumber(lot.quantity_on_hand) }} présent{{ lot.quantity_on_hand > 1 ? 's' : '' }}</span>
                            </td>
                            <td class="px-4 py-3.5"><Badge :tone="statusTone(lot.status)">{{ lot.status_label }}</Badge></td>
                            <td v-if="capabilities.can_adjust_stock" class="px-5 py-3.5 text-end">
                                <Link :href="`/pharmacy/stock/adjustments/create?lot=${lot.uuid}`" class="text-sm font-semibold text-primary-600 hover:underline">Corriger</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else icon="package" title="Aucun lot" description="Ce médicament n’a encore jamais été reçu en stock." />
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <header class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                <div>
                    <h2 class="font-heading text-base font-bold text-slate-800 dark:text-white">Nom et prix à la clinique</h2>
                    <p class="mt-0.5 text-sm text-slate-500">Quel que soit le nom donné par chaque fournisseur, ce médicament est vendu et suivi sous un seul nom, à un prix de vente propre à la clinique.</p>
                </div>
                <div class="rounded-lg bg-primary-50 px-4 py-2 text-end dark:bg-primary-950/30">
                    <p class="text-xs font-semibold text-primary-700 dark:text-primary-300">Prix de vente clinique</p>
                    <p class="text-lg font-bold tabular-nums text-primary-800 dark:text-white">{{ medicine.sale_price ? formatMoney(medicine.sale_price) : 'Non défini' }}</p>
                </div>
            </header>
            <div class="flex flex-wrap items-center gap-2 px-5 py-3 text-sm">
                <span class="text-slate-500">Nom standard :</span>
                <span class="rounded-full bg-emerald-50 px-3 py-1 font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300">{{ medicine.name }}</span>
                <ArrowLeft v-if="supplierNames.length" class="text-slate-300 h-4 w-4" />
                <span v-for="entry in supplierNames" :key="entry.supplier_uuid" class="rounded-full border border-gray-200 px-3 py-1 text-slate-600 dark:border-gray-800 dark:text-slate-300" :title="entry.supplier">{{ entry.label }}</span>
            </div>
            <div v-if="supplierNames.length" class="overflow-x-auto border-t border-gray-100 dark:border-gray-900">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold text-slate-500 dark:bg-gray-1000">
                        <tr>
                            <th class="px-5 py-3 text-start">Fournisseur</th>
                            <th class="px-4 py-3 text-start">Nom chez le fournisseur</th>
                            <th class="px-4 py-3 text-start">Référence · présentation</th>
                            <th v-if="capabilities.can_view_cost" class="px-4 py-3 text-end">Prix d’achat unitaire</th>
                            <th v-if="capabilities.can_view_cost" class="px-5 py-3 text-end">Marge sur le prix de vente</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                        <tr v-for="entry in supplierNames" :key="entry.supplier_uuid">
                            <td class="px-5 py-3 font-semibold text-slate-700 dark:text-white">{{ entry.supplier ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ entry.label }}</td>
                            <td class="px-4 py-3 text-slate-500"><span class="font-mono">{{ entry.reference || '—' }}</span><span v-if="entry.presentation"> · {{ entry.presentation }}</span></td>
                            <td v-if="capabilities.can_view_cost" class="px-4 py-3 text-end tabular-nums">{{ entry.purchase_price ? formatMoney(entry.purchase_price) : '—' }}</td>
                            <td v-if="capabilities.can_view_cost" :class="['px-5 py-3 text-end font-semibold tabular-nums', medicine.sale_price && entry.purchase_price && Number(medicine.sale_price) < Number(entry.purchase_price) ? 'text-red-600' : 'text-emerald-600']">{{ medicine.sale_price && entry.purchase_price ? formatMoney(Number(medicine.sale_price) - Number(entry.purchase_price)) : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="border-t border-gray-100 px-5 py-3 text-sm text-slate-400 dark:border-gray-900">Aucun catalogue fournisseur n’est encore rattaché à ce médicament. Rattachez une ligne depuis le catalogue d’un fournisseur pour retrouver ses noms ici.</p>
        </section>

        <section v-if="capabilities.can_view_lots" class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <header class="border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                <h2 class="font-heading text-base font-bold text-slate-800 dark:text-white">Mouvements de stock</h2>
                <p class="mt-0.5 text-sm text-slate-500">Les 50 derniers : entrées, délivrances et corrections. Un mouvement ne se modifie jamais ; une erreur se corrige par un nouveau mouvement.</p>
            </header>
            <div v-if="movements.length" class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold text-slate-500 dark:bg-gray-1000">
                        <tr>
                            <th class="px-5 py-3 text-start">Date</th>
                            <th class="px-4 py-3 text-start">Opération</th>
                            <th class="px-4 py-3 text-start">Lot</th>
                            <th class="px-4 py-3 text-end">Quantité</th>
                            <th class="px-4 py-3 text-end">Solde du lot</th>
                            <th class="px-5 py-3 text-start">Motif · par</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                        <tr v-for="movement in movements" :key="movement.id">
                            <td class="px-5 py-3 text-slate-500">{{ formatDateTime(movement.occurred_at) }}</td>
                            <td class="px-4 py-3 font-semibold text-slate-700 dark:text-white">{{ movement.type_label }}</td>
                            <td class="px-4 py-3 font-mono text-slate-600 dark:text-slate-300">{{ movement.lot_number ?? '—' }}</td>
                            <td :class="['px-4 py-3 text-end font-bold tabular-nums', movement.quantity_delta > 0 ? 'text-emerald-600' : 'text-red-600']">{{ movement.quantity_delta > 0 ? '+' : '' }}{{ formatNumber(movement.quantity_delta) }}</td>
                            <td class="px-4 py-3 text-end tabular-nums text-slate-600 dark:text-slate-300">{{ formatNumber(movement.balance_after) }}</td>
                            <td class="px-5 py-3 text-slate-500">{{ movement.reason || '—' }}<span v-if="movement.performed_by" class="block text-xs text-slate-400">{{ movement.performed_by }}</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else icon="activity" title="Aucun mouvement" description="Les entrées, délivrances et corrections de ce médicament apparaîtront ici." />
        </section>
    </div>
</template>
