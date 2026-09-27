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

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    closeLabel: { type: String, default: 'Fermer' },
    size: { type: String, default: 'md' },
    contentClass: { type: String, default: '' },
    bodyClass: { type: String, default: '' },
    /**
     * Fermable par un clic à l'extérieur ou par Échap.
     *
     * Vrai par défaut : la plupart de ces fenêtres se lisent, et les fermer
     * d'un clic est l'attendu. On le passe à faux pour celles où l'on
     * **écrit** — un compte rendu de plusieurs minutes ne doit pas partir sur
     * un clic à côté. Il reste alors « Annuler » et la croix, deux gestes
     * explicites ; jamais une fenêtre dont on ne peut pas sortir.
     */
    dismissible: { type: Boolean, default: true },
});
defineEmits(['update:open']);

const contentClassName = computed(() => cn(
    'fixed left-1/2 top-1/2 z-[1500] w-[calc(100%-2rem)] -translate-x-1/2 -translate-y-1/2 rounded-xl border border-border bg-card p-0 text-card-foreground shadow-2xl focus:outline-none data-[state=open]:animate-[rivo-dialog-in_180ms_ease-out]',
    // `wide` sert les panneaux de lecture — contexte clinique, synthèses —
    // dont le contenu est tabulaire : sous cette largeur, les colonnes se
    // replient et la fenêtre devient plus haute que l'écran.
    props.size === 'lg' ? 'max-w-2xl'
        : props.size === 'xl' ? 'max-w-4xl'
            : props.size === 'wide' ? 'max-w-[min(96rem,calc(100vw-2rem))]'
                : 'max-w-lg',
    props.contentClass,
));
</script>

<template>
    <DialogRoot :open="open" @update:open="$emit('update:open', $event)">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-[1490] bg-slate-950/55 backdrop-blur-[2px] data-[state=open]:animate-[rivo-overlay-in_160ms_ease-out]" />
            <DialogContent
                :class="contentClassName"
                @interact-outside="(event) => dismissible || event.preventDefault()"
                @escape-key-down="(event) => dismissible || event.preventDefault()"
            >
                <header class="flex items-start gap-4 border-b border-border px-6 py-5 pe-14">
                    <slot name="icon" />
                    <div class="min-w-0">
                        <DialogTitle class="text-lg font-bold tracking-tight text-foreground">{{ title }}</DialogTitle>
                        <DialogDescription v-if="description" class="mt-1 text-sm leading-5 text-muted-foreground">
                            {{ description }}
                        </DialogDescription>
                    </div>
                </header>

                <DialogClose
                    class="absolute end-4 top-4 grid h-8 w-8 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus:outline-none focus:ring-2 focus:ring-ring/30"
                    :aria-label="closeLabel"
                >
                    <X class="h-4 w-4" />
                </DialogClose>

                <div :class="cn('px-6 py-5', bodyClass)"><slot /></div>
                <footer v-if="$slots.footer" class="flex flex-col-reverse gap-2 border-t border-border bg-muted/35 px-6 py-4 sm:flex-row sm:justify-end">
                    <slot name="footer" />
                </footer>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
