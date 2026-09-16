<script setup>
import { computed } from 'vue';
import { lucideIcon } from '@/lib/icons';

const props = defineProps({
    icon: { type: String, default: 'inbox' },
    title: { type: String, default: 'Aucune donnée' },
    description: String,
});

const glyph = computed(() => lucideIcon(props.icon));
</script>

<template>
    <div class="px-5 py-14 text-center">
        <span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-muted text-muted-foreground">
            <component :is="glyph" class="h-6 w-6" />
        </span>
        <p class="mt-3 text-sm font-bold text-foreground">{{ title }}</p>
        <p v-if="description" class="mx-auto mt-1 max-w-md text-xs leading-5 text-muted-foreground">{{ description }}</p>
        <div v-if="$slots.default" class="mt-4"><slot /></div>
    </div>
</template>
