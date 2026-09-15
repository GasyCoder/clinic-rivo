<script setup>
import { computed } from 'vue';
import { CircleAlert, CircleCheck, LoaderCircle, PencilLine } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { formatTime } from '@/utilities/date';

/**
 * L'état réel de la sauvegarde, jamais une supposition.
 *
 * Ce composant ne déclenche rien : il reflète le brouillon serveur de
 * l'ADR-073, déjà en place. Il n'affiche « enregistré » que lorsque le
 * serveur a confirmé un horodatage — prétendre le succès d'une écriture qui
 * a échoué serait pire que ne rien afficher, sur un formulaire clinique.
 */
const props = defineProps({
    /** Le serveur enregistre en ce moment. */
    saving: { type: Boolean, default: false },
    /** Horodatage renvoyé par le serveur au dernier enregistrement réussi. */
    savedAt: { type: [String, null], default: null },
    /** Le formulaire porte des modifications non encore transmises. */
    dirty: { type: Boolean, default: false },
    /** Dernier enregistrement refusé — message serveur. */
    failed: { type: Boolean, default: false },
});

const state = computed(() => {
    if (props.failed) {
        return { icon: CircleAlert, label: 'Échec de l’enregistrement', tone: 'text-destructive', spin: false };
    }
    if (props.saving) {
        return { icon: LoaderCircle, label: 'Enregistrement…', tone: 'text-muted-foreground', spin: true };
    }
    if (props.dirty) {
        return { icon: PencilLine, label: 'Modifications non enregistrées', tone: 'text-amber-600 dark:text-amber-400', spin: false };
    }
    if (props.savedAt) {
        return { icon: CircleCheck, label: `Enregistré à ${formatTime(props.savedAt)}`, tone: 'text-emerald-600 dark:text-emerald-400', spin: false };
    }

    return null;
});
</script>

<template>
    <p
        v-if="state"
        :class="cn('inline-flex items-center gap-1.5 text-xs font-medium', state.tone)"
        role="status"
        aria-live="polite"
    >
        <component :is="state.icon" :class="cn('h-3.5 w-3.5 shrink-0', state.spin && 'animate-spin')" aria-hidden="true" />
        {{ state.label }}
    </p>
</template>
