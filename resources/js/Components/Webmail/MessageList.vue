<script setup>
import { computed, h, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import {
    Archive,
    ChevronLeft,
    ChevronRight,
    CornerUpLeft,
    FolderInput,
    Inbox,
    Mail,
    MailOpen,
    Menu,
    Paperclip,
    Search,
    ShieldAlert,
    Star,
    StarOff,
    Tag,
    Trash2,
    X,
} from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import DropdownMenu from '@/Components/Shadcn/DropdownMenu.vue';
import RefreshIcon from '@/Components/Shadcn/RefreshIcon.vue';
import { folderIcon } from '@/Components/Webmail/folderIcons';
import { cn } from '@/lib/cn';
import {
    avatarTone,
    folderActions,
    folderUrl,
    formatFullDate,
    formatListDate,
    initialsOf,
    labelColor,
    messageUrl,
    senderLabel,
    WEBMAIL_CACHE_TAG,
    WEBMAIL_NAVIGATION,
} from '@/utilities/webmail';

/**
 * ADR-195 — la liste d'un dossier : sélection multiple et actions groupées,
 * recherche sur le serveur, filtres Non lus / Favoris, pagination.
 *
 * La liste ne montre pas d'extrait du message : l'afficher obligerait à lire
 * chaque corps sur le serveur, et un message ouvert serait marqué lu. L'objet,
 * l'expéditeur, la date et les pièces jointes suffisent à choisir.
 */
const props = defineProps({
    list: { type: Object, required: true },
    folder: { type: Object, required: true },
    folders: { type: Array, default: () => [] },
    labels: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    error: { type: String, default: null },
    processing: { type: Boolean, default: false },
});
const emit = defineEmits(['act', 'open-folders']);

const selected = ref([]);
const keyOf = (item) => `${item.folder}:${item.uid}`;
watch(() => props.list, () => { selected.value = []; });

const selectedItems = computed(() => props.list.items.filter((item) => selected.value.includes(keyOf(item))));
const allState = computed(() => {
    if (!props.list.items.length || !selected.value.length) return false;
    return selected.value.length === props.list.items.length ? true : 'indeterminate';
});
const toggleAll = (value) => { selected.value = value ? props.list.items.map(keyOf) : []; };
const toggle = (item, value) => {
    const key = keyOf(item);
    selected.value = value ? [...new Set([...selected.value, key])] : selected.value.filter((candidate) => candidate !== key);
};

const available = computed(() => folderActions(props.folder.role));
const act = (action, items = selectedItems.value, extra = {}) => emit('act', { action, items: items.map((item) => ({ folder: item.folder, uid: item.uid })), ...extra });

const ACTIONS = {
    read: { label: 'Marquer comme lu', icon: MailOpen },
    unread: { label: 'Marquer comme non lu', icon: Mail },
    star: { label: 'Ajouter aux favoris', icon: Star },
    unstar: { label: 'Retirer des favoris', icon: StarOff },
    archive: { label: 'Archiver', icon: Archive },
    spam: { label: 'Signaler comme indésirable', icon: ShieldAlert },
    inbox: { label: 'Remettre en boîte de réception', icon: Inbox },
    trash: { label: 'Mettre à la corbeille', icon: Trash2 },
    delete: { label: 'Supprimer définitivement', icon: Trash2 },
};
const primaryBulk = computed(() => ['archive', 'spam', 'inbox', 'trash', 'delete'].filter((action) => available.value.includes(action)));

const LabelDot = (color) => (_, { attrs }) => h('span', { class: [attrs.class, 'mt-1 !h-2.5 !w-2.5 rounded-full', labelColor(color).dot] });
const labelItems = computed(() => [
    ...props.labels.map((label) => ({ key: `label:${label.uuid}`, label: `Ajouter « ${label.name} »`, icon: LabelDot(label.color) })),
    ...props.labels.map((label, index) => ({ key: `unlabel:${label.uuid}`, label: `Retirer « ${label.name} »`, icon: X, separatorBefore: index === 0 })),
]);
const onLabel = (key) => {
    const [kind, uuid] = key.split(':');
    if (kind === 'label' || kind === 'unlabel') act(kind, selectedItems.value, { label: uuid });
};

const moveTargets = computed(() => props.folders
    .filter((folder) => folder.key !== props.folder.key && !['favorites', 'drafts'].includes(folder.role))
    .map((folder) => ({ key: folder.key, label: folder.name, icon: FolderInput })));
const onMove = (key) => act('move', selectedItems.value, { target: key });

const moreItems = computed(() => [
    { key: 'read', label: ACTIONS.read.label, icon: ACTIONS.read.icon },
    { key: 'unread', label: ACTIONS.unread.label, icon: ACTIONS.unread.icon },
    { key: 'star', label: ACTIONS.star.label, icon: ACTIONS.star.icon, separatorBefore: true },
    { key: 'unstar', label: ACTIONS.unstar.label, icon: ACTIONS.unstar.icon },
]);

// Recherche sur le serveur : Entrée, ou une courte pause.
const query = ref(props.filters.q ?? '');
watch(() => props.filters.q, (value) => { query.value = value ?? ''; });
let searchTimer = null;
const search = () => {
    clearTimeout(searchTimer);
    router.get(folderUrl(props.folder.key, { q: query.value.trim() || null, filter: props.filters.filter, label: props.filters.label }), {}, { ...WEBMAIL_NAVIGATION, preserveScroll: true, replace: true });
};
const onSearchInput = () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(search, 450);
};
const clearSearch = () => { query.value = ''; search(); };

