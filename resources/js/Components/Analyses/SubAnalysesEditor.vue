<script setup>
import { computed, ref, watch } from 'vue';
import { ChevronDown, ChevronUp, FolderTree, GripVertical, Plus, SlidersHorizontal, Trash2 } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Switch from '@/Components/Shadcn/Switch.vue';
import CriticalRangesField from './CriticalRangesField.vue';
// Récursif : une sous-analyse peut être elle-même un groupe, dont les
// résultats se règlent avec cette même liste, un niveau plus bas (`maxDepth`).
// eslint-disable-next-line vue/no-unused-components -- utilisé récursivement plus bas
import SubAnalysesEditor from './SubAnalysesEditor.vue';
import {
    RESULT_TYPES, automaticEntryMode, emptyChild, entryModesFor, isNumericResult, keepEntryModeCoherent, usesPredefinedValues,
} from '@/utilities/analysisForm';
import { cn } from '@/lib/cn';

/**
 * Les résultats d'un groupe (ADR-063, amendement du 2026-10-01) : une ligne
 * par résultat — désignation, code, type, unité —, le reste (normes, saisie,
 * bornes, sous-groupe) dans son détail, déplié à la demande. Retirer une
 * sous-analyse déjà enregistrée la désactive, jamais ne la supprime : la
 * page le demande avant.
 */
const props = defineProps({
    children: { type: Array, required: true },
    form: { type: Object, required: true },
    pathPrefix: { type: String, required: true },
    resultTypes: { type: Array, required: true },
    entryModes: { type: Array, default: () => [] },
    depth: { type: Number, default: 0 },
    maxDepth: { type: Number, default: 1 },
});

const canNest = computed(() => props.depth < props.maxDepth);
const title = computed(() => (props.depth === 0 ? 'Sous-analyses du groupe' : 'Résultats du sous-groupe'));
const typeOptions = computed(() => props.resultTypes.map((type) => ({ value: type, label: RESULT_TYPES[type]?.label ?? type })));
// ADR-238 — la saisie de chaque résultat suit son type, comme dans la fiche.
const entryModeOptions = (child) => [
    { value: '', label: `Automatique — ${automaticEntryMode(child, props.entryModes).label}` },
    ...entryModesFor(props.entryModes, child.result_type).map((mode) => ({ value: mode.value, label: mode.label })),
];
watch(() => props.children.map((child) => child.result_type), () => {
    props.children.forEach((child) => keepEntryModeCoherent(child, props.entryModes));
});

// Ouvert : les lignes dont on règle le détail. Une ligne neuve s'ouvre d'elle-même
// à la saisie de son nom ; une ligne en erreur aussi (voir `isOpen`).
const opened = ref(new Set());
const keyOf = (child, index) => child.uuid ?? `new-${index}`;
const err = (index, field) => props.form.errors?.[`${props.pathPrefix}.${index}.${field}`] ?? '';
const hasError = (index) => Object.keys(props.form.errors ?? {}).some((key) => key.startsWith(`${props.pathPrefix}.${index}.`));
const isOpen = (child, index) => opened.value.has(keyOf(child, index)) || hasError(index);
const toggle = (child, index) => {
    const next = new Set(opened.value);
    const key = keyOf(child, index);
    next.has(key) ? next.delete(key) : next.add(key);
    opened.value = next;
};

const add = () => {
    props.children.push(emptyChild());
};
const move = (index, direction) => {
    const target = index + direction;
    if (target < 0 || target >= props.children.length) return;
    const [moved] = props.children.splice(index, 1);
    props.children.splice(target, 0, moved);
};

// Une ligne jamais enregistrée part sans question ; une sous-analyse enregistrée
// demande confirmation : la retirer la désactive au prochain enregistrement.
const removing = ref(null);
const remove = (index) => {
    if (props.children[index]?.uuid) {
        removing.value = index;
        return;
    }
    props.children.splice(index, 1);
};
const confirmRemove = () => {
    if (removing.value !== null) props.children.splice(removing.value, 1);
    removing.value = null;
};

