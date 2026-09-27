<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Archive, ArrowRight, Bell, BellOff, CheckCheck, CheckCircle2, Clock, ListChecks, Loader2, Mail, MailOpen } from 'lucide-vue-next';
import Popover from '@/Components/Shadcn/Popover.vue';
import RefreshIcon from '@/Components/Shadcn/RefreshIcon.vue';
import HeaderAttention from '@/Components/Layout/HeaderAttention.vue';
import { cn } from '@/lib/cn';
import { notificationIcon, notificationTime, unreadLabel } from '@/utilities/notifications';

/**
 * ADR-197 — la cloche de l'en-tête : les notifications du compte (ce qu'on est
 * venu lui dire, lu ou non lu) et, dans un second onglet, les points d'attention
 * (ce qui attend encore une action, qui disparaît quand le travail est fait).
 *
 * La pastille compte les notifications non lues. Elle est servie avec chaque page
 * puis relue toutes les minutes tant que l'onglet du navigateur est visible :
 * une notification arrivée pendant qu'on travaille se voit sans recharger.
 */
const POLL_MS = 60_000;

const page = usePage();
const open = ref(false);
const tab = ref('notifications');
const unread = ref(Number(page.props.notifications?.unread ?? 0));
const items = ref([]);
const loaded = ref(false);
const loading = ref(false);
const failed = ref(false);
const attentionTotal = ref(null);
let timer = null;

watch(() => page.props.notifications?.unread, (value) => {
    if (typeof value === 'number') unread.value = value;
});

const badge = computed(() => (unread.value > 99 ? '99+' : String(unread.value)));

const headers = () => ({
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
});

const load = async () => {
    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch('/notifications/resume', { headers: headers(), credentials: 'same-origin' });
        if (!response.ok) throw new Error(String(response.status));
        const payload = await response.json();
        unread.value = Number(payload.unread ?? 0);
        items.value = payload.items ?? [];
        loaded.value = true;
    } catch {
        // Une panne ne doit pas se lire comme « aucune notification ».
        failed.value = true;
    } finally {
        loading.value = false;
    }
};

