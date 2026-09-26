<script setup>
import { ArrowLeft } from 'lucide-vue-next';
import Skeleton from '@/Components/Shadcn/Skeleton.vue';
import { cn } from '@/lib/cn';
import { avatarTone, formatFullDate, initialsOf, senderLabel } from '@/utilities/webmail';

/**
 * ADR-195 — un message qui s'ouvre. Ce que la ligne cliquée dit déjà (objet,
 * expéditeur, date) s'affiche tout de suite ; seul le corps attend le serveur.
 */
defineProps({
    /** La ligne de la liste qui a été cliquée ; `null` quand on vient d'ailleurs. */
    item: { type: Object, default: null },
    folderName: { type: String, default: '' },
});
</script>

<template>
    <article class="flex min-h-0 flex-col" aria-busy="true" data-webmail-pending>
        <p class="sr-only" role="status">Ouverture du message…</p>

        <div class="flex items-center gap-2 border-b border-border px-3 py-2">
            <span class="inline-flex h-8 items-center gap-1.5 px-2 text-sm font-medium text-muted-foreground">
                <ArrowLeft class="h-4 w-4" aria-hidden="true" /> <span class="hidden sm:inline">{{ folderName }}</span>
            </span>
            <span class="mx-1 h-5 w-px bg-border" aria-hidden="true" />
            <Skeleton v-for="index in 6" :key="index" class="h-7 w-7" />
        </div>

        <div class="space-y-5 px-4 py-5 sm:px-6">
            <h2 v-if="item" class="text-xl font-bold leading-snug text-foreground">{{ item.subject || '(Sans objet)' }}</h2>
            <Skeleton v-else class="h-7 w-2/3" />

            <div class="flex items-start gap-3">
                <span
                    v-if="item?.from"
                    :class="cn('grid h-11 w-11 shrink-0 place-items-center rounded-full text-sm font-bold', avatarTone(item.from.email))"
                    aria-hidden="true"
                >{{ initialsOf(item.from) }}</span>
                <Skeleton v-else class="h-11 w-11 rounded-full" />
                <div class="min-w-0 flex-1 space-y-2">
                    <div v-if="item" class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <p class="min-w-0 truncate text-sm font-semibold text-foreground">{{ senderLabel(item.from) }}</p>
                        <time :datetime="item.date" class="shrink-0 text-xs text-muted-foreground">{{ formatFullDate(item.date) }}</time>
                    </div>
                    <Skeleton v-else class="h-4 w-1/3" />
                    <Skeleton class="h-3 w-40" />
                </div>
            </div>

            <div class="space-y-2.5 rounded-lg border border-border p-5">
                <Skeleton class="h-3.5 w-11/12" />
                <Skeleton class="h-3.5 w-full" />
                <Skeleton class="h-3.5 w-10/12" />
                <Skeleton class="h-3.5 w-8/12" />
                <Skeleton class="mt-5 h-3.5 w-9/12" />
                <Skeleton class="h-3.5 w-7/12" />
            </div>
        </div>
    </article>
</template>
