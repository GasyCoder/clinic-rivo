<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const site = computed(() => page.props.site);

const year = new Date().getFullYear();
</script>

<template>
    <div class="w-full min-w-[320px] mt-auto border-t bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-900 px-1.5 sm:px-5 py-5">
        <div class="container max-w-none">
            <div class="flex items-center justify-between flex-wrap">
                <div class="text-sm text-slate-500 pb-1 sm:pb-0">
                    &copy; {{ year }} {{ site.brand }}<span v-if="site.name"> — {{ site.name }}</span>
                </div>

                <div class="text-xs text-slate-400">
                    Système de gestion clinique
                </div>
            </div>
        </div>
    </div>
</template>
