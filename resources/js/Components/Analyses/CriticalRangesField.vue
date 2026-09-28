<script setup>
import { computed } from 'vue';
import Input from '@/Components/Shadcn/Input.vue';
import { Siren } from 'lucide-vue-next';
import { CRITICAL_PROFILES } from '@/utilities/criticalRanges';

/**
 * ADR-214 — les bornes critiques d'une analyse numérique, par profil. Toutes
 * facultatives : sans borne, le résultat n'est jamais marqué critique d'office.
 * `compact` ne montre que la borne générale (sous-analyses en ligne) ; les
 * autres profils déjà saisis restent en place.
 */
const props = defineProps({
    modelValue: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    errorPrefix: { type: String, default: 'critical_ranges' },
    compact: { type: Boolean, default: false },
});

const profiles = computed(() => (props.compact ? CRITICAL_PROFILES.filter((profile) => profile.key === 'general') : CRITICAL_PROFILES));
const errorOf = (key) => props.errors[`${props.errorPrefix}.${key}.low`] ?? props.errors[`${props.errorPrefix}.${key}.high`] ?? null;
</script>

<template>
    <div v-if="compact" class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
        <span class="inline-flex items-center gap-1 font-semibold text-destructive"><Siren class="h-3.5 w-3.5" /> Critique si</span>
        <span>&lt;</span><Input v-model="modelValue.general.low" size="sm" class="w-20" inputmode="decimal" placeholder="bas" aria-label="Borne critique basse" />
        <span>ou &gt;</span><Input v-model="modelValue.general.high" size="sm" class="w-20" inputmode="decimal" placeholder="haut" aria-label="Borne critique haute" />
        <p v-if="errorOf('general')" class="w-full text-destructive">{{ errorOf('general') }}</p>
    </div>
    <div v-else class="overflow-hidden rounded-lg border border-border">
        <div class="grid grid-cols-[minmax(0,1fr)_6rem_6rem] items-center gap-2 border-b border-border bg-muted/50 px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
            <span>Profil</span><span>Critique si &lt;</span><span>ou si &gt;</span>
        </div>
        <div v-for="profile in profiles" :key="profile.key" class="border-b border-border px-3 py-2 last:border-b-0">
            <div class="grid grid-cols-[minmax(0,1fr)_6rem_6rem] items-center gap-2">
                <span class="text-sm font-medium text-foreground">{{ profile.label }}</span>
                <Input v-model="modelValue[profile.key].low" size="sm" inputmode="decimal" placeholder="—" :aria-label="`Borne critique basse · ${profile.label}`" />
                <Input v-model="modelValue[profile.key].high" size="sm" inputmode="decimal" placeholder="—" :aria-label="`Borne critique haute · ${profile.label}`" />
            </div>
            <p v-if="errorOf(profile.key)" class="mt-1 text-xs text-destructive">{{ errorOf(profile.key) }}</p>
        </div>
    </div>
</template>
