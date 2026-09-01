<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({ paginator: Object });
</script>

<template>
    <div v-if="paginator?.last_page > 1" class="flex flex-col gap-3 border-t border-gray-200 px-4 py-3 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
        <span class="text-xs text-slate-400">Page {{ paginator.current_page }} sur {{ paginator.last_page }} · {{ paginator.total }} résultat(s)</span>
        <div class="flex max-w-full gap-1 overflow-x-auto pb-1 sm:pb-0">
            <template v-for="(link, index) in paginator.links" :key="index">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-state
                    :class="['rounded-lg px-3 py-1.5 text-sm', link.active ? 'bg-primary-600 text-white' : 'text-slate-500 hover:bg-gray-100 dark:hover:bg-gray-900']"
                    v-html="link.label"
                />
                <span v-else class="rounded-lg px-3 py-1.5 text-sm text-slate-300" v-html="link.label" />
            </template>
        </div>
    </div>
</template>
