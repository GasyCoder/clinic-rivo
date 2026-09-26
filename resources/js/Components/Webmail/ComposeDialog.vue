<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { BookmarkPlus, FileText, Forward, LoaderCircle, Paperclip, Reply, Save, Send, SquarePen, Trash2, X } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import DropdownMenu from '@/Components/Shadcn/DropdownMenu.vue';
import Input from '@/Components/Shadcn/Input.vue';
import EmailEditor from '@/Components/Webmail/EmailEditor.vue';
import RecipientInput from '@/Components/Webmail/RecipientInput.vue';
import { useToastStore } from '@/stores/toast';
import {
    WEBMAIL_BASE,
    WEBMAIL_CACHE_TAG,
    folderUrl,
    formatSize,
    forwardSubject,
    forwardedBody,
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
 * La fenêtre ne se ferme pas sur un clic à côté : un message de plusieurs
 * minutes ne doit pas partir sur une maladresse. Fermer un message commencé
 * demande confirmation.
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

const snapshot = () => JSON.stringify([form.to, form.cc, form.bcc, form.subject, form.body_html, form.attachments.length]);
const dirty = computed(() => props.open && snapshot() !== initialSnapshot.value);

const source = computed(() => props.message ? { folder: props.message.folder, uid: props.message.uid } : null);

const titles = {
    new: 'Nouveau message',
    reply: 'Répondre',
    replyAll: 'Répondre à tous',
    forward: 'Transférer',
    draft: 'Reprendre le brouillon',
};
const titleIcons = { new: SquarePen, reply: Reply, replyAll: Reply, forward: Forward, draft: SquarePen };

const initialise = () => {
    form.reset();
    form.clearErrors();
    attachmentError.value = '';
    templateName.value = '';
    savingTemplate.value = false;
    const message = props.message;

    if ((props.mode === 'reply' || props.mode === 'replyAll') && message) {
        const recipients = replyRecipients(message, props.address, props.mode === 'replyAll');
        form.to = recipients.to;
        form.cc = recipients.cc;
        form.subject = replySubject(message.subject);
        form.body_html = quotedReply(message);
        form.reply = source.value;
    } else if (props.mode === 'forward' && message) {
        form.subject = forwardSubject(message.subject);
        form.body_html = forwardedBody(message);
        form.forward = message.attachments?.length ? source.value : null;
    } else if (props.mode === 'draft' && message) {
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
    initialSnapshot.value = snapshot();
};

// Un envoi refusé rouvre la fenêtre : le message revient tel quel, pas réinitialisé.
let resuming = false;

watch(() => props.open, (open) => {
    if (open && resuming) {
        resuming = false;
        return;
    }
    if (open) {
        initialise();
        nextTick(() => {
            if (form.to) editor.value?.focus();
            else document.getElementById('webmail-to')?.focus();
        });
    }
}, { immediate: true });

const carried = computed(() => (form.forward && props.message ? (props.message.attachments ?? []) : []));

const totalBytes = computed(() => form.attachments.reduce((sum, file) => sum + file.size, 0)
    + carried.value.reduce((sum, file) => sum + Number(file.size ?? 0), 0));

const addFiles = (event) => {
    attachmentError.value = '';
    const maxBytes = props.limits.attachment_max_mb * 1024 * 1024;
    const totalLimit = props.limits.attachments_total_mb * 1024 * 1024;
    const accepted = [];

    for (const file of Array.from(event.target.files ?? [])) {
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
    event.target.value = '';
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

const onOpenChange = (open) => {
    if (!open) requestClose();
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
    }
};

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
    <Dialog
        :open="open"
        :title="titles[mode] ?? titles.new"
        :description="`De : ${owner ? `${owner} <${address}>` : address}`"
        size="xl"
        :dismissible="false"
        body-class="px-0 py-0"
        close-label="Fermer le message"
        @update:open="onOpenChange"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                <component :is="titleIcons[mode] ?? SquarePen" class="h-5 w-5" aria-hidden="true" />
            </span>
        </template>

        <form class="flex max-h-[calc(100vh-14rem)] flex-col overflow-y-auto" @submit.prevent="send" @keydown="onKeydown">
            <div class="space-y-2 border-b border-border px-6 py-4">
                <div class="grid grid-cols-[3.5rem_1fr_auto] items-start gap-2">
                    <label for="webmail-to" class="pt-2 text-sm font-medium text-muted-foreground">À</label>
                    <RecipientInput id="webmail-to" v-model="form.to" label="Destinataires" :contacts="contacts" :invalid="Boolean(form.errors.to)" placeholder="Nom d’un collègue ou n’importe quelle adresse email" />
                    <div class="flex gap-1 pt-1">
                        <Button v-if="!showCc" type="button" variant="ghost" size="xs" @click="showCc = true">Cc</Button>
                        <Button v-if="!showBcc" type="button" variant="ghost" size="xs" @click="showBcc = true">Cci</Button>
                    </div>
                </div>
                <p v-if="form.errors.to" class="ms-[4rem] text-xs font-medium text-destructive">{{ form.errors.to }}</p>

                <div v-if="showCc" class="grid grid-cols-[3.5rem_1fr_auto] items-start gap-2">
                    <label for="webmail-cc" class="pt-2 text-sm font-medium text-muted-foreground">Cc</label>
                    <RecipientInput id="webmail-cc" v-model="form.cc" label="Copie" :contacts="contacts" :invalid="Boolean(form.errors.cc)" />
                    <Button type="button" variant="ghost" size="xs" icon aria-label="Retirer la copie" class="mt-1" @click="form.cc = ''; showCc = false"><X class="h-3.5 w-3.5" /></Button>
                </div>
                <p v-if="form.errors.cc" class="ms-[4rem] text-xs font-medium text-destructive">{{ form.errors.cc }}</p>

                <div v-if="showBcc" class="grid grid-cols-[3.5rem_1fr_auto] items-start gap-2">
                    <label for="webmail-bcc" class="pt-2 text-sm font-medium text-muted-foreground" title="Copie cachée : les autres destinataires ne la voient pas">Cci</label>
                    <RecipientInput id="webmail-bcc" v-model="form.bcc" label="Copie cachée" :contacts="contacts" :invalid="Boolean(form.errors.bcc)" />
                    <Button type="button" variant="ghost" size="xs" icon aria-label="Retirer la copie cachée" class="mt-1" @click="form.bcc = ''; showBcc = false"><X class="h-3.5 w-3.5" /></Button>
                </div>
                <p v-if="form.errors.bcc" class="ms-[4rem] text-xs font-medium text-destructive">{{ form.errors.bcc }}</p>

                <div class="grid grid-cols-[3.5rem_1fr] items-center gap-2">
                    <label for="webmail-subject" class="text-sm font-medium text-muted-foreground">Objet</label>
                    <Input id="webmail-subject" v-model="form.subject" maxlength="255" placeholder="Objet du message" :aria-invalid="Boolean(form.errors.subject) || undefined" />
                </div>
                <p v-if="form.errors.subject" class="ms-[4rem] text-xs font-medium text-destructive">{{ form.errors.subject }}</p>
            </div>

            <div class="space-y-3 px-6 py-4">
                <EmailEditor ref="editor" v-model="form.body_html" :disabled="form.processing" />

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

                <div v-if="form.attachments.length || carried.length" class="space-y-1.5">
                    <p class="text-xs font-semibold text-muted-foreground">Pièces jointes · {{ formatSize(totalBytes) }} sur {{ limits.attachments_total_mb }} Mo</p>
                    <ul class="flex flex-wrap gap-2">
                        <li v-for="file in carried" :key="`carried-${file.part}`" class="inline-flex max-w-full items-center gap-2 rounded-lg border border-border bg-muted/40 px-2.5 py-1.5 text-xs" :title="mode === 'draft' ? 'Pièce du brouillon, reprise automatiquement' : 'Pièce du message d’origine, jointe automatiquement'">
                            <Paperclip class="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <span class="truncate font-medium">{{ file.name }}</span>
                            <span class="shrink-0 text-muted-foreground">{{ formatSize(file.size) }}</span>
                            <button type="button" class="rounded p-0.5 hover:bg-background" :aria-label="`Ne pas joindre les pièces d’origine`" title="Ne pas joindre les pièces d’origine" @click="form.forward = null"><X class="h-3 w-3" aria-hidden="true" /></button>
                        </li>
                        <li v-for="(file, index) in form.attachments" :key="`${file.name}-${index}`" class="inline-flex max-w-full items-center gap-2 rounded-lg border border-border bg-card px-2.5 py-1.5 text-xs">
                            <Paperclip class="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <span class="truncate font-medium">{{ file.name }}</span>
                            <span class="shrink-0 text-muted-foreground">{{ formatSize(file.size) }}</span>
                            <button type="button" class="rounded p-0.5 hover:bg-muted" :aria-label="`Retirer ${file.name}`" @click="removeFile(index)"><X class="h-3 w-3" aria-hidden="true" /></button>
                        </li>
                    </ul>
                </div>
                <p v-if="attachmentError || form.errors.attachments" class="text-xs font-medium text-destructive">{{ attachmentError || form.errors.attachments }}</p>
                <p v-if="Object.keys(form.errors).some((key) => key.startsWith('attachments.'))" class="text-xs font-medium text-destructive">
                    {{ Object.entries(form.errors).find(([key]) => key.startsWith('attachments.'))?.[1] }}
                </p>
                <p v-if="form.errors.webmail" role="alert" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">{{ form.errors.webmail }}</p>
            </div>
        </form>

        <template #footer>
            <div class="flex w-full flex-wrap items-center gap-2">
                <input ref="fileInput" type="file" multiple class="sr-only" tabindex="-1" aria-hidden="true" @change="addFiles">
                <Button type="button" variant="outline" size="sm" :disabled="form.processing" @click="fileInput?.click()">
                    <Paperclip class="h-4 w-4" aria-hidden="true" /> Joindre
                </Button>
                <DropdownMenu :items="templateItems" label="Modèles de message" align="start" @select="useTemplate">
                    <template #trigger>
                        <Button type="button" variant="outline" size="sm" :disabled="form.processing"><FileText class="h-4 w-4" aria-hidden="true" /> Modèles</Button>
                    </template>
                </DropdownMenu>
                <Button type="button" variant="ghost" size="sm" :disabled="form.processing" title="Abandonner ce message" @click="requestClose">
                    <Trash2 class="h-4 w-4" aria-hidden="true" /> <span class="hidden sm:inline">Abandonner</span>
                </Button>

                <div class="ms-auto flex flex-wrap items-center justify-end gap-2">
                    <span v-if="sendBlocker && recipientCount + invalidRecipients.length > 0" class="text-xs text-muted-foreground">{{ sendBlocker }}</span>
                    <Button type="button" variant="outline" size="sm" :disabled="form.processing" @click="saveDraft">
                        <Save class="h-4 w-4" aria-hidden="true" /> Brouillon
                    </Button>
                    <Button type="button" size="sm" :disabled="Boolean(sendBlocker) || form.processing" :title="sendBlocker || 'Envoyer (Ctrl+Entrée)'" @click="send">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" aria-hidden="true" />
                        <Send v-else class="h-4 w-4" aria-hidden="true" />
                        Envoyer
                    </Button>
                </div>
            </div>
        </template>
    </Dialog>

    <ConfirmModal
        v-model:open="confirmDiscard"
        title="Abandonner ce message ?"
        description="Ce que vous avez écrit sera perdu. Pour le garder, enregistrez-le en brouillon."
        confirm-label="Abandonner"
        cancel-label="Continuer à écrire"
        tone="danger"
        @confirm="confirmDiscard = false; close()"
    />
</template>
