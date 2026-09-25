<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { KeyRound, LogOut, RefreshCw } from 'lucide-vue-next';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import BrandMark from '@/Components/Auth/BrandMark.vue';
import MaintenanceNotice from '@/Components/Maintenance/MaintenanceNotice.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Copyright from '@/Components/UI/Copyright.vue';
import { msUntil } from '@/utilities/maintenance';

/**
 * ADR-193 — ce que voit un compte du site pendant sa maintenance : le message
 * réglé depuis le portail et l'heure de retour prévue. Un compte connecté qui n'a
 * pas le droit de la traverser le lit ici, avec de quoi se déconnecter ; la
 * connexion reste ouverte au compte qui doit vérifier le site.
 *
 * À l'heure de fin prévue, la page se recharge d'elle-même : le site a rouvert.
 */
defineOptions({ layout: GuestLayout });

const props = defineProps({
    notice: { type: Object, required: true },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const site = computed(() => page.props.site ?? {});
const brand = computed(() => site.value.brand ?? 'Clinique Saint Georges');

/** Au-delà d'un jour, on n'arme pas de minuteur : la personne réessaiera. */
const RELOAD_WINDOW_MS = 24 * 60 * 60 * 1000;
let timer = null;
const reloading = ref(false);

const retry = () => {
    reloading.value = true;
    window.location.reload();
};

onMounted(() => {
    const wait = msUntil(props.notice.ends_at);
    if (wait > 0 && wait <= RELOAD_WINDOW_MS) timer = window.setTimeout(retry, wait + 5000);
});
onBeforeUnmount(() => window.clearTimeout(timer));

const logout = () => router.post('/logout');
</script>

<template>
    <Head :title="notice.title" />

    <main class="flex min-h-screen items-center justify-center bg-muted/40 px-4 pb-24 pt-10 sm:px-6">
        <section class="w-full max-w-lg rounded-2xl border border-border bg-card px-6 py-9 text-card-foreground shadow-xl sm:px-10 sm:py-11">
            <BrandMark />

            <MaintenanceNotice :title="notice.title" :message="notice.message" :ends-at="notice.ends_at" />

            <div class="mt-8 flex flex-col items-center gap-3">
                <Button type="button" variant="outline" :disabled="reloading" @click="retry">
                    <RefreshCw :class="['h-4 w-4', reloading && 'animate-spin']" aria-hidden="true" />Réessayer
                </Button>

                <div v-if="user" class="w-full rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                    <p>Connecté(e) en tant que <span class="font-medium text-foreground">{{ user.name }}</span> : ce compte n’a pas accès au site pendant la maintenance.</p>
                    <Button type="button" variant="ghost" size="sm" class="mt-2" @click="logout"><LogOut class="h-3.5 w-3.5" aria-hidden="true" />Se déconnecter</Button>
                </div>
                <a v-else href="/login" class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground hover:underline">
                    <KeyRound class="h-3.5 w-3.5" aria-hidden="true" />Connexion réservée aux comptes autorisés
                </a>
            </div>

            <p class="mt-8 text-center text-[11px] text-muted-foreground"><Copyright :brand="brand" /></p>
        </section>
    </main>
</template>
