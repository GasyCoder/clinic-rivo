<script setup>
import { computed, onMounted, ref } from 'vue';
import { Monitor, Moon, Sun } from 'lucide-vue-next';
import { useThemeStore } from '@/stores/theme';
import { cn } from '@/lib/cn';

/**
 * Le choix de l'apparence : Clair, Système (celle de l'appareil) ou Sombre.
 *
 * - `header`   : dans la barre du haut, à côté de la cloche, trois icônes ;
 * - `menu`     : dans le menu du compte (téléphone), les trois choix avec leur nom ;
 * - `floating` : sur les pages de connexion, une pastille d'icônes.
 *
 * La sélection ne s'affiche qu'une fois la page reprise par le navigateur : le
 * serveur, qui rend la page, ne connaît pas le choix gardé sur le poste
 * (hydratation, voir la mémoire SSR du projet).
 */
const props = defineProps({
    variant: { type: String, default: 'menu' },
});

const OPTIONS = [
    { value: 'light', label: 'Clair', icon: Sun },
    { value: 'system', label: 'Système', icon: Monitor },
    { value: 'dark', label: 'Sombre', icon: Moon },
];

const theme = useThemeStore();
const hydrated = ref(false);
onMounted(() => { hydrated.value = true; });

const selected = computed(() => (hydrated.value ? theme.mode : null));
const floating = computed(() => props.variant === 'floating');
const header = computed(() => props.variant === 'header');
/** Seules les icônes : le nom se lit au survol et par le lecteur d'écran. */
const iconOnly = computed(() => floating.value || header.value);
</script>

<template>
    <div
        role="radiogroup"
        aria-label="Apparence"
        :class="cn(
            'inline-flex items-center gap-0.5',
            floating
                ? 'rounded-full border border-white/30 bg-slate-950/60 p-1 shadow-lg backdrop-blur'
                : header
                    ? 'p-0.5'
                    : 'w-full rounded-lg border border-border bg-muted/50 p-1',
        )"
    >
        <!-- Dans le menu, l'icône est au-dessus du nom : le menu du compte fait 260 px,
             trois noms côte à côte débordaient. -->
        <button
            v-for="option in OPTIONS"
            :key="option.value"
            type="button"
            role="radio"
            :aria-checked="selected === option.value"
            :aria-label="iconOnly ? `Apparence : ${option.label.toLowerCase()}` : undefined"
            :title="iconOnly ? option.label : undefined"
            :class="cn(
                'inline-flex items-center justify-center gap-1.5 font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2',
                floating
                    ? cn('h-8 w-8 rounded-full focus-visible:ring-white/70', selected === option.value ? 'bg-white text-slate-900 shadow' : 'text-white/80 hover:bg-white/15 hover:text-white')
                    : header
                        ? cn('h-8 w-8 rounded-md focus-visible:ring-ring/40', selected === option.value ? 'bg-accent text-primary' : 'text-muted-foreground hover:bg-accent hover:text-foreground')
                        : cn('min-w-0 flex-1 flex-col gap-1 rounded-md px-1 py-1.5 text-[11px] leading-none focus-visible:ring-ring/40', selected === option.value ? 'bg-card text-foreground shadow-sm ring-1 ring-border' : 'text-muted-foreground hover:text-foreground'),
            )"
            @click="theme.setMode(option.value)"
        >
            <component :is="option.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
            <span v-if="! iconOnly">{{ option.label }}</span>
        </button>
    </div>
</template>
