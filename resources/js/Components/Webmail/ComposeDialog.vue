<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import {
    BookmarkPlus,
    ChevronUp,
    File as FileIcon,
    FileImage,
    FileSpreadsheet,
    FileText,
    Forward,
    LoaderCircle,
    Maximize2,
    Minimize2,
    Minus,
    Paperclip,
    Reply,
    Save,
    Send,
    SquarePen,
    Trash2,
    Type,
    Upload,
    X,
} from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import DropdownMenu from '@/Components/Shadcn/DropdownMenu.vue';
import Input from '@/Components/Shadcn/Input.vue';
import { cn } from '@/lib/cn';
import EmailEditor from '@/Components/Webmail/EmailEditor.vue';
import RecipientInput from '@/Components/Webmail/RecipientInput.vue';
import { useToastStore } from '@/stores/toast';
import {
    WEBMAIL_BASE,
    WEBMAIL_CACHE_TAG,
    avatarTone,
    composeLeavesMessaging,
    folderUrl,
    formatSize,
    forwardSubject,
    forwardedBody,
    initialsOf,
    parseRecipients,
    quotedReply,
    reloadAfterSending,
    replyRecipients,
    replySubject,
    toFormData,
} from '@/utilities/webmail';

/**
 * ADR-195 — rédiger un message : nouveau, réponse, réponse à tous, transfert, ou
 * brouillon repris. Il part de l'adresse du titulaire ; le serveur relit tout
 * (destinataires, corps, pièces jointes) avant l'envoi.
 *
 * Amendement du 2026-09-30 — une fenêtre de rédaction comme dans une messagerie :
 * ancrée en bas à droite, elle laisse la messagerie utilisable (lire un autre
 * message, y copier un passage). Elle se réduit en une barre, s'agrandit au centre
 * (Échap ramène à la taille normale), et prend tout l'écran sur un téléphone.
 * Les pièces jointes se déposent sur elle. Rien ne se perd : ni un clic à côté, ni
 * la réduction ; quitter la messagerie ou l'onglet avec un message commencé demande
 * confirmation, et fermer un message commencé aussi.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** new | reply | replyAll | forward | draft */
    mode: { type: String, default: 'new' },
    /** Le message d'origine (réponse, transfert, brouillon repris). */
    message: { type: Object, default: null },
    /** Un destinataire déjà choisi (un collègue, depuis la liste des contacts). */
    to: { type: String, default: '' },
    address: { type: String, required: true },
    owner: { type: String, default: '' },
    contacts: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({ attachment_max_mb: 10, attachments_total_mb: 20, max_recipients: 50 }) },
    /** Le dossier affiché : après l'envoi, seule sa liste change (Envoyés, Brouillons). */
    current: { type: String, default: 'reception' },
    /** Change à chaque demande de rédaction : une nouvelle demande, fenêtre déjà ouverte. */
    request: { type: Number, default: 0 },
});
const emit = defineEmits(['update:open', 'sending']);
const toast = useToastStore();

const form = useForm({
    to: '',
    cc: '',
    bcc: '',
    subject: '',
    body_html: '',
    attachments: [],
    reply: null,
    forward: null,
    draft: null,
    return_to: null,
});

const showCc = ref(false);
const showBcc = ref(false);
const editor = ref(null);
const fileInput = ref(null);
const attachmentError = ref('');
const confirmDiscard = ref(false);
const templateName = ref('');
const savingTemplate = ref(false);
const initialSnapshot = ref('');
/** docked (en bas à droite) | maximized (au centre) | minimized (une barre) */
const view = ref('docked');
const formatting = ref(true);
const dragDepth = ref(0);
const confirmReplace = ref(false);
const confirmLeave = ref(null);
const titleId = 'webmail-compose-title';
const FORMATTING_KEY = 'rivo:webmail:formatting';

const snapshot = () => JSON.stringify([form.to, form.cc, form.bcc, form.subject, form.body_html, form.attachments.length]);
const dirty = computed(() => props.open && snapshot() !== initialSnapshot.value);

// Ce que la fenêtre rédige, figé à son ouverture : une autre demande (Répondre ailleurs)
// ne change ni son titre ni les pièces qu'elle reprend tant qu'on ne l'a pas acceptée.
const drafting = shallowRef({ mode: props.mode, message: props.message });
const source = computed(() => drafting.value.message ? { folder: drafting.value.message.folder, uid: drafting.value.message.uid } : null);

