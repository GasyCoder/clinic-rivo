<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import { TriangleAlert } from 'lucide-vue-next';
import MedicineForm from '@/Components/Pharmacy/MedicineForm.vue';
import MedicineStatusPanel from '@/Components/Pharmacy/MedicineStatusPanel.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    targetSite: { type: Object, required: true },
    medicine: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    medicineForms: { type: Array, default: () => [] },
    error: { type: String, default: null },
    can: { type: Object, default: () => ({}) },
});

const baseUrl = `/super-admin/stock/${props.targetSite.code}/medicines/${props.medicine?.uuid}`;
</script>

<template>
    <Head :title="medicine ? `Modifier ${medicine.name}` : 'Modifier un médicament'" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Stock médicaments', href: '/super-admin/stock' }, { label: targetSite.name }, { label: medicine?.name ?? 'Médicament' }]" />

        <section v-if="error" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
            <TriangleAlert class="mt-0.5 h-4.5 w-4.5" /><p>{{ error }}</p>
        </section>

        <template v-else-if="medicine">
            <div>
                <h1 class="font-heading text-2xl font-bold text-foreground">Modifier {{ medicine.name }}</h1>
                <p class="mt-1 text-sm text-muted-foreground">Enregistré sur le site {{ targetSite.name }}, à votre nom. Les ordonnances, ventes et lots déjà enregistrés ne sont pas modifiés.</p>
            </div>

            <MedicineForm
                :medicine="medicine"
                :categories="categories"
                :suppliers="suppliers"
                :medicine-forms="medicineForms"
                :submit-url="baseUrl"
                cancel-href="/super-admin/stock"
                :can-change-price="can.change_price"
            />

            <MedicineStatusPanel :medicine="medicine" :can="can" :base-url="baseUrl" />
        </template>
    </div>
</template>
