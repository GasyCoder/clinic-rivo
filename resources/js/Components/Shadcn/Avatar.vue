<script setup>
import { computed } from 'vue';
import { AvatarFallback, AvatarRoot } from 'reka-ui';
import { cn } from '@/lib/cn';

const props = defineProps({
    initials: { type: String, default: '' },
    text: { type: String, default: '' },
    emergency: { type: Boolean, default: false },
    variant: { type: String, default: '' },
    size: { type: String, default: 'default' },
    class: { type: String, default: '' },
});
/**
 * Les variantes reprennent celles de l'Avatar DashWind qu'il remplace :
 * une valeur non reconnue tomberait sur le neutre et ferait disparaître
 * sans bruit une distinction que l'écran utilise (`primary-pale` marque le
 * patient sélectionné, `danger-pale` une urgence).
 */
const TONES = {
    'danger-pale': 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
    'primary-pale': 'bg-primary/10 text-primary',
    'slate-pale': 'bg-accent text-accent-foreground',
};

const componentClass = computed(() => cn(
    'relative flex shrink-0 overflow-hidden rounded-full ring-1 ring-border',
    props.size === 'lg' ? 'h-12 w-12' : props.size === 'sm' ? 'h-9 w-9' : 'h-10 w-10',
    props.emergency
        ? TONES['danger-pale']
        : TONES[props.variant] ?? 'bg-accent text-accent-foreground',
    props.class,
));
const displayInitials = computed(() => props.initials || props.text);
</script>

<template>
    <AvatarRoot :class="componentClass">
        <AvatarFallback class="flex h-full w-full items-center justify-center text-xs font-bold">
            {{ displayInitials }}
        </AvatarFallback>
    </AvatarRoot>
</template>
