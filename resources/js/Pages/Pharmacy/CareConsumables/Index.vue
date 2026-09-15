<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import CareConsumableQueue from '@/Pages/Pharmacy/Partials/CareConsumableQueue.vue';

defineOptions({ layout: AppLayout });

defineProps({
    capabilities: { type: Object, required: true },
    careConsumables: { type: Object, required: true },
});

const search = ref('');
</script>

<template>
    <Head title="Consommables Soins" />

    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Pharmacie"
            title="Consommables Soins"
            description="Le matériel déjà utilisé sur un patient aux Soins. Sa sortie du stock n’attend aucun règlement."
            icon="user-check"
            tone="amber"
        >
            <template #actions>
                <label class="relative block w-full sm:w-80">
                    <span class="sr-only">Rechercher une demande</span>
                    <Icon class="pointer-events-none absolute inset-y-0 start-3 my-auto text-lg text-slate-400" name="search" />
                    <input v-model="search" type="search" class="h-10 w-full rounded-lg border border-gray-200 bg-white ps-10 pe-3 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Patient, passage ou matériel…">
                </label>
            </template>
        </PageHeader>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <CareConsumableQueue :care-consumables="careConsumables" :capabilities="capabilities" :search="search" />
        </section>
    </div>
</template>
