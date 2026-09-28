<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Activity, ArrowLeft } from 'lucide-vue-next';
import { computed } from 'vue';
import AnalysisForm from '@/Components/Analyses/AnalysisForm.vue';
import { analysisCatalogUrls, isPortalContext } from '@/utilities/analysisCatalogUrls';

defineOptions({ layout: AppLayout });

const props = defineProps({
    clinicSite: Object,
    context: { type: Object, default: () => ({ mode: 'portal' }) },
    catalogItems: Array,
    parents: Array,
    levels: Array,
    resultTypes: Array,
    entryModes: { type: Array, default: () => [] },
    examCategories: { type: Array, default: () => [] },
});

const portal = isPortalContext(props.context);
const urls = computed(() => analysisCatalogUrls(props.context, props.clinicSite.code));

// Le portail désigne le site destinataire ; sur un site, c'est implicite.
const form = useForm({
    ...(portal ? { site_code: props.clinicSite.code } : {}),
    catalog_item_uuid: props.catalogItems[0]?.uuid ?? '',
    parent_uuid: '',
    code: '',
    level: 'NORMAL',
    designation: '',
    description: '',
    exam_category: '',
    result_type: 'TEXT',
    entry_mode: null,
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
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded bg-primary/10 text-primary"><Activity class="h-5 w-5" /></span>
                <div><p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ portal ? 'Super Administration · Laboratoire' : 'Administration · Référentiel clinique' }}</p><h1 class="mt-0.5 font-heading text-2xl font-bold text-foreground">Nouvelle définition d’analyse</h1><p class="mt-1 text-sm text-muted-foreground">Site : {{ clinicSite.name }} · la prestation porte le tarif, cette fiche structure le résultat.</p></div>
            </div>
            <Link :href="urls.index" class="inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-card px-3 text-sm font-bold text-muted-foreground hover:border-input hover:text-primary"><ArrowLeft class="h-4 w-4" /> Retour au catalogue</Link>
        </header>

        <AnalysisForm
            :form="form"
            :catalog-items="catalogItems"
            :parents="parents"
            :levels="levels"
            :result-types="resultTypes"
            :entry-modes="entryModes"
            :exam-categories="examCategories"
            submit-label="Créer l’analyse"
            :submit-url="urls.store"
            submit-method="post"
            :cancel-href="urls.index"
        />
    </div>
</template>
