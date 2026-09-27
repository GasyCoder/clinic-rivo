<script setup>
import { computed, h, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Archive,
    ArrowLeft,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Download,
    File,
    FileImage,
    FileSpreadsheet,
    FileText,
    FolderInput,
    Forward,
    ImageOff,
    Inbox,
    Mail,
    Printer,
    Reply,
    ReplyAll,
    ShieldAlert,
    SquarePen,
    Star,
    Tag,
    Trash2,
    X,
} from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import DropdownMenu from '@/Components/Shadcn/DropdownMenu.vue';
import EmailBodyFrame from '@/Components/Webmail/EmailBodyFrame.vue';
import { cn } from '@/lib/cn';
import {
    WEBMAIL_BASE,
    avatarTone,
    escapeHtml,
    folderActions,
    folderUrl,
    formatFullDate,
    formatParty,
    formatSize,
    initialsOf,
    labelColor,
    messageUrl,
    senderLabel,
    WEBMAIL_NAVIGATION,
    canReturnByHistory,
} from '@/utilities/webmail';

/**
 * ADR-195 — un message ouvert : ses actions, ses destinataires, son corps dans un
 * cadre isolé, ses pièces jointes, et de quoi répondre ou transférer.
 */
const props = defineProps({
    message: { type: Object, required: true },
    folder: { type: Object, required: true },
    folders: { type: Array, default: () => [] },
    labels: { type: Array, default: () => [] },
    mailbox: { type: Object, required: true },
    processing: { type: Boolean, default: false },
});
const emit = defineEmits(['act', 'compose', 'back']);
const page = usePage();

const body = ref(null);
const showDetails = ref(false);

const available = computed(() => folderActions(props.folder.role));
const item = computed(() => [{ folder: props.message.folder, uid: props.message.uid }]);
const back = computed(() => folderUrl(props.folder.key));

// Ouvert depuis la liste : on y revient par l'historique, et la liste réapparaît
// aussitôt, telle qu'on l'avait laissée (elle est relue ensuite en arrière-plan).
// `onBefore` et non `@click` : le lien d'Inertia remplace un gestionnaire de clic.
const goBack = () => {
    if (!canReturnByHistory(page.url, props.folder.key)) return true;
    emit('back');
    window.history.back();

    return false;
};
// Un voisin remplace le message dans l'historique : « retour » mène toujours à la liste.
const neighbour = { ...WEBMAIL_NAVIGATION, replace: true };
const act = (action, extra = {}) => emit('act', { action, items: item.value, ...extra });

const messageLabels = computed(() => props.labels.filter((label) => props.message.keywords?.includes(label.keyword)));
const LabelDot = (color) => (_, { attrs }) => h('span', { class: [attrs.class, 'mt-1 !h-2.5 !w-2.5 rounded-full', labelColor(color).dot] });
const labelItems = computed(() => props.labels.map((label) => {
    const on = props.message.keywords?.includes(label.keyword);
    return { key: label.uuid, label: on ? `Retirer « ${label.name} »` : `Ajouter « ${label.name} »`, icon: on ? X : LabelDot(label.color) };
}));
const onLabel = (uuid) => act(props.message.keywords?.includes(props.labels.find((label) => label.uuid === uuid)?.keyword) ? 'unlabel' : 'label', { label: uuid });

const moveTargets = computed(() => props.folders
    .filter((folder) => folder.key !== props.folder.key && !['favorites', 'drafts'].includes(folder.role))
    .map((folder) => ({ key: folder.key, label: folder.name, icon: FolderInput })));

const recipientsLine = computed(() => {
    const own = props.mailbox.address.toLowerCase();
    const names = [...(props.message.to ?? []), ...(props.message.cc ?? [])]
        .map((party) => (party.email?.toLowerCase() === own ? 'moi' : senderLabel(party)));
    return names.length ? `À ${names.slice(0, 3).join(', ')}${names.length > 3 ? ` et ${names.length - 3} autre${names.length > 4 ? 's' : ''}` : ''}` : 'Aucun destinataire';
});
const canReplyAll = computed(() => ((props.message.to?.length ?? 0) + (props.message.cc?.length ?? 0)) > 1);

