<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import MedicineForm from '@/Components/Pharmacy/MedicineForm.vue';
import MedicineStatusPanel from '@/Components/Pharmacy/MedicineStatusPanel.vue';
import { pharmacyUrl } from '@/utilities/pharmacyUrl';

defineOptions({ layout: AppLayout });

defineProps({
    capabilities: { type: Object, required: true },
    medicine: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    medicineForms: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});
</script>

<template>
    <Head :title="`Modifier ${medicine.name}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Médicaments & stock', href: pharmacyUrl('/pharmacy/stock') }, { label: medicine.name, href: pharmacyUrl(`/pharmacy/stock/${medicine.uuid}`) }, { label: 'Modifier' }]" />

        <div>
            <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Modifier {{ medicine.name }}</h1>
            <p class="mt-1 text-sm text-slate-500">Les ordonnances, ventes et lots déjà enregistrés ne sont pas modifiés.</p>
        </div>

        <MedicineForm
            :medicine="medicine"
            :categories="categories"
            :suppliers="suppliers"
            :medicine-forms="medicineForms"
            :submit-url="pharmacyUrl(`/pharmacy/medicines/${medicine.uuid}`)"
            :cancel-href="pharmacyUrl('/pharmacy/stock')"
            :can-change-price="can.change_price"
        />

        <MedicineStatusPanel :medicine="medicine" :can="can" :base-url="pharmacyUrl(`/pharmacy/medicines/${medicine.uuid}`)" />
    </div>
</template>
