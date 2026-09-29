<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import ClinicalSaveStatus from '@/Components/Clinical/ClinicalSaveStatus.vue';
import LabAntibiogramEditor from '@/Components/Laboratory/LabAntibiogramEditor.vue';
import LabLineNote from '@/Components/Laboratory/LabLineNote.vue';
import LabResultField from '@/Components/Laboratory/LabResultField.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import {
    AlertTriangle, BadgeCheck, Building2, CheckCheck, ClipboardList, Eraser, FileText, History, Lock, NotebookPen, RotateCcw, Save, Send, SendHorizontal, Siren, TriangleAlert, Undo2,
} from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { useAutosave } from '@/composables/useAutosave';
import { formatDateTime } from '@/utilities/date';
import LabSiteOnlyAction from '@/Components/Laboratory/LabSiteOnlyAction.vue';
import { LAB_SITE_ONLY_REASON, labUrl } from '@/utilities/labUrl';
import {
    INTERPRETATION_LABELS, LAB_STATUS_TONES, effectiveInterpretation, entryFilled, entryFromNode,
    LAB_ENTRY_MODE_LABELS, analysisInitials, designationWeight, hasEntries, notesFromNodes, notesPayload, resultText, resultsPayload, suggestedInterpretation,
} from '@/utilities/labWorkbench';

/**
 * ADR-213 — la saisie d'une analyse demandée : une ligne par analyse du
 * catalogue de la prestation, enregistrée d'elle-même.
 *
 * ADR-216 — « Envoyer au médecin » la rend au prescripteur et la valide en un
 * geste : il n'y a plus de biologiste distinct. La fenêtre d'envoi appartient à
 * la page (elle peut envoyer plusieurs analyses) ; l'éditeur lui passe la main
 * une fois sa saisie enregistrée.
 *
 * ADR-219 — une carte par ligne comme labo-vuejs (mode, norme, résultat,
 * interprétation, conclusion partielle), le nom en gras seulement si le
 * catalogue le dit, et « Réinitialiser la saisie » tant que rien n'est envoyé.
 * Plus de conclusion par analyse : la conclusion générale suffit.
 */
const props = defineProps({
    item: { type: Object, required: true },
    options: { type: Object, default: () => ({}) },
    microbiology: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    cancelled: { type: Boolean, default: false },
    // ADR-217 — la saisie n'attend plus la réception : la première saisie prend la demande en charge.
    received: { type: Boolean, default: true },
    requestUuid: { type: String, default: '' },
    externalLabs: { type: Array, default: () => [] },
});
const emit = defineEmits(['send']);

const page = usePage();
const writable = computed(() => props.item.editable && props.can.enter && !props.cancelled);

const nodes = computed(() => props.item.nodes ?? []);
const inputs = computed(() => nodes.value.filter((node) => node.takes_result));

const form = useForm({
    entries: inputs.value.map((node) => entryFromNode(node)),
    notes: notesFromNodes(nodes.value),
});
// ADR-218 — ce que le serveur a servi : une note vidée part pour être effacée.
const servedNotes = computed(() => notesFromNodes(nodes.value));
const entryIndex = (uuid) => form.entries.findIndex((entry) => entry.analysis_uuid === uuid);
const entryOf = (uuid) => form.entries[entryIndex(uuid)];

const autosave = useAutosave(form, (options) => form
    .transform((data) => ({ results: resultsPayload(data.entries), notes: notesPayload(data.notes, servedNotes.value) }))
    .put(labUrl(`/laboratory/items/${props.item.uuid}/results`), options), { enabled: () => writable.value });

const filled = computed(() => inputs.value.filter((node) => entryFilled(node, entryOf(node.uuid))).length);

