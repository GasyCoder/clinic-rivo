<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Bold, CircleX, Italic, List, ListOrdered, Underline } from 'lucide-vue-next';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    placeholder: { type: String, default: '' },
    maxLength: { type: Number, default: 3000 },
    id: { type: String, default: undefined },
    /** Tailwind min-height for the writing area, e.g. 'min-h-36'. */
    minHeightClass: { type: String, default: 'min-h-44' },
});

const emit = defineEmits(['update:modelValue']);
const editor = ref(null);

const containsMarkup = (value) => /<(?:p|div|br|strong|b|em|i|u|mark|span|ul|ol|li)\b/i.test(value);
const decodePlainHtml = (value) => {
    const container = document.createElement('div');
    container.innerHTML = value;

    return container.textContent ?? '';
};
const plainLength = computed(() => {
    if (typeof document === 'undefined') return 0;
    const container = document.createElement('div');
    container.innerHTML = props.modelValue;

    return (container.textContent ?? '').trim().length;
});

const syncEditor = () => {
    if (!editor.value) return;

    const current = editor.value.innerHTML;
    if (current === props.modelValue) return;

    if (containsMarkup(props.modelValue)) {
        editor.value.innerHTML = props.modelValue;
    } else {
        editor.value.textContent = decodePlainHtml(props.modelValue);
    }
};

const updateValue = () => emit('update:modelValue', editor.value?.innerHTML ?? '');

const command = (name, value = null) => {
    if (props.disabled || !editor.value) return;
    editor.value.focus();
    document.execCommand(name, false, value);
    updateValue();
};

const highlight = () => {
    if (!document.execCommand('hiliteColor', false, '#fef08a')) {
        document.execCommand('backColor', false, '#fef08a');
    }
    updateValue();
};

const pastePlainText = (event) => {
    event.preventDefault();
    const text = event.clipboardData?.getData('text/plain') ?? '';
    document.execCommand('insertText', false, text);
    updateValue();
};

watch(() => props.modelValue, () => nextTick(syncEditor));
onMounted(syncEditor);
</script>

<template>
    <div :class="['overflow-hidden rounded border bg-card transition-shadow', disabled ? 'border-border bg-muted/35' : 'border-border focus-within:border-primary/60 focus-within:ring-2 focus-within:ring-ring/25']">
        <div class="flex flex-wrap items-center gap-1 border-b border-border bg-muted/35 px-2 py-1.5" role="toolbar" aria-label="Mise en forme de l’interrogatoire">
            <button type="button" class="editor-tool" title="Gras" aria-label="Gras" :disabled="disabled" @mousedown.prevent @click="command('bold')"><Bold class="h-4 w-4" aria-hidden="true" /></button>
            <button type="button" class="editor-tool" title="Italique" aria-label="Italique" :disabled="disabled" @mousedown.prevent @click="command('italic')"><Italic class="h-4 w-4" aria-hidden="true" /></button>
            <button type="button" class="editor-tool" title="Souligné" aria-label="Souligné" :disabled="disabled" @mousedown.prevent @click="command('underline')"><Underline class="h-4 w-4" aria-hidden="true" /></button>
            <button type="button" class="editor-tool" title="Surligner" aria-label="Surligner" :disabled="disabled" @mousedown.prevent @click="highlight"><span class="border-b-4 border-yellow-300 px-0.5 font-bold">A</span></button>
            <span class="mx-1 h-5 w-px bg-muted" aria-hidden="true" />
            <button type="button" class="editor-tool-wide" title="Liste à puces" :disabled="disabled" @mousedown.prevent @click="command('insertUnorderedList')"><List class="h-4 w-4" aria-hidden="true" /> Liste</button>
            <button type="button" class="editor-tool-wide" title="Liste numérotée" :disabled="disabled" @mousedown.prevent @click="command('insertOrderedList')"><ListOrdered class="h-4 w-4" aria-hidden="true" /> Liste</button>
            <span class="mx-1 h-5 w-px bg-muted" aria-hidden="true" />
            <button type="button" class="editor-tool-wide text-muted-foreground" title="Effacer la mise en forme" :disabled="disabled" @mousedown.prevent @click="command('removeFormat')"><CircleX class="h-4 w-4" aria-hidden="true" />Effacer le format</button>
        </div>

        <div
            ref="editor"
            :id="id"
            v-bind="$attrs"
            role="textbox"
            aria-multiline="true"
            :aria-disabled="disabled"
            :contenteditable="disabled ? 'false' : 'true'"
            :data-placeholder="placeholder"
            :class="['clinical-editor px-4 py-3 text-sm leading-6 text-foreground outline-none', minHeightClass]"
            @input="updateValue"
            @paste="pastePlainText"
        />

        <div class="flex justify-end border-t border-border px-3 py-1.5 text-[11px] tabular-nums text-muted-foreground">
            {{ plainLength.toLocaleString('fr-FR') }} / {{ maxLength.toLocaleString('fr-FR') }} caractères
        </div>
    </div>
</template>

<style scoped>
.editor-tool,
.editor-tool-wide {
    align-items: center;
    border-radius: 0.25rem;
    color: rgb(71 85 105);
    display: inline-flex;
    height: 2rem;
    justify-content: center;
    transition: background-color 150ms, color 150ms;
}

.editor-tool { width: 2rem; }
.editor-tool-wide { gap: 0.375rem; padding-inline: 0.625rem; font-size: 0.75rem; font-weight: 600; }
.editor-tool:hover:not(:disabled), .editor-tool-wide:hover:not(:disabled) { background: white; color: rgb(15 23 42); }
.editor-tool:disabled, .editor-tool-wide:disabled { cursor: not-allowed; opacity: 0.45; }

.clinical-editor:empty::before {
    color: rgb(148 163 184);
    content: attr(data-placeholder);
    pointer-events: none;
}

.clinical-editor :deep(p) { margin-block: 0.25rem; }
.clinical-editor :deep(ul) { list-style: disc; margin-block: 0.35rem; padding-inline-start: 1.5rem; }
.clinical-editor :deep(ol) { list-style: decimal; margin-block: 0.35rem; padding-inline-start: 1.5rem; }
.clinical-editor :deep(mark) { background: rgb(254 240 138); color: inherit; }

:global(.dark) .editor-tool,
:global(.dark) .editor-tool-wide { color: rgb(203 213 225); }
:global(.dark) .editor-tool:hover:not(:disabled),
:global(.dark) .editor-tool-wide:hover:not(:disabled) { background: rgb(30 41 59); color: white; }
</style>
