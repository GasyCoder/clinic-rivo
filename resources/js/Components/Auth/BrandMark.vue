<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { monogramOf } from '@/lib/brand';

// Le logo est toujours centré dans la carte, quel que soit le modèle
// (Couverture, Partagé, Centré) et quelle que soit sa largeur : un logo
// étroit, calé à gauche, laissait un vide à sa droite.
const page = usePage();
const site = computed(() => page.props.site);
const logoUrl = computed(() => site.value.documents?.logo_url || null);

// Les mêmes initiales que la barre latérale : deux repli différents pour la
// même enseigne se liraient comme deux applications.
const monogram = computed(() => monogramOf(site.value.brand));
</script>

<template>
    <div class="mb-8 flex min-h-16 items-center justify-center" data-auth-logo>
        <img
            v-if="logoUrl"
            :src="logoUrl"
            :alt="`Logo ${site.brand}`"
            class="mx-auto h-auto w-full max-w-[270px] object-contain object-center"
        />
        <div v-else class="flex items-center justify-center gap-3">
            <span class="flex h-11 w-11 flex-none items-center justify-center rounded-lg bg-primary font-heading text-sm font-bold text-primary-foreground">
                {{ monogram }}
            </span>
            <div class="font-heading text-sm font-bold text-foreground">{{ site.brand }}</div>
        </div>
    </div>
</template>
