<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Archive,
    CircleCheck,
    CirclePause,
    Download,
    Eye,
    FileSpreadsheet,
    Mail,
    Pencil,
    Phone,
    RotateCcw,
    Search,
    Upload,
    UserPlus,
    Users,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Shadcn/Avatar.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import ExplorerTile from '@/Components/UI/ExplorerTile.vue';
import ExplorerView from '@/Components/UI/ExplorerView.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import HrPagination from '../Partials/HrPagination.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
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

const restore = (employee) => router.post(hrUrl(`/administration/employees/${employee.uuid}/restore`), {}, { preserveScroll: true });

const initials = (employee) => `${employee.first_name?.[0] ?? ''}${employee.last_name?.[0] ?? ''}`.toUpperCase() || 'RH';

/** « 2024-03-01 » → « 01/03/2024 », sans passer par un fuseau horaire. */
const frenchDate = (iso) => (iso ? iso.split('-').reverse().join('/') : null);

/* ------------------------------------------------------------------ */
/* Compteurs : chacun est un filtre                                    */
/* ------------------------------------------------------------------ */

const total = computed(() => Number(props.summary?.active ?? 0) + Number(props.summary?.inactive ?? 0) + Number(props.summary?.archived ?? 0));

const STATUS_CARDS = [
    { value: 'all', label: 'Tous les dossiers', hint: 'Actifs, inactifs et archivés', icon: Users, tone: 'bg-primary/10 text-primary', bar: 'bg-primary' },
    { value: 'active', label: 'Actifs', hint: 'En poste', icon: CircleCheck, tone: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300', bar: 'bg-emerald-500' },
    { value: 'inactive', label: 'Inactifs', hint: 'Dossier gardé, hors poste', icon: CirclePause, tone: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300', bar: 'bg-amber-500' },
    { value: 'archived', label: 'Archivés', hint: 'Restaurables', icon: Archive, tone: 'bg-muted text-muted-foreground', bar: 'bg-muted-foreground/60' },
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

const tileTone = (employee) => (employee.archived ? 'slate' : (employee.active ? 'emerald' : 'amber'));
const tileBadge = (employee) => (employee.archived ? 'Archivé' : (employee.active ? null : 'Inactif'));
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
                <Button v-if="can('employees.create')" :as="Link" :href="hrUrl('/administration/employees/create')">
                    <UserPlus class="h-4 w-4" />Nouvel employé
                </Button>
            </template>
        </PageHeader>

        <!-- Les compteurs sont les filtres : un clic affiche ce qu'ils comptent. -->
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" role="group" aria-label="Filtrer par état">
            <button
                v-for="card in cards"
                :key="card.value"
                type="button"
                :aria-pressed="statusFilter === card.value"
                :class="cn(
                    'group relative flex flex-col gap-2.5 rounded-xl border bg-card p-3.5 text-start shadow-sm transition-all',
                    'hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                    statusFilter === card.value ? 'border-primary bg-primary/5 ring-1 ring-primary/30' : 'border-border hover:border-primary/40',
                )"
                @click="setStatus(card.value)"
            >
                <span class="flex items-center gap-3">
                    <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-lg', card.tone]">
                        <component :is="card.icon" class="h-4.5 w-4.5" aria-hidden="true" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-xl font-bold leading-none tabular-nums text-foreground">{{ card.count }}</span>
                        <span class="mt-1 block text-xs font-medium leading-tight text-muted-foreground">{{ card.label }}</span>
                    </span>
                    <CircleCheck v-if="statusFilter === card.value" class="absolute end-3 top-3 h-4 w-4 text-primary" aria-hidden="true" />
                </span>
                <!-- La part du total, pour lire l'effectif d'un coup d'œil. -->
                <span v-if="card.value !== 'all'" class="flex items-center gap-2">
                    <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                        <span :class="['block h-full rounded-full transition-all', card.bar]" :style="{ width: `${card.share}%` }" />
                    </span>
                    <span class="text-[11px] tabular-nums text-muted-foreground">{{ card.share }} %</span>
                </span>
                <span v-else class="text-[11px] text-muted-foreground">{{ card.hint }}</span>
            </button>
        </div>

        <ExplorerView
            storage-key="hr-employees"
            :count="count"
            count-label="employé"
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
                <ExplorerTile
                    v-for="employee in employees.data"
                    :key="employee.uuid"
                    :href="hrUrl(`/administration/employees/${employee.uuid}`)"
                    icon="user"
                    :tone="tileTone(employee)"
                    :badge="tileBadge(employee)"
                    :title="employee.name"
                    :subtitle="employee.job_title || 'Fonction non renseignée'"
                    :highlight="employee.department"
                    :meta="employee.employee_number"
                    :muted="employee.archived"
                >
                    <template #actions>
                        <Button v-if="!employee.archived && can('employees.update')" :as="Link" :href="hrUrl(`/administration/employees/${employee.uuid}/edit`)" variant="outline" size="icon" :aria-label="`Modifier ${employee.name}`" title="Modifier"><Pencil class="h-4 w-4" /></Button>
                        <Button v-if="employee.archived && can('employees.restore')" type="button" variant="outline" size="icon" :aria-label="`Restaurer ${employee.name}`" title="Restaurer" @click="restore(employee)"><RotateCcw class="h-4 w-4" /></Button>
                    </template>
                </ExplorerTile>
            </template>

            <template #list>
                <table class="w-full min-w-[900px] text-sm">
                    <thead>
                        <tr class="border-b border-border bg-muted/40 text-left text-xs font-semibold text-muted-foreground">
                            <th scope="col" class="px-5 py-3">Employé</th>
                            <th scope="col" class="px-4 py-3">Fonction · département</th>
                            <th scope="col" class="px-4 py-3">Contact</th>
                            <th scope="col" class="px-4 py-3">Entrée</th>
                            <th scope="col" class="px-4 py-3">État</th>
                            <th scope="col" class="px-5 py-3 text-end"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="employee in employees.data"
                            :key="employee.uuid"
                            :class="cn('transition-colors hover:bg-muted/40', employee.archived && 'text-muted-foreground')"
                        >
                            <td class="px-5 py-3">
                                <Link :href="hrUrl(`/administration/employees/${employee.uuid}`)" class="group flex items-center gap-3">
                                    <Avatar size="sm" :variant="employee.archived ? '' : 'primary-pale'" :text="initials(employee)" />
                                    <span class="min-w-0">
                                        <span :class="cn('block truncate font-semibold group-hover:text-primary', employee.archived ? 'text-muted-foreground' : 'text-foreground')">{{ employee.name }}</span>
                                        <span class="block font-mono text-xs text-muted-foreground">{{ employee.employee_number }}</span>
                                    </span>
                                </Link>
                            </td>
                            <td class="px-4 py-3">
                                <p class="text-foreground">{{ employee.job_title || '—' }}</p>
                                <p class="text-xs text-muted-foreground">{{ employee.department || 'Département non renseigné' }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <a v-if="employee.phone" :href="`tel:${employee.phone}`" class="flex items-center gap-1.5 text-foreground hover:text-primary">
                                    <Phone class="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />{{ employee.phone }}
                                </a>
                                <a v-if="employee.email" :href="`mailto:${employee.email}`" class="mt-0.5 flex max-w-56 items-center gap-1.5 text-xs text-muted-foreground hover:text-primary">
                                    <Mail class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span class="truncate">{{ employee.email }}</span>
                                </a>
                                <span v-if="! employee.phone && ! employee.email" class="text-muted-foreground">—</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 tabular-nums text-muted-foreground">{{ frenchDate(employee.hire_date) || '—' }}</td>
                            <td class="px-4 py-3">
                                <Badge v-if="employee.archived" variant="outline"><Archive class="h-3 w-3" />Archivé</Badge>
                                <Badge v-else-if="employee.active" variant="success"><CircleCheck class="h-3 w-3" />Actif</Badge>
                                <Badge v-else variant="warning"><CirclePause class="h-3 w-3" />Inactif</Badge>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex justify-end gap-1">
                                    <Button :as="Link" :href="hrUrl(`/administration/employees/${employee.uuid}`)" variant="ghost" size="icon" :aria-label="`Voir le dossier de ${employee.name}`" title="Voir le dossier"><Eye class="h-4 w-4" /></Button>
                                    <Button v-if="!employee.archived && can('employees.update')" :as="Link" :href="hrUrl(`/administration/employees/${employee.uuid}/edit`)" variant="ghost" size="icon" :aria-label="`Modifier ${employee.name}`" title="Modifier"><Pencil class="h-4 w-4" /></Button>
                                    <Button v-if="employee.archived && can('employees.restore')" type="button" variant="ghost" size="icon" :aria-label="`Restaurer ${employee.name}`" title="Restaurer" @click="restore(employee)"><RotateCcw class="h-4 w-4" /></Button>
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
    </div>
</template>
