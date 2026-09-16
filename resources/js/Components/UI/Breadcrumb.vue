<script setup>
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';

defineProps({
    // [{ label, href? }] — the last item is the current page.
    items: { type: Array, required: true },
});
</script>

<template>
    <nav aria-label="Fil d’Ariane" class="flex flex-wrap items-center gap-1.5 text-sm">
        <template v-for="(item, index) in items" :key="index">
            <ChevronRight v-if="index > 0" class="h-3.5 w-3.5 shrink-0 text-muted-foreground/60" />
            <Link
                v-if="item.href && index < items.length - 1"
                :href="item.href"
                class="font-medium text-muted-foreground transition-colors hover:text-primary"
            >
                {{ item.label }}
            </Link>
            <span v-else class="font-semibold text-foreground" aria-current="page">{{ item.label }}</span>
        </template>
    </nav>
</template>
