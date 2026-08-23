<script setup>
defineProps({
    message: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <span class="block text-[11px] italic leading-[1.8] text-red-600 mt-1">
        <slot>{{ message }}</slot>
    </span>
</template>
