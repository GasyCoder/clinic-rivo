<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import FolderCard from '@/Components/UI/FolderCard.vue';
import Icon from '@/Components/UI/Icon.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';

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
const visible = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();

    return (current.value?.suppliers ?? []).filter((supplier) => !needle || [supplier.name, supplier.code, supplier.contact_name, supplier.phone]
        .filter(Boolean)
        .some((value) => value.toLocaleLowerCase().includes(needle)));
});

const STATUS = {
    ONLINE: { tone: 'success', label: 'Connecté' },
    OFFLINE: { tone: 'danger', label: 'Injoignable' },
    ERROR: { tone: 'danger', label: 'Erreur' },
    UNCONFIGURED: { tone: 'neutral', label: 'Non configuré' },
};

const go = (params) => router.get('/super-admin/pharmacy-suppliers', { site: props.selectedSite, status: props.status, ...params }, { preserveScroll: true, replace: true });

const panel = ref(null); // 'create' | 'import' | null
const toggle = (name) => { panel.value = panel.value === name ? null : name; };

const form = useForm({ code: '', name: '', contact_name: '', phone: '', email: '', address: '' });
const submit = () => form
    .transform((data) => ({ ...data, site_code: props.selectedSite }))
    .post('/super-admin/pharmacy-suppliers', {
        preserveScroll: true,
        onSuccess: () => { form.reset(); panel.value = null; },
    });

const importForm = useForm({ file: null });
const fileInput = ref(null);
const submitImport = () => importForm
    .transform((data) => ({ ...data, site_code: props.selectedSite }))
    .post('/super-admin/pharmacy-suppliers/import', { forceFormData: true, preserveScroll: true });
const importError = computed(() => importForm.errors.file ?? importForm.errors.site_code ?? importForm.errors.rows ?? page.props.errors?.file ?? null);

const exportUrl = (siteCode) => `/super-admin/pharmacy-suppliers/export?site_code=${encodeURIComponent(siteCode)}`;

const inputClass = 'h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
const labelClass = 'mb-1.5 block text-sm font-medium text-slate-700 dark:text-white';
</script>

