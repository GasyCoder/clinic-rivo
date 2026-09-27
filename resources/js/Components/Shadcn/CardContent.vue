<script setup>
import { computed, useAttrs } from 'vue';
import { cn } from '@/lib/cn';

defineOptions({ inheritAttrs: false });

const attrs = useAttrs();
const componentClass = computed(() => cn('p-5', attrs.class));
const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;

    return rest;
});
</script>

<template>
    <div :class="componentClass" v-bind="forwardedAttrs"><slot /></div>
</template>
