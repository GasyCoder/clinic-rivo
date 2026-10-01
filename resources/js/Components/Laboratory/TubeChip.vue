<script setup>
import { cn } from '@/lib/cn';

/**
 * ADR-214 — un tube reconnu d'un coup d'œil : la pastille de la couleur de son
 * bouchon, son code, et la couleur écrite (la couleur ne porte jamais seule
 * l'information).
 */
defineProps({
    tube: { type: Object, default: null },
    size: { type: String, default: 'sm' },
    withName: { type: Boolean, default: false },
});
</script>

<template>
    <span v-if="tube" class="inline-flex items-center gap-1.5 text-xs text-foreground" :title="[tube.name, tube.color].filter(Boolean).join(' · ')">
        <span
            :class="cn('inline-block shrink-0 rounded-full border border-border', size === 'lg' ? 'h-4 w-4' : 'h-3 w-3')"
            :style="{ backgroundColor: tube.hex || 'transparent' }"
            aria-hidden="true"
        />
        <span class="font-semibold">{{ tube.code }}</span>
        <span v-if="withName && tube.name" class="text-muted-foreground">{{ tube.name }}</span>
        <span v-if="tube.color" class="text-muted-foreground">· {{ tube.color }}</span>
    </span>
    <span v-else class="text-xs text-muted-foreground">Sans tube</span>
</template>
