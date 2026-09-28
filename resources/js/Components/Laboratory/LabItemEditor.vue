<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import ClinicalSaveStatus from '@/Components/Clinical/ClinicalSaveStatus.vue';
import LabAntibiogramEditor from '@/Components/Laboratory/LabAntibiogramEditor.vue';
import LabResultField from '@/Components/Laboratory/LabResultField.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import {
    AlertTriangle, BadgeCheck, Building2, CheckCheck, ClipboardCheck, FileText, History, Lock, RotateCcw, Send, Siren, TriangleAlert, Undo2,
} from 'lucide-vue-next';
import { criticalFlag } from '@/utilities/criticalRanges';
import { cn } from '@/lib/cn';
import { useAutosave } from '@/composables/useAutosave';
import { formatDateTime } from '@/utilities/date';
import LabSiteOnlyAction from '@/Components/Laboratory/LabSiteOnlyAction.vue';
import { LAB_SITE_ONLY_REASON, labUrl } from '@/utilities/labUrl';
import {
    FLAG_LABELS, INTERPRETATION_LABELS, LAB_STATUS_TONES, effectiveInterpretation, entryFilled, entryFromNode,
    rangeFlag, resultText, resultsPayload, suggestedInterpretation,
} from '@/utilities/labWorkbench';

/**
 * ADR-213 — la saisie d'une analyse demandée : une ligne par analyse du
 * catalogue de la prestation, enregistrée d'elle-même. « Terminer » la rend
 * aux prescripteurs ; le biologiste la valide ou la renvoie avec un motif.
 */
const props = defineProps({
    item: { type: Object, required: true },
    options: { type: Object, default: () => ({}) },
    microbiology: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    cancelled: { type: Boolean, default: false },
    // ADR-214 — rien ne se saisit avant la réception de la demande.
    received: { type: Boolean, default: true },
    requestUuid: { type: String, default: '' },
    externalLabs: { type: Array, default: () => [] },
});

const page = usePage();
const writable = computed(() => props.item.editable && props.can.enter && !props.cancelled && props.received);

const nodes = computed(() => props.item.nodes ?? []);
const inputs = computed(() => nodes.value.filter((node) => node.takes_result));

const form = useForm({
    entries: inputs.value.map((node) => entryFromNode(node)),
    conclusion: props.item.conclusion ?? '',
});
const entryIndex = (uuid) => form.entries.findIndex((entry) => entry.analysis_uuid === uuid);
const entryOf = (uuid) => form.entries[entryIndex(uuid)];

const autosave = useAutosave(form, (options) => form
    .transform((data) => ({ results: resultsPayload(data.entries), conclusion: data.conclusion ?? '' }))
    .put(labUrl(`/laboratory/items/${props.item.uuid}/results`), options), { enabled: () => writable.value });

const filled = computed(() => inputs.value.filter((node) => entryFilled(node, entryOf(node.uuid))).length);

const fieldError = (uuid) => {
    const index = entryIndex(uuid);
    return form.errors[`results.${index}.value`] ?? form.errors[`results.${index}.analysis_uuid`] ?? null;
};
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

const flagOf = (node) => (node.entry_mode === 'NUMERIC' ? rangeFlag(node.range, entryOf(node.uuid)?.value) : null);
// ADR-214 — au-delà d'une borne critique du catalogue : marqué critique d'office à l'enregistrement.
const criticalOf = (node) => (node.entry_mode === 'NUMERIC' ? criticalFlag(node.critical, entryOf(node.uuid)?.value) : null);

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
    if (!props.can.site_only || props.cancelled || !props.received) return [];
    if (props.item.status === 'COMPLETED') return [{ label: 'Valider', variant: 'success' }];
    if (props.item.editable && props.item.has_definitions) return [{ label: 'Terminer l’analyse', variant: 'default' }];

    return [];
});
const canSendOut = computed(() => props.can.send_out && props.item.editable && !props.item.sent_out && !props.cancelled && props.received);
const canCancelSendOut = computed(() => props.can.send_out && props.item.sent_out && props.item.status === 'PENDING' && !props.cancelled
    && !(props.item.nodes ?? []).some((node) => node.result));
const externalListId = computed(() => `external-labs-${props.item.uuid}`);

// Critique : signalé à la main, sur un résultat enregistré.
const toggleCritical = (node) => {
    if (!node.result?.uuid) return;
    router.post(labUrl(`/laboratory/results/${node.result.uuid}/critical`), { critical: !node.result.is_critical }, { preserveScroll: true, preserveState: true });
};

