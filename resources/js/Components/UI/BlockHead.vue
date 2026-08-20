<script setup>
defineProps({
    as: String,
});
</script>

<template>
    <component :is="as || 'div'" class="pb-5">
        <slot />
    </component>
</template>