const printHeader = computed(() => `<h1>${escapeHtml(props.message.subject || '(Sans objet)')}</h1>`
    + `<div>De : ${escapeHtml(formatParty(props.message.from))}</div>`
    + `<div>À : ${escapeHtml((props.message.to ?? []).map(formatParty).join(', '))}</div>`
    + ((props.message.cc ?? []).length ? `<div>Cc : ${escapeHtml(props.message.cc.map(formatParty).join(', '))}</div>` : '')
    + `<div>Date : ${escapeHtml(formatFullDate(props.message.date))}</div>`);

const attachmentUrl = (attachment) => `${WEBMAIL_BASE}/dossier/${encodeURIComponent(props.message.folder)}/${props.message.uid}/pieces/${encodeURIComponent(attachment.part)}`;
const attachmentIcon = (type) => {
    if (String(type).startsWith('image/')) return FileImage;
    if (/pdf|text|word|document/i.test(type)) return FileText;
    if (/sheet|excel|csv/i.test(type)) return FileSpreadsheet;
    return File;
};
const remoteImagesUrl = computed(() => messageUrl(props.message.folder, props.message.uid, { images: true }));
const allowRemote = computed(() => props.message.remote_images === true);
</script>

<template>
    <article class="flex min-h-0 flex-col" :aria-label="message.subject || 'Message sans objet'">
        <div class="flex flex-wrap items-center gap-1 border-b border-border px-3 py-2">
            <Link :href="back" v-bind="WEBMAIL_NAVIGATION" class="inline-flex h-8 items-center gap-1.5 rounded-md px-2 text-sm font-medium text-muted-foreground hover:bg-accent hover:text-foreground" :on-before="goBack">
                <ArrowLeft class="h-4 w-4" aria-hidden="true" /> <span class="hidden sm:inline">{{ folder.name }}</span>
            </Link>
            <span class="mx-1 h-5 w-px bg-border" aria-hidden="true" />
            <Button v-if="available.includes('archive')" type="button" variant="ghost" size="xs" icon title="Archiver" aria-label="Archiver" :disabled="processing" @click="act('archive')"><Archive class="h-4 w-4" aria-hidden="true" /></Button>
            <Button v-if="available.includes('inbox')" type="button" variant="ghost" size="xs" icon title="Remettre en boîte de réception" aria-label="Remettre en boîte de réception" :disabled="processing" @click="act('inbox')"><Inbox class="h-4 w-4" aria-hidden="true" /></Button>
            <Button v-if="available.includes('spam')" type="button" variant="ghost" size="xs" icon title="Signaler comme indésirable" aria-label="Signaler comme indésirable" :disabled="processing" @click="act('spam')"><ShieldAlert class="h-4 w-4" aria-hidden="true" /></Button>
            <Button v-if="available.includes('trash')" type="button" variant="ghost" size="xs" icon title="Mettre à la corbeille" aria-label="Mettre à la corbeille" :disabled="processing" @click="act('trash')"><Trash2 class="h-4 w-4" aria-hidden="true" /></Button>
            <Button v-if="available.includes('delete')" type="button" variant="ghost" size="xs" icon class="text-destructive hover:text-destructive" title="Supprimer définitivement" aria-label="Supprimer définitivement" :disabled="processing" @click="act('delete')"><Trash2 class="h-4 w-4" aria-hidden="true" /></Button>
            <span class="mx-1 h-5 w-px bg-border" aria-hidden="true" />
            <Button type="button" variant="ghost" size="xs" icon title="Marquer comme non lu" aria-label="Marquer comme non lu" :disabled="processing" @click="act('unread')"><Mail class="h-4 w-4" aria-hidden="true" /></Button>
            <DropdownMenu v-if="labels.length" :items="labelItems" label="Libellés" align="start" @select="onLabel">
                <template #trigger>
                    <Button type="button" variant="ghost" size="xs" icon title="Libellés" aria-label="Libellés" :disabled="processing"><Tag class="h-4 w-4" aria-hidden="true" /></Button>
                </template>
            </DropdownMenu>
            <DropdownMenu v-if="moveTargets.length" :items="moveTargets" label="Déplacer vers" align="start" @select="(key) => act('move', { target: key })">
                <template #trigger>
                    <Button type="button" variant="ghost" size="xs" icon title="Déplacer vers un dossier" aria-label="Déplacer vers un dossier" :disabled="processing"><FolderInput class="h-4 w-4" aria-hidden="true" /></Button>
                </template>
            </DropdownMenu>
            <Button type="button" variant="ghost" size="xs" icon title="Imprimer ce message" aria-label="Imprimer ce message" @click="body?.print()"><Printer class="h-4 w-4" aria-hidden="true" /></Button>

            <div class="ms-auto flex items-center gap-1 text-xs text-muted-foreground">
                <span v-if="message.position" class="me-1 tabular-nums">{{ message.position }} sur {{ message.count }}</span>
                <Link v-if="message.newer" :href="messageUrl(message.folder, message.newer)" v-bind="neighbour" class="grid h-8 w-8 place-items-center rounded-md hover:bg-accent" title="Message plus récent" aria-label="Message plus récent"><ChevronLeft class="h-4 w-4" aria-hidden="true" /></Link>
                <span v-else class="grid h-8 w-8 place-items-center opacity-40" aria-hidden="true"><ChevronLeft class="h-4 w-4" /></span>
                <Link v-if="message.older" :href="messageUrl(message.folder, message.older)" v-bind="neighbour" class="grid h-8 w-8 place-items-center rounded-md hover:bg-accent" title="Message plus ancien" aria-label="Message plus ancien"><ChevronRight class="h-4 w-4" aria-hidden="true" /></Link>
                <span v-else class="grid h-8 w-8 place-items-center opacity-40" aria-hidden="true"><ChevronRight class="h-4 w-4" /></span>
            </div>
        </div>

        <div class="space-y-5 px-4 py-5 sm:px-6">
            <div class="flex items-start gap-3">
                <h2 class="min-w-0 flex-1 text-xl font-bold leading-snug text-foreground">{{ message.subject || '(Sans objet)' }}</h2>
                <button
                    type="button"
                    :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-md transition-colors hover:bg-accent', message.flagged ? 'text-amber-500' : 'text-muted-foreground')"
                    :aria-label="message.flagged ? 'Retirer des favoris' : 'Ajouter aux favoris'"
                    :aria-pressed="message.flagged"
                    :disabled="processing"
                    @click="act(message.flagged ? 'unstar' : 'star')"
                >
                    <Star :class="cn('h-5 w-5', message.flagged && 'fill-current')" aria-hidden="true" />
                </button>
            </div>
            <div v-if="messageLabels.length" class="-mt-3 flex flex-wrap gap-1.5">
                <span v-for="label in messageLabels" :key="label.uuid" :class="cn('inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold', labelColor(label.color).soft)">
                    {{ label.name }}
                    <button type="button" class="rounded-full hover:opacity-70" :aria-label="`Retirer le libellé ${label.name}`" :disabled="processing" @click="act('unlabel', { label: label.uuid })"><X class="h-3 w-3" aria-hidden="true" /></button>
                </span>
            </div>

            <div class="flex items-start gap-3">
                <span :class="cn('grid h-11 w-11 shrink-0 place-items-center rounded-full text-sm font-bold', avatarTone(message.from?.email))" aria-hidden="true">{{ initialsOf(message.from) }}</span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <p class="min-w-0 text-sm">
                            <span class="font-semibold text-foreground">{{ senderLabel(message.from) }}</span>
                            <span v-if="message.from?.name" class="ms-1 text-muted-foreground">&lt;{{ message.from.email }}&gt;</span>
                        </p>
                        <time :datetime="message.date" class="shrink-0 text-xs text-muted-foreground">{{ formatFullDate(message.date) }}</time>
                    </div>
                    <button type="button" class="mt-0.5 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground" :aria-expanded="showDetails" @click="showDetails = !showDetails">
                        {{ recipientsLine }} <ChevronDown :class="cn('h-3.5 w-3.5 transition-transform', showDetails && 'rotate-180')" aria-hidden="true" />
                    </button>
                    <dl v-if="showDetails" class="mt-2 grid grid-cols-[4.5rem_1fr] gap-x-3 gap-y-1 rounded-lg border border-border bg-muted/30 p-3 text-xs">
                        <dt class="text-muted-foreground">De</dt><dd class="break-all text-foreground">{{ formatParty(message.from) }}</dd>
                        <template v-if="message.reply_to?.length"><dt class="text-muted-foreground">Répondre à</dt><dd class="break-all text-foreground">{{ message.reply_to.map(formatParty).join(', ') }}</dd></template>
                        <dt class="text-muted-foreground">À</dt><dd class="break-all text-foreground">{{ (message.to ?? []).map(formatParty).join(', ') || '—' }}</dd>
                        <template v-if="message.cc?.length"><dt class="text-muted-foreground">Cc</dt><dd class="break-all text-foreground">{{ message.cc.map(formatParty).join(', ') }}</dd></template>
                        <template v-if="message.bcc?.length"><dt class="text-muted-foreground">Cci</dt><dd class="break-all text-foreground">{{ message.bcc.map(formatParty).join(', ') }}</dd></template>
                        <dt class="text-muted-foreground">Date</dt><dd class="text-foreground">{{ formatFullDate(message.date) }}</dd>
                        <dt class="text-muted-foreground">Dossier</dt><dd class="text-foreground">{{ folder.name }}</dd>
                    </dl>
                </div>
            </div>

            <div v-if="message.blocked_images > 0" class="flex flex-wrap items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                <ImageOff class="h-4 w-4 shrink-0" aria-hidden="true" />
                <span class="flex-1">{{ message.blocked_images }} image{{ message.blocked_images > 1 ? 's' : '' }} distante{{ message.blocked_images > 1 ? 's' : '' }} masquée{{ message.blocked_images > 1 ? 's' : '' }} : elles peuvent signaler à l’expéditeur que vous avez lu le message.</span>
                <Link :href="remoteImagesUrl" v-bind="WEBMAIL_NAVIGATION" replace preserve-scroll class="font-semibold underline-offset-2 hover:underline">Afficher les images</Link>
            </div>

            <div v-if="message.draft" class="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm">
                <SquarePen class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <span class="flex-1 text-muted-foreground">Ce message est un brouillon : il n’est pas encore parti.</span>
                <Button type="button" size="sm" @click="emit('compose', 'draft')"><SquarePen class="h-4 w-4" aria-hidden="true" /> Reprendre le brouillon</Button>
            </div>

            <div class="rounded-xl border border-border bg-white p-3 sm:p-4">
                <EmailBodyFrame ref="body" :html="message.body_html" :allow-remote="allowRemote" :print-header="printHeader" :title="`Contenu du message ${message.subject || ''}`" />
            </div>

            <section v-if="message.attachments?.length" aria-label="Pièces jointes">
                <h3 class="mb-2 text-sm font-semibold text-foreground">{{ message.attachments.length }} pièce{{ message.attachments.length > 1 ? 's' : '' }} jointe{{ message.attachments.length > 1 ? 's' : '' }}</h3>
                <ul class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    <li v-for="attachment in message.attachments" :key="attachment.part">
                        <a :href="attachmentUrl(attachment)" class="group flex items-center gap-3 rounded-lg border border-border bg-card p-3 transition-colors hover:border-primary/40 hover:bg-accent/40" :title="`Télécharger ${attachment.name}`" download>
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground"><component :is="attachmentIcon(attachment.type)" class="h-5 w-5" aria-hidden="true" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-foreground">{{ attachment.name }}</span>
                                <span class="block text-xs text-muted-foreground">{{ formatSize(attachment.size) }}</span>
                            </span>
                            <Download class="h-4 w-4 shrink-0 text-muted-foreground group-hover:text-primary" aria-hidden="true" />
                        </a>
                    </li>
                </ul>
            </section>

            <div v-if="!message.draft" class="flex flex-wrap gap-2 border-t border-border pt-4">
                <Button type="button" variant="outline" @click="emit('compose', 'reply')"><Reply class="h-4 w-4" aria-hidden="true" /> Répondre</Button>
                <Button v-if="canReplyAll" type="button" variant="outline" @click="emit('compose', 'replyAll')"><ReplyAll class="h-4 w-4" aria-hidden="true" /> Répondre à tous</Button>
                <Button type="button" variant="outline" @click="emit('compose', 'forward')"><Forward class="h-4 w-4" aria-hidden="true" /> Transférer</Button>
            </div>
        </div>
    </article>
</template>
