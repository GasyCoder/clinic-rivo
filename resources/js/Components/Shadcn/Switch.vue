<script setup>
import { computed, useAttrs } from 'vue';
import { SwitchRoot, SwitchThumb } from 'reka-ui';
import { cn } from '@/lib/cn';

defineOptions({ inheritAttrs: false });

/** Un interrupteur (shadcn/ui) : activé ou non, dit par sa position et par l'état lu à voix haute. */
const props = defineProps({ modelValue: { type: Boolean, default: false } });
const emit = defineEmits(['update:modelValue']);
const attrs = useAttrs();
const model = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value === true),
});
const componentClass = computed(() => cn(
    'peer inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:bg-primary data-[state=unchecked]:bg-input',
    attrs.class,
));
const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;

    return rest;
});
</script>

<template>
    <SwitchRoot v-model="model" :class="componentClass" v-bind="forwardedAttrs">
        <SwitchThumb class="pointer-events-none block h-4 w-4 rounded-full bg-background shadow-lg ring-0 transition-transform data-[state=checked]:translate-x-4 data-[state=unchecked]:translate-x-0" />
    </SwitchRoot>
</template>