const fieldError = (uuid) => {
    const index = entryIndex(uuid);
    return form.errors[`results.${index}.value`] ?? form.errors[`results.${index}.analysis_uuid`] ?? null;
};
// ADR-218 / ADR-219 — la conclusion partielle d'une ligne part au geste
// « Valider » ou « Supprimer », aussitôt enregistrée.
const saveNote = (uuid, text) => {
    form.notes[uuid] = text;
    autosave.flush();
};
const noteError = (uuid) => {
    const index = notesPayload(form.notes, servedNotes.value).findIndex((note) => note.analysis_uuid === uuid);
    return index < 0 ? null : (form.errors[`notes.${index}.note`] ?? form.errors[`notes.${index}.analysis_uuid`] ?? null);
};

// ADR-219 — remettre la saisie à zéro, tant que rien n'est envoyé au médecin.
const resetOpen = ref(false);
const resetting = ref(false);
const canReset = computed(() => writable.value && props.item.has_definitions && props.item.resettable
    && (hasEntries(props.item) || form.isDirty));
const clearForm = () => {
    form.entries = inputs.value.map((node) => entryFromNode(node));
    form.notes = notesFromNodes(nodes.value);
    form.defaults();
    form.clearErrors();
    resetOpen.value = false;
};
const reset = () => {
    // La saisie en cours est abandonnée : l'enregistrement automatique n'a plus rien à envoyer.
    form.defaults();
    // Rien d'enregistré : il n'y a que l'écran à vider.
    if (!hasEntries(props.item)) {
        clearForm();
        return;
    }
    resetting.value = true;
    router.post(labUrl(`/laboratory/items/${props.item.uuid}/reset`), {}, {
        preserveScroll: true,
        onSuccess: clearForm,
        onFinish: () => { resetting.value = false; },
    });
};
const saveNow = () => autosave.flush();
const expected = computed(() => inputs.value.length);
const typeLabel = (node) => LAB_ENTRY_MODE_LABELS[node.entry_mode] ?? node.entry_mode;

const itemError = computed(() => page.props.errors?.item ?? form.errors.results ?? null);

// Interprétation : « Auto » suit la proposition, un choix explicite part tel quel.
const setInterpretation = (uuid, value) => {
    const entry = entryOf(uuid);
    if (value === 'AUTO') {
        entry.interpretation_set = false;
        entry.interpretation = null;
        return;
    }
    entry.interpretation_set = true;
    entry.interpretation = value;
};
const interpretationTone = { NORMAL: 'success', PATHOLOGICAL: 'danger' };


// Laboratoire extérieur
const sendOutOpen = ref(false);
const sendOutForm = useForm({ laboratory: '', reference: '', notes: '' });
const openSendOut = () => { sendOutForm.reset(); sendOutForm.clearErrors(); sendOutOpen.value = true; };
const sendOut = () => sendOutForm.post(labUrl(`/laboratory/items/${props.item.uuid}/send-out`), {
    preserveScroll: true,
    onSuccess: () => { sendOutOpen.value = false; },
});
const cancellingSendOut = ref(false);
const cancelSendOut = () => {
    cancellingSendOut.value = true;
    router.post(labUrl(`/laboratory/items/${props.item.uuid}/send-out/cancel`), {}, { preserveScroll: true, onFinish: () => { cancellingSendOut.value = false; } });
};
// ADR-215 — sur le portail, les gestes de l'analyse restent au site : ils sont
// montrés verrouillés plutôt que masqués (ADR-158).
const portalGestures = computed(() => {
    if (!props.can.site_only || props.cancelled) return [];
    if (props.item.status === 'COMPLETED' || (props.item.editable && props.item.has_definitions)) return [{ label: 'Envoyer au médecin', variant: 'default' }];

    return [];
});
const canSendOut = computed(() => props.can.send_out && props.item.editable && !props.item.sent_out && !props.cancelled);
const canCancelSendOut = computed(() => props.can.send_out && props.item.sent_out && props.item.status === 'PENDING' && !props.cancelled
    && !(props.item.nodes ?? []).some((node) => node.result));
