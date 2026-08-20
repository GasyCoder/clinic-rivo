<script setup>
import Input from '@/Components/UI/Input.vue';
import Icon from '@/Components/UI/Icon.vue';

defineOptions({
    inheritAttrs: false,
});

defineProps({
    modelValue: [String, Number],
    icon: { type: String, required: true },
});
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
        <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400">
            <Icon class="text-lg/4.5" :name="icon" />
        </span>
    </div>
</template>
