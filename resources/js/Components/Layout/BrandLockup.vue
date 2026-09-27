<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { monogramOf } from '@/lib/brand';

/**
 * L'enseigne, telle qu'elle se présente dans la navigation.
 *
 * Le bandeau latéral la portait en casse normale, la barre du haut — seul
 * endroit où elle apparaît sur un téléphone — en 16 px gras : deux rendus
 * pour la même clinique. Un seul composant les tient désormais ensemble.
 *
 * Le logo officiel n'est pas repris ici : c'est un bloc horizontal (emblème,
 * nom, devise) dont la devise devient illisible sous 40 px de haut. Il reste
 * à sa place sur les documents imprimés et la page de connexion, qui lui
 * laissent la largeur nécessaire.
 */
const page = usePage();
const site = computed(() => page.props.site);
const brand = computed(() => site.value?.brand ?? '');
const monogram = computed(() => monogramOf(brand.value));
// ADR-184 — l'icône carrée réglée pour le site remplace les initiales ; sans
// elle, la pastille garde les initiales du nom.
const iconUrl = computed(() => site.value?.iconUrl ?? null);

// Sous l'enseigne : le site où l'on travaille, ou le portail central. Résolu
// ici et non par l'appelant, pour que la barre du haut et le bandeau latéral
// ne puissent pas répondre différemment à la question « où suis-je ? ».
const subtitle = computed(() => site.value?.type === 'admin' ? 'Super Administration' : (site.value?.name ?? ''));
const title = computed(() => [brand.value, subtitle.value].filter(Boolean).join(' · '));
</script>

<template>
    <Link
        href="/"
        :title="title"
        :aria-label="title"
        class="flex min-w-0 items-center gap-2.5"
    >
        <img
            v-if="iconUrl"
            :src="iconUrl"
            alt=""
            class="h-8 w-8 shrink-0 rounded-lg bg-card object-contain shadow-sm ring-1 ring-border"
            aria-hidden="true"
        />
        <span
            v-else
            class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-gradient-to-br from-primary to-primary/80 font-heading text-[10px] font-bold uppercase leading-none tracking-tight text-primary-foreground shadow-sm ring-1 ring-inset ring-primary-foreground/15"
            aria-hidden="true"
        >{{ monogram }}</span>
        <span class="flex min-w-0 flex-col">
            <span class="truncate font-heading text-[11px] font-bold uppercase leading-none tracking-[0.06em] text-foreground">{{ brand }}</span>
            <span
                v-if="subtitle"
                class="mt-1 truncate text-[9px] font-bold uppercase leading-none tracking-[0.16em] text-muted-foreground"
            >{{ subtitle }}</span>
        </span>
    </Link>
</template>
