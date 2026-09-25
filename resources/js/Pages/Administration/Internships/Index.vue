<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CalendarClock, CalendarPlus, CircleCheck, FilePlus2, FolderOpen, GraduationCap, History, Layers,
    Pencil, School, Search, TriangleAlert, UserPlus, UserRound,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import EmployeePhoto from '@/Components/Administration/EmployeePhoto.vue';
import HrPagination from '../Partials/HrPagination.vue';
import HrStatCard from '../Partials/HrStatCard.vue';
import { usePermissions } from '@/composables/usePermissions';
import { endingLabel, formatPeriod } from '@/utilities/hr';
import { cn } from '@/lib/cn';

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
];
const STATE = {
    current: { label: 'En cours', tone: 'success' },
    future: { label: 'À venir', tone: 'info' },
    ended: { label: 'Terminé', tone: 'neutral' },
    archived: { label: 'Archivé', tone: 'neutral' },
};
const canCreateIntern = computed(() => can('employees.create') && can('contracts.create'));
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
                <Button v-if="can('contracts.create') && hasInternshipType" :as="Link" :href="hrUrl('/administration/contracts/create?type=stage')" variant="outline"><FilePlus2 class="h-4 w-4" />Stage d’un dossier existant</Button>
                <Button v-if="canCreateIntern && hasInternshipType" :as="Link" :href="hrUrl('/administration/employees/create?stagiaire=1')"><UserPlus class="h-4 w-4" />Nouveau stagiaire</Button>
            </template>
        </PageHeader>

        <div v-if="!hasInternshipType" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-300">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
            <p>Aucun type de contrat n’est marqué « Contrat de stage ». Cochez-le sur le type voulu dans <Link :href="hrUrl('/administration/settings')" class="font-semibold underline">Paramètres RH › Type de contrat</Link>.</p>
        </div>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
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
                            <th class="px-5 py-3 text-start">Stagiaire</th>
                            <th class="px-4 py-3 text-start">Filière · école</th>
                            <th class="px-4 py-3 text-start">Encadrant</th>
                            <th class="px-4 py-3 text-start">Période</th>
                            <th class="px-4 py-3 text-start">État</th>
                            <th class="px-5 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="stage in internships.data" :key="stage.uuid" class="hover:bg-accent/40">
                            <td class="px-5 py-3">
                                <Link :href="hrUrl(`/administration/employees/${stage.employee.uuid}`)" class="group flex items-center gap-3">
                                    <EmployeePhoto :src="stage.employee.photo_url" :name="stage.employee.name" size="md" />
                                    <span class="min-w-0">
                                        <strong class="block truncate text-foreground group-hover:text-primary">{{ stage.employee.name }}</strong>
                                        <span class="block truncate text-xs text-muted-foreground"><span class="font-mono">{{ stage.employee.employee_number }}</span><span v-if="stage.employee.department"> · {{ stage.employee.department }}</span></span>
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
                            <td class="px-4 py-3"><Badge :tone="STATE[stage.state]?.tone ?? 'neutral'">{{ STATE[stage.state]?.label ?? stage.state }}</Badge></td>
                            <td class="px-5 py-3">
                                <div class="flex justify-end gap-1.5">
                                    <Button :as="Link" :href="hrUrl(`/administration/employees/${stage.employee.uuid}`)" size="icon-xs" variant="outline" title="Dossier du stagiaire" aria-label="Dossier du stagiaire"><FolderOpen class="h-3.5 w-3.5" /></Button>
                                    <Button v-if="can('contracts.update') && !stage.archived" :as="Link" :href="hrUrl(`/administration/contracts/${stage.uuid}/edit`)" size="icon-xs" variant="outline" title="Modifier le stage" aria-label="Modifier le stage"><Pencil class="h-3.5 w-3.5" /></Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Téléphone : une carte par stage. -->
                <div class="divide-y divide-border lg:hidden">
                    <Link v-for="stage in internships.data" :key="stage.uuid" :href="hrUrl(`/administration/employees/${stage.employee.uuid}`)" class="flex gap-3 p-4 hover:bg-accent/40">
                        <EmployeePhoto :src="stage.employee.photo_url" :name="stage.employee.name" size="md" />
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex items-start justify-between gap-2">
                                <strong class="truncate text-sm text-foreground">{{ stage.employee.name }}</strong>
                                <Badge :tone="STATE[stage.state]?.tone ?? 'neutral'" class="shrink-0">{{ STATE[stage.state]?.label ?? stage.state }}</Badge>
                            </div>
                            <p class="text-xs text-muted-foreground">{{ stage.internship?.field || 'Filière à compléter' }} · {{ stage.internship?.school || 'École non renseignée' }}</p>
                            <p class="text-xs text-muted-foreground">{{ formatPeriod(stage.starts_on, stage.ends_on) }}</p>
                        </div>
                    </Link>
                </div>
            </div>
            <EmptyState
                v-else
                :icon="GraduationCap"
                :title="filters.status === 'current' ? 'Aucun stagiaire en ce moment' : 'Aucun stage ici'"
                description="Un stage est un contrat marqué « contrat de stage ». « Nouveau stagiaire » crée son dossier, puis son stage."
            />
            <HrPagination :paginator="internships" />
        </section>
    </div>
</template>
