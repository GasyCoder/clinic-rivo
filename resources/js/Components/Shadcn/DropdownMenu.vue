<script setup>
import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuPortal,
    DropdownMenuRoot,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from 'reka-ui';
import { cn } from '@/lib/cn';

/**
 * Un menu d'actions ancré à son bouton (ADR-091 : les primitives shadcn sont
 * ajoutées à la demande ; ADR-178 pour « Rôles & permissions »).
 *
 * Chaque entrée dit ce qu'elle fait et, quand elle est indisponible, pourquoi :
 * une entrée grisée sans explication se lit « la fonction est cassée ».
 *
 * `items` : `[{ key, label, icon?, description?, disabled?, destructive?, separatorBefore? }]`.
 * Le choix remonte par `select` avec la clé de l'entrée.
 */
const props = defineProps({
    items: { type: Array, default: () => [] },
    label: { type: String, default: '' },
    align: { type: String, default: 'end' },
    contentClass: { type: String, default: '' },
});

const emit = defineEmits(['select']);
</script>

<template>
    <DropdownMenuRoot :modal="false">
        <DropdownMenuTrigger as-child>
            <slot name="trigger" />
        </DropdownMenuTrigger>

        <DropdownMenuPortal>
            <DropdownMenuContent
                :align="align"
                :side-offset="6"
                :class="cn(
                    'z-[1500] min-w-[15rem] max-w-[20rem] rounded-xl border border-border bg-popover p-1 text-popover-foreground shadow-xl focus:outline-none data-[state=open]:animate-[rivo-popover-in_90ms_ease-out]',
                    props.contentClass,
                )"
            >
                <DropdownMenuLabel v-if="label" class="px-2.5 pb-1.5 pt-2 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                    {{ label }}
                </DropdownMenuLabel>

                <template v-for="item in items" :key="item.key">
                    <DropdownMenuSeparator v-if="item.separatorBefore" class="-mx-1 my-1 h-px bg-border" />
                    <DropdownMenuItem
                        :disabled="item.disabled"
                        :class="cn(
                            'flex cursor-default select-none items-start gap-2.5 rounded-lg px-2.5 py-2 text-sm outline-none transition-colors data-[disabled]:pointer-events-none data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground',
                            item.destructive ? 'text-destructive data-[highlighted]:bg-red-50 data-[highlighted]:text-red-700 dark:data-[highlighted]:bg-red-950/40 dark:data-[highlighted]:text-red-300' : '',
                            item.disabled ? 'opacity-60' : '',
                        )"
                        @select="emit('select', item.key)"
                    >
                        <component :is="item.icon" v-if="item.icon" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                        <span class="min-w-0">
                            <span class="block font-medium leading-5">{{ item.label }}</span>
                            <span v-if="item.description" class="mt-0.5 block text-xs leading-4 text-muted-foreground">{{ item.description }}</span>
                        </span>
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
