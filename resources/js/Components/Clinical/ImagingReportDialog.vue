<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Bold, CircleCheck, Eye, FilePlus2, FileText, Highlighter, Italic, List, ListOrdered, PenLine, Pencil, Pin, PinOff, Plus, TriangleAlert, Trash2, Underline } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import ClinicalRichTextEditor from '@/Components/Clinical/ClinicalRichTextEditor.vue';
import ImagingReportDocument from '@/Components/Medicine/ImagingReportDocument.vue';
import FormError from '@/Components/UI/FormError.vue';

/**
 * La saisie d'un compte rendu d'imagerie — une seule, où qu'on l'ouvre.
 *
 * Elle existait en deux exemplaires : une fenêtre complète dans « Demandes
 * d'examens » (feuilles de la clinique, observations, ADR-108) et un éditeur
 * réduit dans la consultation, sans feuilles. Deux outils pour écrire la même
 * colonne finissent par produire deux comptes rendus différents pour le même
 * type d'examen. Les deux écrans ouvrent désormais ce composant.
 *
 * Le serveur reste l'unique garde : il refuse un second compte rendu sur le
 * même examen (`RecordImagingResultAction`). Corriger un compte rendu déjà
 * enregistré est un autre acte (ADR-130) : la même fenêtre, préremplie, avec
 * son propre droit ; l'ancienne version est conservée, jamais écrasée.
 */
const props = defineProps({
    /** `{ uuid, exam }` de la ligne d'examen, ou `null` fenêtre fermée. */
    item: { type: Object, default: null },
    orientationUuid: { type: String, default: '' },
    /** Qui et quel passage : l'en-tête que le médecin relit avant d'écrire. */
    subtitle: { type: String, default: '' },
    templates: { type: Array, default: () => [] },
    /** `{ create, archive }` : ce que le compte peut faire des feuilles (ADR-108). */
    templateRights: { type: Object, default: () => ({}) },
    /**
     * `record` : première saisie. `correct` : le compte rendu existe déjà ;
     * `item` porte alors `report_raw` et `notes_raw` pour préremplir.
     */
    mode: { type: String, default: 'record' },
});

const emit = defineEmits(['close', 'saved']);

/**
 * Les feuilles de la clinique (ADR-108). Le médecin choisit la sienne : rien
 * n'est déduit du nom de l'examen (ADR-052).
 */
const pendingTemplate = ref(null);

/** La feuille appliquée : sert à l'annoncer, jamais à décider de quoi que ce soit. */
const sheetKey = ref(null);
const notesOpen = ref(false);

// Lus par le `watch` immédiat plus bas : ils doivent exister avant lui.
const previewOpen = ref(false);
const previewError = ref('');
const editOpen = ref(false);

/**
 * La feuille à figer sur le compte rendu : `null` tant qu'on n'y a pas touché
 * (le titre déjà enregistré reste), `FREE` pour une saisie libre, sinon la clé
 * d'une feuille. Le serveur lit le titre lui-même : le navigateur ne dicte
 * jamais l'intitulé d'un document.
 */
const sheetSent = ref(null);

/**
 * La feuille proposée d'office pour cet examen (ADR-108). Réglée, jamais
 * déduite du nom : le serveur la sert avec l'examen. Copie locale, parce que
 * `item` est figé à l'ouverture et ne suit pas le réglage qu'on vient de
 * faire.
 */
const defaultKey = ref(null);

const form = useForm({ result_value: '', result_notes: '', reason: '' });

const correcting = computed(() => props.mode === 'correct');

// Une feuille de la clinique est en deux colonnes séparées par un trait
// pointillé (ADR-108) : le médecin doit savoir qu'il ne s'efface pas.
const hasColumns = computed(() => /<hr\b/i.test(form.result_value ?? ''));

