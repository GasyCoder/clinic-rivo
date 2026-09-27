<script setup>
import { computed, ref, watch } from 'vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { cn } from '@/lib/cn';
import { Check, ChevronDown, Clock, Hourglass } from 'lucide-vue-next';

/**
 * ADR-048 / ADR-099 — une section du dossier du bloc.
 *
 * Toutes les sections se lisent de la même façon : une icône, un titre, ce
 * qu'elle contient, et ses actions à droite. Dans une étape qui enchaîne
 * plusieurs gestes, `order` numérote la section dans l'ordre du workflow et
 * `state` dit où elle en est — faite, à faire, ou en attente d'un geste
 * précédent —, pour qu'on sache toujours par où commencer.
 */
const props = defineProps({
    icon: { type: [Object, Function], required: true },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    /** primary · danger · violet · muted */
    tone: { type: String, default: 'primary' },
    id: { type: String, default: undefined },
    bodyClass: { type: String, default: '' },
    /** Rang de la section dans l'étape (1, 2, 3…). */
    order: { type: Number, default: null },
    /** done · todo · waiting */
    state: { type: String, default: null },
    /** Ce que la section attend, quand `state` vaut `waiting`. */
    waitingLabel: { type: String, default: 'En attente' },
    /**
     * Une section en attente se replie : son formulaire ne peut pas encore
     * servir. `false` quand elle n'a rien d'autre à montrer que son attente.
     */
    peekable: { type: Boolean, default: true },
    /**
     * Colonne latérale : en-tête resserré, description au survol plutôt que
     * sur trois lignes — la carte reste lisible sans allonger la colonne.
     */
    compact: { type: Boolean, default: false },
});

// Repliée tant qu'elle attend ; elle s'ouvre d'elle-même quand son tour vient.
const expanded = ref(props.state !== 'waiting');
watch(() => props.state, (state) => { expanded.value = state !== 'waiting'; });
const collapsed = computed(() => props.state === 'waiting' && !expanded.value);

const TONES = {
    primary: 'bg-primary/10 text-primary',
    danger: 'bg-red-50 text-destructive dark:bg-red-950/40',
    violet: 'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300',
    muted: 'bg-muted text-muted-foreground',
};

const STATES = {
    done: { variant: 'success', icon: Check, label: 'Fait' },
    todo: { variant: 'default', icon: Clock, label: 'À faire' },
    waiting: { variant: 'outline', icon: Hourglass, label: null },
};
const stateMeta = computed(() => (props.state ? STATES[props.state] ?? null : null));
const cardClass = computed(() => cn(
    'flex scroll-mt-24 flex-col overflow-hidden',
    props.state === 'todo' && 'ring-1 ring-primary/40',
    props.state === 'waiting' && 'border-dashed bg-muted/20 shadow-none',
));
</script>

<template>
    <Card :id="props.id" :class="cardClass">
        <header :class="cn('flex flex-wrap items-start gap-3', compact ? 'px-4 py-3' : 'px-5 py-3.5', !collapsed || $slots.waiting ? 'border-b border-border' : '')" :title="compact && description ? description : undefined">
            <span :class="cn('relative grid shrink-0 place-items-center rounded-lg', compact ? 'h-8 w-8' : 'h-9 w-9', state === 'waiting' ? TONES.muted : (TONES[tone] ?? TONES.primary))" aria-hidden="true">
                <component :is="icon" class="h-4 w-4" />
                <span
                    v-if="order"
                    :class="cn(
                        'absolute -end-1.5 -top-1.5 grid h-4 min-w-4 place-items-center rounded-full px-1 text-[10px] font-bold leading-none ring-2 ring-card',
                        state === 'done' ? 'bg-emerald-600 text-white' : state === 'waiting' ? 'bg-muted-foreground/60 text-white' : 'bg-primary text-primary-foreground',
                    )"
                >{{ order }}</span>
            </span>
            <div class="min-w-0 flex-1 basis-56">
                <h2 class="flex flex-wrap items-center gap-2 text-sm font-semibold text-foreground">
                    <span v-if="order" class="sr-only">Étape {{ order }} :</span>{{ title }}
                    <Badge v-if="stateMeta" :variant="stateMeta.variant"><component :is="stateMeta.icon" class="h-3 w-3" />{{ stateMeta.label ?? waitingLabel }}</Badge>
                    <slot name="badge" />
                </h2>
                <p v-if="description && !compact" class="mt-0.5 text-xs text-muted-foreground">{{ description }}</p>
                <p v-else-if="description" class="sr-only">{{ description }}</p>
            </div>
            <div v-if="$slots.actions && !collapsed" class="flex shrink-0 flex-wrap items-center gap-2"><slot name="actions" /></div>
            <Button
                v-if="state === 'waiting' && peekable"
                size="sm"
                variant="ghost"
                type="button"
                :aria-expanded="expanded"
                @click="expanded = !expanded"
            >{{ expanded ? 'Replier' : 'Afficher' }}<ChevronDown :class="cn('h-3.5 w-3.5 transition-transform', expanded && 'rotate-180')" /></Button>
        </header>
        <div v-if="collapsed && $slots.waiting" :class="cn('text-muted-foreground', compact ? 'px-4 py-2.5 text-xs leading-5' : 'px-5 py-3 text-sm')"><slot name="waiting" /></div>
        <div v-else-if="!collapsed" :class="cn('flex-1 px-5 py-4', bodyClass)"><slot /></div>
        <footer v-if="$slots.footer" class="border-t border-border bg-muted/20 px-5 py-3 text-xs text-muted-foreground"><slot name="footer" /></footer>
    </Card>
</template>
