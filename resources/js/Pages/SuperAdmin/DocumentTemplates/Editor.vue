<script setup>
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { ArrowLeft, ChevronDown, ChevronUp, Copy, Eye, History, Image, LoaderCircle, Plus, Redo2, RotateCcw, Save, Trash2, Upload, X } from 'lucide-vue-next';
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
import { convertDocxToPages, extractPdfPages } from '@/tiptap/documentImport';

defineOptions({ layout: AppLayout });

const props = defineProps({
    targetSite: { type: Object, required: true },
    template: { type: Object, default: null },
    dataContexts: { type: Array, default: () => [] },
});

const isEditing = computed(() => props.template !== null);

// ADR-198 — ce que le RH verra : les champs de la page 1 et où le canevas lui est proposé.
const selectedContext = computed(() => props.dataContexts.find((context) => context.value === form.data_context) ?? null);
const normalizeType = (value) => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase();
/** Un canevas de congé ou de contrat mal réglé : il ne reprendrait pas les dates, et ne serait pas proposé au bon endroit. */
const contextMismatch = computed(() => {
    const type = normalizeType(form.document_type);
    if (type.includes('CONGE') && form.data_context !== 'EMPLOYEE_AND_LEAVE') {
        return 'Un canevas de congé devrait utiliser « Personnel + demande de congé » : sinon le type, les dates et le motif du congé ne sont pas repris, et il n’est pas proposé à l’impression d’un congé.';
    }
    if (type.includes('CONTRAT') && form.data_context !== 'EMPLOYEE_AND_CONTRACT') {
        return 'Un canevas de contrat devrait utiliser « Personnel + contrat de travail » : sinon les dates et la référence du contrat ne sont pas reprises, et il n’est pas proposé à l’impression d’un contrat.';
    }

    return null;
});
const isArchivedTemplate = computed(() => props.template?.archived ?? false);

const form = useForm({
    document_type: props.template?.document_type ?? '',
    data_context: props.template?.data_context ?? (props.dataContexts[0]?.value ?? ''),
    name: props.template?.name ?? '',
    description: props.template?.description ?? '',
    active: props.template?.active ?? true,
});

const PAGE_BREAK_HTML = '<div data-page-break class="canevas-page-break"></div>';
const newPageId = () => (crypto.randomUUID ? crypto.randomUUID() : `page-${Date.now()}-${Math.random()}`);

/** @typedef {{ id: string, content: string }} CanevasPage */

/** @type {import('vue').Ref<CanevasPage[]>} */
const pages = ref(
    Array.isArray(props.template?.content?.pages) && props.template.content.pages.length
        ? props.template.content.pages.map((page) => ({
            id: page.id ?? newPageId(),
            content: page.content ?? '<p></p>',
        }))
        : [{ id: newPageId(), content: '<p></p>' }],
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
    const page = { id: newPageId(), content: '<p></p>' };
    pages.value.push(page);
    selectPage(page.id);
    isDirty.value = true;
};
const duplicatePage = (id) => {
    commitActivePage();
    const index = pages.value.findIndex((page) => page.id === id);
    const source = pages.value[index];
    pages.value.splice(index + 1, 0, { id: newPageId(), content: source.content });
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

const isActive = (name, attrs) => editor.value?.isActive(name, attrs) ?? false;

const importInput = ref(null);
const importWarning = ref('');
const triggerImport = () => importInput.value?.click();
const handleImportFile = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file || !editor.value) return;

    importWarning.value = '';
    const isPdf = file.name.toLowerCase().endsWith('.pdf');

    let imported;
    try {
        imported = isPdf
            ? { pages: await extractPdfPages(file), paginated: true }
            : await convertDocxToPages(file);
    } catch (error) {
        alert(`Échec de l’import : ${error.message}`);
        return;
    }

    const count = imported.pages.length;
    const currentContent = editor.value.getHTML();
    if (currentContent && currentContent !== '<p></p>'
        && !confirm(count > 1
            ? `Le fichier contient ${count} pages. La page active sera remplacée par la page 1, et les ${count - 1} suivantes seront ajoutées juste après. Continuer ?`
            : 'La page active contient déjà du texte. Remplacer son contenu par le fichier importé ?')) {
        return;
    }

    // One canevas page per page of the file: the first replaces the active
    // page, the others follow it in their original order.
    commitActivePage();
    const index = activePageIndex.value;
    pages.value[index].content = imported.pages[0];
    pages.value.splice(index + 1, 0, ...imported.pages.slice(1).map((content) => ({ id: newPageId(), content })));
    editor.value.commands.setContent(imported.pages[0]);
    isDirty.value = true;

    const notes = [];
    if (count > 1) notes.push(`${count} pages importées, une page du canevas par page du fichier.`);
    if (!isPdf && !imported.paginated) notes.push('Aucune limite de page n’a été trouvée dans ce fichier Word : tout le contenu est sur une page. Enregistrez-le depuis Word puis réimportez-le, ou ajoutez des sauts de page.');
    if (isPdf) notes.push('Import PDF : seul le texte a été récupéré, sans mise en forme — reformatez manuellement (gras, titres, tableaux…).');
    importWarning.value = notes.join(' ');
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
        const response = await fetch(`/super-admin/workspaces/document-templates/${props.targetSite.code}/${props.template.uuid}/history`, {
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
        `/super-admin/workspaces/document-templates/${props.targetSite.code}/${revertTarget.value.uuid}/revert`,
        { reason: revertReason.value },
        { onFinish: () => { reverting.value = false; } },
    );
};

