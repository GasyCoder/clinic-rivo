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
    <section class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
        <header class="border-b border-border px-5 py-4">
            <h2 class="font-heading text-base font-bold text-foreground">Prix actuels</h2>
        </header>
        <div v-if="offers.length" class="overflow-x-auto">
            <table class="w-full min-w-[600px] text-sm">
                <thead class="bg-muted text-xs font-semibold text-muted-foreground">
                    <tr>
                        <th class="px-5 py-3 text-start">Médicament</th>
                        <th class="px-4 py-3 text-start">Référence chez le fournisseur</th>
                        <th class="px-4 py-3 text-end">Prix d’achat</th>
                        <th class="px-5 py-3 text-start">Depuis le</th>
                        <th v-if="stockHref" class="px-5 py-3 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="offer in offers" :key="offer.uuid">
                        <td class="px-5 py-3.5"><span class="font-semibold text-foreground">{{ offer.medicine_name }}</span> <span class="font-mono text-xs text-muted-foreground">{{ offer.medicine_code }}</span></td>
                        <td class="px-4 py-3.5 text-muted-foreground">{{ offer.supplier_label || '—' }}<span v-if="offer.supplier_reference" class="block font-mono text-xs text-muted-foreground">{{ offer.supplier_reference }}</span></td>
                        <td class="px-4 py-3.5 text-end font-semibold tabular-nums text-foreground">{{ formatMoney(offer.quoted_price) }}</td>
                        <td class="px-5 py-3.5 text-muted-foreground">{{ formatDate(offer.effective_from) }}</td>
                        <td v-if="stockHref" class="px-5 py-3.5 text-end"><Link :href="stockHref(offer.medicine_uuid)" class="text-sm font-semibold text-primary hover:underline">Voir le stock</Link></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else icon="capsule" title="Aucun prix enregistré" description="Rattachez les lignes d’un catalogue aux médicaments de la clinique pour enregistrer les prix de ce fournisseur." />
    </section>

    <section v-if="history.length" class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
        <header class="border-b border-border px-5 py-4">
            <h2 class="font-heading text-base font-bold text-foreground">Anciens prix</h2>
            <p class="mt-0.5 text-sm text-muted-foreground">Conservés tels qu’ils étaient, pour savoir combien un médicament a été acheté à une date donnée.</p>
        </header>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-muted text-xs font-semibold text-muted-foreground">
                    <tr>
                        <th class="px-5 py-3 text-start">Médicament</th>
                        <th class="px-4 py-3 text-end">Prix</th>
                        <th class="px-4 py-3 text-start">Période</th>
                        <th class="px-5 py-3 text-start">Motif du changement</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="offer in history" :key="offer.uuid">
                        <td class="px-5 py-3.5 font-medium text-foreground">{{ offer.medicine_name }}</td>
                        <td class="px-4 py-3.5 text-end tabular-nums text-muted-foreground">{{ formatMoney(offer.quoted_price) }}</td>
                        <td class="px-4 py-3.5 text-muted-foreground">du {{ formatDate(offer.effective_from) }} au {{ formatDate(offer.effective_until) }}</td>
                        <td class="px-5 py-3.5 text-muted-foreground">{{ offer.change_reason || '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
