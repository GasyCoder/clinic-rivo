<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const site = computed(() => page.props.site);
const logoUrl = computed(() => site.value.documents?.logo_url || null);

const monogram = computed(() => site.value.brand
    .split(' ')
    .filter(Boolean)
    .map((word) => word[0])
    .join('')
    .toUpperCase());
</script>

<template>
    <div class="mb-8 flex min-h-16 items-center">
        <img
            v-if="logoUrl"
            :src="logoUrl"
            :alt="`Logo ${site.brand}`"
            class="h-auto w-full max-w-[270px] object-contain object-left"
        />
        <div v-else class="flex items-center gap-3">
            <span class="flex h-11 w-11 flex-none items-center justify-center rounded-md bg-primary-800 font-heading text-sm font-bold text-white">
                {{ monogram }}
            </span>
            <div class="font-heading text-sm font-bold text-slate-700 dark:text-white">{{ site.brand }}</div>
        </div>
    </div>
</template>