const post = async (url, body = {}) => {
    const response = await fetch(url, {
        method: 'POST',
        headers: { ...headers(), 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    if (!response.ok) throw new Error(String(response.status));

    return response.json();
};

/** Lu, non lu, archivé : l'écran change tout de suite, le serveur confirme le compte. */
const act = async (item, action) => {
    const before = [...items.value];
    if (action === 'archive') items.value = items.value.filter((candidate) => candidate.id !== item.id);
    else item.read = action === 'read';

    try {
        const payload = await post('/notifications/actions', { ids: [item.id], action });
        unread.value = Number(payload.unread ?? unread.value);
    } catch {
        items.value = before;
        failed.value = true;
    }
};

const readAll = async () => {
    items.value.forEach((item) => { item.read = true; });
    try {
        await post('/notifications/tout-lire');
        unread.value = 0;
    } catch {
        failed.value = true;
    }
};

const openItem = (item) => {
    if (!item.read) {
        item.read = true;
        unread.value = Math.max(0, unread.value - 1);
    }
    open.value = false;
};

const toggle = (value) => {
    open.value = value;
    if (value) load();
};

const poll = () => {
    if (document.visibilityState === 'visible') load();
};

// Jamais pendant le rendu serveur : la relecture ne démarre qu'une fois la page reprise.
onMounted(() => {
    timer = window.setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', poll);
});

onBeforeUnmount(() => {
    window.clearInterval(timer);
    document.removeEventListener('visibilitychange', poll);
});
</script>

<template>
    <Popover :open="open" width-class="w-[min(27rem,calc(100vw-2rem))]" @update:open="toggle">
        <template #trigger>
            <button
                type="button"
                class="relative inline-flex h-9 w-9 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40 data-[state=open]:bg-accent data-[state=open]:text-foreground"
                :aria-label="unread > 0 ? `Notifications : ${unreadLabel(unread)}` : 'Notifications'"
            >
                <Bell class="h-[18px] w-[18px]" aria-hidden="true" />
                <span
                    v-if="unread > 0"
                    class="absolute -end-0.5 -top-0.5 inline-flex min-w-[18px] items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-bold leading-4 text-destructive-foreground ring-2 ring-background"
                    aria-hidden="true"
                >{{ badge }}</span>
            </button>
        </template>

        <!-- EN-TÊTE -->
        <div class="flex items-center justify-between gap-3 px-4 pb-2 pt-3">
            <div class="min-w-0">
                <p class="text-sm font-bold text-foreground">Notifications</p>
                <p class="text-[11px] text-muted-foreground">{{ unreadLabel(unread) }}</p>
            </div>
            <div class="flex items-center gap-0.5">
                <button
                    v-if="tab === 'notifications' && unread > 0"
                    type="button"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md px-2 text-[11px] font-semibold text-primary transition-colors hover:bg-primary/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                    @click="readAll"
                >
                    <CheckCheck class="h-3.5 w-3.5" aria-hidden="true" />Tout marquer comme lu
                </button>
                <button
                    v-if="tab === 'notifications'"
                    type="button"
                    class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                    aria-label="Actualiser les notifications"
                    :disabled="loading"
                    @click="load"
                >
                    <RefreshIcon :spinning="loading" class="h-3.5 w-3.5" />
                </button>
            </div>
        </div>

        <!-- ONGLETS -->
        <div class="flex gap-1 border-b border-border px-3" role="tablist" aria-label="Contenu de la cloche">
            <button
                v-for="entry in [
                    { key: 'notifications', label: 'Notifications', icon: Bell, count: unread },
                    { key: 'attention', label: 'À traiter', icon: ListChecks, count: attentionTotal },
                ]"
                :key="entry.key"
                type="button"
                role="tab"
                :aria-selected="tab === entry.key"
                :class="cn(
                    '-mb-px inline-flex items-center gap-1.5 border-b-2 px-2.5 py-2 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
                    tab === entry.key ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground',
                )"
                @click="tab = entry.key"
            >
                <component :is="entry.icon" class="h-3.5 w-3.5" aria-hidden="true" />
                {{ entry.label }}
                <span
                    v-if="entry.count"
                    :class="cn('rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums', entry.key === 'notifications' ? 'bg-destructive text-destructive-foreground' : 'bg-primary/10 text-primary')"
                >{{ entry.count > 99 ? '99+' : entry.count }}</span>
            </button>
        </div>

        <!-- NOTIFICATIONS -->
        <div v-show="tab === 'notifications'" role="tabpanel">
            <p v-if="loading && !loaded" class="flex items-center gap-2 px-4 py-6 text-xs text-muted-foreground">
                <Loader2 class="h-3.5 w-3.5 animate-spin" aria-hidden="true" />Chargement…
            </p>

            <div v-else-if="failed && !items.length" class="px-4 py-6 text-center">
                <p class="text-xs font-semibold text-foreground">Impossible de lire les notifications.</p>
                <button type="button" class="mt-1 text-xs font-bold text-primary underline underline-offset-2 hover:no-underline" @click="load">Réessayer</button>
            </div>

            <ul v-else-if="items.length" class="max-h-[24rem] divide-y divide-border overflow-y-auto">
                <li v-for="item in items" :key="item.id" class="group relative">
                    <Link
                        :href="item.open_url"
                        :class="cn(
                            'flex items-start gap-3 px-4 py-3 pe-16 transition-colors hover:bg-accent/60 focus-visible:bg-accent/60 focus-visible:outline-none',
                            !item.read && 'bg-primary/[0.04]',
                        )"
                        @click="openItem(item)"
                    >
                        <span :class="cn('relative mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', item.resolution?.state === 'done' ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-primary/10 text-primary')">
                            <component :is="item.resolution?.state === 'done' ? CheckCircle2 : notificationIcon(item.icon)" class="h-[18px] w-[18px]" aria-hidden="true" />
                            <span v-if="!item.read" class="absolute -end-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-destructive ring-2 ring-popover" aria-hidden="true" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span :class="cn('block text-sm leading-5', item.read ? 'font-medium text-muted-foreground' : 'font-bold text-foreground')">
                                <span v-if="!item.read" class="sr-only">Non lue : </span>{{ item.title }}
                            </span>
                            <span class="mt-0.5 line-clamp-2 block text-[11px] leading-4 text-muted-foreground">{{ item.body }}</span>
                            <span v-if="item.resolution" :class="cn('mt-1 inline-flex items-center gap-1 text-[10px] font-semibold', item.resolution.state === 'done' ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400')">
                                <component :is="item.resolution.state === 'done' ? CheckCircle2 : Clock" class="h-3 w-3" aria-hidden="true" />{{ item.resolution.label }}
                            </span>
                            <span class="mt-1 block text-[10px] font-medium text-muted-foreground/80">{{ item.category_label }} · {{ notificationTime(item.created_at) }}</span>
                        </span>
                    </Link>
                    <div class="absolute end-2 top-2.5 flex items-center gap-0.5 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
                        <button
                            type="button"
                            class="inline-flex h-7 w-7 items-center justify-center rounded-md bg-popover text-muted-foreground shadow-sm ring-1 ring-border transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                            :aria-label="item.read ? `Marquer comme non lue : ${item.title}` : `Marquer comme lue : ${item.title}`"
                            :title="item.read ? 'Marquer comme non lue' : 'Marquer comme lue'"
                            @click="act(item, item.read ? 'unread' : 'read')"
                        >
                            <component :is="item.read ? Mail : MailOpen" class="h-3.5 w-3.5" aria-hidden="true" />
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-7 w-7 items-center justify-center rounded-md bg-popover text-muted-foreground shadow-sm ring-1 ring-border transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                            :aria-label="`Archiver : ${item.title}`"
                            title="Archiver"
                            @click="act(item, 'archive')"
                        >
                            <Archive class="h-3.5 w-3.5" aria-hidden="true" />
                        </button>
                    </div>
                </li>
            </ul>

            <div v-else class="px-4 py-8 text-center">
                <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <BellOff class="h-5 w-5" aria-hidden="true" />
                </span>
                <p class="mt-2 text-sm font-semibold text-foreground">Aucune notification</p>
                <p class="mt-0.5 text-[11px] leading-4 text-muted-foreground">Ce qui vous est adressé arrivera ici.</p>
            </div>

            <Link
                href="/notifications"
                class="flex items-center justify-center gap-1.5 border-t border-border px-4 py-2.5 text-xs font-semibold text-primary transition-colors hover:bg-accent/60 focus-visible:bg-accent/60 focus-visible:outline-none"
                @click="open = false"
            >
                Voir toutes les notifications<ArrowRight class="h-3.5 w-3.5" aria-hidden="true" />
            </Link>
        </div>

        <!-- À TRAITER -->
        <div v-show="tab === 'attention'" role="tabpanel">
            <HeaderAttention :active="open && tab === 'attention'" @total="attentionTotal = $event" @navigate="open = false" />
        </div>
    </Popover>
</template>