const FILTERS = [
    { key: null, label: 'Tous', icon: Inbox, count: 'all' },
    { key: 'non-lus', label: 'Non lus', icon: Mail, count: 'unseen' },
    { key: 'favoris', label: 'Favoris', icon: Star, count: 'flagged' },
];
/** Le compteur d'un filtre : le dossier entier, en pastille rouge ; rien quand il est à zéro. */
const countOf = (filter) => props.list.counts?.[filter.count] ?? 0;
const badge = (count) => (count > 999 ? '999+' : String(count));
const activeLabel = computed(() => props.labels.find((label) => label.uuid === props.filters.label) ?? null);
const filterHref = (filter) => folderUrl(props.folder.key, { q: props.filters.q, filter, label: props.filters.label });

const range = computed(() => {
    const start = (props.list.page - 1) * props.list.per_page + 1;
    const end = Math.min(props.list.total, props.list.page * props.list.per_page);
    return props.list.total ? `${start}–${end} sur ${props.list.total}` : '0 message';
});
const pageHref = (page) => folderUrl(props.folder.key, { ...pageFilters.value, page });
const pageFilters = computed(() => ({ q: props.filters.q, filter: props.filters.filter, label: props.filters.label }));
/** Une page voisine se prépare au survol de sa flèche (gardée 30 s). */
const pageLink = { ...WEBMAIL_NAVIGATION, prefetch: true, cacheFor: '30s', cacheTags: WEBMAIL_CACHE_TAG };

/** Dans Envoyés et Brouillons, on lit à qui le message s'adresse. */
const showsRecipient = computed(() => ['sent', 'drafts'].includes(props.folder.role));
const partyOf = (item) => (showsRecipient.value ? (item.to?.[0] ?? null) : item.from);
const partyText = (item) => {
    if (!showsRecipient.value) return senderLabel(item.from);
    const first = item.to?.[0];
    if (!first) return '(aucun destinataire)';
    return `À : ${senderLabel(first)}${item.to.length > 1 ? ` +${item.to.length - 1}` : ''}`;
};
const labelsOf = (item) => props.labels.filter((label) => item.keywords?.includes(label.keyword));
const hrefOf = (item) => messageUrl(item.folder, item.uid);
// Actualiser relit la liste et les compteurs — et oublie les dossiers préparés au survol.
const refreshing = ref(false);
const refresh = () => {
    refreshing.value = true;
    router.flushByCacheTags([WEBMAIL_CACHE_TAG]);
    router.reload({
        only: ['list', 'folders', 'error', 'flash', 'errors'],
        preserveScroll: true,
        onFinish: () => { refreshing.value = false; },
    });
};

const filtered = computed(() => Boolean(props.filters.q || props.filters.filter || props.filters.label));
const emptyTitle = computed(() => (filtered.value ? 'Aucun résultat' : 'Aucun message'));
const emptyText = computed(() => {
    if (props.filters.q) return `Aucun message ne contient « ${props.filters.q} » dans ${props.folder.name}.`;
    if (props.filters.filter === 'non-lus') return 'Aucun message non lu ici.';
    if (props.filters.filter === 'favoris') return 'Aucun favori dans ce dossier.';
    if (activeLabel.value) return `Aucun message avec le libellé « ${activeLabel.value.name} » dans ${props.folder.name}.`;
    if (props.folder.role === 'favorites') return 'Aucun favori. Cliquez sur l’étoile d’un message pour le retrouver ici.';
    return `${props.folder.name} est vide.`;
});
</script>

