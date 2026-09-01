<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import SubAnalysesEditor from './SubAnalysesEditor.vue';

const props = defineProps({
    form: { type: Object, required: true },
    analysisUuid: { type: String, default: null },
    hierarchyPath: { type: String, default: '' },
    catalogItems: { type: Array, required: true },
    parents: { type: Array, required: true },
    levels: { type: Array, required: true },
    resultTypes: { type: Array, required: true },
    examCategories: { type: Array, default: () => [] },
    submitLabel: { type: String, required: true },
    submitUrl: { type: String, required: true },
    submitMethod: { type: String, required: true },
    cancelHref: { type: String, required: true },
});

// A group (PARENT) can itself sit inside another group — only a terminal
// result (CHILD) is required to have one and a standalone analysis (NORMAL)
// is required to have none; PARENT may be a root or nested either way.
const mayHaveParent = (level) => level === 'PARENT' || level === 'CHILD';

// A group can never be moved under itself or under its own descendants —
// the backend rejects that as a cycle anyway, but excluding it here (via the
// breadcrumb path already computed server-side) means the dropdown never
// even offers an option that would just bounce back with an error.
const availableParents = computed(() => props.parents.filter((parent) => {
    if (parent.catalog_item_uuid !== props.form.catalog_item_uuid) return false;
    if (!props.analysisUuid) return true;
    if (parent.uuid === props.analysisUuid) return false;
    return !props.hierarchyPath || !parent.path.startsWith(`${props.hierarchyPath} › `);
}));

const selectClass = 'block h-9 w-full appearance-none rounded border border-gray-200 bg-white px-3 pe-9 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';

// Sub-analyses are edited inline up to two levels deep (children, then
// their own children) — a node needing a third level still goes through
// the normal Groupe parent picker above as a separate entry.
const splitPredefinedValues = (text) => text.split('|').map((value) => value.trim()).filter(Boolean);
const transformChild = (child, index) => ({
    ...child,
    display_order: index + 1,
    predefined_values: splitPredefinedValues(child.predefined_values_text),
    children: child.level === 'PARENT' ? child.children.map(transformChild) : [],
});
const payload = (data) => ({
    ...data,
    parent_uuid: mayHaveParent(data.level) ? data.parent_uuid : null,
    predefined_values: splitPredefinedValues(data.predefined_values_text),
    children: data.level === 'PARENT' ? data.children.map(transformChild) : [],
});

const submit = () => props.form.transform(payload)[props.submitMethod](props.submitUrl, { preserveScroll: true });

const levelLabel = (value) => ({ PARENT: 'Groupe', CHILD: 'Sous-analyse', NORMAL: 'Analyse simple' }[value] ?? value);
const levelHint = (value) => ({
    PARENT: 'Peut contenir des sous-éléments et être lui-même rattaché à un autre groupe.',
    CHILD: 'Résultat terminal : un groupe parent est obligatoire.',
    NORMAL: 'Analyse autonome : jamais de parent.',
}[value] ?? '');
const typeLabel = (value) => ({ NUMERIC: 'Numérique', TEXT: 'Texte', CHOICE: 'Choix', BOOLEAN: 'Oui / Non' }[value] ?? value);
const typeHint = (value) => ({
    NUMERIC: 'Valeur numérique précise avec unité de mesure. Ex : Glycémie (1,05 g/L).',
    TEXT: 'Texte libre ou description qualitative. Ex : Aspect macroscopique.',
    CHOICE: 'Choix parmi une liste de valeurs prédéfinies (ci-dessous). Ex : Groupe sanguin.',
    BOOLEAN: 'Résultat à deux états. Ex : Positif / Négatif.',
}[value] ?? '');
</script>

