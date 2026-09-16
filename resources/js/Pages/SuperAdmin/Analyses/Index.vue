<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/UI/Card.vue';
import FormError from '@/Components/UI/FormError.vue';
import { Activity, Bold, ChevronRight, Download, Pencil, Plus, Server, Upload, X } from 'lucide-vue-next';
import IconInput from '@/Components/UI/IconInput.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({ sites: Array, filters: Object });
const { can } = usePermissions();
const selectedSiteCode = ref(props.sites.find((site) => site.ok)?.site.code ?? props.sites[0]?.site.code ?? '');
const search = ref(props.filters.q ?? '');
const status = ref(props.filters.status ?? 'ALL');
const catalogItem = ref('');
const pageNumber = ref(1);
const pageSize = 50;
const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value));
const siteData = computed(() => selectedSite.value?.data ?? {});
const analyses = computed(() => (siteData.value.analyses ?? []).filter((analysis) => (
    !catalogItem.value || analysis.catalog_item?.uuid === catalogItem.value
)));
const pageCount = computed(() => Math.max(1, Math.ceil(orderedAnalyses.value.length / pageSize)));
const visibleAnalyses = computed(() => orderedAnalyses.value.slice(
    (pageNumber.value - 1) * pageSize,
    pageNumber.value * pageSize,
));
const catalogItems = computed(() => siteData.value.catalog_items ?? []);
const summary = computed(() => selectedSite.value?.meta?.summary ?? { active: 0, inactive: 0, services: 0, nested_groups: 0, displayed: 0 });
const showImport = ref(false);