// Terminer
const completing = ref(false);
const confirmComplete = ref(false);
const complete = () => {
    completing.value = true;
    autosave.flush(() => {
        router.post(labUrl(`/laboratory/items/${props.item.uuid}/complete`), {}, {
            preserveScroll: true,
            onFinish: () => { completing.value = false; confirmComplete.value = false; },
        });
    }, () => { completing.value = false; confirmComplete.value = false; });
};

// Valider
const validating = ref(false);
const validate = () => {
    validating.value = true;
    router.post(labUrl(`/laboratory/items/${props.item.uuid}/validate`), {}, { preserveScroll: true, onFinish: () => { validating.value = false; } });
};

// Renvoyer
const returnOpen = ref(false);
const returnForm = useForm({ reason: '' });
const sendBack = () => returnForm.post(labUrl(`/laboratory/items/${props.item.uuid}/return`), {
    preserveScroll: true,
    onSuccess: () => { returnOpen.value = false; returnForm.reset(); },
});
const canReturn = computed(() => !props.cancelled && (
    (props.item.status === 'COMPLETED' && (props.can.validate || props.can.enter))
    || (props.item.status === 'VALIDATED' && props.can.validate)));

// Résultat en une fois (analyse sans définition au catalogue)
const legacy = useForm({ result_value: '', result_notes: '' });
const recordLegacy = () => legacy.post(labUrl(`/laboratory/items/${props.item.uuid}/result`), { preserveScroll: true });

const anteriorityText = (node) => {
    const previous = node.anteriority;
    if (!previous) return null;
    const value = resultText({ ...node, result: { value: previous.value, selections: previous.selections } }, props.options);
    return `${value}${previous.unit ? ` ${previous.unit}` : ''} — ${formatDateTime(previous.resulted_at)}${previous.validated ? '' : ' (non validé)'}`;
};
</script>

