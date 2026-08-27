<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ExternalCounterSaleWorkspace from '@/Pages/Pharmacy/Partials/ExternalCounterSaleWorkspace.vue';
import PharmacyWorkspaceNav from '@/Pages/Pharmacy/Partials/PharmacyWorkspaceNav.vue';

defineOptions({ layout: AppLayout });

defineProps({
    navigation: { type: Object, required: true },
    medicines: { type: Array, default: () => [] },
});

const form = useForm({
    customer_name: '',
    customer_phone: '',
    external_prescription_reference: '',
    external_prescriber: '',
    lines: [],
});

const returnToDispenses = () => router.visit('/pharmacy?tab=dispenses');
const submit = () => form.post('/pharmacy/counter-sales');
</script>

<template>
    <Head title="Nouvelle demande comptoir" />

    <div class="w-full space-y-5">
        <PharmacyWorkspaceNav
            :capabilities="navigation"
            :dispense-count="navigation.dispense_count ?? 0"
            link-mode
        />

        <ExternalCounterSaleWorkspace
            :visible="true"
            :form="form"
            :medicines="medicines"
            @close="returnToDispenses"
            @submit="submit"
        />
    </div>
</template>
