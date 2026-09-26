<script setup>
import { ArrowRight, Scale } from 'lucide-vue-next';
import Card from '@/Components/Shadcn/Card.vue';
import { formatDateTime } from '@/utilities/date';

defineProps({ comparison: { type: Object, required: true } });

const display = (value, unit) => value === null || value === undefined || value === '' ? '—' : `${value}${unit ? ` ${unit}` : ''}`;
const delta = (value) => value === null || value === undefined ? null : `${value > 0 ? '+' : ''}${value} kg`;
</script>

<template>
    <Card class="overflow-hidden">
        <header class="border-b border-border px-5 py-4">
            <h2 class="flex items-center gap-2 text-sm font-bold text-foreground"><Scale class="h-4 w-4" />Comparaison prénatale</h2>
            <p class="mt-1 text-xs text-muted-foreground">Information factuelle entre la dernière consultation et aujourd’hui — aucun diagnostic automatique.</p>
        </header>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[560px] text-left text-sm">
                <thead class="bg-muted/45 text-xs uppercase tracking-wide text-muted-foreground">
                    <tr><th class="px-5 py-3">Paramètre</th><th class="px-5 py-3">Précédent<br><span class="normal-case font-normal">{{ formatDateTime(comparison.previous_visit.at) }}</span></th><th class="px-5 py-3">Aujourd’hui<br><span class="normal-case font-normal">{{ formatDateTime(comparison.current_visit.at) }}</span></th><th class="px-5 py-3">Variation</th></tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="field in comparison.fields" :key="field.key">
                        <th class="px-5 py-3 font-semibold text-foreground">{{ field.label }}</th>
                        <td class="px-5 py-3 text-muted-foreground">{{ display(field.previous, field.unit) }}</td>
                        <td class="px-5 py-3 font-semibold text-foreground"><span class="inline-flex items-center gap-2"><ArrowRight class="h-3.5 w-3.5 text-muted-foreground" />{{ display(field.current, field.unit) }}</span></td>
                        <td class="px-5 py-3 text-muted-foreground">{{ delta(field.delta) ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>
</template>
