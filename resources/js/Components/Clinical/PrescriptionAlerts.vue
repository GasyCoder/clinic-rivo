<script setup>
import { CircleAlert, Info, TriangleAlert } from 'lucide-vue-next';
import { cn } from '@/lib/cn';

/**
 * Ce que le système a relu d'une ligne d'ordonnance (ADR-128).
 *
 * Une aide, jamais un verrou : rien ici n'empêche de valider. Elle dit ce
 * qui cloche — et, pour la dose d'un enfant, ce que le système ne peut pas
 * vérifier, plutôt que de se taire et de laisser croire qu'il a regardé.
 */
defineProps({
    alerts: { type: Array, default: () => [] },
    compact: { type: Boolean, default: false },
});

const TONES = {
    danger: { box: 'border-destructive/30 bg-destructive/10 text-destructive', icon: CircleAlert },
    warning: { box: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200', icon: TriangleAlert },
    info: { box: 'border-border bg-muted/40 text-muted-foreground', icon: Info },
};
</script>

<template>
    <ul v-if="alerts.length" :class="cn('space-y-1.5', compact ? 'mt-1.5' : 'mt-2.5')" role="list" aria-label="Points à vérifier sur cette ligne">
        <li
            v-for="alert in alerts"
            :key="alert.code"
            :class="cn('flex items-start gap-2 rounded-lg border px-2.5 py-2 text-[11px] leading-4', TONES[alert.level].box, alert.level === 'danger' && 'font-semibold')"
        >
            <component :is="TONES[alert.level].icon" class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span>{{ alert.message }}</span>
        </li>
    </ul>
</template>
