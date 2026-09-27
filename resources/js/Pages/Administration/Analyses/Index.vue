<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconInput from '@/Components/UI/IconInput.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({
    analyses: Object,
    catalogItems: Array,
    filters: Object,
    summary: Object,
});

const { can } = usePermissions();
const search = ref(props.filters.q ?? '');
const status = ref(props.filters.status ?? 'ACTIVE');
const catalogItem = ref(props.filters.catalog_item ?? '');
const showImport = ref(false);
const importForm = useForm({ file: null });
const selectClass = 'block h-9 w-full appearance-none rounded border border-gray-200 bg-white px-3 pe-9 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';

const applyFilters = () => router.get('/administration/analyses', {
    q: search.value || undefined,
    status: status.value,
    catalog_item: catalogItem.value || undefined,
}, { preserveState: true, replace: true });

const toggleActive = (analysis) => router.post(
    `/administration/analyses/${analysis.uuid}/${analysis.is_active ? 'deactivate' : 'activate'}`,
    {}, { preserveScroll: true },
);
const submitImport = () => importForm.post('/administration/analyses/import', {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => { importForm.reset(); showImport.value = false; },
});
const levelLabel = (value) => ({ PARENT: 'Groupe', CHILD: 'Sous-analyse', NORMAL: 'Analyse simple' }[value] ?? value);
const typeLabel = (value) => ({ NUMERIC: 'Numérique', TEXT: 'Texte', CHOICE: 'Choix', BOOLEAN: 'Oui / Non' }[value] ?? value);
// Ancestors only — hierarchy_path already ends with the item's own
// designation (AnalysisCatalogHierarchy::resolve()).
const ancestorPath = (analysis) => (analysis.hierarchy_path ?? '').split(' › ').slice(0, -1).join(' › ');

// The list arrives flat, sorted by (prestation, ordre, désignation) — not by
// tree position, so a depth-2 result can appear before its own parent. This
// re-flattens it into a real pre-order walk (root, then its children in the
// same order the backend already uses) so hierarchy_depth indentation reads
// as an actual tree instead of looking broken.
const orderedAnalyses = computed(() => {
    const sortSiblings = (a, b) => (a.display_order - b.display_order) || a.designation.localeCompare(b.designation, 'fr');
    const byCatalogItem = new Map();
    for (const item of props.analyses.data) {
        const key = item.catalog_item.uuid;
        if (!byCatalogItem.has(key)) byCatalogItem.set(key, []);
        byCatalogItem.get(key).push(item);
    }

    const ordered = [];
    for (const items of byCatalogItem.values()) {
        const knownUuids = new Set(items.map((item) => item.uuid));
        const childrenByParent = new Map();
        const roots = [];
        for (const item of items) {
            const parentUuid = item.parent?.uuid;
            if (parentUuid && knownUuids.has(parentUuid)) {
                if (!childrenByParent.has(parentUuid)) childrenByParent.set(parentUuid, []);
                childrenByParent.get(parentUuid).push(item);
            } else {
                roots.push(item);
            }
        }
        roots.sort(sortSiblings);
        for (const siblings of childrenByParent.values()) siblings.sort(sortSiblings);

        const visit = (item) => {
            ordered.push(item);
            (childrenByParent.get(item.uuid) ?? []).forEach(visit);
        };
        roots.forEach(visit);
    }

    return ordered;
});
</script>

