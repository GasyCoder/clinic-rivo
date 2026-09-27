<script setup>
import { computed, useAttrs } from 'vue';
import { TabsTrigger } from 'reka-ui';
import { cn } from '@/lib/cn';

defineOptions({ inheritAttrs: false });

const props = defineProps({ value: { type: String, required: true } });
const attrs = useAttrs();
const componentClass = computed(() => cn(
    'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40 disabled:pointer-events-none disabled:opacity-50 data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow',
    attrs.class,
));
const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;

    return rest;
});
</script>

<template>
    <TabsTrigger :value="props.value" :class="componentClass" v-bind="forwardedAttrs"><slot /></TabsTrigger>
</template>
