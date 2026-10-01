<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft, Check, ChevronDown, ChevronUp, Copy, Database, Eye, FileText, FileUp, Folder, FolderPen, History, Image, Info, LoaderCircle,
    Maximize2, PanelRightOpen, Pencil, PenLine, Plus, Redo2, RotateCcw, Save, Server, Tag, Trash2, TriangleAlert, Upload, X,
} from 'lucide-vue-next';
import { Editor, EditorContent } from '@tiptap/vue-3';
import { StarterKit } from '@tiptap/starter-kit';
import { Underline } from '@tiptap/extension-underline';
import { TextAlign } from '@tiptap/extension-text-align';
import { TextStyle } from '@tiptap/extension-text-style';
import { Color } from '@tiptap/extension-color';
import { FontFamily } from '@tiptap/extension-font-family';
import { Highlight } from '@tiptap/extension-highlight';
import { Image as ImageExtension } from '@tiptap/extension-image';
import { Table } from '@tiptap/extension-table';
import { TableRow } from '@tiptap/extension-table-row';
import { TableHeader } from '@tiptap/extension-table-header';
import { TableCell } from '@tiptap/extension-table-cell';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Sheet from '@/Components/Shadcn/Sheet.vue';
import Switch from '@/Components/Shadcn/Switch.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { cn } from '@/lib/cn';
import { FontSize } from '@/tiptap/FontSize';
import { BlockStyle, parseStyle, stringifyStyle } from '@/tiptap/BlockStyle';
import { convertDocxToPages, extractPdfPages } from '@/tiptap/documentImport';
import { familyKey, familyTone } from '@/utilities/documentFamilies';
import { CUSTOM_FOLDER, MODULE_NAME, expectedContext, folderChoice, missingTemplateFields } from '@/utilities/documentTemplates';

defineOptions({ layout: AppLayout });

/**
 * ADR-240 — la fiche d'un modèle de document, dans l'ordre où l'on décide :
 * 1 · le dossier (contrat, congé…), qui règle d'office les données reprises ;
 * 2 · les données que le RH verra en page 1 ; 3 · le nom ; puis le texte,
 * page par page. Aucune fenêtre du navigateur : chaque confirmation est une
 * fenêtre de l'application.
 */
const props = defineProps({
    targetSite: { type: Object, required: true },
    template: { type: Object, default: null },
    dataContexts: { type: Array, default: () => [] },
    families: { type: Array, default: () => [] },
    /** ADR-208 — « Nouveau modèle » depuis un dossier : son type et son contexte. */
    preset: { type: Object, default: null },
});

const isEditing = computed(() => props.template !== null);
const isArchivedTemplate = computed(() => props.template?.archived ?? false);

const form = useForm({
    document_type: props.template?.document_type ?? props.preset?.document_type ?? '',
    // Un nouveau modèle ne présume rien : le dossier choisi règle les données reprises.
    data_context: props.template?.data_context ?? props.preset?.data_context ?? '',
    name: props.template?.name ?? '',
    description: props.template?.description ?? '',
    active: props.template?.active ?? true,
});

// 1 · Le dossier : un dossier connu, ou un type écrit à la main (son propre dossier).
const folder = ref(folderChoice(form.document_type, props.families));
const customType = ref(folder.value === CUSTOM_FOLDER ? form.document_type : '');
const TONE_CLASSES = {
    sky: 'text-sky-600 bg-sky-50 dark:bg-sky-950/40 dark:text-sky-300',
    emerald: 'text-emerald-600 bg-emerald-50 dark:bg-emerald-950/40 dark:text-emerald-300',
    violet: 'text-violet-600 bg-violet-50 dark:bg-violet-950/40 dark:text-violet-300',
    amber: 'text-amber-600 bg-amber-50 dark:bg-amber-950/40 dark:text-amber-300',
    primary: 'text-primary bg-primary/10',
    slate: 'text-muted-foreground bg-muted',
};
const chooseFolder = (key) => {
    if (isArchivedTemplate.value) return;
    folder.value = key;
    if (key === CUSTOM_FOLDER) {
        form.document_type = customType.value.trim().toUpperCase();

        return;
    }
    form.document_type = key;
    // Le dossier connu règle les données reprises ; elles restent modifiables ensuite.
    const family = props.families.find((item) => item.key === key);
    if (family?.context) form.data_context = family.context;
};
watch(customType, (value) => {
    if (folder.value === CUSTOM_FOLDER) form.document_type = value.trim().toUpperCase();
});

// 2 · Ce que le RH verra : les champs de la page 1 et où le modèle lui est proposé (ADR-207).
const selectedContext = computed(() => props.dataContexts.find((context) => context.value === form.data_context) ?? null);
const contextOptions = computed(() => props.dataContexts.map((context) => ({ value: context.value, label: context.label })));
const contextLabel = (value) => props.dataContexts.find((context) => context.value === value)?.label ?? value;
const contextMismatch = computed(() => expectedContext({ document_type: form.document_type, data_context: form.data_context }));

const PAGE_BREAK_HTML = '<div data-page-break class="canevas-page-break"></div>';
const newPageId = () => (crypto.randomUUID ? crypto.randomUUID() : `page-${Date.now()}-${Math.random()}`);

/** @typedef {{ id: string, name: string, content: string }} TemplatePage */

/** @type {import('vue').Ref<TemplatePage[]>} */
const pages = ref(
    Array.isArray(props.template?.content?.pages) && props.template.content.pages.length
        ? props.template.content.pages.map((page) => ({
            id: page.id ?? newPageId(),
            name: typeof page.name === 'string' ? page.name : '',
            content: page.content ?? '<p></p>',
        }))
        : [{ id: newPageId(), name: '', content: '<p></p>' }],
);
const activePageId = ref(pages.value[0].id);
const activePageIndex = computed(() => pages.value.findIndex((page) => page.id === activePageId.value));
const activePage = computed(() => pages.value[activePageIndex.value]);
const PAGE_NAME_MAX = 60;
/** Le nom donné à une page, sinon « Page N ». Le nom n'est pas imprimé : il sert à s'y retrouver. */
const pageLabel = (page, index) => page.name?.trim() || `Page ${index + 1}`;

// Renommer une page sur place : Entrée ou un clic ailleurs enregistre, Échap annule.
const renaming = ref(null);
const renameDraft = ref('');
const startRename = (page, where) => {
    if (isArchivedTemplate.value) return;
    renaming.value = { id: page.id, where };
    renameDraft.value = page.name ?? '';
    nextTick(() => document.querySelector(`[data-page-rename="${page.id}-${where}"]`)?.select());
};
const commitRename = () => {
    if (!renaming.value) return;
    const page = pages.value.find((item) => item.id === renaming.value.id);
    const value = renameDraft.value.trim().slice(0, PAGE_NAME_MAX);
    if (page && page.name !== value) {
        page.name = value;
        isDirty.value = true;
    }
    renaming.value = null;
};
const cancelRename = () => { renaming.value = null; };
const isRenaming = (page, where) => renaming.value?.id === page.id && renaming.value?.where === where;