const titles = {
    new: 'Nouveau message',
    reply: 'Répondre',
    replyAll: 'Répondre à tous',
    forward: 'Transférer',
    draft: 'Reprendre le brouillon',
};
const titleIcons = { new: SquarePen, reply: Reply, replyAll: Reply, forward: Forward, draft: SquarePen };
const headline = computed(() => form.subject.trim() || titles[drafting.value.mode] || titles.new);
const sender = computed(() => ({ name: props.owner, email: props.address }));

const initialise = () => {
    form.reset();
    form.clearErrors();
    attachmentError.value = '';
    templateName.value = '';
    savingTemplate.value = false;
    drafting.value = { mode: props.mode, message: props.message };
    const { mode, message } = drafting.value;

    if ((mode === 'reply' || mode === 'replyAll') && message) {
        const recipients = replyRecipients(message, props.address, mode === 'replyAll');
        form.to = recipients.to;
        form.cc = recipients.cc;
        form.subject = replySubject(message.subject);
        form.body_html = quotedReply(message);
        form.reply = source.value;
    } else if (mode === 'forward' && message) {
        form.subject = forwardSubject(message.subject);
        form.body_html = forwardedBody(message);
        form.forward = message.attachments?.length ? source.value : null;
    } else if (mode === 'draft' && message) {
        const list = (parties) => (parties ?? []).map((party) => (party.name ? `${party.name} <${party.email}>` : party.email)).join(', ');
        form.to = list(message.to);
        form.cc = list(message.cc);
        form.bcc = list(message.bcc);
        form.subject = message.subject ?? '';
        form.body_html = message.body_html ?? '';
        form.draft = source.value;
        // Les pièces du brouillon repartent avec lui, lues sur le serveur.
        form.forward = message.attachments?.length ? source.value : null;
        form.return_to = folderUrl('brouillons');
    } else {
        form.to = props.to;
    }

    showCc.value = Boolean(form.cc);
    showBcc.value = Boolean(form.bcc);
    view.value = 'docked';
    dragDepth.value = 0;
    try {
        formatting.value = window.localStorage.getItem(FORMATTING_KEY) !== 'off';
    } catch {
        formatting.value = true;
    }
    initialSnapshot.value = snapshot();
};

const focusFirst = () => nextTick(() => {
    if (!form.to) document.getElementById('webmail-to')?.focus();
    else if (['reply', 'replyAll', 'forward'].includes(drafting.value.mode) && drafting.value.message) editor.value?.focus();
    else editor.value?.focusEnd?.();
});

// Un envoi refusé rouvre la fenêtre : le message revient tel quel, pas réinitialisé.
let resuming = false;
// La demande déjà servie : une ouverture et sa demande arrivent ensemble.
let servedRequest = props.request;

watch(() => props.open, (open) => {
    if (open && resuming) {
        resuming = false;
        return;
    }
    if (open) {
        servedRequest = props.request;
        initialise();
        focusFirst();
    }
}, { immediate: true });

// Une autre demande de rédaction (Répondre, un contact…) alors que la fenêtre est
// ouverte : un message vide est remplacé ; un message commencé n'est jamais écrasé
// sans le demander.
watch(() => props.request, (request) => {
    if (!props.open || request === servedRequest) return;
    servedRequest = request;
    if (dirty.value) {
        view.value = 'docked';
        confirmReplace.value = true;
        return;
    }
    initialise();
    focusFirst();
});

const replaceCurrent = () => {
    confirmReplace.value = false;
    initialise();
    focusFirst();
};

const toggleFormatting = () => {
    formatting.value = !formatting.value;
    try {
        window.localStorage.setItem(FORMATTING_KEY, formatting.value ? 'on' : 'off');
    } catch {
        // Sans stockage, la préférence vaut pour cette fenêtre seulement.
    }
};

const minimize = () => { view.value = view.value === 'minimized' ? 'docked' : 'minimized'; };
const toggleMaximize = () => { view.value = view.value === 'maximized' ? 'docked' : 'maximized'; };
const showCopy = (field) => {
    if (field === 'cc') showCc.value = true;
    else showBcc.value = true;
    nextTick(() => document.getElementById(`webmail-${field}`)?.focus());
};

const onHeaderDblClick = (event) => {
    if (view.value === 'minimized' || event.target.closest('button')) return;
    toggleMaximize();
};

const restore = () => {
    if (view.value !== 'minimized') return;
    view.value = 'docked';
    nextTick(() => editor.value?.focusEnd?.());
};

const carried = computed(() => (form.forward && drafting.value.message ? (drafting.value.message.attachments ?? []) : []));

