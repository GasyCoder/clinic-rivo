<script setup>
import { computed, useAttrs } from 'vue';
import { cn } from '@/lib/cn';

/** Un bloc qui tient la place d'un contenu en cours de chargement (shadcn-vue). */
defineOptions({ inheritAttrs: false });

const attrs = useAttrs();
const componentClass = computed(() => cn('animate-pulse rounded-md bg-muted', attrs.class));
const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;

    return rest;
});
</script>

<template>
    <div :class="componentClass" aria-hidden="true" v-bind="forwardedAttrs" />
</template>
