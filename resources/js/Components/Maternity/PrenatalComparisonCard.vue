<script setup>
import { ArrowRight, Scale } from 'lucide-vue-next';
import Card from '@/Components/Shadcn/Card.vue';
import { formatDateTime } from '@/utilities/date';

/**
 * Comparaison prénatale (ADR-201) : ce que le serveur a chiffré, affiché tel
 * quel. Les écarts, leur signe et leur unité viennent de
 * `PrenatalComparisonPresenter` ; Vue n'en calcule ni n'en interprète aucun.
 */
defineProps({
    comparison: { type: Object, required: true },
    /** Sans carte ni en-tête : dans l'onglet « Comparaison » du suivi. */
    bare: { type: Boolean, default: false },
});

// Mise en forme seulement (virgule décimale, comme les écarts servis) : aucune valeur n'est recalculée.
const number = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 });
const display = (value, unit) => {
    if (value === null || value === undefined || value === '') return '—';
    const shown = typeof value === 'number' ? number.format(value) : value;

    return `${shown}${unit ? ` ${unit}` : ''}`;
};
</script>

<template>
    <component :is="bare ? 'div' : Card" :class="bare ? '' : 'overflow-hidden'">
        <header v-if="!bare" class="border-b border-border px-5 py-4">
            <h2 class="flex items-center gap-2 text-sm font-bold text-foreground"><Scale class="h-4 w-4" aria-hidden="true" />Comparaison prénatale</h2>
        </header>
        <p class="border-b border-border px-5 py-2.5 text-xs text-muted-foreground">
            <template v-if="bare"><span class="font-semibold text-foreground">Comparaison prénatale</span> · </template>
            Information factuelle entre la consultation précédente et celle-ci — aucun diagnostic automatique.
            <template v-if="comparison.interval_label"> Consultation {{ comparison.interval_label }}.</template>
        </p>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[560px] text-left text-sm">
                <thead class="bg-muted/45 text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-5 py-2.5">Paramètre</th>
                        <th class="px-5 py-2.5">{{ comparison.previous_visit.label ?? 'Précédent' }}<br><span class="font-normal normal-case">{{ formatDateTime(comparison.previous_visit.at) }}</span></th>
                        <th class="px-5 py-2.5">{{ comparison.current_visit.label ?? 'Aujourd’hui' }}<br><span class="font-normal normal-case">{{ formatDateTime(comparison.current_visit.at) }}</span></th>
                        <th class="px-5 py-2.5">Variation</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="field in comparison.fields" :key="field.key">
                        <th class="px-5 py-2.5 font-semibold text-foreground">{{ field.label }}</th>
                        <td class="px-5 py-2.5 text-muted-foreground">{{ display(field.previous, field.unit) }}</td>
                        <td class="px-5 py-2.5 font-semibold text-foreground"><span class="inline-flex items-center gap-2"><ArrowRight class="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />{{ display(field.current, field.unit) }}</span></td>
                        <td class="px-5 py-2.5 tabular-nums text-muted-foreground">{{ field.delta_label ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </component>
</template>