// Le formulaire repart de zéro à chaque ouverture ; en correction, il repart
// du compte rendu enregistré — jamais d'une feuille vide, qui inviterait à
// tout réécrire.
// Une chaîne, pas un tableau : un getter qui renvoie un tableau neuf est
// « changé » à chaque re-rendu de la page, même pour le même examen — et
// remettait le formulaire à zéro dès qu'un rechargement (l'enregistrement
// d'une feuille, par exemple) recréait l'objet `item`.
watch(() => `${props.item?.uuid ?? ''}|${props.mode}`, () => {
    form.reset();
    form.clearErrors();
    pendingTemplate.value = null;
    previewOpen.value = false;
    previewError.value = '';
    editOpen.value = false;
    sheetKey.value = null;
    sheetSent.value = null;

    if (props.item && correcting.value) {
        form.defaults({
            result_value: props.item.report_raw ?? '',
            result_notes: props.item.notes_raw ?? '',
            reason: '',
        });
        form.reset();
    } else {
        form.defaults({ result_value: '', result_notes: '', reason: '' });
        form.reset();

        // Une échographie s'ouvre déjà sur sa feuille : le médecin n'a pas à
        // la chercher. Le champ est vide, rien n'est écrasé ; la liste permet
        // d'en changer.
        const preset = props.item
            ? props.templates.find((template) => template.key === props.item.default_template_key)
            : null;

        if (preset) {
            form.result_value = preset.body_html;
            sheetKey.value = preset.key;
            sheetSent.value = preset.key;
        }
    }

    defaultKey.value = props.item?.default_template_key ?? null;

    // Des observations déjà écrites s'affichent ; sinon la case reste
    // repliée : c'est un complément, pas une étape.
    notesOpen.value = (form.result_notes ?? '').trim() !== '';
}, { immediate: true });

/** Rien à enregistrer tant que rien n'a changé : le bouton le dit. */
const unchanged = computed(() => correcting.value
    && form.result_value === (props.item?.report_raw ?? '')
    && form.result_notes === (props.item?.notes_raw ?? '')
    && sheetSent.value === null);



const hasContent = computed(() => form.result_value
    .replace(/<[^>]*>/g, '')
    .replace(/&nbsp;| /g, ' ')
    .trim() !== '');

const chooseTemplate = (template) => {
    // Une feuille remplace tout le compte rendu : sur un champ déjà écrit,
    // on demande avant, on n'écrase jamais.
    if (hasContent.value) {
        pendingTemplate.value = template;

        return;
    }

    applyTemplate(template);
};

const applyTemplate = (template) => {
    form.result_value = template.body_html;
    sheetKey.value = template.key;
    sheetSent.value = template.key;
    pendingTemplate.value = null;
};

/**
 * Une feuille de la clinique est en deux colonnes suivies de cases pleine
 * largeur (ADR-108) ; le compte rendu les porte séparées par un `<hr>`. Chaque
 * région a son propre éditeur, comme la case du papier : le médecin n'a plus
 * à voir ni à ménager le trait.
 */
const COLUMN_BREAK = '<hr>';
const regions = computed(() => (form.result_value ?? '').split(/<hr\s*\/?>/i));
const sheetMode = computed(() => regions.value.length >= 2);
const wideIndexes = computed(() => regions.value.slice(2).map((_, offset) => offset + 2));

const setRegion = (index, html) => {
    const next = [...regions.value];
    next[index] = html;
    form.result_value = next.join(COLUMN_BREAK);
};

const CURRENT_SHEET = '__current__';
const sheetGroups = computed(() => {
    const entry = (template) => ({ value: template.key, label: template.label });

    return [
        { label: 'Feuilles de la clinique', items: props.templates.filter((template) => !template.custom && template.validated).map(entry) },
        // Écrites par le système faute de modèle papier : à faire valider.
        { label: 'Propositions à valider', items: props.templates.filter((template) => !template.custom && !template.validated).map(entry) },
        { label: 'Feuilles du site', items: props.templates.filter((template) => template.custom).map(entry) },
    ].filter((group) => group.items.length > 0);
});

