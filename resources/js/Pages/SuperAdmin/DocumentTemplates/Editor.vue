<script setup>
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { Editor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import TextAlign from '@tiptap/extension-text-align';
import TextStyle from '@tiptap/extension-text-style';
import Color from '@tiptap/extension-color';
import FontFamily from '@tiptap/extension-font-family';
import Highlight from '@tiptap/extension-highlight';
import ImageExtension from '@tiptap/extension-image';
import Table from '@tiptap/extension-table';
import TableRow from '@tiptap/extension-table-row';
import TableHeader from '@tiptap/extension-table-header';
import TableCell from '@tiptap/extension-table-cell';
import { FontSize } from '@/tiptap/FontSize';
import { BlockStyle, parseStyle, stringifyStyle } from '@/tiptap/BlockStyle';
import { VariableMark } from '@/tiptap/VariableMark';

defineOptions({ layout: AppLayout });

const props = defineProps({
    site: { type: Object, required: true },
    template: { type: Object, default: null },
    dataContexts: { type: Array, default: () => [] },
    variablesByContext: { type: Object, default: () => ({}) },
});

const isEditing = computed(() => props.template !== null);
const isArchivedTemplate = computed(() => props.template?.archived ?? false);

const form = useForm({
    document_type: props.template?.document_type ?? '',
    data_context: props.template?.data_context ?? (props.dataContexts[0]?.value ?? ''),
    name: props.template?.name ?? '',
    description: props.template?.description ?? '',
    active: props.template?.active ?? true,
});

const PAGE_TYPES = [
    { value: 'FORM', label: 'Formulaire' },
    { value: 'FIXED', label: 'Contenu fixe' },
    { value: 'MIXED', label: 'Mixte' },
];
const PAGE_BREAK_HTML = '<div data-page-break class="canevas-page-break"></div>';
const newPageId = () => (crypto.randomUUID ? crypto.randomUUID() : `page-${Date.now()}-${Math.random()}`);

/** @typedef {{ id: string, type: string, content: string }} CanevasPage */

/** @type {import('vue').Ref<CanevasPage[]>} */
const pages = ref(
    Array.isArray(props.template?.content?.pages) && props.template.content.pages.length
        ? props.template.content.pages.map((page) => ({
            id: page.id ?? newPageId(),
            type: page.type ?? 'MIXED',
            content: page.content ?? '<p></p>',
        }))
        : [{ id: newPageId(), type: 'MIXED', content: '<p></p>' }],
);
const activePageId = ref(pages.value[0].id);
const activePageIndex = computed(() => pages.value.findIndex((page) => page.id === activePageId.value));
const activePage = computed(() => pages.value[activePageIndex.value]);

const editor = shallowRef(null);
// Tracks any unsaved change (page content, page list, or the top form) so
// leaving the editor without saving can be caught — losing a page's content
// this way would be a critical defect for a document meant to be legally
// stable.
const isDirty = ref(false);

const mountPage = (page) => {
    editor.value?.destroy();
    editor.value = new Editor({
        content: page.content,
        editable: !isArchivedTemplate.value,
        extensions: [
            StarterKit,
            Underline,
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            TextStyle,
            Color,
            FontFamily,
            FontSize,
            Highlight.configure({ multicolor: true }),
            ImageExtension.configure({ inline: false, allowBase64: true }),
            Table.configure({ resizable: true }),
            TableRow,
            TableHeader,
            TableCell,
            BlockStyle,
            VariableMark,
        ],
        onUpdate: () => { isDirty.value = true; },
    });
};

const handleBeforeUnload = (event) => {
    if (!isDirty.value) return;
    event.preventDefault();
    event.returnValue = '';
};

const commitActivePage = () => {
    const page = activePage.value;
    if (page && editor.value) {
        page.content = editor.value.getHTML();
    }
};

const selectPage = (id) => {
    if (id === activePageId.value) return;
    commitActivePage();
    activePageId.value = id;
    mountPage(pages.value.find((page) => page.id === id));
};

onMounted(() => {
    mountPage(activePage.value);
    window.addEventListener('beforeunload', handleBeforeUnload);
});
onBeforeUnmount(() => {
    editor.value?.destroy();
    window.removeEventListener('beforeunload', handleBeforeUnload);
});

const addPage = () => {
    commitActivePage();
    const page = { id: newPageId(), type: 'MIXED', content: '<p></p>' };
    pages.value.push(page);
    selectPage(page.id);
    isDirty.value = true;
};
const duplicatePage = (id) => {
    commitActivePage();
    const index = pages.value.findIndex((page) => page.id === id);
    const source = pages.value[index];
    pages.value.splice(index + 1, 0, { id: newPageId(), type: source.type, content: source.content });
    isDirty.value = true;
};
const deletePage = (id) => {
    if (pages.value.length <= 1) return;
    if (!confirm('Supprimer définitivement cette page du modèle ?')) return;
    const wasActive = id === activePageId.value;
    pages.value = pages.value.filter((page) => page.id !== id);
    if (wasActive) selectPage(pages.value[0].id);
    isDirty.value = true;
};
const movePage = (id, direction) => {
    const index = pages.value.findIndex((page) => page.id === id);
    const target = index + direction;
    if (target < 0 || target >= pages.value.length) return;
    const [item] = pages.value.splice(index, 1);
    pages.value.splice(target, 0, item);
    isDirty.value = true;
};

const groupedVariables = computed(() => props.variablesByContext[form.data_context] ?? {});
const knownVariableCodes = computed(() => new Set(
    Object.values(groupedVariables.value).flatMap((variables) => Object.keys(variables)),
));
const isActive = (name, attrs) => editor.value?.isActive(name, attrs) ?? false;

const insertVariable = (code) => {
    // Marked so it reads visually distinct from hand-typed text — inserting
    // via this panel is the only supported way to add a variable, precisely
    // so a typo can never silently fail to be replaced at generation time.
    editor.value?.chain().focus().insertContent({
        type: 'text',
        marks: [{ type: 'variableToken' }],
        text: `{{${code}}}`,
    }).run();
};
const insertTable = () => {
    editor.value?.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();
};
const insertSignatureBlock = () => {
    editor.value?.chain().focus().insertContent(
        '<table><tbody><tr>'
        + '<td style="border: none; text-align: center; padding-top: 3rem;">Signature de l’employeur</td>'
        + '<td style="border: none; text-align: center; padding-top: 3rem;">Signature du salarié</td>'
        + '</tr></tbody></table>',
    ).run();
};
const insertImage = () => {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.onchange = () => {
        const file = input.files?.[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = () => editor.value?.chain().focus().setImage({ src: reader.result }).run();
        reader.readAsDataURL(file);
    };
    input.click();
};
const transformCase = (mode) => {
    if (!editor.value) return;
    const { from, to, empty } = editor.value.state.selection;
    if (empty) return;
    const text = editor.value.state.doc.textBetween(from, to, ' ');
    editor.value.chain().focus().insertContentAt(
        { from, to },
        mode === 'upper' ? text.toUpperCase() : text.toLowerCase(),
    ).run();
};

const blockTypes = ['paragraph', 'heading'];
const currentBlockStyle = () => {
    for (const type of blockTypes) {
        if (editor.value?.isActive(type)) {
            return parseStyle(editor.value.getAttributes(type).style);
        }
    }

    return {};
};
const applyBlockStyle = (patch) => {
    if (!editor.value) return;
    const style = stringifyStyle({ ...currentBlockStyle(), ...patch });
    blockTypes.forEach((type) => {
        if (editor.value.isActive(type)) editor.value.chain().focus().updateAttributes(type, { style }).run();
    });
};
const setLineHeight = (value) => applyBlockStyle({ 'line-height': value || null });
const setSpacingBefore = (value) => applyBlockStyle({ 'margin-top': value || null });
const setSpacingAfter = (value) => applyBlockStyle({ 'margin-bottom': value || null });
const indentLevel = () => {
    const marginLeft = currentBlockStyle()['margin-left'];

    return marginLeft ? Math.round(parseFloat(marginLeft) / 24) : 0;
};
const changeIndent = (delta) => {
    const level = Math.max(0, Math.min(8, indentLevel() + delta));
    applyBlockStyle({ 'margin-left': level ? `${level * 24}px` : null });
};

const fontFamilies = ['Arial', 'Georgia', 'Times New Roman', 'Courier New', 'Liberation Serif'];
const fontSizes = ['10px', '12px', '14px', '16px', '18px', '24px', '32px'];
const lineHeights = ['1', '1.15', '1.5', '2'];
const spacings = [
    { label: 'Aucun', value: '' },
    { label: 'Petit', value: '4px' },
    { label: 'Moyen', value: '8px' },
    { label: 'Grand', value: '16px' },
];

const showPreview = ref(false);
const buildContentHtml = () => {
    commitActivePage();

    return pages.value.map((page) => page.content).join(PAGE_BREAK_HTML);
};
const previewHtml = computed(() => (showPreview.value ? buildContentHtml() : ''));

const showHistory = ref(false);
const historyVersions = ref([]);
const historyLoading = ref(false);
const historyError = ref('');
const revertReason = ref('');
const revertTarget = ref(null);
const reverting = ref(false);

const openHistory = async () => {
    if (!isEditing.value) return;
    showHistory.value = true;
    historyLoading.value = true;
    historyError.value = '';
    try {
        const response = await fetch(`/super-admin/workspaces/document-templates/${props.site.code}/${props.template.uuid}/history`, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error('L’historique n’a pas pu être chargé.');
        const data = await response.json();
        historyVersions.value = data.versions ?? [];
    } catch (error) {
        historyError.value = error.message;
    } finally {
        historyLoading.value = false;
    }
};
const requestRevert = (version) => { revertTarget.value = version; revertReason.value = ''; };
const cancelRevert = () => { revertTarget.value = null; };
const confirmRevert = () => {
    reverting.value = true;
    router.post(
        `/super-admin/workspaces/document-templates/${props.site.code}/${revertTarget.value.uuid}/revert`,
        { reason: revertReason.value },
        { onFinish: () => { reverting.value = false; } },
    );
};

// Kept as a helper so the template never spells out a literal `{{ }}` pair
// inside a mustache expression (the SFC tokenizer reads that as closing the
// interpolation early).
const braces = (code) => `{{${code}}}`;

watch(() => [form.document_type, form.data_context, form.name, form.description, form.active], () => {
    isDirty.value = true;
});

const backUrl = `/super-admin/workspaces/document-templates?site=${props.site.code}`;
const leaveEditor = () => {
    if (isDirty.value && !confirm('Des modifications ne sont pas enregistrées. Quitter sans enregistrer ?')) return;
    router.visit(backUrl);
};

const submit = () => {
    if (!editor.value) return;

    const contentHtml = buildContentHtml();
    form.transform((data) => ({
        ...data,
        content: { pages: pages.value.map(({ id, type, content }) => ({ id, type, content })) },
        content_html: contentHtml,
        ...(isEditing.value ? {} : { site_code: props.site.code }),
    }));

    const options = {
        preserveScroll: true,
        onSuccess: () => { isDirty.value = false; },
    };

    if (isEditing.value) {
        form.put(`/super-admin/workspaces/document-templates/${props.site.code}/${props.template.uuid}`, options);
    } else {
        form.post('/super-admin/workspaces/document-templates', options);
    }
};
</script>

<template>
    <Head :title="isEditing ? `Modifier · ${template.name}` : 'Nouveau canevas'" />

    <div class="w-full space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <button type="button" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-primary-600" @click="leaveEditor"><Icon name="arrow-left" />Canevas de documents</button>
                <h1 class="mt-1 font-heading text-xl font-bold text-slate-700 dark:text-white">{{ isEditing ? `Modifier « ${template.name} »` : 'Nouveau canevas' }}</h1>
                <p class="mt-1 text-xs text-slate-500">Site destinataire : <strong>{{ site.name }}</strong><span v-if="isEditing && template.generated_documents_count"> · {{ template.generated_documents_count }} document(s) déjà généré(s) — toute modification crée une nouvelle version, sans affecter ceux-là.</span></p>
            </div>
            <div class="flex items-center gap-2">
                <span v-if="isDirty" class="text-xs font-bold text-amber-600">● Modifications non enregistrées</span>
                <Button v-if="isEditing" size="rg" variant="white-outline" type="button" @click="openHistory"><Icon name="history" /><span class="ms-2">Historique</span></Button>
                <Button size="rg" variant="white-outline" type="button" @click="showPreview = true"><Icon name="eye" /><span class="ms-2">Aperçu de la structure</span></Button>
                <Button size="rg" variant="white-outline" type="button" @click="leaveEditor">Annuler</Button>
                <Button v-if="!isArchivedTemplate" size="rg" :disabled="form.processing" @click="submit">
                    <Icon class="text-lg" name="save" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}</span>
                </Button>
            </div>
        </header>

        <p v-if="isArchivedTemplate" class="rounded border border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-300">Ce canevas est archivé et affiché en lecture seule. Restaurez-le depuis la liste pour le modifier.</p>

        <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <label class="mb-1.5 block text-xs font-bold uppercase text-slate-400">Nom du canevas <span class="text-red-500">*</span></label>
                    <input v-model="form.name" type="text" required :disabled="isArchivedTemplate" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase text-slate-400">Type de document <span class="text-red-500">*</span></label>
                    <input v-model="form.document_type" type="text" required list="document-type-suggestions" placeholder="CONTRAT, ATTESTATION…" :disabled="isArchivedTemplate" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm uppercase dark:border-gray-800 dark:bg-gray-950">
                    <datalist id="document-type-suggestions">
                        <option value="CONTRAT" /><option value="CONGE" /><option value="ATTESTATION" /><option value="CERTIFICAT" /><option value="LETTRE" /><option value="DECISION" /><option value="AUTRE" />
                    </datalist>
                    <p v-if="form.errors.document_type" class="mt-1 text-xs text-red-600">{{ form.errors.document_type }}</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase text-slate-400">Contexte de données <span class="text-red-500">*</span></label>
                    <select v-model="form.data_context" required :disabled="isArchivedTemplate" class="h-10 w-full rounded border border-gray-200 bg-white px-2 text-sm dark:border-gray-800 dark:bg-gray-950">
                        <option v-for="context in dataContexts" :key="context.value" :value="context.value">{{ context.label }}</option>
                    </select>
                    <p v-if="form.errors.data_context" class="mt-1 text-xs text-red-600">{{ form.errors.data_context }}</p>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <label class="mb-1.5 block text-xs font-bold uppercase text-slate-400">Description <span class="text-slate-300">(facultatif)</span></label>
                    <input v-model="form.description" type="text" :disabled="isArchivedTemplate" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                </div>
                <label class="inline-flex items-center gap-2 self-end text-sm text-slate-700 dark:text-white">
                    <input v-model="form.active" type="checkbox" :disabled="isArchivedTemplate" class="h-4 w-4 rounded border-gray-300">
                    Actif (proposé au RH)
                </label>
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-[220px_minmax(0,1fr)_260px]">
            <aside class="space-y-2">
                <h2 class="px-1 text-xs font-bold uppercase tracking-wide text-slate-400">Pages ({{ pages.length }})</h2>
                <div
                    v-for="(page, index) in pages" :key="page.id"
                    :class="['rounded-lg border p-2.5', page.id === activePageId ? 'border-primary-400 bg-primary-50/60 dark:bg-primary-950/20' : 'border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950']"
                >
                    <button type="button" class="block w-full text-start" @click="selectPage(page.id)">
                        <p class="text-xs font-bold text-slate-700 dark:text-white">Page {{ index + 1 }}</p>
                        <select v-model="page.type" :disabled="isArchivedTemplate" class="mt-1 h-7 w-full rounded border border-gray-200 bg-white px-1.5 text-[11px] dark:border-gray-800 dark:bg-gray-950" @click.stop>
                            <option v-for="pageType in PAGE_TYPES" :key="pageType.value" :value="pageType.value">{{ pageType.label }}</option>
                        </select>
                    </button>
                    <div v-if="!isArchivedTemplate" class="mt-2 flex items-center justify-between gap-1">
                        <div class="flex gap-0.5">
                            <button type="button" class="flex h-6 w-6 items-center justify-center rounded text-slate-400 hover:text-primary-600" title="Monter" :disabled="index === 0" @click="movePage(page.id, -1)"><Icon name="chevron-up" /></button>
                            <button type="button" class="flex h-6 w-6 items-center justify-center rounded text-slate-400 hover:text-primary-600" title="Descendre" :disabled="index === pages.length - 1" @click="movePage(page.id, 1)"><Icon name="chevron-down" /></button>
                        </div>
                        <div class="flex gap-0.5">
                            <button type="button" class="flex h-6 w-6 items-center justify-center rounded text-slate-400 hover:text-primary-600" title="Dupliquer" @click="duplicatePage(page.id)"><Icon name="copy" /></button>
                            <button type="button" class="flex h-6 w-6 items-center justify-center rounded text-slate-400 hover:text-red-600" title="Supprimer" :disabled="pages.length <= 1" @click="deletePage(page.id)"><Icon name="trash" /></button>
                        </div>
                    </div>
                </div>
                <button v-if="!isArchivedTemplate" type="button" class="w-full rounded-lg border border-dashed border-gray-300 py-2 text-xs font-bold text-slate-500 hover:border-primary-400 hover:text-primary-600 dark:border-gray-800" @click="addPage">
                    <Icon name="plus" /> Ajouter une page
                </button>
            </aside>

            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                <div class="flex items-center justify-between border-b border-gray-200 px-3 py-1.5 text-[11px] font-bold text-slate-400 dark:border-gray-900">
                    <span>Page {{ activePageIndex + 1 }} / {{ pages.length }}</span>
                    <span>{{ PAGE_TYPES.find((pageType) => pageType.value === activePage?.type)?.label }}</span>
                </div>
                <div v-if="editor" class="flex flex-wrap items-center gap-1 border-b border-gray-200 bg-gray-50/70 p-2 dark:border-gray-900 dark:bg-gray-1000/40">
                    <select :disabled="isArchivedTemplate" class="toolbar-select" @change="$event.target.value ? editor.chain().focus().setFontFamily($event.target.value).run() : editor.chain().focus().unsetFontFamily().run()">
                        <option value="">Police</option>
                        <option v-for="font in fontFamilies" :key="font" :value="font">{{ font }}</option>
                    </select>
                    <select :disabled="isArchivedTemplate" class="toolbar-select" @change="$event.target.value ? editor.chain().focus().setFontSize($event.target.value).run() : editor.chain().focus().unsetFontSize().run()">
                        <option value="">Taille</option>
                        <option v-for="size in fontSizes" :key="size" :value="size">{{ size }}</option>
                    </select>
                    <input type="color" title="Couleur du texte" :disabled="isArchivedTemplate" class="toolbar-color" @input="editor.chain().focus().setColor($event.target.value).run()">
                    <input type="color" title="Surlignage" :disabled="isArchivedTemplate" class="toolbar-color" @input="editor.chain().focus().toggleHighlight({ color: $event.target.value }).run()">
                    <span class="toolbar-sep" />
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn', isActive('heading', { level: 1 }) && 'toolbar-btn-active']" title="Titre 1" @click="editor.chain().focus().toggleHeading({ level: 1 }).run()">H1</button>
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn', isActive('heading', { level: 2 }) && 'toolbar-btn-active']" title="Titre 2" @click="editor.chain().focus().toggleHeading({ level: 2 }).run()">H2</button>
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn', isActive('heading', { level: 3 }) && 'toolbar-btn-active']" title="Sous-titre" @click="editor.chain().focus().toggleHeading({ level: 3 }).run()">H3</button>
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn', isActive('heading', { level: 4 }) && 'toolbar-btn-active']" title="Sous-titre 2" @click="editor.chain().focus().toggleHeading({ level: 4 }).run()">H4</button>
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn', isActive('paragraph') && 'toolbar-btn-active']" title="Paragraphe" @click="editor.chain().focus().setParagraph().run()">¶</button>
                    <span class="toolbar-sep" />
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn font-bold', isActive('bold') && 'toolbar-btn-active']" title="Gras" @click="editor.chain().focus().toggleBold().run()">B</button>
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn italic', isActive('italic') && 'toolbar-btn-active']" title="Italique" @click="editor.chain().focus().toggleItalic().run()">I</button>
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn underline', isActive('underline') && 'toolbar-btn-active']" title="Souligné" @click="editor.chain().focus().toggleUnderline().run()">U</button>
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn line-through', isActive('strike') && 'toolbar-btn-active']" title="Barré" @click="editor.chain().focus().toggleStrike().run()">S</button>
                    <button type="button" :disabled="isArchivedTemplate" class="toolbar-btn text-[10px]" title="MAJUSCULES" @click="transformCase('upper')">AB</button>
                    <button type="button" :disabled="isArchivedTemplate" class="toolbar-btn text-[10px]" title="minuscules" @click="transformCase('lower')">ab</button>
                    <span class="toolbar-sep" />
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn', isActive({ textAlign: 'left' }) && 'toolbar-btn-active']" title="Aligner à gauche" @click="editor.chain().focus().setTextAlign('left').run()">⟸</button>
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn', isActive({ textAlign: 'center' }) && 'toolbar-btn-active']" title="Centrer" @click="editor.chain().focus().setTextAlign('center').run()">⟺</button>
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn', isActive({ textAlign: 'right' }) && 'toolbar-btn-active']" title="Aligner à droite" @click="editor.chain().focus().setTextAlign('right').run()">⟹</button>
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn', isActive({ textAlign: 'justify' }) && 'toolbar-btn-active']" title="Justifier" @click="editor.chain().focus().setTextAlign('justify').run()">≡</button>
                    <span class="toolbar-sep" />
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn', isActive('bulletList') && 'toolbar-btn-active']" title="Liste à puces" @click="editor.chain().focus().toggleBulletList().run()">• Liste</button>
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn', isActive('orderedList') && 'toolbar-btn-active']" title="Liste numérotée" @click="editor.chain().focus().toggleOrderedList().run()">1. Liste</button>
                    <button type="button" :disabled="isArchivedTemplate" class="toolbar-btn" title="Diminuer le retrait" @click="changeIndent(-1)">⇤</button>
                    <button type="button" :disabled="isArchivedTemplate" class="toolbar-btn" title="Augmenter le retrait" @click="changeIndent(1)">⇥</button>
                    <span class="toolbar-sep" />
                    <select :disabled="isArchivedTemplate" class="toolbar-select" title="Interligne" @change="setLineHeight($event.target.value)">
                        <option value="">Interligne</option>
                        <option v-for="value in lineHeights" :key="value" :value="value">{{ value }}</option>
                    </select>
                    <select :disabled="isArchivedTemplate" class="toolbar-select" title="Espacement avant" @change="setSpacingBefore($event.target.value)">
                        <option value="" disabled selected>Avant ¶</option>
                        <option v-for="spacing in spacings" :key="spacing.value" :value="spacing.value">{{ spacing.label }}</option>
                    </select>
                    <select :disabled="isArchivedTemplate" class="toolbar-select" title="Espacement après" @change="setSpacingAfter($event.target.value)">
                        <option value="" disabled selected>Après ¶</option>
                        <option v-for="spacing in spacings" :key="spacing.value" :value="spacing.value">{{ spacing.label }}</option>
                    </select>
                    <span class="toolbar-sep" />
                    <button type="button" :disabled="isArchivedTemplate" class="toolbar-btn" title="Insérer un tableau" @click="insertTable">Tableau</button>
                    <button type="button" :disabled="isArchivedTemplate" class="toolbar-btn" title="Fusionner les cellules" @click="editor.chain().focus().mergeCells().run()">Fusionner</button>
                    <button type="button" :disabled="isArchivedTemplate" class="toolbar-btn" title="Scinder la cellule" @click="editor.chain().focus().splitCell().run()">Scinder</button>
                    <button type="button" :disabled="isArchivedTemplate" class="toolbar-btn" title="Ligne de séparation" @click="editor.chain().focus().setHorizontalRule().run()">―</button>
                    <button type="button" :disabled="isArchivedTemplate" class="toolbar-btn" title="Insérer une image" @click="insertImage"><Icon name="img" /></button>
                    <button type="button" :disabled="isArchivedTemplate" class="toolbar-btn" title="Bloc signature" @click="insertSignatureBlock">Signatures</button>
                    <span class="toolbar-sep" />
                    <button type="button" :disabled="isArchivedTemplate" class="toolbar-btn" title="Annuler" @click="editor.chain().focus().undo().run()"><Icon name="undo" /></button>
                    <button type="button" :disabled="isArchivedTemplate" class="toolbar-btn" title="Rétablir" @click="editor.chain().focus().redo().run()"><Icon name="redo" /></button>
                </div>
                <EditorContent :editor="editor" class="canevas-editor-content" />
            </section>

            <aside class="space-y-3 xl:sticky xl:top-4">
                <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950">
                    <h2 class="text-xs font-bold uppercase tracking-wide text-slate-400">Variables disponibles</h2>
                    <p class="mt-1 text-[11px] leading-4 text-slate-400">Cliquez pour insérer dans la page active. Une variable absente de cette liste (ex. montant, motif libre) reste utilisable — elle sera demandée au RH à la génération.</p>
                    <div v-for="(variables, group) in groupedVariables" :key="group" class="mt-3">
                        <h3 class="text-[10px] font-bold uppercase text-slate-400">{{ group }}</h3>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            <button v-for="(label, code) in variables" :key="code" type="button" :disabled="isArchivedTemplate" class="rounded border border-gray-200 bg-gray-50 px-2 py-1 text-[11px] font-bold text-slate-600 hover:border-primary-300 hover:text-primary-600 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-300" :title="label" @click="insertVariable(code)">+ {{ label }}</button>
                        </div>
                    </div>
                </section>
                <section v-if="isEditing && template.variables_used?.length" class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950">
                    <h2 class="text-xs font-bold uppercase tracking-wide text-slate-400">Variables détectées (dernier enregistrement)</h2>
                    <p class="mt-1 text-[11px] leading-4 text-slate-400">En vert : remplies automatiquement. En orange : demandées au RH à chaque génération.</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <code
                            v-for="code in template.variables_used" :key="code"
                            :class="[
                                'rounded px-1.5 py-0.5 text-[10px] font-bold',
                                knownVariableCodes.has(code)
                                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300'
                                    : 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300',
                            ]"
                        >{{ braces(code) }}</code>
                    </div>
                </section>
            </aside>
        </div>

        <div v-if="showPreview" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @click.self="showPreview = false">
            <div class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-lg bg-white shadow-xl dark:bg-gray-950">
                <header class="flex items-center justify-between border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                    <div>
                        <h2 class="text-sm font-bold text-slate-700 dark:text-white">Aperçu de la structure ({{ pages.length }} page(s))</h2>
                        <p class="mt-0.5 text-[11px] text-slate-400">Les variables ({{ braces('nom') }}, {{ braces('salaire') }}…) restent affichées telles quelles — pour un aperçu avec les vraies informations d’une personne, utilisez « Générer un document » côté Administration/RH.</p>
                    </div>
                    <button type="button" class="text-slate-400 hover:text-slate-700" @click="showPreview = false"><Icon class="text-xl" name="cross" /></button>
                </header>
                <div class="overflow-y-auto p-6">
                    <div class="canevas-document" v-html="previewHtml" />
                </div>
            </div>
        </div>

        <div v-if="showHistory" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @click.self="showHistory = false">
            <div class="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-lg bg-white shadow-xl dark:bg-gray-950">
                <header class="flex items-center justify-between border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                    <div>
                        <h2 class="text-sm font-bold text-slate-700 dark:text-white">Historique des versions</h2>
                        <p class="mt-0.5 text-xs text-slate-500">Chaque enregistrement qui remplace un canevas déjà utilisé crée une nouvelle version — les documents déjà générés continuent de pointer vers celle qui les a produits.</p>
                    </div>
                    <button type="button" class="text-slate-400 hover:text-slate-700" @click="showHistory = false"><Icon class="text-xl" name="cross" /></button>
                </header>
                <div class="overflow-y-auto p-5">
                    <div v-if="historyLoading" class="flex items-center gap-2 py-8 text-sm text-slate-400"><Icon class="animate-spin" name="loader" />Chargement…</div>
                    <p v-else-if="historyError" class="py-8 text-center text-sm text-red-600">{{ historyError }}</p>
                    <ul v-else class="space-y-2">
                        <li v-for="version in historyVersions" :key="version.uuid" class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <span :class="['rounded px-2 py-0.5 text-[10px] font-bold uppercase', !version.archived ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-gray-100 text-slate-500 dark:bg-gray-900']">{{ !version.archived ? 'Version actuelle' : 'Archivée' }}</span>
                                    <p class="mt-1 text-sm font-bold text-slate-700 dark:text-white">{{ version.name }}</p>
                                    <p class="mt-0.5 text-xs text-slate-400">{{ version.creator || 'Auteur inconnu' }} · {{ version.created_at ? new Date(version.created_at).toLocaleString('fr-FR') : '' }}</p>
                                    <p v-if="version.archive_reason" class="mt-1 text-xs text-slate-500">{{ version.archive_reason }}</p>
                                    <p v-if="version.generated_documents_count" class="mt-1 text-xs text-slate-400">{{ version.generated_documents_count }} document(s) généré(s) depuis cette version</p>
                                </div>
                                <Button v-if="version.archived" size="sm" variant="white-outline" type="button" @click="requestRevert(version)">Revenir à cette version</Button>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div v-if="revertTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4" @click.self="cancelRevert">
            <div class="w-full max-w-md rounded-lg bg-white shadow-xl dark:bg-gray-950">
                <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                    <div><h2 class="text-lg font-bold text-slate-700 dark:text-white">Revenir à cette version</h2><p class="mt-1 text-xs text-slate-500">« {{ revertTarget.name }} » du {{ new Date(revertTarget.created_at).toLocaleString('fr-FR') }} redevient la version active. La version actuelle est archivée, jamais supprimée.</p></div>
                    <button type="button" class="text-slate-400 hover:text-slate-700" @click="cancelRevert"><Icon class="text-xl" name="cross" /></button>
                </header>
                <form class="space-y-4 p-5" @submit.prevent="confirmRevert">
                    <div><label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif <span class="text-red-500">*</span></label><textarea v-model="revertReason" required rows="3" class="block w-full rounded border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950" /></div>
                    <div class="flex justify-end gap-2"><Button type="button" variant="white-outline" @click="cancelRevert">Annuler</Button><Button type="submit" :disabled="reverting">{{ reverting ? 'Retour en cours…' : 'Confirmer' }}</Button></div>
                </form>
            </div>
        </div>
    </div>
</template>

<style scoped>
.toolbar-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2rem;
    height: 2rem;
    padding: 0 0.5rem;
    border-radius: 0.375rem;
    font-size: 0.75rem;
    font-weight: 700;
    color: rgb(100 116 139);
}
.toolbar-btn:hover:not(:disabled) {
    background-color: rgb(243 244 246);
    color: rgb(30 41 59);
}
.toolbar-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.toolbar-btn-active {
    background-color: rgb(238 242 255);
    color: rgb(79 70 229);
}
.toolbar-select {
    height: 2rem;
    border-radius: 0.375rem;
    border: 1px solid rgb(229 231 235);
    background-color: white;
    padding: 0 0.375rem;
    font-size: 0.75rem;
}
.toolbar-color {
    height: 2rem;
    width: 2rem;
    border-radius: 0.375rem;
    border: 1px solid rgb(229 231 235);
    padding: 0.125rem;
    background-color: white;
}
.toolbar-sep {
    margin: 0 0.25rem;
    height: 1.25rem;
    width: 1px;
    background-color: rgb(229 231 235);
}
:deep(.canevas-editor-content) {
    min-height: 28rem;
    padding: 1.5rem;
}
:deep(.ProseMirror) {
    outline: none;
    min-height: 26rem;
}
:deep(.ProseMirror table),
.canevas-document :deep(table) {
    border-collapse: collapse;
    width: 100%;
    margin: 0.75rem 0;
}
:deep(.ProseMirror table td),
:deep(.ProseMirror table th),
.canevas-document :deep(td),
.canevas-document :deep(th) {
    border: 1px solid rgb(203 213 225);
    padding: 0.375rem 0.5rem;
}
:deep(.ProseMirror table th) {
    background-color: rgb(248 250 252);
    font-weight: 700;
}
:deep(.ProseMirror img) {
    max-width: 100%;
}
/* Editor-only: this styling is intentionally scoped to this component and
   never reused in Print.vue / the RH preview, so a generated document never
   ships with a colored box around the substituted value. */
:deep(.canevas-variable-token) {
    border-radius: 0.25rem;
    background-color: rgb(238 242 255);
    padding: 0.0625rem 0.25rem;
    color: rgb(79 70 229);
    font-weight: 700;
}
:deep(.canevas-page-break),
.canevas-document :deep(.canevas-page-break) {
    margin: 1rem 0;
    padding: 0.375rem 0;
    border-top: 1px dashed rgb(203 213 225);
    border-bottom: 1px dashed rgb(203 213 225);
    text-align: center;
    font-size: 0.6875rem;
    font-weight: 700;
    text-transform: uppercase;
    color: rgb(148 163 184);
    user-select: none;
}
.canevas-document :deep(.canevas-page-break)::after {
    content: 'Saut de page';
}
</style>
