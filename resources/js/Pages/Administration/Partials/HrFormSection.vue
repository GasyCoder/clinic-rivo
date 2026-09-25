<script setup>
import { computed } from 'vue';
import { lucideIcon } from '@/lib/icons';
import Badge from '@/Components/Shadcn/Badge.vue';

/*
 * Une étape d'un formulaire RH : son numéro (ou son icône), son titre, ce
 * qu'on y demande. Les étapes se lisent de haut en bas, dans l'ordre du
 * geste — jamais un mur de champs sans repère.
 */
const props = defineProps({
    number: [Number, String],
    title: String,
    description: String,
    // Un composant lucide, ou un nom de la table `lib/icons.js`.
    icon: { type: [String, Object, Function], default: 'edit' },
    optional: Boolean,
});

const glyph = computed(() => (typeof props.icon === 'string' ? lucideIcon(props.icon) : props.icon));
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
        <header class="flex items-start gap-3 border-b border-border bg-muted/40 px-5 py-4">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                <span v-if="number" class="text-sm font-bold tabular-nums">{{ number }}</span>
                <component :is="glyph" v-else class="h-4 w-4" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-sm font-semibold text-foreground">{{ title }}</h2>
                    <Badge v-if="optional" variant="outline" class="px-1.5 py-0 text-[10px] uppercase tracking-wide">Facultatif</Badge>
                </div>
                <p v-if="description" class="mt-0.5 text-xs leading-5 text-muted-foreground">{{ description }}</p>
            </div>
        </header>
        <div class="p-5 sm:p-6"><slot /></div>
    </section>
</template>