const sheetOptions = computed(() => [
    { value: '', label: 'Texte libre (sans colonnes)' },
    ...(sheetMode.value && !sheetKey.value ? [{ value: CURRENT_SHEET, label: 'Feuille de la clinique (en cours)' }] : []),
    ...sheetGroups.value,
]);
const sheetValue = computed(() => sheetKey.value ?? (sheetMode.value ? CURRENT_SHEET : ''));

const onSheetSelected = (key) => {
    if (key === CURRENT_SHEET || key === sheetValue.value) {
        return;
    }

    if (key === '') {
        // Le texte est conservé tel quel : seules les colonnes disparaissent.
        form.result_value = regions.value.join('');
        sheetKey.value = null;
        sheetSent.value = 'FREE';

        return;
    }

    const template = props.templates.find((candidate) => candidate.key === key);

    if (template) {
        chooseTemplate(template);
    }
};

/**
 * Une seule barre pour toutes les zones : elle agit sur celle qui a le focus.
 * `mousedown.prevent` sur les boutons garde la sélection en place.
 */
const format = (command) => {
    if (command === 'highlight') {
        if (!document.execCommand('hiliteColor', false, '#fef08a')) {
            document.execCommand('backColor', false, '#fef08a');
        }

        return;
    }

    document.execCommand(command);
};

const TOOLS = [
    { command: 'bold', label: 'Gras', icon: Bold },
    { command: 'italic', label: 'Italique', icon: Italic },
    { command: 'underline', label: 'Souligné', icon: Underline },
    { command: 'highlight', label: 'Surligner', icon: Highlighter },
    { command: 'insertUnorderedList', label: 'Liste à puces', icon: List },
    { command: 'insertOrderedList', label: 'Liste numérotée', icon: ListOrdered },
];

/**
 * Créer une feuille depuis ce que le médecin vient d'écrire (ADR-108). Le
 * contenu actuel devient le modèle tel quel : la fenêtre le dit, car le
 * serveur ne peut pas deviner ce qui est propre au patient.
 */
/**
 * L'aperçu : le compte rendu tel qu'il s'imprimera, composé par le serveur —
 * même document que l'impression, identité du patient et signature comprises.
 * Il n'écrit rien ; on l'ouvre avant d'enregistrer, jamais à la place.
 */
const previewLoading = ref(false);
const previewDocument = ref(null);

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const openPreview = async () => {
    if (!props.item || previewLoading.value) {
        return;
    }

    previewLoading.value = true;
    previewError.value = '';

    try {
        const response = await fetch(`/medicine/orientations/${props.orientationUuid}/imaging-requests/${props.item.uuid}/result/preview`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                result_value: form.result_value,
                result_notes: form.result_notes,
                ...(sheetSent.value === null ? {} : { sheet_key: sheetSent.value }),
            }),
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            previewError.value = payload?.errors?.result_value?.[0] ?? payload?.message ?? 'L’aperçu n’a pas pu être composé.';

            return;
        }

        previewDocument.value = payload.document;
        previewOpen.value = true;
    } catch {
        previewError.value = 'L’aperçu n’a pas pu être composé : connexion impossible.';
    } finally {
        previewLoading.value = false;
    }
};

const saveOpen = ref(false);
const archiveOpen = ref(false);
const templateForm = useForm({ name: '', description: '', body_html: '', default_here: true });

const currentTemplate = computed(() => props.templates.find((template) => template.key === sheetKey.value) ?? null);
const canArchiveCurrent = computed(() => Boolean(props.templateRights?.archive && currentTemplate.value?.custom));

const isDefaultSheet = computed(() => sheetKey.value !== null && sheetKey.value === defaultKey.value);

