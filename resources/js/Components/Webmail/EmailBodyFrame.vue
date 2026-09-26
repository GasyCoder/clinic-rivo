<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { frameDocument } from '@/utilities/webmail';

/**
 * ADR-194 — le corps d'un message reçu, dans un cadre isolé.
 *
 * Le HTML arrive déjà nettoyé par le serveur ; le cadre est la seconde barrière :
 * `sandbox` sans `allow-scripts` — aucun script ne s'exécute, même oublié par le
 * nettoyage —, une politique de contenu qui n'autorise que les images, et aucun
 * référent envoyé. `allow-same-origin` ne sert qu'à mesurer la hauteur du contenu,
 * `allow-modals` qu'à l'imprimer depuis la page : sans script, le document ne
 * peut rien faire de l'un ni de l'autre. Les liens s'ouvrent dans un nouvel
 * onglet, hors du cadre.
 */
const props = defineProps({
    html: { type: String, default: '' },
    allowRemote: { type: Boolean, default: false },
    title: { type: String, default: 'Contenu du message' },
    /** En-tête imprimé au-dessus du corps (objet, expéditeur, date), déjà échappé ; caché à l'écran. */
    printHeader: { type: String, default: null },
});

const frame = ref(null);
const height = ref(160);
let observer = null;

const srcdoc = computed(() => frameDocument(props.html, { allowRemote: props.allowRemote, printHeader: props.printHeader }));

const measure = () => {
    const body = frame.value?.contentDocument?.body;
    if (!body) return;
    height.value = Math.max(80, Math.ceil(body.scrollHeight) + 8);
};

const onLoad = () => {
    measure();
    observer?.disconnect();
    const body = frame.value?.contentDocument?.body;
    if (body && typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(measure);
        observer.observe(body);
    }
    // Les images arrivent après le chargement du document : on remesure à chacune.
    frame.value?.contentDocument?.querySelectorAll('img').forEach((image) => image.addEventListener('load', measure, { once: true }));
};

onBeforeUnmount(() => observer?.disconnect());

/** Imprime le message seul — objet, expéditeur, date et corps —, pas la page autour. */
const print = () => frame.value?.contentWindow?.print();
defineExpose({ print });
</script>

<template>
    <iframe
        ref="frame"
        :title="title"
        :srcdoc="srcdoc"
        sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-modals"
        referrerpolicy="no-referrer"
        class="block w-full rounded-lg border-0 bg-white"
        :style="{ height: `${height}px` }"
        @load="onLoad"
    />
</template>
