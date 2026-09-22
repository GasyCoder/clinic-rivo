<script setup>
import { computed, useAttrs } from 'vue';
import { cn } from '@/lib/cn';
import { ChevronDown } from 'lucide-vue-next';

/**
 * ADR-099 — une liste native habillée comme les autres champs shadcn.
 *
 * Réservée aux listes courtes à l'intérieur d'un panneau déjà ouvert (mois,
 * année, heure, minutes du sélecteur de date) : la liste du navigateur s'ouvre
 * au-dessus du panneau sans second portail, qui le fermerait au premier clic.
 * Partout ailleurs, `Select` reste la liste de l'application.
 *
 * `bg-none` retire la flèche que le plugin @tailwindcss/forms dessine en image
 * de fond sur tout `<select>` : sans lui, la flèche apparaît deux fois.
 */
defineOptions({ inheritAttrs: false });

defineProps({
    modelValue: { type: [String, Number], default: null },
    /** [{ value, label }] */
    options: { type: Array, default: () => [] },
    /** Affiché tant qu'aucune valeur n'est choisie ; jamais re-choisissable. */
    placeholder: { type: String, default: undefined },
    /** Mise en avant discrète : la liste attend une réponse. */
    highlighted: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
const attrs = useAttrs();
const forwarded = computed(() => {
    const { class: _class, ...rest } = attrs;

    return rest;
});
</script>

<template>
    <span :class="cn('relative inline-flex', attrs.class)">
        <select
            v-bind="forwarded"
            :value="modelValue ?? ''"
            :class="cn(
                'h-7 w-full cursor-pointer appearance-none rounded-md border border-input bg-card bg-none py-0 pe-5 ps-2 text-xs font-semibold leading-7 text-foreground tabular-nums shadow-sm outline-none transition-colors',
                'hover:bg-accent/50 focus-visible:border-primary/60 focus-visible:ring-2 focus-visible:ring-ring/25',
                highlighted && 'border-primary/70 ring-2 ring-primary/15',
            )"
            @change="emit('update:modelValue', $event.target.value)"
        >
            <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
            <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
        <ChevronDown class="pointer-events-none absolute end-1.5 top-1/2 h-3 w-3 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
    </span>
</template>
