<script setup>
import { computed } from 'vue';
import Button from '@/Components/Shadcn/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import { ChevronDown, ChevronUp, Plus, Trash2 } from 'lucide-vue-next';
import Input from '@/Components/Shadcn/Input.vue';
// Self-referencing: a sub-analysis can itself be a group, which then needs
// this exact same list rendered again for its own sub-analyses — capped at
// `maxDepth` so a request never has to validate an unbounded structure.
// eslint-disable-next-line vue/no-unused-components -- used recursively in the template below
import SubAnalysesEditor from './SubAnalysesEditor.vue';

const props = defineProps({
    children: { type: Array, required: true },
    form: { type: Object, required: true },
    pathPrefix: { type: String, required: true },
    resultTypes: { type: Array, required: true },
    examCategories: { type: Array, default: () => [] },
    depth: { type: Number, default: 0 },
    maxDepth: { type: Number, default: 1 },
});

const canNest = computed(() => props.depth < props.maxDepth);
const sectionLabel = computed(() => (props.depth === 0 ? 'Sous-analyses' : 'Sous-sous-analyses'));
const itemLabel = computed(() => (props.depth === 0 ? 'Sous-analyse' : 'Sous-sous-analyse'));

const emptyChild = () => ({
    uuid: null,
    code: '',
    designation: '',
    description: '',
    exam_category: '',
    level: 'CHILD',
    result_type: 'TEXT',
    reference_general: '',
    reference_male: '',
    reference_female: '',
    reference_child_male: '',
    reference_child_female: '',
    unit: '',
    predefined_values_text: '',
    is_bold: false,
    children: [],
});

const addChild = () => { props.children.push(emptyChild()); };
const removeChild = (index) => { props.children.splice(index, 1); };
const moveChild = (index, direction) => {
    const target = index + direction;
    if (target < 0 || target >= props.children.length) return;
    const [moved] = props.children.splice(index, 1);
    props.children.splice(target, 0, moved);
};

const errorFor = (index) => props.form.errors[`${props.pathPrefix}.${index}.code`]
    || props.form.errors[`${props.pathPrefix}.${index}.designation`]
    || props.form.errors[`${props.pathPrefix}.${index}.result_type`];

const typeLabel = (value) => ({ NUMERIC: 'Numérique', TEXT: 'Texte', CHOICE: 'Choix', BOOLEAN: 'Oui / Non' }[value] ?? value);
</script>

<template>
    <div class="rounded-lg border border-primary/30 bg-primary/5 p-4">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h3 class="text-sm font-bold text-foreground">{{ sectionLabel }}</h3>
                <span class="rounded bg-card px-2 py-0.5 text-xs font-bold text-primary">{{ children.length }}</span>
            </div>
            <Button type="button" size="sm" variant="white-outline" @click="addChild"><Plus class="h-4 w-4" />Ajouter</Button>
        </div>
        <p class="mt-1 text-[11px] text-muted-foreground">Un élément retiré d’ici est désactivé à l’enregistrement, jamais supprimé.<span v-if="!canNest"> Pour un niveau supplémentaire, enregistrez d’abord ce groupe puis rattachez la suite via « Groupe parent ».</span></p>

        <div v-if="children.length" class="mt-3 space-y-3">
            <div v-for="(child, index) in children" :key="child.uuid ?? `new-${depth}-${index}`" class="rounded border border-border bg-card p-3">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">{{ itemLabel }} #{{ index + 1 }}</span>
                    <div class="flex items-center gap-1">
                        <button type="button" class="flex h-6 w-6 items-center justify-center rounded text-muted-foreground hover:bg-muted disabled:opacity-30 dark:hover:bg-muted" :disabled="index === 0" aria-label="Monter" @click="moveChild(index, -1)"><ChevronUp class="h-3.5 w-3.5" /></button>
                        <button type="button" class="flex h-6 w-6 items-center justify-center rounded text-muted-foreground hover:bg-muted disabled:opacity-30 dark:hover:bg-muted" :disabled="index === children.length - 1" aria-label="Descendre" @click="moveChild(index, 1)"><ChevronDown class="h-3.5 w-3.5" /></button>
                        <button type="button" class="flex h-6 w-6 items-center justify-center rounded text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20" aria-label="Retirer" @click="removeChild(index)"><Trash2 class="h-3.5 w-3.5" /></button>
                    </div>
                </div>
                <div class="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <Input v-model="child.code" size="sm" placeholder="Code *" />
                    <Input v-model="child.designation" size="sm" placeholder="Désignation *" :class="canNest ? '' : 'xl:col-span-2'" />
                    <select v-if="canNest" v-model="child.level" class="block h-9 w-full appearance-none rounded border border-border bg-card px-3 pe-9 text-xs text-foreground outline-none focus:border-primary focus:ring-2 focus:ring-ring/25"><option value="CHILD">Sous-analyse</option><option value="PARENT">Groupe</option></select>
                    <select v-model="child.result_type" class="block h-9 w-full appearance-none rounded border border-border bg-card px-3 pe-9 text-xs text-foreground outline-none focus:border-primary focus:ring-2 focus:ring-ring/25"><option v-for="type in resultTypes" :key="type" :value="type">{{ typeLabel(type) }}</option></select>
                </div>
                <div class="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                    <Input v-model="child.reference_general" size="sm" placeholder="Référence générale" />
                    <Input v-model="child.reference_male" size="sm" placeholder="Homme" />
                    <Input v-model="child.reference_female" size="sm" placeholder="Femme" />
                    <Input v-model="child.reference_child_male" size="sm" placeholder="Garçon" />
                    <Input v-model="child.reference_child_female" size="sm" placeholder="Fille" />
                </div>
                <div class="mt-2 grid items-center gap-2 sm:grid-cols-[1fr_1fr_auto]">
                    <Input v-model="child.unit" size="sm" placeholder="Unité" />
                    <Input v-model="child.exam_category" size="sm" list="exam-category-options" placeholder="Examen" />
                    <label class="inline-flex items-center gap-1.5 whitespace-nowrap text-xs font-semibold text-muted-foreground"><input v-model="child.is_bold" type="checkbox" class="rounded border-input text-primary focus:ring-ring" />Gras</label>
                </div>
                <FormError class="mt-1" :message="errorFor(index)" />

                <SubAnalysesEditor
                    v-if="canNest && child.level === 'PARENT'"
                    class="mt-3"
                    :children="child.children"
                    :form="form"
                    :path-prefix="`${pathPrefix}.${index}.children`"
                    :result-types="resultTypes"
                    :exam-categories="examCategories"
                    :depth="depth + 1"
                    :max-depth="maxDepth"
                />
            </div>
        </div>
        <p v-else class="mt-3 rounded border border-dashed border-input px-3 py-4 text-center text-xs text-muted-foreground dark:border-border">Aucun élément pour l’instant.</p>
    </div>
</template>