const totalBytes = computed(() => form.attachments.reduce((sum, file) => sum + file.size, 0)
    + carried.value.reduce((sum, file) => sum + Number(file.size ?? 0), 0));

const addFiles = (files) => {
    attachmentError.value = '';
    const maxBytes = props.limits.attachment_max_mb * 1024 * 1024;
    const totalLimit = props.limits.attachments_total_mb * 1024 * 1024;
    const accepted = [];

    for (const file of Array.from(files ?? [])) {
        if (file.size > maxBytes) {
            attachmentError.value = `« ${file.name} » dépasse ${props.limits.attachment_max_mb} Mo.`;
            continue;
        }
        if (totalBytes.value + accepted.reduce((sum, item) => sum + item.size, 0) + file.size > totalLimit) {
            attachmentError.value = `Les pièces jointes dépasseraient ${props.limits.attachments_total_mb} Mo au total.`;
            continue;
        }
        accepted.push(file);
    }

    form.attachments = [...form.attachments, ...accepted];
};

const onFilesChosen = (event) => {
    addFiles(event.target.files);
    event.target.value = '';
};

// Déposer des fichiers sur la fenêtre les joint.
const carriesFiles = (event) => Array.from(event.dataTransfer?.types ?? []).includes('Files');
const onDragEnter = (event) => {
    if (!carriesFiles(event) || form.processing) return;
    event.preventDefault();
    if (view.value === 'minimized') view.value = 'docked';
    dragDepth.value += 1;
};
const onDragOver = (event) => {
    if (carriesFiles(event)) event.preventDefault();
};
const onDragLeave = (event) => {
    if (carriesFiles(event)) dragDepth.value = Math.max(0, dragDepth.value - 1);
};
const onDrop = (event) => {
    if (!carriesFiles(event)) return;
    event.preventDefault();
    dragDepth.value = 0;
    addFiles(event.dataTransfer.files);
};

const fileIcon = (file) => {
    const name = String(file?.name ?? '').toLowerCase();
    const type = String(file?.type ?? file?.mime ?? '').toLowerCase();
    if (type.startsWith('image/') || /\.(png|jpe?g|gif|webp|bmp|svg)$/.test(name)) return FileImage;
    if (/\.(xlsx?|csv|ods)$/.test(name) || type.includes('spreadsheet')) return FileSpreadsheet;
    if (/\.(pdf|docx?|odt|txt|rtf)$/.test(name) || type.includes('pdf') || type.includes('word') || type.startsWith('text/')) return FileText;
    return FileIcon;
};

const removeFile = (index) => {
    form.attachments = form.attachments.filter((_, position) => position !== index);
};

const recipientCount = computed(() => ['to', 'cc', 'bcc'].reduce((sum, field) => sum + parseRecipients(form[field]).valid.length, 0));
const invalidRecipients = computed(() => ['to', 'cc', 'bcc'].flatMap((field) => parseRecipients(form[field]).invalid));

const sendBlocker = computed(() => {
    if (invalidRecipients.value.length) return `Adresse invalide : ${invalidRecipients.value.slice(0, 2).join(', ')}.`;
    if (recipientCount.value === 0) return 'Indiquez au moins un destinataire.';
    if (recipientCount.value > props.limits.max_recipients) return `${props.limits.max_recipients} destinataires au plus, copies comprises.`;
    return '';
});

const close = () => emit('update:open', false);

const requestClose = () => {
    if (form.processing) return;
    if (dirty.value) confirmDiscard.value = true;
    else close();
};

// Quitter la messagerie ou l'onglet avec un message commencé : la question est posée.
// Naviguer dans la messagerie (un autre message, un autre dossier) garde la fenêtre.
let removeBefore = null;
let leaving = false;
const onBeforeUnload = (event) => {
    if (!dirty.value) return;
    event.preventDefault();
    event.returnValue = '';
};

onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);
    removeBefore = router.on('before', (event) => {
        const visit = event.detail.visit;
        if (leaving || !dirty.value || !composeLeavesMessaging(visit)) return;
        event.preventDefault();
        confirmLeave.value = visit;
    });
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
    removeBefore?.();
});

const leave = () => {
    const visit = confirmLeave.value;
    confirmLeave.value = null;
    if (!visit) return;
    leaving = true;
    close();
    router.visit(visit.url, { onFinish: () => { leaving = false; } });
};

