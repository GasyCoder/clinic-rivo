<script setup>
import { computed, useAttrs } from 'vue';
import { TabsList } from 'reka-ui';
import { cn } from '@/lib/cn';

defineOptions({ inheritAttrs: false });

const attrs = useAttrs();
const componentClass = computed(() => cn('inline-flex h-9 items-center justify-center rounded-lg bg-muted p-1 text-muted-foreground', attrs.class));
const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;

    return rest;
});
</script>

<template>
    <TabsList :class="componentClass" v-bind="forwardedAttrs"><slot /></TabsList>
</template>
