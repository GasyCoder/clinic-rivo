<script setup>
import { computed, useAttrs } from 'vue';
import Input from '@/Components/Shadcn/Input.vue';
import { cn } from '@/lib/cn';

/**
 * A text field with a leading mark.
 *
 * `icon` is the lucide component itself, not a name: there is no string
 * table to keep in sync with the icon set, and an icon that no longer
 * exists fails at build time instead of rendering an empty box.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: [String, Number],
    icon: { type: [Object, Function], required: true },
    size: { type: String, default: 'default' },
});
defineEmits(['update:modelValue']);

const attrs = useAttrs();
const inputClass = computed(() => cn(props.size === 'lg' ? 'ps-11' : 'ps-9', attrs.class));
const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;

    return rest;
});
</script>

<template>
    <div class="relative">
        <component
            :is="icon"
            :class="cn(
                'pointer-events-none absolute top-1/2 z-10 -translate-y-1/2 text-muted-foreground',
                size === 'lg' ? 'start-4 h-4.5 w-4.5' : 'start-3 h-4 w-4',
            )"
            aria-hidden="true"
        />
        <Input
            :model-value="modelValue"
            :size="size"
            :class="inputClass"
            v-bind="forwardedAttrs"
            @update:model-value="$emit('update:modelValue', $event)"
        />
    </div>
</template>
