<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const site = computed(() => page.props.site);

const monogram = computed(() => site.value.brand
    .split(' ')
    .filter(Boolean)
    .map((word) => word[0])
    .join('')
    .toUpperCase());
</script>

<template>
    <div class="mb-10 flex items-center gap-3">
        <span class="flex h-9 w-9 flex-none items-center justify-center rounded-md bg-primary-600 font-heading text-xs font-bold text-white">
            {{ monogram }}
        </span>
        <div class="min-w-0 leading-tight">
            <div class="truncate font-heading text-sm font-bold text-slate-700 dark:text-white">{{ site.brand }}</div>
            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Espace professionnel</div>
        </div>
    </div>
</template>
