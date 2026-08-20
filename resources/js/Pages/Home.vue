<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({
    layout: AppLayout,
});
</script>

<template>
    <Head title="Tableau de bord" />

    <div class="space-y-6">
        <!-- Page heading -->
        <div>
            <h1
                class="font-heading text-2xl font-bold
                       text-slate-700 dark:text-white"
            >
                Tableau de bord
            </h1>

            <p class="mt-1 text-sm text-slate-500">
                Vue générale de la Clinique Saint Georges
            </p>
        </div>

        <!-- Cards -->
        <div
            class="grid grid-cols-1 gap-5
                   sm:grid-cols-2 xl:grid-cols-4"
        >
            <div
                class="rounded-lg border border-gray-200
                       bg-white p-5 dark:border-gray-900
                       dark:bg-gray-950"
            >
                <div class="text-sm text-slate-500">
                    Patients aujourd'hui
                </div>

                <div
                    class="mt-2 font-heading text-2xl
                           font-bold text-slate-700
                           dark:text-white"
                >
                    —
                </div>
            </div>

            <div
                class="rounded-lg border border-gray-200
                       bg-white p-5 dark:border-gray-900
                       dark:bg-gray-950"
            >
                <div class="text-sm text-slate-500">
                    Consultations
                </div>

                <div
                    class="mt-2 font-heading text-2xl
                           font-bold text-slate-700
                           dark:text-white"
                >
                    —
                </div>
            </div>

            <div
                class="rounded-lg border border-gray-200
                       bg-white p-5 dark:border-gray-900
                       dark:bg-gray-950"
            >
                <div class="text-sm text-slate-500">
                    Analyses laboratoire
                </div>

                <div
                    class="mt-2 font-heading text-2xl
                           font-bold text-slate-700
                           dark:text-white"
                >
                    —
                </div>
            </div>

            <div
                class="rounded-lg border border-gray-200
                       bg-white p-5 dark:border-gray-900
                       dark:bg-gray-950"
            >
                <div class="text-sm text-slate-500">
                    Recettes du jour
                </div>

                <div
                    class="mt-2 font-heading text-2xl
                           font-bold text-slate-700
                           dark:text-white"
                >
                    —
                </div>
            </div>
        </div>
    </div>
</template>
