<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { pharmacyUrl } from '@/utilities/pharmacyUrl';

/**
 * ADR-098 — « Achats »: orders, goods awaiting reception, receptions and
 * supplier invoices are one purchasing circuit. Each list keeps its own
 * address; this header turns them into tabs of the same page.
 */
const props = defineProps({
    active: { type: String, required: true }, // orders | to-receive | receipts | invoices
    purchases: { type: Object, required: true },
});

const tabs = computed(() => [
    { key: 'orders', label: 'Commandes', href: pharmacyUrl('/pharmacy/purchase-orders'), show: props.purchases.can?.orders, count: props.purchases.counts?.orders },
    { key: 'to-receive', label: 'À réceptionner', href: pharmacyUrl('/pharmacy/purchase-orders?status=TO_RECEIVE'), show: props.purchases.can?.orders, count: props.purchases.counts?.to_receive, highlight: true },
    { key: 'receipts', label: 'Réceptions', href: pharmacyUrl('/pharmacy/receipts'), show: props.purchases.can?.receipts, count: props.purchases.counts?.receipts },
    { key: 'invoices', label: 'Factures fournisseurs', href: pharmacyUrl('/pharmacy/supplier-invoices'), show: props.purchases.can?.invoices, count: props.purchases.counts?.invoices },
].filter((tab) => tab.show));
</script>

<template>
    <div class="space-y-4">
        <PageHeader
            eyebrow="Pharmacie"
            title="Achats"
            description="Commandes, arrivées de marchandise et factures des fournisseurs. Une commande n’ajoute rien au stock : c’est la réception qui le fait."
            icon="truck"
            tone="violet"
        >
            <template #actions>
                <slot name="actions" />
            </template>
        </PageHeader>

        <nav class="flex max-w-full gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-900" aria-label="Achats">
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="tab.href"
                :aria-current="tab.key === active ? 'page' : undefined"
                :class="['-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-semibold transition', tab.key === active ? 'border-primary-600 text-primary-700 dark:text-primary-300' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-white']"
            >
                {{ tab.label }}
                <span v-if="tab.count !== null && tab.count !== undefined" :class="['rounded-full px-1.5 text-xs', tab.highlight && tab.count ? 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' : 'bg-gray-100 text-slate-500 dark:bg-gray-900']">{{ tab.count }}</span>
            </Link>
        </nav>
    </div>
</template>
