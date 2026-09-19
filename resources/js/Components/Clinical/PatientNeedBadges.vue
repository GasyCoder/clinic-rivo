<script setup>
import { computed } from 'vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import { cn } from '@/lib/cn';
import { formatRelativeTime } from '@/utilities/date';
import {
    NEED_SERVICES,
    needStateText,
    needTitle,
    needVariant,
} from '@/utilities/patientNeeds';

/**
 * Où un patient a encore besoin d'aller, sur sa ligne du répertoire (ADR-119).
 *
 * Une pastille par service — Médecine, Soins, Pharmacie —, avec où en est ce
 * besoin : en attente (ambre) ou pris en charge maintenant (vert). Le détail
 * que la pastille n'a pas la place de porter — depuis quand, sur quel passage —
 * est dans son infobulle.
 *
 * Aucun besoin s'écrit « — » plutôt qu'un blanc : un blanc se lirait « pas
 * encore chargé ».
 */
const props = defineProps({
    /** `[{ service, state, episode_number?, since? }]`, servi par Laravel. */
    needs: { type: Array, default: () => [] },
    /** Nom du patient, pour le lecteur d'écran. */
    patientName: { type: String, default: '' },
    /** Pastilles côte à côte (carte) plutôt qu'empilées (tableau). */
    inline: { type: Boolean, default: false },
    class: { type: String, default: '' },
});

const items = computed(() => props.needs
    .filter((need) => NEED_SERVICES[need.service])
    .map((need) => ({
        need,
        service: NEED_SERVICES[need.service],
        state: needStateText(need),
        variant: needVariant(need),
        title: needTitle(need, formatRelativeTime(need.since)),
    })));
</script>

<template>
    <ul
        v-if="items.length"
        :class="cn('flex gap-1.5', inline ? 'flex-wrap items-center' : 'flex-col items-start', props.class)"
        :aria-label="patientName ? `Besoin actuel de ${patientName}` : 'Besoin actuel'"
    >
        <li v-for="item in items" :key="item.need.service">
            <Badge class="whitespace-nowrap" :variant="item.variant" :title="item.title">
                <component :is="item.service.icon" class="h-3 w-3" aria-hidden="true" />
                {{ item.service.label }}
                <span v-if="item.state" class="font-normal opacity-80">· {{ item.state }}</span>
            </Badge>
        </li>
    </ul>
    <span
        v-else
        :class="cn('text-xs text-muted-foreground', props.class)"
        title="Aucun besoin en Médecine, Soins ou Pharmacie"
    >—</span>
</template>
