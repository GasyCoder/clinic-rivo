<script setup>
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { Editor, EditorContent } from '@tiptap/vue-3';
import { StarterKit } from '@tiptap/starter-kit';
import { Underline } from '@tiptap/extension-underline';
import { TextAlign } from '@tiptap/extension-text-align';
import { Highlight } from '@tiptap/extension-highlight';
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
 * ADR-195 — le corps d'un message, rédigé comme dans un traitement de texte.
 *
 * Les mises en forme proposées sont exactement celles que le serveur garde à
 * l'envoi (`EmailHtmlSanitizer::forSending`) : rien de ce qu'on voit ici ne
 * disparaît en partant. L'éditeur est créé une fois la page reprise par le
 * navigateur — jamais pendant le rendu serveur.
 *
 * `bare` : sans cadre, il occupe toute la hauteur de son conteneur (la fenêtre de
 * rédaction) ; `toolbarPosition="bottom"` pose la barre de mise en forme sous le
 * texte, au-dessus des boutons d'envoi, comme dans une messagerie ; `toolbar` la
 * replie. Le texte d'invite s'affiche tant que le message est vide.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Écrivez votre message…' },
    disabled: { type: Boolean, default: false },
    bare: { type: Boolean, default: false },
    toolbar: { type: Boolean, default: true },
    /** top | bottom */
    toolbarPosition: { type: String, default: 'top' },
});

const emit = defineEmits(['update:modelValue']);
const editor = shallowRef(null);
const empty = ref(!props.modelValue);

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
                class: props.bare
                    ? 'rivo-email-editor min-h-full px-4 py-3 text-sm leading-relaxed text-foreground focus:outline-none sm:px-5'
                    : 'rivo-email-editor min-h-[14rem] max-h-[50vh] overflow-y-auto px-4 py-3 text-sm leading-relaxed text-foreground focus:outline-none',
                'aria-label': 'Corps du message',
                'aria-multiline': 'true',
                role: 'textbox',
            },
        },
        onCreate: ({ editor: instance }) => { empty.value = instance.isEmpty; },
        onUpdate: ({ editor: instance }) => {
            empty.value = instance.isEmpty;
            emit('update:modelValue', instance.getHTML());
        },
    });
});

// Un modèle inséré, ou un brouillon rouvert, remplace le contenu.
watch(() => props.modelValue, (value) => {
    if (editor.value && value !== editor.value.getHTML()) {
        editor.value.commands.setContent(value || '', false);
        empty.value = editor.value.isEmpty;
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
const focusEnd = () => editor.value?.commands.focus('end');

defineExpose({ insertHtml, focus, focusEnd });

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
    <div
        :class="cn(
            bare
                ? 'flex h-full min-h-0 flex-col bg-background'
                : 'overflow-hidden rounded-lg border border-input bg-background focus-within:ring-2 focus-within:ring-ring/40',
            disabled && 'opacity-70',
        )"
    >
        <div
            v-if="toolbar && toolbarPosition === 'top'"
            role="toolbar"
            aria-label="Mise en forme du message"
            class="flex flex-nowrap items-center gap-0.5 overflow-x-auto border-b border-border bg-muted/40 px-1.5 py-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        >
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

        <div :class="cn('relative', bare && 'min-h-0 flex-1 cursor-text overflow-y-auto')" @click.self="bare && focusEnd()">
            <EditorContent v-if="editor" :editor="editor" :class="bare && 'h-full'" />
            <div v-else :class="cn('px-4 py-3 text-sm text-muted-foreground', bare ? 'sm:px-5' : 'min-h-[14rem]')">{{ placeholder }}</div>
            <p
                v-if="editor && empty"
                :class="cn('pointer-events-none absolute start-0 top-0 select-none px-4 py-3 text-sm text-muted-foreground', bare && 'sm:px-5')"
                aria-hidden="true"
            >
                {{ placeholder }}
            </p>
        </div>

        <slot name="before-toolbar" />

        <div
            v-if="toolbar && toolbarPosition === 'bottom'"
            role="toolbar"
            aria-label="Mise en forme du message"
            class="mx-3 mb-1 flex shrink-0 flex-nowrap items-center gap-0.5 overflow-x-auto rounded-lg border border-border bg-muted/50 px-1 py-0.5 shadow-sm [scrollbar-width:none] sm:mx-4 [&::-webkit-scrollbar]:hidden"
        >
            <template v-for="tool in tools" :key="tool.key">
                <span v-if="tool.separator" class="mx-1 h-4 w-px shrink-0 bg-border" aria-hidden="true" />
                <button
                    v-else
                    type="button"
                    :title="tool.label"
                    :aria-label="tool.label"
                    :aria-pressed="tool.active()"
                    :disabled="disabled || !editor"
                    :class="cn('inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-background hover:text-foreground disabled:opacity-40', tool.active() && 'bg-background text-foreground shadow-sm')"
                    @mousedown.prevent
                    @click="run(tool.action)"
                >
                    <component :is="tool.icon" class="h-3.5 w-3.5" aria-hidden="true" />
                </button>
            </template>
        </div>
    </div>
</template>

<style>
.rivo-email-editor p { margin: 0 0 0.5rem; }
.rivo-email-editor > :last-child { margin-bottom: 0; }
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
