<script setup>
import Badge from '@/Components/Shadcn/Badge.vue';
import { STATUS_ICONS } from '@/utilities/staffDebts';

/** ADR-228 — l'état d'une dette : son mot et son icône, jamais la couleur seule. */
defineProps({
    status: { type: String, required: true },
    label: { type: String, required: true },
    tone: { type: String, default: 'neutral' },
});
</script>

<template>
    <Badge :tone="tone"><component :is="STATUS_ICONS[status]" class="h-3.5 w-3.5" aria-hidden="true" />{{ label }}</Badge>
</template>
