<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { RefreshCw } from 'lucide-vue-next';

/**
 * L'icône d'un bouton « Actualiser » ou « Réessayer » : elle tourne tant que
 * la relecture est en cours (`spinning`).
 *
 * Deux règles, pour qu'on la voie vraiment tourner :
 * - elle fait toujours au moins un tour complet : une réponse en 80 ms ne
 *   laisserait voir qu'un tressaillement, et le clic semblerait sans effet ;
 * - elle s'arrête à la fin d'un tour, jamais au milieu : sinon elle
 *   sauterait d'un coup à sa position de départ.
 *
 * La fin d'un tour se lit sur l'animation elle-même (`animationiteration`),
 * pas sur une horloge : elle ne commence qu'à l'image suivante, et une
 * horloge l'arrêterait quelques degrés trop tôt. La minuterie n'est qu'un
 * filet, pour le cas où aucun tour ne se termine — la préférence
 * « animations réduites » (ADR-191) coupe la rotation, et alors aucun
 * événement n'arrive.
 */
const props = defineProps({
    spinning: { type: Boolean, default: false },
    /** Une autre icône lucide, pour garder celle d'un bouton existant. */
    icon: { type: [Object, Function], default: () => RefreshCw },
});

const TURN_MS = 700;

const turning = ref(props.spinning);
let stopping = false;
let fallback = null;

const stop = () => {
    clearTimeout(fallback);
    stopping = false;
    turning.value = false;
};

watch(() => props.spinning, (spinning) => {
    clearTimeout(fallback);

    if (spinning) {
        stopping = false;
        turning.value = true;

        return;
    }

    if (!turning.value) return;

    stopping = true;
    fallback = setTimeout(stop, TURN_MS + 150);
});

const onTurn = () => {
    if (stopping) stop();
};

onBeforeUnmount(() => clearTimeout(fallback));
</script>

<template>
    <component
        :is="icon"
        :style="turning ? { animation: `rivo-refresh-turn ${TURN_MS}ms linear infinite` } : undefined"
        aria-hidden="true"
        @animationiteration="onTurn"
    />
</template>
