<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DispenseDeliveryWorkspace from '@/Pages/Pharmacy/Partials/DispenseDeliveryWorkspace.vue';
import DispenseQueue from '@/Pages/Pharmacy/Partials/DispenseQueue.vue';

defineOptions({ layout: AppLayout });

defineProps({
    capabilities: { type: Object, required: true },
    queue: { type: Object, required: true },
});

const deliveryTarget = ref(null);
const deliveryForm = useForm({ lines: [], notes: '' });

const prepareInvoice = (dispense) => router.post(`/pharmacy/dispenses/${dispense.uuid}/invoice`, {}, { preserveScroll: true });

const openDelivery = (dispense) => {
    deliveryTarget.value = dispense;
    deliveryForm.clearErrors();
    deliveryForm.lines = dispense.lines
        .filter((line) => line.remaining_quantity > 0)
        .map((line) => ({ uuid: line.uuid, quantity: line.remaining_quantity }));
    deliveryForm.notes = '';
};

const submitDelivery = () => deliveryForm
    .transform((data) => ({
        ...data,
        lines: data.lines
            .filter((line) => Number(line.quantity) > 0)
            .map((line) => ({ uuid: line.uuid, quantity: Number(line.quantity) })),
    }))
    .post(`/pharmacy/dispenses/${deliveryTarget.value.uuid}/deliveries`, {
        preserveScroll: true,
        onSuccess: () => { deliveryTarget.value = null; },
    });
</script>

<template>
    <Head title="Ordonnances à délivrer" />

    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Pharmacie"
            title="Ordonnances à délivrer"
            description="Un médicament ne sort de l’étagère qu’une fois le ticket réglé ou pris en charge à la Caisse."
            icon="file-docs"
            tone="primary"
        >
            <template #actions>
                <Button v-if="capabilities.can_create_counter_sale" :as="Link" href="/pharmacy/counter-sales/create" size="rg" variant="white-outline">
                    <Icon name="cart" /><span class="ms-2">Nouvelle vente comptoir</span>
                </Button>
            </template>
        </PageHeader>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <DispenseQueue
                :dispenses="queue.dispenses ?? []"
                :summary="queue.summary ?? {}"
                :capabilities="capabilities"
                @prepare-invoice="prepareInvoice"
                @deliver="openDelivery"
            />
        </section>

        <DispenseDeliveryWorkspace
            :target="deliveryTarget"
            :form="deliveryForm"
            :can-view-lots="capabilities.can_view_lots"
            :can-view-expiration="capabilities.can_view_expiration"
            @close="deliveryTarget = null"
            @submit="submitDelivery"
        />
    </div>
</template>
