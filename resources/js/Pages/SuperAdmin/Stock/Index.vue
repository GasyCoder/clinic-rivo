<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ChevronDown, ChevronUp, Download, Folder, Info, Pencil, Search, Server, Upload, X } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Select from '@/Components/Shadcn/Select.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import ExplorerTile from '@/Components/UI/ExplorerTile.vue';
import ExplorerView from '@/Components/UI/ExplorerView.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';

defineOptions({ layout: AppLayout });

const props = defineProps({
    sites: Array,
    summary: Object,
});

const selectedSiteCode = ref(props.sites.find((site) => site.ok)?.site.code ?? props.sites[0]?.site.code);
const search = ref('');
const status = ref('ALL');
const expanded = ref(new Set());
const selectedUuids = ref(new Set());
const showImport = ref(false);
const { can } = usePermissions();
const importForm = useForm({ site_code: selectedSiteCode.value, file: null });

const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value));
const medicines = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();

    return (selectedSite.value?.data?.medicines ?? []).filter((medicine) => {
        const matchesSearch = !needle || [medicine.name, medicine.generic_name, medicine.code, medicine.form_label]
            .filter(Boolean)
            .some((value) => value.toLocaleLowerCase().includes(needle));
        const matchesStatus = status.value === 'ALL' || medicine.status === status.value;

        return matchesSearch && matchesStatus;
    });
});
const selectedMedicines = computed(() => medicines.value.filter((medicine) => selectedUuids.value.has(medicine.uuid)));
const allVisibleSelected = computed(() => medicines.value.length > 0 && medicines.value.every((medicine) => selectedUuids.value.has(medicine.uuid)));

const toggleMedicine = (uuid) => {
    const next = new Set(selectedUuids.value);
    next.has(uuid) ? next.delete(uuid) : next.size < 100 && next.add(uuid);
    selectedUuids.value = next;
};

const toggleAllVisible = () => {
    const next = new Set(selectedUuids.value);
    if (allVisibleSelected.value) medicines.value.forEach((medicine) => next.delete(medicine.uuid));
    else medicines.value.forEach((medicine) => { if (next.size < 100) next.add(medicine.uuid); });
    selectedUuids.value = next;
};

const clearSelection = () => { selectedUuids.value = new Set(); };

const statusLabel = (value) => ({
    AVAILABLE: 'Disponible',
    OUT_OF_STOCK: 'Rupture',
    EXPIRING_SOON: 'Péremption proche',
    EXPIRED: 'Périmé',
    INACTIVE: 'Inactif',
}[value] ?? value);

/**
 * Le ton de la pastille d'état, pas ses classes : le `Badge` partagé porte
 * déjà le vocabulaire (succès, danger, avertissement) et son mode sombre.
 * Cinq jeux de classes recopiés ici s'en écartaient à la première retouche.
 */
const statusTone = (value) => ({
    AVAILABLE: 'success',
    OUT_OF_STOCK: 'danger',
    EXPIRING_SOON: 'warning',
    EXPIRED: 'danger',
    INACTIVE: 'neutral',
}[value] ?? 'neutral');

const statusOptions = [
    { value: 'ALL', label: 'Tous les états' },
    { value: 'AVAILABLE', label: 'Disponibles' },
    { value: 'OUT_OF_STOCK', label: 'Ruptures' },
    { value: 'EXPIRING_SOON', label: 'Péremption proche' },
    { value: 'INACTIVE', label: 'Inactifs' },
];

/** Un site injoignable reste listé, mais ne peut pas recevoir l'import. */
const importSiteOptions = computed(() => props.sites.map((site) => ({
    value: site.site.code,
    label: site.ok ? site.site.name : `${site.site.name} — indisponible`,
})));

const toggleLots = (uuid) => {
    const next = new Set(expanded.value);
    next.has(uuid) ? next.delete(uuid) : next.add(uuid);
    expanded.value = next;
};

