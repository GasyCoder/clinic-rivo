<script setup>
import { Link } from '@inertiajs/vue3';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { formatDate } from '@/utilities/date';
import { formatMoney } from '@/utilities/pharmacyStatus';

/**
 * ADR-098 — a supplier's current prices and their history, shared by the
 * clinic's supplier folder and the central portal's. An old price is never
 * erased, so both screens show it the same way.
 */
defineProps({
    offers: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
    // Clinic only: a link to the medicine's stock (the portal has no stock page).
    stockHref: { type: Function, default: null },
});
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
        <header class="border-b border-gray-200 px-5 py-4 dark:border-gray-900">
            <h2 class="font-heading text-base font-bold text-slate-800 dark:text-white">Prix actuels</h2>
        </header>
        <div v-if="offers.length" class="overflow-x-auto">
            <table class="w-full min-w-[600px] text-sm">
                <thead class="bg-gray-50 text-xs font-semibold text-slate-500 dark:bg-gray-1000">
                    <tr>
                        <th class="px-5 py-3 text-start">Médicament</th>
                        <th class="px-4 py-3 text-start">Référence chez le fournisseur</th>
                        <th class="px-4 py-3 text-end">Prix d’achat</th>
                        <th class="px-5 py-3 text-start">Depuis le</th>
                        <th v-if="stockHref" class="px-5 py-3 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                    <tr v-for="offer in offers" :key="offer.uuid">
                        <td class="px-5 py-3.5"><span class="font-semibold text-slate-800 dark:text-white">{{ offer.medicine_name }}</span> <span class="font-mono text-xs text-slate-400">{{ offer.medicine_code }}</span></td>
                        <td class="px-4 py-3.5 text-slate-600 dark:text-slate-300">{{ offer.supplier_label || '—' }}<span v-if="offer.supplier_reference" class="block font-mono text-xs text-slate-400">{{ offer.supplier_reference }}</span></td>
                        <td class="px-4 py-3.5 text-end font-semibold tabular-nums text-slate-800 dark:text-white">{{ formatMoney(offer.quoted_price) }}</td>
                        <td class="px-5 py-3.5 text-slate-500">{{ formatDate(offer.effective_from) }}</td>
                        <td v-if="stockHref" class="px-5 py-3.5 text-end"><Link :href="stockHref(offer.medicine_uuid)" class="text-sm font-semibold text-primary-600 hover:underline">Voir le stock</Link></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else icon="capsule" title="Aucun prix enregistré" description="Rattachez les lignes d’un catalogue aux médicaments de la clinique pour enregistrer les prix de ce fournisseur." />
    </section>

    <section v-if="history.length" class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
        <header class="border-b border-gray-200 px-5 py-4 dark:border-gray-900">
            <h2 class="font-heading text-base font-bold text-slate-800 dark:text-white">Anciens prix</h2>
            <p class="mt-0.5 text-sm text-slate-500">Conservés tels qu’ils étaient, pour savoir combien un médicament a été acheté à une date donnée.</p>
        </header>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-gray-50 text-xs font-semibold text-slate-500 dark:bg-gray-1000">
                    <tr>
                        <th class="px-5 py-3 text-start">Médicament</th>
                        <th class="px-4 py-3 text-end">Prix</th>
                        <th class="px-4 py-3 text-start">Période</th>
                        <th class="px-5 py-3 text-start">Motif du changement</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                    <tr v-for="offer in history" :key="offer.uuid">
                        <td class="px-5 py-3.5 font-medium text-slate-700 dark:text-slate-200">{{ offer.medicine_name }}</td>
                        <td class="px-4 py-3.5 text-end tabular-nums text-slate-600 dark:text-slate-300">{{ formatMoney(offer.quoted_price) }}</td>
                        <td class="px-4 py-3.5 text-slate-500">du {{ formatDate(offer.effective_from) }} au {{ formatDate(offer.effective_until) }}</td>
                        <td class="px-5 py-3.5 text-slate-500">{{ offer.change_reason || '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