<template>
    <Head title="Fournisseurs pharmacie" />

    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Référentiels & stocks"
            title="Fournisseurs pharmacie"
            description="Les dossiers fournisseurs de chaque clinique et leurs catalogues. Chaque site conserve ses propres fournisseurs ; commandes, réceptions et factures restent gérées sur place."
            icon="building"
            tone="amber"
        />

        <nav class="flex max-w-full gap-2 overflow-x-auto" aria-label="Choisir un site">
            <button
                v-for="site in sites"
                :key="site.code"
                type="button"
                :class="['flex shrink-0 items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold transition', site.code === selectedSite ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-950/30 dark:text-primary-300' : 'border-gray-200 bg-white text-slate-600 hover:border-slate-300 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300']"
                :aria-pressed="site.code === selectedSite"
                @click="go({ site: site.code })"
            >
                {{ site.name }}
                <Badge :tone="STATUS[site.status]?.tone ?? 'neutral'">{{ STATUS[site.status]?.label ?? site.status }}</Badge>
            </button>
        </nav>

        <template v-if="current">
            <section v-if="current.status !== 'ONLINE'" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
                <Icon name="alert" class="mt-0.5 text-lg" />
                <p>{{ current.message || 'Ce site ne répond pas actuellement.' }} Les autres sites restent disponibles.</p>
            </section>

            <template v-else>
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-800 dark:bg-gray-950" role="tablist" aria-label="Statut des fournisseurs">
                            <button type="button" role="tab" :aria-selected="!isArchivedView" :class="['rounded-md px-3 py-1.5 text-sm font-semibold', !isArchivedView ? 'bg-primary-600 text-white' : 'text-slate-600 hover:text-slate-800 dark:text-slate-300']" @click="go({ status: 'ACTIVE' })">Actifs</button>
                            <button type="button" role="tab" :aria-selected="isArchivedView" :class="['rounded-md px-3 py-1.5 text-sm font-semibold', isArchivedView ? 'bg-primary-600 text-white' : 'text-slate-600 hover:text-slate-800 dark:text-slate-300']" @click="go({ status: 'ARCHIVED' })">Archivés</button>
                        </div>
                        <label class="relative block w-full sm:w-72">
                            <span class="sr-only">Rechercher un fournisseur</span>
                            <Icon class="pointer-events-none absolute inset-y-0 start-3 my-auto text-lg text-slate-400" name="search" />
                            <input v-model="search" type="search" class="h-10 w-full rounded-lg border border-gray-200 bg-white ps-10 pe-3 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Nom, code, contact…">
                        </label>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="can.export" as="a" :href="exportUrl(current.code)" size="rg" variant="white-outline" :title="`Tous les fournisseurs de ${current.name}, archivés compris`">
                            <Icon name="download" /><span class="ms-2">Exporter Excel</span>
                        </Button>
                        <Button v-if="can.import" size="rg" variant="white-outline" type="button" @click="toggle('import')">
                            <Icon :name="panel === 'import' ? 'cross' : 'upload'" /><span class="ms-2">{{ panel === 'import' ? 'Fermer' : 'Importer Excel' }}</span>
                        </Button>
                        <Button v-if="can.create" size="rg" type="button" @click="toggle('create')">
                            <Icon :name="panel === 'create' ? 'cross' : 'folder-plus'" /><span class="ms-2">{{ panel === 'create' ? 'Fermer' : 'Nouveau fournisseur' }}</span>
                        </Button>
                    </div>
                </div>

                <p v-if="page.props.errors?.file && panel !== 'import'" class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/20 dark:text-red-200">{{ page.props.errors.file }}</p>

                <form v-if="panel === 'import'" class="space-y-4 rounded-xl border border-primary-200 bg-white p-5 shadow-sm dark:border-primary-900 dark:bg-gray-950" @submit.prevent="submitImport">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="font-heading text-base font-bold text-slate-800 dark:text-white">Importer des fournisseurs · {{ current.name }}</h2>
                            <p class="mt-1 text-sm text-slate-500">Le fichier est d’abord analysé : vous verrez ce que chaque ligne va créer ou modifier avant de confirmer. Rien n’est enregistré à cette étape.</p>
                        </div>
                        <a href="/super-admin/pharmacy-suppliers/import-template" class="shrink-0 text-sm font-bold text-primary-600 hover:text-primary-700">Télécharger le modèle Excel</a>
                    </div>
                    <ul class="grid gap-1 text-xs text-slate-500 sm:grid-cols-2">
                        <li>• Colonnes : <strong>Code</strong>, <strong>Nom</strong>, Personne à contacter, Téléphone, E-mail, Adresse.</li>
                        <li>• Un code déjà connu met à jour ce fournisseur ; un nouveau code le crée.</li>
                        <li>• Une cellule vide garde la valeur actuelle : elle n’efface rien.</li>
                        <li>• Un fichier exporté ici peut être réimporté ; ses lignes « Archivé » sont ignorées.</li>
                    </ul>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <label class="block min-w-0 flex-1">
                            <span :class="labelClass">Fichier Excel (.xlsx) <span class="text-red-500">*</span></span>
                            <input ref="fileInput" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="block h-11 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-slate-600 file:me-3 file:border-0 file:bg-transparent file:text-sm file:font-bold file:text-slate-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300" @change="importForm.file = $event.target.files[0] ?? null">
                        </label>
                        <Button size="rg" type="submit" :disabled="!importForm.file || importForm.processing">
                            <Icon name="search" /><span class="ms-2">{{ importForm.processing ? 'Analyse…' : 'Analyser le fichier' }}</span>
                        </Button>
                    </div>
                    <p v-if="importError" class="text-sm text-red-600">{{ importError }}</p>
                </form>

                <form v-if="panel === 'create'" class="space-y-4 rounded-xl border border-primary-200 bg-white p-5 shadow-sm dark:border-primary-900 dark:bg-gray-950" @submit.prevent="submit">
                    <h2 class="font-heading text-base font-bold text-slate-800 dark:text-white">Nouveau fournisseur · {{ current.name }}</h2>
                    <ValidationErrorSummary :errors="form.errors" />
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <label class="block"><span :class="labelClass">Nom <span class="text-red-500">*</span></span><input v-model="form.name" name="name" :class="inputClass" required></label>
                        <label class="block"><span :class="labelClass">Code court <span class="text-red-500">*</span></span><input v-model="form.code" name="code" :class="[inputClass, 'uppercase']" required><span class="mt-1 block text-xs text-slate-400">Définitif : il identifie le fournisseur dans les imports.</span></label>
                        <label class="block"><span :class="labelClass">Personne à contacter</span><input v-model="form.contact_name" name="contact_name" :class="inputClass"></label>
                        <label class="block"><span :class="labelClass">Téléphone</span><input v-model="form.phone" name="phone" type="tel" :class="inputClass"></label>
                        <label class="block"><span :class="labelClass">E-mail</span><input v-model="form.email" name="email" type="email" :class="inputClass"></label>
                        <label class="block"><span :class="labelClass">Adresse</span><input v-model="form.address" name="address" :class="inputClass"></label>
                    </div>
                    <div class="flex justify-end gap-2">
                        <Button type="button" size="rg" variant="white-outline" @click="panel = null">Annuler</Button>
                        <Button type="submit" size="rg" :disabled="form.processing"><Icon name="folder-plus" /><span class="ms-2">Créer le dossier</span></Button>
                    </div>
                </form>

                <section class="rounded-xl border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-900 dark:bg-gray-1000/40">
                    <p v-if="isArchivedView && visible.length" class="px-2 pb-2 text-xs text-slate-500">Fournisseurs archivés : invisibles pour les commandes et les entrées de stock, mais leur historique est conservé. Ouvrez un dossier pour le restaurer.</p>
                    <div v-if="visible.length" class="grid grid-cols-2 gap-1 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6">
                        <FolderCard
                            v-for="supplier in visible"
                            :key="supplier.uuid"
                            :href="`/super-admin/pharmacy-suppliers/${current.code}/${supplier.uuid}`"
                            :title="supplier.name"
                            :subtitle="supplier.contact_name || supplier.phone || supplier.code"
                            :meta="supplier.archived ? 'Archivé' : (supplier.catalogs_count ? `${supplier.catalogs_count} catalogue${supplier.catalogs_count > 1 ? 's' : ''}` : 'Aucun catalogue')"
                        />
                    </div>
                    <EmptyState
                        v-else
                        icon="folder"
                        :title="current.suppliers.length ? 'Aucun fournisseur trouvé' : (isArchivedView ? 'Aucun fournisseur archivé' : 'Aucun fournisseur sur ce site')"
                    />
                </section>

                <p v-if="can.export && sites.length > 1" class="text-right text-xs text-slate-500">
                    <a :href="exportUrl('ALL')" class="font-bold text-primary-600 hover:text-primary-700">Exporter les fournisseurs de tous les sites</a>
                </p>
            </template>
        </template>
        <EmptyState v-else icon="building" title="Aucun site configuré" />
    </div>
</template>