<template>
    <Head title="Catalogue des analyses" />
    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-primary-50 text-primary-700 dark:bg-primary-950/30 dark:text-primary-300"><Icon class="text-xl" name="activity" /></span>
                <div><p class="text-xs font-medium uppercase tracking-wide text-slate-400">Administration · Référentiel clinique</p><h1 class="mt-0.5 font-heading text-2xl font-bold text-slate-700 dark:text-white">Catalogue des analyses</h1><p class="mt-1 text-sm text-slate-500">Structure des examens Laboratoire, unités et références adaptées au profil du patient.</p></div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="can('analysis_catalog.export')" as="a" :href="`/administration/analyses/export?status=${status}`" size="rg" variant="white-outline"><Icon class="me-2" name="download" />Exporter Excel</Button>
                <Button v-if="can('analysis_catalog.import')" type="button" size="rg" variant="white-outline" @click="showImport = !showImport"><Icon class="me-2" :name="showImport ? 'cross' : 'upload'" />{{ showImport ? 'Fermer' : 'Importer Excel' }}</Button>
                <Button v-if="can('analysis_catalog.create')" as="a" href="/administration/analyses/create" size="rg"><Icon class="me-2" name="plus" />Nouvelle analyse</Button>
            </div>
        </header>

        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Card v-for="metric in [{ label: 'Analyses actives', value: summary.active }, { label: 'Analyses inactives', value: summary.inactive }, { label: 'Prestations Laboratoire', value: summary.services }, { label: 'Groupes imbriqués', value: summary.nested_groups }]" :key="metric.label" class="p-4 shadow-sm"><p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">{{ metric.label }}</p><p class="mt-1 text-xl font-bold tabular-nums text-slate-700 dark:text-white">{{ metric.value }}</p></Card>
        </section>

        <Card v-if="showImport" class="overflow-hidden border-primary-200 shadow-sm dark:border-primary-900">
            <div class="flex flex-col gap-4 p-5 lg:flex-row lg:items-end lg:justify-between">
                <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Importer un classeur Excel</h2><p class="mt-1 text-xs leading-5 text-slate-500">L’import est atomique : une ligne invalide annule tout le fichier. Les codes existants sont mis à jour.</p><a class="mt-2 inline-flex items-center gap-1 text-xs font-bold text-primary-700 hover:underline dark:text-primary-300" href="/administration/analyses/import-template"><Icon name="download" />Télécharger le modèle</a></div>
                <form class="flex flex-col gap-2 sm:flex-row sm:items-end" @submit.prevent="submitImport"><label class="text-xs font-semibold text-slate-600 dark:text-slate-300">Fichier .xlsx<input class="mt-1 block w-full rounded border border-gray-200 bg-white px-3 py-2 text-xs dark:border-gray-800 dark:bg-gray-950" type="file" accept=".xlsx" required @change="importForm.file = $event.target.files[0]" /></label><Button size="rg" type="submit" :disabled="importForm.processing || !importForm.file">Importer</Button><FormError :message="importForm.errors.file" /></form>
            </div>
        </Card>

        <Card class="overflow-hidden shadow-sm">
            <div class="grid gap-3 border-b border-gray-200 p-4 dark:border-gray-900 lg:grid-cols-[minmax(18rem,1fr)_15rem_12rem_auto]">
                <IconInput v-model="search" icon="search" placeholder="Code, analyse ou prestation…" @keyup.enter="applyFilters" />
                <select v-model="catalogItem" :class="selectClass" @change="applyFilters"><option value="">Toutes les prestations</option><option v-for="item in catalogItems" :key="item.uuid" :value="item.uuid">{{ item.code }} · {{ item.name }}</option></select>
                <select v-model="status" :class="selectClass" @change="applyFilters"><option value="ACTIVE">Actives</option><option value="INACTIVE">Inactives</option><option value="ALL">Toutes</option></select>
                <Button type="button" size="rg" variant="white-outline" @click="applyFilters">Filtrer</Button>
            </div>
            <div class="overflow-x-auto"><table class="w-full min-w-[1250px] border-collapse"><thead class="bg-gray-50/70 dark:bg-gray-1000/40"><tr><th v-for="heading in ['Prestation', 'Analyse', 'Examen', 'Type', 'Références', 'Unité', 'Statut', 'Actions']" :key="heading" :class="['px-4 py-2.5 text-[10px] font-bold uppercase tracking-wide text-slate-400', heading === 'Actions' ? 'text-end' : 'text-start']">{{ heading }}</th></tr></thead><tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                <tr v-for="analysis in orderedAnalyses" :key="analysis.uuid" class="hover:bg-gray-50/60 dark:hover:bg-gray-1000/30"><td class="px-4 py-3"><p class="text-xs font-bold text-slate-700 dark:text-white">{{ analysis.catalog_item.name }}</p><code class="text-[10px] text-slate-400">{{ analysis.catalog_item.code }}</code></td><td class="px-4 py-3"><div class="flex items-center gap-2" :style="{ paddingInlineStart: `${analysis.hierarchy_depth * 1.25}rem` }"><Icon v-if="analysis.hierarchy_depth > 0" name="chevron-right" class="shrink-0 text-xs text-slate-300" /><code class="text-xs font-bold text-primary-700 dark:text-primary-300">{{ analysis.code }}</code><span class="rounded bg-gray-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-slate-500 dark:bg-gray-900">{{ levelLabel(analysis.level) }}</span><Icon v-if="analysis.is_bold" name="bold" class="shrink-0 text-xs text-slate-400" title="Gras à l’impression" /></div><p :class="['mt-1 text-sm text-slate-700 dark:text-white', analysis.is_bold ? 'font-bold' : 'font-semibold']" :style="{ paddingInlineStart: `${analysis.hierarchy_depth * 1.25}rem` }">{{ analysis.designation }}</p><p v-if="analysis.hierarchy_depth > 0" class="mt-0.5 truncate text-[10px] text-slate-400" :style="{ paddingInlineStart: `${analysis.hierarchy_depth * 1.25}rem` }" :title="ancestorPath(analysis)">Sous {{ ancestorPath(analysis) }}</p></td><td class="px-4 py-3 text-xs text-slate-500">{{ analysis.exam_category || '—' }}</td><td class="px-4 py-3 text-xs text-slate-500">{{ typeLabel(analysis.result_type) }}<p v-if="analysis.predefined_values.length" class="mt-1 max-w-48 truncate text-[10px] text-slate-400">{{ analysis.predefined_values.join(' · ') }}</p></td><td class="px-4 py-3 text-xs leading-5 text-slate-500"><p v-if="analysis.reference_general">Gén. {{ analysis.reference_general }}</p><p v-if="analysis.reference_male">H {{ analysis.reference_male }}</p><p v-if="analysis.reference_female">F {{ analysis.reference_female }}</p><span v-if="!analysis.reference_general && !analysis.reference_male && !analysis.reference_female" class="text-slate-300">Non configurée</span></td><td class="px-4 py-3 text-xs font-semibold text-slate-500">{{ analysis.unit || '—' }}</td><td class="px-4 py-3"><span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', analysis.is_active ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-gray-100 text-slate-500 dark:bg-gray-900']">{{ analysis.is_active ? 'Active' : 'Inactive' }}</span></td><td class="px-4 py-3"><div class="flex justify-end gap-2"><Link v-if="can('analysis_catalog.update')" :href="`/administration/analyses/${analysis.uuid}/edit`" class="inline-flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:border-primary-300 hover:text-primary-600 dark:border-gray-800"><Icon name="edit" /></Link><Button v-if="can(analysis.is_active ? 'analysis_catalog.deactivate' : 'analysis_catalog.activate')" type="button" size="sm" :variant="analysis.is_active ? 'warning' : 'success'" @click="toggleActive(analysis)">{{ analysis.is_active ? 'Désactiver' : 'Activer' }}</Button></div></td></tr>
                <tr v-if="orderedAnalyses.length === 0"><td colspan="8" class="px-5 py-14 text-center text-sm text-slate-400">Aucune analyse ne correspond à ces filtres.</td></tr>
            </tbody></table></div>
            <div v-if="analyses.links?.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-gray-200 p-4 dark:border-gray-900"><Link v-for="link in analyses.links" :key="link.label" :href="link.url || '#'" :class="['rounded border px-3 py-1.5 text-xs font-semibold', link.active ? 'border-primary-600 bg-primary-600 text-white' : 'border-gray-200 text-slate-500 dark:border-gray-800', !link.url && 'pointer-events-none opacity-40']" v-html="link.label" /></div>
        </Card>
    </div>
</template>
