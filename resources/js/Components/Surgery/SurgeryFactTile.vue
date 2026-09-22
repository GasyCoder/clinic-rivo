<script setup>
import { cn } from '@/lib/cn';
import { Pencil } from 'lucide-vue-next';

/**
 * Un fait du dossier du bloc, lisible d'un coup d'œil : une icône qui dit de
 * quoi il s'agit, un libellé, la valeur. Une valeur absente se dit en clair
 * (« Aucun », « Non affectée ») et s'affiche atténuée — jamais un blanc.
 */
defineProps({
    icon: { type: [Object, Function], required: true },
    label: { type: String, required: true },
    value: { type: String, default: null },
    /** Valeur absente : le texte s'atténue et l'icône passe en gris. */
    empty: { type: Boolean, default: false },
    /** Précision sous la valeur (ex. « Demain »). */
    hint: { type: String, default: null },
    multiline: { type: Boolean, default: false },
    /** « warning » : une valeur attendue, encore à saisir (ex. heure de fin). */
    tone: { type: String, default: null },
    /** Affiche un crayon : la tuile se corrige elle-même (émet `edit`). */
    editable: { type: Boolean, default: false },
    /** La tuile est celle qu'on est en train de corriger. */
    editing: { type: Boolean, default: false },
});

defineEmits(['edit']);
</script>

<template>
    <div :class="cn('group flex min-w-0 items-start gap-3 rounded-lg border px-3 py-2.5 transition-colors', tone === 'warning' ? 'border-amber-200 bg-amber-50/60 dark:border-amber-900 dark:bg-amber-950/20' : 'border-border bg-muted/20', editing && 'border-primary ring-1 ring-primary/40')">
        <span
            :class="cn('grid h-8 w-8 shrink-0 place-items-center rounded-md', tone === 'warning' ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300' : (empty ? 'bg-muted text-muted-foreground' : 'bg-primary/10 text-primary'))"
            aria-hidden="true"
        >
            <component :is="icon" class="h-4 w-4" />
        </span>
        <div class="min-w-0 flex-1">
            <dt class="text-xs text-muted-foreground">{{ label }}</dt>
            <dd :class="cn('break-words text-sm', tone === 'warning' ? 'font-medium text-amber-700 dark:text-amber-300' : (empty ? 'text-muted-foreground' : 'font-medium text-foreground'), multiline && 'whitespace-pre-line')">
                <slot>{{ value }}</slot>
            </dd>
            <p v-if="hint" class="mt-0.5 text-xs text-muted-foreground">{{ hint }}</p>
        </div>
        <button
            v-if="editable"
            type="button"
            :aria-label="`Modifier : ${label}`"
            :title="`Modifier : ${label}`"
            :aria-pressed="editing"
            :class="cn('grid h-7 w-7 shrink-0 place-items-center rounded-md border text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', editing ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-card')"
            @click="$emit('edit')"
        >
            <Pencil class="h-3.5 w-3.5" aria-hidden="true" />
        </button>
    </div>
</template>
