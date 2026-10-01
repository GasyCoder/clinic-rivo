<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Archive,
    ArchiveRestore,
    CircleAlert,
    CircleCheck,
    CirclePause,
    Download,
    Eye,
    FileSpreadsheet,
    GraduationCap,
    IdCard,
    Mail,
    Palmtree,
    Pencil,
    Phone,
    RotateCcw,
    Search,
    Trash2,
    Upload,
    UserRoundSearch,
    UserPlus,
    Users,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmployeePhoto from '@/Components/Administration/EmployeePhoto.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import ExplorerView from '@/Components/UI/ExplorerView.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import HrPagination from '../Partials/HrPagination.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { badgeSheetPath } from '@/utilities/employeeBadge';
import { bulkTargets, duplicateLabel, forceDeleteState } from '@/utilities/employeeActions';
import { hrUrl } from '@/utilities/hrUrl';

defineOptions({ layout: AppLayout });

/**
 * L'annuaire du personnel d'un site (ADR-066), le même sur le site et sur le
 * portail (ADR-187). Les compteurs sont aussi les filtres ; les échanges Excel
 * (modèle, import, export) sont réunis au même endroit.
 */
const props = defineProps({ employees: Object, filters: Object, summary: Object });
const { can } = usePermissions();

const query = ref(props.filters?.q ?? '');
const statusFilter = computed(() => props.filters?.status ?? 'active');

const visit = (params) => router.get(hrUrl('/administration/employees'), params, { preserveState: true, preserveScroll: true, replace: true });
const setStatus = (value) => visit({ q: query.value || undefined, status: value });
const submitSearch = () => visit({ q: query.value || undefined, status: statusFilter.value });

// La recherche se lance d'elle-même après une courte pause ; Entrée la lance tout de suite.
let pause = null;
watch(query, () => {
    window.clearTimeout(pause);
    pause = window.setTimeout(submitSearch, 350);
});
onBeforeUnmount(() => window.clearTimeout(pause));

const clearSearch = () => {
    query.value = '';
    window.clearTimeout(pause);
    submitSearch();
};



/** « 2024-03-01 » → « 01/03/2024 », sans passer par un fuseau horaire. */
const frenchDate = (iso) => (iso ? iso.split('-').reverse().join('/') : null);

/* ------------------------------------------------------------------ */
/* Compteurs : chacun est un filtre                                    */
/* ------------------------------------------------------------------ */

const total = computed(() => Number(props.summary?.active ?? 0) + Number(props.summary?.inactive ?? 0) + Number(props.summary?.archived ?? 0));

