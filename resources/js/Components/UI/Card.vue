<script setup>
import { computed } from 'vue';

const props = defineProps({
    as: String,
    variant: String,
});

const compClass = computed(() => ({
    'border rounded-md': true,
    'bg-white dark:bg-gray-950 border-gray-300 dark:border-gray-900': !props.variant,
    'bg-primary-600 border-primary-600': props.variant === 'primary',
}));
</script>

<template>
    <component :is="as || 'div'" :class="compClass">
        <slot />
    </component>
</template>
