<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Archive, ArchiveRestore, CalendarClock, CalendarPlus, CircleAlert, CircleCheck, FilePlus2, FolderOpen, GraduationCap, History, IdCard, Layers,
    Pencil, RotateCcw, School, Search, Trash2, TriangleAlert, UserPlus, UserRound, X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import EmployeePhoto from '@/Components/Administration/EmployeePhoto.vue';
import HrPagination from '../Partials/HrPagination.vue';
import HrStatCard from '../Partials/HrStatCard.vue';
import { usePermissions } from '@/composables/usePermissions';
import { endingLabel, formatPeriod } from '@/utilities/hr';
import { cn } from '@/lib/cn';
import { badgeSheetPath } from '@/utilities/employeeBadge';
import { bulkTargets, forceDeleteState } from '@/utilities/employeeActions';
import { internDossiers } from '@/utilities/internships';

defineOptions({ layout: AppLayout });

/*
 * ADR-194 — tous les stages de la clinique. Un stagiaire est un dossier
 * Employé (photo, présences, planning de garde) ; son stage est un contrat
 * dont le type est marqué « contrat de stage », avec sa filière, son école
 * et son encadrant.
 */
const props = defineProps({
    internships: Object,
    filters: Object,
    counts: Object,
    fields: Array,
    withoutField: Number,
    hasInternshipType: Boolean,
});
const { can } = usePermissions();

const query = ref(props.filters?.q ?? '');
const params = (overrides = {}) => {
    const next = { q: query.value || undefined, status: props.filters.status, field: props.filters.field || undefined, ...overrides };
    Object.keys(next).forEach((key) => next[key] === undefined && delete next[key]);
    return next;
};
const visit = (overrides) => router.get(hrUrl('/administration/internships'), params(overrides), { preserveState: true, preserveScroll: true, replace: true });
const search = () => visit({ page: undefined });
const href = (overrides) => hrUrl(`/administration/internships?${new URLSearchParams(params(overrides)).toString()}`);

const STATUSES = [
    { key: 'current', label: 'En cours', hint: 'Stagiaires présents', icon: CircleCheck, tone: 'emerald' },
    { key: 'future', label: 'À venir', hint: 'Début futur', icon: CalendarPlus, tone: 'sky' },
    { key: 'ended', label: 'Terminés', hint: 'Fin passée', icon: History, tone: 'amber' },
    { key: 'all', label: 'Tous les stages', hint: 'Depuis toujours', icon: Layers, tone: 'violet' },
    // ADR-243 — les dossiers de stagiaires archivés : restaurables, ou supprimés s'ils n'ont servi nulle part.
    { key: 'archived', label: 'Archivés', hint: 'Dossiers restaurables', icon: Archive, tone: 'slate' },
];
const STATE = {
    current: { label: 'En cours', tone: 'success' },
    future: { label: 'À venir', tone: 'info' },
    ended: { label: 'Terminé', tone: 'neutral' },
    archived: { label: 'Archivé', tone: 'neutral' },
};
const canCreateIntern = computed(() => can('employees.create') && can('contracts.create'));

// ADR-209 — les badges des stagiaires affichés (vue, recherche et filière : toutes les pages), ou d'un seul.
const canBadge = computed(() => can('employees.print'));
const listedCount = computed(() => props.internships?.total ?? props.internships?.data?.length ?? 0);
const badgesUrl = computed(() => hrUrl(badgeSheetPath({
    scope: 'interns',
    status: props.filters?.status,
    q: props.filters?.q || undefined,
    field: props.filters?.field || undefined,
})));
const badgeUrl = (stage) => hrUrl(`/administration/employees/${stage.employee.uuid}/badge`);

/*
 * ADR-243 — la liste des stages se manie comme celle des employés (ADR-236) : on coche
 * des stagiaires pour imprimer leurs badges, archiver, restaurer ou supprimer leur
 * dossier. Un geste porte sur le dossier du stagiaire, jamais sur son seul contrat.
 */
const canArchive = computed(() => can('employees.delete'));
const canRestore = computed(() => can('employees.restore'));
const canForceDelete = computed(() => can('employees.force_delete'));
const canSelect = computed(() => canBadge.value || canArchive.value || canRestore.value || canForceDelete.value);