// La fiche (dossier, données reprises, nom, pages) vit dans un panneau latéral :
// la feuille garde toute la largeur. Un nouveau modèle commence par un choix —
// importer un fichier Word/PDF, ou écrire directement.
const showFiche = ref(false);
const hasWrittenContent = pages.value.some((page) => page.content && page.content !== '<p></p>');
/** @type {import('vue').Ref<'choose'|'import'|'write'>} */
const startMode = ref(isEditing.value || hasWrittenContent ? 'write' : 'choose');
const startWriting = () => {
    startMode.value = 'write';
    setTimeout(() => editor.value?.commands.focus('end'), 0);
};
const dropActive = ref(false);

const editor = shallowRef(null);
// Toute modification non enregistrée (texte, pages ou fiche) : quitter sans
// enregistrer le demande d'abord — perdre une page d'un document à valeur
// juridique serait un défaut grave.
const isDirty = ref(false);
const contentVersion = ref(0);

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
        onUpdate: () => { isDirty.value = true; contentVersion.value += 1; },
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
    const page = { id: newPageId(), name: '', content: '<p></p>' };
    pages.value.push(page);
    selectPage(page.id);
    startMode.value = 'write';
    isDirty.value = true;
};
const duplicatePage = (id) => {
    commitActivePage();
    const index = pages.value.findIndex((page) => page.id === id);
    const source = pages.value[index];
    pages.value.splice(index + 1, 0, { id: newPageId(), name: source.name ? `${source.name} (copie)`.slice(0, PAGE_NAME_MAX) : '', content: source.content });
    isDirty.value = true;
};
const pageToDelete = ref(null);
const deletePage = () => {
    const id = pageToDelete.value;
    pageToDelete.value = null;
    if (!id || pages.value.length <= 1) return;
    const wasActive = id === activePageId.value;
    pages.value = pages.value.filter((page) => page.id !== id);
    if (wasActive) {
        activePageId.value = null;
        selectPage(pages.value[0].id);
    }
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
const pageExcerpt = (page) => {
    const html = page.id === activePageId.value && editor.value ? (contentVersion.value, editor.value.getHTML()) : page.content;
    const text = String(html ?? '').replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();

    return text ? text.slice(0, 70) : 'Page vide';
};

const isActive = (name, attrs) => editor.value?.isActive(name, attrs) ?? false;

// Import d'un fichier Word ou PDF : une page du modèle par page du fichier (ADR-087).
const importInput = ref(null);
const importNotice = ref(null);
const pendingImport = ref(null);
const triggerImport = () => importInput.value?.click();
const applyImport = ({ imported, isPdf }) => {
    pendingImport.value = null;
    const count = imported.pages.length;
    commitActivePage();
    const index = activePageIndex.value;
    pages.value[index].content = imported.pages[0];
    pages.value.splice(index + 1, 0, ...imported.pages.slice(1).map((content) => ({ id: newPageId(), name: '', content })));
    editor.value.commands.setContent(imported.pages[0]);
    isDirty.value = true;
    startMode.value = 'write';

    const notes = [];
    if (count > 1) notes.push(`${count} pages importées, une page du modèle par page du fichier.`);
    if (!isPdf && !imported.paginated) notes.push('Aucune limite de page n’a été trouvée dans ce fichier Word : tout le contenu est sur une page. Enregistrez-le depuis Word puis réimportez-le, ou ajoutez des sauts de page.');
    if (isPdf) notes.push('Import PDF : seul le texte a été récupéré, sans mise en forme — reformatez à la main (gras, titres, tableaux…).');
    importNotice.value = notes.length ? { tone: 'info', text: notes.join(' ') } : null;
};
const handleImportFile = (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    importFile(file);
};
const handleDrop = (event) => {
    dropActive.value = false;
    importFile(event.dataTransfer?.files?.[0]);
};
const importFile = async (file) => {
    if (!file || !editor.value) return;
    if (!/\.(docx|pdf)$/i.test(file.name)) {
        importNotice.value = { tone: 'error', text: `« ${file.name} » n’est ni un fichier Word (.docx) ni un PDF.` };

        return;
    }

    importNotice.value = null;
    const isPdf = file.name.toLowerCase().endsWith('.pdf');

    let imported;
    try {
        imported = isPdf
            ? { pages: await extractPdfPages(file), paginated: true }
            : await convertDocxToPages(file);
    } catch (error) {
        importNotice.value = { tone: 'error', text: `Échec de l’import de « ${file.name} » : ${error.message}` };

        return;
    }

    const currentContent = editor.value.getHTML();
    if (currentContent && currentContent !== '<p></p>') {
        pendingImport.value = { imported, isPdf, name: file.name };

        return;
    }
    applyImport({ imported, isPdf });
};
const importQuestion = computed(() => {
    const count = pendingImport.value?.imported.pages.length ?? 0;

    return count > 1
        ? `« ${pendingImport.value.name} » contient ${count} pages. La page active sera remplacée par la page 1, et les ${count - 1} suivantes ajoutées juste après.`
        : `La page active contient déjà du texte : il sera remplacé par le contenu de « ${pendingImport.value?.name} ».`;
});

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
// L'aperçu montre chaque page sur sa feuille A4, comme elle s'imprimera.
const previewPages = computed(() => {
    if (!showPreview.value) return [];
    commitActivePage();

    return pages.value.map((page, index) => ({ id: page.id, label: pageLabel(page, index), html: page.content }));
});

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

// ADR-208 — on revient au dossier du modèle (celui de son type), pas à la racine.
const backFolder = familyKey(props.template?.document_type ?? props.preset?.document_type ?? '');
const backFolderLabel = props.families.find((family) => family.key === backFolder)?.label ?? MODULE_NAME;
const backUrl = `/super-admin/workspaces/document-templates?site=${props.targetSite.code}${props.template || props.preset ? `&dossier=${encodeURIComponent(backFolder)}` : ''}`;
const confirmLeave = ref(false);
const leaveEditor = () => {
    if (isDirty.value) {
        confirmLeave.value = true;

        return;
    }
    router.visit(backUrl);
};
const leaveWithoutSaving = () => {
    isDirty.value = false;
    confirmLeave.value = false;
    router.visit(backUrl);
};

const missing = computed(() => (contentVersion.value, missingTemplateFields(form, {
    pages: pages.value.map((page) => ({ content: page.id === activePageId.value && editor.value ? editor.value.getHTML() : page.content })),
})));
const steps = computed(() => [
    { key: 'dossier', label: 'Dossier', done: Boolean(form.document_type.trim()) },
    { key: 'donnees', label: 'Données reprises', done: Boolean(form.data_context) },
    { key: 'nom', label: 'Nom', done: Boolean(form.name.trim()) },
    { key: 'texte', label: 'Texte', done: ! missing.value.includes('le texte du modèle') },
]);

// La feuille A4 : 21 × 29,7 cm, marges 2,5 cm en haut et en bas, 2 cm sur les côtés —
// celles de l'impression du document par le RH (Print.vue). Le texte qui dépasse la
// zone imprimable est signalé : il passerait sur une page de plus à l'impression.
const MM_TO_PX = 96 / 25.4;
const A4_WIDTH_PX = 210 * MM_TO_PX;
const paperViewport = ref(null);
const pageBody = ref(null);
const editorHost = ref(null);
const zoomChoice = ref('fit');
const fitZoom = ref(1);
const zoomOptions = [
    { value: 'fit', label: 'Ajuster' },
    { value: '0.75', label: '75 %' },
    { value: '1', label: '100 %' },
    { value: '1.25', label: '125 %' },
];
const zoom = computed(() => (zoomChoice.value === 'fit' ? fitZoom.value : Number(zoomChoice.value)));
const pageOverflows = ref(false);
const measurePaper = () => {
    if (paperViewport.value) {
        const available = paperViewport.value.clientWidth - 48;
        fitZoom.value = Math.max(0.4, Math.min(1, available / A4_WIDTH_PX));
    }
    if (pageBody.value && editorHost.value) {
        pageOverflows.value = editorHost.value.offsetHeight > pageBody.value.clientHeight + 2;
    }
};
let paperObserver = null;
onMounted(() => {
    if (typeof ResizeObserver === 'undefined') return;
    paperObserver = new ResizeObserver(() => measurePaper());
    [paperViewport.value, editorHost.value].forEach((element) => element && paperObserver.observe(element));
    measurePaper();
});
onBeforeUnmount(() => paperObserver?.disconnect());
watch([contentVersion, activePageId, startMode], () => nextTick(measurePaper));

// « Fiche du modèle » : chaque saisie est gardée aussitôt, sans bouton « Terminé ».
// « Annuler » remet la fiche — et les pages — comme à l'ouverture du panneau.
const ficheSnapshot = ref(null);
/** Ce que la fiche règle : le dossier, les données, le nom, et l'ordre et le nom des pages. */
const ficheState = () => JSON.stringify({
    form: { document_type: form.document_type, data_context: form.data_context, name: form.name, description: form.description, active: form.active },
    folder: folder.value,
    customType: customType.value,
    pages: pages.value.map(({ id, name }) => ({ id, name })),
});
watch(showFiche, (open) => {
    if (open) {
        commitActivePage();
        ficheSnapshot.value = {
            state: ficheState(),
            form: { ...JSON.parse(ficheState()).form },
            folder: folder.value,
            customType: customType.value,
            pages: pages.value.map((page) => ({ ...page })),
            activePageId: activePageId.value,
            dirty: isDirty.value,
        };
    } else {
        // Un clic ailleurs a déjà enregistré le nom (blur) ; Échap ferme sans l'enregistrer.
        renaming.value = null;
    }
});
const ficheChanged = computed(() => Boolean(showFiche.value && ficheSnapshot.value && ficheState() !== ficheSnapshot.value.state));
const cancelFiche = () => {
    const saved = ficheSnapshot.value;
    renaming.value = null;
    if (saved && ficheChanged.value) {
        Object.assign(form, saved.form);
        folder.value = saved.folder;
        customType.value = saved.customType;
        pages.value = saved.pages.map((page) => ({ ...page }));
        activePageId.value = pages.value.some((page) => page.id === saved.activePageId) ? saved.activePageId : pages.value[0].id;
        mountPage(activePage.value);
        nextTick(() => { isDirty.value = saved.dirty; });
    }
    showFiche.value = false;
};

const TEXT_MISSING = 'le texte du modèle';
const ficheMissing = computed(() => missing.value.filter((item) => item !== TEXT_MISSING));
const stepsDone = computed(() => steps.value.filter((step) => step.done).length);

const submit = () => {
    if (!editor.value) return;
    // Ce qui manque à la fiche se complète dans le panneau : on l'ouvre plutôt que de bloquer en silence.
    if (ficheMissing.value.length) {
        showFiche.value = true;

        return;
    }
    if (missing.value.length) return;

    const contentHtml = buildContentHtml();
    form.transform((data) => ({
        ...data,
        content: { pages: pages.value.map(({ id, name, content }) => ({ id, ...(name?.trim() ? { name: name.trim() } : {}), content })) },
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
const formatDateTime = (value) => (value ? new Date(value).toLocaleString('fr-FR') : '');
</script>

<template>
    <Head :title="isEditing ? `Modifier · ${template.name}` : `Nouveau modèle · ${MODULE_NAME}`" />

    <div class="w-full space-y-4">
        <!-- En-tête -->
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <button type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground hover:text-primary" @click="leaveEditor">
                    <ArrowLeft class="h-4 w-4" />{{ MODULE_NAME }}<template v-if="template || preset"> · {{ backFolderLabel }}</template>
                </button>
                <h1 class="mt-1 font-heading text-xl font-bold text-foreground">{{ isEditing ? template.name : 'Nouveau modèle de document' }}</h1>
                <div class="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <Badge variant="outline"><Server class="h-3 w-3" />{{ targetSite.name }}</Badge>
                    <Badge v-if="isArchivedTemplate" variant="secondary">Archivé · lecture seule</Badge>
                    <span v-if="isEditing && template.generated_documents_count">{{ template.generated_documents_count }} document{{ template.generated_documents_count > 1 ? 's' : '' }} déjà produit{{ template.generated_documents_count > 1 ? 's' : '' }} : enregistrer crée une nouvelle version, sans les toucher.</span>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span v-if="isDirty && ! isArchivedTemplate" class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-600 dark:text-amber-400"><span class="h-2 w-2 rounded-full bg-amber-500" />Non enregistré</span>
                <Button v-if="isEditing" variant="outline" type="button" @click="openHistory"><History class="h-4 w-4" />Versions</Button>
                <Button variant="outline" type="button" @click="showPreview = true"><Eye class="h-4 w-4" />Aperçu</Button>
                <Button variant="outline" type="button" @click="leaveEditor">{{ isArchivedTemplate ? 'Retour' : 'Annuler' }}</Button>
                <Button variant="outline" type="button" :aria-expanded="showFiche" @click="showFiche = true">
                    <PanelRightOpen class="h-4 w-4" />Fiche du modèle
                    <span :class="cn('rounded-full px-1.5 text-[10px] font-bold', stepsDone === steps.length ? 'bg-emerald-600 text-white' : 'bg-amber-500 text-white')">{{ stepsDone }}/{{ steps.length }}</span>
                </Button>
                <Button v-if="! isArchivedTemplate" type="button" :disabled="form.processing || (ficheMissing.length === 0 && missing.length > 0)" :title="missing.length ? `À compléter : ${missing.join(', ')}` : ''" @click="submit">
                    <Save class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : isEditing ? 'Enregistrer' : 'Créer le modèle' }}
                </Button>
            </div>
        </header>

        <p v-if="isArchivedTemplate" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-300">Ce modèle est archivé : il s’affiche en lecture seule. Restaurez-le depuis la liste pour le modifier.</p>
        <p v-if="form.errors.site_code" class="rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-xs text-red-700 dark:border-red-900 dark:bg-red-950/20 dark:text-red-300">{{ form.errors.site_code }}</p>

        <!-- Avancement -->
        <ol class="flex flex-wrap items-center gap-2" aria-label="Avancement de la fiche">
            <li v-for="(step, index) in steps" :key="step.key">
                <button
                    type="button"
                    :class="cn('inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold transition hover:border-primary/50', step.done ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300' : 'border-border bg-card text-muted-foreground')"
                    :title="step.key === 'texte' ? 'Le texte s’écrit sur la feuille' : 'Ouvrir la fiche du modèle'"
                    @click="step.key === 'texte' ? (startMode === 'choose' ? null : editor?.commands.focus()) : (showFiche = true)"
                >
                    <span :class="cn('grid h-4 w-4 place-items-center rounded-full text-[10px]', step.done ? 'bg-emerald-600 text-white' : 'bg-muted text-foreground')"><Check v-if="step.done" class="h-3 w-3" /><template v-else>{{ index + 1 }}</template></span>
                    {{ step.label }}
                </button>
            </li>
            <li v-if="ficheMissing.length && ! isArchivedTemplate" class="text-xs text-muted-foreground">
                À compléter : {{ ficheMissing.join(', ') }} —
                <button type="button" class="font-semibold text-primary hover:underline" @click="showFiche = true">ouvrir la fiche</button>
            </li>
        </ol>


            <!-- Comment commencer : importer un fichier, ou écrire directement -->
            <section v-if="startMode !== 'write'" class="rounded-xl border border-border bg-card p-4 sm:p-6">
                <h2 class="text-base font-bold text-foreground">Comment voulez-vous commencer ?</h2>
                <p class="mt-1 text-sm text-muted-foreground">Le texte du modèle est imprimé tel quel après la page 1 du RH. Vous pourrez toujours importer ou retoucher ensuite.</p>
                <div class="mt-4 grid gap-3 md:grid-cols-2" role="radiogroup" aria-label="Façon de commencer">
                    <button
                        type="button"
                        role="radio"
                        :aria-checked="startMode === 'import'"
                        :class="cn('flex items-start gap-3 rounded-xl border p-4 text-start transition', startMode === 'import' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border hover:border-primary/40')"
                        @click="startMode = 'import'"
                    >
                        <span :class="cn('mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full border-2', startMode === 'import' ? 'border-primary' : 'border-input')"><span v-if="startMode === 'import'" class="h-2.5 w-2.5 rounded-full bg-primary" /></span>
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300"><FileUp class="h-5 w-5" /></span>
                        <span>
                            <span class="block text-sm font-bold text-foreground">Importer un fichier Word ou PDF</span>
                            <span class="mt-0.5 block text-xs text-muted-foreground">Un document existant (.docx ou .pdf) : une page du modèle par page du fichier.</span>
                        </span>
                    </button>
                    <button
                        type="button"
                        role="radio"
                        :aria-checked="false"
                        class="flex items-start gap-3 rounded-xl border border-border p-4 text-start transition hover:border-primary/40"
                        @click="startWriting"
                    >
                        <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full border-2 border-input" />
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300"><PenLine class="h-5 w-5" /></span>
                        <span>
                            <span class="block text-sm font-bold text-foreground">Créer et écrire directement</span>
                            <span class="mt-0.5 block text-xs text-muted-foreground">Une feuille blanche, avec la barre de mise en forme d’un traitement de texte.</span>
                        </span>
                    </button>
                </div>

                <div
                    v-if="startMode === 'import'"
                    :class="cn('mt-4 flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-10 text-center transition', dropActive ? 'border-primary bg-primary/5' : 'border-input bg-muted/30')"
                    @dragover.prevent="dropActive = true"
                    @dragleave.prevent="dropActive = false"
                    @drop.prevent="handleDrop"
                >
                    <Upload class="h-8 w-8 text-muted-foreground" />
                    <p class="text-sm font-semibold text-foreground">Déposez le fichier ici</p>
                    <p class="text-xs text-muted-foreground">ou</p>
                    <Button type="button" @click="triggerImport"><FileUp class="h-4 w-4" />Choisir un fichier .docx ou .pdf</Button>
                    <p class="text-[11px] text-muted-foreground">Un PDF ne rend que le texte, sans mise en forme.</p>
                </div>
                <p v-if="importNotice && startMode === 'import'" :class="cn('mt-3 rounded-lg px-3 py-2 text-xs', importNotice.tone === 'error' ? 'bg-red-50 text-red-700 dark:bg-red-950/20 dark:text-red-300' : 'bg-amber-50 text-amber-800 dark:bg-amber-950/20 dark:text-amber-300')" role="status">{{ importNotice.text }}</p>
            </section>

            <!-- Le texte -->
            <section v-show="startMode === 'write'" class="min-w-0 overflow-hidden rounded-xl border border-border bg-card">
                <div class="flex flex-wrap items-center gap-2 border-b border-border px-3 py-2">
                    <!-- Les pages : un clic ouvre, un double-clic (ou le crayon) renomme sur place -->
                    <ol class="flex min-w-0 flex-1 flex-wrap items-center gap-1" aria-label="Pages du modèle">
                        <li v-for="(page, index) in pages" :key="page.id" class="inline-flex items-center">
                            <span v-if="isRenaming(page, 'tab')" class="inline-flex items-center gap-1 rounded-md border border-primary bg-card px-1.5 py-0.5">
                                <span class="text-[10px] font-bold text-muted-foreground">{{ index + 1 }}</span>
                                <input
                                    v-model="renameDraft"
                                    :data-page-rename="`${page.id}-tab`"
                                    :maxlength="PAGE_NAME_MAX"
                                    :placeholder="`Page ${index + 1}`"
                                    class="w-36 bg-transparent text-xs font-semibold text-foreground outline-none"
                                    :aria-label="`Nom de la page ${index + 1}`"
                                    @keydown.enter.prevent="commitRename"
                                    @keydown.esc.prevent="cancelRename"
                                    @blur="commitRename"
                                >
                            </span>
                            <button
                                v-else
                                type="button"
                                :aria-current="page.id === activePageId ? 'page' : undefined"
                                :title="isArchivedTemplate ? pageLabel(page, index) : 'Double-clic pour renommer'"
                                :class="cn('group inline-flex max-w-[14rem] items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-semibold transition', page.id === activePageId ? 'border-primary bg-primary/5 text-foreground' : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground')"
                                @click="selectPage(page.id)"
                                @dblclick="startRename(page, 'tab')"
                            >
                                <span :class="cn('grid h-4 min-w-4 place-items-center rounded px-0.5 text-[10px]', page.id === activePageId ? 'bg-primary text-primary-foreground' : 'bg-muted text-foreground')">{{ index + 1 }}</span>
                                <span class="truncate">{{ pageLabel(page, index) }}</span>
                            </button>
                            <button v-if="! isRenaming(page, 'tab') && page.id === activePageId && ! isArchivedTemplate" type="button" class="ms-0.5 inline-grid h-6 w-6 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground" :aria-label="`Renommer ${pageLabel(page, index)}`" title="Renommer la page" @click="startRename(page, 'tab')"><Pencil class="h-3 w-3" /></button>
                        </li>
                        <li v-if="! isArchivedTemplate">
                            <button type="button" class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-muted-foreground hover:bg-accent hover:text-foreground" title="Ajouter une page" aria-label="Ajouter une page" @click="addPage"><Plus class="h-3.5 w-3.5" />Page</button>
                        </li>
                    </ol>
                    <label class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Maximize2 class="h-3.5 w-3.5" /><span class="sr-only">Zoom</span>
                        <select v-model="zoomChoice" class="toolbar-select" aria-label="Zoom de la feuille">
                            <option v-for="option in zoomOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                    </label>
                    <Button v-if="! isArchivedTemplate" variant="outline" size="sm" type="button" title="Importer un fichier Word (.docx) ou PDF dans la page active" @click="triggerImport"><Upload class="h-4 w-4" />Importer Word ou PDF</Button>
                    <input ref="importInput" type="file" accept=".docx,.pdf" class="hidden" @change="handleImportFile">
                </div>
                <div v-if="editor && ! isArchivedTemplate" class="flex flex-wrap items-center gap-1 border-b border-border bg-muted/50 p-2">
                    <select class="toolbar-select" aria-label="Police" @change="$event.target.value ? editor.chain().focus().setFontFamily($event.target.value).run() : editor.chain().focus().unsetFontFamily().run()">
                        <option value="">Police</option>
                        <option v-for="font in fontFamilies" :key="font" :value="font">{{ font }}</option>
                    </select>
                    <select class="toolbar-select" aria-label="Taille" @change="$event.target.value ? editor.chain().focus().setFontSize($event.target.value).run() : editor.chain().focus().unsetFontSize().run()">
                        <option value="">Taille</option>
                        <option v-for="size in fontSizes" :key="size" :value="size">{{ size }}</option>
                    </select>
                    <input type="color" title="Couleur du texte" aria-label="Couleur du texte" class="toolbar-color" @input="editor.chain().focus().setColor($event.target.value).run()">
                    <input type="color" title="Surlignage" aria-label="Surlignage" class="toolbar-color" @input="editor.chain().focus().toggleHighlight({ color: $event.target.value }).run()">
                    <span class="toolbar-sep" />
                    <button type="button" :class="['toolbar-btn', isActive('heading', { level: 1 }) && 'toolbar-btn-active']" title="Titre 1" @click="editor.chain().focus().toggleHeading({ level: 1 }).run()">H1</button>
                    <button type="button" :class="['toolbar-btn', isActive('heading', { level: 2 }) && 'toolbar-btn-active']" title="Titre 2" @click="editor.chain().focus().toggleHeading({ level: 2 }).run()">H2</button>
                    <button type="button" :class="['toolbar-btn', isActive('heading', { level: 3 }) && 'toolbar-btn-active']" title="Sous-titre" @click="editor.chain().focus().toggleHeading({ level: 3 }).run()">H3</button>
                    <button type="button" :class="['toolbar-btn', isActive('heading', { level: 4 }) && 'toolbar-btn-active']" title="Sous-titre 2" @click="editor.chain().focus().toggleHeading({ level: 4 }).run()">H4</button>
                    <button type="button" :class="['toolbar-btn', isActive('paragraph') && 'toolbar-btn-active']" title="Paragraphe" @click="editor.chain().focus().setParagraph().run()">¶</button>
                    <span class="toolbar-sep" />
                    <button type="button" :class="['toolbar-btn font-bold', isActive('bold') && 'toolbar-btn-active']" title="Gras" @click="editor.chain().focus().toggleBold().run()">G</button>
                    <button type="button" :class="['toolbar-btn italic', isActive('italic') && 'toolbar-btn-active']" title="Italique" @click="editor.chain().focus().toggleItalic().run()">I</button>
                    <button type="button" :class="['toolbar-btn underline', isActive('underline') && 'toolbar-btn-active']" title="Souligné" @click="editor.chain().focus().toggleUnderline().run()">S</button>
                    <button type="button" :class="['toolbar-btn line-through', isActive('strike') && 'toolbar-btn-active']" title="Barré" @click="editor.chain().focus().toggleStrike().run()">B</button>
                    <button type="button" class="toolbar-btn text-[10px]" title="MAJUSCULES" @click="transformCase('upper')">AB</button>
                    <button type="button" class="toolbar-btn text-[10px]" title="minuscules" @click="transformCase('lower')">ab</button>
                    <span class="toolbar-sep" />
                    <button type="button" :class="['toolbar-btn', isActive({ textAlign: 'left' }) && 'toolbar-btn-active']" title="Aligner à gauche" @click="editor.chain().focus().setTextAlign('left').run()">⟸</button>
                    <button type="button" :class="['toolbar-btn', isActive({ textAlign: 'center' }) && 'toolbar-btn-active']" title="Centrer" @click="editor.chain().focus().setTextAlign('center').run()">⟺</button>
                    <button type="button" :class="['toolbar-btn', isActive({ textAlign: 'right' }) && 'toolbar-btn-active']" title="Aligner à droite" @click="editor.chain().focus().setTextAlign('right').run()">⟹</button>
                    <button type="button" :class="['toolbar-btn', isActive({ textAlign: 'justify' }) && 'toolbar-btn-active']" title="Justifier" @click="editor.chain().focus().setTextAlign('justify').run()">≡</button>
                    <span class="toolbar-sep" />
                    <button type="button" :class="['toolbar-btn', isActive('bulletList') && 'toolbar-btn-active']" title="Liste à puces" @click="editor.chain().focus().toggleBulletList().run()">• Liste</button>
                    <button type="button" :class="['toolbar-btn', isActive('orderedList') && 'toolbar-btn-active']" title="Liste numérotée" @click="editor.chain().focus().toggleOrderedList().run()">1. Liste</button>
                    <button type="button" class="toolbar-btn" title="Diminuer le retrait" @click="changeIndent(-1)">⇤</button>
                    <button type="button" class="toolbar-btn" title="Augmenter le retrait" @click="changeIndent(1)">⇥</button>
                    <span class="toolbar-sep" />
                    <select class="toolbar-select" title="Interligne" aria-label="Interligne" @change="setLineHeight($event.target.value)">
                        <option value="">Interligne</option>
                        <option v-for="value in lineHeights" :key="value" :value="value">{{ value }}</option>
                    </select>
                    <select class="toolbar-select" title="Espacement avant" aria-label="Espacement avant" @change="setSpacingBefore($event.target.value)">
                        <option value="" disabled selected>Avant ¶</option>
                        <option v-for="spacing in spacings" :key="spacing.value" :value="spacing.value">{{ spacing.label }}</option>
                    </select>
                    <select class="toolbar-select" title="Espacement après" aria-label="Espacement après" @change="setSpacingAfter($event.target.value)">
                        <option value="" disabled selected>Après ¶</option>
                        <option v-for="spacing in spacings" :key="spacing.value" :value="spacing.value">{{ spacing.label }}</option>
                    </select>
                    <span class="toolbar-sep" />
                    <button type="button" class="toolbar-btn" title="Insérer un tableau" @click="insertTable">Tableau</button>
                    <button type="button" class="toolbar-btn" title="Fusionner les cellules" @click="editor.chain().focus().mergeCells().run()">Fusionner</button>
                    <button type="button" class="toolbar-btn" title="Scinder la cellule" @click="editor.chain().focus().splitCell().run()">Scinder</button>
                    <button type="button" class="toolbar-btn" title="Ligne de séparation" @click="editor.chain().focus().setHorizontalRule().run()">―</button>
                    <button type="button" class="toolbar-btn" title="Insérer une image" aria-label="Insérer une image" @click="insertImage"><Image class="h-4 w-4" /></button>
                    <button type="button" class="toolbar-btn" title="Bloc signature" @click="insertSignatureBlock">Signatures</button>
                    <span class="toolbar-sep" />
                    <button type="button" class="toolbar-btn" title="Annuler" aria-label="Annuler la frappe" @click="editor.chain().focus().undo().run()"><RotateCcw class="h-4 w-4" /></button>
                    <button type="button" class="toolbar-btn" title="Rétablir" aria-label="Rétablir" @click="editor.chain().focus().redo().run()"><Redo2 class="h-4 w-4" /></button>
                </div>
                <div v-if="importNotice" :class="cn('flex items-start justify-between gap-3 border-b px-3 py-2 text-xs', importNotice.tone === 'error' ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/20 dark:text-red-300' : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-300')" role="status">
                    <span>{{ importNotice.text }}</span>
                    <button type="button" class="shrink-0 opacity-70 hover:opacity-100" aria-label="Fermer" @click="importNotice = null"><X class="h-4 w-4" /></button>
                </div>
                <div v-if="pageOverflows" class="flex flex-wrap items-center justify-between gap-2 border-b border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950/20 dark:text-red-300" role="status">
                    <span class="inline-flex items-center gap-1.5"><TriangleAlert class="h-3.5 w-3.5 shrink-0" />Le texte dépasse la feuille A4 : à l’impression, la fin passera sur une page de plus. Déplacez-la sur une nouvelle page, ou resserrez le texte.</span>
                    <Button v-if="! isArchivedTemplate" size="xs" variant="outline" type="button" @click="addPage"><Plus class="h-3.5 w-3.5" />Nouvelle page</Button>
                </div>
                <!-- La feuille A4 réelle : 21 × 29,7 cm, marges de l'impression -->
                <div ref="paperViewport" class="paper-viewport bg-muted/40 px-3 py-6 sm:px-6">
                    <div class="a4-sheet" :class="{ 'a4-sheet--overflow': pageOverflows }" :style="{ zoom }">
                        <span class="a4-sheet__label">{{ activePage ? pageLabel(activePage, activePageIndex) : '' }} · {{ activePageIndex + 1 }} / {{ pages.length }}</span>
                        <div ref="pageBody" class="a4-sheet__body">
                            <div ref="editorHost">
                                <EditorContent :editor="editor" class="canevas-editor-content" />
                            </div>
                        </div>
                        <span class="a4-sheet__limit" aria-hidden="true">Fin de la zone imprimable</span>
                    </div>
                    <p class="mt-3 text-center text-[11px] text-muted-foreground">Format A4 · marges 2,5 cm en haut et en bas, 2 cm sur les côtés · imprimé tel quel après la page 1 du RH — écrivez un document final, sans code de variable.</p>
                </div>
            </section>

        <!-- La fiche du modèle : rangée sur le bord droit, la feuille garde toute la largeur -->
        <Sheet
            :open="showFiche"
            title="Fiche du modèle"
            description="Où le RH le retrouve, ce qu’il verra en page 1, son nom et l’ordre des pages."
            content-class="max-w-md"
            body-class="space-y-4 bg-muted/20"
            @update:open="(value) => { showFiche = value; }"
        >
            <Card class="space-y-3 p-4">
                <h2 class="flex items-center gap-2 text-sm font-bold text-foreground"><span class="grid h-6 w-6 place-items-center rounded-full bg-primary/10 text-xs text-primary">1</span>Dossier</h2>
                <p class="text-xs text-muted-foreground">Où le RH le retrouvera. Un dossier connu règle d’office les données reprises.</p>
                <div class="grid grid-cols-2 gap-1.5" role="radiogroup" aria-label="Dossier du modèle">
                    <button
                        v-for="family in families"
                        :key="family.key"
                        type="button"
                        role="radio"
                        :aria-checked="folder === family.key"
                        :disabled="isArchivedTemplate"
                        :class="cn('flex items-center gap-2 rounded-lg border px-2 py-1.5 text-start text-xs font-semibold transition disabled:cursor-not-allowed', folder === family.key ? 'border-primary bg-primary/5 text-foreground ring-1 ring-primary' : 'border-border text-muted-foreground hover:border-primary/40 hover:text-foreground')"
                        @click="chooseFolder(family.key)"
                    >
                        <span :class="cn('grid h-6 w-6 shrink-0 place-items-center rounded-md', TONE_CLASSES[familyTone(family.key)] ?? TONE_CLASSES.slate)"><Folder class="h-3.5 w-3.5" /></span>
                        <span class="truncate">{{ family.label }}</span>
                    </button>
                    <button
                        type="button"
                        role="radio"
                        :aria-checked="folder === CUSTOM_FOLDER"
                        :disabled="isArchivedTemplate"
                        :class="cn('flex items-center gap-2 rounded-lg border border-dashed px-2 py-1.5 text-start text-xs font-semibold transition disabled:cursor-not-allowed', folder === CUSTOM_FOLDER ? 'border-primary bg-primary/5 text-foreground ring-1 ring-primary' : 'border-input text-muted-foreground hover:border-primary/40 hover:text-foreground')"
                        @click="chooseFolder(CUSTOM_FOLDER)"
                    >
                        <span class="grid h-6 w-6 shrink-0 place-items-center rounded-md bg-muted text-muted-foreground"><FolderPen class="h-3.5 w-3.5" /></span>
                        <span class="truncate">Autre type…</span>
                    </button>
                </div>
                <FormField v-if="folder === CUSTOM_FOLDER" label="Nom du type" required hint="crée son propre dossier" :error="form.errors.document_type" :icon="Tag">
                    <Input v-model="customType" maxlength="80" placeholder="Ex. NOTE DE SERVICE" class="uppercase" :disabled="isArchivedTemplate" />
                </FormField>
                <p v-else-if="form.errors.document_type" class="text-xs text-red-600">{{ form.errors.document_type }}</p>
            </Card>

            <Card class="space-y-3 p-4">
                <h2 class="flex items-center gap-2 text-sm font-bold text-foreground"><span class="grid h-6 w-6 place-items-center rounded-full bg-primary/10 text-xs text-primary">2</span>Données reprises</h2>
                <FormField label="Page 1 remplie depuis" required :error="form.errors.data_context" :icon="Database">
                    <Select v-model="form.data_context" :options="contextOptions" placeholder="Choisissez d’abord un dossier…" :disabled="isArchivedTemplate" class="w-full" />
                </FormField>
                <div v-if="selectedContext" class="space-y-2 rounded-lg bg-muted/40 p-3">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Le RH verra en page 1, modifiable</p>
                    <div class="flex flex-wrap gap-1">
                        <span v-for="field in selectedContext.fields" :key="field" class="rounded-md border border-border bg-card px-1.5 py-0.5 text-[11px] text-foreground">{{ field }}</span>
                    </div>
                    <p class="text-[11px] text-muted-foreground">Proposé au RH depuis : {{ selectedContext.offered_from.join(' · ') }}</p>
                </div>
                <p v-if="contextMismatch" class="flex items-start gap-1.5 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200" role="status">
                    <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />Ce dossier attend « {{ contextLabel(contextMismatch) }} » : sinon les dates ne sont pas reprises, et le modèle n’est pas proposé à l’impression.
                </p>
            </Card>

            <Card class="space-y-3 p-4">
                <h2 class="flex items-center gap-2 text-sm font-bold text-foreground"><span class="grid h-6 w-6 place-items-center rounded-full bg-primary/10 text-xs text-primary">3</span>Identification</h2>
                <FormField label="Nom du modèle" required :error="form.errors.name" :icon="FileText">
                    <Input v-model="form.name" maxlength="255" placeholder="Ex. Contrat à durée déterminée" :disabled="isArchivedTemplate" />
                </FormField>
                <FormField label="Description" hint="(facultatif)" :error="form.errors.description">
                    <Textarea v-model="form.description" rows="2" maxlength="2000" placeholder="À quoi sert ce modèle, pour qui…" :disabled="isArchivedTemplate" />
                </FormField>
                <label class="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2.5">
                    <span>
                        <span class="block text-sm font-semibold text-foreground">Proposé au RH</span>
                        <span class="block text-xs text-muted-foreground">Inactif, il reste ici mais le RH ne le voit pas.</span>
                    </span>
                    <Switch v-model="form.active" :disabled="isArchivedTemplate" />
                </label>
            </Card>

            <Card class="space-y-2 p-4">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="flex items-center gap-2 text-sm font-bold text-foreground"><span class="grid h-6 w-6 place-items-center rounded-full bg-primary/10 text-xs text-primary">4</span>Pages · {{ pages.length }}</h2>
                    <Button v-if="! isArchivedTemplate" variant="outline" size="xs" type="button" @click="addPage"><Plus class="h-3.5 w-3.5" />Page</Button>
                </div>
                <ol class="space-y-1.5">
                    <li v-for="(page, index) in pages" :key="page.id" :class="cn('rounded-lg border p-2 transition', page.id === activePageId ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40')">
                        <div v-if="isRenaming(page, 'fiche')" class="flex items-center gap-1.5">
                            <span class="text-[10px] font-bold text-muted-foreground">{{ index + 1 }}</span>
                            <Input
                                v-model="renameDraft"
                                :data-page-rename="`${page.id}-fiche`"
                                :maxlength="PAGE_NAME_MAX"
                                :placeholder="`Page ${index + 1}`"
                                class="h-7 text-xs"
                                :aria-label="`Nom de la page ${index + 1}`"
                                @keydown.enter.prevent="commitRename"
                                @keydown.esc.prevent.stop="cancelRename"
                                @blur="commitRename"
                            />
                        </div>
                        <div v-else class="flex items-start gap-1">
                            <button type="button" class="block min-w-0 flex-1 text-start" @click="selectPage(page.id); startMode = 'write'; showFiche = false" @dblclick.stop="startRename(page, 'fiche')">
                                <span class="block truncate text-xs font-semibold text-foreground"><span class="me-1 text-muted-foreground">{{ index + 1 }}.</span>{{ pageLabel(page, index) }}</span>
                                <span class="block truncate text-[11px] text-muted-foreground">{{ pageExcerpt(page) }}</span>
                            </button>
                            <Button v-if="! isArchivedTemplate" variant="ghost" size="icon" type="button" class="h-6 w-6 shrink-0" :aria-label="`Renommer ${pageLabel(page, index)}`" title="Renommer" @click="startRename(page, 'fiche')"><Pencil class="h-3.5 w-3.5" /></Button>
                        </div>
                        <div v-if="! isArchivedTemplate" class="mt-1 flex items-center justify-end gap-0.5">
                            <Button variant="ghost" size="icon" type="button" class="h-6 w-6" :disabled="index === 0" :aria-label="`Monter la page ${index + 1}`" title="Monter" @click="movePage(page.id, -1)"><ChevronUp class="h-3.5 w-3.5" /></Button>
                            <Button variant="ghost" size="icon" type="button" class="h-6 w-6" :disabled="index === pages.length - 1" :aria-label="`Descendre la page ${index + 1}`" title="Descendre" @click="movePage(page.id, 1)"><ChevronDown class="h-3.5 w-3.5" /></Button>
                            <Button variant="ghost" size="icon" type="button" class="h-6 w-6" :aria-label="`Dupliquer la page ${index + 1}`" title="Dupliquer" @click="duplicatePage(page.id)"><Copy class="h-3.5 w-3.5" /></Button>
                            <Button variant="ghost" size="icon" type="button" class="h-6 w-6 hover:text-destructive" :disabled="pages.length <= 1" :aria-label="`Supprimer la page ${index + 1}`" title="Supprimer" @click="pageToDelete = page.id"><Trash2 class="h-3.5 w-3.5" /></Button>
                        </div>
                    </li>
                </ol>
            </Card>

            <template #footer>
                <div class="me-auto min-w-0 space-y-0.5 text-xs text-muted-foreground">
                    <p v-if="! isArchivedTemplate" class="flex items-center gap-1.5"><Check class="h-3.5 w-3.5 shrink-0 text-emerald-600" />Gardé automatiquement — envoyé au site par « {{ isEditing ? 'Enregistrer' : 'Créer le modèle' }} ».</p>
                    <p v-if="missing.length && ! isArchivedTemplate" class="flex items-start gap-1.5"><Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />À compléter : {{ missing.join(', ') }}.</p>
                </div>
                <Button v-if="! isArchivedTemplate" variant="outline" type="button" :disabled="! ficheChanged" :title="ficheChanged ? 'Remettre la fiche comme à l’ouverture' : 'Aucune modification depuis l’ouverture'" @click="cancelFiche"><RotateCcw class="h-4 w-4" />Annuler</Button>
            </template>
        </Sheet>

        <!-- Aperçu -->
        <Dialog :open="showPreview" :title="`Aperçu · ${pages.length} page${pages.length > 1 ? 's' : ''}`" description="Le texte seul : la page 1 (informations de la personne) est ajoutée par le RH à la génération. Pour un aperçu avec une vraie personne, utilisez « Générer un document » du RH du site." size="xl" @update:open="(value) => { showPreview = value; }">
            <div class="space-y-6 bg-muted/40 p-4">
                <div v-for="(page, index) in previewPages" :key="page.id">
                    <p class="mb-1.5 text-center text-[11px] font-semibold text-muted-foreground">{{ page.label }} · {{ index + 1 }} / {{ previewPages.length }}</p>
                    <div class="a4-sheet a4-sheet--preview" :style="{ zoom: 0.8 }"><div class="canevas-document" v-html="page.html" /></div>
                </div>
            </div>
        </Dialog>

        <!-- Versions -->
        <Dialog :open="showHistory" title="Versions du modèle" description="Enregistrer un modèle déjà utilisé crée une nouvelle version : les documents déjà produits restent attachés à celle qui les a produits." size="lg" @update:open="(value) => { showHistory = value; }">
            <div v-if="historyLoading" class="flex items-center gap-2 py-8 text-sm text-muted-foreground"><LoaderCircle class="h-4 w-4 animate-spin" />Chargement…</div>
            <p v-else-if="historyError" class="py-8 text-center text-sm text-red-600">{{ historyError }}</p>
            <ol v-else class="space-y-2">
                <li v-for="version in historyVersions" :key="version.uuid" class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border p-3">
                    <div class="min-w-0">
                        <Badge :variant="version.archived ? 'secondary' : 'success'">{{ version.archived ? 'Version précédente' : 'Version actuelle' }}</Badge>
                        <p class="mt-1 text-sm font-semibold text-foreground">{{ version.name }}</p>
                        <p class="text-xs text-muted-foreground">{{ version.creator || 'Auteur inconnu' }} · {{ formatDateTime(version.created_at) }}</p>
                        <p v-if="version.archive_reason" class="mt-1 text-xs text-muted-foreground">{{ version.archive_reason }}</p>
                        <p v-if="version.generated_documents_count" class="mt-1 text-xs text-muted-foreground">{{ version.generated_documents_count }} document(s) produit(s) avec cette version</p>
                    </div>
                    <Button v-if="version.archived" size="sm" variant="outline" type="button" @click="requestRevert(version)"><RotateCcw class="h-4 w-4" />Revenir à cette version</Button>
                </li>
            </ol>
        </Dialog>

        <Dialog
            :open="Boolean(revertTarget)"
            title="Revenir à cette version ?"
            :description="revertTarget ? `« ${revertTarget.name} » du ${formatDateTime(revertTarget.created_at)} redevient la version active. La version actuelle est archivée, jamais supprimée.` : ''"
            :dismissible="false"
            @update:open="(value) => { if (! value) revertTarget = null; }"
        >
            <FormField label="Motif" required>
                <Textarea v-model="revertReason" rows="3" maxlength="1000" placeholder="Ex. la nouvelle version comportait une erreur…" />
            </FormField>
            <template #footer>
                <Button variant="outline" type="button" @click="revertTarget = null">Retour</Button>
                <Button type="button" :disabled="reverting || revertReason.trim().length < 3" @click="confirmRevert"><RotateCcw class="h-4 w-4" />{{ reverting ? 'Retour en cours…' : 'Revenir à cette version' }}</Button>
            </template>
        </Dialog>

        <ConfirmModal :open="Boolean(pageToDelete)" title="Supprimer cette page ?" description="Son texte quitte le modèle. Tant que vous n’avez pas enregistré, « Annuler » rend le modèle tel qu’il était." tone="danger" confirm-label="Supprimer la page" @update:open="(value) => { if (! value) pageToDelete = null; }" @confirm="deletePage" />
        <ConfirmModal :open="Boolean(pendingImport)" title="Remplacer le texte de la page ?" :description="importQuestion" tone="warning" confirm-label="Importer" @update:open="(value) => { if (! value) pendingImport = null; }" @confirm="applyImport(pendingImport)" />
        <ConfirmModal :open="confirmLeave" title="Quitter sans enregistrer ?" description="Des modifications du modèle ne sont pas enregistrées : elles seront perdues." tone="warning" confirm-label="Quitter sans enregistrer" cancel-label="Rester" @update:open="(value) => { confirmLeave = value; }" @confirm="leaveWithoutSaving" />
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
    color: hsl(var(--muted-foreground));
    transition: background-color 150ms, color 150ms;
}
.toolbar-btn:hover:not(:disabled) {
    background-color: hsl(var(--accent));
    color: hsl(var(--foreground));
}
.toolbar-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.toolbar-btn-active {
    background-color: hsl(var(--primary) / 0.12);
    color: hsl(var(--primary));
}
.toolbar-select,
.toolbar-color {
    height: 2rem;
    border-radius: 0.375rem;
    border: 1px solid hsl(var(--border));
    background-color: hsl(var(--card));
    color: hsl(var(--foreground));
}
.toolbar-select {
    padding: 0 1.75rem 0 0.5rem;
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
    background-color: hsl(var(--border));
}

/* La feuille représente du papier imprimé : toujours blanche, texte foncé, dans les
   deux thèmes (comme Print.vue). A4 réel : 210 × 297 mm, marges de l'impression. */
.paper-viewport {
    overflow-x: auto;
}
.a4-sheet {
    position: relative;
    box-sizing: border-box;
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    padding: 25mm 20mm;
    background-color: white;
    color: rgb(15 23 42);
    box-shadow: 0 1px 3px rgb(15 23 42 / 0.12), 0 8px 24px rgb(15 23 42 / 0.08);
}
.a4-sheet__label {
    position: absolute;
    top: 9mm;
    left: 20mm;
    right: 20mm;
    font-size: 10px;
    font-weight: 600;
    color: rgb(148 163 184);
    user-select: none;
}
.a4-sheet__body {
    height: 247mm;
}
.a4-sheet__limit {
    position: absolute;
    left: 0;
    right: 0;
    top: calc(297mm - 25mm);
    border-top: 1px dashed rgb(203 213 225);
    padding: 2px 20mm 0;
    text-align: right;
    font-size: 9px;
    color: rgb(148 163 184);
    pointer-events: none;
    user-select: none;
}
.a4-sheet--overflow .a4-sheet__limit {
    border-top-color: rgb(239 68 68);
    color: rgb(220 38 38);
}
.a4-sheet--preview {
    min-height: 297mm;
}
.canevas-document {
    background-color: white;
    color: rgb(15 23 42);
}
:deep(.ProseMirror) {
    outline: none;
    min-height: 247mm;
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
