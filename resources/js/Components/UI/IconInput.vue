<script setup>
import { computed } from 'vue';
import Input from '@/Components/UI/Input.vue';
import { lucideIcon } from '@/lib/icons';

defineOptions({
    inheritAttrs: false,
});

const props = defineProps({
    modelValue: [String, Number],
    icon: { type: String, required: true },
});

const glyph = computed(() => lucideIcon(props.icon));
defineEmits(['update:modelValue']);
</script>

<template>
    <div class="relative">
        <Input
            icon="start"
            :model-value="modelValue"
            v-bind="$attrs"
            @update:model-value="$emit('update:modelValue', $event)"
        />
        <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 start-0 grid w-9 place-items-center text-muted-foreground">
            <component :is="glyph" class="h-4 w-4" />
        </span>
    </div>
</template>
