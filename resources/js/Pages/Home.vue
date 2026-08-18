<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({
    layout: AppLayout,
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
        <h1 class="font-heading text-2xl font-bold text-slate-700 dark:text-white">
            Clinique Saint Georges
        </h1>

        <p class="mt-2 text-sm text-gray-500">
            DashWind + Laravel + Inertia + Vue
        </p>
    </div>
</template>
