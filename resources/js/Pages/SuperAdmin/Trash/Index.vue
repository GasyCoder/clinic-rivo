<script setup>
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import { computed, reactive, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import { CircleAlert, CircleCheck, Eraser, Filter, Lock, LoaderCircle, RotateCcw, Search, Server, Trash2, X } from 'lucide-vue-next';
import { lucideIcon } from '@/lib/icons';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({
    sites: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const { can } = usePermissions();
const restoring = ref(null);
const filtersProcessing = ref(false);
const restoreForm = useForm({});
const filterValues = reactive({
    search: props.filters.search ?? '',
    site: props.filters.site ?? 'ALL',
    category: props.filters.category ?? 'ALL',
    deleted_from: props.filters.deleted_from ?? '',
    deleted_to: props.filters.deleted_to ?? '',
});
let filterRequestId = 0;

const selectedSites = computed(() => filterValues.site !== 'ALL'
    ? props.sites.filter((site) => site.site.code === filterValues.site)
    : props.sites);
const records = computed(() => selectedSites.value
    .filter((site) => site.ok)
    .flatMap((site) => (site.data ?? []).map((record) => ({
        ...record,
        site: site.site,
    })))
    .sort((left, right) => new Date(right.deleted_at) - new Date(left.deleted_at)));
const unavailableSites = computed(() => selectedSites.value.filter((site) => !site.ok));
const categoryCounts = computed(() => Object.fromEntries(props.categories.map((category) => [
    category.code,
    selectedSites.value.reduce(
        (total, site) => total + (site.ok ? Number(site.meta?.summary?.categories?.[category.code] ?? 0) : 0),
        0,
    ),
])));
const total = computed(() => Object.values(categoryCounts.value).reduce((sum, count) => sum + count, 0));
const matchingTotal = computed(() => filterValues.category !== 'ALL'
    ? Number(categoryCounts.value[filterValues.category] ?? 0)
    : total.value);
const resultIsLimited = computed(() => selectedSites.value.some((site) => site.meta?.summary?.limited));

const applyFilters = () => {
    const requestId = ++filterRequestId;
    filtersProcessing.value = true;
    router.get('/super-admin/trash', {
        search: filterValues.search || undefined,
        site: filterValues.site,
        category: filterValues.category,
        deleted_from: filterValues.deleted_from || undefined,
        deleted_to: filterValues.deleted_to || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
            if (requestId === filterRequestId) filtersProcessing.value = false;
        },
    });
};

const selectFilter = (field, value) => {
    filterValues[field] = value;
    applyFilters();
};
const clearFilters = () => {
    Object.assign(filterValues, {
        search: '',
        site: 'ALL',
        category: 'ALL',
        deleted_from: '',
        deleted_to: '',
    });
    applyFilters();
};
const openRestore = (record) => {
    restoring.value = record;
    restoreForm.clearErrors();
};
const closeRestore = () => {
    if (restoreForm.processing) return;
    restoring.value = null;
    restoreForm.clearErrors();
};
// Destroying for good is a second, deliberate step: it is never offered from
// the main lists, only here, and the site refuses it as soon as anything
// used the record (ADR-010).
const destroying = ref(null);
const destroyForm = useForm({});
const openDestroy = (record) => {
    destroying.value = record;
    destroyForm.clearErrors();
};
const closeDestroy = () => {
    if (destroyForm.processing) return;
    destroying.value = null;
    destroyForm.clearErrors();
};
const submitDestroy = () => destroyForm.delete(
    `/super-admin/trash/${destroying.value.site.code}/${destroying.value.category}/${destroying.value.uuid}`,
    { preserveScroll: true, onSuccess: closeDestroy },
);

const submitRestore = () => restoreForm.post(
    `/super-admin/trash/${restoring.value.site.code}/${restoring.value.category}/${restoring.value.uuid}/restore`,
    {
        preserveScroll: true,
        onSuccess: closeRestore,
    },
);

// ADR-236 — vider la corbeille : ce que montrent les filtres, sur les sites choisis. Chaque site
// supprime ce qui n'a servi nulle part et garde le reste ; « VIDER » est à saisir.
const emptyOpen = ref(false);
const emptyForm = useForm({ confirmation: '' });
const deletableShown = computed(() => records.value.filter((record) => record.can_force_delete).length);
const keptShown = computed(() => records.value.length - deletableShown.value);
const scopeLabel = computed(() => {
    const site = filterValues.site === 'ALL' ? 'tous les sites' : (props.sites.find((item) => item.site.code === filterValues.site)?.site.name ?? filterValues.site);
    const category = filterValues.category === 'ALL' ? 'toutes les catégories' : (props.categories.find((item) => item.code === filterValues.category)?.label ?? filterValues.category);
    const narrowed = filterValues.search || filterValues.deleted_from || filterValues.deleted_to;

    return `${category}, ${site}${narrowed ? ', avec la recherche et les dates choisies' : ''}`;
});
const openEmpty = () => {
    emptyForm.reset();
    emptyForm.clearErrors();
    emptyOpen.value = true;
};
const submitEmpty = () => emptyForm
    .transform((data) => ({
        confirmation: data.confirmation.trim(),
        site: filterValues.site,
        category: filterValues.category,
        search: filterValues.search || undefined,
        deleted_from: filterValues.deleted_from || undefined,
        deleted_to: filterValues.deleted_to || undefined,
    }))
    .post('/super-admin/trash/empty', { preserveScroll: true, onSuccess: () => { emptyOpen.value = false; } });

const page = usePage();
const emptyReport = computed(() => (page.props.flash?.bulk_report?.action === 'trash_empty' ? page.props.flash.bulk_report : null));
const reportHidden = ref(false);
watch(emptyReport, () => { reportHidden.value = false; });

const formatDateTime = (value) => value
    ? new Intl.DateTimeFormat('fr-MG', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : '—';
</script>

<template>
    <Head title="Corbeille" />

    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground dark:text-muted-foreground">
                    <Trash2 class="h-5 w-5" />
                </span>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Super Administration</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold text-foreground">Corbeille</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Dossiers et référentiels archivés sur les sites cliniques.</p>
                </div>
            </div>
            <div class="flex flex-col items-start gap-3 lg:items-end">
                <Button v-if="can('trash.force_delete')" type="button" variant="destructive" @click="openEmpty"><Eraser class="h-4 w-4" />Vider la corbeille</Button>
                <div class="max-w-xl border-s-2 border-amber-300 ps-3 text-xs leading-5 text-muted-foreground dark:border-amber-700">
                    La restauration conserve l’UUID et l’historique. Elle est exécutée par l’API du site concerné et inscrite dans son journal d’audit.
                </div>
            </div>
        </header>

        <!-- ADR-236 — ce que « Vider la corbeille » a supprimé, et ce qu'il a gardé, site par site. -->
        <div v-if="emptyReport && ! reportHidden" class="flex items-start gap-3 rounded-lg border border-border bg-card px-4 py-3 shadow-sm" role="status">
            <component :is="emptyReport.kept || emptyReport.sites.some((site) => ! site.ok) ? CircleAlert : CircleCheck" :class="['mt-0.5 h-5 w-5 shrink-0', emptyReport.kept ? 'text-amber-600' : 'text-emerald-600']" />
            <div class="min-w-0 flex-1 space-y-2 text-sm">
                <p class="font-semibold text-foreground">{{ emptyReport.deleted }} supprimé{{ emptyReport.deleted > 1 ? 's' : '' }} définitivement · {{ emptyReport.kept }} conservé{{ emptyReport.kept > 1 ? 's' : '' }} parce qu’ils ont servi</p>
                <div v-for="entry in emptyReport.sites" :key="entry.site.code" class="text-muted-foreground">
                    <p v-if="! entry.ok"><span class="font-medium text-foreground">{{ entry.site.name }}</span> — {{ entry.message }}</p>
                    <template v-else>
                        <p><span class="font-medium text-foreground">{{ entry.site.name }}</span> — {{ entry.deleted }} supprimé{{ entry.deleted > 1 ? 's' : '' }}, {{ entry.kept }} conservé{{ entry.kept > 1 ? 's' : '' }}<span v-if="entry.remaining"> ; il en reste, relancez « Vider » pour la suite</span>.</p>
                        <p v-if="entry.skipped?.length" class="text-xs">Non traité (droit manquant) : {{ entry.skipped.join(', ') }}.</p>
                        <ul v-if="entry.kept_items?.length" class="mt-1 space-y-0.5 text-xs">
                            <li v-for="(item, index) in entry.kept_items" :key="index"><span class="font-medium text-foreground">{{ item.title }}</span> ({{ item.category }}) — {{ item.reasons.join(', ') }}</li>
                            <li v-if="entry.kept > entry.kept_items.length">… et {{ entry.kept - entry.kept_items.length }} autre{{ entry.kept - entry.kept_items.length > 1 ? 's' : '' }}.</li>
                        </ul>
                    </template>
                </div>
            </div>
            <Button type="button" size="icon-xs" variant="ghost" aria-label="Fermer le rapport" @click="reportHidden = true"><X class="h-4 w-4" /></Button>
        </div>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <button
                v-for="category in categories"
                :key="category.code"
                type="button"
                :class="['flex min-w-0 items-center gap-3 rounded-lg border bg-card px-4 py-3 text-start transition-colors ', filterValues.category === category.code ? 'border-primary/40 ring-1 ring-ring/25 ' : 'border-border hover:border-primary/30 dark:hover:border-primary/30']"
                @click="selectFilter('category', filterValues.category === category.code ? 'ALL' : category.code)"
            >
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground dark:text-muted-foreground">
                    <component class="h-4.5 w-4.5" :is="lucideIcon(category.icon)" />
                </span>
                <div class="min-w-0">
                    <p class="truncate text-xs font-medium text-muted-foreground">{{ category.label }}</p>
                    <p class="mt-0.5 text-xl font-bold tabular-nums text-foreground">{{ categoryCounts[category.code] ?? 0 }}</p>
                </div>
            </button>
        </section>

        <section class="overflow-hidden rounded-lg border border-border bg-card">
            <form class="border-b border-border p-4" @submit.prevent="applyFilters">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(260px,1fr)_190px_210px_150px_150px_auto] xl:items-end">
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Recherche</span>
                        <span class="relative block">
                            <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto text-muted-foreground h-4.5 w-4.5" />
                            <input v-model="filterValues.search" name="search" type="search" class="h-10 w-full rounded border border-border bg-card ps-10 pe-3 text-sm text-foreground outline-none focus:border-primary focus:ring-2 focus:ring-ring/25" placeholder="Nom, numéro patient, code…">
                        </span>
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Site</span>
                        <select name="site" :value="filterValues.site" class="h-10 w-full rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary" @change="selectFilter('site', $event.target.value)">
                            <option value="ALL">Tous les sites</option>
                            <option v-for="site in sites" :key="site.site.code" :value="site.site.code">{{ site.site.name }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Catégorie</span>
                        <select name="category" :value="filterValues.category" class="h-10 w-full rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary" @change="selectFilter('category', $event.target.value)">
                            <option value="ALL">Toutes les catégories</option>
                            <option v-for="category in categories" :key="category.code" :value="category.code">{{ category.label }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Du</span>
                        <DatePicker v-model="filterValues.deleted_from" name="deleted_from" />
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Au</span>
                        <DatePicker v-model="filterValues.deleted_to" name="deleted_to" />
                    </label>
                    <div class="flex h-10 items-center gap-2">
                        <Button size="rg" type="submit" :disabled="filtersProcessing"><component :class="{ 'animate-spin': filtersProcessing }" :is="filtersProcessing ? LoaderCircle : Filter" />{{ filtersProcessing ? 'Actualisation…' : 'Filtrer' }}</Button>
                        <button v-if="filterValues.search || filterValues.category !== 'ALL' || filterValues.site !== 'ALL' || filterValues.deleted_from || filterValues.deleted_to" type="button" class="h-10 px-2 text-xs font-bold text-muted-foreground hover:text-foreground" @click="clearFilters">Effacer</button>
                    </div>
                </div>
            </form>

            <div v-for="site in unavailableSites" :key="site.site.code" class="flex items-start gap-3 border-b border-amber-200 bg-amber-50 px-5 py-3 text-xs text-amber-800 last:border-b-0 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
                <Server class="mt-0.5 shrink-0 h-4 w-4" />
                <p><strong>{{ site.site.name }} :</strong> {{ site.message }}</p>
            </div>

            <div class="hidden grid-cols-[minmax(240px,1.25fr)_190px_160px_210px_minmax(220px,1fr)_210px] border-b border-border bg-muted px-5 py-3 text-[11px] font-medium uppercase tracking-wide text-muted-foreground lg:grid">
                <span>Élément</span><span>Catégorie</span><span>Site</span><span>Suppression</span><span>Motif</span><span class="text-end">Action</span>
            </div>

            <div v-if="records.length" class="divide-y divide-border">
                <article v-for="record in records" :key="`${record.site.code}-${record.category}-${record.uuid}`" class="grid gap-3 px-5 py-4 lg:grid-cols-[minmax(240px,1.25fr)_190px_160px_210px_minmax(220px,1fr)_210px] lg:items-center lg:gap-0">
                    <div class="min-w-0 pe-4">
                        <p class="truncate text-sm font-bold text-foreground">{{ record.title }}</p>
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                            <span v-if="record.reference" class="font-mono">{{ record.reference }}</span>
                            <span>{{ record.subtitle }}</span>
                        </p>
                    </div>
                    <div class="flex items-center gap-2 pe-4 text-xs font-medium text-muted-foreground">
                        <component class="text-muted-foreground h-4 w-4" :is="lucideIcon(record.category_icon)" />
                        <span>{{ record.category_label }}</span>
                    </div>
                    <div class="pe-4 text-xs text-muted-foreground">
                        <p class="font-medium">{{ record.site.name }}</p>
                        <p class="mt-0.5 font-mono text-[10px] text-muted-foreground">{{ record.site.code }}</p>
                    </div>
                    <div class="pe-4 text-xs text-muted-foreground">
                        <p>{{ formatDateTime(record.deleted_at) }}</p>
                        <p class="mt-0.5 truncate text-[11px] text-muted-foreground">par {{ record.deleted_by }}</p>
                    </div>
                    <div class="pe-4">
                        <p class="line-clamp-2 text-xs leading-5 text-muted-foreground" :title="record.delete_reason">{{ record.delete_reason || 'Aucun motif renseigné' }}</p>
                        <p v-if="!record.can_force_delete && (record.force_delete_blockers ?? []).length" class="mt-0.5 text-[11px] leading-4 text-amber-600 dark:text-amber-400">
                            Conservé : {{ record.force_delete_blockers.join(', ') }}
                        </p>
                    </div>
                    <div class="flex justify-end">
                        <Button v-if="can('trash.restore') && record.can_restore" size="sm" variant="white-outline" type="button" @click="openRestore(record)">
                            <RotateCcw class="h-4 w-4" />Restaurer
                        </Button>
                        <!-- ADR-061 — un bouton qui serait refusé ne s'affiche pas :
                             le site dit ce qui retient l'élément, et on le lit ici. -->
                        <Button
                            v-if="can('trash.force_delete') && record.can_force_delete"
                            size="sm"
                            variant="white-outline"
                            type="button"
                            class="ms-2 text-destructive hover:bg-destructive/10"
                            title="Supprimer définitivement"
                            @click="openDestroy(record)"
                        >
                            <Trash2 class="h-4 w-4" />
                        </Button>
                        <span
                            v-else-if="can('trash.force_delete')"
                            class="ms-2 inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap text-[11px] text-muted-foreground"
                            :title="`A déjà servi : ${(record.force_delete_blockers ?? []).join(', ')}. Cet élément reste restaurable.`"
                        >
                            <Lock class="h-3.5 w-3.5" />A servi
                        </span>
                    </div>
                </article>
            </div>

            <div v-else-if="!unavailableSites.length || selectedSites.some((site) => site.ok)" class="flex min-h-52 flex-col items-center justify-center px-6 py-10 text-center">
                <Trash2 class="text-muted-foreground h-8 w-8" />
                <h2 class="mt-3 text-sm font-bold text-foreground">Aucun élément dans cette sélection</h2>
                <p class="mt-1 text-xs text-muted-foreground">Modifiez les filtres ou choisissez un autre site.</p>
            </div>

            <footer class="flex flex-col gap-1 border-t border-border px-5 py-3 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                <span>{{ records.length }} élément(s) affiché(s) · {{ matchingTotal }} correspondant(s) sur les sites joignables</span>
                <span v-if="resultIsLimited">Les 100 suppressions les plus récentes de chaque site sont affichées.</span>
            </footer>
        </section>

        <ConfirmModal
            :open="emptyOpen"
            title="Vider la corbeille"
            confirm-label="Vider la corbeille"
            tone="danger"
            :icon="Eraser"
            :processing="emptyForm.processing"
            :disabled="emptyForm.confirmation.trim() !== 'VIDER'"
            :dismissible="false"
            @update:open="emptyOpen = $event"
            @confirm="submitEmpty"
        >
            <div class="space-y-3 text-sm">
                <p class="text-muted-foreground">Supprime définitivement, pour <strong class="text-foreground">{{ scopeLabel }}</strong>, tout ce qui n’a servi nulle part. C’est irréversible : ces éléments ne pourront plus être restaurés.</p>
                <p class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                    Ce qui a servi — une facture, un passage, un contrat signé, une présence… — reste dans la corbeille et se restaure toujours. Parmi les éléments affichés : <strong class="text-foreground">{{ deletableShown }}</strong> supprimable{{ deletableShown > 1 ? 's' : '' }}, {{ keptShown }} conservé{{ keptShown > 1 ? 's' : '' }}.
                </p>
                <FormField label="Saisissez VIDER pour confirmer" required :error="emptyForm.errors.confirmation">
                    <Input v-model="emptyForm.confirmation" autocomplete="off" placeholder="VIDER" />
                </FormField>
                <p v-for="(error, key) in emptyForm.errors" v-show="key !== 'confirmation'" :key="key" class="text-xs text-destructive">{{ error }}</p>
            </div>
        </ConfirmModal>

        <div v-if="destroying" class="fixed inset-0 z-[1100] flex items-center justify-center bg-slate-950/45 p-4" role="dialog" aria-modal="true" @click.self="closeDestroy">
            <form class="w-full max-w-md rounded-lg border border-border bg-card p-5 shadow-xl" @submit.prevent="submitDestroy">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-destructive/10 text-destructive"><Trash2 class="h-4.5 w-4.5" /></span>
                    <div>
                        <h2 class="text-base font-bold text-foreground">Supprimer définitivement ?</h2>
                        <p class="mt-1 text-sm leading-5 text-muted-foreground">
                            <strong>{{ destroying.title }}</strong> sera effacé de {{ destroying.site.name }}. Cette action est irréversible et il ne sera plus restaurable.
                        </p>
                    </div>
                </div>
                <p class="mt-4 rounded border border-border bg-muted/50 px-3 py-2.5 text-xs leading-5 text-muted-foreground">
                    Le site a vérifié que rien ne s’y rattache — ni commande, ni réception, ni prix, ni mouvement de stock. Il le revérifie à l’enregistrement.
                </p>
                <p v-for="error in Object.values(destroyForm.errors)" :key="error" class="mt-3 text-xs text-red-600">{{ error }}</p>
                <div class="mt-5 flex justify-end gap-3">
                    <Button size="rg" variant="white-outline" type="button" @click="closeDestroy">Annuler</Button>
                    <Button size="rg" variant="destructive" :disabled="destroyForm.processing"><Trash2 class="h-4 w-4" />{{ destroyForm.processing ? 'Suppression…' : 'Supprimer définitivement' }}</Button>
                </div>
            </form>
        </div>

        <div v-if="restoring" class="fixed inset-0 z-[1100] flex items-center justify-center bg-slate-950/45 p-4" role="dialog" aria-modal="true" @click.self="closeRestore">
            <form class="w-full max-w-md rounded-lg border border-border bg-card p-5 shadow-xl" @submit.prevent="submitRestore">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-primary/10 text-primary"><RotateCcw class="h-4.5 w-4.5" /></span>
                    <div>
                        <h2 class="text-base font-bold text-foreground">Restaurer cet élément ?</h2>
                        <p class="mt-1 text-sm leading-5 text-muted-foreground"><strong>{{ restoring.title }}</strong> redeviendra disponible sur {{ restoring.site.name }}.</p>
                    </div>
                </div>
                <dl class="mt-5 divide-y divide-border rounded border border-border px-3 text-xs">
                    <div class="flex justify-between gap-4 py-2.5"><dt class="text-muted-foreground">Catégorie</dt><dd class="font-medium text-muted-foreground">{{ restoring.category_label }}</dd></div>
                    <div class="flex justify-between gap-4 py-2.5"><dt class="text-muted-foreground">Supprimé le</dt><dd class="text-end font-medium text-muted-foreground">{{ formatDateTime(restoring.deleted_at) }}</dd></div>
                    <div class="py-2.5"><dt class="text-muted-foreground">Motif initial</dt><dd class="mt-1 leading-5 text-muted-foreground">{{ restoring.delete_reason || 'Aucun motif renseigné' }}</dd></div>
                </dl>
                <p v-for="error in Object.values(restoreForm.errors)" :key="error" class="mt-3 text-xs text-red-600">{{ error }}</p>
                <div class="mt-5 flex justify-end gap-3">
                    <Button size="rg" variant="white-outline" type="button" @click="closeRestore">Annuler</Button>
                    <Button size="rg" :disabled="restoreForm.processing"><RotateCcw class="h-4 w-4" />{{ restoreForm.processing ? 'Restauration…' : 'Confirmer' }}</Button>
                </div>
            </form>
        </div>
    </div>
</template>
