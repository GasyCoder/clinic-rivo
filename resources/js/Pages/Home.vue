<script setup>
import { Head } from '@inertiajs/vue3'
</script>

<template>
    <Head title="Accueil" />

    <div class="min-h-screen bg-gray-50">
        <main class="mx-auto max-w-7xl px-6 py-16">
            <div class="rounded-lg bg-white p-8 shadow-sm">
                <h1 class="text-3xl font-bold text-gray-900">
                    RIVO — Clinique Saint Georges
                </h1>

                <p class="mt-4 text-gray-600">
                    Laravel 13 + Vue 3 + Inertia 3
                </p>

                <div class="mt-6">
                    <span
                        class="inline-flex rounded-full bg-green-100 px-3 py-1 text-sm font-medium text-green-700"
                    >
                        Frontend opérationnel
                    </span>
                </div>
            </div>
        </main>
    </div>
</template>
