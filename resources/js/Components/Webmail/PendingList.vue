<script setup>
import Skeleton from '@/Components/Shadcn/Skeleton.vue';

/**
 * ADR-195 — la liste d'un dossier qui arrive : son nom tout de suite, ses lignes en
 * squelette. La colonne des dossiers, elle, ne bouge pas.
 */
defineProps({
    folderName: { type: String, default: '' },
});
</script>

<template>
    <section class="flex min-h-0 flex-col" aria-busy="true" data-webmail-pending>
        <p class="sr-only" role="status">Chargement de {{ folderName || 'la liste' }}…</p>

        <header class="flex flex-wrap items-center gap-3 border-b border-border px-4 py-3">
            <div class="min-w-0 flex-1 space-y-1.5">
                <h2 v-if="folderName" class="truncate text-lg font-bold text-foreground">{{ folderName }}</h2>
                <Skeleton v-else class="h-6 w-40" />
                <Skeleton class="h-3 w-28" />
            </div>
            <Skeleton class="h-[var(--control-h)] w-full rounded-lg sm:w-72" />
        </header>
        <div class="flex items-center gap-2 border-b border-border bg-muted/30 px-4 py-2">
            <Skeleton class="h-4 w-4" />
            <Skeleton class="h-6 w-14 rounded-full" />
            <Skeleton class="h-6 w-16 rounded-full" />
            <Skeleton class="h-6 w-16 rounded-full" />
            <Skeleton class="ms-auto h-4 w-24" />
        </div>

        <ul class="divide-y divide-border" aria-hidden="true">
            <li v-for="index in 9" :key="index" class="flex items-center gap-3 px-4 py-3">
                <Skeleton class="h-4 w-4" />
                <Skeleton class="h-4 w-4" />
                <Skeleton class="hidden h-9 w-9 rounded-full sm:block" />
                <div class="min-w-0 flex-1 space-y-2">
                    <Skeleton class="h-3.5" :class="index % 3 === 0 ? 'w-1/4' : 'w-1/3'" />
                    <Skeleton class="h-3.5" :class="index % 2 === 0 ? 'w-2/3' : 'w-1/2'" />
                </div>
                <Skeleton class="h-3 w-12" />
            </li>
        </ul>
    </section>
</template>
