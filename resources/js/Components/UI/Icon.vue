<script setup>
import { computed } from 'vue';

const props = defineProps({
    name: String,
});

const compClass = computed(() => ({
    'icon ni': true,
    [`ni-${props.name}`]: props.name,
}));
</script>

<template>
    <em :class="compClass" />
</template>
