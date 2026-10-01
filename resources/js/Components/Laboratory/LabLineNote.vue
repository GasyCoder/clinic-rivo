<script setup>
import { computed, nextTick, ref } from 'vue';
import Button from '@/Components/Shadcn/Button.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { ChevronDown, NotebookPen, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { NOTE_MAX_LENGTH } from '@/utilities/labWorkbench';

/**
 * ADR-218 / ADR-219 — la conclusion partielle d'une ligne (« Notes : » sur le
 * compte rendu), comme la section « Conclusion » de labo-vuejs : on l'ajoute,
 * on la modifie, on l'annule. Elle ne part qu'au geste « Valider » ou
 * « Supprimer » : la page l'enregistre aussitôt.
 */
const props = defineProps({
    uuid: { type: String, required: true },
    designation: { type: String, default: '' },
    note: { type: String, default: '' },
    author: { type: String, default: null },
    writable: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    error: { type: String, default: null },
    group: { type: Boolean, default: false },
});
const emit = defineEmits(['save']);

const saved = computed(() => String(props.note ?? '').trim());
const open = ref(saved.value !== '');
const editing = ref(false);
const draft = ref('');
const field = ref(null);

const start = async () => {
    draft.value = saved.value;
    editing.value = true;
    open.value = true;
    await nextTick();
    field.value?.$el?.focus?.() ?? field.value?.focus?.();
};
const cancel = () => { editing.value = false; draft.value = ''; };
const validate = () => { emit('save', draft.value.trim()); editing.value = false; };
const remove = () => { emit('save', ''); editing.value = false; };
const count = computed(() => (saved.value ? 1 : 0));
</script>

<template>
    <div v-if="writable || saved" class="mt-3 border-t border-border pt-3">
        <button
            type="button"
            class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground transition-colors hover:text-primary"
            :aria-expanded="open"
            @click="open = !open"
        >
            <NotebookPen class="h-3.5 w-3.5" />
            Conclusion{{ group ? ' du groupe' : '' }} ({{ count }})
            <ChevronDown :class="cn('h-3.5 w-3.5 transition-transform', open && 'rotate-180')" />
        </button>

        <div v-show="open" class="mt-2 space-y-2">
            <div v-if="saved && !editing" class="rounded-lg border border-border bg-muted/40 p-3 text-sm">
                <div class="mb-1 flex items-center justify-between gap-2 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                    <span>{{ author ? `Saisie par ${author}` : 'Conclusion partielle' }}</span>
                    <span v-if="writable" class="flex gap-1">
                        <Button type="button" size="xs" variant="ghost" :disabled="saving" :aria-label="`Modifier la conclusion de ${designation}`" @click="start"><Pencil class="h-3 w-3" /> Modifier</Button>
                        <Button type="button" size="xs" variant="ghost" class="text-destructive hover:text-destructive" :disabled="saving" :aria-label="`Supprimer la conclusion de ${designation}`" @click="remove"><Trash2 class="h-3 w-3" /> Supprimer</Button>
                    </span>
                </div>
                <p class="whitespace-pre-line leading-snug text-foreground">{{ saved }}</p>
            </div>

            <template v-if="writable">
                <Button v-if="!saved && !editing" type="button" size="xs" variant="secondary" :disabled="saving" @click="start">
                    <Plus class="h-3.5 w-3.5" /> Ajouter une conclusion
                </Button>
                <div v-if="editing" class="space-y-2">
                    <Textarea
                        ref="field"
                        v-model="draft"
                        rows="2"
                        :maxlength="NOTE_MAX_LENGTH"
                        :aria-label="`Conclusion partielle de ${designation}`"
                        placeholder="Ex. Microcytose isolée avec polynucléose neutrophile."
                        @keydown.esc.prevent="cancel"
                        @keydown.ctrl.enter.prevent="validate"
                        @keydown.meta.enter.prevent="validate"
                    />
                    <div class="flex items-center justify-end gap-2">
                        <span class="me-auto text-[11px] tabular-nums text-muted-foreground">{{ draft.length }} / {{ NOTE_MAX_LENGTH }}</span>
                        <Button type="button" size="xs" variant="ghost" @click="cancel">Annuler</Button>
                        <Button type="button" size="xs" :disabled="saving || draft.trim() === saved" @click="validate">Valider</Button>
                    </div>
                </div>
            </template>
            <p v-if="error" class="text-xs text-destructive">{{ error }}</p>
        </div>
    </div>
</template>
