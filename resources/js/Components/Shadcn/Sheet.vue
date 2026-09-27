<script setup>
import { X } from 'lucide-vue-next';
import { computed } from 'vue';
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { cn } from '@/lib/cn';

/**
 * Un panneau latéral (shadcn/ui « Sheet ») : on le consulte sans quitter ce
 * qu'on est en train de faire — l'historique d'une grossesse pendant un
 * accouchement, par exemple. Même primitive que `Dialog` (focus piégé, Échap,
 * titre annoncé), posée sur le bord de l'écran au lieu d'être centrée.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    closeLabel: { type: String, default: 'Fermer' },
    /** `right` (par défaut) ou `left`. */
    side: { type: String, default: 'right' },
    contentClass: { type: String, default: '' },
    bodyClass: { type: String, default: '' },
});
defineEmits(['update:open']);

const contentClassName = computed(() => cn(
    'fixed inset-y-0 z-[1500] flex h-full w-full max-w-2xl flex-col border-border bg-card text-card-foreground shadow-2xl focus:outline-none',
    props.side === 'left'
        ? 'left-0 border-r data-[state=open]:animate-[rivo-sheet-in-left_220ms_ease-out]'
        : 'right-0 border-l data-[state=open]:animate-[rivo-sheet-in-right_220ms_ease-out]',
    props.contentClass,
));
</script>

<template>
    <DialogRoot :open="open" @update:open="$emit('update:open', $event)">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-[1490] bg-slate-950/45 backdrop-blur-[1px] data-[state=open]:animate-[rivo-overlay-in_160ms_ease-out]" />
            <DialogContent :class="contentClassName">
                <header class="flex items-start gap-3 border-b border-border px-5 py-4 pe-14">
                    <slot name="icon" />
                    <div class="min-w-0">
                        <DialogTitle class="text-base font-bold tracking-tight text-foreground">{{ title }}</DialogTitle>
                        <DialogDescription v-if="description" class="mt-1 text-xs leading-5 text-muted-foreground">{{ description }}</DialogDescription>
                    </div>
                </header>
                <DialogClose
                    class="absolute end-4 top-4 grid h-8 w-8 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus:outline-none focus:ring-2 focus:ring-ring/30"
                    :aria-label="closeLabel"
                >
                    <X class="h-4 w-4" />
                </DialogClose>
                <div :class="cn('min-h-0 flex-1 overflow-y-auto px-5 py-4', bodyClass)"><slot /></div>
                <footer v-if="$slots.footer" class="flex flex-wrap justify-end gap-2 border-t border-border bg-muted/35 px-5 py-3">
                    <slot name="footer" />
                </footer>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
