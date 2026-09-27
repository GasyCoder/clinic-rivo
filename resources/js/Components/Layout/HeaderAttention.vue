<script setup>
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    Bell,
    CheckCircle2,
    ChevronRight,
    Loader2,
    Package,
    UserPlus,
    Wallet,
} from 'lucide-vue-next';
import RefreshIcon from '@/Components/Shadcn/RefreshIcon.vue';
import { cn } from '@/lib/cn';

/**
 * Les points d'attention : l'onglet « À traiter » de la cloche (ADR-197).
 *
 * Ce ne sont pas des notifications : ce panneau compte des **faits en cours**,
 * et une ligne disparaît quand le travail est fait, jamais quand on l'a
 * regardée. Aucune lecture n'y est donc enregistrée ni affichée.
 *
 * Le contenu est chargé quand l'onglet s'affiche, jamais à chaque navigation :
 * le calculer partout ferait payer à toutes les pages un panneau rarement ouvert.
 */
const props = defineProps({
    /** L'onglet est affiché : il (re)charge son contenu. */
    active: { type: Boolean, default: false },
});
const emit = defineEmits(['total', 'navigate']);

const loading = ref(false);
const loaded = ref(false);
const failed = ref(false);
const items = ref([]);
const refreshedAt = ref(null);

/** L'icône est nommée par le serveur : l'écran ne choisit pas l'illustration. */
const ICONS = { wallet: Wallet, package: Package, 'user-plus': UserPlus };

const iconFor = (item) => ICONS[item.icon] ?? Bell;

const refreshedLabel = computed(() => (refreshedAt.value
    ? refreshedAt.value.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
    : null));

const load = async () => {
    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch('/points-attention', { headers: { Accept: 'application/json' } });

        if (!response.ok) {
            failed.value = true;

            return;
        }

        const payload = await response.json();
        items.value = payload.items ?? [];
        emit('total', payload.total ?? 0);
        refreshedAt.value = new Date();
        loaded.value = true;
    } catch (error) {
        // Une panne réseau ne doit pas se lire comme « rien n'attend » : le
        // panneau le dit et propose de réessayer.
        failed.value = true;
    } finally {
        loading.value = false;
    }
};

// Rechargé à chaque affichage : un compteur figé depuis la connexion décrirait
// un état qui n'existe plus.
watch(() => props.active, (active) => {
    if (active) load();
}, { immediate: true });

defineExpose({ load });
</script>

<template>
    <div>
        <div class="flex items-start justify-between gap-3 px-4 py-2.5">
            <p class="text-[11px] leading-4 text-muted-foreground">
                Points d’attention — une ligne disparaît quand le travail est fait, pas quand on l’a regardée.
            </p>
            <button
                type="button"
                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                aria-label="Actualiser les points d’attention"
                :disabled="loading"
                @click="load"
            >
                <RefreshIcon :spinning="loading" class="h-3.5 w-3.5" />
            </button>
        </div>

        <p v-if="loading && !loaded" class="flex items-center gap-2 px-4 py-6 text-xs text-muted-foreground">
            <Loader2 class="h-3.5 w-3.5 animate-spin" aria-hidden="true" />Chargement…
        </p>

        <div v-else-if="failed" class="px-4 py-6 text-center">
            <p class="text-xs font-semibold text-foreground">Impossible de relever les points d’attention.</p>
            <button type="button" class="mt-1 text-xs font-bold text-primary underline underline-offset-2 hover:no-underline" @click="load">
                Réessayer
            </button>
        </div>

        <ul v-else-if="items.length" class="max-h-[22rem] divide-y divide-border overflow-y-auto border-t border-border">
            <li v-for="item in items" :key="item.key">
                <Link
                    :href="item.url"
                    class="group flex items-start gap-3 px-4 py-3 transition-colors hover:bg-accent/60 focus-visible:bg-accent/60 focus-visible:outline-none"
                    @click="emit('navigate')"
                >
                    <span
                        :class="cn('mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg',
                            item.tone === 'warning'
                                ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300'
                                : 'bg-primary/10 text-primary')"
                    >
                        <component :is="iconFor(item)" class="h-[18px] w-[18px]" aria-hidden="true" />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-2">
                            <span class="truncate text-sm font-bold text-foreground">{{ item.label }}</span>
                            <span
                                :class="cn('shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums',
                                    item.tone === 'warning'
                                        ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300'
                                        : 'bg-primary/10 text-primary')"
                            >{{ item.count }}</span>
                        </span>
                        <span class="mt-0.5 block text-[11px] leading-4 text-muted-foreground">{{ item.description }}</span>
                        <span v-if="item.action" class="mt-1 inline-flex items-center gap-1 text-[11px] font-bold text-primary opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100">
                            {{ item.action }}<ChevronRight class="h-3 w-3" aria-hidden="true" />
                        </span>
                    </span>

                    <ChevronRight class="mt-2.5 h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                </Link>
            </li>
        </ul>

        <div v-else class="border-t border-border px-4 py-8 text-center">
            <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-400">
                <CheckCircle2 class="h-5 w-5" aria-hidden="true" />
            </span>
            <p class="mt-2 text-sm font-semibold text-foreground">Rien n’attend d’action</p>
            <p class="mt-0.5 text-[11px] leading-4 text-muted-foreground">
                Ce panneau ne montre que les files auxquelles vous avez accès.
            </p>
        </div>

        <p v-if="loaded && !failed && refreshedLabel" class="border-t border-border px-4 py-2 text-[10px] text-muted-foreground">
            Relevé à {{ refreshedLabel }}
        </p>
    </div>
</template>
