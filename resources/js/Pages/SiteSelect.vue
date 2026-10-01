<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { ArrowRight, Building2, ShieldCheck, Star, X } from 'lucide-vue-next';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Label from '@/Components/Shadcn/Label.vue';
import Skeleton from '@/Components/Shadcn/Skeleton.vue';
import { monogramOf } from '@/lib/brand';
import { centralLogoOf, identityOf, siteLogoOf, statusOf } from '@/utilities/gatewaySites';
import { useSitePreferenceStore } from '@/stores/sitePreference';

defineOptions({ layout: GuestLayout });

const props = defineProps({
    clinics: { type: Array, required: true },
    adminUrl: { type: String, required: true },
    // ADR-184 — prop différée : `undefined` tant que les sites n'ont pas répondu.
    branding: { type: Object, default: undefined },
});

const page = usePage();
const sitePreference = useSitePreferenceStore();
const rememberChoice = ref(false);

// Une image qui ne se charge pas cède la place à la suivante (icône, initiales).
const broken = reactive(new Set());
const markBroken = (url) => broken.add(url);

const loading = computed(() => props.branding === undefined);
const brand = computed(() => props.branding?.central?.brand || page.props.site.brand);
const centralLogo = computed(() => centralLogoOf(props.branding, page.props.site.documents?.logo_url, broken));
const monogram = computed(() => monogramOf(brand.value));
const year = new Date().getFullYear();

const sites = computed(() => props.clinics.map((clinic) => {
    const identity = identityOf(props.branding, clinic.code);

    return {
        ...clinic,
        identity,
        logo: siteLogoOf(identity, broken),
        status: statusOf(identity),
        href: `${clinic.url}/login`,
    };
}));

// localStorage n'existe pas au rendu serveur : le site habituel n'apparaît
// qu'une fois la page reprise par le navigateur (même rendu des deux côtés).
const isMounted = ref(false);
onMounted(() => {
    isMounted.value = true;
});

const preferredSite = computed(() => (isMounted.value
    ? sites.value.find((site) => site.code === sitePreference.preferredSiteCode) ?? null
    : null));

const selectSite = (site) => {
    if (rememberChoice.value) {
        sitePreference.remember(site.code);
    }
};
</script>

