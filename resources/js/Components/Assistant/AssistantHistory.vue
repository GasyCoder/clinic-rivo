<script setup>
import { MessageSquareText, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Skeleton from '@/Components/Shadcn/Skeleton.vue';
import { cn } from '@/lib/cn';

/**
 * ADR-222 — l'historique des conversations : chacun ne voit, ne reprend et ne
 * supprime que les siennes (le serveur le vérifie à chaque lecture).
 */
defineProps({
    items: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    activeId: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['open', 'remove']);

const dateLabel = (iso) => (iso
    ? new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
    : '');
</script>

<template>
    <div v-if="loading && ! items.length" class="space-y-2 p-3" aria-busy="true">
        <Skeleton v-for="index in 5" :key="index" class="h-11 w-full rounded-lg" />
    </div>

    <p v-else-if="! items.length" class="flex flex-col items-center gap-2 px-4 py-8 text-center text-sm text-muted-foreground">
        <MessageSquareText class="h-6 w-6 opacity-60" aria-hidden="true" />
        Aucune conversation pour l’instant.
    </p>

    <ul v-else class="space-y-0.5 p-2" data-assistant-history>
        <li v-for="item in items" :key="item.id" class="group relative">
            <button
                type="button"
                :class="cn('flex w-full flex-col rounded-lg px-3 py-2 pe-10 text-left transition hover:bg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50',
                    item.id === activeId && 'bg-primary/10 hover:bg-primary/10')"
                :aria-current="item.id === activeId ? 'true' : undefined"
                :disabled="disabled"
                @click="emit('open', item.id)"
            >
                <span :class="cn('block truncate text-sm', item.id === activeId ? 'font-medium text-primary' : 'text-foreground')">{{ item.title || 'Conversation' }}</span>
                <span class="block text-xs text-muted-foreground">{{ dateLabel(item.updated_at) }}</span>
            </button>
            <Button
                type="button"
                size="icon"
                variant="ghost"
                class="absolute end-1 top-1/2 h-8 w-8 -translate-y-1/2 opacity-60 hover:opacity-100 focus-visible:opacity-100 group-hover:opacity-100"
                :aria-label="`Supprimer la conversation « ${item.title || 'Conversation'} »`"
                title="Supprimer"
                :disabled="disabled"
                @click="emit('remove', item)"
            >
                <Trash2 class="h-4 w-4" aria-hidden="true" />
            </Button>
        </li>
    </ul>
</template>
