<script setup>
import { cn } from '@/lib/cn';

/**
 * Les compteurs d'une file, en cartes.
 *
 * Ils vivaient partout en pastilles à l'intérieur d'une barre d'onglets :
 * lisibles seulement en les cherchant, alors que « combien en attente ? » et
 * « y a-t-il une urgence ? » sont les deux questions qu'on se pose en
 * arrivant devant l'écran.
 *
 * Deux règles portent ce composant :
 *
 * 1. **La carte est le filtre.** Un seul contrôle au lieu de deux qui
 *    disaient la même chose, et `aria-pressed` porte l'état pour qui
 *    n'utilise pas la souris. Chaque tuile porte son propre `active` :
 *    une file peut croiser deux filtres — aux Soins, la file et la
 *    priorité — que le composant n'a pas à connaître. Une carte non
 *    filtrante (`filterable: false`) reste un simple indicateur, sans
 *    fausse affordance de clic.
 * 2. **Le compte vient du serveur.** Recalculé depuis la page affichée, il
 *    mentirait dès la deuxième page — et c'est précisément quand la file
 *    est longue qu'on le regarde.
 */
const props = defineProps({
    /** `[{ value, label, hint?, title?, icon, tone?, count, active?, filterable? }]` */
    tiles: { type: Array, required: true },
    class: { type: String, default: '' },
});

defineEmits(['select']);

const TONES = {
    neutral: {
        idle: 'border-border hover:border-primary/40',
        active: 'border-primary bg-primary/5 ring-1 ring-ring/25',
        icon: 'bg-muted text-muted-foreground',
        value: 'text-foreground',
    },
    primary: {
        idle: 'border-border hover:border-primary/40',
        active: 'border-primary bg-primary/5 ring-1 ring-ring/25',
        icon: 'bg-primary/10 text-primary',
        value: 'text-primary',
    },
    amber: {
        idle: 'border-border hover:border-amber-300',
        active: 'border-amber-400 bg-amber-50 ring-1 ring-amber-200 dark:bg-amber-950/25 dark:ring-amber-900',
        icon: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300',
        value: 'text-amber-700 dark:text-amber-300',
    },
    emerald: {
        idle: 'border-border hover:border-emerald-300',
        active: 'border-emerald-400 bg-emerald-50 ring-1 ring-emerald-200 dark:bg-emerald-950/25 dark:ring-emerald-900',
        icon: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300',
        value: 'text-emerald-700 dark:text-emerald-300',
    },
    red: {
        idle: 'border-border hover:border-red-300',
        active: 'border-destructive bg-red-50 ring-1 ring-red-200 dark:bg-red-950/25 dark:ring-red-900',
        icon: 'bg-red-50 text-destructive dark:bg-red-950/40',
        value: 'text-destructive',
    },
    sky: {
        idle: 'border-border hover:border-sky-300',
        active: 'border-sky-400 bg-sky-50 ring-1 ring-sky-200 dark:bg-sky-950/25 dark:ring-sky-900',
        icon: 'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300',
        value: 'text-sky-700 dark:text-sky-300',
    },
};

const tone = (tile) => TONES[tile.tone] ?? TONES.neutral;
const clickable = (tile) => tile.filterable !== false;
</script>

<template>
    <div :class="cn('grid grid-cols-2 gap-3 lg:grid-cols-4', props.class)">
        <component
            :is="clickable(tile) ? 'button' : 'div'"
            v-for="tile in tiles"
            :key="tile.value"
            :type="clickable(tile) ? 'button' : undefined"
            :title="tile.title"
            :class="cn(
                'flex items-start gap-3 rounded-xl border bg-card p-4 text-start shadow-sm transition-colors',
                tile.active ? tone(tile).active : tone(tile).idle,
                ! clickable(tile) && 'cursor-default',
            )"
            :aria-pressed="clickable(tile) ? Boolean(tile.active) : undefined"
            @click="clickable(tile) && $emit('select', tile.value)"
        >
            <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', tone(tile).icon)">
                <component :is="tile.icon" class="h-5 w-5" />
            </span>
            <span class="min-w-0 flex-1">
                <span :class="cn(
                    'block text-2xl font-bold leading-none tabular-nums',
                    tile.count ? tone(tile).value : 'text-muted-foreground',
                )">{{ tile.count }}</span>
                <span class="mt-1 block truncate text-xs font-semibold text-foreground">{{ tile.label }}</span>
                <span v-if="tile.hint" class="mt-0.5 block truncate text-[11px] text-muted-foreground">{{ tile.hint }}</span>
            </span>
        </component>
    </div>
</template>
