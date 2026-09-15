<script setup>
import PageHeader from '@/Components/UI/PageHeader.vue';
</script>

<template>
    <PageHeader>
        <template v-if="$slots.actions" #actions><slot name="actions" /></template>
    </PageHeader>
</template>
