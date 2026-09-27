<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Archive,
    ArchiveRestore,
    ArrowRight,
    Bell,
    BellOff,
    CheckCheck,
    CheckCircle2,
    Clock,
    Inbox,
    Mail,
    MailOpen,
    Search,
    Tags,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Select from '@/Components/Shadcn/Select.vue';
import HrPagination from '@/Pages/Administration/Partials/HrPagination.vue';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/utilities/date';
import { groupNotificationsByDay, notificationIcon, notificationTime, unreadLabel } from '@/utilities/notifications';

/**
 * ADR-197 — toutes les notifications du compte : les non lues, toutes celles
 * qui sont courantes (lues comprises), et les archivées. Rangées par jour, les
 * plus récentes d'abord ; ouvrir une notification la marque lue et suit son lien.
 * Archiver range sans effacer : l'onglet « Archivées » les rend.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    inbox: { type: Object, required: true },
    counts: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    // Base dont les migrations n'ont pas été jouées : la page le dit au lieu d'une erreur.
    unavailable: { type: String, default: null },
});

const TABS = [
    { key: 'all', label: 'Toutes', icon: Inbox },
    { key: 'unread', label: 'Non lues', icon: Bell },
    { key: 'archived', label: 'Archivées', icon: Archive },
];

const search = ref(props.filters.q ?? '');
const category = ref(props.filters.category ?? '');
const selected = ref(new Set());
const busy = ref(false);

const items = computed(() => props.inbox.data ?? []);
const groups = computed(() => groupNotificationsByDay(items.value));
const archivedTab = computed(() => props.filters.status === 'archived');
const categoryOptions = computed(() => [
    { value: '', label: 'Toutes les catégories' },
    ...props.categories.map((entry) => ({ value: entry.key, label: entry.label })),
]);

const visit = (changes = {}) => {
    const query = {
        status: props.filters.status,
        category: category.value || undefined,
        q: search.value.trim() || undefined,
        ...changes,
    };
    Object.keys(query).forEach((key) => { if (query[key] === undefined || query[key] === null || query[key] === '') delete query[key]; });
    selected.value = new Set();
    router.get('/notifications', query, { preserveState: true, preserveScroll: true, replace: true });
};

let searchTimer = null;
watch(search, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => visit({ page: undefined }), 300);
});
watch(category, () => visit({ page: undefined }));

// La sélection ne survit pas à un changement de page ou de filtre.
watch(() => props.inbox.current_page, () => { selected.value = new Set(); });

const allSelected = computed(() => items.value.length > 0 && items.value.every((item) => selected.value.has(item.id)));
const someSelected = computed(() => !allSelected.value && items.value.some((item) => selected.value.has(item.id)));
const toggleAll = (value) => { selected.value = value ? new Set(items.value.map((item) => item.id)) : new Set(); };
const toggleOne = (id, value) => {
    const next = new Set(selected.value);
    if (value) next.add(id);
    else next.delete(id);
    selected.value = next;
};

const act = (ids, action) => {
    if (!ids.length || busy.value) return;
    busy.value = true;
    router.post('/notifications/actions', { ids, action }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { selected.value = new Set(); },
        onFinish: () => { busy.value = false; },
    });
};

const readAll = () => {
    busy.value = true;
    router.post('/notifications/tout-lire', {}, { preserveScroll: true, onFinish: () => { busy.value = false; } });
};

const selectionIds = computed(() => [...selected.value]);
const selectionHasUnread = computed(() => items.value.some((item) => selected.value.has(item.id) && !item.read));
const selectionHasRead = computed(() => items.value.some((item) => selected.value.has(item.id) && item.read));

const empty = computed(() => {
    if (search.value.trim() || category.value) {
        return { icon: Search, title: 'Aucune notification ne correspond', text: 'Changez la recherche ou la catégorie.' };
    }

    return {
        unread: { icon: CheckCheck, title: 'Tout est lu', text: 'Aucune notification n’attend votre lecture.' },
        archived: { icon: Archive, title: 'Aucune notification archivée', text: 'Archiver range une notification ici, sans l’effacer.' },
    }[props.filters.status] ?? { icon: BellOff, title: 'Aucune notification', text: 'Ce qui vous est adressé arrivera ici, et dans la cloche en haut de l’écran.' };
});
</script>

<template>
    <Head title="Notifications" />

    <div class="flex flex-col gap-6">
        <!-- En-tête -->
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary" aria-hidden="true"><Bell class="h-5 w-5" /></span>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">Mon compte</p>
                    <h1 class="font-heading text-2xl font-bold text-foreground">Notifications</h1>
                    <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                        Ce qui vous a été adressé. Une notification lue reste ici ; archivée, elle quitte la liste sans être effacée.
                    </p>
                </div>
            </div>
            <Button v-if="counts.unread > 0" type="button" variant="outline" size="sm" :disabled="busy" @click="readAll">
                <CheckCheck class="h-4 w-4" aria-hidden="true" />Tout marquer comme lu
            </Button>
        </header>

        <div
            v-if="unavailable"
            role="alert"
            class="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
        >
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <p>{{ unavailable }}</p>
        </div>

        <section class="min-w-0 rounded-xl border border-border bg-card shadow-sm">
            <!-- Onglets -->
            <div class="flex flex-wrap items-center gap-1 border-b border-border px-3 pt-2" role="tablist" aria-label="Notifications">
                <button
                    v-for="tab in TABS"
                    :key="tab.key"
                    type="button"
                    role="tab"
                    :aria-selected="filters.status === tab.key"
                    :class="cn(
                        '-mb-px inline-flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
                        filters.status === tab.key ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground',
                    )"
                    @click="visit({ status: tab.key, page: undefined })"
                >
                    <component :is="tab.icon" class="h-4 w-4" aria-hidden="true" />
                    {{ tab.label }}
                    <span
                        :class="cn(
                            'rounded-full px-1.5 py-0.5 text-[11px] font-bold tabular-nums',
                            tab.key === 'unread' && counts.unread > 0 ? 'bg-destructive text-destructive-foreground' : 'bg-muted text-muted-foreground',
                        )"
                    >{{ counts[tab.key] }}</span>
                </button>
            </div>

            <!-- Filtres -->
            <div class="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center">
                <div class="min-w-0 flex-1">
                    <IconInput v-model="search" :icon="Search" type="search" placeholder="Chercher dans les notifications…" aria-label="Chercher dans les notifications" />
                </div>
                <div class="w-full sm:w-64">
                    <Select v-model="category" :options="categoryOptions" :icon="Tags" aria-label="Catégorie" />
                </div>
            </div>

            <!-- Sélection -->
            <div class="flex min-h-[3.25rem] flex-wrap items-center gap-2 border-b border-border px-4 py-2">
                <Checkbox
                    :model-value="allSelected ? true : (someSelected ? 'indeterminate' : false)"
                    :disabled="!items.length"
                    aria-label="Tout sélectionner sur cette page"
                    @update:model-value="toggleAll"
                />
                <span class="text-xs text-muted-foreground">
                    <template v-if="selected.size">{{ selected.size }} sélectionnée{{ selected.size > 1 ? 's' : '' }}</template>
                    <template v-else>{{ unreadLabel(counts.unread) }}</template>
                </span>
                <div v-if="selected.size" class="ms-auto flex flex-wrap items-center gap-1.5">
                    <Button v-if="selectionHasUnread" type="button" variant="white-outline" size="xs" :disabled="busy" @click="act(selectionIds, 'read')">
                        <MailOpen class="h-3.5 w-3.5" aria-hidden="true" />Marquer lues
                    </Button>
                    <Button v-if="selectionHasRead" type="button" variant="white-outline" size="xs" :disabled="busy" @click="act(selectionIds, 'unread')">
                        <Mail class="h-3.5 w-3.5" aria-hidden="true" />Marquer non lues
                    </Button>
                    <Button v-if="!archivedTab" type="button" variant="white-outline" size="xs" :disabled="busy" @click="act(selectionIds, 'archive')">
                        <Archive class="h-3.5 w-3.5" aria-hidden="true" />Archiver
                    </Button>
                    <Button v-else type="button" variant="white-outline" size="xs" :disabled="busy" @click="act(selectionIds, 'unarchive')">
                        <ArchiveRestore class="h-3.5 w-3.5" aria-hidden="true" />Remettre dans la liste
                    </Button>
                    <Button type="button" variant="ghost" size="xs" icon aria-label="Annuler la sélection" @click="toggleAll(false)">
                        <X class="h-3.5 w-3.5" aria-hidden="true" />
                    </Button>
                </div>
            </div>

            <!-- Liste, par jour -->
            <div v-if="groups.length">
                <div v-for="group in groups" :key="group.key">
                    <h2 class="border-b border-border bg-muted/40 px-4 py-1.5 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">{{ group.label }}</h2>
                    <ul class="divide-y divide-border">
                        <li
                            v-for="item in group.items"
                            :key="item.id"
                            :class="cn('group flex items-start gap-3 px-4 py-3.5 transition-colors hover:bg-accent/40', !item.read && !item.archived && 'bg-primary/[0.04]')"
                        >
                            <Checkbox
                                class="mt-3"
                                :model-value="selected.has(item.id)"
                                :aria-label="`Sélectionner : ${item.title}`"
                                @update:model-value="toggleOne(item.id, $event)"
                            />
                            <span :class="cn('relative mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg', item.resolution?.state === 'done' ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-primary/10 text-primary')">
                                <component :is="item.resolution?.state === 'done' ? CheckCircle2 : notificationIcon(item.icon)" class="h-5 w-5" aria-hidden="true" />
                                <span v-if="!item.read" class="absolute -end-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-destructive ring-2 ring-card" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <component
                                        :is="item.has_target ? Link : 'span'"
                                        v-bind="item.has_target ? { href: item.open_url } : {}"
                                        :class="cn('text-sm leading-5', item.read ? 'font-medium text-foreground/80' : 'font-bold text-foreground', item.has_target && 'hover:underline')"
                                    >
                                        <span v-if="!item.read" class="sr-only">Non lue : </span>{{ item.title }}
                                    </component>
                                    <Badge variant="outline" class="py-0.5 text-[10px]">{{ item.category_label }}</Badge>
                                    <!-- ADR-199 — ce qu'elle annonçait a été fait, ou l'est en partie. -->
                                    <Badge v-if="item.resolution" :variant="item.resolution.state === 'done' ? 'success' : 'warning'" class="py-0.5 text-[10px]">
                                        <component :is="item.resolution.state === 'done' ? CheckCircle2 : Clock" class="h-3 w-3" aria-hidden="true" />{{ item.resolution.label }}
                                    </Badge>
                                </div>
                                <p :class="cn('mt-1 text-sm leading-5 text-muted-foreground', item.resolution?.state === 'done' && 'line-through decoration-muted-foreground/40')">{{ item.body }}</p>
                                <p class="mt-1.5 text-[11px] text-muted-foreground/80" :title="formatDateTime(item.created_at)">{{ notificationTime(item.created_at) }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <Button
                                    v-if="item.has_target"
                                    :as="Link"
                                    :href="item.open_url"
                                    variant="white-outline"
                                    size="xs"
                                    class="hidden sm:inline-flex"
                                >
                                    Ouvrir<ArrowRight class="h-3.5 w-3.5" aria-hidden="true" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="xs"
                                    icon
                                    :disabled="busy"
                                    :title="item.read ? 'Marquer comme non lue' : 'Marquer comme lue'"
                                    :aria-label="item.read ? `Marquer comme non lue : ${item.title}` : `Marquer comme lue : ${item.title}`"
                                    @click="act([item.id], item.read ? 'unread' : 'read')"
                                >
                                    <component :is="item.read ? Mail : MailOpen" class="h-4 w-4" aria-hidden="true" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="xs"
                                    icon
                                    :disabled="busy"
                                    :title="item.archived ? 'Remettre dans la liste' : 'Archiver'"
                                    :aria-label="item.archived ? `Remettre dans la liste : ${item.title}` : `Archiver : ${item.title}`"
                                    @click="act([item.id], item.archived ? 'unarchive' : 'archive')"
                                >
                                    <component :is="item.archived ? ArchiveRestore : Archive" class="h-4 w-4" aria-hidden="true" />
                                </Button>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

            <div v-else class="flex flex-col items-center gap-2 px-6 py-16 text-center">
                <span class="grid h-12 w-12 place-items-center rounded-full bg-muted text-muted-foreground"><component :is="empty.icon" class="h-5 w-5" aria-hidden="true" /></span>
                <p class="text-sm font-semibold text-foreground">{{ empty.title }}</p>
                <p class="max-w-sm text-xs text-muted-foreground">{{ empty.text }}</p>
                <button v-if="search || category" type="button" class="text-sm font-medium text-primary hover:underline" @click="search = ''; category = ''">Effacer la recherche</button>
            </div>

            <HrPagination :paginator="inbox" />
        </section>
    </div>
</template>
