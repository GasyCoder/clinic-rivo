<script setup>
import EmptyState from '@/Components/UI/EmptyState.vue';
</script>

<template>
    <EmptyState>
        <template v-if="$slots.default" #default><slot /></template>
    </EmptyState>
</template>
