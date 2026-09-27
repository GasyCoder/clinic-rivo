<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import BrandMark from '@/Components/Auth/BrandMark.vue';
import Copyright from '@/Components/UI/Copyright.vue';
import { cn } from '@/lib/cn';

/**
 * L'enveloppe des pages d'authentification (ADR-184) : connexion, mot de
 * passe oublié, réinitialisation et activation de compte suivent le modèle
 * réglé pour le site, depuis le portail.
 *
 * - COVER    l'image occupe tout l'écran, le formulaire flotte sur une carte ;
 * - SPLIT    DashWind v1/v3 : le formulaire sur un panneau, l'image à côté ;
 * - CENTERED DashWind v2 : une carte centrée sur un fond sobre, sans image.
 *
 * Seule la disposition change : chaque page garde son formulaire, ses routes
 * et ses messages. Un modèle inconnu retombe sur « Couverture ».
 */
defineProps({
    eyebrow: { type: String, default: '' },
    title: { type: String, required: true },
    description: { type: String, default: '' },
});

const TEMPLATES = ['COVER', 'SPLIT', 'CENTERED'];

const page = usePage();
const site = computed(() => page.props.site ?? {});
const template = computed(() => (TEMPLATES.includes(site.value.authTemplate) ? site.value.authTemplate : 'COVER'));
const coverUrl = computed(() => site.value.authCoverUrl || null);
const siteLabel = computed(() => (site.value.type === 'admin' ? 'Super Administration' : site.value.name));
const tagline = computed(() => site.value.tagline || null);
const brand = computed(() => site.value.brand ?? 'Clinique Saint Georges');
</script>

<template>
    <!-- Couverture : l'image plein écran, la carte à droite. -->
    <div v-if="template === 'COVER'" class="relative min-h-screen overflow-hidden bg-slate-950" data-auth-template="COVER">
        <div class="absolute inset-0" aria-hidden="true">
            <img v-if="coverUrl" :src="coverUrl" alt="" class="h-full w-full object-cover object-center" />
            <div class="absolute inset-0 bg-slate-950/35" />
            <div class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-transparent to-slate-950/15" />
            <!-- Au-dessus du choix d'apparence, posé en bas à gauche par GuestLayout. -->
            <div class="absolute bottom-20 start-9 hidden max-w-lg text-white lg:block xl:start-14">
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-white/75">{{ brand }} · {{ siteLabel }}</p>
                <p v-if="tagline" class="mt-2 font-heading text-xl font-semibold tracking-tight xl:text-2xl">{{ tagline }}</p>
            </div>
        </div>

        <!-- En bas, la place du bouton de thème (GuestLayout) : il ne recouvre jamais le formulaire. -->
        <main class="relative z-10 flex min-h-screen w-full items-center justify-center px-4 pb-20 pt-8 sm:px-8 lg:justify-end lg:px-12 lg:py-8 xl:px-20">
            <section class="w-full max-w-[450px] rounded-2xl border border-border bg-card px-6 py-8 text-card-foreground shadow-2xl sm:px-9 sm:py-10">
                <BrandMark />
                <div class="mb-7">
                    <p v-if="eyebrow" class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">{{ eyebrow }}</p>
                    <h1 class="mt-2 font-heading text-2xl font-bold tracking-tight text-foreground sm:text-[28px]">{{ title }}</h1>
                    <p v-if="description" class="mt-2 text-sm leading-6 text-muted-foreground">{{ description }}</p>
                </div>
                <slot />
                <slot name="footer" />
                <p class="mt-6 text-center text-[11px] text-muted-foreground"><Copyright :brand="brand" /></p>
            </section>
        </main>
    </div>

    <!-- Partagé : le formulaire sur un panneau, l'image à côté (DashWind v1/v3). -->
    <div v-else-if="template === 'SPLIT'" class="grid min-h-screen bg-background lg:grid-cols-[minmax(0,45%)_minmax(0,1fr)]" data-auth-template="SPLIT">
        <div class="relative z-10 flex min-h-screen flex-col bg-card text-card-foreground">
            <main class="m-auto w-full max-w-[420px] px-5 py-10">
                <BrandMark />
                <div class="mb-7">
                    <p v-if="eyebrow" class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">{{ eyebrow }}</p>
                    <h1 class="mt-2 font-heading text-2xl font-bold tracking-tight text-foreground">{{ title }}</h1>
                    <p v-if="description" class="mt-2 text-sm leading-6 text-muted-foreground">{{ description }}</p>
                </div>
                <slot />
                <slot name="footer" />
            </main>
            <footer class="px-5 pb-20 text-center text-xs text-muted-foreground lg:pb-8"><Copyright :brand="brand" /></footer>
        </div>
        <aside :class="cn('relative hidden overflow-hidden lg:block', coverUrl ? 'bg-slate-950' : 'bg-primary')" aria-hidden="true">
            <img v-if="coverUrl" :src="coverUrl" alt="" class="absolute inset-0 h-full w-full object-cover object-center" />
            <div v-if="coverUrl" class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-slate-950/10 to-transparent" />
            <div class="absolute inset-x-0 bottom-0 p-10 text-white xl:p-14">
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-white/75">{{ brand }} · {{ siteLabel }}</p>
                <p v-if="tagline" class="mt-2 max-w-lg font-heading text-2xl font-semibold tracking-tight">{{ tagline }}</p>
            </div>
        </aside>
    </div>

    <!-- Centré : une carte au milieu d'un fond sobre (DashWind v2). -->
    <div v-else class="relative flex min-h-screen flex-col items-center justify-center overflow-hidden bg-muted/40 px-4 pb-20 pt-10 sm:pb-10" data-auth-template="CENTERED">
        <div class="absolute inset-x-0 top-0 h-72 bg-primary" aria-hidden="true" />
        <header class="relative z-10 mb-6 max-w-[460px] text-center text-primary-foreground">
            <p class="text-xs font-bold uppercase tracking-[0.18em] opacity-80">{{ brand }} · {{ siteLabel }}</p>
            <p v-if="tagline" class="mt-1.5 font-heading text-lg font-semibold tracking-tight">{{ tagline }}</p>
        </header>
        <main class="relative z-10 w-full max-w-[460px] rounded-2xl border border-border bg-card px-6 py-8 text-card-foreground shadow-xl sm:px-10 sm:py-10">
            <BrandMark />
            <div class="mb-7">
                <p v-if="eyebrow" class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">{{ eyebrow }}</p>
                <h1 class="mt-2 font-heading text-2xl font-bold tracking-tight text-foreground">{{ title }}</h1>
                <p v-if="description" class="mt-2 text-sm leading-6 text-muted-foreground">{{ description }}</p>
            </div>
            <slot />
            <slot name="footer" />
        </main>
        <footer class="relative z-10 mt-6 text-center text-xs text-muted-foreground"><Copyright :brand="brand" /></footer>
    </div>
</template>
