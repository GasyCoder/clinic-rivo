<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Check, Tag, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import { cn } from '@/lib/cn';
import { LABEL_COLORS, WEBMAIL_BASE, labelColor } from '@/utilities/webmail';

/**
 * ADR-195 — créer, renommer ou retirer un libellé. Il n'appartient qu'au compte ;
 * il est posé sur les messages comme un mot-clé du serveur de messagerie. Le
 * retirer ne supprime aucun message.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    label: { type: Object, default: null },
    colors: { type: Array, default: () => Object.keys(LABEL_COLORS) },
});
const emit = defineEmits(['update:open']);

const form = useForm({ name: '', color: 'blue' });
const confirmDelete = ref(false);
const deleting = ref(false);
const editing = computed(() => Boolean(props.label));

watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    form.name = props.label?.name ?? '';
    form.color = props.label?.color ?? 'blue';
}, { immediate: true });

const close = () => emit('update:open', false);

const submit = () => {
    const options = { preserveScroll: true, preserveState: true, only: ['labels', 'flash', 'errors'], onSuccess: close };
    if (editing.value) form.put(`${WEBMAIL_BASE}/libelles/${props.label.uuid}`, options);
    else form.post(`${WEBMAIL_BASE}/libelles`, options);
};

const destroy = () => {
    deleting.value = true;
    form.delete(`${WEBMAIL_BASE}/libelles/${props.label.uuid}`, {
        preserveScroll: true,
        preserveState: true,
        only: ['labels', 'flash', 'errors'],
        onSuccess: () => { confirmDelete.value = false; close(); },
        onFinish: () => { deleting.value = false; },
    });
};
</script>

<template>
    <Dialog
        :open="open"
        :title="editing ? 'Modifier le libellé' : 'Nouveau libellé'"
        description="Un libellé classe vos messages sans les déplacer. Il n’est visible que de vous."
        @update:open="(value) => value || close()"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Tag class="h-5 w-5" aria-hidden="true" /></span>
        </template>

        <form class="space-y-4" @submit.prevent="submit">
            <FormField label="Nom" :error="form.errors.name" required>
                <Input id="webmail-label-name" v-model="form.name" maxlength="40" placeholder="Ex. Laboratoire" autofocus />
            </FormField>
            <fieldset>
                <legend class="mb-2 text-sm font-medium text-foreground">Couleur</legend>
                <div class="flex flex-wrap gap-2">
                    <label
                        v-for="color in colors"
                        :key="color"
                        :class="cn('inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors', form.color === color ? 'border-foreground' : 'border-border hover:bg-accent')"
                    >
                        <input v-model="form.color" type="radio" name="webmail-label-color" :value="color" class="sr-only">
                        <span :class="cn('grid h-4 w-4 place-items-center rounded-full text-white', labelColor(color).dot)" aria-hidden="true">
                            <Check v-if="form.color === color" class="h-3 w-3" />
                        </span>
                        {{ labelColor(color).name }}
                    </label>
                </div>
                <p v-if="form.errors.color" class="mt-1 text-xs font-medium text-destructive">{{ form.errors.color }}</p>
            </fieldset>
        </form>

        <template #footer>
            <Button v-if="editing" type="button" variant="danger-outline" class="sm:me-auto" :disabled="form.processing" @click="confirmDelete = true">
                <Trash2 class="h-4 w-4" aria-hidden="true" /> Supprimer
            </Button>
            <Button type="button" variant="outline" :disabled="form.processing" @click="close">Annuler</Button>
            <Button type="button" :disabled="!form.name.trim() || form.processing" @click="submit">{{ editing ? 'Enregistrer' : 'Créer le libellé' }}</Button>
        </template>
    </Dialog>

    <ConfirmModal
        v-model:open="confirmDelete"
        :title="`Supprimer le libellé « ${label?.name ?? ''} » ?`"
        description="Les messages qui le portent restent où ils sont ; seul le libellé disparaît de la liste."
        confirm-label="Supprimer le libellé"
        tone="danger"
        :processing="deleting"
        @confirm="destroy"
    />
</template>