/** Proposer (ou ne plus proposer) la feuille en cours d'office pour cet examen. */
const toggleDefault = () => {
    if (!props.item || sheetKey.value === null) {
        return;
    }

    const next = isDefaultSheet.value ? null : sheetKey.value;

    router.put(`/medicine/imaging-request-items/${props.item.uuid}/default-template`, { template_key: next }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            defaultKey.value = next;
        },
    });
};

const openSave = () => {
    templateForm.reset();
    templateForm.default_here = true;
    templateForm.clearErrors();
    saveOpen.value = true;
};

const saveTemplate = () => {
    const name = templateForm.name.trim();
    const defaultHere = templateForm.default_here;

    if (name === '' || templateForm.processing) {
        return;
    }

    templateForm
        .transform(({ default_here, ...data }) => ({
            ...data,
            name,
            body_html: form.result_value,
            default_for_item_uuid: default_here ? props.item?.uuid : null,
        }))
        .post('/medicine/imaging-report-templates', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                saveOpen.value = false;
                templateForm.reset();

                // La feuille arrive avec les nouvelles props : on la
                // sélectionne, pour que la liste dise d'où vient le texte.
                nextTick(() => {
                    const created = props.templates.find((template) => template.custom && template.label === name);

                    if (created) {
                        sheetKey.value = created.key;
                        // Le serveur l'a réglée d'office pour cet examen si on l'a demandé.
                        if (defaultHere) {
                            defaultKey.value = created.key;
                        }
                    }
                });
            },
        });
};

const isProposal = computed(() => currentTemplate.value !== null && !currentTemplate.value.custom && !currentTemplate.value.validated);
const canEditCurrent = computed(() => Boolean(props.templateRights?.update && currentTemplate.value?.custom));

const editForm = useForm({ name: '', description: '', replace_body: false });

const openEdit = () => {
    const template = currentTemplate.value;

    if (!template) {
        return;
    }

    editForm.defaults({ name: template.label, description: template.custom ? (template.description ?? '') : '', replace_body: false });
    editForm.reset();
    editForm.clearErrors();
    editOpen.value = true;
};

const saveEdit = () => {
    const template = currentTemplate.value;
    const name = editForm.name.trim();

    if (!template?.uuid || name === '' || editForm.processing) {
        return;
    }

    editForm
        .transform(({ replace_body, ...data }) => ({
            ...data,
            name,
            ...(replace_body ? { body_html: form.result_value } : {}),
        }))
        .put(`/medicine/imaging-report-templates/${template.uuid}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                editOpen.value = false;
            },
        });
};

const archiveTemplate = () => {
    const template = currentTemplate.value;

    if (!template?.uuid) {
        return;
    }

    router.delete(`/medicine/imaging-report-templates/${template.uuid}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            archiveOpen.value = false;
            sheetKey.value = null;
        },
    });
};

/** Plafond serveur, mise en forme comprise (`RecordImagingResultRequest`). */
const REPORT_LIMIT = 10000;
const reportLength = computed(() => (form.result_value ?? '').length);

// Le compteur d'une feuille est celui du serveur (le HTML, pas le texte) ;
// en saisie libre l'éditeur affiche déjà le sien, on ne parle qu'à l'approche
// du plafond.
const showLimit = computed(() => sheetMode.value || reportLength.value > REPORT_LIMIT * 0.8);

const close = () => {
    pendingTemplate.value = null;
    previewOpen.value = false;
    emit('close');
};

const submit = () => {
    if (!props.item) {
        return;
    }

    const url = `/medicine/orientations/${props.orientationUuid}/imaging-requests/${props.item.uuid}/result`;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            previewOpen.value = false;
            form.reset();
            emit('saved');
        },
    };

    // Le titre de la feuille suit le compte rendu (instantané côté serveur).
    form.transform((data) => (sheetSent.value === null ? data : { ...data, sheet_key: sheetSent.value }));

    if (correcting.value) {
        form.put(url, options);
    } else {
        form.post(url, options);
    }
};
</script>