// The API returns the site's analyses flat, sorted by (prestation, ordre,
// désignation) — not by tree position. Re-flatten into a real pre-order walk
// so hierarchy_depth indentation reads as an actual tree instead of looking
// broken, mirroring AnalysisCatalog's own children() ordering.
const orderedAnalyses = computed(() => {
    const sortSiblings = (a, b) => (a.display_order - b.display_order) || a.designation.localeCompare(b.designation, 'fr');
    const byCatalogItem = new Map();
    for (const item of analyses.value) {
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

const importForm = useForm({ site_code: selectedSiteCode.value, file: null });
const selectClass = 'block h-10 w-full appearance-none rounded border border-border bg-card px-3 pe-9 text-sm text-foreground outline-none focus:border-primary focus:ring-2 focus:ring-ring/25 ';
const exportUrl = computed(() => `/super-admin/analyses/export?site_code=${selectedSiteCode.value}&status=${status.value}`);
const createUrl = computed(() => `/super-admin/analyses/${selectedSiteCode.value}/create`);

const levelLabel = (value) => ({ PARENT: 'Groupe', CHILD: 'Sous-analyse', NORMAL: 'Analyse simple' }[value] ?? value);
const typeLabel = (value) => ({ NUMERIC: 'Numérique', TEXT: 'Texte', CHOICE: 'Choix', BOOLEAN: 'Oui / Non' }[value] ?? value);
// Ancestors only — hierarchy_path already ends with the item's own
// designation (AnalysisCatalogHierarchy::resolve()).
const ancestorPath = (analysis) => (analysis.hierarchy_path ?? '').split(' › ').slice(0, -1).join(' › ');
const selectSite = (code) => {
    selectedSiteCode.value = code;
    importForm.site_code = code;
    catalogItem.value = '';
    pageNumber.value = 1;
};
const applyFilters = () => router.get('/super-admin/analyses', {
    q: search.value || undefined,
    status: status.value,
}, { preserveState: true, replace: true });
const toggleActive = (analysis) => router.post(
    `/super-admin/analyses/${selectedSiteCode.value}/${analysis.uuid}/${analysis.is_active ? 'deactivate' : 'activate'}`,
    {}, { preserveScroll: true },
);
const submitImport = () => importForm.post('/super-admin/analyses/import', {
    forceFormData: true, preserveScroll: true,
    onSuccess: () => { importForm.reset('file'); showImport.value = false; },
});
</script>

<template>
    <Head title="Catalogue des analyses" />
    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded bg-primary/10 text-primary"><Activity class="h-5 w-5" /></span>
                <div><p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Super Administration · Laboratoire</p><h1 class="mt-0.5 font-heading text-2xl font-bold text-foreground">Catalogue des analyses</h1><p class="mt-1 text-sm text-muted-foreground">Définitions, unités et valeurs de référence propres à chaque clinique.</p></div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="can('analysis_catalog.export')" as="a" :href="exportUrl" size="rg" variant="white-outline" :class="!selectedSite?.ok && 'pointer-events-none opacity-50'"><Download class="me-2 h-4 w-4" />Exporter Excel</Button>
                <Button v-if="can('analysis_catalog.import')" type="button" size="rg" variant="white-outline" @click="showImport = !showImport"><component class="me-2" :is="showImport ? X : Upload" />{{ showImport ? 'Fermer' : 'Importer Excel' }}</Button>
                <Button v-if="can('analysis_catalog.create')" as="a" :href="createUrl" size="rg" :class="!selectedSite?.ok && 'pointer-events-none opacity-50'"><Plus class="me-2 h-4 w-4" />Nouvelle analyse</Button>
            </div>
        </header>

        <section class="overflow-hidden rounded-lg border border-border bg-card">
            <div class="flex flex-wrap gap-1 border-b border-border bg-muted p-1.5">
                <button v-for="site in sites" :key="site.site.code" type="button" :class="['flex min-w-40 flex-1 items-center justify-between rounded px-4 py-2.5 text-start text-xs font-bold transition', selectedSiteCode === site.site.code ? 'bg-card text-foreground shadow-sm ' : 'text-muted-foreground hover:text-foreground']" @click="selectSite(site.site.code)">
                    <span>{{ site.site.name }}</span><span :class="['rounded px-2 py-0.5 text-[10px]', site.ok ? 'bg-primary/10 text-primary ' : 'bg-red-50 text-red-600 dark:bg-red-950/30']">{{ site.ok ? `${site.meta?.summary?.active ?? 0} actives` : 'Indisponible' }}</span>
                </button>
            </div>
            <div v-if="selectedSite?.ok" class="grid grid-cols-2 divide-x divide-border lg:grid-cols-4">
                <div v-for="metric in [{ label: 'Analyses actives', value: summary.active }, { label: 'Analyses inactives', value: summary.inactive }, { label: 'Prestations Laboratoire', value: summary.services }, { label: 'Groupes imbriqués', value: summary.nested_groups }]" :key="metric.label" class="px-5 py-4"><p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">{{ metric.label }}</p><p class="mt-1 text-xl font-bold tabular-nums text-foreground">{{ metric.value }}</p></div>
            </div>
            <div v-else class="flex items-center gap-3 px-5 py-5 text-sm text-muted-foreground"><Server class="text-red-500 h-5 w-5" /><span>{{ selectedSite?.message }}</span></div>
        </section>

        <Card v-if="showImport" class="overflow-hidden border-primary/30 shadow-sm">
            <form class="grid gap-4 p-5 lg:grid-cols-[220px_minmax(0,1fr)_auto] lg:items-end" @submit.prevent="submitImport">
                <label class="text-xs font-semibold text-muted-foreground">Site destinataire<select v-model="importForm.site_code" :class="selectClass"><option v-for="site in sites" :key="site.site.code" :value="site.site.code" :disabled="!site.ok">{{ site.site.name }}{{ site.ok ? '' : ' — indisponible' }}</option></select></label>
                <label class="text-xs font-semibold text-muted-foreground"><span class="flex justify-between gap-3"><span>Fichier .xlsx</span><a href="/super-admin/analyses/import-template" class="text-primary hover:underline">Télécharger le modèle</a></span><input class="mt-1 block h-10 w-full rounded border border-border bg-card px-3 py-2 text-xs" type="file" accept=".xlsx" required @change="importForm.file = $event.target.files[0]" /><FormError :message="Object.values(importForm.errors)[0]" /></label>
                <Button size="rg" type="submit" :disabled="importForm.processing || !importForm.file">Importer sur le site</Button>
            </form>
        </Card>

        <Card class="overflow-hidden shadow-sm">
            <div class="grid gap-3 border-b border-border p-4 lg:grid-cols-[minmax(18rem,1fr)_15rem_12rem_auto]"><IconInput v-model="search" icon="search" placeholder="Code, analyse ou prestation…" @keyup.enter="applyFilters" /><select v-model="catalogItem" :class="selectClass" @change="pageNumber = 1"><option value="">Toutes les prestations</option><option v-for="item in catalogItems" :key="item.uuid" :value="item.uuid">{{ item.code }} · {{ item.name }}</option></select><select v-model="status" :class="selectClass"><option value="ACTIVE">Actives</option><option value="INACTIVE">Inactives</option><option value="ALL">Toutes</option></select><Button type="button" size="rg" variant="white-outline" @click="applyFilters">Appliquer les filtres</Button></div>
            <div v-if="selectedSite?.ok" class="overflow-x-auto"><table class="w-full min-w-[1250px] border-collapse"><thead class="bg-muted/70 /40"><tr><th v-for="heading in ['Prestation', 'Analyse', 'Examen', 'Type', 'Références', 'Unité', 'Statut', 'Actions']" :key="heading" :class="['px-4 py-2.5 text-[10px] font-bold uppercase tracking-wide text-muted-foreground', heading === 'Actions' ? 'text-end' : 'text-start']">{{ heading }}</th></tr></thead><tbody class="divide-y divide-border">
                <tr v-for="analysis in visibleAnalyses" :key="analysis.uuid" class="hover:bg-muted/60"><td class="px-4 py-3"><p class="text-xs font-bold text-foreground">{{ analysis.catalog_item.name }}</p><code class="text-[10px] text-muted-foreground">{{ analysis.catalog_item.code }}</code></td><td class="px-4 py-3"><div class="flex flex-wrap items-center gap-2" :style="{ paddingInlineStart: `${analysis.hierarchy_depth * 1.25}rem` }"><ChevronRight v-if="analysis.hierarchy_depth > 0" class="h-3 w-3 shrink-0 text-muted-foreground" /><code class="text-xs font-bold text-primary">{{ analysis.code }}</code><span class="rounded bg-muted px-1.5 py-0.5 text-[9px] font-bold uppercase text-muted-foreground">{{ levelLabel(analysis.level) }}</span><span v-if="analysis.source_system" class="rounded border border-border px-1.5 py-0.5 text-[9px] font-bold uppercase text-muted-foreground" :title="`Type historique : ${analysis.source_metadata?.type_name ?? 'non renseigné'}`">Historique</span><Bold class="shrink-0 text-muted-foreground h-3 w-3" v-if="analysis.is_bold"  title="Gras à l’impression" /></div><p :class="['mt-1 text-sm text-foreground', analysis.is_bold ? 'font-bold' : 'font-semibold']" :style="{ paddingInlineStart: `${analysis.hierarchy_depth * 1.25}rem` }">{{ analysis.designation }}</p><p v-if="analysis.hierarchy_depth > 0" class="mt-0.5 truncate text-[10px] text-muted-foreground" :style="{ paddingInlineStart: `${analysis.hierarchy_depth * 1.25}rem` }" :title="ancestorPath(analysis)">Sous {{ ancestorPath(analysis) }}</p></td><td class="px-4 py-3 text-xs text-muted-foreground">{{ analysis.exam_category || '—' }}</td><td class="px-4 py-3 text-xs text-muted-foreground">{{ typeLabel(analysis.result_type) }}<p v-if="analysis.source_metadata?.type_label" class="mt-1 text-[10px] text-muted-foreground">Ancien : {{ analysis.source_metadata.type_label }}</p><p v-if="analysis.predefined_values?.length" class="mt-1 max-w-48 truncate text-[10px] text-muted-foreground">{{ analysis.predefined_values.join(' · ') }}</p></td><td class="px-4 py-3 text-xs leading-5 text-muted-foreground"><p v-if="analysis.reference_general">Gén. {{ analysis.reference_general }}</p><p v-if="analysis.reference_male">H {{ analysis.reference_male }}</p><p v-if="analysis.reference_female">F {{ analysis.reference_female }}</p><span v-if="!analysis.reference_general && !analysis.reference_male && !analysis.reference_female" class="text-muted-foreground">Non configurée</span></td><td class="px-4 py-3 text-xs font-semibold text-muted-foreground">{{ analysis.unit || '—' }}</td><td class="px-4 py-3"><span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', analysis.is_active ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-muted text-muted-foreground ']">{{ analysis.is_active ? 'Active' : 'Inactive' }}</span></td><td class="px-4 py-3"><div class="flex justify-end gap-2"><Link v-if="can('analysis_catalog.update')" :href="`/super-admin/analyses/${selectedSiteCode}/${analysis.uuid}/edit`" class="inline-flex h-8 w-8 items-center justify-center rounded border border-border text-muted-foreground hover:border-primary/40 hover:text-primary"><Pencil class="h-4 w-4" /></Link><Button v-if="can(analysis.is_active ? 'analysis_catalog.deactivate' : 'analysis_catalog.activate')" type="button" size="sm" :variant="analysis.is_active ? 'warning' : 'success'" @click="toggleActive(analysis)">{{ analysis.is_active ? 'Désactiver' : 'Activer' }}</Button></div></td></tr>
                <tr v-if="!analyses.length"><td colspan="8" class="px-5 py-14 text-center"><Activity class="text-muted-foreground h-6 w-6" /><p class="mt-2 text-sm text-muted-foreground">Aucune analyse ne correspond à ces filtres sur {{ selectedSite?.site.name }}.</p></td></tr>
            </tbody></table></div>
            <div v-else class="px-5 py-14 text-center text-sm text-muted-foreground">Le catalogue ne peut pas être chargé tant que l’API de ce site est indisponible.</div>
            <div class="flex flex-col gap-3 border-t border-border px-5 py-3 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between"><span>{{ analyses.length }} analyse(s) · données lues exclusivement via l’API du site sélectionné</span><div v-if="pageCount > 1" class="flex items-center gap-2"><button type="button" class="rounded border border-border px-3 py-1.5 font-bold text-muted-foreground disabled:opacity-40" :disabled="pageNumber === 1" @click="pageNumber--">Précédent</button><span class="tabular-nums">Page {{ pageNumber }} / {{ pageCount }}</span><button type="button" class="rounded border border-border px-3 py-1.5 font-bold text-muted-foreground disabled:opacity-40" :disabled="pageNumber === pageCount" @click="pageNumber++">Suivant</button></div></div>
        </Card>
    </div>
</template>
