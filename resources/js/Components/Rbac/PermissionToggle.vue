<script setup>
import { computed } from 'vue';
import { Check, TriangleAlert } from 'lucide-vue-next';
import { permissionLabel } from '@/utilities/permissionWorkspace';
import { cn } from '@/lib/cn';

/**
 * Une permission du socle d'un rôle : accordée ou non (ADR-178).
 *
 * Deux présentations du même interrupteur. `box` est la case de la grille —
 * la colonne dit déjà « Voir » ou « Supprimer », la case n'a qu'à dire oui
 * ou non. `chip` est la pastille qui porte son texte : sur un écran étroit,
 * ou pour une action qui n'a pas de colonne (« Clôturer la caisse »).
 *
 * Ce que la case doit dire d'elle-même, sans survol :
 *  - accordée ou non — remplie, coche blanche ;
 *  - modifiée depuis l'enregistrement — anneau ambre ;
 *  - sensible — petit triangle ;
 *  - ADR-153 — refusée individuellement à N comptes de ce rôle : la cocher
 *    ne l'ouvrira pas pour eux, et c'est précisément ce qui surprenait.
 */
const props = defineProps({
    permission: { type: Object, required: true },
    granted: { type: Boolean, default: false },
    changed: { type: Boolean, default: false },
    sensitive: { type: Boolean, default: false },
    denyCount: { type: Number, default: 0 },
    dimmed: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    presentation: { type: String, default: 'box' },
    /** Ce que dit la pastille : le verbe de la colonne, ou le libellé complet. */
    text: { type: String, default: '' },
    /** La fonctionnalité, pour que le lecteur d'écran sache de quoi on parle. */
    context: { type: String, default: '' },
});

defineEmits(['toggle']);

const label = computed(() => permissionLabel(props.permission));

const denyText = computed(() => (props.denyCount
    ? `Refusé à ${props.denyCount} compte${props.denyCount > 1 ? 's' : ''} de ce rôle : le cocher ici ne l’ouvrira pas pour ${props.denyCount > 1 ? 'eux' : 'lui'}.`
    : ''));

/** Tout ce qu'on sait de la case, dans l'ordre où on le lit. */
const title = computed(() => [
    `${label.value} · ${props.permission.name}`,
    props.granted ? 'Accordée au rôle' : 'Non accordée',
    props.changed ? 'Modifiée, pas encore enregistrée' : '',
    props.sensitive ? 'Permission sensible' : '',
    denyText.value,
].filter(Boolean).join('\n'));

const ariaLabel = computed(() => [props.text || label.value, props.context].filter(Boolean).join(' — '));
</script>

<template>
    <button
        v-if="presentation === 'box'"
        type="button"
        role="checkbox"
        :aria-checked="granted"
        :aria-label="ariaLabel"
        :aria-description="[changed ? 'Modifiée' : '', sensitive ? 'Sensible' : '', denyText].filter(Boolean).join('. ') || undefined"
        :title="title"
        :disabled="disabled"
        :class="cn(
            'relative grid h-7 w-7 place-items-center rounded-md border transition-[background-color,border-color,box-shadow,opacity] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-card disabled:cursor-not-allowed',
            granted
                ? 'border-primary bg-primary text-primary-foreground shadow-sm hover:bg-primary/90'
                : 'border-input bg-card text-transparent hover:border-primary/60 hover:bg-primary/5',
            changed ? 'ring-2 ring-amber-400 ring-offset-1 ring-offset-card' : '',
            dimmed ? 'opacity-30' : '',
            disabled && ! granted ? 'hover:border-input hover:bg-card' : '',
        )"
        @click="$emit('toggle')"
    >
        <Check class="h-4 w-4" :stroke-width="3" aria-hidden="true" />
        <TriangleAlert
            v-if="sensitive"
            :class="cn('absolute -bottom-1.5 -end-1.5 h-3 w-3 rounded-full bg-card p-px', granted ? 'text-amber-500' : 'text-amber-500/70')"
            aria-hidden="true"
        />
        <span
            v-if="denyCount"
            class="absolute -end-2 -top-2 grid h-4 min-w-4 place-items-center rounded-full bg-destructive px-1 text-[10px] font-bold leading-none text-destructive-foreground ring-2 ring-card"
            aria-hidden="true"
        >{{ denyCount }}</span>
    </button>

    <button
        v-else
        type="button"
        role="checkbox"
        :aria-checked="granted"
        :aria-label="ariaLabel"
        :title="title"
        :disabled="disabled"
        :class="cn(
            'inline-flex max-w-full items-center gap-2 rounded-2xl border py-1 pe-3 ps-1.5 text-start text-xs font-semibold leading-4 transition-[background-color,border-color,box-shadow,opacity] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-card disabled:cursor-not-allowed',
            granted
                ? 'border-primary/40 bg-primary/10 text-foreground hover:bg-primary/15'
                : 'border-border bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground',
            changed ? 'ring-2 ring-amber-400 ring-offset-1 ring-offset-card' : '',
            dimmed ? 'opacity-30' : '',
        )"
        @click="$emit('toggle')"
    >
        <span
            :class="cn(
                'grid h-4 w-4 shrink-0 place-items-center rounded border',
                granted ? 'border-primary bg-primary text-primary-foreground' : 'border-input bg-card text-transparent',
            )"
            aria-hidden="true"
        >
            <Check class="h-3 w-3" :stroke-width="3" />
        </span>
        <span class="min-w-0 [overflow-wrap:anywhere]">{{ text || label }}</span>
        <TriangleAlert v-if="sensitive" class="h-3 w-3 shrink-0 text-amber-500" aria-hidden="true" />
        <span
            v-if="denyCount"
            class="shrink-0 rounded-full bg-destructive/10 px-1.5 py-px text-[10px] font-bold text-destructive"
        >Refusé à {{ denyCount }} compte{{ denyCount > 1 ? 's' : '' }}</span>
    </button>
</template>