const stages = computed(() => props.internships?.data ?? []);
/** Les stagiaires de la page, un par dossier (un stagiaire peut avoir deux stages). */
const dossiers = computed(() => internDossiers(stages.value));
const selected = ref([]);
watch(() => props.internships?.data, () => { selected.value = []; });

const allSelected = computed(() => {
    if (dossiers.value.length === 0 || selected.value.length === 0) return false;

    return selected.value.length === dossiers.value.length ? true : 'indeterminate';
});
const toggleAll = (checked) => { selected.value = checked ? dossiers.value.map((dossier) => dossier.uuid) : []; };
const toggleOne = (uuid, checked) => {
    selected.value = checked ? [...new Set([...selected.value, uuid])] : selected.value.filter((item) => item !== uuid);
};
const selectedRows = computed(() => dossiers.value.filter((dossier) => selected.value.includes(dossier.uuid)));
const targets = computed(() => bulkTargets(selectedRows.value, {
    badge: canBadge.value, archive: canArchive.value, restore: canRestore.value, forceDelete: canForceDelete.value,
}));
const selectedBadgesUrl = computed(() => hrUrl(badgeSheetPath({ scope: 'interns', uuids: targets.value.badge.map((dossier) => dossier.uuid) })));
const archivedView = computed(() => props.filters?.status === 'archived');
const deletion = (stage) => forceDeleteState(stage.employee, canForceDelete.value);

const pending = ref(null);
const actionForm = useForm({ reason: '' });
const ask = (mode, rows) => {
    actionForm.reset();
    actionForm.clearErrors();
    pending.value = { mode, rows };
};
const askFor = (mode, stage) => ask(mode, internDossiers([stage]));
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

    return {
        ...base,
        title: rows.length === 1 ? `${base.verb} le dossier de ${rows[0].name}` : `${base.verb} ${rows.length} stagiaires`,
        confirm: rows.length === 1 ? base.verb : `${base.verb} (${rows.length})`,
    };
});
const reasonMissing = computed(() => pending.value?.mode === 'archive' && actionForm.reason.trim() === '');
const actionError = computed(() => Object.values(actionForm.errors)[0] ?? '');

const confirmPending = () => {
    const { mode, rows } = pending.value;
    const options = { preserveScroll: true, onSuccess: () => { pending.value = null; selected.value = []; } };

    // Le même geste que la liste des employés : il revient ici avec son rapport.
    actionForm.transform((data) => ({ action: mode, uuids: rows.map((row) => row.uuid), reason: mode === 'archive' ? data.reason : null }))
        .post(hrUrl('/administration/employees/bulk'), options);
};

const page = usePage();
const report = computed(() => (page.props.flash?.bulk_report?.action === 'employees_bulk' ? page.props.flash.bulk_report : null));
const reportHidden = ref(false);
watch(report, () => { reportHidden.value = false; });
const REPORT_VERBS = { archive: 'archivé', restore: 'restauré', force_delete: 'supprimé' };
</script>

