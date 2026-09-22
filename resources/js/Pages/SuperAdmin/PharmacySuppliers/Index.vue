<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Archive,
    Building2,
    Download,
    FileSpreadsheet,
    Eye,
    Folder,
    FolderPlus,
    LayoutGrid,
    List,
    Pencil,
    RotateCcw,
    Search,
    ShoppingCart,
    Trash2,
    TriangleAlert,
    Upload,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import { cn } from '@/lib/cn';

defineOptions({ layout: AppLayout });

const props = defineProps({
    sites: { type: Array, default: () => [] },
    selectedSite: { type: String, default: null },
    status: { type: String, default: 'ACTIVE' },
    can: { type: Object, default: () => ({}) },
});

const page = usePage();
const current = computed(() => props.sites.find((site) => site.code === props.selectedSite) ?? null);
const isArchivedView = computed(() => props.status === 'ARCHIVED');
const search = ref('');
const listMode = ref('grid');

const suppliers = computed(() => current.value?.suppliers ?? []);
const visible = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();

    return suppliers.value.filter((supplier) => ! needle || [supplier.name, supplier.code, supplier.contact_name, supplier.phone]
        .filter(Boolean)
        .some((value) => value.toLocaleLowerCase().includes(needle)));
});

// Mettre à la corbeille et restaurer depuis la liste : ouvrir le dossier
// pour archiver un fournisseur obligeait à un aller-retour, alors que la
// décision se prend devant la liste. Le motif reste obligatoire (ADR-009).
const archiving = ref(null);
const archiveForm = useForm({ reason: '' });
const restoreForm = useForm({});

const openArchive = (supplier) => {
    archiving.value = supplier;
    archiveForm.reset();
    archiveForm.clearErrors();
};
const closeArchive = () => {
    if (archiveForm.processing) return;
    archiving.value = null;
};
const submitArchive = () => archiveForm.delete(
    `/super-admin/pharmacy-suppliers/${props.selectedSite}/${archiving.value.uuid}`,
    { preserveScroll: true, onSuccess: () => { archiving.value = null; } },
);
const restoring = ref(null);
const restoreSupplier = () => restoreForm.post(
    `/super-admin/pharmacy-suppliers/${props.selectedSite}/${restoring.value.uuid}/restore`,
    { preserveScroll: true, onSuccess: () => { restoring.value = null; } },
);

const STATUS = {
    ONLINE: { variant: 'success', label: 'Connecté' },
    OFFLINE: { variant: 'destructive', label: 'Injoignable' },
    ERROR: { variant: 'destructive', label: 'Erreur' },
    UNCONFIGURED: { variant: 'outline', label: 'Non configuré' },
};

const go = (params) => router.get(
    '/super-admin/pharmacy-suppliers',
    { site: props.selectedSite, status: props.status, ...params },
    { preserveScroll: true, replace: true },
);

/**
 * Les deux formulaires vivaient dépliés dans la page et poussaient la liste
 * vers le bas ; le bouton qui les ouvrait devenait « Fermer », si bien qu'on
 * ne savait plus ce qu'il ferait. Ce sont des saisies courtes et
 * délibérées : elles appartiennent à une fenêtre, qui reste ouverte quand le
 * serveur refuse — sinon la correction se ferait à l'aveugle.
 */
const creating = ref(false);
const importing = ref(false);

const form = useForm({ code: '', name: '', contact_name: '', phone: '', email: '', address: '' });
const submit = () => form
    .transform((data) => ({ ...data, site_code: props.selectedSite }))
    .post('/super-admin/pharmacy-suppliers', {
        preserveScroll: true,
        onSuccess: () => { form.reset(); creating.value = false; },
    });

const closeCreate = () => {
    if (form.processing) return;
    form.clearErrors();
    creating.value = false;
};

const importForm = useForm({ file: null });
const submitImport = () => importForm
    .transform((data) => ({ ...data, site_code: props.selectedSite }))
    .post('/super-admin/pharmacy-suppliers/import', { forceFormData: true, preserveScroll: true });

