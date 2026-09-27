<script setup>
import { computed, useAttrs } from 'vue';
import { TabsContent } from 'reka-ui';
import { cn } from '@/lib/cn';

defineOptions({ inheritAttrs: false });

const props = defineProps({ value: { type: String, required: true } });
const attrs = useAttrs();
const componentClass = computed(() => cn('mt-4 focus-visible:outline-none', attrs.class));
const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;

    return rest;
});
</script>

<template>
    <TabsContent :value="props.value" :class="componentClass" v-bind="forwardedAttrs"><slot /></TabsContent>
</template>
