<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft, FileText, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import EmailEditor from '@/Components/Webmail/EmailEditor.vue';
import { WEBMAIL_BASE } from '@/utilities/webmail';

/**
 * ADR-195 — les modèles de message du compte : une réponse qui revient souvent
 * (« Bien reçu », « Résultats disponibles »…), insérée d'un clic en rédigeant.
 * Ils n'appartiennent qu'au compte ; leur corps est nettoyé par le serveur comme
 * celui d'un message.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    templates: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:open']);

const editing = ref(null); // null = liste ; {} = nouveau ; un modèle = modification
const form = useForm({ name: '', subject: '', body_html: '' });
const toDelete = ref(null);
const deleting = ref(false);

watch(() => props.open, (open) => { if (open) editing.value = null; });

const excerpt = (html) => {
    const text = String(html ?? '').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
    return text.length > 140 ? `${text.slice(0, 140)}…` : text;
};

const edit = (template = null) => {
    form.clearErrors();
    form.name = template?.name ?? '';
    form.subject = template?.subject ?? '';
    form.body_html = template?.body_html ?? '';
    editing.value = template ?? {};
};

const isNew = computed(() => editing.value && !editing.value.uuid);
const title = computed(() => (editing.value ? (isNew.value ? 'Nouveau modèle' : 'Modifier le modèle') : 'Modèles de message'));

const submit = () => {
    const options = { preserveScroll: true, preserveState: true, only: ['templates', 'flash', 'errors'], onSuccess: () => { editing.value = null; } };
    if (isNew.value) form.post(`${WEBMAIL_BASE}/modeles`, options);
    else form.put(`${WEBMAIL_BASE}/modeles/${editing.value.uuid}`, options);
};

const destroy = () => {
    deleting.value = true;
    form.delete(`${WEBMAIL_BASE}/modeles/${toDelete.value.uuid}`, {
        preserveScroll: true,
        preserveState: true,
        only: ['templates', 'flash', 'errors'],
        onSuccess: () => { toDelete.value = null; },
        onFinish: () => { deleting.value = false; },
    });
};
</script>

<template>
    <Dialog
        :open="open"
        :title="title"
        description="Réservés à votre compte. Insérez-les depuis « Modèles » en rédigeant un message."
        size="lg"
        :dismissible="!editing"
        @update:open="(value) => value || emit('update:open', false)"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><FileText class="h-5 w-5" aria-hidden="true" /></span>
        </template>

        <form v-if="editing" class="space-y-4" @submit.prevent="submit">
            <FormField label="Nom du modèle" :error="form.errors.name" required>
                <Input v-model="form.name" maxlength="80" placeholder="Ex. Résultats disponibles" />
            </FormField>
            <FormField label="Objet" hint="(facultatif)" :error="form.errors.subject">
                <Input v-model="form.subject" maxlength="255" placeholder="Repris si le message n’a pas encore d’objet" />
            </FormField>
            <div>
                <p class="mb-1.5 text-sm font-medium text-foreground">Texte</p>
                <EmailEditor v-model="form.body_html" />
                <p v-if="form.errors.body_html" class="mt-1 text-xs font-medium text-destructive">{{ form.errors.body_html }}</p>
            </div>
        </form>

        <template v-else>
            <ul v-if="templates.length" class="divide-y divide-border rounded-lg border border-border">
                <li v-for="template in templates" :key="template.uuid" class="flex items-start gap-3 p-3">
                    <FileText class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-foreground">{{ template.name }}</p>
                        <p v-if="template.subject" class="truncate text-xs text-muted-foreground">Objet : {{ template.subject }}</p>
                        <p class="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{{ excerpt(template.body_html) }}</p>
                    </div>
                    <Button type="button" variant="ghost" size="xs" icon :aria-label="`Modifier ${template.name}`" @click="edit(template)"><Pencil class="h-4 w-4" aria-hidden="true" /></Button>
                    <Button type="button" variant="ghost" size="xs" icon class="hover:text-destructive" :aria-label="`Supprimer ${template.name}`" @click="toDelete = template"><Trash2 class="h-4 w-4" aria-hidden="true" /></Button>
                </li>
            </ul>
            <div v-else class="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
                Aucun modèle pour l’instant. Créez-en un ici, ou enregistrez un message en cours de rédaction comme modèle.
            </div>
        </template>

        <template #footer>
            <template v-if="editing">
                <Button type="button" variant="outline" class="sm:me-auto" :disabled="form.processing" @click="editing = null"><ArrowLeft class="h-4 w-4" aria-hidden="true" /> Retour</Button>
                <Button type="button" :disabled="!form.name.trim() || form.processing" @click="submit">{{ isNew ? 'Créer le modèle' : 'Enregistrer' }}</Button>
            </template>
            <template v-else>
                <Button type="button" variant="outline" @click="emit('update:open', false)">Fermer</Button>
                <Button type="button" @click="edit()"><Plus class="h-4 w-4" aria-hidden="true" /> Nouveau modèle</Button>
            </template>
        </template>
    </Dialog>

    <ConfirmModal
        :open="Boolean(toDelete)"
        :title="`Supprimer le modèle « ${toDelete?.name ?? ''} » ?`"
        description="Les messages déjà envoyés ne changent pas."
        confirm-label="Supprimer le modèle"
        tone="danger"
        :processing="deleting"
        @update:open="(value) => value || (toDelete = null)"
        @confirm="destroy"
    />
</template>
