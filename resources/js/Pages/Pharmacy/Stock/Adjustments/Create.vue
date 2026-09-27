<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { Save } from 'lucide-vue-next';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import { formatDate } from '@/utilities/date';
import { pharmacyUrl } from '@/utilities/pharmacyUrl';

defineOptions({ layout: AppLayout });

const props = defineProps({
    capabilities: { type: Object, required: true },
    lots: { type: Array, default: () => [] },
    adjustmentTypes: { type: Array, default: () => [] },
});

const page = usePage();
const form = useForm({
    lot_uuid: new URLSearchParams(page.url.split('?')[1] ?? '').get('lot') ?? '',
    type: 'INVENTORY',
    quantity: '',
    counted_quantity: '',
    reason: '',
});

const selectedLot = computed(() => props.lots.find((lot) => lot.uuid === form.lot_uuid));
const inputClass = 'h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
const labelClass = 'mb-1.5 block text-sm font-medium text-slate-700 dark:text-white';

const submit = () => form.post(pharmacyUrl('/pharmacy/stock/adjustments'));
const focusInvalidField = (key) => document.querySelector(`[name="${CSS.escape(key)}"]`)?.focus();
</script>

<template>
    <Head title="Corriger le stock" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Stock', href: pharmacyUrl('/pharmacy/stock') }, { label: 'Corriger le stock' }]" />

        <div>
            <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Corriger le stock</h1>
            <p class="mt-1 text-sm text-slate-500">L’historique n’est jamais effacé : la correction s’ajoute comme une nouvelle opération, avec votre nom et votre justification.</p>
        </div>

        <section v-if="!lots.length" class="rounded-xl border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <EmptyState icon="package" title="Aucun lot à corriger" description="Aucun lot n’est enregistré, ou votre compte ne permet pas de consulter les lots." />
        </section>

        <form v-else class="space-y-5 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950" @submit.prevent="submit">
            <ValidationErrorSummary :errors="form.errors" @select="focusInvalidField" />

            <label class="block">
                <span :class="labelClass">Lot concerné <span class="text-red-500">*</span></span>
                <select v-model="form.lot_uuid" name="lot_uuid" :class="inputClass" required>
                    <option value="">Choisir un lot</option>
                    <option v-for="lot in lots" :key="lot.uuid" :value="lot.uuid">{{ lot.medicine_name }} — lot {{ lot.lot_number }} ({{ lot.quantity_on_hand }} {{ lot.unit }})</option>
                </select>
                <span v-if="selectedLot" class="mt-1.5 block text-xs text-slate-500">
                    Actuellement {{ selectedLot.quantity_on_hand }} {{ selectedLot.unit }} sur l’étagère<span v-if="selectedLot.reserved_quantity">, dont {{ selectedLot.reserved_quantity }} réservé(s) — ils ne peuvent pas être retirés</span><span v-if="selectedLot.expires_at"> · périme le {{ formatDate(selectedLot.expires_at) }}</span>.
                </span>
            </label>

            <fieldset>
                <legend :class="labelClass">Raison de la correction <span class="text-red-500">*</span></legend>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label v-for="type in adjustmentTypes" :key="type.value" :class="['flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-3 text-sm font-semibold transition', form.type === type.value ? 'border-primary-500 bg-primary-50/50 text-primary-700 dark:bg-primary-950/20 dark:text-primary-300' : 'border-gray-200 text-slate-600 dark:border-gray-800 dark:text-slate-300']">
                        <input v-model="form.type" type="radio" name="type" :value="type.value">{{ type.label }}
                    </label>
                </div>
            </fieldset>

            <label v-if="form.type === 'INVENTORY'" class="block sm:w-1/2">
                <span :class="labelClass">Quantité réellement comptée <span class="text-red-500">*</span></span>
                <input v-model="form.counted_quantity" name="counted_quantity" type="number" min="0" :class="inputClass" required>
            </label>
            <label v-else class="block sm:w-1/2">
                <span :class="labelClass">Quantité à retirer <span class="text-red-500">*</span></span>
                <input v-model="form.quantity" name="quantity" type="number" min="1" :class="inputClass" required>
            </label>

            <label class="block">
                <span :class="labelClass">Justification <span class="text-red-500">*</span></span>
                <textarea v-model="form.reason" name="reason" rows="3" maxlength="2000" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Ex. inventaire du 14/09, 2 boîtes cassées à la livraison" required />
            </label>

            <div class="flex flex-col-reverse gap-2 border-t border-gray-100 pt-4 dark:border-gray-900 sm:flex-row sm:justify-end">
                <Button :as="Link" :href="pharmacyUrl('/pharmacy/stock')" size="lg" variant="white-outline">Annuler</Button>
                <Button type="submit" size="lg" :disabled="form.processing"><Save class="h-4 w-4" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : 'Enregistrer la correction' }}</span></Button>
            </div>
        </form>
    </div>
</template>
