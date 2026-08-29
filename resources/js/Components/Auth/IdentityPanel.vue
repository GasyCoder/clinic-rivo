<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const site = computed(() => page.props.site);
const coverUrl = computed(() => site.value.authCoverUrl || null);
const isAdminPortal = computed(() => site.value.type === 'admin');
const siteLabel = computed(() => isAdminPortal.value
    ? 'Super Administration'
    : site.value.name);
</script>

<template>
    <aside class="absolute inset-0 z-0 overflow-hidden bg-primary-950" aria-hidden="true">
        <img
            v-if="coverUrl"
            :src="coverUrl"
            alt=""
            class="h-full w-full object-cover object-center"
        />
        <div class="absolute inset-0 bg-primary-950/35"></div>
        <div class="absolute inset-0 bg-gradient-to-t from-primary-950/75 via-transparent to-primary-950/15"></div>
        <div class="absolute inset-0 bg-gradient-to-r from-primary-950/20 via-transparent to-primary-950/35"></div>

        <div class="absolute bottom-9 start-9 hidden max-w-lg text-white lg:block xl:bottom-12 xl:start-14">
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-white/75">
                {{ site.brand }} · {{ siteLabel }}
            </p>
            <p class="mt-2 font-heading text-xl font-semibold tracking-tight xl:text-2xl">
                Ny fahasalamana no loharanon-karena
            </p>
        </div>
    </aside>
</template>