<template>
    <Head title="Choisir votre site" />

    <div class="relative flex min-h-screen flex-col overflow-hidden bg-background" data-gateway>
        <!-- Un halo discret aux couleurs du site, sans image à charger. -->
        <div
            class="pointer-events-none absolute inset-x-0 top-0 h-[420px] bg-gradient-to-b from-primary/10 via-primary/5 to-transparent"
            aria-hidden="true"
        />

        <main class="relative mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center px-4 py-12 sm:px-6">
            <header class="mb-10 flex flex-col items-center text-center">
                <div class="mb-6 flex min-h-20 items-center justify-center" data-gateway-logo>
                    <Skeleton v-if="loading" class="h-20 w-60 rounded-xl" />
                    <img
                        v-else-if="centralLogo"
                        :src="centralLogo"
                        :alt="`Logo ${brand}`"
                        fetchpriority="high"
                        class="h-auto max-h-24 w-auto max-w-[280px] object-contain"
                        @error="markBroken(centralLogo)"
                    />
                    <span
                        v-else
                        class="flex h-16 w-16 items-center justify-center rounded-2xl bg-primary font-heading text-xl font-bold text-primary-foreground shadow-sm"
                    >
                        {{ monogram }}
                    </span>
                </div>

                <h1 class="font-heading text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
                    Choisissez votre site
                </h1>
                <p class="mt-2 max-w-md text-sm leading-6 text-muted-foreground">
                    Connectez-vous à l’établissement de {{ brand }} où vous travaillez aujourd’hui.
                </p>
            </header>

            <!-- Le site habituel, retenu sur ce poste seulement. -->
            <section
                v-if="preferredSite"
                class="mx-auto mb-8 flex w-full max-w-2xl flex-col gap-4 rounded-xl border border-primary/40 bg-primary/5 p-4 shadow-sm sm:flex-row sm:items-center"
                data-gateway-preferred
            >
                <div class="flex min-w-0 flex-1 items-center gap-4">
                    <span class="flex h-12 w-12 flex-none items-center justify-center rounded-lg border border-border bg-card">
                        <img
                            v-if="preferredSite.logo"
                            :src="preferredSite.logo"
                            alt=""
                            class="max-h-9 max-w-10 object-contain"
                            @error="markBroken(preferredSite.logo)"
                        />
                        <Building2 v-else class="h-5 w-5 text-primary" />
                    </span>
                    <div class="min-w-0">
                        <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-primary">
                            <Star class="h-3.5 w-3.5" /> Votre site habituel
                        </p>
                        <p class="truncate font-heading text-base font-bold text-foreground">{{ preferredSite.name }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="ghost" size="sm" type="button" @click="sitePreference.forget">
                        <X class="h-4 w-4" /> Oublier
                    </Button>
                    <Button as="a" :href="preferredSite.href">
                        Continuer <ArrowRight class="h-4 w-4" />
                    </Button>
                </div>
            </section>

            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" aria-label="Sites de la clinique">
                <li v-for="site in sites" :key="site.code">
                    <a
                        :href="site.href"
                        class="group flex h-full flex-col rounded-xl border border-border bg-card p-5 text-card-foreground shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/60 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                        :data-gateway-site="site.code"
                        @click="selectSite(site)"
                    >
                        <div class="mb-5 flex h-24 items-center justify-center rounded-lg border border-border/60 bg-muted/40 px-4">
                            <Skeleton v-if="loading" class="h-12 w-36 rounded-md" />
                            <img
                                v-else-if="site.logo"
                                :src="site.logo"
                                :alt="`Logo ${site.name}`"
                                loading="lazy"
                                class="max-h-16 max-w-full object-contain"
                                @error="markBroken(site.logo)"
                            />
                            <span
                                v-else
                                class="flex h-14 w-14 items-center justify-center rounded-xl bg-primary/10 text-primary"
                            >
                                <Building2 class="h-7 w-7" />
                            </span>
                        </div>

                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs text-muted-foreground">{{ site.identity?.brand || brand }}</p>
                                <p class="mt-0.5 truncate font-heading text-lg font-bold uppercase tracking-wide text-foreground">
                                    {{ site.name }}
                                </p>
                            </div>
                            <Badge variant="outline" class="flex-none font-mono">{{ site.code }}</Badge>
                        </div>

                        <div class="mt-auto flex items-center justify-between gap-3 pt-5">
                            <Skeleton v-if="loading" class="h-6 w-24 rounded-full" />
                            <Badge v-else-if="site.status" :tone="site.status.tone">
                                <span
                                    v-if="site.status.tone === 'success'"
                                    class="h-1.5 w-1.5 rounded-full bg-current"
                                    aria-hidden="true"
                                />
                                {{ site.status.label }}
                            </Badge>
                            <span class="inline-flex items-center gap-1.5 text-sm font-semibold text-primary">
                                Se connecter
                                <ArrowRight class="h-4 w-4 transition-transform duration-200 group-hover:translate-x-0.5" />
                            </span>
                        </div>
                    </a>
                </li>
            </ul>

            <div class="mt-6 flex items-center justify-center gap-2">
                <Checkbox id="remember-site" v-model="rememberChoice" />
                <Label for="remember-site" class="cursor-pointer text-muted-foreground">
                    Se souvenir de mon choix sur ce poste
                </Label>
            </div>

            <div class="mx-auto my-8 flex w-full max-w-sm items-center gap-3" aria-hidden="true">
                <span class="h-px flex-1 bg-border" />
                <span class="text-xs font-semibold uppercase tracking-widest text-muted-foreground">ou</span>
                <span class="h-px flex-1 bg-border" />
            </div>

            <div class="flex justify-center">
                <Button as="a" variant="outline" :href="`${adminUrl}/login`" data-gateway-admin>
                    <ShieldCheck class="h-4 w-4" />
                    Super Administration
                </Button>
            </div>
        </main>

        <footer class="relative px-4 pb-6 text-center text-xs text-muted-foreground">
            <span>
                &copy; {{ year }} {{ brand }} — développé par
                <a
                    href="https://www.linkedin.com/in/florentbezara/"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="font-medium text-primary underline-offset-4 hover:underline"
                >GasyCoder</a>
            </span>
        </footer>
    </div>
</template>
