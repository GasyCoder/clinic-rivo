<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';
import AnalysisForm from './AnalysisForm.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    clinicSite: Object,
    analysis: Object,
    catalogItems: Array,
    parents: Array,
    levels: Array,
    resultTypes: Array,
    examCategories: { type: Array, default: () => [] },
});

// Recursive: a sub-analysis can itself be a group, carrying its own
// children (grandchildren from the root's point of view) one level deeper.
const childRowFrom = (row) => ({
    uuid: row.uuid,
    code: row.code,
    designation: row.designation,
    description: row.description ?? '',
    exam_category: row.exam_category ?? '',
    level: row.level,
    result_type: row.result_type,
    reference_general: row.reference_general ?? '',
    reference_male: row.reference_male ?? '',
    reference_female: row.reference_female ?? '',
    reference_child_male: row.reference_child_male ?? '',
    reference_child_female: row.reference_child_female ?? '',
    unit: row.unit ?? '',
    predefined_values_text: (row.predefined_values ?? []).join('|'),
    is_bold: row.is_bold ?? false,
    children: row.level === 'PARENT' ? (row.children ?? []).map(childRowFrom) : [],
});

const form = useForm({
    site_code: props.clinicSite.code,
    catalog_item_uuid: props.analysis.catalog_item.uuid,
    parent_uuid: props.analysis.parent?.uuid ?? '',
    code: props.analysis.code,
    level: props.analysis.level,
    designation: props.analysis.designation,
    description: props.analysis.description ?? '',
    exam_category: props.analysis.exam_category ?? '',
    result_type: props.analysis.result_type,
    reference_general: props.analysis.reference_general ?? '',
    reference_male: props.analysis.reference_male ?? '',
    reference_female: props.analysis.reference_female ?? '',
    reference_child_male: props.analysis.reference_child_male ?? '',
    reference_child_female: props.analysis.reference_child_female ?? '',
    unit: props.analysis.unit ?? '',
    predefined_values_text: (props.analysis.predefined_values ?? []).join('|'),
    display_order: props.analysis.display_order,
    is_active: props.analysis.is_active,
    is_bold: props.analysis.is_bold ?? false,
    children: props.analysis.level === 'PARENT' ? (props.analysis.children ?? []).map(childRowFrom) : [],
});
</script>

<template>
    <Head :title="`Modifier ${analysis.designation}`" />
    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded bg-primary-50 text-primary-700 dark:bg-primary-950/30 dark:text-primary-300"><Icon class="text-xl" name="activity" /></span>
                <div><p class="text-xs font-medium uppercase tracking-wide text-slate-400">Super Administration · Laboratoire</p><h1 class="mt-0.5 font-heading text-2xl font-bold text-slate-700 dark:text-white">Modifier « {{ analysis.designation }} »</h1><p class="mt-1 text-sm text-slate-500">Site : {{ clinicSite.name }} · la prestation porte le tarif, cette fiche structure le résultat.</p></div>
            </div>
            <Link href="/super-admin/analyses" class="inline-flex h-10 items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 text-sm font-bold text-slate-600 hover:border-gray-300 hover:text-primary-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200"><Icon name="arrow-left" /> Retour au catalogue</Link>
        </header>

        <AnalysisForm
            :form="form"
            :analysis-uuid="analysis.uuid"
            :hierarchy-path="analysis.hierarchy_path"
            :catalog-items="catalogItems"
            :parents="parents"
            :levels="levels"
            :result-types="resultTypes"
            :exam-categories="examCategories"
            submit-label="Enregistrer"
            :submit-url="`/super-admin/analyses/${clinicSite.code}/${analysis.uuid}`"
            submit-method="put"
            cancel-href="/super-admin/analyses"
        />
    </div>
</template>