// Un champ vide n'est pas envoyé : le serveur n'a rien à relire pour lui.
const payload = (data) => Object.fromEntries(Object.entries(data).filter(([, value]) => value !== null && value !== ''));

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/**
 * ADR-195 — envoyer ne fait plus attendre : la fenêtre se ferme aussitôt, le message
 * part en arrière-plan (quelques secondes vers le serveur d'envoi), un avis dit
 * quand il est parti. Refusé — adresse, serveur, boîte refermée —, il revient dans
 * la fenêtre, intact, avec la raison. Rien n'est perdu.
 */
const send = async () => {
    if (sendBlocker.value || form.processing) return;

    const body = toFormData(payload(form.data()));
    const subject = form.subject;
    form.clearErrors();
    emit('sending', true);
    close();
    const notice = toast.info('Envoi en cours…', 60000);
    let failure = null;

    try {
        const response = await fetch(`${WEBMAIL_BASE}/envoyer`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body,
        });
        const json = (response.headers.get('content-type') ?? '').includes('application/json')
            ? await response.json().catch(() => null)
            : null;

        if (response.ok && json?.status) {
            toast.dismiss(notice);
            toast.success(json.status);
            form.reset();
            router.flushByCacheTags([WEBMAIL_CACHE_TAG]);
            const only = reloadAfterSending(props.current);
            if (only.includes('list')) router.reload({ only, preserveScroll: true, async: true });
            return;
        }
        failure = json ?? {};
    } catch {
        failure = {};
    } finally {
        emit('sending', false);
    }

    toast.dismiss(notice);
    const errors = failure.errors
        ? Object.fromEntries(Object.entries(failure.errors).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value]))
        : { webmail: failure.message ?? 'Le message n’a pas pu partir : le serveur d’envoi ne répond pas. Il est gardé ici.' };
    form.setError(errors);
    toast.error(`« ${subject || 'Sans objet'} » n’est pas parti : il est revenu dans la fenêtre.`);
    resuming = true;
    emit('update:open', true);
};

const saveDraft = () => {
    if (form.processing) return;
    form.transform(payload).post(`${WEBMAIL_BASE}/brouillons`, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        only: reloadAfterSending(props.current, true),
        onSuccess: () => close(),
    });
};

const onKeydown = (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        event.preventDefault();
        send();
    } else if (event.key === 'Escape' && view.value === 'maximized' && !event.defaultPrevented) {
        event.preventDefault();
        view.value = 'docked';
    }
};

const windowClass = computed(() => cn(
    'fixed z-[1450] flex flex-col overflow-hidden border border-border bg-background text-foreground shadow-2xl motion-safe:animate-[rivo-compose-in_160ms_ease-out]',
    view.value === 'docked' && 'inset-0 sm:inset-auto sm:bottom-0 sm:end-4 sm:h-[min(40rem,calc(100dvh-5rem))] sm:w-[min(42rem,calc(100vw-2rem))] sm:rounded-t-xl sm:border-b-0',
    view.value === 'maximized' && 'inset-0 sm:inset-6 sm:mx-auto sm:max-w-6xl sm:rounded-xl',
    view.value === 'minimized' && 'inset-x-0 bottom-0 h-12 sm:inset-x-auto sm:end-4 sm:w-80 sm:rounded-t-xl sm:border-b-0',
));

const fieldLabel = 'w-10 shrink-0 pt-2 text-sm text-muted-foreground';

const attachmentFieldError = computed(() => Object.entries(form.errors).find(([key]) => key.startsWith('attachments.'))?.[1] ?? '');

const templateItems = computed(() => [
    ...props.templates.map((template) => ({
        key: template.uuid,
        label: template.name,
        description: template.subject ? `Objet : ${template.subject}` : 'Sans objet',
        icon: FileText,
    })),
    { key: '__save', label: 'Enregistrer ce message comme modèle', icon: BookmarkPlus, separatorBefore: props.templates.length > 0 },
]);

const useTemplate = (key) => {
    if (key === '__save') {
        savingTemplate.value = true;
        nextTick(() => document.getElementById('webmail-template-name')?.focus());
        return;
    }
    const template = props.templates.find((candidate) => candidate.uuid === key);
    if (!template) return;
    if (!form.subject.trim() && template.subject) form.subject = template.subject;
    editor.value?.insertHtml(template.body_html);
};