const closeImport = () => {
    if (importForm.processing) return;
    importForm.clearErrors();
    importing.value = false;
};

const importError = computed(() => importForm.errors.file
    ?? importForm.errors.site_code
    ?? importForm.errors.rows
    ?? page.props.errors?.file
    ?? null);

const exportUrl = (siteCode) => `/super-admin/pharmacy-suppliers/export?site_code=${encodeURIComponent(siteCode)}`;
const supplierUrl = (supplier) => `/super-admin/pharmacy-suppliers/${current.value.code}/${supplier.uuid}`;

const catalogLabel = (supplier) => (supplier.catalogs_count
    ? `${supplier.catalogs_count} catalogue${supplier.catalogs_count > 1 ? 's' : ''}`
    : 'Aucun catalogue');

const emptyTitle = computed(() => {
    if (suppliers.value.length) return 'Aucun fournisseur ne correspond';

    return isArchivedView.value ? 'Aucun fournisseur archivé' : 'Aucun fournisseur sur ce site';
});
</script>

<template>
    <Head title="Fournisseurs pharmacie" />

    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex min-w-0 items-start gap-3.5">
                <span class="mt-0.5 grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                    <Building2 class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">Référentiels &amp; stocks</p>
                    <h1 class="mt-1 font-heading text-2xl font-bold tracking-tight text-foreground sm:text-3xl">Fournisseurs pharmacie</h1>
                    <p class="mt-1.5 max-w-3xl text-sm leading-6 text-muted-foreground">
                        Les dossiers fournisseurs de chaque clinique et leurs catalogues. Chaque site conserve ses propres fournisseurs ; la réception des marchandises reste sur place, devant les lots et les péremptions.
                    </p>
                </div>
            </div>

            <div v-if="current?.status === 'ONLINE'" class="flex shrink-0 flex-wrap gap-2 ps-[3.6rem] lg:ps-0">
                <Button v-if="can.export" as="a" :href="exportUrl(current.code)" variant="outline" :title="`Tous les fournisseurs de ${current.name}, archivés compris`">
                    <Download class="h-4 w-4" />Exporter Excel
                </Button>
                <Button v-if="can.import" type="button" variant="outline" @click="importing = true">
                    <Upload class="h-4 w-4" />Importer Excel
                </Button>
                <Button v-if="can.create" type="button" variant="outline" @click="creating = true">
                    <FolderPlus class="h-4 w-4" />Nouveau fournisseur
                </Button>
                <Button v-if="can.order" :as="Link" :href="`/super-admin/pharmacy-suppliers/${current.code}/commander`" variant="primary" title="Comparer les prix de tous les fournisseurs, puis commander">
                    <ShoppingCart class="h-4 w-4" />Comparer et commander
                </Button>
            </div>
        </header>

        <nav class="flex max-w-full gap-2 overflow-x-auto pb-1" aria-label="Choisir un site">
            <button
                v-for="site in sites"
                :key="site.code"
                type="button"
                :class="cn(
                    'flex shrink-0 items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold transition-colors',
                    site.code === selectedSite
                        ? 'border-primary bg-primary/5 text-primary ring-1 ring-ring/25'
                        : 'border-border bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground',
                )"
                :aria-pressed="site.code === selectedSite"
                @click="go({ site: site.code })"
            >
                {{ site.name }}
                <Badge :variant="STATUS[site.status]?.variant ?? 'outline'" class="px-2 py-0 text-[10px]">{{ STATUS[site.status]?.label ?? site.status }}</Badge>
            </button>
        </nav>

        <template v-if="current">
            <Card v-if="current.status !== 'ONLINE'" class="flex items-start gap-3 border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900 dark:bg-amber-950/20">
                <TriangleAlert class="mt-0.5 h-4.5 w-4.5 shrink-0 text-amber-600 dark:text-amber-300" />
                <p class="text-sm leading-6 text-amber-900 dark:text-amber-100">
                    {{ current.message || 'Ce site ne répond pas actuellement.' }} Les autres sites restent disponibles.
                </p>
            </Card>

            <template v-else>
                <p v-if="page.props.errors?.file && ! importing" class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/20 dark:text-red-200">
                    {{ page.props.errors.file }}
                </p>

                <Card class="overflow-hidden">
                    <div class="flex flex-col gap-3 border-b border-border px-4 py-3 xl:flex-row xl:items-center xl:justify-between">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <div class="inline-flex shrink-0 rounded-lg bg-muted p-1" role="tablist" aria-label="Statut des fournisseurs">
                                <button
                                    type="button"
                                    role="tab"
                                    :aria-selected="! isArchivedView"
                                    :class="cn('rounded-md px-3 py-1.5 text-xs font-bold transition-colors', ! isArchivedView ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')"
                                    @click="go({ status: 'ACTIVE' })"
                                >Actifs</button>
                                <button
                                    type="button"
                                    role="tab"
                                    :aria-selected="isArchivedView"
                                    :class="cn('rounded-md px-3 py-1.5 text-xs font-bold transition-colors', isArchivedView ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')"
                                    @click="go({ status: 'ARCHIVED' })"
                                >Archivés</button>
                            </div>
                            <IconInput
                                v-model="search"
                                :icon="Search"
                                type="search"
                                class="h-10 w-full sm:w-72"
                                placeholder="Nom, code, contact…"
                                autocomplete="off"
                                aria-label="Rechercher un fournisseur"
                            />
                        </div>

                        <div class="flex items-center gap-3">
                            <!-- Le compteur ne se déduit pas d'une grille d'icônes :
                                 sans lui, on ne sait pas si la recherche a écarté
                                 des dossiers ou si le site n'en a pas d'autres. -->
                            <span class="text-xs tabular-nums text-muted-foreground">
                                {{ visible.length }}<template v-if="visible.length !== suppliers.length"> sur {{ suppliers.length }}</template>
                                dossier{{ suppliers.length > 1 ? 's' : '' }}
                            </span>
                            <div class="inline-flex shrink-0 gap-1 rounded-lg bg-muted p-1">
                                <button
                                    type="button"
                                    :class="cn('grid h-7 w-7 place-items-center rounded-md transition-colors', listMode === 'grid' ? 'bg-card text-primary shadow-sm' : 'text-muted-foreground')"
                                    aria-label="Vue dossiers"
                                    title="Vue dossiers"
                                    @click="listMode = 'grid'"
                                ><LayoutGrid class="h-4 w-4" /></button>
                                <button
                                    type="button"
                                    :class="cn('grid h-7 w-7 place-items-center rounded-md transition-colors', listMode === 'list' ? 'bg-card text-primary shadow-sm' : 'text-muted-foreground')"
                                    aria-label="Vue liste"
                                    title="Vue liste"
                                    @click="listMode = 'list'"
                                ><List class="h-4 w-4" /></button>
                            </div>
                        </div>
                    </div>

                    <!-- Le rappel valait pour la vue archivée entière, mais ne
                         s'affichait qu'en présence de résultats : la seule fois
                         où il manquait était celle où la liste vide surprenait. -->
                    <p v-if="isArchivedView" class="border-b border-border bg-muted/40 px-4 py-2.5 text-xs leading-5 text-muted-foreground">
                        Les fournisseurs archivés n’apparaissent ni dans les commandes ni dans les entrées de stock, mais tout leur historique est conservé. Ouvrez un dossier pour le restaurer.
                    </p>

                    <div v-if="visible.length === 0" class="px-5 py-14 text-center">
                        <span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-muted text-muted-foreground"><Folder class="h-6 w-6" /></span>
                        <p class="mt-3 text-sm font-bold text-foreground">{{ emptyTitle }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            <template v-if="suppliers.length">Modifiez la recherche pour élargir la liste.</template>
                            <template v-else-if="can.create && ! isArchivedView">Créez un dossier, ou importez une liste Excel existante.</template>
                        </p>
                        <Button v-if="can.create && ! isArchivedView && ! suppliers.length" class="mt-4" type="button" variant="primary" size="sm" @click="creating = true">
                            <FolderPlus class="h-4 w-4" />Nouveau fournisseur
                        </Button>
                    </div>

                    <!-- ADR-098 : un fournisseur se présente comme un dossier, que
                         l'on ouvre pour voir ses catalogues, commandes et factures.
                         La vue liste ne remplace pas cette métaphore, elle rend
                         seulement une longue liste comparable — nom, contact et
                         catalogues côte à côte plutôt qu'une grille d'icônes. -->
                    <div v-else-if="listMode === 'grid'" class="grid grid-cols-2 gap-2 p-3 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6">
                        <Link
                            v-for="supplier in visible"
                            :key="supplier.uuid"
                            :href="supplierUrl(supplier)"
                            :class="cn(
                                'group flex flex-col items-center rounded-xl border border-transparent px-3 py-4 text-center transition-colors',
                                'hover:border-border hover:bg-accent/50 focus-visible:border-primary focus-visible:outline-none',
                                supplier.archived && 'opacity-70',
                            )"
                        >
                            <span class="relative">
                                <Folder :class="cn('h-12 w-12 transition-transform group-hover:scale-105', supplier.archived ? 'text-muted-foreground' : 'fill-amber-200 text-amber-500 dark:fill-amber-500/20')" />
                                <span v-if="supplier.catalogs_count" class="absolute -end-1.5 -top-1 grid h-5 min-w-5 place-items-center rounded-full bg-primary px-1 text-[10px] font-bold text-primary-foreground">{{ supplier.catalogs_count }}</span>
                            </span>
                            <span class="mt-2.5 line-clamp-2 text-sm font-semibold text-foreground">{{ supplier.name }}</span>
                            <span class="mt-0.5 line-clamp-1 font-mono text-[10px] text-muted-foreground">{{ supplier.code }}</span>
                            <span v-if="supplier.contact_name || supplier.phone" class="mt-0.5 line-clamp-1 text-xs text-muted-foreground">{{ supplier.contact_name || supplier.phone }}</span>
                            <Badge v-if="supplier.archived" variant="outline" class="mt-1.5 px-2 py-0 text-[10px]"><Archive class="h-3 w-3" />Archivé</Badge>
                            <span v-else class="mt-1 text-[11px] text-muted-foreground">{{ catalogLabel(supplier) }}</span>
                        </Link>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full min-w-[760px] border-collapse">
                            <caption class="sr-only">Fournisseurs pharmacie de {{ current.name }}</caption>
                            <thead>
                                <tr class="bg-muted/40">
                                    <th class="border-b border-border px-4 py-2.5 text-start text-xs font-semibold uppercase tracking-wide text-muted-foreground">Fournisseur</th>
                                    <th class="border-b border-border px-4 py-2.5 text-start text-xs font-semibold uppercase tracking-wide text-muted-foreground">Contact</th>
                                    <th class="border-b border-border px-4 py-2.5 text-start text-xs font-semibold uppercase tracking-wide text-muted-foreground">Catalogues</th>
                                    <th class="border-b border-border px-4 py-2.5 text-start text-xs font-semibold uppercase tracking-wide text-muted-foreground">État</th>
                                    <th class="border-b border-border px-4 py-2.5 text-end text-xs font-semibold uppercase tracking-wide text-muted-foreground">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="supplier in visible" :key="supplier.uuid" class="transition-colors hover:bg-accent/40">
                                    <td class="border-b border-border px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <Folder :class="cn('h-5 w-5 shrink-0', supplier.archived ? 'text-muted-foreground' : 'fill-amber-200 text-amber-500 dark:fill-amber-500/20')" />
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-bold text-foreground">{{ supplier.name }}</p>
                                                <p class="mt-0.5 truncate font-mono text-[11px] text-muted-foreground">{{ supplier.code }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="border-b border-border px-4 py-3">
                                        <p class="text-sm text-foreground">{{ supplier.contact_name || '—' }}</p>
                                        <p v-if="supplier.phone" class="mt-0.5 text-xs text-muted-foreground">{{ supplier.phone }}</p>
                                    </td>
                                    <td class="border-b border-border px-4 py-3 text-sm tabular-nums text-muted-foreground">{{ catalogLabel(supplier) }}</td>
                                    <td class="border-b border-border px-4 py-3">
                                        <Badge v-if="supplier.archived" variant="outline"><Archive class="h-3 w-3" />Archivé</Badge>
                                        <Badge v-else variant="success">Actif</Badge>
                                    </td>
                                    <td class="border-b border-border px-4 py-3">
                                        <div class="flex items-center justify-end gap-1">
                                            <Button :as="Link" :href="supplierUrl(supplier)" size="sm" variant="outline" title="Ouvrir le dossier"><Eye class="h-4 w-4" /><span class="hidden lg:inline">Ouvrir</span></Button>
                                            <Button v-if="can.update && !supplier.archived" :as="Link" :href="`${supplierUrl(supplier)}?edit=1`" size="sm" variant="ghost" title="Modifier le fournisseur"><Pencil class="h-4 w-4" /></Button>
                                            <Button v-if="can.archive && !supplier.archived" size="sm" variant="ghost" class="text-destructive" title="Mettre à la corbeille" @click="openArchive(supplier)"><Trash2 class="h-4 w-4" /></Button>
                                            <Button v-if="can.restore && supplier.archived" size="sm" variant="ghost" title="Restaurer" :disabled="restoreForm.processing" @click="restoring = supplier"><RotateCcw class="h-4 w-4" /></Button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <p v-if="can.export && sites.length > 1" class="text-end text-xs text-muted-foreground">
                    <a :href="exportUrl('ALL')" class="font-bold text-primary hover:underline">Exporter les fournisseurs de tous les sites</a>
                </p>
            </template>
        </template>

        <Card v-else class="px-5 py-14 text-center">
            <span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-muted text-muted-foreground"><Building2 class="h-6 w-6" /></span>
            <p class="mt-3 text-sm font-bold text-foreground">Aucun site configuré</p>
        </Card>

        <Dialog
            :open="creating"
            size="lg"
            :title="`Nouveau fournisseur · ${current?.name ?? ''}`"
            description="Le dossier est créé dans la base de ce site. Les autres cliniques gardent leurs propres fournisseurs."
            :dismissible="! form.processing"
            @update:open="closeCreate"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/35 dark:text-amber-300"><FolderPlus class="h-5 w-5" /></span>
            </template>

            <form id="create-supplier" class="space-y-4" @submit.prevent="submit">
                <ValidationErrorSummary :errors="form.errors" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Nom" required :error="form.errors.name">
                        <Input v-model="form.name" name="name" required :aria-invalid="Boolean(form.errors.name)" />
                    </FormField>
                    <FormField label="Code court" required hint="(définitif)" :error="form.errors.code">
                        <Input v-model="form.code" name="code" class="uppercase" required :aria-invalid="Boolean(form.errors.code)" />
                    </FormField>
                </div>
                <p class="-mt-1 text-xs text-muted-foreground">
                    Le code identifie le fournisseur dans les imports de médicaments et de fournisseurs : il ne pourra plus être modifié.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Personne à contacter" :error="form.errors.contact_name">
                        <Input v-model="form.contact_name" name="contact_name" />
                    </FormField>
                    <FormField label="Téléphone" :error="form.errors.phone">
                        <Input v-model="form.phone" name="phone" type="tel" />
                    </FormField>
                    <FormField label="E-mail" :error="form.errors.email">
                        <Input v-model="form.email" name="email" type="email" />
                    </FormField>
                    <FormField label="Adresse" :error="form.errors.address">
                        <Input v-model="form.address" name="address" />
                    </FormField>
                </div>
            </form>

            <template #footer>
                <Button type="button" variant="outline" :disabled="form.processing" @click="closeCreate">Annuler</Button>
                <Button type="submit" form="create-supplier" variant="primary" :disabled="form.processing">
                    <FolderPlus class="h-4 w-4" />{{ form.processing ? 'Création…' : 'Créer le dossier' }}
                </Button>
            </template>
        </Dialog>

        <Dialog
            :open="importing"
            size="lg"
            :title="`Importer des fournisseurs · ${current?.name ?? ''}`"
            description="Le fichier est d’abord analysé : vous verrez ce que chaque ligne va créer ou modifier avant de confirmer. Rien n’est enregistré à cette étape."
            :dismissible="! importForm.processing"
            @update:open="closeImport"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Upload class="h-5 w-5" /></span>
            </template>

            <form id="import-suppliers" class="space-y-4" @submit.prevent="submitImport">
                <ul class="space-y-1.5 rounded-lg border border-border bg-muted/40 p-3 text-xs leading-5 text-muted-foreground">
                    <li>Colonnes : <strong class="text-foreground">Code</strong>, <strong class="text-foreground">Nom</strong>, Personne à contacter, Téléphone, E-mail, Adresse.</li>
                    <li>Un code déjà connu met à jour ce fournisseur ; un nouveau code le crée.</li>
                    <li>Une cellule vide garde la valeur actuelle : elle n’efface rien.</li>
                    <li>Un fichier exporté ici peut être réimporté ; ses lignes « Archivé » sont ignorées.</li>
                </ul>

                <FormField as="div" label="Fichier Excel (.xlsx)" required :error="importError">
                    <template #action>
                        <a href="/super-admin/pharmacy-suppliers/import-template" class="inline-flex shrink-0 items-center gap-1 text-xs font-bold text-primary hover:underline">
                            <FileSpreadsheet class="h-3.5 w-3.5" />Télécharger le modèle
                        </a>
                    </template>
                    <input
                        type="file"
                        accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        class="block h-11 w-full rounded-lg border border-input bg-card px-3 py-2 text-sm text-muted-foreground shadow-sm file:me-3 file:border-0 file:bg-transparent file:text-sm file:font-bold file:text-foreground"
                        @change="importForm.file = $event.target.files[0] ?? null"
                    >
                </FormField>
            </form>

            <template #footer>
                <Button type="button" variant="outline" :disabled="importForm.processing" @click="closeImport">Annuler</Button>
                <Button type="submit" form="import-suppliers" variant="primary" :disabled="! importForm.file || importForm.processing">
                    <Search class="h-4 w-4" />{{ importForm.processing ? 'Analyse…' : 'Analyser le fichier' }}
                </Button>
            </template>
        </Dialog>
    </div>
    <Dialog :open="archiving !== null" title="Mettre ce fournisseur à la corbeille ?" @close="closeArchive">
        <form id="archive-supplier" class="space-y-4" @submit.prevent="submitArchive">
            <p class="text-sm leading-6 text-muted-foreground">
                <strong class="text-foreground">{{ archiving?.name }}</strong> ne sera plus proposé pour une commande ni pour une entrée de stock.
                Son dossier — catalogues, commandes, factures, prix — reste consultable, et il se restaure depuis la corbeille.
            </p>
            <FormField label="Motif" :error="archiveForm.errors.reason" required>
                <Input v-model="archiveForm.reason" placeholder="Ex. fournisseur remplacé par la centrale" maxlength="1000" required />
            </FormField>
        </form>
        <template #footer>
            <Button type="button" variant="outline" :disabled="archiveForm.processing" @click="closeArchive">Annuler</Button>
            <Button type="submit" form="archive-supplier" variant="destructive" :disabled="archiveForm.processing || archiveForm.reason.trim().length < 3">
                <Trash2 class="h-4 w-4" />{{ archiveForm.processing ? 'Archivage…' : 'Mettre à la corbeille' }}
            </Button>
        </template>
    </Dialog>
    <ConfirmModal
        :open="Boolean(restoring)"
        title="Restaurer ce fournisseur ?"
        :description="restoring ? `${restoring.name} sera de nouveau proposé pour les commandes et les entrées de stock.` : ''"
        confirm-label="Restaurer"
        :processing="restoreForm.processing"
        @update:open="(value) => { if (!value) restoring = null; }"
        @confirm="restoreSupplier"
    />

</template>