<template>
    <Head title="Stages" />

    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Ressources humaines"
            title="Stages"
            description="Chaque stagiaire accueilli à la clinique : sa filière (infirmier, sage-femme…), son école, son encadrant et les dates de son stage."
            :icon="GraduationCap"
            tone="violet"
        >
            <template #actions>
                <Button v-if="canBadge && listedCount > 0 && !archivedView" :as="Link" :href="badgesUrl" variant="outline" title="Badges des stagiaires affichés (toutes les pages)">
                    <IdCard class="h-4 w-4" />Badges<span class="rounded-full bg-muted px-1.5 text-xs tabular-nums text-muted-foreground">{{ listedCount }}</span>
                </Button>
                <Button v-if="can('contracts.create') && hasInternshipType" :as="Link" :href="hrUrl('/administration/contracts/create?type=stage')" variant="outline"><FilePlus2 class="h-4 w-4" />Stage d’un dossier existant</Button>
                <Button v-if="canCreateIntern && hasInternshipType" :as="Link" :href="hrUrl('/administration/employees/create?stagiaire=1')"><UserPlus class="h-4 w-4" />Nouveau stagiaire</Button>
            </template>
        </PageHeader>

        <div v-if="!hasInternshipType" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-300">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
            <p>Aucun type de contrat n’est marqué « Contrat de stage ». Cochez-le sur le type voulu dans <Link :href="hrUrl('/administration/settings')" class="font-semibold underline">Paramètres RH › Type de contrat</Link>.</p>
        </div>

        <!-- ADR-243 — les stagiaires cochés : leurs badges, ou un geste sur leur dossier. -->
        <div v-if="selected.length" class="flex flex-wrap items-center gap-3 rounded-xl border border-primary/40 bg-primary/5 px-4 py-2.5" role="status">
            <span class="text-sm font-medium text-foreground">{{ selected.length }} stagiaire{{ selected.length > 1 ? 's' : '' }} coché{{ selected.length > 1 ? 's' : '' }}</span>
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

        <section class="grid gap-3 sm:grid-cols-3 xl:grid-cols-5">
            <Link v-for="stat in STATUSES" :key="stat.key" :href="href({ status: stat.key, page: undefined })" preserve-scroll :aria-current="filters.status === stat.key ? 'true' : undefined">
                <HrStatCard :label="stat.label" :value="counts?.[stat.key] ?? 0" :hint="stat.hint" :icon="stat.icon" :tone="stat.tone" :active="filters.status === stat.key" />
            </Link>
        </section>

        <section class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <header class="space-y-3 border-b border-border p-4">
                <form class="flex flex-col gap-2 sm:flex-row sm:items-center" @submit.prevent="search">
                    <IconInput v-model="query" :icon="Search" type="search" placeholder="Nom, matricule, école, filière…" class="sm:max-w-sm" aria-label="Rechercher un stage" />
                    <Button type="submit" variant="outline">Rechercher</Button>
                </form>
                <nav class="flex flex-wrap gap-1.5" aria-label="Filières">
                    <Link
                        :href="href({ field: undefined, page: undefined })"
                        preserve-scroll
                        :class="cn('inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition', !filters.field ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-foreground hover:bg-accent')"
                    >Toutes les filières</Link>
                    <Link
                        v-for="field in fields"
                        :key="field.uuid"
                        :href="href({ field: field.uuid, page: undefined })"
                        preserve-scroll
                        :class="cn('inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition', filters.field === field.uuid ? 'border-violet-600 bg-violet-600 text-white' : 'border-border text-foreground hover:bg-accent')"
                    >{{ field.label }}<span :class="cn('rounded-full px-1.5 tabular-nums', filters.field === field.uuid ? 'bg-white/20' : 'bg-muted text-muted-foreground')">{{ field.count }}</span></Link>
                </nav>
                <p v-if="withoutField" class="flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-300"><TriangleAlert class="h-3.5 w-3.5" />{{ withoutField }} stage{{ withoutField > 1 ? 's' : '' }} sans filière (importé{{ withoutField > 1 ? 's' : '' }} par fichier) : complétez-le{{ withoutField > 1 ? 's' : '' }} depuis « Modifier ».</p>
            </header>

            <div v-if="internships.data.length">
                <table class="hidden w-full text-sm lg:table">
                    <thead class="bg-muted/50 text-xs font-semibold text-muted-foreground">
                        <tr>
                            <th v-if="canSelect" scope="col" class="w-10 ps-5 pe-0 py-3">
                                <Checkbox :model-value="allSelected" :disabled="dossiers.length === 0" aria-label="Cocher tous les stagiaires de la page" @update:model-value="toggleAll" />
                            </th>
                            <th class="px-5 py-3 text-start">Stagiaire</th>
                            <th class="px-4 py-3 text-start">Filière · école</th>
                            <th class="px-4 py-3 text-start">Encadrant</th>
                            <th class="px-4 py-3 text-start">Période</th>
                            <th class="px-4 py-3 text-start">État</th>
                            <th class="px-5 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="stage in internships.data" :key="stage.uuid" :class="cn('hover:bg-accent/40', stage.employee.archived && 'text-muted-foreground', selected.includes(stage.employee.uuid) && 'bg-primary/5')">
                            <td v-if="canSelect" class="w-10 ps-5 pe-0 py-3">
                                <Checkbox :model-value="selected.includes(stage.employee.uuid)" :aria-label="`Cocher ${stage.employee.name}`" @update:model-value="(checked) => toggleOne(stage.employee.uuid, checked)" />
                            </td>
                            <td class="px-5 py-3">
                                <Link :href="hrUrl(`/administration/employees/${stage.employee.uuid}`)" class="group flex items-center gap-3">
                                    <EmployeePhoto :src="stage.employee.photo_url" :name="stage.employee.name" size="md" :class="stage.employee.archived && 'opacity-60 grayscale'" />
                                    <span class="min-w-0">
                                        <strong :class="cn('block truncate group-hover:text-primary', stage.employee.archived ? 'text-muted-foreground' : 'text-foreground')">{{ stage.employee.name }}</strong>
                                        <span class="block truncate text-xs text-muted-foreground"><span class="font-mono">{{ stage.employee.employee_number }}</span><span v-if="stage.employee.department"> · {{ stage.employee.department }}</span></span>
                                        <span v-if="stage.employee.archived && stage.employee.deletion_blockers?.length === 0" class="block text-[11px] text-muted-foreground">N’a servi nulle part</span>
                                    </span>
                                </Link>
                            </td>
                            <td class="px-4 py-3">
                                <Badge v-if="stage.internship?.field" variant="secondary"><GraduationCap class="h-3.5 w-3.5" />{{ stage.internship.field }}</Badge>
                                <Badge v-else variant="warning">Filière à compléter</Badge>
                                <p class="mt-1 flex items-center gap-1 text-xs text-muted-foreground"><School class="h-3.5 w-3.5 shrink-0" /><span class="truncate">{{ stage.internship?.school || 'École non renseignée' }}<span v-if="stage.internship?.level"> · {{ stage.internship.level }}</span></span></p>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="stage.internship?.supervisor" class="flex items-center gap-2">
                                    <EmployeePhoto :src="stage.internship.supervisor.photo_url" :name="stage.internship.supervisor.name" size="xs" rounded />
                                    <span class="min-w-0"><span class="block truncate text-sm text-foreground">{{ stage.internship.supervisor.name }}</span><span class="block truncate text-xs text-muted-foreground">{{ stage.internship.supervisor.job_title || '' }}</span></span>
                                </span>
                                <span v-else class="flex items-center gap-1.5 text-xs text-muted-foreground"><UserRound class="h-3.5 w-3.5" />Non désigné</span>
                            </td>
                            <td class="px-4 py-3">
                                <p class="text-foreground">{{ formatPeriod(stage.starts_on, stage.ends_on) }}</p>
                                <p v-if="stage.state === 'current' && endingLabel(stage.ends_in_days)" class="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground"><CalendarClock class="h-3.5 w-3.5" />{{ endingLabel(stage.ends_in_days) }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <Badge v-if="stage.employee.archived" variant="outline"><Archive class="h-3 w-3" />Dossier archivé</Badge>
                                <Badge v-else :tone="STATE[stage.state]?.tone ?? 'neutral'">{{ STATE[stage.state]?.label ?? stage.state }}</Badge>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex justify-end gap-1.5">
                                    <Button :as="Link" :href="hrUrl(`/administration/employees/${stage.employee.uuid}`)" size="icon-xs" variant="outline" title="Dossier du stagiaire" aria-label="Dossier du stagiaire"><FolderOpen class="h-3.5 w-3.5" /></Button>
                                    <Button v-if="canBadge && !stage.archived && !stage.employee.archived" :as="Link" :href="badgeUrl(stage)" size="icon-xs" variant="outline" :title="`Badge de ${stage.employee.name}`" :aria-label="`Badge de ${stage.employee.name}`"><IdCard class="h-3.5 w-3.5" /></Button>
                                    <Button v-if="can('contracts.update') && !stage.archived && !stage.employee.archived" :as="Link" :href="hrUrl(`/administration/contracts/${stage.uuid}/edit`)" size="icon-xs" variant="outline" title="Modifier le stage" aria-label="Modifier le stage"><Pencil class="h-3.5 w-3.5" /></Button>
                                    <Button v-if="!stage.employee.archived && canArchive" type="button" size="icon-xs" variant="outline" class="hover:text-amber-700" :aria-label="`Archiver le dossier de ${stage.employee.name}`" title="Archiver le dossier (motif demandé, restaurable)" @click="askFor('archive', stage)"><Archive class="h-3.5 w-3.5" /></Button>
                                    <Button v-if="stage.employee.archived && canRestore" type="button" size="icon-xs" variant="outline" :aria-label="`Restaurer le dossier de ${stage.employee.name}`" title="Restaurer le dossier" @click="askFor('restore', stage)"><RotateCcw class="h-3.5 w-3.5" /></Button>
                                    <Button v-if="stage.employee.archived && (canForceDelete || canRestore)" type="button" size="icon-xs" variant="outline" class="text-destructive hover:text-destructive" :disabled="! deletion(stage).allowed" :aria-label="`Supprimer définitivement ${stage.employee.name}`" :title="deletion(stage).allowed ? 'Supprimer définitivement le dossier et son stage' : deletion(stage).reason" @click="askFor('force_delete', stage)"><Trash2 class="h-3.5 w-3.5" /></Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Téléphone : une carte par stage. -->
                <div class="divide-y divide-border lg:hidden">
                    <div v-for="stage in internships.data" :key="stage.uuid" class="flex gap-3 p-4 hover:bg-accent/40">
                        <Checkbox v-if="canSelect" class="mt-1" :model-value="selected.includes(stage.employee.uuid)" :aria-label="`Cocher ${stage.employee.name}`" @update:model-value="(checked) => toggleOne(stage.employee.uuid, checked)" />
                        <Link :href="hrUrl(`/administration/employees/${stage.employee.uuid}`)" class="flex min-w-0 flex-1 gap-3">
                        <EmployeePhoto :src="stage.employee.photo_url" :name="stage.employee.name" size="md" />
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex items-start justify-between gap-2">
                                <strong class="truncate text-sm text-foreground">{{ stage.employee.name }}</strong>
                                <Badge v-if="stage.employee.archived" variant="outline" class="shrink-0">Dossier archivé</Badge>
                                <Badge v-else :tone="STATE[stage.state]?.tone ?? 'neutral'" class="shrink-0">{{ STATE[stage.state]?.label ?? stage.state }}</Badge>
                            </div>
                            <p class="text-xs text-muted-foreground">{{ stage.internship?.field || 'Filière à compléter' }} · {{ stage.internship?.school || 'École non renseignée' }}</p>
                            <p class="text-xs text-muted-foreground">{{ formatPeriod(stage.starts_on, stage.ends_on) }}</p>
                        </div>
                        </Link>
                    </div>
                </div>
            </div>
            <EmptyState
                v-else
                :icon="GraduationCap"
                :title="archivedView ? 'Aucun dossier de stagiaire archivé' : filters.status === 'current' ? 'Aucun stagiaire en ce moment' : 'Aucun stage ici'"
                :description="archivedView ? 'Un dossier archivé ici se restaure, ou se supprime définitivement s’il n’a servi nulle part.' : 'Un stage est un contrat marqué « contrat de stage ». « Nouveau stagiaire » crée son dossier, puis son stage.'"
            />
            <HrPagination :paginator="internships" />
        </section>

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
                <p v-if="pending.mode === 'archive'" class="text-muted-foreground">Le dossier du stagiaire quitte la liste, garde tout son historique et se restaure depuis « Archivés ».</p>
                <p v-else-if="pending.mode === 'restore'" class="text-muted-foreground">Le dossier revient dans la liste, avec son stage, tel qu’il était.</p>
                <p v-else class="text-muted-foreground">Le dossier et son contrat de stage sont détruits : c’est irréversible. Seul un dossier archivé qui n’a servi nulle part (aucune présence, aucun document, aucun compte…) peut l’être ; ce qu’il était reste dans l’audit.</p>

                <ul v-if="pending.rows.length > 1" class="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-border bg-muted/30 px-3 py-2">
                    <li v-for="row in pending.rows" :key="row.uuid" class="flex items-center justify-between gap-2">
                        <span class="truncate font-medium text-foreground">{{ row.name }}</span>
                        <span class="font-mono text-xs text-muted-foreground">{{ row.employee_number }}</span>
                    </li>
                </ul>

                <FormField v-if="pending.mode === 'archive'" label="Motif d’archivage" required :error="actionForm.errors.reason">
                    <Textarea v-model="actionForm.reason" rows="3" placeholder="Ex. stage terminé, saisi à tort, doublon de STG-0002…" />
                </FormField>
                <p v-if="actionError && ! actionForm.errors.reason" class="rounded-lg bg-destructive/10 px-3 py-2 text-destructive">{{ actionError }}</p>
            </div>
        </ConfirmModal>
    </div>
</template>
