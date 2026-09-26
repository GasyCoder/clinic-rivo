<script setup>
import { onBeforeUnmount, onMounted, shallowRef, watch } from 'vue';
import { Editor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import TextAlign from '@tiptap/extension-text-align';
import Highlight from '@tiptap/extension-highlight';
import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    Bold,
    Highlighter,
    Italic,
    List,
    ListOrdered,
    Minus,
    Quote,
    Redo2,
    RemoveFormatting,
    Strikethrough,
    Underline as UnderlineIcon,
    Undo2,
} from 'lucide-vue-next';
import { cn } from '@/lib/cn';

/**
 * ADR-194 — le corps d'un message, rédigé comme dans un traitement de texte.
 *
 * Les mises en forme proposées sont exactement celles que le serveur garde à
 * l'envoi (`EmailHtmlSanitizer::forSending`) : rien de ce qu'on voit ici ne
 * disparaît en partant. L'éditeur est créé une fois la page reprise par le
 * navigateur — jamais pendant le rendu serveur.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Écrivez votre message…' },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);
const editor = shallowRef(null);

onMounted(() => {
    editor.value = new Editor({
        content: props.modelValue || '',
        editable: !props.disabled,
        extensions: [
            StarterKit.configure({ heading: { levels: [1, 2, 3] } }),
            Underline,
            Highlight,
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
        ],
        editorProps: {
            attributes: {
                class: 'rivo-email-editor min-h-[14rem] max-h-[50vh] overflow-y-auto px-4 py-3 text-sm leading-relaxed text-foreground focus:outline-none',
                'aria-label': 'Corps du message',
                'aria-multiline': 'true',
                role: 'textbox',
            },
        },
        onUpdate: ({ editor: instance }) => emit('update:modelValue', instance.getHTML()),
    });
});

// Un modèle inséré, ou un brouillon rouvert, remplace le contenu.
watch(() => props.modelValue, (value) => {
    if (editor.value && value !== editor.value.getHTML()) {
        editor.value.commands.setContent(value || '', false);
    }
});
watch(() => props.disabled, (disabled) => editor.value?.setEditable(!disabled));

onBeforeUnmount(() => editor.value?.destroy());

const is = (name, attributes) => editor.value?.isActive(name, attributes) ?? false;
const run = (callback) => {
    if (!editor.value || props.disabled) return;
    callback(editor.value.chain().focus()).run();
};

/** Insère un texte déjà en HTML (un modèle) là où se trouve le curseur. */
const insertHtml = (html) => run((chain) => chain.insertContent(html));
const focus = () => editor.value?.commands.focus('start');

defineExpose({ insertHtml, focus });

const tools = [
    { key: 'bold', label: 'Gras (Ctrl+B)', icon: Bold, action: (c) => c.toggleBold(), active: () => is('bold') },
    { key: 'italic', label: 'Italique (Ctrl+I)', icon: Italic, action: (c) => c.toggleItalic(), active: () => is('italic') },
    { key: 'underline', label: 'Souligné (Ctrl+U)', icon: UnderlineIcon, action: (c) => c.toggleUnderline(), active: () => is('underline') },
    { key: 'strike', label: 'Barré', icon: Strikethrough, action: (c) => c.toggleStrike(), active: () => is('strike') },
    { key: 'highlight', label: 'Surligner', icon: Highlighter, action: (c) => c.toggleHighlight(), active: () => is('highlight') },
    { separator: true, key: 's1' },
    { key: 'bullet', label: 'Liste à puces', icon: List, action: (c) => c.toggleBulletList(), active: () => is('bulletList') },
    { key: 'ordered', label: 'Liste numérotée', icon: ListOrdered, action: (c) => c.toggleOrderedList(), active: () => is('orderedList') },
    { key: 'quote', label: 'Citation', icon: Quote, action: (c) => c.toggleBlockquote(), active: () => is('blockquote') },
    { key: 'rule', label: 'Ligne de séparation', icon: Minus, action: (c) => c.setHorizontalRule(), active: () => false },
    { separator: true, key: 's2' },
    { key: 'left', label: 'Aligner à gauche', icon: AlignLeft, action: (c) => c.setTextAlign('left'), active: () => is({ textAlign: 'left' }) },
    { key: 'center', label: 'Centrer', icon: AlignCenter, action: (c) => c.setTextAlign('center'), active: () => is({ textAlign: 'center' }) },
    { key: 'right', label: 'Aligner à droite', icon: AlignRight, action: (c) => c.setTextAlign('right'), active: () => is({ textAlign: 'right' }) },
    { separator: true, key: 's3' },
    { key: 'clear', label: 'Effacer la mise en forme', icon: RemoveFormatting, action: (c) => c.unsetAllMarks().clearNodes(), active: () => false },
    { key: 'undo', label: 'Annuler (Ctrl+Z)', icon: Undo2, action: (c) => c.undo(), active: () => false },
    { key: 'redo', label: 'Rétablir (Ctrl+Y)', icon: Redo2, action: (c) => c.redo(), active: () => false },
];
</script>

<template>
    <div :class="cn('overflow-hidden rounded-lg border border-input bg-background focus-within:ring-2 focus-within:ring-ring/40', disabled && 'opacity-70')">
        <div role="toolbar" aria-label="Mise en forme du message" class="flex flex-wrap items-center gap-0.5 border-b border-border bg-muted/40 px-1.5 py-1">
            <template v-for="tool in tools" :key="tool.key">
                <span v-if="tool.separator" class="mx-1 h-5 w-px bg-border" aria-hidden="true" />
                <button
                    v-else
                    type="button"
                    :title="tool.label"
                    :aria-label="tool.label"
                    :aria-pressed="tool.active()"
                    :disabled="disabled || !editor"
                    :class="cn('inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground disabled:opacity-40', tool.active() && 'bg-accent text-foreground')"
                    @mousedown.prevent
                    @click="run(tool.action)"
                >
                    <component :is="tool.icon" class="h-4 w-4" aria-hidden="true" />
                </button>
            </template>
        </div>
        <EditorContent v-if="editor" :editor="editor" />
        <div v-else class="min-h-[14rem] px-4 py-3 text-sm text-muted-foreground">{{ placeholder }}</div>
    </div>
</template>

<style>
.rivo-email-editor p { margin: 0 0 0.5rem; }
.rivo-email-editor ul { list-style: disc; padding-left: 1.5rem; margin: 0 0 0.5rem; }
.rivo-email-editor ol { list-style: decimal; padding-left: 1.5rem; margin: 0 0 0.5rem; }
.rivo-email-editor blockquote { border-left: 3px solid hsl(var(--border)); padding-left: 0.75rem; color: hsl(var(--muted-foreground)); margin: 0 0 0.5rem; }
.rivo-email-editor h1 { font-size: 1.25rem; font-weight: 700; margin: 0 0 0.5rem; }
.rivo-email-editor h2 { font-size: 1.125rem; font-weight: 700; margin: 0 0 0.5rem; }
.rivo-email-editor h3 { font-size: 1rem; font-weight: 700; margin: 0 0 0.5rem; }
.rivo-email-editor hr { border: 0; border-top: 1px solid hsl(var(--border)); margin: 0.75rem 0; }
.rivo-email-editor mark { background: #fef08a; color: inherit; padding: 0 1px; }
.rivo-email-editor pre { background: hsl(var(--muted)); border-radius: 0.375rem; padding: 0.5rem 0.75rem; white-space: pre-wrap; }
</style>
