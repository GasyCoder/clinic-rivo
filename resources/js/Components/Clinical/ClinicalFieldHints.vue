<script setup>
import { CircleAlert, Info, TriangleAlert } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import { cn } from '@/lib/cn';

/**
 * Ce que le système rappelle sous un champ pendant la saisie (ADR-137).
 *
 * Une aide, jamais un verrou : rien ici n'empêche d'enregistrer. Un message
 * peut porter une proposition (`action`) — « Utiliser » reprend la valeur
 * calculée dans le champ, jamais par-dessus une saisie : c'est le formulaire
 * qui décide de l'appliquer.
 */
defineProps({
    hints: { type: Array, default: () => [] },
    label: { type: String, default: 'Repères sur ce champ' },
});

const emit = defineEmits(['apply']);

const TONES = {
    danger: { box: 'border-destructive/30 bg-destructive/10 text-destructive', icon: CircleAlert },
    warning: { box: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200', icon: TriangleAlert },
    info: { box: 'border-border bg-muted/40 text-muted-foreground', icon: Info },
};
</script>

<template>
    <ul v-if="hints.length" class="mt-1.5 space-y-1" role="list" :aria-label="label">
        <li
            v-for="hint in hints"
            :key="hint.code"
            :class="cn('flex items-start gap-2 rounded-lg border px-2.5 py-1.5 text-[11px] leading-4', TONES[hint.level].box, hint.level === 'danger' && 'font-semibold')"
        >
            <component :is="TONES[hint.level].icon" class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span class="min-w-0 flex-1">{{ hint.message }}</span>
            <Button
                v-if="hint.action"
                type="button"
                size="sm"
                variant="outline"
                class="h-5 shrink-0 px-2 text-[11px]"
                @click.prevent="emit('apply', hint)"
            >{{ hint.action.label }}</Button>
        </li>
    </ul>
</template>
