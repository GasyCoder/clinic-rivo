<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';
import AnalysisForm from './AnalysisForm.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    catalogItems: Array,
    parents: Array,
    levels: Array,
    resultTypes: Array,
    examCategories: { type: Array, default: () => [] },
});

const form = useForm({
    catalog_item_uuid: props.catalogItems[0]?.uuid ?? '',
    parent_uuid: '',
    code: '',
    level: 'NORMAL',
    designation: '',
    description: '',
    exam_category: '',
    result_type: 'TEXT',
    reference_general: '',
    reference_male: '',
    reference_female: '',
    reference_child_male: '',
    reference_child_female: '',
    unit: '',
    predefined_values_text: '',
    display_order: 0,
    is_active: true,
    is_bold: false,
    // Sub-analyses edited inline, one level deep — a sub-analysis needing
    // its own sub-analyses still goes through the normal Groupe parent
    // picker above as a separate entry.
    children: [],
});
</script>

<template>
    <Head title="Nouvelle analyse" />
    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-primary-50 text-primary-700 dark:bg-primary-950/30 dark:text-primary-300"><Icon class="text-xl" name="activity" /></span>
                <div><p class="text-xs font-medium uppercase tracking-wide text-slate-400">Administration · Référentiel clinique</p><h1 class="mt-0.5 font-heading text-2xl font-bold text-slate-700 dark:text-white">Nouvelle définition d’analyse</h1><p class="mt-1 text-sm text-slate-500">La prestation porte la facturation ; cette fiche porte uniquement la structure du résultat.</p></div>
            </div>
            <Link href="/administration/analyses" class="inline-flex h-10 items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 text-sm font-bold text-slate-600 hover:border-gray-300 hover:text-primary-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200"><Icon name="arrow-left" /> Retour au catalogue</Link>
        </header>

        <AnalysisForm
            :form="form"
            :catalog-items="catalogItems"
            :parents="parents"
            :levels="levels"
            :result-types="resultTypes"
            :exam-categories="examCategories"
            submit-label="Créer l’analyse"
            submit-url="/administration/analyses"
            submit-method="post"
            cancel-href="/administration/analyses"
        />
    </div>
</template>