const formatDate = (value) => value ? new Intl.DateTimeFormat('fr-FR').format(new Date(`${value}T00:00:00`)) : '—';
const exportUrl = computed(() => `/super-admin/stock/export?site_code=${encodeURIComponent(selectedSiteCode.value)}`);
const selectedExportUrl = computed(() => {
    const params = new URLSearchParams({ site_code: selectedSiteCode.value });
    selectedMedicines.value.forEach((medicine) => params.append('medicine_uuids[]', medicine.uuid));
    return `/super-admin/stock/export?${params.toString()}`;
});
const selectSite = (code) => {
    selectedSiteCode.value = code;
    importForm.site_code = code;
    showImport.value = false;
    expanded.value = new Set();
    clearSelection();
};
const submitImport = () => importForm.post('/super-admin/stock/import', {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => { importForm.reset('file'); showImport.value = false; },
});
</script>

<template>
    <Head title="Stock médicaments" />

    <div class="w-full space-y-5">
        <PageHeader eyebrow="Super Administration" title="Stock médicaments" description="Disponibilités, réservations, lots et péremptions consolidés par site. Chaque import crée des mouvements audités dans le site choisi." icon="capsule" tone="emerald">
            <template #actions>
                <Button v-if="can('medicine_categories.view') && selectedSite?.ok" :as="Link" :href="`/super-admin/stock/${selectedSiteCode}/categories`" variant="outline">
                    <Folder class="h-4 w-4" />Familles
                </Button>
                <Button v-if="can('stock.import')" type="button" variant="outline" @click="showImport = ! showImport">
                    <component :is="showImport ? X : Upload" class="h-4 w-4" />{{ showImport ? 'Fermer' : 'Importer Excel' }}
                </Button>
                <Button v-if="can('stock.export') && selectedSite?.ok" as="a" :href="exportUrl" variant="outline">
                    <Download class="h-4 w-4" />Exporter Excel
                </Button>
                <Button v-else-if="can('stock.export')" type="button" variant="outline" disabled title="Configurez et connectez l’API du site pour exporter">
                    <Download class="h-4 w-4" />Exporter Excel
                </Button>
            </template>
        </PageHeader>

        <Card v-if="showImport" class="p-5">
            <form class="space-y-4" @submit.prevent="submitImport">
                <p v-if="! selectedSite?.ok" class="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                    <Info class="h-4 w-4 shrink-0" />Vous pouvez préparer le fichier, mais l’import sera disponible après la configuration de l’API du site.
                </p>

                <!-- Deux opérations seulement, et aucune n'est réversible :
                     le rappel appartient au formulaire, pas à la documentation. -->
                <p class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                    <Info class="mt-0.5 h-4 w-4 shrink-0" />
                    <span><strong>STOCK_INITIAL</strong> crée uniquement un nouveau lot. <strong>ENTREE</strong> ajoute la quantité à un lot existant ou nouveau. Toute opération est historisée et ne peut pas être supprimée.</span>
                </p>

                <div class="grid gap-4 lg:grid-cols-[220px_minmax(0,1fr)_auto] lg:items-end">
                    <FormField as="div" label="Site destinataire" required :error="importForm.errors.site_code">
                        <Select v-model="importForm.site_code" :options="importSiteOptions" class="w-full" />
                    </FormField>

                    <!-- `as="div"` : le lien « modèle Excel » ne doit pas se
                         trouver dans un <label>, où un clic activerait aussi
                         le champ fichier. L'étiquette passe par aria-label. -->
                    <FormField as="div" label="Fichier Excel" required :error="Object.values(importForm.errors).find((message) => message) ?? ''">
                        <template #action>
                            <a href="/super-admin/stock/import-template" class="text-xs font-bold text-primary hover:underline">Télécharger le modèle Excel</a>
                        </template>
                        <!-- Pas de primitive shadcn pour un champ fichier : le
                             natif reste, habillé des mêmes tokens que `Input`. -->
                        <input
                            type="file"
                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            aria-label="Fichier Excel à importer"
                            class="flex h-10 w-full rounded-lg border border-input bg-card px-3 py-2 text-sm text-muted-foreground shadow-sm transition-colors file:me-3 file:rounded-md file:border-0 file:bg-muted file:px-2 file:py-1 file:text-xs file:font-bold file:text-foreground focus-visible:border-primary/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/25"
                            @change="importForm.file = $event.target.files[0] ?? null"
                        >
                        <span class="mt-1 block text-xs text-muted-foreground">Format .xlsx uniquement · 500 lignes maximum · 5 Mo.</span>
                    </FormField>

                    <Button type="submit" variant="primary" :disabled="importForm.processing || ! selectedSite?.ok">
                        <Upload class="h-4 w-4" />{{ importForm.processing ? 'Importation…' : 'Importer le stock' }}
                    </Button>
                </div>
            </form>
        </Card>

        <Card class="grid overflow-hidden sm:grid-cols-2 xl:grid-cols-5">
            <div class="border-b border-border px-5 py-4 sm:border-e xl:border-b-0">
                <p class="text-xs text-muted-foreground">Sites connectés</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-foreground">{{ summary.online_sites }} / {{ sites.length }}</p>
            </div>
            <div class="border-b border-border px-5 py-4 xl:border-b-0 xl:border-e">
                <p class="text-xs text-muted-foreground">Médicaments</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-foreground">{{ summary.medicines }}</p>
            </div>
            <div class="border-b border-border px-5 py-4 sm:border-e xl:border-b-0">
                <p class="text-xs text-muted-foreground">Disponible</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-foreground">{{ summary.available_quantity }}</p>
            </div>
            <div class="border-b border-border px-5 py-4 xl:border-b-0 xl:border-e">
                <p class="text-xs text-muted-foreground">Ruptures</p>
                <p :class="cn('mt-1 text-xl font-bold tabular-nums', summary.out_of_stock ? 'text-destructive' : 'text-foreground')">{{ summary.out_of_stock }}</p>
            </div>
            <div class="px-5 py-4">
                <p class="text-xs text-muted-foreground">Alertes péremption</p>
                <p :class="cn('mt-1 text-xl font-bold tabular-nums', summary.expiring_soon + summary.expired_lots ? 'text-amber-600 dark:text-amber-300' : 'text-foreground')">{{ summary.expiring_soon + summary.expired_lots }}</p>
            </div>
        </Card>

        <div class="flex items-center gap-3">
            <span class="shrink-0 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Site</span>
            <div class="inline-flex max-w-full gap-1 overflow-x-auto rounded-lg bg-muted p-1">
                <button
                    v-for="site in sites"
                    :key="site.site.code"
                    type="button"
                    :class="cn(
                        'inline-flex shrink-0 items-center gap-2 rounded-md px-3 py-2 text-xs font-bold transition-colors',
                        selectedSiteCode === site.site.code ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                    )"
                    :aria-current="selectedSiteCode === site.site.code ? 'true' : undefined"
                    @click="selectSite(site.site.code)"
                >
                    <span :class="cn('h-2 w-2 rounded-full', site.ok ? 'bg-emerald-500' : site.status === 'OFFLINE' || site.status === 'ERROR' ? 'bg-destructive' : 'bg-muted-foreground/40')" />
                    {{ site.site.name }}
                </button>
            </div>
        </div>

        <Card v-if="! selectedSite?.ok" class="flex min-h-56 flex-col items-center justify-center px-6 py-10 text-center">
            <span class="grid h-11 w-11 place-items-center rounded-lg bg-muted text-muted-foreground"><Server class="h-5 w-5" /></span>
            <h2 class="mt-3 text-sm font-bold text-foreground">Données indisponibles pour {{ selectedSite?.site.name }}</h2>
            <p class="mt-1 max-w-lg text-xs leading-5 text-muted-foreground">{{ selectedSite?.message }}</p>
        </Card>

        <ExplorerView
            v-else
            storage-key="portal-stock"
            :count="medicines.length"
            count-label="médicament"
            empty-icon="capsule"
            empty-title="Aucun médicament"
            empty-description="Aucun médicament ne correspond à ces filtres."
        >
            <template #toolbar>
                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    <IconInput
                        v-model="search"
                        :icon="Search"
                        type="search"
                        class="h-9 sm:w-72"
                        placeholder="Médicament, code, forme…"
                        autocomplete="off"
                        aria-label="Rechercher un médicament"
                    />
                    <Select v-model="status" :options="statusOptions" class="h-9" aria-label="Filtrer par état" />
                </div>
            </template>

            <template #above>
                <div class="flex items-center gap-3 border-b border-border bg-card px-4 py-2 text-sm">
                    <label class="inline-flex cursor-pointer items-center gap-2 text-muted-foreground">
                        <Checkbox :model-value="allVisibleSelected" aria-label="Tout sélectionner" @update:model-value="toggleAllVisible" />
                        Tout sélectionner
                    </label>
                </div>

                <div v-if="selectedMedicines.length" class="flex flex-col gap-3 border-b border-border bg-primary/5 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <span class="grid h-8 min-w-8 place-items-center rounded-lg bg-primary px-2 text-xs font-bold tabular-nums text-primary-foreground">{{ selectedMedicines.length }}</span>
                        <div>
                            <p class="text-sm font-bold text-foreground">médicament(s) sélectionné(s)</p>
                            <p class="text-xs text-muted-foreground">L’export inclut tous les lots des lignes retenues · 100 maximum</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button v-if="can('stock.export')" as="a" :href="selectedExportUrl" size="sm" variant="primary">
                            <Download class="h-4 w-4" />Exporter la sélection
                        </Button>
                        <Button type="button" size="sm" variant="ghost" @click="clearSelection">Désélectionner</Button>
                    </div>
                </div>
            </template>

            <template #grid>
                <ExplorerTile
                    v-for="medicine in medicines"
                    :key="medicine.uuid"
                    :href="can('medicines.update') ? `/super-admin/stock/${selectedSiteCode}/medicines/${medicine.uuid}/edit` : null"
                    icon="capsule"
                    :tone="{ AVAILABLE: 'emerald', OUT_OF_STOCK: 'rose', EXPIRING_SOON: 'amber', INACTIVE: 'slate' }[medicine.status] ?? 'primary'"
                    :badge="statusLabel(medicine.status)"
                    :title="medicine.name"
                    :subtitle="[medicine.strength, medicine.form_label].filter(Boolean).join(' · ')"
                    :highlight="`${medicine.available_quantity} ${medicine.unit ?? ''}`"
                    :meta="`${medicine.lots.length} lot${medicine.lots.length > 1 ? 's' : ''} · péremption ${formatDate(medicine.nearest_expiration)}`"
                    :muted="medicine.status === 'INACTIVE'"
                    selectable
                    :selected="selectedUuids.has(medicine.uuid)"
                    @toggle="toggleMedicine(medicine.uuid)"
                />
            </template>

            <template #list>
                <table class="w-full min-w-[960px] text-start text-sm">
                    <thead class="bg-muted text-xs text-muted-foreground">
                        <tr>
                            <th class="w-10 px-4 py-3 text-start">
                                <Checkbox :model-value="allVisibleSelected" aria-label="Sélectionner tous les médicaments affichés" @update:model-value="toggleAllVisible" />
                            </th>
                            <th class="px-3 py-3 text-start font-semibold">Médicament</th>
                            <th class="px-4 py-3 text-start font-semibold">Forme</th>
                            <th class="px-4 py-3 text-end font-semibold">Physique</th>
                            <th class="px-4 py-3 text-end font-semibold">Réservé</th>
                            <th class="px-4 py-3 text-end font-semibold">Disponible</th>
                            <th class="px-4 py-3 text-start font-semibold">Prochaine péremption</th>
                            <th class="px-4 py-3 text-start font-semibold">État</th>
                            <th class="px-4 py-3 text-end font-semibold">Lots</th>
                            <th class="px-5 py-3 text-end font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template v-for="medicine in medicines" :key="medicine.uuid">
                            <tr :class="cn('transition-colors hover:bg-accent/40', selectedUuids.has(medicine.uuid) ? 'bg-primary/5' : '')">
                                <td class="px-4 py-3">
                                    <Checkbox
                                        :model-value="selectedUuids.has(medicine.uuid)"
                                        :aria-label="`Sélectionner ${medicine.name}`"
                                        @update:model-value="toggleMedicine(medicine.uuid)"
                                    />
                                </td>
                                <td class="px-3 py-3">
                                    <p class="font-bold text-foreground">{{ medicine.name }}</p>
                                    <p class="mt-0.5 text-xs text-muted-foreground">
                                        {{ medicine.code }}<span v-if="medicine.generic_name"> · {{ medicine.generic_name }}</span><span v-if="medicine.strength"> · {{ medicine.strength }}</span>
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ medicine.form_label }}</td>
                                <td class="px-4 py-3 text-end font-medium tabular-nums text-muted-foreground">{{ medicine.quantity_on_hand }} {{ medicine.unit }}</td>
                                <td class="px-4 py-3 text-end tabular-nums text-muted-foreground">{{ medicine.reserved_quantity }}</td>
                                <td class="px-4 py-3 text-end font-bold tabular-nums text-foreground">{{ medicine.available_quantity }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ formatDate(medicine.nearest_expiration) }}</td>
                                <td class="px-4 py-3"><Badge :tone="statusTone(medicine.status)">{{ statusLabel(medicine.status) }}</Badge></td>
                                <td class="px-5 py-3 text-end">
                                    <Button type="button" size="xs" variant="outline" @click="toggleLots(medicine.uuid)">
                                        <component :is="expanded.has(medicine.uuid) ? ChevronUp : ChevronDown" class="h-3.5 w-3.5" />{{ medicine.lots.length }}
                                    </Button>
                                </td>
                                <td class="px-5 py-3 text-end">
                                    <Button
                                        v-if="can('medicines.update') && selectedSite?.ok"
                                        :as="Link"
                                        :href="`/super-admin/stock/${selectedSiteCode}/medicines/${medicine.uuid}/edit`"
                                        size="xs"
                                        variant="outline"
                                    >
                                        <Pencil class="h-3.5 w-3.5" />Modifier
                                    </Button>
                                </td>
                            </tr>

                            <tr v-if="expanded.has(medicine.uuid)" class="bg-muted/40">
                                <td colspan="10" class="px-5 py-4">
                                    <div class="overflow-hidden rounded-lg border border-border bg-card">
                                        <div class="grid grid-cols-[minmax(150px,1fr)_repeat(4,minmax(110px,.6fr))_minmax(130px,.7fr)] border-b border-border bg-muted px-4 py-2 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                                            <span>Lot</span><span>Expiration</span>
                                            <span class="text-end">Physique</span><span class="text-end">Réservé</span><span class="text-end">Disponible</span>
                                            <span>État</span>
                                        </div>
                                        <div
                                            v-for="lot in medicine.lots"
                                            :key="lot.uuid"
                                            class="grid grid-cols-[minmax(150px,1fr)_repeat(4,minmax(110px,.6fr))_minmax(130px,.7fr)] items-center border-b border-border px-4 py-2.5 text-xs last:border-0"
                                        >
                                            <span class="font-bold text-foreground">{{ lot.lot_number }}</span>
                                            <span class="text-muted-foreground">{{ formatDate(lot.expires_at) }}</span>
                                            <span class="text-end tabular-nums">{{ lot.quantity_on_hand }}</span>
                                            <span class="text-end tabular-nums text-muted-foreground">{{ lot.reserved_quantity }}</span>
                                            <span class="text-end font-bold tabular-nums">{{ lot.available_quantity }}</span>
                                            <span><Badge :tone="statusTone(lot.status)" class="text-[10px]">{{ statusLabel(lot.status) }}</Badge></span>
                                        </div>
                                        <p v-if="! medicine.lots.length" class="px-4 py-4 text-xs text-muted-foreground">Aucun lot enregistré.</p>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </template>
        </ExplorerView>
    </div>
</template>
