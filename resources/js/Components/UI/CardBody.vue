<script setup>
defineProps({
    as: String,
});
</script>

<template>
    <component :is="as || 'div'" class="p-5 sm:p-6">
        <slot />
    </component>
</template>
