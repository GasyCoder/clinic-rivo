<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import Icon from '@/Components/UI/Icon.vue';
import MedicineFamilies from '@/Components/Pharmacy/MedicineFamilies.vue';

defineOptions({ layout: AppLayout });

defineProps({
    targetSite: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    error: { type: String, default: null },
    can: { type: Object, default: () => ({}) },
});
</script>

<template>
    <Head :title="`Familles de médicaments · ${targetSite.name}`" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Stock médicaments', href: '/super-admin/stock' }, { label: targetSite.name }, { label: 'Familles' }]" />

        <div class="flex items-center gap-3">
            <Icon name="folder-fill" class="text-4xl leading-none text-amber-400" />
            <div>
                <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Familles de médicaments</h1>
                <p class="text-sm text-slate-500">Classement des médicaments de {{ targetSite.name }}, par exemple « Amoxicilline » ou « Antalgiques ».</p>
            </div>
        </div>

        <section v-if="error" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
            <Icon name="alert" class="mt-0.5 text-lg" /><p>{{ error }}</p>
        </section>

        <section v-else class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <MedicineFamilies :categories="categories" :can="can" :base-url="`/super-admin/stock/${targetSite.code}/categories`" />
        </section>
    </div>
</template>
