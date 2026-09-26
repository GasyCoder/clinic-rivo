<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import ComposeDialog from '@/Components/Webmail/ComposeDialog.vue';
import LabelDialog from '@/Components/Webmail/LabelDialog.vue';
import MessageList from '@/Components/Webmail/MessageList.vue';
import MessageView from '@/Components/Webmail/MessageView.vue';
import PendingList from '@/Components/Webmail/PendingList.vue';
import PendingMessage from '@/Components/Webmail/PendingMessage.vue';
import TemplatesDialog from '@/Components/Webmail/TemplatesDialog.vue';
import WebmailSidebar from '@/Components/Webmail/WebmailSidebar.vue';
import { useToastStore } from '@/stores/toast';
import {
    INSTANT_ACTIONS,
    REMOVING_ACTIONS,
    WEBMAIL_BASE,
    WEBMAIL_CACHE_TAG,
    applyActionLocally,
    folderUrl,
    followOpening,
    forgetOpening,
    parseWebmailPath,
    rememberOpening,
} from '@/utilities/webmail';

/**
 * ADR-195 — la messagerie professionnelle : la boîte du titulaire, lue en
 * direct sur le serveur de messagerie. Rien n'est copié dans RIVO ; le mot de
 * passe de la boîte n'est gardé que dans la session, chiffré.
 *
 * À gauche les dossiers, libellés, modèles et collègues ; à droite la liste d'un
 * dossier, ou le message ouvert.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    mailbox: { type: Object, required: true },
    folders: { type: Array, default: () => [] },
    labels: { type: Array, default: () => [] },
    labelColors: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    contacts: { type: Array, default: () => [] },
    quota: { type: Object, default: null },
    limits: { type: Object, default: () => ({}) },
    current: { type: String, required: true },
    list: { type: Object, default: null },
    filters: { type: Object, default: () => ({}) },
    message: { type: Object, default: null },
    error: { type: String, default: null },
});

const page = usePage();
const toast = useToastStore();
const folder = computed(() => props.folders.find((candidate) => candidate.key === props.current) ?? props.folders[0]);
const inboxUnseen = computed(() => props.folders.find((candidate) => candidate.role === 'inbox')?.unseen ?? 0);
const pageTitle = computed(() => {
    const base = props.message ? (props.message.subject || 'Message') : folder.value?.name ?? 'Messagerie';
    return `${inboxUnseen.value ? `(${inboxUnseen.value}) ` : ''}${base} · Messagerie`;
});

// Rédaction
const compose = ref({ open: false, mode: 'new', message: null, to: '' });
// Un envoi part en arrière-plan (ComposeDialog) : pendant ces quelques secondes, la
// fenêtre garde le message pour le rendre en cas d'échec — on n'en commence pas un autre.
const sending = ref(false);
const openCompose = (mode = 'new', options = {}) => {
    if (sending.value) {
        toast.info('Un message est en cours d’envoi : un instant, puis vous pourrez en écrire un autre.');
        return;
    }
    compose.value = { open: true, mode, message: options.message ?? null, to: options.to ?? '' };
};
const writeTo = (contact) => openCompose('new', { to: `${contact.name} <${contact.email}>` });

// Libellés et modèles
const labelDialog = ref({ open: false, label: null });
const templatesOpen = ref(false);

// Colonne des dossiers sur petit écran
const foldersOpen = ref(false);
watch(() => page.url, () => { foldersOpen.value = false; });

// — La navigation : la page reste en place, et ce qui arrive se voit tout de suite.
//   Un message : son en-tête (connu par la ligne cliquée), le corps en squelette.
//   Un autre dossier : il s'allume, sa liste en squelette.
//   Le même dossier (page, filtre, recherche) : la liste reste, atténuée.
const pending = ref(null);
const pendingFolderName = computed(() => props.folders.find((candidate) => candidate.key === pending.value?.folder)?.name ?? '');
const pendingFolder = computed(() => (pending.value && !pending.value.sameFolder ? pending.value.folder : null));

// Un message lu puis quitté par l'historique : la liste réapparaît telle qu'on l'avait
// laissée ; il s'y montre lu en attendant qu'elle soit relue en arrière-plan.
const recentlyRead = ref(new Set());
let refreshOnReturn = false;
const keyOf = (item) => `${item.folder}:${item.uid}`;
const displayedList = computed(() => (props.list && recentlyRead.value.size
    ? { ...props.list, items: props.list.items.map((item) => (recentlyRead.value.has(keyOf(item)) ? { ...item, seen: true } : item)) }
    : props.list));

const onBack = () => {
    if (props.message) recentlyRead.value = new Set([...recentlyRead.value, keyOf(props.message)]);
    refreshOnReturn = true;
};

watch(() => props.list, (list) => {
    if (!list || !refreshOnReturn) return;
    refreshOnReturn = false;
    router.reload({
        only: ['list', 'folders', 'error'],
        preserveScroll: true,
        async: true,
        onSuccess: () => { recentlyRead.value = new Set(); },
    });
});

let pendingHref = null;
const stops = [];
onMounted(() => {
    stops.push(router.on('start', (event) => {
        const visit = event.detail?.visit;
        if (!visit || visit.method !== 'get' || visit.prefetch || visit.async || visit.only?.length) return;

        const target = parseWebmailPath(visit.url?.pathname);
        if (!target) return;
        pendingHref = visit.url.href;

        if (target.uid) {
            const item = props.list?.items.find((candidate) => candidate.folder === target.folder && candidate.uid === target.uid) ?? null;
            if (props.list) rememberOpening(page.url, `${visit.url.pathname}${visit.url.search}`);
            else if (props.message && visit.replace) followOpening(`${visit.url.pathname}${visit.url.search}`);
            // Ouvrir un message le marque lu : les dossiers préparés au survol ne le sont plus.
            router.flushByCacheTags([WEBMAIL_CACHE_TAG]);
            pending.value = { kind: 'message', folder: target.folder, item };
            return;
        }

        forgetOpening();
        const sameFolder = target.folder === props.current && !props.message;
        pending.value = { kind: 'list', folder: target.folder, sameFolder };
    }));
    stops.push(router.on('finish', (event) => {
        const href = event.detail?.visit?.url?.href ?? null;
        if (pendingHref !== null && href !== null && href !== pendingHref) return;
        pendingHref = null;
        pending.value = null;
    }));
});
onBeforeUnmount(() => stops.forEach((stop) => stop()));

// Actions sur les messages
const processing = ref(false);
const confirmDelete = ref(null);
const LEAVES_FOLDER = ['archive', 'spam', 'inbox', 'trash', 'delete', 'move'];

const runAction = (request) => {
    const { action, items } = request;
    // Une action qui fait sortir le message ouvert de son dossier ramène à la liste.
    const leaving = props.message && (LEAVES_FOLDER.includes(action) || action === 'unread');
    const payload = {
        items,
        action,
        target: request.target ?? undefined,
        label: request.label ?? undefined,
        return_to: leaving ? folderUrl(props.current) : undefined,
    };

    // Marquer, étoiler, libeller — ou retirer des messages de la liste : l'écran change
    // tout de suite, le serveur confirme en arrière-plan (et Inertia revient en arrière
    // s'il refuse). Seuls les compteurs, ou la liste, sont ensuite relus.
    const instant = !leaving && (INSTANT_ACTIONS.includes(action) || (!props.message && REMOVING_ACTIONS.includes(action)));
    if (instant) {
        router.post(`${WEBMAIL_BASE}/actions`, payload, {
            preserveScroll: true,
            preserveState: true,
            only: REMOVING_ACTIONS.includes(action) ? ['list', 'folders', 'flash', 'errors'] : ['folders', 'flash', 'errors'],
            optimistic: (current) => applyActionLocally(current, request),
            invalidateCacheTags: [WEBMAIL_CACHE_TAG],
        });
        return;
    }

    router.post(`${WEBMAIL_BASE}/actions`, payload, {
        preserveScroll: true,
        invalidateCacheTags: [WEBMAIL_CACHE_TAG],
        onStart: () => { processing.value = true; },
        onFinish: () => { processing.value = false; },
    });
};

const act = (request) => {
    if (request.action === 'delete') {
        confirmDelete.value = request;
        return;
    }
    runAction(request);
};

const logoutConfirm = ref(false);
const logout = () => router.post(`${WEBMAIL_BASE}/deconnexion`);
</script>

<template>
    <Head :title="pageTitle" />

    <div class="relative flex min-h-[calc(100vh-10rem)] overflow-hidden rounded-xl border border-border bg-card shadow-sm">
        <!-- Colonne des dossiers : fixe sur grand écran, tiroir sur petit écran. -->
        <div
            v-if="foldersOpen"
            class="fixed inset-0 z-[1030] bg-slate-950/40 lg:hidden"
            aria-hidden="true"
            @click="foldersOpen = false"
        />
        <aside
            :class="[
                'z-[1031] w-72 shrink-0 overflow-y-auto border-e border-border bg-card p-4',
                'fixed inset-y-0 start-0 transition-transform duration-200 lg:static lg:translate-x-0',
                foldersOpen ? 'translate-x-0 shadow-2xl' : '-translate-x-full',
            ]"
        >
            <button type="button" class="mb-3 ms-auto grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-accent lg:hidden" aria-label="Fermer les dossiers" @click="foldersOpen = false">
                <X class="h-4 w-4" aria-hidden="true" />
            </button>
            <WebmailSidebar
                :folders="folders"
                :labels="labels"
                :contacts="contacts"
                :templates-count="templates.length"
                :quota="quota"
                :current="current"
                :pending-folder="pendingFolder"
                :active-label="filters.label"
                :mailbox="mailbox"
                @compose="openCompose('new')"
                @write-to="writeTo"
                @new-label="labelDialog = { open: true, label: null }"
                @edit-label="(label) => (labelDialog = { open: true, label })"
                @manage-templates="templatesOpen = true"
                @logout="logoutConfirm = true"
                @navigate="foldersOpen = false"
            />
        </aside>

        <main class="relative min-w-0 flex-1">
            <PendingMessage v-if="pending?.kind === 'message'" :item="pending.item" :folder-name="pendingFolderName || folder?.name" />
            <PendingList v-else-if="pending?.kind === 'list' && !pending.sameFolder" :folder-name="pendingFolderName" />
            <template v-else>
                <!-- Même dossier (page, filtre, recherche) : la liste reste, atténuée, sous une barre de chargement. -->
                <div v-if="pending" class="absolute inset-x-0 top-0 z-10 h-0.5 overflow-hidden bg-primary/15" data-webmail-pending role="status" aria-label="Chargement…">
                    <div class="h-full w-1/3 animate-[webmail-progress_1s_ease-in-out_infinite] bg-primary" />
                </div>
                <MessageView
                    v-if="message"
                    :message="message"
                    :folder="folder"
                    :folders="folders"
                    :labels="labels"
                    :mailbox="mailbox"
                    :processing="processing"
                    @act="act"
                    @back="onBack"
                    @compose="(mode) => openCompose(mode, { message })"
                />
                <MessageList
                    v-else-if="displayedList"
                    :class="pending ? 'pointer-events-none opacity-60 transition-opacity' : 'transition-opacity'"
                    :list="displayedList"
                    :folder="folder"
                    :folders="folders"
                    :labels="labels"
                    :filters="filters"
                    :error="error"
                    :processing="processing"
                    @act="act"
                    @open-folders="foldersOpen = true"
                />
            </template>
        </main>
    </div>

    <ComposeDialog
        v-model:open="compose.open"
        :mode="compose.mode"
        :message="compose.message"
        :to="compose.to"
        :address="mailbox.address"
        :owner="mailbox.owner"
        :contacts="contacts"
        :templates="templates"
        :limits="limits"
        :current="current"
        @sending="(value) => (sending = value)"
    />
    <LabelDialog v-model:open="labelDialog.open" :label="labelDialog.label" :colors="labelColors" />
    <TemplatesDialog v-model:open="templatesOpen" :templates="templates" />

    <ConfirmModal
        :open="Boolean(confirmDelete)"
        :title="confirmDelete?.items.length > 1 ? `Supprimer définitivement ${confirmDelete.items.length} messages ?` : 'Supprimer définitivement ce message ?'"
        description="Il sera effacé du serveur de messagerie. Cette action ne peut pas être annulée."
        confirm-label="Supprimer définitivement"
        tone="danger"
        :processing="processing"
        @update:open="(value) => value || (confirmDelete = null)"
        @confirm="runAction(confirmDelete); confirmDelete = null"
    />
    <ConfirmModal
        v-model:open="logoutConfirm"
        :title="mailbox.own === false ? 'Fermer cette boîte ?' : 'Fermer votre boîte ?'"
        description="Le mot de passe de la boîte sera effacé de votre session ; il sera redemandé pour la rouvrir. Vous restez connecté à RIVO."
        :confirm-label="mailbox.own === false ? 'Fermer cette boîte' : 'Fermer ma boîte'"
        @confirm="logout"
    />
</template>