<template>
    <Card class="overflow-hidden">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-4 py-3">
            <div class="min-w-0">
                <h2 class="text-lg font-bold text-foreground">{{ item.name }}</h2>
                <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <Badge :tone="LAB_STATUS_TONES[item.status]">{{ item.status_label }}</Badge>
                    <span v-if="item.code">{{ item.code }}</span>
                    <span v-if="inputs.length">{{ filled }} / {{ inputs.length }} saisie(s)</span>
                    <Badge v-if="item.critical_count" tone="danger"><Siren class="h-3 w-3" /> {{ item.critical_count }} critique(s)</Badge>
                    <Badge v-if="item.pathological_count" tone="warning">{{ item.pathological_count }} pathologique(s)</Badge>
                </p>
            </div>
            <ClinicalSaveStatus v-if="writable && item.has_definitions" :saving="autosave.saving.value" :saved-at="autosave.savedAt.value" :dirty="form.isDirty" :failed="autosave.failed.value" retryable @retry="autosave.retry" />
        </header>

        <!-- ADR-214 — avant la réception, la saisie reste fermée -->
        <div v-if="!received && !cancelled" class="flex gap-2 border-b border-border bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
            <ClipboardCheck class="mt-0.5 h-4 w-4 shrink-0" />
            <p>Réceptionnez d’abord la demande : c’est la réception qui contrôle le règlement et enregistre les prélèvements.</p>
        </div>
        <div v-if="item.sent_out" class="flex flex-wrap items-start justify-between gap-2 border-b border-border bg-sky-50 px-4 py-2.5 text-sm text-sky-900 dark:bg-sky-950/30 dark:text-sky-200">
            <p class="flex gap-2">
                <Building2 class="mt-0.5 h-4 w-4 shrink-0" />
                <span>Confiée à <strong>{{ item.sent_out.laboratory }}</strong><template v-if="item.sent_out.at"> le {{ formatDateTime(item.sent_out.at) }}</template><template v-if="item.sent_out.by"> par {{ item.sent_out.by }}</template><template v-if="item.sent_out.reference"> · réf. {{ item.sent_out.reference }}</template>.
                    Saisissez ici le résultat reçu : le biologiste valide la transcription.</span>
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
            <p>Terminée<template v-if="item.resulted_by"> par {{ item.resulted_by }}</template><template v-if="item.resulted_at">, {{ formatDateTime(item.resulted_at) }}</template> — elle attend la validation du biologiste.</p>
        </div>
        <div v-else-if="item.status === 'VALIDATED'" class="flex gap-2 border-b border-border bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300">
            <BadgeCheck class="mt-0.5 h-4 w-4 shrink-0" />
            <p>Validée<template v-if="item.validated_by"> par {{ item.validated_by }}</template><template v-if="item.validated_at">, {{ formatDateTime(item.validated_at) }}</template>. Elle ne se modifie plus.</p>
        </div>
        <div v-if="!writable && item.editable && !cancelled && !can.enter" class="flex gap-2 border-b border-border px-4 py-2.5 text-xs text-muted-foreground">
            <Lock class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <p v-if="can.site_only">Lecture seule : {{ LAB_SITE_ONLY_REASON.charAt(0).toLowerCase() + LAB_SITE_ONLY_REASON.slice(1) }}</p>
            <p v-else>Lecture seule : la saisie demande le droit « laboratory_results.create ».</p>
        </div>
        <p v-if="itemError" class="flex gap-2 border-b border-border bg-destructive/5 px-4 py-2.5 text-sm text-destructive" role="alert">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />{{ itemError }}
        </p>

        <!-- Analyses du catalogue -->
        <div v-if="item.has_definitions" class="divide-y divide-border">
            <div
                v-for="node in nodes"
                :key="node.uuid"
                :class="cn('px-4', node.takes_result ? 'py-3' : 'bg-muted/40 py-2')"
                :style="{ paddingInlineStart: `${1 + node.depth * 1.25}rem` }"
            >
                <p v-if="!node.takes_result" :class="cn('text-xs uppercase tracking-wide text-muted-foreground', node.is_bold ? 'font-bold text-foreground' : 'font-semibold')">
                    {{ node.designation }}
                </p>

                <div v-else class="grid gap-2 lg:grid-cols-[minmax(0,14rem)_minmax(12rem,1fr)_minmax(0,auto)] lg:items-start">
                    <div class="min-w-0">
                        <p :class="cn('text-sm text-foreground', node.is_bold ? 'font-bold' : 'font-medium')">{{ node.designation }}</p>
                        <p v-if="node.reference" class="mt-0.5 text-[11px] text-muted-foreground" :title="node.reference_profile ? `Référence : ${node.reference_profile}` : 'Référence'">
                            Réf. {{ node.reference }}<template v-if="node.unit && node.entry_mode === 'NUMERIC'">&nbsp;{{ node.unit }}</template>
                        </p>
                        <p v-if="node.critical" class="mt-0.5 flex items-center gap-1 text-[11px] text-destructive" :title="`Bornes critiques : ${node.critical.profile}`">
                            <Siren class="h-3 w-3 shrink-0" />Critique {{ node.critical.text }}<template v-if="node.unit">&nbsp;{{ node.unit }}</template>
                        </p>
                        <p v-if="anteriorityText(node)" class="mt-0.5 flex items-start gap-1 text-[11px] text-muted-foreground">
                            <History class="mt-px h-3 w-3 shrink-0" /><span>Antériorité : {{ anteriorityText(node) }}</span>
                        </p>
                    </div>

                    <div class="min-w-0 space-y-1">
                        <LabResultField
                            :node="node"
                            :entry="entryOf(node.uuid)"
                            :options="options"
                            :microbiology="microbiology"
                            :disabled="!writable"
                        />
                        <p v-if="fieldError(node.uuid)" class="text-xs text-destructive">{{ fieldError(node.uuid) }}</p>
                    </div>

                    <!-- Bornée : repères et interprétation passent à la ligne plutôt que de recouvrir la saisie. -->
                    <div class="flex flex-wrap items-center gap-1.5 lg:max-w-[20rem] lg:justify-end">
                        <Badge v-if="criticalOf(node) && !node.result?.is_critical" tone="danger" title="Au-delà d’une borne critique : marqué critique à l’enregistrement"><Siren class="h-3 w-3" /> Valeur critique</Badge>
                        <Badge v-if="flagOf(node) && flagOf(node) !== 'NORMAL'" tone="danger">{{ FLAG_LABELS[flagOf(node)] }}</Badge>
                        <template v-if="node.interpretable">
                            <div v-if="writable" class="inline-flex rounded-lg border border-border p-0.5 text-[11px]" role="radiogroup" :aria-label="`Interprétation de ${node.designation}`">
                                <button
                                    type="button"
                                    role="radio"
                                    :aria-checked="!entryOf(node.uuid).interpretation_set"
                                    :title="suggestedInterpretation(node, entryOf(node.uuid)) ? `Proposée : ${INTERPRETATION_LABELS[suggestedInterpretation(node, entryOf(node.uuid))]}` : 'Aucune proposition'"
                                    :class="cn('rounded-md px-2 py-0.5 font-semibold', !entryOf(node.uuid).interpretation_set ? 'bg-muted text-foreground' : 'text-muted-foreground hover:bg-accent')"
                                    @click="setInterpretation(node.uuid, 'AUTO')"
                                >Auto</button>
                                <button
                                    v-for="option in options.interpretations"
                                    :key="option.value"
                                    type="button"
                                    role="radio"
                                    :aria-checked="entryOf(node.uuid).interpretation_set && entryOf(node.uuid).interpretation === option.value"
                                    :class="cn('rounded-md px-2 py-0.5 font-semibold',
                                        entryOf(node.uuid).interpretation_set && entryOf(node.uuid).interpretation === option.value
                                            ? (option.value === 'NORMAL' ? 'bg-emerald-600 text-white' : 'bg-destructive text-destructive-foreground')
                                            : 'text-muted-foreground hover:bg-accent')"
                                    @click="setInterpretation(node.uuid, option.value)"
                                >{{ option.label }}</button>
                            </div>
                            <Badge v-if="effectiveInterpretation(node, entryOf(node.uuid))" :tone="interpretationTone[effectiveInterpretation(node, entryOf(node.uuid))]">
                                {{ INTERPRETATION_LABELS[effectiveInterpretation(node, entryOf(node.uuid))] }}
                            </Badge>
                        </template>
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
                            <Siren class="h-3.5 w-3.5" />{{ node.result.is_critical ? (node.result.critical_source === 'AUTO' ? 'Critique (auto)' : 'Critique') : '' }}
                        </Button>
                    </div>

                    <!-- Antibiogrammes des germes retenus (créés à l'enregistrement de la culture) -->
                    <div v-if="node.entry_mode === 'CULTURE' && (node.antibiograms.length || entryOf(node.uuid).value === 'GROWTH')" class="space-y-2 lg:col-span-3">
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
                <Button type="submit" :disabled="legacy.processing || !legacy.result_value.trim()"><CheckCheck class="h-4 w-4" /> Rendre le résultat</Button>
            </form>
            <p v-else class="text-sm text-muted-foreground">Aucun résultat saisi.</p>
        </div>

        <!-- Conclusion -->
        <div v-if="item.has_definitions && (writable || item.conclusion)" class="border-t border-border p-4">
            <FormField label="Conclusion du laboratoire" hint="(facultative, imprimée sous les résultats)">
                <Textarea v-model="form.conclusion" rows="2" :disabled="!writable" />
            </FormField>
        </div>

        <!-- Gestes -->
        <footer v-if="!cancelled && (writable && item.has_definitions || (item.status === 'COMPLETED' && can.validate) || canReturn || canSendOut || portalGestures.length)" class="flex flex-wrap items-center justify-end gap-2 border-t border-border bg-muted/30 px-4 py-3">
            <Button v-if="canSendOut" type="button" variant="ghost" class="me-auto" @click="openSendOut">
                <Send class="h-4 w-4" /> Envoyer à un laboratoire extérieur
            </Button>
            <Button v-if="canReturn" type="button" variant="outline" @click="returnOpen = true">
                <RotateCcw class="h-4 w-4" /> {{ item.status === 'VALIDATED' ? 'Rouvrir (à refaire)' : 'Renvoyer à refaire' }}
            </Button>
            <Button v-if="item.status === 'COMPLETED' && can.validate" type="button" variant="success" :disabled="validating" @click="validate">
                <BadgeCheck class="h-4 w-4" /> Valider
            </Button>
            <Button v-if="writable && item.has_definitions" type="button" :disabled="completing || filled === 0" @click="confirmComplete = true">
                <CheckCheck class="h-4 w-4" /> Terminer l’analyse
            </Button>
            <LabSiteOnlyAction v-for="gesture in portalGestures" :key="gesture.label" :label="gesture.label" :variant="gesture.variant" />
        </footer>

        <ConfirmModal
            v-model:open="confirmComplete"
            title="Terminer l’analyse ?"
            :description="`« ${item.name} » sera rendue aux prescripteurs et attendra la validation du biologiste. La saisie se ferme.`"
            confirm-label="Terminer"
            :processing="completing"
            @confirm="complete"
        />

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

        <Dialog v-model:open="returnOpen" title="Renvoyer à refaire" :description="`« ${item.name} » repasse à la paillasse avec votre motif.`" :dismissible="false">
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
