<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import { formatDate, formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/pharmacyStatus';

defineOptions({ layout: AppLayout });

defineProps({ receipt: Object });
</script>

<template>
    <Head :title="`Réception ${receipt.receipt_number}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Réceptions', href: '/pharmacy/receipts' }, { label: receipt.receipt_number }]" />

        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Réception {{ receipt.receipt_number }}</h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ receipt.supplier }} · commande
                <Link :href="`/pharmacy/purchase-orders/${receipt.order_uuid}`" class="font-semibold text-primary-600 hover:underline">{{ receipt.order_number }}</Link>
                · reçue par {{ receipt.received_by }} le {{ formatDateTime(receipt.received_at) }}
            </p>
            <p v-if="receipt.notes" class="mt-1 text-sm text-slate-500">{{ receipt.notes }}</p>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[600px] text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold text-slate-500 dark:bg-gray-1000">
                        <tr>
                            <th class="px-5 py-3 text-start">Médicament</th>
                            <th class="px-4 py-3 text-start">Lot</th>
                            <th class="px-4 py-3 text-start">Péremption</th>
                            <th class="px-4 py-3 text-end">Quantité</th>
                            <th class="px-4 py-3 text-end">Prix d’achat</th>
                            <th class="px-5 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                        <tr v-for="(line, index) in receipt.lines" :key="index">
                            <td class="px-5 py-3.5 font-semibold text-slate-800 dark:text-white">{{ line.medicine_name }}</td>
                            <td class="px-4 py-3.5 font-mono">{{ line.lot_number }}</td>
                            <td class="px-4 py-3.5">{{ formatDate(line.expires_at) }}</td>
                            <td class="px-4 py-3.5 text-end tabular-nums">{{ line.quantity_received }}</td>
                            <td class="px-4 py-3.5 text-end tabular-nums">{{ line.unit_purchase_price ? formatMoney(line.unit_purchase_price) : '—' }}</td>
                            <td class="px-5 py-3.5 text-end"><Link :href="`/pharmacy/stock/${line.medicine_uuid}`" class="text-sm font-semibold text-primary-600 hover:underline">Voir le stock</Link></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
