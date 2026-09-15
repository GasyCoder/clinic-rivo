<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/UI/Icon.vue';

defineProps({
    // [{ label, href? }] — the last item is the current page.
    items: { type: Array, required: true },
});
</script>

<template>
    <nav aria-label="Fil d’Ariane" class="flex flex-wrap items-center gap-1.5 text-sm">
        <template v-for="(item, index) in items" :key="index">
            <Icon v-if="index > 0" name="chevron-right" class="text-xs text-slate-300 dark:text-slate-600" />
            <Link
                v-if="item.href && index < items.length - 1"
                :href="item.href"
                class="font-medium text-slate-500 transition-colors hover:text-primary-600 dark:text-slate-400"
            >
                {{ item.label }}
            </Link>
            <span v-else class="font-semibold text-slate-700 dark:text-white" aria-current="page">{{ item.label }}</span>
        </template>
    </nav>
</template>
