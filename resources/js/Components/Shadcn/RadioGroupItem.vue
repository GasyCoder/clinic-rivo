<script setup>
import { computed, useAttrs } from 'vue';
import { RadioGroupIndicator, RadioGroupItem } from 'reka-ui';
import { cn } from '@/lib/cn';

defineOptions({ inheritAttrs: false });

const props = defineProps({ value: { type: String, required: true } });
const attrs = useAttrs();
const componentClass = computed(() => cn(
    'grid aspect-square h-4 w-4 place-items-center rounded-full border border-primary text-primary shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-ring/40 disabled:cursor-not-allowed disabled:opacity-50',
    attrs.class,
));
const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;

    return rest;
});
</script>

<template>
    <RadioGroupItem :value="props.value" :class="componentClass" v-bind="forwardedAttrs">
        <RadioGroupIndicator class="grid place-items-center">
            <span class="h-2 w-2 rounded-full bg-primary" />
        </RadioGroupIndicator>
    </RadioGroupItem>
</template>
