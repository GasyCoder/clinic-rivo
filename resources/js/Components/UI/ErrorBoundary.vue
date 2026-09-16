<script setup>
import { onErrorCaptured, ref } from 'vue';
import { RotateCcw, TriangleAlert } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';

/**
 * Une section qui tombe ne doit pas emporter l'écran.
 *
 * Constaté sur « Rôles & permissions » : une permission sans libellé faisait
 * lever une exception au milieu du rendu d'un composant enfant. Vue ne
 * remonte pas d'un rendu interrompu — l'enfant reste à moitié monté, chaque
 * changement d'onglet échoue ensuite à le démonter (`vnode is null`,
 * `subTree of null`), et la zone de contenu reste vide **sans un mot**. Vu de
 * l'utilisateur : la page est figée, plus rien n'est cliquable.
 *
 * `onErrorCaptured` intercepte l'exception, remplace la section fautive par
 * un message lisible, et laisse le reste de l'écran fonctionner. Le message
 * technique est affiché : sans lui, il ne resterait qu'à deviner.
 *
 * Ce n'est pas un filet pour écrire du code fragile — les causes se corrigent
 * à la source. C'est la garantie qu'un défaut isolé se voie et reste isolé.
 */
const props = defineProps({
    /** Ce que l'on tentait d'afficher, nommé dans le message. */
    section: { type: String, default: 'Cette section' },
});

const failure = ref(null);

onErrorCaptured((error) => {
    failure.value = error;

    // Interrompt la propagation : sans cela l'erreur remonte au parent, et
    // c'est l'écran entier qui cesse de se mettre à jour.
    return false;
});

const reload = () => window.location.reload();
</script>

<template>
    <Card v-if="failure" class="border-destructive/30 px-5 py-10 text-center">
        <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-red-50 text-destructive dark:bg-red-950/40">
            <TriangleAlert class="h-5 w-5" />
        </span>
        <p class="mt-3 text-sm font-bold text-foreground">{{ section }} n’a pas pu s’afficher</p>
        <p class="mt-1 text-xs text-muted-foreground">
            Le reste de l’écran continue de fonctionner. Rien n’a été modifié sur le site.
        </p>
        <p class="mx-auto mt-3 max-w-xl break-words rounded-lg border border-border bg-muted/40 px-3 py-2 font-mono text-[11px] text-muted-foreground">
            {{ failure.message }}
        </p>
        <Button type="button" variant="outline" size="sm" class="mt-4" @click="reload">
            <RotateCcw class="h-4 w-4" />Recharger la page
        </Button>
    </Card>

    <slot v-else />
</template>
