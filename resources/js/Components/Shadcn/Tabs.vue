<script setup>
import { computed } from 'vue';
import { TabsRoot } from 'reka-ui';

/** Des onglets (shadcn/ui) : `TabsList`, `TabsTrigger` et `TabsContent` s'y placent. */
const props = defineProps({ modelValue: { type: String, default: '' }, defaultValue: { type: String, default: '' } });
const emit = defineEmits(['update:modelValue']);
const model = computed({
    get: () => props.modelValue || undefined,
    set: (value) => emit('update:modelValue', value),
});
</script>

<template>
    <TabsRoot v-model="model" :default-value="defaultValue || undefined"><slot /></TabsRoot>
</template>
