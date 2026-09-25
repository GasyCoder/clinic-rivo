<script setup>
import { computed } from 'vue';
import { CalendarOff, CalendarSync, Infinity as InfinityIcon } from 'lucide-vue-next';
import OptionTile from '@/Components/Settings/OptionTile.vue';

/**
 * ADR-191 — le repère d'une option de la numérotation : il montre la part du
 * numéro que l'option change — l'année écrite (« 26 », « 2026 »), le séparateur
 * lui-même, autant de barres que de chiffres (la dernière, le « 1 », en couleur),
 * une remise à 1 annuelle ou un compteur sans fin. Décoratif : le nom de
 * l'option est écrit à côté.
 */
const props = defineProps({
    /** `year` · `separator` · `digits` · `reset` */
    kind: { type: String, required: true },
    value: { type: String, required: true },
    /** L'année en cours, pour écrire « 26 » ou « 2026 ». */
    year: { type: Number, default: () => new Date().getFullYear() },
});

const yearText = computed(() => (props.value === '4' ? String(props.year) : String(props.year % 100).padStart(2, '0')));
const digits = computed(() => Math.min(8, Math.max(1, Number(props.value) || 1)));
</script>

<template>
    <OptionTile>
        <template v-if="kind === 'year'">
            <CalendarOff v-if="value === 'none'" class="h-3.5 w-3.5" />
            <span v-else class="font-mono font-semibold leading-none" :class="value === '4' ? 'text-[8px] tracking-tighter' : 'text-[10px]'">{{ yearText }}</span>
        </template>
        <span v-else-if="kind === 'separator'" class="font-mono text-sm font-bold leading-none">{{ value }}</span>
        <span v-else-if="kind === 'digits'" class="flex items-end gap-px">
            <span v-for="index in digits" :key="index" :class="['h-2.5 w-[1.5px] rounded-full', index === digits ? 'bg-primary' : 'bg-current opacity-50']" />
        </span>
        <template v-else-if="kind === 'reset'">
            <CalendarSync v-if="value === 'yearly'" class="h-3.5 w-3.5" />
            <InfinityIcon v-else class="h-3.5 w-3.5" />
        </template>
    </OptionTile>
</template>
