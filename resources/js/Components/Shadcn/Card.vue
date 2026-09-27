<script setup>
import { computed, useAttrs } from 'vue';
import { cn } from '@/lib/cn';

defineOptions({ inheritAttrs: false });

const attrs = useAttrs();
const componentClass = computed(() => cn(
    'rounded-xl border border-border bg-card text-card-foreground shadow-sm',
    attrs.class,
));
const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;
    return rest;
});
</script>

<template>
    <section :class="componentClass" v-bind="forwardedAttrs">
        <slot />
    </section>
</template>
