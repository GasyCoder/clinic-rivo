<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Icon from '@/Components/UI/Icon.vue';

const page = usePage();
const site = computed(() => page.props.site);

const otherSites = ['Mampikony', 'Ambondromamy', 'Boriziny'].filter(
    (name) => name.toUpperCase() !== site.value.name?.toUpperCase(),
);

const monogram = computed(() => site.value.brand
    .split(' ')
    .filter(Boolean)
    .map((word) => word[0])
    .join('')
    .toUpperCase());

// DashWind's own auth-login pattern: a hidden lg:hidden toggle button opens
// this panel as a mobile drawer; from lg: up it is always visible, static,
// occupying the remaining row width (see [&.active]:transform-none /
// lg:transform-none below — this is template/vue/src/views/auths/Login.vue
// ported structurally, content replaced with our real site identity
// instead of DashWind's own product screenshots/carousel.
const open = ref(false);
</script>

<template>
    <div class="absolute end-0 top-0 z-10 p-5 sm:p-11 lg:hidden">
        <button
            type="button"
            class="relative inline-flex h-9 w-9 items-center justify-center rounded border border-gray-300 bg-white text-center align-middle text-sm font-bold leading-4.5 tracking-wide text-slate-600 transition-all duration-300 hover:border-slate-600 hover:bg-slate-600 hover:text-white dark:border-gray-900 dark:bg-gray-900 dark:text-slate-200 dark:hover:border-gray-800 dark:hover:bg-gray-800"
            @click="open = true"
        >
            <Icon name="info" class="text-xl" />
        </button>
    </div>

    <div
        :class="[
            'peer fixed end-0 top-0 z-[999] flex min-h-screen w-full max-w-[calc(100%-2.5rem)] min-w-[260px] flex-shrink flex-grow flex-col bg-gray-50 transition-transform duration-500 dark:bg-gray-900 lg:static lg:flex lg:!translate-x-0 lg:transition-none',
            open ? 'translate-x-0' : 'translate-x-full rtl:-translate-x-full',
        ]"
    >
        <div class="m-auto w-full max-w-[420px] p-8 text-center sm:p-11">
            <span class="mx-auto mb-6 inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-primary-600 font-heading text-xl font-bold text-white">
                {{ monogram }}
            </span>

            <div
                v-if="site.name"
                class="font-heading text-3xl font-bold uppercase leading-tight tracking-tight text-slate-700 dark:text-white"
            >
                {{ site.name }}
            </div>
            <p class="mt-4 text-sm leading-6 text-slate-500 dark:text-slate-400">
                {{ site.brand }} — plateforme de gestion clinique. Réception, médecine, chirurgie, laboratoire et pharmacie réunis dans un même espace.
            </p>

            <div v-if="otherSites.length" class="mt-8 flex flex-wrap justify-center gap-2">
                <span
                    v-for="name in otherSites"
                    :key="name"
                    class="rounded-full border border-gray-200 px-3.5 py-1.5 text-xs font-bold uppercase tracking-wide text-slate-500 dark:border-gray-800 dark:text-slate-400"
                >
                    {{ name }}
                </span>
            </div>
        </div>
    </div>

    <div
        class="fixed inset-0 z-[900] bg-slate-950/20 opacity-0 transition-all duration-300 lg:!invisible lg:!opacity-0"
        :class="open ? 'visible opacity-100' : 'invisible'"
        @click="open = false"
    />
</template>