const templateErrors = ref({});
const storingTemplate = ref(false);
const storeTemplate = () => {
    storingTemplate.value = true;
    router.post(`${WEBMAIL_BASE}/modeles`, { name: templateName.value, subject: form.subject, body_html: form.body_html }, {
        preserveState: true,
        preserveScroll: true,
        only: ['templates', 'flash', 'errors'],
        onSuccess: () => {
            savingTemplate.value = false;
            templateName.value = '';
            templateErrors.value = {};
        },
        onError: (errors) => { templateErrors.value = errors; },
        onFinish: () => { storingTemplate.value = false; },
    });
};
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open && view === 'maximized'"
            class="fixed inset-0 z-[1440] hidden bg-slate-950/40 backdrop-blur-[1px] motion-safe:animate-[rivo-overlay-in_160ms_ease-out] sm:block"
            aria-hidden="true"
            @click="view = 'docked'"
        />

        <section
            v-if="open"
            role="dialog"
            :aria-modal="view === 'maximized' ? 'true' : 'false'"
            :aria-labelledby="titleId"
            :class="windowClass"
            data-compose-window
            @keydown="onKeydown"
            @dragenter="onDragEnter"
            @dragover="onDragOver"
            @dragleave="onDragLeave"
            @drop="onDrop"
        >
            <!-- La barre de titre : le sujet dès qu'il est écrit, comme dans une messagerie. -->
            <header
                :class="cn(
                    'flex h-12 shrink-0 items-center gap-2 border-b border-border bg-muted/60 ps-3 pe-1.5',
                    view === 'minimized' && 'cursor-pointer border-b-0 hover:bg-muted',
                )"
                @click="restore"
                @dblclick="onHeaderDblClick"
            >
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-primary/10 text-primary" aria-hidden="true">
                    <component :is="titleIcons[drafting.mode] ?? SquarePen" class="h-3.5 w-3.5" />
                </span>
                <h2 :id="titleId" class="min-w-0 flex-1 truncate text-sm font-semibold" :title="headline">{{ headline }}</h2>
                <span v-if="view === 'minimized' && (form.attachments.length || carried.length)" class="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground">
                    <Paperclip class="h-3 w-3" aria-hidden="true" />{{ form.attachments.length + carried.length }}
                </span>
                <div class="flex shrink-0 items-center" @click.stop>
                    <Button type="button" variant="ghost" size="icon-xs" class="hidden sm:inline-flex" :aria-label="view === 'minimized' ? 'Rouvrir le message' : 'Réduire'" :title="view === 'minimized' ? 'Rouvrir' : 'Réduire'" @click="minimize">
                        <ChevronUp v-if="view === 'minimized'" class="h-4 w-4" aria-hidden="true" />
                        <Minus v-else class="h-4 w-4" aria-hidden="true" />
                    </Button>
                    <Button v-if="view !== 'minimized'" type="button" variant="ghost" size="icon-xs" class="hidden sm:inline-flex" :aria-label="view === 'maximized' ? 'Taille normale' : 'Agrandir'" :title="view === 'maximized' ? 'Taille normale (Échap)' : 'Agrandir'" @click="toggleMaximize">
                        <Minimize2 v-if="view === 'maximized'" class="h-3.5 w-3.5" aria-hidden="true" />
                        <Maximize2 v-else class="h-3.5 w-3.5" aria-hidden="true" />
                    </Button>
                    <Button type="button" variant="ghost" size="icon-xs" aria-label="Fermer le message" title="Fermer" :disabled="form.processing" @click="requestClose">
                        <X class="h-4 w-4" aria-hidden="true" />
                    </Button>
                </div>
            </header>

            <form v-show="view !== 'minimized'" class="relative flex min-h-0 flex-1 flex-col" @submit.prevent="send">
                <!-- Les en-têtes, en lignes sans cadre séparées d'un filet. -->
                <div class="shrink-0 divide-y divide-border border-b border-border px-4 sm:px-5">
                    <div class="flex items-center gap-2 py-2">
                        <span class="w-10 shrink-0 text-sm text-muted-foreground">De</span>
                        <span :class="cn('inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold', avatarTone(address))" aria-hidden="true">{{ initialsOf(sender) }}</span>
                        <p class="min-w-0 truncate text-sm">
                            <span v-if="owner" class="font-medium">{{ owner }}</span>
                            <span :class="owner ? 'ms-1 text-muted-foreground' : 'font-medium'">{{ owner ? `<${address}>` : address }}</span>
                        </p>
                    </div>

                    <div :class="cn('py-1', form.errors.to && 'bg-destructive/5')">
                        <div class="flex items-start gap-2">
                            <label for="webmail-to" :class="fieldLabel">À</label>
                            <RecipientInput id="webmail-to" v-model="form.to" bare label="Destinataires" :contacts="contacts" :invalid="Boolean(form.errors.to)" placeholder="Nom d’un collègue ou n’importe quelle adresse email" class="min-w-0 flex-1" />
                            <div class="flex shrink-0 gap-0.5 pt-1">
                                <Button v-if="!showCc" type="button" variant="ghost" size="xs" class="px-2" title="Ajouter des destinataires en copie" @click="showCopy('cc')">Cc</Button>
                                <Button v-if="!showBcc" type="button" variant="ghost" size="xs" class="px-2" title="Copie cachée : les autres destinataires ne la voient pas" @click="showCopy('bcc')">Cci</Button>
                            </div>
                        </div>
                        <p v-if="form.errors.to" class="pb-1 ps-12 text-xs font-medium text-destructive">{{ form.errors.to }}</p>
                    </div>

                    <div v-if="showCc" :class="cn('py-1', form.errors.cc && 'bg-destructive/5')">
                        <div class="flex items-start gap-2">
                            <label for="webmail-cc" :class="fieldLabel">Cc</label>
                            <RecipientInput id="webmail-cc" v-model="form.cc" bare label="Copie" :contacts="contacts" :invalid="Boolean(form.errors.cc)" class="min-w-0 flex-1" />
                            <Button type="button" variant="ghost" size="icon-xs" aria-label="Retirer la copie" class="mt-1" @click="form.cc = ''; showCc = false"><X class="h-3.5 w-3.5" /></Button>
                        </div>
                        <p v-if="form.errors.cc" class="pb-1 ps-12 text-xs font-medium text-destructive">{{ form.errors.cc }}</p>
                    </div>

                    <div v-if="showBcc" :class="cn('py-1', form.errors.bcc && 'bg-destructive/5')">
                        <div class="flex items-start gap-2">
                            <label for="webmail-bcc" :class="fieldLabel" title="Copie cachée : les autres destinataires ne la voient pas">Cci</label>
                            <RecipientInput id="webmail-bcc" v-model="form.bcc" bare label="Copie cachée" :contacts="contacts" :invalid="Boolean(form.errors.bcc)" class="min-w-0 flex-1" />
                            <Button type="button" variant="ghost" size="icon-xs" aria-label="Retirer la copie cachée" class="mt-1" @click="form.bcc = ''; showBcc = false"><X class="h-3.5 w-3.5" /></Button>
                        </div>
                        <p v-if="form.errors.bcc" class="pb-1 ps-12 text-xs font-medium text-destructive">{{ form.errors.bcc }}</p>
                    </div>

                    <div :class="cn(form.errors.subject && 'bg-destructive/5')">
                        <label for="webmail-subject" class="sr-only">Objet</label>
                        <input
                            id="webmail-subject"
                            v-model="form.subject"
                            maxlength="255"
                            placeholder="Objet"
                            autocomplete="off"
                            :aria-invalid="Boolean(form.errors.subject) || undefined"
                            class="h-11 w-full border-0 bg-transparent px-0 text-sm font-medium text-foreground outline-none placeholder:font-normal placeholder:text-muted-foreground focus:ring-0"
                        >
                        <p v-if="form.errors.subject" class="pb-1 text-xs font-medium text-destructive">{{ form.errors.subject }}</p>
                    </div>
                </div>

                <!-- Le texte occupe toute la place ; la mise en forme se range au-dessus des boutons. -->
                <div class="relative min-h-0 flex-1">
                    <EmailEditor ref="editor" v-model="form.body_html" bare toolbar-position="bottom" :toolbar="formatting" :disabled="form.processing">
                        <template #before-toolbar>
                            <div v-if="savingTemplate || form.attachments.length || carried.length || attachmentError || form.errors.attachments || attachmentFieldError || form.errors.webmail" class="shrink-0 space-y-2 px-4 pb-2 sm:px-5">
                                <div v-if="savingTemplate" class="flex flex-wrap items-end gap-2 rounded-lg border border-dashed border-border bg-muted/30 p-3">
                                    <div class="min-w-[14rem] flex-1">
                                        <label for="webmail-template-name" class="mb-1 block text-xs font-semibold text-muted-foreground">Nom du modèle</label>
                                        <Input id="webmail-template-name" v-model="templateName" maxlength="80" placeholder="Ex. Accusé de réception" @keydown.enter.prevent="storeTemplate" />
                                        <p v-if="templateErrors.name || templateErrors.body_html" class="mt-1 text-xs font-medium text-destructive">{{ templateErrors.name || templateErrors.body_html }}</p>
                                    </div>
                                    <Button type="button" size="sm" :disabled="!templateName.trim() || storingTemplate" @click="storeTemplate">
                                        <LoaderCircle v-if="storingTemplate" class="h-4 w-4 animate-spin" aria-hidden="true" />
                                        Enregistrer le modèle
                                    </Button>
                                    <Button type="button" size="sm" variant="ghost" @click="savingTemplate = false">Annuler</Button>
                                    <p class="basis-full text-xs text-muted-foreground">L’objet et le corps actuels deviennent un modèle, rien que pour votre compte.</p>
                                </div>

                                <div v-if="form.attachments.length || carried.length">
                                    <p class="mb-1.5 text-xs text-muted-foreground">
                                        {{ form.attachments.length + carried.length }} pièce{{ form.attachments.length + carried.length > 1 ? 's' : '' }} jointe{{ form.attachments.length + carried.length > 1 ? 's' : '' }}
                                        · {{ formatSize(totalBytes) }} sur {{ limits.attachments_total_mb }} Mo
                                    </p>
                                    <ul class="flex flex-wrap gap-2">
                                        <li v-for="file in carried" :key="`carried-${file.part}`" class="inline-flex max-w-[16rem] items-center gap-2 rounded-lg border border-border bg-muted/40 py-1.5 pe-1.5 ps-2 text-xs" :title="drafting.mode === 'draft' ? 'Pièce du brouillon, reprise automatiquement' : 'Pièce du message d’origine, jointe automatiquement'">
                                            <component :is="fileIcon(file)" class="h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                                            <span class="min-w-0">
                                                <span class="block truncate font-medium">{{ file.name }}</span>
                                                <span class="block text-[11px] text-muted-foreground">{{ formatSize(file.size) }} · reprise</span>
                                            </span>
                                            <button type="button" class="rounded p-0.5 text-muted-foreground hover:bg-background hover:text-foreground" aria-label="Ne pas joindre les pièces d’origine" title="Ne pas joindre les pièces d’origine" @click="form.forward = null"><X class="h-3.5 w-3.5" aria-hidden="true" /></button>
                                        </li>
                                        <li v-for="(file, index) in form.attachments" :key="`${file.name}-${index}`" class="inline-flex max-w-[16rem] items-center gap-2 rounded-lg border border-border bg-card py-1.5 pe-1.5 ps-2 text-xs shadow-sm">
                                            <component :is="fileIcon(file)" class="h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                                            <span class="min-w-0">
                                                <span class="block truncate font-medium">{{ file.name }}</span>
                                                <span class="block text-[11px] text-muted-foreground">{{ formatSize(file.size) }}</span>
                                            </span>
                                            <button type="button" class="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground" :aria-label="`Retirer ${file.name}`" @click="removeFile(index)"><X class="h-3.5 w-3.5" aria-hidden="true" /></button>
                                        </li>
                                    </ul>
                                </div>
                                <p v-if="attachmentError || form.errors.attachments" class="text-xs font-medium text-destructive">{{ attachmentError || form.errors.attachments }}</p>
                                <p v-if="attachmentFieldError" class="text-xs font-medium text-destructive">{{ attachmentFieldError }}</p>
                                <p v-if="form.errors.webmail" role="alert" class="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm font-medium text-destructive">{{ form.errors.webmail }}</p>
                            </div>
                        </template>
                    </EmailEditor>

                    <div v-if="dragDepth > 0" class="pointer-events-none absolute inset-2 z-10 flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-primary bg-background/90 text-primary">
                        <Upload class="h-6 w-6" aria-hidden="true" />
                        <p class="text-sm font-semibold">Déposez vos fichiers pour les joindre</p>
                        <p class="text-xs text-muted-foreground">{{ limits.attachment_max_mb }} Mo par fichier, {{ limits.attachments_total_mb }} Mo au total</p>
                    </div>
                </div>

                <!-- Envoyer d'abord, les outils ensuite, abandonner à l'autre bout. -->
                <footer class="flex shrink-0 items-center gap-1 px-3 pb-3 pt-1.5 sm:px-4">
                    <Button type="button" class="rounded-full px-5" :disabled="Boolean(sendBlocker) || form.processing" :title="sendBlocker || 'Envoyer (Ctrl+Entrée)'" @click="send">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" aria-hidden="true" />
                        <Send v-else class="h-4 w-4" aria-hidden="true" />
                        Envoyer
                    </Button>

                    <div class="ms-1 flex min-w-0 items-center gap-0.5">
                        <Button type="button" variant="ghost" size="icon" :class="formatting && 'bg-accent text-foreground'" :aria-pressed="formatting" :aria-label="formatting ? 'Masquer la mise en forme' : 'Afficher la mise en forme'" :title="formatting ? 'Masquer la mise en forme' : 'Mise en forme'" @click="toggleFormatting">
                            <Type class="h-4 w-4" aria-hidden="true" />
                        </Button>
                        <input ref="fileInput" type="file" multiple class="sr-only" tabindex="-1" aria-hidden="true" @change="onFilesChosen">
                        <Button type="button" variant="ghost" size="icon" :disabled="form.processing" aria-label="Joindre des fichiers" :title="`Joindre des fichiers (${limits.attachment_max_mb} Mo par fichier) — ou déposez-les sur la fenêtre`" @click="fileInput?.click()">
                            <Paperclip class="h-4 w-4" aria-hidden="true" />
                        </Button>
                        <DropdownMenu :items="templateItems" label="Modèles de message" align="start" @select="useTemplate">
                            <template #trigger>
                                <Button type="button" variant="ghost" size="icon" :disabled="form.processing" aria-label="Modèles de message" title="Modèles de message"><FileText class="h-4 w-4" aria-hidden="true" /></Button>
                            </template>
                        </DropdownMenu>
                        <Button type="button" variant="ghost" size="sm" :disabled="form.processing" title="Enregistrer dans les brouillons et fermer" @click="saveDraft">
                            <Save class="h-4 w-4" aria-hidden="true" /><span class="hidden sm:inline">Brouillon</span>
                        </Button>
                    </div>

                    <p v-if="sendBlocker && recipientCount + invalidRecipients.length > 0" class="ms-2 min-w-0 flex-1 truncate text-xs text-destructive" :title="sendBlocker">{{ sendBlocker }}</p>
                    <p v-else class="ms-2 hidden min-w-0 flex-1 truncate text-xs text-muted-foreground lg:block">
                        <kbd class="rounded border border-border bg-muted px-1 py-0.5 font-sans text-[10px]">Ctrl</kbd> + <kbd class="rounded border border-border bg-muted px-1 py-0.5 font-sans text-[10px]">Entrée</kbd> pour envoyer
                    </p>
                    <span v-if="!(sendBlocker && recipientCount + invalidRecipients.length > 0)" class="flex-1 lg:hidden" aria-hidden="true" />

                    <Button type="button" variant="ghost" size="icon" class="hover:text-destructive" :disabled="form.processing" aria-label="Abandonner ce message" title="Abandonner ce message" @click="requestClose">
                        <Trash2 class="h-4 w-4" aria-hidden="true" />
                    </Button>
                </footer>
            </form>
        </section>
    </Teleport>

    <ConfirmModal
        v-model:open="confirmDiscard"
        title="Abandonner ce message ?"
        description="Ce que vous avez écrit sera perdu. Pour le garder, enregistrez-le en brouillon."
        confirm-label="Abandonner"
        cancel-label="Continuer à écrire"
        tone="danger"
        @confirm="confirmDiscard = false; close()"
    />
    <ConfirmModal
        v-model:open="confirmReplace"
        title="Remplacer le message en cours ?"
        description="Un message est en cours d’écriture. Le remplacer perdra ce que vous avez écrit ; pour le garder, enregistrez-le d’abord en brouillon."
        confirm-label="Remplacer"
        cancel-label="Continuer celui-ci"
        tone="danger"
        @confirm="replaceCurrent"
    />
    <ConfirmModal
        :open="Boolean(confirmLeave)"
        title="Quitter la messagerie ?"
        description="Le message en cours d’écriture sera perdu. Pour le garder, restez et enregistrez-le en brouillon."
        confirm-label="Quitter sans enregistrer"
        cancel-label="Rester"
        tone="danger"
        @update:open="(value) => value || (confirmLeave = null)"
        @confirm="leave"
    />
</template>

<style>
@keyframes rivo-compose-in {
    from { opacity: 0; transform: translateY(0.75rem); }
    to { opacity: 1; transform: none; }
}

/* La fenêtre occupe le coin où se tient le bouton de l'assistant : il s'efface le temps qu'elle est ouverte. */
body:has([data-compose-window]) [data-assistant-launcher] {
    opacity: 0;
    pointer-events: none;
}
</style>