const externalListId = computed(() => `external-labs-${props.item.uuid}`);

// Critique : signalé à la main, sur un résultat enregistré.
const toggleCritical = (node) => {
    if (!node.result?.uuid) return;
    router.post(labUrl(`/laboratory/results/${node.result.uuid}/critical`), { critical: !node.result.is_critical }, { preserveScroll: true, preserveState: true });
};

// ADR-216 — envoyer au médecin : la saisie en cours part d'abord, puis la page
// ouvre sa fenêtre d'envoi sur ce qui est réellement enregistré.
const preparing = ref(false);
const canSend = computed(() => props.can.send && !props.cancelled
    && (props.item.status === 'COMPLETED' || (props.item.editable && props.item.has_definitions)));
const send = () => {
    preparing.value = true;
    autosave.flush(() => { preparing.value = false; emit('send', props.item.uuid); }, () => { preparing.value = false; });
};
defineExpose({ flush: (done, onError) => autosave.flush(done, onError) });

// Renvoyer
const returnOpen = ref(false);
const returnForm = useForm({ reason: '' });
const sendBack = () => returnForm.post(labUrl(`/laboratory/items/${props.item.uuid}/return`), {
    preserveScroll: true,
    onSuccess: () => { returnOpen.value = false; returnForm.reset(); },
});
const canReturn = computed(() => !props.cancelled && (
    (props.item.status === 'COMPLETED' && (props.can.send || props.can.enter))
    || (props.item.status === 'VALIDATED' && props.can.send)));

// Résultat en une fois (analyse sans définition au catalogue)
const legacy = useForm({ result_value: '', result_notes: '' });
const recordLegacy = () => legacy.post(labUrl(`/laboratory/items/${props.item.uuid}/result`), { preserveScroll: true });

const anteriorityText = (node) => {
    const previous = node.anteriority;
    if (!previous) return null;
    const value = resultText({ ...node, result: { value: previous.value, selections: previous.selections } }, props.options);
    return `${value}${previous.unit ? ` ${previous.unit}` : ''} — ${formatDateTime(previous.resulted_at)}${previous.validated ? '' : ' (non envoyé)'}`;
};
</script>

