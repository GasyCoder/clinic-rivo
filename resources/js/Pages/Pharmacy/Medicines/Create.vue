<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import MedicineForm from '@/Components/Pharmacy/MedicineForm.vue';

defineOptions({ layout: AppLayout });

defineProps({
    capabilities: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    medicineForms: { type: Array, default: () => [] },
});

const query = new URLSearchParams(usePage().url.split('?')[1] ?? '');
</script>

<template>
    <Head title="Ajouter un médicament" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Médicaments & stock', href: '/pharmacy/stock' }, { label: 'Ajouter un médicament' }]" />

        <div>
            <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Ajouter un médicament</h1>
            <p class="mt-1 text-sm text-slate-500">Le médicament rejoint le catalogue de la clinique avec son prix de vente. Le prix d’achat, lui, est noté à chaque réception.</p>
        </div>

        <MedicineForm
            :categories="categories"
            :suppliers="suppliers"
            :medicine-forms="medicineForms"
            submit-url="/pharmacy/setup/medicines"
            cancel-href="/pharmacy/stock"
            :supplier-catalog-item-uuid="query.get('supplier_catalog_item') ?? ''"
            :initial-name="query.get('name') ?? ''"
        />
    </div>
</template>