<template>
    <Card class="overflow-hidden shadow-sm">
        <form class="space-y-5 p-5" @submit.prevent="submit">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <label class="text-xs font-semibold text-slate-600 dark:text-slate-300">Prestation Laboratoire *<select v-model="form.catalog_item_uuid" :class="selectClass" required @change="form.parent_uuid = ''"><option v-for="item in catalogItems" :key="item.uuid" :value="item.uuid">{{ item.code }} · {{ item.name }}</option></select></label>
                <label class="text-xs font-semibold text-slate-600 dark:text-slate-300">Code *<Input v-model="form.code" required placeholder="LAB-NFS-HB" /></label>
                <label class="text-xs font-semibold text-slate-600 dark:text-slate-300">Niveau *<select v-model="form.level" :class="selectClass" required @change="form.parent_uuid = ''"><option v-for="level in levels" :key="level" :value="level">{{ levelLabel(level) }}</option></select><span class="mt-1 block text-[10px] font-normal leading-4 text-slate-400">{{ levelHint(form.level) }}</span></label>
                <label v-if="mayHaveParent(form.level)" class="text-xs font-semibold text-slate-600 dark:text-slate-300">{{ form.level === 'PARENT' ? 'Groupe parent (facultatif)' : 'Groupe parent *' }}<select v-model="form.parent_uuid" :class="selectClass" :required="form.level === 'CHILD'"><option value="">{{ form.level === 'PARENT' ? 'Aucun — groupe racine' : 'Choisir' }}</option><option v-for="parent in availableParents" :key="parent.uuid" :value="parent.uuid">{{ parent.path }}</option></select></label>
                <label :class="['text-xs font-semibold text-slate-600 dark:text-slate-300', !mayHaveParent(form.level) && 'xl:col-span-1']">Désignation *<Input v-model="form.designation" required placeholder="Hémoglobine" /></label>
                <label class="text-xs font-semibold text-slate-600 dark:text-slate-300">Examen<Input v-model="form.exam_category" list="exam-category-options" placeholder="BIOCHIMIE, HEMATOLOGIE…" /></label>
                <label class="text-xs font-semibold text-slate-600 dark:text-slate-300">Type de résultat *<select v-model="form.result_type" :class="selectClass" required><option v-for="type in resultTypes" :key="type" :value="type">{{ typeLabel(type) }}</option></select><span class="mt-1 block text-[10px] font-normal leading-4 text-slate-400">{{ typeHint(form.result_type) }}</span></label>
                <label class="text-xs font-semibold text-slate-600 dark:text-slate-300">Unité<Input v-model="form.unit" placeholder="g/dL, mmol/L…" /></label>
                <label class="text-xs font-semibold text-slate-600 dark:text-slate-300">Ordre<Input v-model="form.display_order" type="number" min="0" /></label>
            </div>
            <datalist id="exam-category-options"><option v-for="category in examCategories" :key="category" :value="category" /></datalist>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5"><label v-for="field in [{ key: 'reference_general', label: 'Référence générale' }, { key: 'reference_male', label: 'Homme' }, { key: 'reference_female', label: 'Femme' }, { key: 'reference_child_male', label: 'Enfant garçon' }, { key: 'reference_child_female', label: 'Enfant fille' }]" :key="field.key" class="text-xs font-semibold text-slate-600 dark:text-slate-300">{{ field.label }}<Input v-model="form[field.key]" placeholder="Intervalle ou texte" /></label></div>
            <div class="grid gap-4 lg:grid-cols-2"><label class="text-xs font-semibold text-slate-600 dark:text-slate-300">Valeurs prédéfinies<Input v-model="form.predefined_values_text" placeholder="Positif|Négatif|Indéterminé" /><span class="mt-1 block font-normal text-slate-400">Séparez les choix par |</span></label><label class="text-xs font-semibold text-slate-600 dark:text-slate-300">Description<Input v-model="form.description" placeholder="Méthode ou précision utile" /></label></div>
            <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-600 dark:text-slate-300"><input v-model="form.is_bold" type="checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />Gras à l’impression</label>

            <SubAnalysesEditor
                v-if="form.level === 'PARENT'"
                :children="form.children"
                :form="form"
                path-prefix="children"
                :result-types="resultTypes"
                :exam-categories="examCategories"
                :depth="0"
                :max-depth="1"
            />

            <FormError :message="Object.values(form.errors)[0]" />
            <div class="flex justify-end gap-2 border-t border-gray-200 pt-4 dark:border-gray-900"><Link :href="cancelHref" class="inline-flex h-10 items-center rounded-lg border border-gray-200 bg-white px-4 text-sm font-bold text-slate-600 hover:border-gray-300 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200">Annuler</Link><Button type="submit" size="rg" :disabled="form.processing"><Icon class="me-2" name="save" />{{ submitLabel }}</Button></div>
        </form>
    </Card>
</template>
