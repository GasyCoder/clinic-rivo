<script setup>
import Icon from '@/Components/UI/Icon.vue';

defineProps({
    icon: { type: String, default: 'inbox' },
    title: { type: String, default: 'Aucune donnée' },
    description: String,
});
</script>

<template>
    <div class="px-5 py-14 text-center">
        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-gray-100 text-2xl text-slate-400 dark:bg-gray-900">
            <Icon :name="icon" />
        </span>
        <p class="mt-3 text-sm font-bold text-slate-700 dark:text-white">{{ title }}</p>
        <p v-if="description" class="mx-auto mt-1 max-w-md text-xs leading-5 text-slate-400">{{ description }}</p>
        <div v-if="$slots.default" class="mt-4"><slot /></div>
    </div>
</template>
