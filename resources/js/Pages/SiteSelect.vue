<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Icon from '@/Components/UI/Icon.vue';

defineOptions({
    layout: GuestLayout,
});

defineProps({
    clinics: {
        type: Array,
        required: true,
    },
});

const page = usePage();

const year = new Date().getFullYear();
</script>

<template>
    <Head title="Choisir votre site" />

    <div class="relative flex min-h-screen items-center justify-center px-5 py-10">
        <div class="w-full max-w-[480px]">
            <div class="mb-8 flex flex-col items-center text-center">
                <div
                    class="mb-5 inline-flex h-14 w-14 flex-none items-center justify-center rounded-2xl bg-primary-50 text-primary-600 dark:bg-primary-950"
                >
                    <em class="ni ni-plus-medi-fill text-2xl leading-none" />
                </div>

                <h1 class="font-heading text-xl font-bold leading-tighter -tracking-snug text-slate-700 dark:text-white">
                    {{ page.props.site.brand }}
                </h1>
                <p class="mt-2 text-sm leading-6 text-slate-400">
                    Choisissez votre site.
                </p>
            </div>

            <ul class="space-y-3">
                <li v-for="clinic in clinics" :key="clinic.code">
                    <a
                        :href="`${clinic.url}/login`"
                        class="group flex items-center gap-4 rounded-md border border-gray-200 bg-white p-5 transition-all duration-300 hover:border-primary-500 hover:shadow-sm dark:border-gray-900 dark:bg-gray-950 dark:hover:border-primary-600"
                    >
                        <span
                            class="inline-flex h-11 w-11 flex-none items-center justify-center rounded-full bg-gray-100 text-slate-500 transition-colors duration-300 group-hover:bg-primary-50 group-hover:text-primary-600 dark:bg-gray-900 dark:text-slate-400 dark:group-hover:bg-primary-950 dark:group-hover:text-primary-500"
                        >
                            <Icon name="building" class="text-lg leading-none" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block text-xs text-slate-400">{{ page.props.site.brand }}</span>
                            <span class="block truncate font-heading text-sm font-bold text-slate-700 dark:text-white">
                                {{ clinic.name }}
                            </span>
                        </span>

                        <Icon
                            name="chevron-right"
                            class="flex-none text-lg leading-none text-slate-300 transition-colors duration-300 group-hover:text-primary-500 rtl:-scale-x-100"
                        />
                    </a>
                </li>
            </ul>

            <div class="mt-8 text-center text-xs text-slate-400">
                © {{ year }} {{ page.props.site.brand }}
            </div>
        </div>
    </div>
</template>