watch(() => [form.document_type, form.data_context, form.name, form.description, form.active], () => {
    isDirty.value = true;
});

const backUrl = `/super-admin/workspaces/document-templates?site=${props.targetSite.code}`;
const leaveEditor = () => {
    if (isDirty.value && !confirm('Des modifications ne sont pas enregistrées. Quitter sans enregistrer ?')) return;
    router.visit(backUrl);
};

const submit = () => {
    if (!editor.value) return;

    const contentHtml = buildContentHtml();
    form.transform((data) => ({
        ...data,
        content: { pages: pages.value.map(({ id, content }) => ({ id, content })) },
        content_html: contentHtml,
        ...(isEditing.value ? {} : { site_code: props.targetSite.code }),
    }));

    const options = {
        preserveScroll: true,
        onSuccess: () => { isDirty.value = false; },
    };

    if (isEditing.value) {
        form.put(`/super-admin/workspaces/document-templates/${props.targetSite.code}/${props.template.uuid}`, options);
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
                <button type="button" class="inline-flex items-center gap-1.5 text-xs font-bold text-muted-foreground hover:text-primary" @click="leaveEditor"><ArrowLeft class="h-4 w-4" />Canevas de documents</button>
                <h1 class="mt-1 font-heading text-xl font-bold text-foreground">{{ isEditing ? `Modifier « ${template.name} »` : 'Nouveau canevas' }}</h1>
                <p class="mt-1 text-xs text-muted-foreground">Site destinataire : <strong>{{ targetSite.name }}</strong><span v-if="isEditing && template.generated_documents_count"> · {{ template.generated_documents_count }} document(s) déjà généré(s) — toute modification crée une nouvelle version, sans affecter ceux-là.</span></p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span v-if="isDirty" class="text-xs font-bold text-amber-600">● Modifications non enregistrées</span>
                <Button v-if="isEditing" size="rg" variant="white-outline" type="button" @click="openHistory"><History class="h-4 w-4" />Historique</Button>
                <Button size="rg" variant="white-outline" type="button" @click="showPreview = true"><Eye class="h-4 w-4" />Aperçu de la structure</Button>
                <Button v-if="!isArchivedTemplate" size="rg" variant="white-outline" type="button" title="Importer un fichier Word (.docx) ou PDF dans la page active" @click="triggerImport"><Upload class="h-4 w-4" />Importer un fichier</Button>
                <input ref="importInput" type="file" accept=".docx,.pdf" class="hidden" @change="handleImportFile">
                <Button size="rg" variant="white-outline" type="button" @click="leaveEditor">Annuler</Button>
                <Button v-if="!isArchivedTemplate" size="rg" :disabled="form.processing" @click="submit">
                    <Save class="h-4.5 w-4.5" />{{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
                </Button>
            </div>
        </header>

        <p v-if="isArchivedTemplate" class="rounded border border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-300">Ce canevas est archivé et affiché en lecture seule. Restaurez-le depuis la liste pour le modifier.</p>

        <section class="rounded-lg border border-border bg-card p-4">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <label class="mb-1.5 block text-xs font-bold uppercase text-muted-foreground">Nom du canevas <span class="text-red-500">*</span></label>
                    <input v-model="form.name" type="text" required :disabled="isArchivedTemplate" class="h-10 w-full rounded border border-border bg-card px-3 text-sm">
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase text-muted-foreground">Type de document <span class="text-red-500">*</span></label>
                    <input v-model="form.document_type" type="text" required list="document-type-suggestions" placeholder="CONTRAT, ATTESTATION…" :disabled="isArchivedTemplate" class="h-10 w-full rounded border border-border bg-card px-3 text-sm uppercase">
                    <datalist id="document-type-suggestions">
                        <option value="CONTRAT" /><option value="CONGE" /><option value="ATTESTATION" /><option value="CERTIFICAT" /><option value="LETTRE" /><option value="DECISION" /><option value="AUTRE" />
                    </datalist>
                    <p v-if="form.errors.document_type" class="mt-1 text-xs text-red-600">{{ form.errors.document_type }}</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase text-muted-foreground">Contexte de données <span class="text-red-500">*</span></label>
                    <select v-model="form.data_context" required :disabled="isArchivedTemplate" class="h-10 w-full rounded border border-border bg-card px-2 text-sm">
                        <option v-for="context in dataContexts" :key="context.value" :value="context.value">{{ context.label }}</option>
                    </select>
                    <p v-if="form.errors.data_context" class="mt-1 text-xs text-red-600">{{ form.errors.data_context }}</p>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <label class="mb-1.5 block text-xs font-bold uppercase text-muted-foreground">Description <span class="text-muted-foreground">(facultatif)</span></label>
                    <input v-model="form.description" type="text" :disabled="isArchivedTemplate" class="h-10 w-full rounded border border-border bg-card px-3 text-sm">
                </div>
                <label class="inline-flex items-center gap-2 self-end text-sm text-foreground">
                    <input v-model="form.active" type="checkbox" :disabled="isArchivedTemplate" class="h-4 w-4 rounded border-input">
                    Actif (proposé au RH)
                </label>
            </div>

            <!-- ADR-198 — ce que le RH verra, pour le contexte choisi. -->
            <div v-if="selectedContext" class="mt-4 grid gap-3 rounded-lg border border-border bg-muted/30 p-3 text-sm lg:grid-cols-[2fr_1fr]">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Page 1 pour le RH · remplie depuis le dossier, modifiable</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <span v-for="field in selectedContext.fields" :key="field" class="rounded-md border border-border bg-card px-2 py-0.5 text-xs text-foreground">{{ field }}</span>
                    </div>
                </div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Proposé au RH depuis</p>
                    <ul class="mt-2 space-y-0.5 text-xs text-foreground">
                        <li v-for="place in selectedContext.offered_from" :key="place">{{ place }}</li>
                    </ul>
                </div>
                <p v-if="contextMismatch" class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 lg:col-span-2 dark:bg-amber-950/30 dark:text-amber-200" role="status">{{ contextMismatch }}</p>
                <p class="text-xs text-muted-foreground lg:col-span-2">Le texte des pages ci-dessous est imprimé tel quel après la page 1 : écrivez-le comme un document final, sans code de variable.</p>
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-[220px_minmax(0,1fr)]">
            <aside class="space-y-2">
                <h2 class="px-1 text-xs font-bold uppercase tracking-wide text-muted-foreground">Pages ({{ pages.length }})</h2>
                <div
                    v-for="(page, index) in pages" :key="page.id"
                    :class="['rounded-lg border p-2.5', page.id === activePageId ? 'border-primary bg-primary/5' : 'border-border bg-card']"
                >
                    <button type="button" class="block w-full text-start" @click="selectPage(page.id)">
                        <p class="text-xs font-bold text-foreground">Page {{ index + 1 }}</p>
                    </button>
                    <div v-if="!isArchivedTemplate" class="mt-2 flex items-center justify-between gap-1">
                        <div class="flex gap-0.5">
                            <button type="button" class="flex h-6 w-6 items-center justify-center rounded text-muted-foreground hover:text-primary" title="Monter" :disabled="index === 0" @click="movePage(page.id, -1)"><ChevronUp class="h-4 w-4" /></button>
                            <button type="button" class="flex h-6 w-6 items-center justify-center rounded text-muted-foreground hover:text-primary" title="Descendre" :disabled="index === pages.length - 1" @click="movePage(page.id, 1)"><ChevronDown class="h-4 w-4" /></button>
                        </div>
                        <div class="flex gap-0.5">
                            <button type="button" class="flex h-6 w-6 items-center justify-center rounded text-muted-foreground hover:text-primary" title="Dupliquer" @click="duplicatePage(page.id)"><Copy class="h-4 w-4" /></button>
                            <button type="button" class="flex h-6 w-6 items-center justify-center rounded text-muted-foreground hover:text-red-600" title="Supprimer" :disabled="pages.length <= 1" @click="deletePage(page.id)"><Trash2 class="h-4 w-4" /></button>
                        </div>
                    </div>
                </div>
                <button v-if="!isArchivedTemplate" type="button" class="w-full rounded-lg border border-dashed border-input py-2 text-xs font-bold text-muted-foreground hover:border-primary hover:text-primary" @click="addPage">
                    <Plus class="h-4 w-4" /> Ajouter une page
                </button>
            </aside>

            <section class="overflow-hidden rounded-lg border border-border bg-card">
                <div class="flex items-center justify-between border-b border-border px-3 py-1.5 text-[11px] font-bold text-muted-foreground">
                    <span>Page {{ activePageIndex + 1 }} / {{ pages.length }}</span>
                </div>
                <div v-if="editor" class="flex flex-wrap items-center gap-1 border-b border-border bg-muted/70 p-2 /40">
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
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn font-bold', isActive('bold') && 'toolbar-btn-active']" title="Gras" @click="editor.chain().focus().toggleBold().run()">G</button>
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn italic', isActive('italic') && 'toolbar-btn-active']" title="Italique" @click="editor.chain().focus().toggleItalic().run()">I</button>
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn underline', isActive('underline') && 'toolbar-btn-active']" title="Souligné" @click="editor.chain().focus().toggleUnderline().run()">S</button>
                    <button type="button" :disabled="isArchivedTemplate" :class="['toolbar-btn line-through', isActive('strike') && 'toolbar-btn-active']" title="Barré" @click="editor.chain().focus().toggleStrike().run()">B</button>
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
                    <button type="button" :disabled="isArchivedTemplate" class="toolbar-btn" title="Insérer une image" @click="insertImage"><Image class="h-4 w-4" /></button>
                    <button type="button" :disabled="isArchivedTemplate" class="toolbar-btn" title="Bloc signature" @click="insertSignatureBlock">Signatures</button>
                    <span class="toolbar-sep" />
                    <button type="button" :disabled="isArchivedTemplate" class="toolbar-btn" title="Annuler" @click="editor.chain().focus().undo().run()"><RotateCcw class="h-4 w-4" /></button>
                    <button type="button" :disabled="isArchivedTemplate" class="toolbar-btn" title="Rétablir" @click="editor.chain().focus().redo().run()"><Redo2 class="h-4 w-4" /></button>
                </div>
                <div v-if="importWarning" class="flex items-start justify-between gap-3 border-b border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-300">
                    <span>{{ importWarning }}</span>
                    <button type="button" class="shrink-0 text-amber-600 hover:text-amber-800" @click="importWarning = ''"><X class="h-4 w-4" /></button>
                </div>
                <EditorContent :editor="editor" class="canevas-editor-content" />
            </section>
        </div>

        <div v-if="showPreview" class="fixed inset-0 z-50 flex items-center justify-center bg-foreground/50 p-4" @click.self="showPreview = false">
            <div class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-lg bg-card shadow-xl">
                <header class="flex items-center justify-between border-b border-border px-5 py-3">
                    <div>
                        <h2 class="text-sm font-bold text-foreground">Aperçu de la structure ({{ pages.length }} page(s))</h2>
                        <p class="mt-0.5 text-[11px] text-muted-foreground">Aperçu du canevas seul — la page 1 (informations du RH) est ajoutée automatiquement à la génération. Pour un aperçu avec les vraies informations d’une personne, utilisez « Générer un document » côté Administration/RH.</p>
                    </div>
                    <button type="button" class="text-muted-foreground hover:text-foreground" @click="showPreview = false"><X class="h-5 w-5" /></button>
                </header>
                <div class="overflow-y-auto p-6">
                    <div class="canevas-document" v-html="previewHtml" />
                </div>
            </div>
        </div>

        <div v-if="showHistory" class="fixed inset-0 z-50 flex items-center justify-center bg-foreground/50 p-4" @click.self="showHistory = false">
            <div class="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-lg bg-card shadow-xl">
                <header class="flex items-center justify-between border-b border-border px-5 py-3">
                    <div>
                        <h2 class="text-sm font-bold text-foreground">Historique des versions</h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">Chaque enregistrement qui remplace un canevas déjà utilisé crée une nouvelle version — les documents déjà générés continuent de pointer vers celle qui les a produits.</p>
                    </div>
                    <button type="button" class="text-muted-foreground hover:text-foreground" @click="showHistory = false"><X class="h-5 w-5" /></button>
                </header>
                <div class="overflow-y-auto p-5">
                    <div v-if="historyLoading" class="flex items-center gap-2 py-8 text-sm text-muted-foreground"><LoaderCircle class="animate-spin h-4 w-4" />Chargement…</div>
                    <p v-else-if="historyError" class="py-8 text-center text-sm text-red-600">{{ historyError }}</p>
                    <ul v-else class="space-y-2">
                        <li v-for="version in historyVersions" :key="version.uuid" class="rounded-lg border border-border p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <span :class="['rounded px-2 py-0.5 text-[10px] font-bold uppercase', !version.archived ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-muted text-muted-foreground ']">{{ !version.archived ? 'Version actuelle' : 'Archivée' }}</span>
                                    <p class="mt-1 text-sm font-bold text-foreground">{{ version.name }}</p>
                                    <p class="mt-0.5 text-xs text-muted-foreground">{{ version.creator || 'Auteur inconnu' }} · {{ version.created_at ? new Date(version.created_at).toLocaleString('fr-FR') : '' }}</p>
                                    <p v-if="version.archive_reason" class="mt-1 text-xs text-muted-foreground">{{ version.archive_reason }}</p>
                                    <p v-if="version.generated_documents_count" class="mt-1 text-xs text-muted-foreground">{{ version.generated_documents_count }} document(s) généré(s) depuis cette version</p>
                                </div>
                                <Button v-if="version.archived" size="sm" variant="white-outline" type="button" @click="requestRevert(version)">Revenir à cette version</Button>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div v-if="revertTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-foreground/60 p-4" @click.self="cancelRevert">
            <div class="w-full max-w-md rounded-lg bg-card shadow-xl">
                <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
                    <div><h2 class="text-lg font-bold text-foreground">Revenir à cette version</h2><p class="mt-1 text-xs text-muted-foreground">« {{ revertTarget.name }} » du {{ new Date(revertTarget.created_at).toLocaleString('fr-FR') }} redevient la version active. La version actuelle est archivée, jamais supprimée.</p></div>
                    <button type="button" class="text-muted-foreground hover:text-foreground" @click="cancelRevert"><X class="h-5 w-5" /></button>
                </header>
                <form class="space-y-4 p-5" @submit.prevent="confirmRevert">
                    <div><label class="mb-1.5 block text-sm font-medium text-foreground">Motif <span class="text-red-500">*</span></label><textarea v-model="revertReason" required rows="3" class="block w-full rounded border border-border bg-card px-3 py-2 text-sm" /></div>
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
    transition: background-color 150ms, color 150ms;
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
.toolbar-select,
.toolbar-color {
    height: 2rem;
    border-radius: 0.375rem;
    border: 1px solid rgb(229 231 235);
    background-color: white;
    color: rgb(51 65 85);
}
.toolbar-select {
    padding: 0 0.375rem;
    font-size: 0.75rem;
}
.toolbar-color {
    width: 2rem;
    padding: 0.125rem;
}
.toolbar-sep {
    margin: 0 0.25rem;
    height: 1.25rem;
    width: 1px;
    background-color: rgb(229 231 235);
}

/* Dark mode: this app toggles a `.dark` class higher up the tree, so plain
   (non-Tailwind-utility) CSS in a scoped block needs an explicit :global()
   override — same pattern already used by ClinicalRichTextEditor.vue. */
:global(.dark) .toolbar-btn {
    color: rgb(203 213 225);
}
:global(.dark) .toolbar-btn:hover:not(:disabled) {
    background-color: rgb(30 41 59);
    color: white;
}
:global(.dark) .toolbar-btn-active {
    background-color: rgb(49 46 129 / 0.4);
    color: rgb(165 180 252);
}
:global(.dark) .toolbar-select,
:global(.dark) .toolbar-color {
    border-color: rgb(31 41 55);
    background-color: rgb(2 6 23);
    color: rgb(226 232 240);
}
:global(.dark) .toolbar-select option {
    background-color: rgb(2 6 23);
    color: rgb(226 232 240);
}
:global(.dark) .toolbar-sep {
    background-color: rgb(31 41 55);
}

/* The editing canvas — and the structure-preview modal below — represent an
   actual printed page (same convention as Print.vue's `.print-doc`): always
   literal white paper with dark text, in both themes, never following the
   app's dark mode. Reversing this to dark-on-dark would misrepresent what
   the document will actually look like once printed, and previously left
   the text unreadable (near-black on near-black) in dark mode. */
:deep(.canevas-editor-content),
.canevas-document {
    background-color: white;
    color: rgb(15 23 42);
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
