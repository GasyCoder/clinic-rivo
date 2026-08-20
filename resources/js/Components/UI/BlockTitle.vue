<script setup>
defineProps({
    as: String,
});
</script>

<template>
    <component :is="as || 'h5'" class="text-xl font-medium -tracking-snug text-slate-700 dark:text-white leading-tighter [&:not(:last-child)]:mb-2">
        <slot />
    </component>
</template>