<template>
    <section class="flex min-h-0 flex-col" :aria-label="folder.name">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-4 py-3">
            <Button type="button" variant="ghost" size="sm" icon class="lg:hidden" aria-label="Afficher les dossiers" @click="emit('open-folders')">
                <Menu class="h-4 w-4" aria-hidden="true" />
            </Button>
            <span class="hidden h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary sm:grid" aria-hidden="true">
                <component :is="folderIcon(folder)" class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="truncate text-lg font-bold text-foreground">
                    {{ folder.name }}
                    <span v-if="activeLabel" :class="cn('ms-2 inline-flex translate-y-[-2px] items-center gap-1.5 rounded-full px-2 py-0.5 align-middle text-xs font-semibold', labelColor(activeLabel.color).soft)">
                        <Tag class="h-3 w-3" aria-hidden="true" /> {{ activeLabel.name }}
                        <Link :href="folderUrl(folder.key)" v-bind="WEBMAIL_NAVIGATION" class="rounded-full hover:opacity-70" aria-label="Retirer le filtre de libellé"><X class="h-3 w-3" aria-hidden="true" /></Link>
                    </span>
                </h2>
                <p class="text-xs text-muted-foreground">
                    <template v-if="folder.role === 'favorites'">Vos messages étoilés de la boîte de réception, des archives et des envoyés.</template>
                    <template v-else>{{ list.total }} message{{ list.total > 1 ? 's' : '' }}<template v-if="folder.unseen"> · {{ folder.unseen }} non lu{{ folder.unseen > 1 ? 's' : '' }}</template></template>
                </p>
            </div>
            <form class="relative w-full sm:w-72" role="search" @submit.prevent="search">
                <Search class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <input
                    v-model="query"
                    type="search"
                    :aria-label="`Rechercher dans ${folder.name}`"
                    placeholder="Rechercher (objet, expéditeur, texte)"
                    class="h-[var(--control-h)] w-full rounded-lg border border-input bg-card ps-9 pe-8 text-sm placeholder:text-muted-foreground focus:border-primary/60 focus:outline-none focus:ring-2 focus:ring-ring/25"
                    @input="onSearchInput"
                >
                <button v-if="query" type="button" class="absolute end-2 top-1/2 grid h-6 w-6 -translate-y-1/2 place-items-center rounded text-muted-foreground hover:text-foreground" aria-label="Effacer la recherche" @click="clearSearch">
                    <X class="h-3.5 w-3.5" aria-hidden="true" />
                </button>
            </form>
        </header>

        <div class="flex flex-wrap items-center gap-2 border-b border-border bg-muted/30 px-4 py-2">
            <Checkbox :model-value="allState" :disabled="!list.items.length" aria-label="Tout sélectionner sur cette page" @update:model-value="toggleAll" />

            <template v-if="selected.length">
                <span class="me-1 text-xs font-semibold text-foreground">{{ selected.length }} sélectionné{{ selected.length > 1 ? 's' : '' }}</span>
                <Button
                    v-for="action in primaryBulk"
                    :key="action"
                    type="button"
                    variant="ghost"
                    size="xs"
                    icon
                    :disabled="processing"
                    :title="ACTIONS[action].label"
                    :aria-label="ACTIONS[action].label"
                    :class="action === 'delete' ? 'text-destructive hover:text-destructive' : ''"
                    @click="act(action)"
                >
                    <component :is="ACTIONS[action].icon" class="h-4 w-4" aria-hidden="true" />
                </Button>
                <DropdownMenu :items="moreItems" label="Marquer" align="start" @select="(key) => act(key)">
                    <template #trigger>
                        <Button type="button" variant="ghost" size="xs" icon title="Lu, non lu, favori" aria-label="Lu, non lu, favori" :disabled="processing"><MailOpen class="h-4 w-4" aria-hidden="true" /></Button>
                    </template>
                </DropdownMenu>
                <DropdownMenu v-if="labels.length" :items="labelItems" label="Libellés" align="start" @select="onLabel">
                    <template #trigger>
                        <Button type="button" variant="ghost" size="xs" icon title="Libellés" aria-label="Libellés" :disabled="processing"><Tag class="h-4 w-4" aria-hidden="true" /></Button>
                    </template>
                </DropdownMenu>
                <DropdownMenu v-if="moveTargets.length && folder.role !== 'favorites'" :items="moveTargets" label="Déplacer vers" align="start" @select="onMove">
                    <template #trigger>
                        <Button type="button" variant="ghost" size="xs" icon title="Déplacer vers un dossier" aria-label="Déplacer vers un dossier" :disabled="processing"><FolderInput class="h-4 w-4" aria-hidden="true" /></Button>
                    </template>
                </DropdownMenu>
            </template>
            <template v-else>
                <nav class="flex items-center gap-1" aria-label="Filtrer la liste">
                    <Link
                        v-for="filter in FILTERS"
                        :key="filter.label"
                        :href="filterHref(filter.key)"
                        v-bind="WEBMAIL_NAVIGATION"
                        preserve-scroll
                        :aria-current="(filters.filter ?? null) === filter.key ? 'page' : undefined"
                        :aria-label="countOf(filter) ? `${filter.label} : ${countOf(filter)}` : filter.label"
                        :class="cn(
                            'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 py-1 text-xs font-semibold transition-colors sm:px-3',
                            (filters.filter ?? null) === filter.key ? 'border-foreground bg-foreground text-background' : 'border-transparent text-muted-foreground hover:border-border hover:bg-card hover:text-foreground',
                        )"
                    >
                        <component :is="filter.icon" class="hidden h-3.5 w-3.5 sm:block" aria-hidden="true" />
                        {{ filter.label }}
                        <span
                            v-if="countOf(filter)"
                            class="inline-flex min-w-[18px] items-center justify-center rounded-full bg-destructive px-1.5 text-[10px] font-bold leading-4 tabular-nums text-destructive-foreground"
                            aria-hidden="true"
                        >{{ badge(countOf(filter)) }}</span>
                    </Link>
                </nav>
                <Button type="button" variant="ghost" size="xs" icon title="Actualiser" aria-label="Actualiser la liste" :aria-busy="refreshing" :disabled="processing || refreshing" @click="refresh">
                    <RefreshIcon :spinning="refreshing" class="h-4 w-4" />
                </Button>
            </template>

            <div class="ms-auto flex items-center gap-1 text-xs text-muted-foreground">
                <span class="me-1 tabular-nums">{{ range }}</span>
                <Link v-if="list.page > 1" :href="pageHref(list.page - 1)" v-bind="pageLink" preserve-scroll class="grid h-7 w-7 place-items-center rounded-md hover:bg-accent" aria-label="Page précédente"><ChevronLeft class="h-4 w-4" aria-hidden="true" /></Link>
                <span v-else class="grid h-7 w-7 place-items-center opacity-40" aria-hidden="true"><ChevronLeft class="h-4 w-4" /></span>
                <Link v-if="list.page < list.pages" :href="pageHref(list.page + 1)" v-bind="pageLink" preserve-scroll class="grid h-7 w-7 place-items-center rounded-md hover:bg-accent" aria-label="Page suivante"><ChevronRight class="h-4 w-4" aria-hidden="true" /></Link>
                <span v-else class="grid h-7 w-7 place-items-center opacity-40" aria-hidden="true"><ChevronRight class="h-4 w-4" /></span>
            </div>
        </div>

        <p v-if="error" role="alert" class="m-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">{{ error }}</p>
        <p v-if="list.truncated" class="mx-4 mt-3 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">Seuls les 200 favoris les plus récents de chaque dossier sont réunis ici.</p>

        <ul v-if="list.items.length" class="divide-y divide-border">
            <li
                v-for="item in list.items"
                :key="keyOf(item)"
                :class="cn(
                    'group relative flex items-center gap-3 px-4 py-3 transition-colors hover:bg-accent/50',
                    !item.seen && 'bg-primary/[0.04]',
                    selected.includes(keyOf(item)) && 'bg-primary/10 hover:bg-primary/10',
                )"
            >
                <span v-if="!item.seen" class="absolute inset-y-2 start-0 w-1 rounded-e bg-primary" aria-hidden="true" />
                <Checkbox :model-value="selected.includes(keyOf(item))" :aria-label="`Sélectionner « ${item.subject || 'Sans objet'} »`" @update:model-value="(value) => toggle(item, value)" />
                <button
                    type="button"
                    :class="cn('grid h-7 w-7 shrink-0 place-items-center rounded-md transition-colors hover:bg-background', item.flagged ? 'text-amber-500' : 'text-muted-foreground/60 hover:text-amber-500')"
                    :aria-label="item.flagged ? 'Retirer des favoris' : 'Ajouter aux favoris'"
                    :aria-pressed="item.flagged"
                    :disabled="processing"
                    @click="act(item.flagged ? 'unstar' : 'star', [item])"
                >
                    <Star :class="cn('h-4 w-4', item.flagged && 'fill-current')" aria-hidden="true" />
                </button>

                <Link :href="hrefOf(item)" v-bind="WEBMAIL_NAVIGATION" class="flex min-w-0 flex-1 items-center gap-3 focus:outline-none" :aria-label="`${item.seen ? '' : 'Non lu. '}${partyText(item)} — ${item.subject || 'Sans objet'}`">
                    <span :class="cn('hidden h-9 w-9 shrink-0 place-items-center rounded-full text-xs font-bold sm:grid', avatarTone(partyOf(item)?.email))" aria-hidden="true">{{ initialsOf(partyOf(item)) }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-2">
                            <span :class="cn('truncate text-sm', item.seen ? 'text-foreground/80' : 'font-bold text-foreground')">{{ partyText(item) }}</span>
                            <CornerUpLeft v-if="item.answered" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-label="Répondu" />
                            <span v-if="item.draft" class="shrink-0 rounded bg-amber-100 px-1.5 text-[10px] font-bold uppercase text-amber-800 dark:bg-amber-950/60 dark:text-amber-200">Brouillon</span>
                        </span>
                        <span class="mt-0.5 flex min-w-0 items-center gap-1.5">
                            <span
                                v-for="label in labelsOf(item)"
                                :key="label.uuid"
                                :class="cn('shrink-0 rounded-full px-2 py-px text-[10px] font-semibold', labelColor(label.color).soft)"
                            >{{ label.name }}</span>
                            <span :class="cn('truncate text-sm', item.seen ? 'text-muted-foreground' : 'font-semibold text-foreground')">{{ item.subject || '(Sans objet)' }}</span>
                        </span>
                    </span>
                    <Paperclip v-if="item.has_attachments" class="h-4 w-4 shrink-0 text-muted-foreground" aria-label="Pièce jointe" />
                    <time :datetime="item.date" :title="formatFullDate(item.date)" :class="cn('w-16 shrink-0 text-end text-xs tabular-nums group-hover:invisible', item.seen ? 'text-muted-foreground' : 'font-bold text-foreground')">{{ formatListDate(item.date) }}</time>
                </Link>

                <div class="invisible absolute end-3 top-1/2 flex -translate-y-1/2 items-center gap-0.5 rounded-lg border border-border bg-card p-0.5 shadow-sm group-hover:visible">
                    <button v-if="available.includes('archive')" type="button" class="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground" title="Archiver" aria-label="Archiver" :disabled="processing" @click="act('archive', [item])"><Archive class="h-4 w-4" aria-hidden="true" /></button>
                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground" :title="item.seen ? 'Marquer comme non lu' : 'Marquer comme lu'" :aria-label="item.seen ? 'Marquer comme non lu' : 'Marquer comme lu'" :disabled="processing" @click="act(item.seen ? 'unread' : 'read', [item])">
                        <component :is="item.seen ? Mail : MailOpen" class="h-4 w-4" aria-hidden="true" />
                    </button>
                    <button v-if="available.includes('trash')" type="button" class="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-destructive" title="Mettre à la corbeille" aria-label="Mettre à la corbeille" :disabled="processing" @click="act('trash', [item])"><Trash2 class="h-4 w-4" aria-hidden="true" /></button>
                </div>
            </li>
        </ul>

        <div v-else class="flex flex-col items-center justify-center gap-3 px-6 py-20 text-center">
            <span class="grid h-16 w-16 place-items-center rounded-2xl bg-primary/10 text-primary" aria-hidden="true">
                <component :is="filtered ? Search : folderIcon(folder)" class="h-7 w-7" />
            </span>
            <p class="text-base font-semibold text-foreground">{{ emptyTitle }}</p>
            <p class="max-w-sm text-sm text-muted-foreground">{{ emptyText }}</p>
            <div class="mt-1 flex flex-wrap items-center justify-center gap-2">
                <Button v-if="filtered" :as="Link" :href="folderUrl(folder.key)" v-bind="WEBMAIL_NAVIGATION" variant="outline" size="sm">
                    <X class="h-4 w-4" aria-hidden="true" /> Afficher tout le dossier
                </Button>
                <Button type="button" variant="ghost" size="sm" :aria-busy="refreshing" :disabled="processing || refreshing" @click="refresh">
                    <RefreshIcon :spinning="refreshing" class="h-4 w-4" /> Actualiser
                </Button>
            </div>
        </div>
    </section>
</template>
