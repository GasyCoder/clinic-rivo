<script setup>
import { computed } from 'vue';

const props = defineProps({
    as: String,
    variant: String,
});

const compClass = computed(() => ({
    'border rounded-md': true,
    'bg-card border-border': !props.variant,
    'bg-primary border-primary text-primary-foreground': props.variant === 'primary',
}));
</script>

<template>
    <component :is="as || 'div'" :class="compClass">
        <slot />
    </component>
</template>