<template>
    <Card class="overflow-hidden">
        <!-- L'analyse ouverte : nom et état sur une ligne, puis les repères, rangés -->
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border bg-muted/30 px-4 py-3 sm:px-5">
            <div class="flex min-w-0 flex-1 items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-base font-bold text-primary" aria-hidden="true">{{ analysisInitials(item.name) }}</span>
                <div class="min-w-0 flex-1 space-y-1.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="min-w-0 text-lg font-bold leading-tight text-foreground">{{ item.name }}</h2>
                        <Badge :tone="LAB_STATUS_TONES[item.status]">{{ item.status_label }}</Badge>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-muted-foreground">
                        <span v-if="item.code" class="rounded border border-border bg-background px-1.5 py-0.5 font-mono text-[11px] text-foreground">{{ item.code }}</span>
                        <span
                            v-if="expected"
                            class="inline-flex items-center gap-2"
                            :aria-label="`${filled} résultat(s) saisi(s) sur ${expected}`"
                        >
                            <ClipboardList class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            <span class="h-1.5 w-24 overflow-hidden rounded-full bg-muted" aria-hidden="true"><span class="block h-full rounded-full bg-primary transition-all" :style="{ width: `${Math.round((filled / expected) * 100)}%` }" /></span>
                            <span class="tabular-nums"><strong class="text-foreground">{{ filled }}</strong> / {{ expected }} résultat(s)</span>
                        </span>
                        <span v-if="item.pathological_count || item.critical_count" class="h-3.5 w-px bg-border" aria-hidden="true" />
                        <span v-if="item.pathological_count" class="inline-flex items-center gap-1 font-medium text-amber-700 dark:text-amber-400">
                            <TriangleAlert class="h-3.5 w-3.5" aria-hidden="true" />{{ item.pathological_count }} pathologique(s)
                        </span>
                        <span v-if="item.critical_count" class="inline-flex items-center gap-1 font-medium text-destructive">
                            <Siren class="h-3.5 w-3.5" aria-hidden="true" />{{ item.critical_count }} critique(s)
                        </span>
                    </div>
                </div>
            </div>
            <Button v-if="canReset" type="button" size="sm" variant="outline" class="text-destructive hover:text-destructive" @click="resetOpen = true">
                <Eraser class="h-4 w-4" /> Réinitialiser la saisie
            </Button>
        </header>

        <div v-if="item.sent_out" class="flex flex-wrap items-start justify-between gap-2 border-b border-border bg-sky-50 px-4 py-2.5 text-sm text-sky-900 dark:bg-sky-950/30 dark:text-sky-200">
            <p class="flex gap-2">
                <Building2 class="mt-0.5 h-4 w-4 shrink-0" />
                <span>Confiée à <strong>{{ item.sent_out.laboratory }}</strong><template v-if="item.sent_out.at"> le {{ formatDateTime(item.sent_out.at) }}</template><template v-if="item.sent_out.by"> par {{ item.sent_out.by }}</template><template v-if="item.sent_out.reference"> · réf. {{ item.sent_out.reference }}</template>.
                    Saisissez ici le résultat reçu, puis envoyez-le au médecin.</span>
            </p>
            <span class="flex gap-1">
                <Button v-if="requestUuid" :as="Link" :href="labUrl(`/laboratory/requests/${requestUuid}/bon-envoi`)" size="xs" variant="outline"><FileText class="h-3.5 w-3.5" /> Bon d’envoi</Button>
                <Button v-if="canCancelSendOut" type="button" size="xs" variant="ghost" :disabled="cancellingSendOut" @click="cancelSendOut"><Undo2 class="h-3.5 w-3.5" /> Faire ici</Button>
            </span>
        </div>

        <!-- Où en est l'analyse -->
        <div v-if="item.status === 'TO_REDO'" class="flex gap-2 border-b border-border bg-destructive/5 px-4 py-2.5 text-sm text-destructive">
            <RotateCcw class="mt-0.5 h-4 w-4 shrink-0" />
            <p>À refaire<template v-if="item.returned_by"> — renvoyée par {{ item.returned_by }}</template><template v-if="item.returned_at">, {{ formatDateTime(item.returned_at) }}</template> : <strong>{{ item.return_reason }}</strong></p>
        </div>
        <div v-else-if="item.status === 'COMPLETED'" class="flex gap-2 border-b border-border bg-primary/5 px-4 py-2.5 text-sm text-foreground">
            <CheckCheck class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
            <p>Résultat rendu<template v-if="item.resulted_by"> par {{ item.resulted_by }}</template><template v-if="item.resulted_at">, {{ formatDateTime(item.resulted_at) }}</template> — il n’est pas encore envoyé au médecin.</p>
        </div>
        <div v-else-if="item.status === 'VALIDATED'" class="flex gap-2 border-b border-border bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300">
            <BadgeCheck class="mt-0.5 h-4 w-4 shrink-0" />
            <p>Envoyée au médecin<template v-if="item.validated_by"> par {{ item.validated_by }}</template><template v-if="item.validated_at">, {{ formatDateTime(item.validated_at) }}</template>. Elle ne se modifie plus ; une erreur se corrige par « Renvoyer à refaire ».</p>
        </div>
        <div v-if="!writable && item.editable && !cancelled && !can.enter" class="flex gap-2 border-b border-border px-4 py-2.5 text-xs text-muted-foreground">
            <Lock class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <p v-if="can.site_only">Lecture seule : {{ LAB_SITE_ONLY_REASON.charAt(0).toLowerCase() + LAB_SITE_ONLY_REASON.slice(1) }}</p>
            <p v-else>Lecture seule : la saisie demande le droit « laboratory_results.create ».</p>
        </div>
        <p v-if="itemError" class="flex gap-2 border-b border-border bg-destructive/5 px-4 py-2.5 text-sm text-destructive" role="alert">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />{{ itemError }}
        </p>

        <!-- Les lignes du catalogue : une carte par analyse, un groupe porte ses lignes -->
        <div v-if="item.has_definitions" class="space-y-3 p-3 sm:p-5">
            <template v-for="node in nodes" :key="node.uuid">
                <!-- Groupe ou intitulé : aucune saisie, sa conclusion partielle -->
                <section
                    v-if="!node.takes_result"
                    class="rounded-xl border border-dashed border-border bg-muted/30 px-4 py-3"
                    :style="{ marginInlineStart: `${node.depth * 1.25}rem` }"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge variant="outline" class="text-[10px] uppercase tracking-wider">Groupe</Badge>
                        <h3 :class="cn('text-sm text-foreground', designationWeight(node))">{{ node.designation }}</h3>
                    </div>
                    <LabLineNote
                        :uuid="node.uuid"
                        :designation="node.designation"
                        :note="node.note ?? ''"
                        :author="node.note_by"
                        :writable="writable"
                        :saving="autosave.saving.value"
                        :error="noteError(node.uuid)"
                        group
                        @save="saveNote(node.uuid, $event)"
                    />
                </section>

                <!-- Une ligne qui attend un résultat -->
                <article
                    v-else
                    :class="cn('rounded-xl border bg-card p-4 shadow-sm transition-shadow hover:shadow-md',
                        node.result?.is_critical ? 'border-destructive/50' : 'border-border')"
                    :style="{ marginInlineStart: `${node.depth * 1.25}rem` }"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge variant="outline" class="text-[10px] uppercase tracking-wider">{{ typeLabel(node) }}</Badge>
                                <h3 :class="cn('text-sm text-foreground sm:text-base', designationWeight(node))">{{ node.designation }}</h3>
                            </div>
                            <p v-if="anteriorityText(node)" class="mt-1 flex items-start gap-1 text-[11px] text-muted-foreground">
                                <History class="mt-px h-3 w-3 shrink-0" /><span>Antériorité : {{ anteriorityText(node) }}</span>
                            </p>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <span
                                v-if="node.reference"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-muted/50 px-2 py-1 text-xs"
                                :title="node.reference_profile ? `Référence : ${node.reference_profile}` : 'Valeur de référence'"
                            >
                                <span class="text-[10px] font-semibold uppercase text-muted-foreground">Norme :</span>
                                <span class="font-bold text-emerald-700 dark:text-emerald-400">{{ node.reference }}</span>
                                <span v-if="node.unit" class="text-[11px] text-muted-foreground">{{ node.unit }}</span>
                            </span>
                            <span v-if="node.critical" class="inline-flex items-center gap-1 text-[11px] text-destructive" :title="`Bornes critiques : ${node.critical.profile}`">
                                <Siren class="h-3 w-3 shrink-0" />Critique {{ node.critical.text }}<template v-if="node.unit">&nbsp;{{ node.unit }}</template>
                            </span>
                        </div>
                    </div>

                    <div :class="cn('mt-3 grid gap-4', node.interpretable && 'md:grid-cols-12')">
                        <div :class="cn('min-w-0 space-y-1', node.interpretable && 'md:col-span-7 lg:col-span-8')">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{{ node.entry_mode === 'TEXT' ? 'Observations' : 'Résultat' }}</p>
                            <LabResultField
                                :node="node"
                                :entry="entryOf(node.uuid)"
                                :options="options"
                                :microbiology="microbiology"
                                :disabled="!writable"
                            />
                            <p v-if="fieldError(node.uuid)" class="text-xs text-destructive">{{ fieldError(node.uuid) }}</p>
                        </div>

                        <div v-if="node.interpretable" class="min-w-0 space-y-2 md:col-span-5 lg:col-span-4">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Interprétation</p>
                            <div class="grid grid-cols-3 gap-1 rounded-lg border border-border bg-muted/40 p-1" role="radiogroup" :aria-label="`Interprétation de ${node.designation}`">
                                <button
                                    type="button"
                                    role="radio"
                                    :disabled="!writable"
                                    :aria-checked="!entryOf(node.uuid).interpretation_set"
                                    :title="suggestedInterpretation(node, entryOf(node.uuid)) ? `Proposée : ${INTERPRETATION_LABELS[suggestedInterpretation(node, entryOf(node.uuid))]}` : 'Aucune proposition'"
                                    :class="cn('rounded-md px-2 py-1.5 text-[11px] font-semibold uppercase transition-colors disabled:cursor-not-allowed',
                                        !entryOf(node.uuid).interpretation_set ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:bg-accent')"
                                    @click="setInterpretation(node.uuid, 'AUTO')"
                                >Auto</button>
                                <button
                                    v-for="option in options.interpretations"
                                    :key="option.value"
                                    type="button"
                                    role="radio"
                                    :disabled="!writable"
                                    :aria-checked="entryOf(node.uuid).interpretation_set && entryOf(node.uuid).interpretation === option.value"
                                    :class="cn('rounded-md px-2 py-1.5 text-[11px] font-semibold uppercase transition-colors disabled:cursor-not-allowed',
                                        entryOf(node.uuid).interpretation_set && entryOf(node.uuid).interpretation === option.value
                                            ? (option.value === 'NORMAL' ? 'bg-emerald-600 text-white shadow-sm' : 'bg-destructive text-destructive-foreground shadow-sm')
                                            : 'text-muted-foreground hover:bg-accent')"
                                    @click="setInterpretation(node.uuid, option.value)"
                                >{{ option.value === 'PATHOLOGICAL' ? 'Patho' : option.label }}</button>
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <Badge v-if="effectiveInterpretation(node, entryOf(node.uuid))" :tone="interpretationTone[effectiveInterpretation(node, entryOf(node.uuid))]">
                                    {{ INTERPRETATION_LABELS[effectiveInterpretation(node, entryOf(node.uuid))] }}
                                </Badge>
                                <Button
                                    v-if="node.result?.uuid && (can.flag_critical || node.result.is_critical)"
                                    type="button"
                                    size="xs"
                                    :variant="node.result.is_critical ? 'danger' : 'ghost'"
                                    :disabled="!can.flag_critical || cancelled"
                                    :title="node.result.is_critical
                                        ? (node.result.critical_source === 'AUTO' ? `Marqué critique d’office (${node.result.critical_snapshot}) — retirer la marque` : 'Retirer le signalement critique')
                                        : (node.result.critical_source === 'DISMISSED' ? 'Marque automatique retirée — la signaler de nouveau' : 'Signaler ce résultat comme critique')"
                                    @click="toggleCritical(node)"
                                >
                                    <Siren class="h-3.5 w-3.5" />{{ node.result.is_critical ? (node.result.critical_source === 'AUTO' ? 'Critique (auto)' : 'Critique') : 'Signaler' }}
                                </Button>
                            </div>
                        </div>

                        <!-- Antibiogrammes des germes retenus (créés à l'enregistrement de la culture) -->
                        <div v-if="node.entry_mode === 'CULTURE' && (node.antibiograms.length || entryOf(node.uuid).value === 'GROWTH')" :class="cn('space-y-2', node.interpretable && 'md:col-span-12')">
                            <LabAntibiogramEditor
                                v-for="antibiogram in node.antibiograms"
                                :key="antibiogram.uuid"
                                :item-uuid="item.uuid"
                                :antibiogram="antibiogram"
                                :microbiology="microbiology"
                                :options="options"
                                :disabled="!writable"
                            />
                            <p v-if="writable && (entryOf(node.uuid).selections?.bacteria?.length ?? 0) > node.antibiograms.length" class="text-xs text-muted-foreground">
                                L’antibiogramme d’un germe ajouté apparaît dès que la saisie est enregistrée.
                            </p>
                        </div>
                    </div>

                    <LabLineNote
                        :uuid="node.uuid"
                        :designation="node.designation"
                        :note="node.note ?? ''"
                        :author="node.note_by"
                        :writable="writable"
                        :saving="autosave.saving.value"
                        :error="noteError(node.uuid)"
                        @save="saveNote(node.uuid, $event)"
                    />
                </article>
            </template>

            <!-- ADR-219 — une conclusion d'analyse saisie avant ce choix : lisible, plus modifiable -->
            <div v-if="item.legacy_conclusion" class="flex gap-2 rounded-lg border border-border bg-muted/40 p-3 text-sm">
                <NotebookPen class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                <p><span class="font-semibold">Conclusion saisie avant la conclusion générale : </span><span class="whitespace-pre-line">{{ item.legacy_conclusion }}</span></p>
            </div>
        </div>

        <!-- Analyse sans définition au catalogue : un résultat rendu en une fois -->
        <div v-else class="space-y-3 p-4">
            <p class="text-xs text-muted-foreground">Cette prestation n’a pas d’analyses détaillées au catalogue : le résultat se saisit en une fois.</p>
            <template v-if="item.result_value">
                <p class="whitespace-pre-line rounded-lg border border-border bg-muted/30 p-3 text-sm text-foreground">{{ item.result_value }}</p>
                <p v-if="item.result_notes" class="text-xs text-muted-foreground">{{ item.result_notes }}</p>
            </template>
            <form v-else-if="writable" class="space-y-3" @submit.prevent="recordLegacy">
                <FormField label="Résultat" :error="legacy.errors.result_value" required>
                    <Textarea v-model="legacy.result_value" rows="4" />
                </FormField>
                <FormField label="Remarque" :error="legacy.errors.result_notes">
                    <Textarea v-model="legacy.result_notes" rows="2" />
                </FormField>
                <p v-if="legacy.errors.item" class="text-sm text-destructive">{{ legacy.errors.item }}</p>
                <Button type="submit" :disabled="legacy.processing || !legacy.result_value.trim()"><CheckCheck class="h-4 w-4" /> Enregistrer le résultat</Button>
            </form>
            <p v-else class="text-sm text-muted-foreground">Aucun résultat saisi.</p>
        </div>

        <!-- Gestes : l'état de l'enregistrement à gauche, les actions à droite -->
        <footer
            v-if="!cancelled && (writable || canSend || canReturn || canSendOut || portalGestures.length)"
            class="sticky bottom-0 z-10 flex flex-wrap items-center justify-end gap-2 border-t border-border bg-background/95 px-4 py-3 backdrop-blur supports-[backdrop-filter]:bg-background/80"
        >
            <div class="me-auto flex flex-wrap items-center gap-2">
                <ClinicalSaveStatus v-if="writable && item.has_definitions" :saving="autosave.saving.value" :saved-at="autosave.savedAt.value" :dirty="form.isDirty" :failed="autosave.failed.value" retryable @retry="autosave.retry" />
                <Button v-if="canSendOut" type="button" size="sm" variant="ghost" @click="openSendOut">
                    <Send class="h-4 w-4" /> Laboratoire extérieur
                </Button>
            </div>
            <Button v-if="canReturn" type="button" size="sm" variant="outline" @click="returnOpen = true">
                <RotateCcw class="h-4 w-4" /> {{ item.status === 'VALIDATED' ? 'Reprendre (à refaire)' : 'Renvoyer à refaire' }}
            </Button>
            <Button v-if="writable && item.has_definitions" type="button" size="sm" variant="outline" :disabled="autosave.saving.value || !form.isDirty" @click="saveNow">
                <Save class="h-4 w-4" /> Enregistrer
            </Button>
            <Button v-if="canSend" type="button" size="sm" :disabled="preparing || (item.status !== 'COMPLETED' && filled === 0)" @click="send">
                <SendHorizontal class="h-4 w-4" /> Envoyer au médecin
            </Button>
            <LabSiteOnlyAction v-for="gesture in portalGestures" :key="gesture.label" :label="gesture.label" :variant="gesture.variant" />
        </footer>

        <Dialog v-model:open="resetOpen" title="Réinitialiser la saisie" :description="`Tous les résultats saisis sur « ${item.name} », ses antibiogrammes et ses conclusions partielles seront effacés : l’analyse repart de zéro. L’effacement est tracé.`" :dismissible="false">
            <p class="flex gap-2 rounded-lg bg-destructive/5 p-3 text-sm text-destructive">
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                {{ filled }} résultat(s) et {{ nodes.filter((node) => node.note).length }} conclusion(s) partielle(s) seront effacés. Cette action ne se défait pas.
            </p>
            <template #footer>
                <Button type="button" variant="outline" @click="resetOpen = false">Annuler</Button>
                <Button type="button" variant="danger" :disabled="resetting" @click="reset"><Eraser class="h-4 w-4" /> Tout effacer</Button>
            </template>
        </Dialog>

        <Dialog v-model:open="sendOutOpen" title="Envoyer à un laboratoire extérieur" :description="`« ${item.name} » sera réalisée ailleurs ; son résultat se transcrit ici à réception, puis se valide.`" :dismissible="false">
            <div class="space-y-4">
                <FormField label="Laboratoire" :error="sendOutForm.errors.laboratory" required>
                    <Input v-model="sendOutForm.laboratory" :list="externalListId" placeholder="Nom du laboratoire qui réalise l’analyse" />
                    <datalist :id="externalListId"><option v-for="name in externalLabs" :key="name" :value="name" /></datalist>
                </FormField>
                <FormField label="Référence chez ce laboratoire" hint="(facultative)" :error="sendOutForm.errors.reference">
                    <Input v-model="sendOutForm.reference" placeholder="N° de bon, de dossier…" />
                </FormField>
                <FormField label="Remarque" hint="(facultative, imprimée sur le bon d’envoi)" :error="sendOutForm.errors.notes">
                    <Textarea v-model="sendOutForm.notes" rows="2" />
                </FormField>
            </div>
            <template #footer>
                <Button type="button" variant="outline" @click="sendOutOpen = false">Annuler</Button>
                <Button type="button" :disabled="sendOutForm.processing || sendOutForm.laboratory.trim().length < 2" @click="sendOut"><Send class="h-4 w-4" /> Envoyer</Button>
            </template>
        </Dialog>

        <Dialog v-model:open="returnOpen" title="Renvoyer à refaire" :description="item.status === 'VALIDATED' ? `« ${item.name} » repasse à la paillasse avec votre motif. Le médecin destinataire est prévenu que ce résultat est repris.` : `« ${item.name} » repasse à la paillasse avec votre motif.`" :dismissible="false">
            <FormField label="Motif" :error="returnForm.errors.reason" required>
                <Textarea v-model="returnForm.reason" rows="3" placeholder="Ex. valeur incohérente, prélèvement hémolysé…" />
            </FormField>
            <template #footer>
                <Button type="button" variant="outline" @click="returnOpen = false">Annuler</Button>
                <Button type="button" variant="danger" :disabled="returnForm.processing || returnForm.reason.trim().length < 3" @click="sendBack">
                    <AlertTriangle class="h-4 w-4" /> Renvoyer
                </Button>
            </template>
        </Dialog>
    </Card>
</template>