const STATUS_CARDS = [
    { value: 'all', label: 'Tous', hint: 'Tous les dossiers', icon: Users, tone: 'bg-primary/10 text-primary' },
    { value: 'active', label: 'Actifs', hint: 'En poste', icon: CircleCheck, tone: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300' },
    // ADR-207 — en congé aujourd'hui : toujours actifs (le dossier reste en service), mais absents.
    { value: 'on_leave', label: 'En congé', hint: 'Actifs, absents aujourd’hui', icon: Palmtree, tone: 'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300' },
    { value: 'inactive', label: 'Inactifs', hint: 'Hors poste', icon: CirclePause, tone: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300' },
    { value: 'archived', label: 'Archivés', hint: 'Restaurables', icon: Archive, tone: 'bg-muted text-muted-foreground' },
];

const cards = computed(() => STATUS_CARDS.map((card) => {
    const count = card.value === 'all' ? total.value : Number(props.summary?.[card.value] ?? 0);

    return { ...card, count, share: total.value ? Math.round((count / total.value) * 100) : 0 };
}));

/* ------------------------------------------------------------------ */
/* Échanges Excel                                                      */
/* ------------------------------------------------------------------ */

const excelActions = computed(() => [
    can('employees.import') && { key: 'template', label: 'Modèle Excel', short: 'Modèle', title: 'Télécharger le fichier modèle à remplir pour l’import', icon: FileSpreadsheet, href: hrUrl('/administration/employees/import-template'), download: true },
    can('employees.import') && { key: 'import', label: 'Importer', short: 'Importer', title: 'Importer des employés depuis un fichier Excel', icon: Upload, href: hrUrl('/administration/employees/import'), download: false },
    can('employees.export') && { key: 'export', label: 'Exporter', short: 'Exporter', title: 'Exporter la liste des employés en Excel', icon: Download, href: hrUrl('/administration/employees/export'), download: true },
].filter(Boolean));

/* ------------------------------------------------------------------ */
/* Liste                                                               */
/* ------------------------------------------------------------------ */

const count = computed(() => props.employees?.total ?? props.employees?.data?.length ?? 0);
const searching = computed(() => (props.filters?.q ?? '') !== '');
const emptyTitle = computed(() => (total.value === 0 ? 'Aucun employé pour l’instant' : 'Aucun employé ne correspond'));
const emptyDescription = computed(() => (total.value === 0
    ? 'Créez un premier dossier, ou importez le personnel depuis le modèle Excel.'
    : 'Changez la recherche ou le filtre d’état.'));

// ADR-207 — les stagiaires ne sont pas des employés : la page le dit, et mène à « Stages ».
const interns = computed(() => Number(props.summary?.interns ?? 0));

/* ------------------------------------------------------------------ */
/* Badges (ADR-209)                                                    */
/* ------------------------------------------------------------------ */

const canBadge = computed(() => can('employees.print'));
const canArchive = computed(() => can('employees.delete'));
const canRestore = computed(() => can('employees.restore'));
const canForceDelete = computed(() => can('employees.force_delete'));
/** On coche pour imprimer des badges, archiver, restaurer ou supprimer (ADR-209, ADR-236). */
const canSelect = computed(() => canBadge.value || canArchive.value || canRestore.value || canForceDelete.value);

/** Les dossiers cochés sur la page ouverte ; un autre filtre ou une autre page repart de zéro. */
const selected = ref([]);
watch(() => props.employees?.data, () => { selected.value = []; });

const selectable = computed(() => props.employees?.data ?? []);
const allSelected = computed(() => {
    if (selectable.value.length === 0 || selected.value.length === 0) return false;

    return selected.value.length === selectable.value.length ? true : 'indeterminate';
});
const toggleAll = (checked) => { selected.value = checked ? selectable.value.map((employee) => employee.uuid) : []; };
const toggleOne = (uuid, checked) => {
    selected.value = checked ? [...new Set([...selected.value, uuid])] : selected.value.filter((item) => item !== uuid);
};
const selectedRows = computed(() => selectable.value.filter((employee) => selected.value.includes(employee.uuid)));
const targets = computed(() => bulkTargets(selectedRows.value, {
    badge: canBadge.value, archive: canArchive.value, restore: canRestore.value, forceDelete: canForceDelete.value,
}));

/** Un dossier archivé n'a plus de badge : la vue « Archivés » n'en propose aucun. */
const badgesAvailable = computed(() => canBadge.value && statusFilter.value !== 'archived' && count.value > 0);
const allBadgesUrl = computed(() => hrUrl(badgeSheetPath({ status: statusFilter.value, q: props.filters?.q || undefined })));
const selectedBadgesUrl = computed(() => hrUrl(badgeSheetPath({ uuids: targets.value.badge.map((employee) => employee.uuid) })));
const badgeUrl = (employee) => hrUrl(`/administration/employees/${employee.uuid}/badge`);

/* ------------------------------------------------------------------ */
/* Archiver, restaurer, supprimer définitivement (ADR-236)             */
/* ------------------------------------------------------------------ */

const deletion = (employee) => forceDeleteState(employee, canForceDelete.value);

/** Le geste en attente de confirmation : { mode: archive|restore|force_delete, rows } */
const pending = ref(null);
const actionForm = useForm({ reason: '' });
const ask = (mode, rows) => {
    actionForm.reset();
    actionForm.clearErrors();
    pending.value = { mode, rows };
};
const closePending = (open) => { if (! open) pending.value = null; };

const MODALS = {
    archive: { verb: 'Archiver', tone: 'warning', icon: Archive },
    restore: { verb: 'Restaurer', tone: 'success', icon: ArchiveRestore },
    force_delete: { verb: 'Supprimer définitivement', tone: 'danger', icon: Trash2 },
};
const modal = computed(() => {
    if (! pending.value) return null;
    const { mode, rows } = pending.value;
    const base = MODALS[mode];
    const subject = rows.length === 1 ? rows[0].name : `${rows.length} dossiers`;

    return {
        ...base,
        title: `${base.verb} ${rows.length === 1 ? 'le dossier de ' : ''}${subject}`,
        confirm: rows.length === 1 ? base.verb : `${base.verb} (${rows.length})`,
    };
});
const reasonMissing = computed(() => pending.value?.mode === 'archive' && actionForm.reason.trim() === '');
const actionError = computed(() => Object.values(actionForm.errors)[0] ?? '');

const confirmPending = () => {
    const { mode, rows } = pending.value;
    const options = { preserveScroll: true, onSuccess: () => { pending.value = null; selected.value = []; } };

    if (rows.length > 1) {
        actionForm.transform((data) => ({ action: mode, uuids: rows.map((row) => row.uuid), reason: mode === 'archive' ? data.reason : null }))
            .post(hrUrl('/administration/employees/bulk'), options);
    } else if (mode === 'archive') {
        actionForm.transform((data) => ({ reason: data.reason, back: true })).delete(hrUrl(`/administration/employees/${rows[0].uuid}`), options);
    } else if (mode === 'force_delete') {
        actionForm.transform(() => ({ back: true })).delete(hrUrl(`/administration/employees/${rows[0].uuid}/force`), options);
    } else {
        actionForm.transform(() => ({})).post(hrUrl(`/administration/employees/${rows[0].uuid}/restore`), options);
    }
};

// Rapport d'un geste groupé : ce qui est passé, ce qui ne l'est pas, et pourquoi.
const page = usePage();
const report = computed(() => (page.props.flash?.bulk_report?.action === 'employees_bulk' ? page.props.flash.bulk_report : null));
const reportHidden = ref(false);
watch(report, () => { reportHidden.value = false; });
const REPORT_VERBS = { archive: 'archivé', restore: 'restauré', force_delete: 'supprimé' };

/* ------------------------------------------------------------------ */
/* Doublons possibles (ADR-236)                                        */
/* ------------------------------------------------------------------ */

const duplicateCount = computed(() => Number(props.summary?.duplicates ?? 0));
const duplicatesView = computed(() => statusFilter.value === 'duplicates');
</script>

<template>
    <Head title="Employés" />

    <div class="w-full space-y-5">
        <PageHeader eyebrow="Ressources humaines" title="Employés" description="Un dossier par personne : identité, affectation, contrats et documents." icon="users" tone="primary">
            <template #actions>
                <!-- Les échanges Excel, réunis : le modèle à remplir, l'import, l'export. -->
                <div v-if="excelActions.length" class="flex w-full rounded-lg shadow-sm sm:inline-flex sm:w-auto" role="group" aria-label="Échanges Excel">
                    <Button
                        v-for="(action, index) in excelActions"
                        :key="action.key"
                        :as="action.download ? 'a' : Link"
                        :href="action.href"
                        variant="outline"
                        :title="action.title"
                        :aria-label="action.label"
                        :class="cn(
                            'flex-1 px-3 shadow-none sm:flex-none sm:px-4',
                            excelActions.length > 1 && index === 0 && 'rounded-e-none',
                            excelActions.length > 1 && index === excelActions.length - 1 && '-ms-px rounded-s-none',
                            excelActions.length > 2 && index > 0 && index < excelActions.length - 1 && '-ms-px rounded-none',
                        )"
                    >
                        <component :is="action.icon" class="h-4 w-4" /><span class="sm:hidden">{{ action.short }}</span><span class="hidden sm:inline">{{ action.label }}</span>
                    </Button>
                </div>
                <Button
                    v-if="badgesAvailable"
                    :as="Link"
                    :href="allBadgesUrl"
                    variant="outline"
                    :title="`Badges des ${count} employé${count > 1 ? 's' : ''} affiché${count > 1 ? 's' : ''} (toutes les pages)`"
                >
                    <IdCard class="h-4 w-4" />Badges<span class="rounded-full bg-muted px-1.5 text-xs tabular-nums text-muted-foreground">{{ count }}</span>
                </Button>
                <Button v-if="can('employees.create')" :as="Link" :href="hrUrl('/administration/employees/create')">
                    <UserPlus class="h-4 w-4" />Nouvel employé
                </Button>
            </template>
        </PageHeader>

        <!-- ADR-209 / ADR-236 — les dossiers cochés : leurs badges, ou un geste groupé. -->
        <div v-if="selected.length" class="flex flex-wrap items-center gap-3 rounded-xl border border-primary/40 bg-primary/5 px-4 py-2.5" role="status">
            <span class="text-sm font-medium text-foreground">{{ selected.length }} dossier{{ selected.length > 1 ? 's' : '' }} coché{{ selected.length > 1 ? 's' : '' }}</span>
            <div class="ms-auto flex flex-wrap items-center gap-2">
                <Button v-if="targets.badge.length" :as="Link" :href="selectedBadgesUrl" size="sm" variant="outline"><IdCard class="h-4 w-4" />Badges ({{ targets.badge.length }})</Button>
                <Button v-if="targets.archive.length" type="button" size="sm" variant="outline" @click="ask('archive', targets.archive)"><Archive class="h-4 w-4" />Archiver ({{ targets.archive.length }})</Button>
                <Button v-if="targets.restore.length" type="button" size="sm" variant="outline" @click="ask('restore', targets.restore)"><ArchiveRestore class="h-4 w-4" />Restaurer ({{ targets.restore.length }})</Button>
                <Button
                    v-if="canForceDelete && selectedRows.some((row) => row.archived)"
                    type="button"
                    size="sm"
                    variant="destructive"
                    :disabled="targets.forceDelete.length === 0"
                    :title="targets.forceDelete.length ? 'Seuls les dossiers qui n’ont servi nulle part sont supprimés' : 'Aucun des dossiers cochés n’est supprimable : ils ont servi'"
                    @click="ask('force_delete', targets.forceDelete)"
                ><Trash2 class="h-4 w-4" />Supprimer définitivement ({{ targets.forceDelete.length }})</Button>
                <Button type="button" variant="ghost" size="sm" @click="selected = []"><X class="h-4 w-4" />Décocher</Button>
            </div>
        </div>

        <div v-if="report && ! reportHidden" class="flex items-start gap-3 rounded-lg border border-border bg-card px-4 py-3 shadow-sm" role="status">
            <component :is="report.failed.length ? CircleAlert : CircleCheck" :class="['mt-0.5 h-5 w-5 shrink-0', report.failed.length ? 'text-amber-600' : 'text-emerald-600']" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-foreground">{{ report.done }} sur {{ report.total }} dossier{{ report.total > 1 ? 's' : '' }} {{ REPORT_VERBS[report.kind] }}{{ report.done > 1 ? 's' : '' }}</p>
                <ul v-if="report.failed.length" class="mt-2 space-y-1 text-sm">
                    <li v-for="(failure, index) in report.failed" :key="index" class="text-muted-foreground"><span class="font-medium text-foreground">{{ failure.label }}</span> — {{ failure.message }}</li>
                </ul>
            </div>
            <Button type="button" size="icon-xs" variant="ghost" aria-label="Fermer le rapport" @click="reportHidden = true"><X class="h-4 w-4" /></Button>
        </div>

        <!-- Les compteurs sont les filtres : un clic affiche ce qu'ils comptent. -->
        <div class="grid grid-cols-2 gap-1.5 rounded-xl border border-border bg-card p-1.5 shadow-sm sm:grid-cols-3 xl:grid-cols-5" role="group" aria-label="Filtrer par état">
            <button
                v-for="card in cards"
                :key="card.value"
                type="button"
                :aria-pressed="statusFilter === card.value"
                :title="card.hint"
                :class="cn(
                    'group relative flex min-h-14 items-center gap-2.5 rounded-lg px-2.5 py-2 text-start transition-colors',
                    'hover:bg-muted/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    statusFilter === card.value ? 'bg-primary/10 text-primary' : 'text-foreground',
                )"
                @click="setStatus(card.value)"
            >
                <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-lg', card.tone]">
                    <component :is="card.icon" class="h-4 w-4" aria-hidden="true" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-lg font-bold leading-none tabular-nums text-foreground">{{ card.count }}</span>
                    <span class="mt-1 block truncate text-[11px] font-medium leading-none text-muted-foreground">{{ card.label }}</span>
                </span>
                <span v-if="card.value !== 'all'" class="text-[10px] tabular-nums text-muted-foreground">{{ card.share }} %</span>
                <span class="absolute inset-x-3 bottom-0 h-0.5 origin-left scale-x-0 rounded-full bg-primary transition-transform" :class="statusFilter === card.value && 'scale-x-100'" aria-hidden="true" />
            </button>
        </div>

        <p v-if="interns" class="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-muted/40 px-4 py-2.5 text-sm text-muted-foreground">
            <GraduationCap class="h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
            {{ interns }} stagiaire{{ interns > 1 ? 's' : '' }} ne figure{{ interns > 1 ? 'nt' : '' }} pas ici : un stagiaire n’est pas un employé.
            <Link v-if="can('contracts.view')" :href="hrUrl('/administration/internships')" class="font-semibold text-primary hover:underline">Voir les stages</Link>
        </p>

        <!-- ADR-236 — deux dossiers pour une même personne : on les montre ensemble, le RH décide. -->
        <div
            v-if="duplicateCount || duplicatesView"
            class="flex flex-wrap items-center gap-2 rounded-lg border border-amber-300/70 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:border-amber-800/60 dark:bg-amber-950/30 dark:text-amber-200"
        >
            <UserRoundSearch class="h-4 w-4 shrink-0" aria-hidden="true" />
            <span v-if="duplicatesView" class="flex-1">Même nom, même prénom et, quand elle est connue, même date de naissance. Gardez le bon dossier, archivez l’autre : s’il n’a servi nulle part, il pourra ensuite être supprimé définitivement.</span>
            <span v-else class="flex-1">{{ duplicateCount }} dossier{{ duplicateCount > 1 ? 's' : '' }} en service désigne{{ duplicateCount > 1 ? 'nt' : '' }} peut-être la même personne qu’un autre.</span>
            <Button v-if="duplicatesView" type="button" size="sm" variant="outline" @click="setStatus('active')"><X class="h-4 w-4" />Quitter</Button>
            <Button v-else type="button" size="sm" variant="outline" @click="setStatus('duplicates')"><UserRoundSearch class="h-4 w-4" />Voir les doublons</Button>
        </div>

        <ExplorerView
            storage-key="hr-employees"
            :count="count"
            count-label="employé"
            grid-label="Cartes"
            default-view="list"
            grid-class="grid grid-cols-1 gap-3 p-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
            empty-icon="users"
            :empty-title="emptyTitle"
            :empty-description="emptyDescription"
        >
            <template #toolbar>
                <form class="flex w-full items-center gap-2 sm:w-auto" role="search" @submit.prevent="submitSearch">
                    <label class="relative block flex-1 sm:w-80">
                        <span class="sr-only">Rechercher un employé</span>
                        <IconInput v-model="query" :icon="Search" type="search" class="pe-9" placeholder="Nom, matricule, fonction, téléphone…" />
                        <button
                            v-if="query"
                            type="button"
                            class="absolute inset-y-0 end-2 my-auto grid h-6 w-6 place-items-center rounded-md text-muted-foreground transition hover:bg-accent hover:text-foreground"
                            aria-label="Effacer la recherche"
                            @click="clearSearch"
                        ><X class="h-3.5 w-3.5" /></button>
                    </label>
                </form>
            </template>

            <template #empty>
                <div class="flex flex-wrap justify-center gap-2">
                    <template v-if="total === 0">
                        <Button v-if="can('employees.create')" :as="Link" :href="hrUrl('/administration/employees/create')" size="sm"><UserPlus class="h-4 w-4" />Nouvel employé</Button>
                        <Button v-if="can('employees.import')" :as="Link" :href="hrUrl('/administration/employees/import')" variant="outline" size="sm"><Upload class="h-4 w-4" />Importer</Button>
                        <Button v-if="can('employees.import')" as="a" :href="hrUrl('/administration/employees/import-template')" variant="outline" size="sm"><FileSpreadsheet class="h-4 w-4" />Modèle Excel</Button>
                    </template>
                    <template v-else>
                        <Button v-if="searching" variant="outline" size="sm" @click="clearSearch"><X class="h-4 w-4" />Effacer la recherche</Button>
                        <Button v-if="statusFilter !== 'all'" variant="outline" size="sm" @click="setStatus('all')"><Users class="h-4 w-4" />Tous les dossiers</Button>
                    </template>
                </div>
            </template>

            <template #grid>
                <article
                    v-for="employee in employees.data"
                    :key="employee.uuid"
                    :class="cn('group flex min-w-0 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md', employee.archived && 'opacity-75')"
                >
                    <div class="flex-1 p-4">
                        <div class="flex items-start gap-3">
                            <Link :href="hrUrl(`/administration/employees/${employee.uuid}`)" class="flex min-w-0 flex-1 items-center gap-3 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                <EmployeePhoto :src="employee.photo_url" :name="employee.name" size="md" :class="employee.archived && 'grayscale'" />
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold text-foreground transition-colors group-hover:text-primary" :title="employee.name">{{ employee.name }}</span>
                                    <span class="mt-0.5 block truncate font-mono text-[11px] text-muted-foreground">{{ employee.employee_number }}</span>
                                    <span v-if="employee.duplicates?.length" class="mt-0.5 flex items-center gap-1 text-[11px] font-medium text-amber-700 dark:text-amber-300" :title="`Même personne que ${duplicateLabel(employee.duplicates)} ?`"><UserRoundSearch class="h-3 w-3" aria-hidden="true" />Doublon possible</span>
                                </span>
                            </Link>
                            <Badge v-if="employee.archived" variant="outline"><Archive class="h-3 w-3" />Archivé</Badge>
                            <Badge v-else-if="employee.active && employee.on_leave" variant="secondary" class="bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-200" :title="`${employee.on_leave.type ? `${employee.on_leave.type} · ` : ''}jusqu’au ${frenchDate(employee.on_leave.until)}`"><Palmtree class="h-3 w-3" />En congé</Badge>
                            <Badge v-else-if="employee.active" variant="success"><CircleCheck class="h-3 w-3" />Actif</Badge>
                            <Badge v-else variant="warning"><CirclePause class="h-3 w-3" />Inactif</Badge>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-3 rounded-lg bg-muted/50 p-3">
                            <div class="min-w-0">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Fonction</p>
                                <p class="mt-1 truncate text-sm font-medium text-foreground" :title="employee.job_title || 'Non renseignée'">{{ employee.job_title || 'Non renseignée' }}</p>
                            </div>
                            <div class="min-w-0 border-s border-border ps-3">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Département</p>
                                <p class="mt-1 truncate text-sm font-medium text-foreground" :title="employee.department || 'Non renseigné'">{{ employee.department || 'Non renseigné' }}</p>
                            </div>
                        </div>

                        <div class="mt-3 min-h-10 space-y-1.5 text-xs">
                            <a v-if="employee.phone" :href="`tel:${employee.phone}`" class="flex items-center gap-2 text-foreground hover:text-primary">
                                <Phone class="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden="true" /><span class="truncate">{{ employee.phone }}</span>
                            </a>
                            <a v-if="employee.email" :href="`mailto:${employee.email}`" class="flex items-center gap-2 text-muted-foreground hover:text-primary">
                                <Mail class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span class="truncate">{{ employee.email }}</span>
                            </a>
                            <span v-if="!employee.phone && !employee.email" class="text-muted-foreground">Aucun contact renseigné</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-3 border-t border-border bg-muted/20 px-3 py-2">
                        <span class="text-[11px] tabular-nums text-muted-foreground">Entrée · {{ frenchDate(employee.hire_date) || 'non renseignée' }}</span>
                        <div class="flex items-center gap-1">
                            <Button :as="Link" :href="hrUrl(`/administration/employees/${employee.uuid}`)" variant="ghost" size="icon" :aria-label="`Voir le dossier de ${employee.name}`" title="Voir le dossier"><Eye class="h-4 w-4" /></Button>
                            <Button v-if="!employee.archived && canBadge" :as="Link" :href="badgeUrl(employee)" variant="ghost" size="icon" :aria-label="`Badge de ${employee.name}`" title="Badge"><IdCard class="h-4 w-4" /></Button>
                            <Button v-if="!employee.archived && can('employees.update')" :as="Link" :href="hrUrl(`/administration/employees/${employee.uuid}/edit`)" variant="ghost" size="icon" :aria-label="`Modifier ${employee.name}`" title="Modifier"><Pencil class="h-4 w-4" /></Button>
                            <Button v-if="employee.archived && can('employees.restore')" type="button" variant="ghost" size="icon" :aria-label="`Restaurer ${employee.name}`" title="Restaurer" @click="ask('restore', [employee])"><RotateCcw class="h-4 w-4" /></Button>
                                    <Button v-if="!employee.archived && canArchive" type="button" variant="ghost" size="icon" class="text-muted-foreground hover:text-amber-700" :aria-label="`Archiver ${employee.name}`" title="Archiver (motif demandé, restaurable)" @click="ask('archive', [employee])"><Archive class="h-4 w-4" /></Button>
                                    <Button v-if="employee.archived && (canForceDelete || canRestore)" type="button" variant="ghost" size="icon" class="text-destructive hover:text-destructive" :disabled="! deletion(employee).allowed" :aria-label="`Supprimer définitivement ${employee.name}`" :title="deletion(employee).allowed ? 'Supprimer définitivement' : deletion(employee).reason" @click="ask('force_delete', [employee])"><Trash2 class="h-4 w-4" /></Button>
                        </div>
                    </div>
                </article>
            </template>

            <template #list>
                <table class="w-full min-w-[860px] text-sm">
                    <thead>
                        <tr class="border-b border-border bg-muted/40 text-left text-xs font-semibold text-muted-foreground">
                            <th v-if="canSelect" scope="col" class="w-10 ps-4 pe-0 py-2.5">
                                <Checkbox :model-value="allSelected" :disabled="selectable.length === 0" aria-label="Cocher tous les employés de la page" @update:model-value="toggleAll" />
                            </th>
                            <th scope="col" class="px-4 py-2.5">Employé</th>
                            <th scope="col" class="px-4 py-2.5">Fonction · département</th>
                            <th scope="col" class="px-4 py-2.5">Contact</th>
                            <th scope="col" class="px-4 py-2.5">Entrée</th>
                            <th scope="col" class="px-4 py-2.5">État</th>
                            <th scope="col" class="px-4 py-2.5 text-end"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="employee in employees.data"
                            :key="employee.uuid"
                            :class="cn('transition-colors hover:bg-muted/40', employee.archived && 'text-muted-foreground', selected.includes(employee.uuid) && 'bg-primary/5')"
                        >
                            <td v-if="canSelect" class="w-10 ps-4 pe-0 py-2.5">
                                <Checkbox
                                    :model-value="selected.includes(employee.uuid)"
                                    :aria-label="`Cocher ${employee.name}`"
                                    @update:model-value="(checked) => toggleOne(employee.uuid, checked)"
                                />
                            </td>
                            <td class="px-4 py-2.5">
                                <Link :href="hrUrl(`/administration/employees/${employee.uuid}`)" class="group flex items-center gap-3">
                                    <!-- ADR-194 — la photo 4 × 4, sinon les initiales. -->
                                    <EmployeePhoto :src="employee.photo_url" :name="employee.name" size="sm" :class="employee.archived && 'opacity-60 grayscale'" />
                                    <span class="min-w-0">
                                        <span :class="cn('flex items-center gap-1.5 truncate font-semibold group-hover:text-primary', employee.archived ? 'text-muted-foreground' : 'text-foreground')">
                                            {{ employee.name }}
                                            <Badge v-if="employee.is_intern" variant="secondary" class="px-1.5 py-0 text-[10px] uppercase tracking-wide">Stagiaire</Badge>
                                        </span>
                                        <span class="block font-mono text-xs text-muted-foreground">{{ employee.employee_number }}</span>
                                        <span v-if="employee.duplicates?.length" class="mt-0.5 flex items-center gap-1 text-[11px] font-medium text-amber-700 dark:text-amber-300" :title="`Même personne que ${duplicateLabel(employee.duplicates)} ?`">
                                            <UserRoundSearch class="h-3 w-3" aria-hidden="true" />Doublon possible · {{ employee.duplicates.map((other) => other.number).join(', ') }}
                                        </span>
                                        <span v-if="employee.archived && employee.deletion_blockers?.length === 0" class="mt-0.5 block text-[11px] text-muted-foreground">N’a servi nulle part</span>
                                    </span>
                                </Link>
                            </td>
                            <td class="px-4 py-2.5">
                                <p class="text-foreground">{{ employee.job_title || '—' }}</p>
                                <p class="text-xs text-muted-foreground">{{ employee.department || 'Département non renseigné' }}</p>
                            </td>
                            <td class="px-4 py-2.5">
                                <a v-if="employee.phone" :href="`tel:${employee.phone}`" class="flex items-center gap-1.5 text-foreground hover:text-primary">
                                    <Phone class="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />{{ employee.phone }}
                                </a>
                                <a v-if="employee.email" :href="`mailto:${employee.email}`" class="mt-0.5 flex max-w-56 items-center gap-1.5 text-xs text-muted-foreground hover:text-primary">
                                    <Mail class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span class="truncate">{{ employee.email }}</span>
                                </a>
                                <span v-if="! employee.phone && ! employee.email" class="text-muted-foreground">—</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 tabular-nums text-muted-foreground">{{ frenchDate(employee.hire_date) || '—' }}</td>
                            <td class="px-4 py-2.5">
                                <Badge v-if="employee.archived" variant="outline"><Archive class="h-3 w-3" />Archivé</Badge>
                                <Badge v-else-if="employee.active" variant="success"><CircleCheck class="h-3 w-3" />Actif</Badge>
                                <Badge v-else variant="warning"><CirclePause class="h-3 w-3" />Inactif</Badge>
                                <!-- ADR-207 — en service, mais en congé ce jour : les deux se lisent. -->
                                <span v-if="employee.on_leave && ! employee.archived" class="mt-1 flex flex-col gap-0.5">
                                    <Badge variant="secondary" class="w-fit bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-200"><Palmtree class="h-3 w-3" />En congé</Badge>
                                    <span class="text-[11px] text-muted-foreground">{{ employee.on_leave.type ? `${employee.on_leave.type} · ` : '' }}jusqu’au {{ frenchDate(employee.on_leave.until) }}</span>
                                </span>
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end gap-1">
                                    <Button :as="Link" :href="hrUrl(`/administration/employees/${employee.uuid}`)" variant="ghost" size="icon" :aria-label="`Voir le dossier de ${employee.name}`" title="Voir le dossier"><Eye class="h-4 w-4" /></Button>
                                    <Button v-if="!employee.archived && canBadge" :as="Link" :href="badgeUrl(employee)" variant="ghost" size="icon" :aria-label="`Badge de ${employee.name}`" title="Badge"><IdCard class="h-4 w-4" /></Button>
                                    <Button v-if="!employee.archived && can('employees.update')" :as="Link" :href="hrUrl(`/administration/employees/${employee.uuid}/edit`)" variant="ghost" size="icon" :aria-label="`Modifier ${employee.name}`" title="Modifier"><Pencil class="h-4 w-4" /></Button>
                                    <Button v-if="employee.archived && can('employees.restore')" type="button" variant="ghost" size="icon" :aria-label="`Restaurer ${employee.name}`" title="Restaurer" @click="ask('restore', [employee])"><RotateCcw class="h-4 w-4" /></Button>
                                    <Button v-if="!employee.archived && canArchive" type="button" variant="ghost" size="icon" class="text-muted-foreground hover:text-amber-700" :aria-label="`Archiver ${employee.name}`" title="Archiver (motif demandé, restaurable)" @click="ask('archive', [employee])"><Archive class="h-4 w-4" /></Button>
                                    <Button v-if="employee.archived && (canForceDelete || canRestore)" type="button" variant="ghost" size="icon" class="text-destructive hover:text-destructive" :disabled="! deletion(employee).allowed" :aria-label="`Supprimer définitivement ${employee.name}`" :title="deletion(employee).allowed ? 'Supprimer définitivement' : deletion(employee).reason" @click="ask('force_delete', [employee])"><Trash2 class="h-4 w-4" /></Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>

            <template #footer>
                <HrPagination :paginator="employees" />
            </template>
        </ExplorerView>

        <ConfirmModal
            v-if="modal"
            :open="Boolean(pending)"
            :title="modal.title"
            :confirm-label="modal.confirm"
            :tone="modal.tone"
            :icon="modal.icon"
            :processing="actionForm.processing"
            :disabled="reasonMissing"
            :dismissible="pending.mode !== 'force_delete'"
            @update:open="closePending"
            @confirm="confirmPending"
        >
            <div class="space-y-3 text-sm">
                <p v-if="pending.mode === 'archive'" class="text-muted-foreground">Le dossier quitte la liste, garde tout son historique et se restaure depuis « Archivés ».</p>
                <p v-else-if="pending.mode === 'restore'" class="text-muted-foreground">Le dossier revient dans la liste, tel qu’il était.</p>
                <p v-else class="text-muted-foreground">Le dossier est détruit : c’est irréversible. Seul un dossier archivé qui n’a servi nulle part (aucun contrat, présence, congé, paie, document, compte…) peut l’être ; ce qu’il était reste dans l’audit.</p>

                <ul v-if="pending.rows.length > 1" class="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-border bg-muted/30 px-3 py-2">
                    <li v-for="row in pending.rows" :key="row.uuid" class="flex items-center justify-between gap-2">
                        <span class="truncate font-medium text-foreground">{{ row.name }}</span>
                        <span class="font-mono text-xs text-muted-foreground">{{ row.employee_number }}</span>
                    </li>
                </ul>

                <FormField v-if="pending.mode === 'archive'" label="Motif d’archivage" required :error="actionForm.errors.reason">
                    <Textarea v-model="actionForm.reason" rows="3" placeholder="Ex. doublon de EMP-0002, départ, saisie à tort…" />
                </FormField>
                <p v-if="actionError && ! actionForm.errors.reason" class="rounded-lg bg-destructive/10 px-3 py-2 text-destructive">{{ actionError }}</p>
            </div>
        </ConfirmModal>
    </div>
</template>
