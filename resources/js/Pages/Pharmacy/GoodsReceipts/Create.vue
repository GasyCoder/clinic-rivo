<script setup>
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({ order: Object, can: Object });

const form = useForm({
    notes: '',
    lines: props.order.lines.map((line) => ({
        purchase_order_line_id: line.id,
        quantity_received: line.quantity_remaining,
        lot_number: '',
        expires_at: '',
        unit_purchase_price: line.unit_price,
        _medicine_name: line.medicine_name,
        _medicine_code: line.medicine_code,
        _quantity_remaining: line.quantity_remaining,
    })),
});

const inputClass = 'h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300';

const submit = () => {
    form.transform((data) => ({
        ...data,
        // A line left at 0 means "nothing arrived this time", not an error.
        lines: data.lines
            .filter((line) => Number(line.quantity_received) > 0)
            .map(({ _medicine_name, _medicine_code, _quantity_remaining, ...line }) => line),
    })).post(`/pharmacy/purchase-orders/${props.order.uuid}/receipts`);
};
</script>

<template>
    <Head :title="`Réceptionner ${order.order_number}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Commandes', href: '/pharmacy/purchase-orders' }, { label: order.order_number, href: `/pharmacy/purchase-orders/${order.uuid}` }, { label: 'Réceptionner' }]" />

        <div>
            <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Réceptionner la marchandise</h1>
            <p class="mt-1 text-sm text-slate-500">{{ order.supplier }} · commande {{ order.order_number }}. Recopiez le numéro de lot et la péremption inscrits sur les boîtes, et indiquez la quantité réellement arrivée.</p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <article v-for="(line, index) in form.lines" :key="line.purchase_order_line_id" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="font-semibold text-slate-800 dark:text-white">{{ line._medicine_name }} <span class="font-mono text-xs font-normal text-slate-400">{{ line._medicine_code }}</span></h2>
                    <span class="text-sm text-slate-500">Encore attendu : <strong class="text-slate-700 dark:text-white">{{ line._quantity_remaining }}</strong></span>
                </div>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="block">
                        <span :class="labelClass">Quantité arrivée</span>
                        <input v-model.number="line.quantity_received" type="number" min="0" :max="line._quantity_remaining" :class="inputClass">
                    </label>
                    <label class="block">
                        <span :class="labelClass">Numéro de lot</span>
                        <input v-model="line.lot_number" type="text" :class="inputClass" :required="Number(line.quantity_received) > 0">
                    </label>
                    <label class="block">
                        <span :class="labelClass">Péremption</span>
                        <DatePicker v-model="line.expires_at" :required="Number(line.quantity_received) > 0" />
                    </label>
                    <label v-if="can.record_cost" class="block">
                        <span :class="labelClass">Prix d’achat par unité (MGA)</span>
                        <input v-model="line.unit_purchase_price" type="number" min="0" step="0.01" :class="inputClass">
                    </label>
                </div>
                <p v-if="form.errors[`lines.${index}.lot_number`] || form.errors[`lines.${index}.expires_at`]" class="mt-2 text-sm text-red-600">{{ form.errors[`lines.${index}.lot_number`] || form.errors[`lines.${index}.expires_at`] }}</p>
            </article>

            <p v-if="form.errors.lines || form.errors.status" class="text-sm text-red-600">{{ form.errors.lines || form.errors.status }}</p>

            <label class="block max-w-xl">
                <span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Remarque</span>
                <input v-model="form.notes" type="text" :class="inputClass" placeholder="Ex. 2 cartons abîmés">
            </label>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button :as="Link" :href="`/pharmacy/purchase-orders/${order.uuid}`" size="lg" variant="white-outline">Annuler</Button>
                <Button type="submit" size="lg" :disabled="form.processing"><Icon name="package" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : 'Ajouter au stock' }}</span></Button>
            </div>
        </form>
    </div>
</template>
