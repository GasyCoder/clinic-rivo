<script setup>
import { computed } from 'vue';
import { CircleAlert } from 'lucide-vue-next';
import Popover from '@/Components/Shadcn/Popover.vue';
import { cn } from '@/lib/cn';

/**
 * Le bouton « ! » : ce qui est bon à savoir sur une page — informations,
 * avertissements — tient derrière une icône au lieu d'empiler des bandeaux. La
 * pastille dit combien il y en a ; elle passe à l'ambre dès qu'un avertissement
 * s'y trouve. Au clic, un panneau les liste.
 *
 * Chaque avis : { key, text, title?, icon? (composant lucide), tone?: 'info' | 'warning' }.
 */
const props = defineProps({
    notices: { type: Array, default: () => [] },
    heading: { type: String, default: 'À savoir' },
    subtitle: { type: String, default: '' },
});

const hasWarning = computed(() => props.notices.some((notice) => notice.tone === 'warning'));
const label = computed(() => {
    const count = props.notices.length;
    return `${count} ${hasWarning.value ? 'avis' : `information${count > 1 ? 's' : ''}`} à savoir`;
});
</script>

<template>
    <Popover v-if="notices.length" width-class="w-[min(24rem,calc(100vw-2rem))]">
        <template #trigger>
            <button
                type="button"
                :class="cn(
                    'relative inline-flex h-[var(--control-h-sm)] w-[var(--control-h-sm)] shrink-0 items-center justify-center rounded-md border bg-card shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring data-[state=open]:bg-accent data-[state=open]:text-foreground',
                    hasWarning ? 'border-amber-300 text-amber-600 hover:bg-amber-50 dark:border-amber-800 dark:text-amber-400 dark:hover:bg-amber-950/40' : 'border-border text-muted-foreground hover:bg-accent hover:text-foreground',
                )"
                :aria-label="label"
                :title="label"
            >
                <CircleAlert class="h-4 w-4" aria-hidden="true" />
                <span
                    :class="cn('absolute -end-1.5 -top-1.5 inline-flex min-w-[18px] items-center justify-center rounded-full px-1 text-[10px] font-bold leading-4 ring-2 ring-background', hasWarning ? 'bg-amber-500 text-white' : 'bg-primary text-primary-foreground')"
                    aria-hidden="true"
                >{{ notices.length }}</span>
            </button>
        </template>

        <div class="border-b border-border px-4 py-3">
            <p class="text-sm font-bold text-foreground">{{ heading }}</p>
            <p v-if="subtitle" class="mt-0.5 text-[11px] leading-4 text-muted-foreground">{{ subtitle }}</p>
        </div>
        <ul class="max-h-[22rem] divide-y divide-border overflow-y-auto">
            <li v-for="notice in notices" :key="notice.key" class="flex items-start gap-2.5 px-4 py-3 text-xs leading-5 text-muted-foreground">
                <component
                    :is="notice.icon ?? CircleAlert"
                    :class="cn('mt-0.5 h-3.5 w-3.5 shrink-0', notice.tone === 'warning' ? 'text-amber-600 dark:text-amber-400' : 'text-primary')"
                    aria-hidden="true"
                />
                <p><strong v-if="notice.title" class="font-semibold text-foreground">{{ notice.title }}</strong><template v-if="notice.title"> — </template>{{ notice.text }}</p>
            </li>
        </ul>
    </Popover>
</template>
