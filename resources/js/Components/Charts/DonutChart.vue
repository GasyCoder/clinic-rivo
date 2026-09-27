<script setup>
import { computed } from 'vue';
import { cn } from '@/lib/cn';

/**
 * Un diagramme circulaire — une répartition, jamais une évolution.
 *
 * Le dessin est décoratif : il porte `aria-hidden` et **la légende porte les
 * chiffres**. Un graphique dont les valeurs n'existent que dans le tracé est
 * illisible au lecteur d'écran, et impossible à relire quand on cherche un
 * montant précis.
 *
 * Aucune dépendance : quelques arcs SVG suffisent, et une bibliothèque de
 * graphiques amènerait sa propre palette là où le thème RIVO doit décider.
 */
const props = defineProps({
    /** `[{ key, label, value, tone? }]` */
    segments: { type: Array, default: () => [] },
    /** Formate une valeur pour la légende ; par défaut, un entier. */
    format: { type: Function, default: (value) => new Intl.NumberFormat('fr-FR').format(value) },
    /** Ce que l'on compte, affiché au centre sous le total. */
    unit: { type: String, default: '' },
    /** Les montants avec « Ar » doivent rester dans le trou du diagramme. */
    compactCenter: { type: Boolean, default: false },
    class: { type: String, default: '' },
});

const TONES = ['stroke-primary', 'stroke-emerald-500', 'stroke-amber-500', 'stroke-sky-500', 'stroke-violet-500', 'stroke-rose-500'];
const DOTS = ['bg-primary', 'bg-emerald-500', 'bg-amber-500', 'bg-sky-500', 'bg-violet-500', 'bg-rose-500'];

const RADIUS = 60;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

const total = computed(() => props.segments.reduce((sum, segment) => sum + Number(segment.value ?? 0), 0));

/**
 * Chaque arc démarre là où le précédent s'arrête. Un segment nul ne produit
 * aucun arc : un trait de longueur zéro dessine quand même ses extrémités.
 */
const arcs = computed(() => {
    let offset = 0;

    return props.segments.map((segment, index) => {
        const value = Number(segment.value ?? 0);
        const share = total.value > 0 ? value / total.value : 0;
        const length = share * CIRCUMFERENCE;
        const arc = {
            key: segment.key ?? segment.label,
            label: segment.label,
            value,
            share,
            length,
            offset,
            stroke: TONES[index % TONES.length],
            dot: DOTS[index % DOTS.length],
        };

        offset += length;

        return arc;
    });
});

const percent = (share) => `${Math.round(share * 100)} %`;
</script>

<template>
    <div :class="cn('flex flex-col items-center gap-5 sm:flex-row sm:items-center', props.class)">
        <div class="relative shrink-0">
            <svg viewBox="0 0 160 160" class="h-40 w-40 -rotate-90" aria-hidden="true">
                <circle cx="80" cy="80" :r="RADIUS" fill="none" class="stroke-muted" stroke-width="18" />
                <circle
                    v-for="arc in arcs"
                    :key="arc.key"
                    cx="80"
                    cy="80"
                    :r="RADIUS"
                    fill="none"
                    :class="arc.stroke"
                    stroke-width="18"
                    :stroke-dasharray="`${arc.length} ${CIRCUMFERENCE - arc.length}`"
                    :stroke-dashoffset="-arc.offset"
                />
            </svg>
            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                <span :class="cn('max-w-[7rem] text-center font-heading font-bold leading-tight tabular-nums text-foreground', compactCenter ? 'text-base' : 'text-2xl')">{{ format(total) }}</span>
                <span v-if="unit" class="text-[11px] text-muted-foreground">{{ unit }}</span>
            </div>
        </div>

        <!-- La légende est le contenu : c'est elle qui porte les valeurs. -->
        <ul class="min-w-0 flex-1 space-y-1.5">
            <li v-for="arc in arcs" :key="arc.key" class="flex items-center gap-2.5 text-sm">
                <span :class="cn('h-2.5 w-2.5 shrink-0 rounded-full', arc.dot)" aria-hidden="true" />
                <span class="min-w-0 flex-1 truncate text-foreground">{{ arc.label }}</span>
                <span class="shrink-0 font-semibold tabular-nums text-foreground">{{ format(arc.value) }}</span>
                <span class="w-12 shrink-0 text-end text-xs tabular-nums text-muted-foreground">{{ percent(arc.share) }}</span>
            </li>
            <li v-if="! arcs.length" class="text-sm text-muted-foreground">Aucune donnée sur la période.</li>
        </ul>
    </div>
</template>