<template>
    <Dialog
        :open="item !== null"
        size="wide"
        body-class="max-h-[78vh] overflow-y-auto"
        :dismissible="false"
        :title="item ? `${correcting ? 'Corriger le compte rendu' : 'Compte rendu'} — ${item.exam}` : 'Compte rendu'"
        :description="subtitle"
        @update:open="(value) => value || close()"
    >
        <template #icon><PenLine class="h-5 w-5" /></template>

        <form v-if="item" class="space-y-5" @submit.prevent="submit">
            <section class="space-y-3">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold text-foreground">
                            Compte rendu<span class="ms-0.5 text-destructive">*</span>
                        </h3>
                        <p class="mt-0.5 text-xs leading-5 text-muted-foreground">
                            <template v-if="sheetMode && isDefaultSheet">
                                Feuille proposée d’office pour cet examen : changez-la dans la liste si besoin.
                            </template>
                            <template v-else-if="sheetMode">
                                Comme sur la feuille papier : colonne de gauche, colonne de droite, puis les cases pleine largeur.
                            </template>
                            <template v-else-if="templates.length">
                                Saisie libre, ou partez d’une feuille de la clinique : ses rubriques arrivent dans leurs colonnes.
                            </template>
                            <template v-else>Technique, constatations, conclusion.</template>
                        </p>
                    </div>

                    <div v-if="templates.length" class="flex w-full items-center gap-2 sm:w-auto">
                        <Select
                            class="w-full sm:w-[26rem]"
                            :model-value="sheetValue"
                            :options="sheetOptions"
                            :icon="FileText"
                            aria-label="Feuille de la clinique"
                            @update:model-value="onSheetSelected"
                        />
                        <Button
                            v-if="templateRights.create && sheetKey !== null"
                            type="button"
                            :variant="isDefaultSheet ? 'default' : 'outline'"
                            size="icon"
                            class="h-10 w-10 shrink-0"
                            :title="isDefaultSheet ? 'Ne plus proposer cette feuille d’office pour cet examen' : 'Proposer cette feuille d’office pour cet examen'"
                            :aria-label="isDefaultSheet ? 'Ne plus proposer cette feuille d’office' : 'Proposer cette feuille d’office'"
                            :aria-pressed="isDefaultSheet"
                            @click="toggleDefault"
                        >
                            <component :is="isDefaultSheet ? PinOff : Pin" class="h-4 w-4" />
                        </Button>
                        <Button
                            v-if="templateRights.create"
                            type="button"
                            variant="outline"
                            size="icon"
                            class="h-10 w-10 shrink-0"
                            title="Enregistrer le contenu actuel comme nouvelle feuille"
                            aria-label="Enregistrer comme nouvelle feuille"
                            :disabled="!hasContent"
                            @click="openSave"
                        >
                            <FilePlus2 class="h-4 w-4" />
                        </Button>
                        <Button
                            v-if="canEditCurrent"
                            type="button"
                            variant="outline"
                            size="icon"
                            class="h-10 w-10 shrink-0"
                            title="Modifier cette feuille"
                            aria-label="Modifier cette feuille"
                            @click="openEdit"
                        >
                            <Pencil class="h-4 w-4" />
                        </Button>
                        <Button
                            v-if="canArchiveCurrent"
                            type="button"
                            variant="danger-outline"
                            size="icon"
                            class="h-10 w-10 shrink-0"
                            title="Retirer cette feuille"
                            aria-label="Retirer cette feuille"
                            @click="archiveOpen = true"
                        >
                            <Trash2 class="h-4 w-4" />
                        </Button>
                    </div>
                </div>

                <p
                    v-if="isProposal"
                    class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                >
                    <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    <span>
                        <strong>Proposition du système</strong>, faute de modèle papier pour cet examen : à faire valider par un médecin
                        de la clinique. Corrigez-la ici si besoin, puis « + » pour l’enregistrer comme feuille du site.
                    </span>
                </p>

                <div v-if="sheetMode" class="overflow-hidden rounded-lg border border-border bg-card">
                    <div class="flex flex-wrap items-center gap-0.5 border-b border-border bg-muted/35 px-2 py-1.5" role="toolbar" aria-label="Mise en forme du compte rendu">
                        <Button
                            v-for="tool in TOOLS"
                            :key="tool.command"
                            type="button"
                            variant="ghost"
                            size="icon-xs"
                            :title="tool.label"
                            :aria-label="tool.label"
                            @mousedown.prevent
                            @click="format(tool.command)"
                        >
                            <component :is="tool.icon" class="h-4 w-4" aria-hidden="true" />
                        </Button>
                    </div>

                    <div class="grid divide-border lg:grid-cols-2 lg:divide-x">
                        <div v-for="index in [0, 1]" :key="index" class="space-y-1.5 p-3">
                            <label :for="`imaging_region_${index}`" class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                                {{ index === 0 ? 'Colonne de gauche' : 'Colonne de droite' }}
                            </label>
                            <ClinicalRichTextEditor
                                :id="`imaging_region_${index}`"
                                bare
                                :model-value="regions[index] ?? ''"
                                :max-length="REPORT_LIMIT"
                                min-height-class="min-h-[38vh]"
                                @update:model-value="(html) => setRegion(index, html)"
                            />
                        </div>
                    </div>

                    <div v-for="index in wideIndexes" :key="index" class="space-y-1.5 border-t border-border p-3">
                        <label :for="`imaging_region_${index}`" class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                            Pleine largeur<template v-if="wideIndexes.length > 1"> · {{ index - 1 }}</template>
                        </label>
                        <ClinicalRichTextEditor
                            :id="`imaging_region_${index}`"
                            bare
                            :model-value="regions[index] ?? ''"
                            :max-length="REPORT_LIMIT"
                            min-height-class="min-h-[14vh]"
                            @update:model-value="(html) => setRegion(index, html)"
                        />
                    </div>
                </div>

                <ClinicalRichTextEditor
                    v-else
                    id="imaging_report"
                    v-model="form.result_value"
                    :max-length="REPORT_LIMIT"
                    min-height-class="min-h-[48vh]"
                    placeholder="Technique, constatations, conclusion…"
                />

                <div class="flex items-start justify-between gap-3">
                    <FormError class="mt-0" :message="form.errors.result_value" />
                    <p v-if="showLimit" :class="['ms-auto text-[11px] tabular-nums', reportLength > REPORT_LIMIT ? 'font-semibold text-destructive' : 'text-muted-foreground']">
                        {{ reportLength.toLocaleString('fr-FR') }} / {{ REPORT_LIMIT.toLocaleString('fr-FR') }} caractères, mise en forme comprise
                    </p>
                </div>
            </section>

            <section>
                <Button v-if="!notesOpen" type="button" variant="ghost" size="sm" @click="notesOpen = true">
                    <Plus class="h-4 w-4" />Ajouter des observations complémentaires
                </Button>
                <FormField v-else label="Observations complémentaires" hint="· facultatif" as="div">
                    <ClinicalRichTextEditor
                        id="imaging_notes"
                        v-model="form.result_notes"
                        :max-length="REPORT_LIMIT"
                        min-height-class="min-h-[16vh]"
                        placeholder="Ce que le compte rendu ne porte pas."
                    />
                    <FormError class="mt-1" :message="form.errors.result_notes" />
                </FormField>
            </section>

            <FormField v-if="correcting" label="Motif de la correction" hint="· facultatif">
                <Input
                    id="imaging_reason"
                    v-model="form.reason"
                    maxlength="500"
                    placeholder="« Faute de frappe sur la mesure » est un énoncé complet."
                    autocomplete="off"
                />
                <FormError class="mt-1" :message="form.errors.reason" />
            </FormField>

            <p v-if="correcting" class="text-[11px] leading-4 text-muted-foreground">
                L’ancienne version n’est pas perdue : elle reste consultable dans l’historique du compte rendu, avec son auteur et sa date.
            </p>
            <p v-else class="text-[11px] leading-4 text-muted-foreground">
                La demande cesse d’attendre un résultat et ne peut plus être retirée. Le compte rendu reste corrigeable ensuite, versions conservées.
            </p>
        </form>

        <template #footer>
            <p v-if="previewError" class="me-auto self-center text-xs text-destructive" role="alert">{{ previewError }}</p>
            <Button type="button" variant="white-outline" size="sm" @click="close">Annuler</Button>
            <Button type="button" variant="outline" size="sm" :disabled="!hasContent || previewLoading" @click="openPreview">
                <Eye class="h-4 w-4" />{{ previewLoading ? 'Composition…' : 'Aperçu' }}
            </Button>
            <Button type="button" size="sm" :disabled="form.processing || unchanged" @click="submit">
                <CircleCheck class="h-4 w-4" />{{ correcting ? 'Enregistrer la correction' : 'Enregistrer le compte rendu' }}
            </Button>
        </template>
    </Dialog>

    <Dialog
        :open="previewOpen"
        size="xl"
        body-class="max-h-[74vh] overflow-y-auto bg-muted/40"
        :dismissible="false"
        title="Aperçu du compte rendu"
        description="Tel qu’il sera imprimé. Rien n’est encore enregistré."
        close-label="Retour à la saisie"
        @update:open="(value) => value || (previewOpen = false)"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                <Eye class="h-5 w-5" />
            </span>
        </template>

        <!-- La feuille porte elle-même son fond blanc : c'est du papier, quel que soit le thème. -->
        <div v-if="previewDocument" class="mx-auto max-w-3xl overflow-hidden rounded-lg border border-border shadow-sm">
            <ImagingReportDocument :document="previewDocument" />
        </div>

        <template #footer>
            <Button type="button" variant="outline" @click="previewOpen = false">Retour à la saisie</Button>
            <Button type="button" :disabled="form.processing || unchanged" @click="submit">
                <CircleCheck class="h-4 w-4" />{{ correcting ? 'Enregistrer la correction' : 'Enregistrer le compte rendu' }}
            </Button>
        </template>
    </Dialog>

    <Dialog
        :open="pendingTemplate !== null"
        title="Remplacer le compte rendu ?"
        :description="pendingTemplate?.label ?? ''"
        :dismissible="false"
        close-label="Conserver ma saisie"
        @update:open="pendingTemplate = null"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                <FileText class="h-5 w-5" />
            </span>
        </template>

        <p class="text-xs leading-5 text-muted-foreground">
            Ce compte rendu porte déjà du texte. Insérer cette feuille le remplacera entièrement.
            Rien n’est encore enregistré : vous pouvez revenir en arrière.
        </p>

        <template #footer>
            <Button type="button" variant="outline" @click="pendingTemplate = null">Conserver ma saisie</Button>
            <Button type="button" @click="applyTemplate(pendingTemplate)">
                <FileText class="h-4 w-4" />Insérer la feuille
            </Button>
        </template>
    </Dialog>

    <Dialog
        :open="saveOpen"
        title="Enregistrer comme nouvelle feuille"
        description="Le contenu actuel devient le modèle, colonnes comprises."
        :dismissible="false"
        close-label="Annuler"
        @update:open="(value) => value || (saveOpen = false)"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                <FilePlus2 class="h-5 w-5" />
            </span>
        </template>

        <div class="space-y-4">
            <FormField label="Nom de la feuille" required :error="templateForm.errors.name">
                <Input
                    v-model="templateForm.name"
                    maxlength="120"
                    placeholder="Ex. Échographie thyroïdienne"
                    autocomplete="off"
                    @keydown.enter.prevent="saveTemplate"
                />
            </FormField>
            <FormField label="Description" hint="· facultatif" :error="templateForm.errors.description">
                <Input
                    v-model="templateForm.description"
                    maxlength="255"
                    placeholder="Ce que la feuille couvre, en une ligne."
                    autocomplete="off"
                    @keydown.enter.prevent="saveTemplate"
                />
            </FormField>
            <FormError :message="templateForm.errors.body_html" />

            <label class="flex cursor-pointer items-start gap-2.5 text-sm text-foreground">
                <Checkbox v-model="templateForm.default_here" class="mt-0.5" aria-label="Proposer d’office pour cet examen" />
                <span>
                    Proposer d’office pour « {{ item?.exam }} »
                    <span class="block text-xs text-muted-foreground">Elle s’ouvrira directement à la prochaine saisie de cet examen.</span>
                </span>
            </label>

            <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                Le texte est enregistré <strong>tel quel</strong> et proposé à tous les médecins du site.
                Effacez d’abord ce qui est propre à ce patient : mesures, constatations, conclusion.
            </p>
        </div>

        <template #footer>
            <Button type="button" variant="outline" @click="saveOpen = false">Annuler</Button>
            <Button type="button" :disabled="templateForm.processing || templateForm.name.trim() === ''" @click="saveTemplate">
                <FilePlus2 class="h-4 w-4" />Enregistrer la feuille
            </Button>
        </template>
    </Dialog>

    <Dialog
        :open="editOpen"
        title="Modifier la feuille"
        :description="currentTemplate?.label ?? ''"
        :dismissible="false"
        close-label="Annuler"
        @update:open="(value) => value || (editOpen = false)"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                <Pencil class="h-5 w-5" />
            </span>
        </template>

        <div class="space-y-4">
            <FormField label="Nom de la feuille" required :error="editForm.errors.name">
                <Input v-model="editForm.name" maxlength="120" autocomplete="off" @keydown.enter.prevent="saveEdit" />
            </FormField>
            <FormField label="Description" hint="· facultatif" :error="editForm.errors.description">
                <Input v-model="editForm.description" maxlength="255" autocomplete="off" @keydown.enter.prevent="saveEdit" />
            </FormField>
            <FormError :message="editForm.errors.body_html" />

            <label class="flex cursor-pointer items-start gap-2.5 text-sm text-foreground">
                <Checkbox v-model="editForm.replace_body" class="mt-0.5" aria-label="Remplacer le contenu de la feuille" />
                <span>
                    Remplacer le contenu de la feuille par le texte actuel de la fenêtre
                    <span class="block text-xs text-muted-foreground">
                        Sinon, seul le nom change. Les comptes rendus déjà écrits ne sont jamais modifiés.
                    </span>
                </span>
            </label>
        </div>

        <template #footer>
            <Button type="button" variant="outline" @click="editOpen = false">Annuler</Button>
            <Button type="button" :disabled="editForm.processing || editForm.name.trim() === ''" @click="saveEdit">
                <Pencil class="h-4 w-4" />Enregistrer les modifications
            </Button>
        </template>
    </Dialog>

    <Dialog
        :open="archiveOpen"
        title="Retirer cette feuille ?"
        :description="currentTemplate?.label ?? ''"
        :dismissible="false"
        close-label="Conserver la feuille"
        @update:open="(value) => value || (archiveOpen = false)"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-300">
                <Trash2 class="h-5 w-5" />
            </span>
        </template>

        <p class="text-xs leading-5 text-muted-foreground">
            Elle ne sera plus proposée. Les comptes rendus déjà écrits ne changent pas, et le texte actuel de cette fenêtre reste en place.
        </p>

        <template #footer>
            <Button type="button" variant="outline" @click="archiveOpen = false">Conserver la feuille</Button>
            <Button type="button" variant="danger" @click="archiveTemplate">
                <Trash2 class="h-4 w-4" />Retirer la feuille
            </Button>
        </template>
    </Dialog>
</template>
