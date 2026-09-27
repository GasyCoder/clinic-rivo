<script setup>
import { computed } from 'vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import { CircleCheck, CircleAlert, CircleSlash, Clock, PauseCircle } from 'lucide-vue-next';

/**
 * ADR-170 — l'état de l'autorisation anesthésique, en un coup d'œil.
 *
 * Le libellé vient du serveur (`AnesthesiaClearanceStatus::label()`) : l'écran
 * ne traduit aucun code. L'icône double la couleur, jamais l'inverse.
 */
const props = defineProps({
    /** @type {{status: string, label: string, badge: string, expired?: boolean}|null} */
    clearance: { type: Object, default: null },
});

const ICONS = {
    CLEARED: CircleCheck,
    CLEARED_WITH_CONDITIONS: CircleAlert,
    NOT_CLEARED: CircleSlash,
    DEFERRED: PauseCircle,
    DRAFT: Clock,
};

const icon = computed(() => ICONS[props.clearance?.status] ?? Clock);
const label = computed(() => (props.clearance?.expired
    ? `${props.clearance.label} — échue`
    : (props.clearance?.label ?? 'Aucune décision')));
const variant = computed(() => (props.clearance?.expired ? 'destructive' : (props.clearance?.badge ?? 'outline')));
</script>

<template>
    <Badge :variant="variant">
        <component :is="icon" class="h-3 w-3" aria-hidden="true" />
        <span class="sr-only">Anesthésie : </span>{{ label }}
    </Badge>
</template>
