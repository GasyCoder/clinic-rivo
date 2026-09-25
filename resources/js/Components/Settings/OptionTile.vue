<script setup>
/**
 * ADR-191 — la vignette d'une option de liste dans les paramètres : un petit carré
 * où l'option montre ce qu'elle change (une icône, un texte, un dessin). Les
 * repères de l'affichage avancé, de la numérotation et de la monnaie partagent
 * celle-ci. Décorative : le nom de l'option est écrit à côté.
 */
</script>

<template>
    <span class="grid h-6 w-6 shrink-0 place-items-center rounded-md border border-border bg-muted/50 text-foreground" aria-hidden="true"><slot /></span>
</template>