const entryModeOf = (child) => child.entry_mode ?? '';
const setEntryMode = (child, value) => { child.entry_mode = value || null; };
const setGroup = (child, isGroup) => {
    child.level = isGroup ? 'PARENT' : 'CHILD';
    if (! isGroup) child.children = [];
};
const filledReferences = (child) => ['reference_general', 'reference_male', 'reference_female', 'reference_child_male', 'reference_child_female']
    .filter((key) => String(child[key] ?? '').trim()).length;
</script>

<template>
    <Card :class="cn('space-y-3', depth === 0 ? 'p-5 sm:p-6' : 'border-dashed bg-muted/30 p-3 shadow-none')">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <h3 :class="cn('font-semibold text-foreground', depth === 0 ? 'text-base' : 'text-sm')">{{ title }}</h3>
                <Badge variant="secondary">{{ children.length }}</Badge>
            </div>
            <Button type="button" size="sm" variant="white-outline" @click="add"><Plus class="h-4 w-4" />Ajouter un résultat</Button>
        </header>
        <p v-if="depth === 0" class="text-sm text-muted-foreground">
            Dans l'ordre du compte rendu : les flèches déplacent une ligne. Le réglage fin d'un résultat est dans son détail.
            <span v-if="! canNest">Un niveau de plus se crée à part, rattaché par son « Groupe parent ».</span>
        </p>

        <ol v-if="children.length" class="space-y-2">
            <li
                v-for="(child, index) in children"
                :key="keyOf(child, index)"
                :class="cn('rounded-lg border bg-card', hasError(index) ? 'border-destructive/60' : 'border-border')"
            >
                <div class="flex flex-wrap items-start gap-2 p-2.5 lg:flex-nowrap">
                    <span class="mt-2.5 flex w-7 shrink-0 items-center text-xs font-semibold tabular-nums text-muted-foreground"><GripVertical class="h-3.5 w-3.5" aria-hidden="true" />{{ index + 1 }}</span>
                    <div class="order-last grid min-w-0 basis-full gap-2 sm:grid-cols-[minmax(0,1fr)_9rem] lg:order-none lg:flex-1 lg:basis-auto lg:grid-cols-[minmax(0,1fr)_9rem_9.5rem_7rem]">
                        <div>
                            <Input v-model="child.designation" size="sm" placeholder="Désignation *" autocomplete="off" :aria-label="`Désignation du résultat ${index + 1}`" :aria-invalid="Boolean(err(index, 'designation'))" />
                            <p v-if="err(index, 'designation')" class="mt-1 text-xs text-destructive">{{ err(index, 'designation') }}</p>
                        </div>
                        <div>
                            <Input v-model="child.code" size="sm" placeholder="Code *" autocomplete="off" class="font-mono uppercase" :aria-label="`Code du résultat ${index + 1}`" :aria-invalid="Boolean(err(index, 'code'))" />
                            <p v-if="err(index, 'code')" class="mt-1 text-xs text-destructive">{{ err(index, 'code') }}</p>
                        </div>
                        <Select v-model="child.result_type" :options="typeOptions" class="h-[var(--control-h-sm)] w-full min-w-0" :aria-label="`Type du résultat ${index + 1}`" />
                        <Input v-if="isNumericResult(child)" v-model="child.unit" size="sm" placeholder="Unité" autocomplete="off" :aria-label="`Unité du résultat ${index + 1}`" />
                        <span v-else class="hidden lg:block" />
                    </div>
                    <div class="ms-auto flex shrink-0 items-center gap-0.5 lg:ms-0">
                        <Button type="button" size="icon" variant="ghost" :disabled="index === 0" aria-label="Monter" @click="move(index, -1)"><ChevronUp class="h-4 w-4" /></Button>
                        <Button type="button" size="icon" variant="ghost" :disabled="index === children.length - 1" aria-label="Descendre" @click="move(index, 1)"><ChevronDown class="h-4 w-4" /></Button>
                        <Button
                            type="button"
                            size="icon"
                            :variant="isOpen(child, index) ? 'secondary' : 'ghost'"
                            :aria-expanded="isOpen(child, index)"
                            :aria-label="`Détail du résultat ${index + 1}`"
                            :title="`Détail : normes, saisie, bornes${canNest ? ', sous-groupe' : ''}`"
                            @click="toggle(child, index)"
                        >
                            <SlidersHorizontal class="h-4 w-4" />
                        </Button>
                        <Button type="button" size="icon" variant="ghost" class="text-destructive hover:text-destructive" :aria-label="`Retirer le résultat ${index + 1}`" @click="remove(index)"><Trash2 class="h-4 w-4" /></Button>
                    </div>
                </div>

                <p v-if="! isOpen(child, index) && (filledReferences(child) || child.level === 'PARENT')" class="-mt-1 px-12 pb-2 text-xs text-muted-foreground">
                    <template v-if="filledReferences(child)">{{ filledReferences(child) }} référence(s)</template>
                    <template v-if="child.level === 'PARENT'"> · sous-groupe de {{ child.children.length }} résultat(s)</template>
                </p>

                <div v-if="isOpen(child, index)" class="space-y-3 border-t border-border bg-muted/20 p-3">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <FormField label="Générale"><Input v-model="child.reference_general" size="sm" placeholder="12 – 16" /></FormField>
                        <FormField label="Homme"><Input v-model="child.reference_male" size="sm" /></FormField>
                        <FormField label="Femme"><Input v-model="child.reference_female" size="sm" /></FormField>
                        <FormField label="Enfant garçon"><Input v-model="child.reference_child_male" size="sm" /></FormField>
                        <FormField label="Enfant fille"><Input v-model="child.reference_child_female" size="sm" /></FormField>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField v-if="entryModes.length" as="div" label="Saisie au laboratoire">
                            <Select :model-value="entryModeOf(child)" :options="entryModeOptions(child)" class="h-[var(--control-h-sm)] w-full min-w-0" @update:model-value="setEntryMode(child, $event)" />
                        </FormField>
                        <FormField v-if="usesPredefinedValues(child)" label="Valeurs proposées" class="sm:col-span-2">
                            <Input v-model="child.predefined_values_text" size="sm" placeholder="Positif | Négatif" autocomplete="off" />
                        </FormField>
                    </div>
                    <CriticalRangesField
                        v-if="child.critical_ranges && isNumericResult(child)"
                        v-model="child.critical_ranges"
                        compact
                        :errors="form.errors"
                        :error-prefix="`${pathPrefix}.${index}.critical_ranges`"
                    />
                    <div class="flex flex-wrap gap-x-6 gap-y-2">
                        <label class="inline-flex items-center gap-2 text-sm text-foreground"><Switch v-model="child.is_bold" />Nom en gras</label>
                        <label v-if="canNest" class="inline-flex items-center gap-2 text-sm text-foreground">
                            <Switch :model-value="child.level === 'PARENT'" @update:model-value="setGroup(child, $event)" />
                            <FolderTree class="h-4 w-4 text-muted-foreground" />Ce résultat est lui-même un groupe
                        </label>
                    </div>

                    <SubAnalysesEditor
                        v-if="canNest && child.level === 'PARENT'"
                        :children="child.children"
                        :form="form"
                        :path-prefix="`${pathPrefix}.${index}.children`"
                        :result-types="resultTypes"
                        :entry-modes="entryModes"
                        :depth="depth + 1"
                        :max-depth="maxDepth"
                    />
                </div>
            </li>
        </ol>
        <div v-else class="rounded-lg border border-dashed border-border px-4 py-8 text-center">
            <p class="text-sm text-muted-foreground">Ce groupe n'a encore aucun résultat.</p>
            <Button type="button" size="sm" class="mt-3" @click="add"><Plus class="h-4 w-4" />Ajouter le premier résultat</Button>
        </div>

        <ConfirmModal
            :open="removing !== null"
            tone="danger"
            title="Retirer ce résultat du groupe ?"
            :description="`« ${children[removing]?.designation || children[removing]?.code || 'Ce résultat'} » sera désactivé au prochain enregistrement, jamais supprimé : ses anciens résultats restent lisibles, et il se réactive depuis le catalogue.`"
            confirm-label="Retirer"
            @update:open="(value) => { if (! value) removing = null; }"
            @confirm="confirmRemove"
        />
    </Card>
</template>
