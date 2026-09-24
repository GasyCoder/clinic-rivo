<script setup>
import { computed } from 'vue';
import { ChevronRight } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import { cn } from '@/lib/cn';

/**
 * Un module replié se lit en une ligne : ce qu'il couvre, combien de droits
 * sont ouverts, et s'il reste des modifications à enregistrer (ADR-178).
 *
 * Replier les quatorze modules donne le résumé du rôle — « Pharmacie 12/68,
 * Caisse 0/33 » — sans rien ouvrir ; on déplie celui qu'on règle. Les actions
 * groupées vivent à droite de l'en-tête, hors du bouton qui déplie : un clic
 * sur « Tout sélectionner » ne doit jamais replier le module au passage.
 */
const props = defineProps({
    module: { type: Object, required: true },
    expanded: { type: Boolean, default: false },
    /** Droits ouverts, sur ce qu'affiche la vue (recherche comprise). */
    active: { type: Number, default: 0 },
    total: { type: Number, default: 0 },
    changed: { type: Number, default: 0 },
    /** Une recherche ou un filtre est actif : on compte ce qui correspond. */
    filtering: { type: Boolean, default: false },
    activeLabel: { type: String, default: 'accordées' },
});

defineEmits(['toggle']);

const bodyId = computed(() => `permission-module-${props.module.key}`);
const ratio = computed(() => (props.total ? Math.round((props.active / props.total) * 100) : 0));
</script>

<template>
    <section
        :class="cn(
            'overflow-hidden rounded-xl border bg-card shadow-sm transition-colors',
            changed ? 'border-amber-300 dark:border-amber-800' : 'border-border',
        )"
    >
        <div class="flex items-center gap-3 px-3 py-2.5 sm:px-4">
            <button
                type="button"
                class="group flex min-w-0 flex-1 items-center gap-3 rounded-lg py-1 text-start focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :aria-expanded="expanded"
                :aria-controls="bodyId"
                @click="$emit('toggle')"
            >
                <ChevronRight :class="cn('h-4 w-4 shrink-0 text-muted-foreground transition-transform', expanded ? 'rotate-90' : '')" aria-hidden="true" />
                <span
                    :class="cn(
                        'grid h-9 w-9 shrink-0 place-items-center rounded-lg transition-colors',
                        active > 0 ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground',
                    )"
                >
                    <component :is="module.icon" class="h-4.5 w-4.5" aria-hidden="true" />
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-bold text-foreground group-hover:text-primary">{{ module.label }}</span>
                    <span class="hidden truncate text-xs text-muted-foreground sm:block">{{ module.description }}</span>
                </span>
            </button>

            <div class="flex shrink-0 items-center gap-3">
                <Badge v-if="changed" variant="warning" class="px-2 py-0.5 text-[11px]">
                    {{ changed }} modif.
                </Badge>
                <div class="hidden w-28 sm:block" :title="`${active} ${activeLabel} sur ${total}${filtering ? ' (vue filtrée)' : ''}`">
                    <p class="text-end text-xs tabular-nums text-muted-foreground">
                        <strong :class="active ? 'text-foreground' : ''">{{ active }}</strong> / {{ total }}
                        <span v-if="filtering" class="text-[10px]">affichées</span>
                    </p>
                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                        <div class="h-full rounded-full bg-primary transition-[width] duration-300" :style="{ width: `${ratio}%` }" />
                    </div>
                </div>
                <span class="text-xs font-semibold tabular-nums text-muted-foreground sm:hidden">{{ active }}/{{ total }}</span>
                <!-- Les actions groupées n'apparaissent que sur le module ouvert :
                     répétées sur quatorze modules repliés, elles chargeaient
                     l'écran pour un geste qu'on fait sur celui qu'on règle. -->
                <slot v-if="expanded" name="actions" />
            </div>
        </div>

        <div v-if="expanded" :id="bodyId" class="border-t border-border">
            <slot />
        </div>
    </section>
</template>
