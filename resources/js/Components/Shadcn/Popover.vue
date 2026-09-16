<script setup>
import { computed } from 'vue';
import {
    PopoverArrow,
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { cn } from '@/lib/cn';

/**
 * Un panneau ancré à son déclencheur (ADR-091 : les primitives shadcn sont
 * ajoutées à la demande).
 *
 * Il rend dans un portail, comme `Dialog` : sans cela, un panneau ouvert
 * depuis l'en-tête serait rogné par le `overflow-hidden` de la barre.
 */
const props = defineProps({
    open: { type: Boolean, default: undefined },
    align: { type: String, default: 'end' },
    sideOffset: { type: Number, default: 8 },
    contentClass: { type: String, default: '' },
    /** Largeur du panneau ; `null` le laisse s'adapter à son contenu. */
    widthClass: { type: String, default: 'w-[min(22rem,calc(100vw-2rem))]' },
});

defineEmits(['update:open']);

const contentClassName = computed(() => cn(
    'z-[1500] rounded-xl border border-border bg-popover p-0 text-popover-foreground shadow-2xl focus:outline-none',
    'data-[state=open]:animate-[rivo-dialog-in_140ms_ease-out]',
    props.widthClass,
    props.contentClass,
));
</script>

<template>
    <PopoverRoot :open="open" @update:open="$emit('update:open', $event)">
        <PopoverTrigger as-child>
            <slot name="trigger" />
        </PopoverTrigger>

        <PopoverPortal>
            <PopoverContent :align="align" :side-offset="sideOffset" :class="contentClassName">
                <slot />
                <PopoverArrow class="fill-popover" />
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
