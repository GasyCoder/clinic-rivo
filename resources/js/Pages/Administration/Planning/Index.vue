<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Briefcase, Building2, CalendarDays, CalendarRange, ChevronLeft, ChevronRight, Clock, Download, Layers, List,
    Moon, Pencil, Plus, Printer, ShieldPlus, Sun, TriangleAlert, Users,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import Select from '@/Components/Shadcn/Select.vue';
import EmployeePhoto from '@/Components/Administration/EmployeePhoto.vue';
import PlanningShiftChip from '@/Components/Administration/PlanningShiftChip.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import {
    PERIOD_LABELS, addDays, addMonths, durationMinutes, formatDuration, groupByDay, monthGrid, rosterRows,
    shiftDay, shiftPeriod, shiftTime, weekDays,
} from '@/utilities/planningCalendar';

defineOptions({ layout: AppLayout });

/*
 * ADR-194 — deux plannings, un seul écran : « Planning du personnel » (le
 * service) et « Planning de garde ». En semaine, un tableau de garde — une
 * ligne par personne, une colonne par jour ; en mois, une grille de jours ;
 * en liste, jour après jour. Une case vide propose d'y planifier quelqu'un.
 *
 * Le planning reste factuel (ADR-066) : aucun chevauchement n'est bloqué,
 * aucune absence ni heure supplémentaire n'est calculée.
 */
const props = defineProps({
    shifts: Array,
    filters: Object,
    range: Object,
    counts: Object,
    summary: Object,
    truncated: Boolean,
    departments: Array,
    kinds: Array,
});
const { can } = usePermissions();

const visit = (overrides = {}) => {
    const params = { view: props.filters.view, kind: props.filters.kind, date: props.filters.date, department: props.filters.department || undefined, ...overrides };
    Object.keys(params).forEach((key) => (params[key] === undefined || params[key] === '') && delete params[key]);
    router.get(hrUrl('/administration/planning'), params, { preserveState: true, preserveScroll: true, replace: true });
};

// --- Onglets : les deux plannings ----------------------------------------------
const TABS = [
    { value: 'SHIFT', label: 'Planning du personnel', hint: 'Le service de chacun', icon: Briefcase, tone: 'sky' },
    { value: 'ON_CALL', label: 'Planning de garde', hint: 'Les gardes, de jour comme de nuit', icon: ShieldPlus, tone: 'violet' },
    { value: 'ALL', label: 'Les deux', hint: 'Service et gardes ensemble', icon: Layers, tone: 'slate' },
];
const createKind = computed(() => (props.filters.kind === 'ALL' ? 'SHIFT' : props.filters.kind));
const createLabel = computed(() => (createKind.value === 'ON_CALL' ? 'Nouvelle garde' : 'Nouveau créneau'));

// --- Période --------------------------------------------------------------------
const VIEWS = [
    { value: 'week', label: 'Semaine', icon: CalendarRange },
    { value: 'month', label: 'Mois', icon: CalendarDays },
    { value: 'list', label: 'Liste', icon: List },
];
const utc = (date) => new Date(`${date}T00:00:00Z`);
const fmt = (date, options) => new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', ...options }).format(utc(date));
const capitalize = (text) => text.charAt(0).toLocaleUpperCase('fr-FR') + text.slice(1);
const rangeLabel = computed(() => {
    if (props.filters.view === 'month') {
        return capitalize(fmt(props.filters.date, { month: 'long', year: 'numeric' }));
    }
    const { from, to } = props.range;
    const sameMonth = from.slice(0, 7) === to.slice(0, 7);
    return sameMonth
        ? `Semaine du ${fmt(from, { day: 'numeric' })} au ${fmt(to, { day: 'numeric', month: 'long', year: 'numeric' })}`
        : `Semaine du ${fmt(from, { day: 'numeric', month: 'short' })} au ${fmt(to, { day: 'numeric', month: 'short', year: 'numeric' })}`;
});
const step = (direction) => visit({ date: props.filters.view === 'month' ? addMonths(props.filters.date, direction) : addDays(props.filters.date, 7 * direction) });
const periodQuery = computed(() => new URLSearchParams({
    from: props.range.from,
    to: props.range.to,
    kind: props.filters.kind,
    ...(props.filters.department ? { department: props.filters.department } : {}),
}).toString());

const departmentOptions = computed(() => [{ value: '', label: 'Tous les départements' }, ...props.departments.map((item) => ({ value: item.uuid, label: item.label }))]);
const department = computed({
    get: () => props.filters.department ?? '',
    set: (value) => visit({ department: value || undefined }),
});

// --- Semaine : le tableau de garde ------------------------------------------------
const days = computed(() => weekDays(props.filters.date));
const rows = computed(() => rosterRows(props.shifts, days.value));
const perDay = computed(() => Object.fromEntries(days.value.map((day) => [day, props.shifts.filter((shift) => shiftDay(shift.starts_at) === day).length])));
const isToday = (day) => day === props.range.today;
const createHref = (day, employeeUuid) => hrUrl(`/administration/planning/create?${new URLSearchParams({ date: day, kind: createKind.value, ...(employeeUuid ? { employee: employeeUuid } : {}) }).toString()}`);

// --- Mois -------------------------------------------------------------------------
const grid = computed(() => monthGrid(props.filters.date));
const byDay = computed(() => groupByDay(props.shifts));
const openDay = ref(null);
const openDayShifts = computed(() => (openDay.value ? byDay.value.get(openDay.value) ?? [] : []));

// --- Liste ------------------------------------------------------------------------
const listDays = computed(() => [...byDay.value.entries()].filter(([day]) => day >= props.range.from && day <= props.range.to));

const PERIOD_ICONS = { DAY: Sun, NIGHT: Moon, LONG: Clock };
const nightCount = computed(() => props.shifts.filter((shift) => shiftPeriod(shift) !== 'DAY').length);
</script>

<template>
    <Head title="Planning RH" />

    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Organisation des équipes"
            title="Planning"
            description="Le planning du personnel et le planning de garde, en calendrier. Cliquez une case vide pour y planifier quelqu’un, un créneau pour le modifier."
            :icon="CalendarRange"
            tone="sky"
        >
            <template #actions>
                <Button v-if="can('planning.print')" :as="Link" :href="hrUrl(`/administration/planning/print?${periodQuery}`)" variant="outline"><Printer class="h-4 w-4" />Imprimer</Button>
                <Button v-if="can('planning.export')" as="a" :href="hrUrl(`/administration/planning/export?${periodQuery}`)" variant="outline"><Download class="h-4 w-4" />Exporter</Button>
                <Button v-if="can('planning.create')" :as="Link" :href="hrUrl(`/administration/planning/create?kind=${createKind}&date=${filters.date}`)"><Plus class="h-4 w-4" />{{ createLabel }}</Button>
            </template>
        </PageHeader>

        <!-- Les deux plannings -->
        <nav class="grid gap-3 sm:grid-cols-3" aria-label="Plannings">
            <button
                v-for="tab in TABS"
                :key="tab.value"
                type="button"
                :aria-current="filters.kind === tab.value ? 'page' : undefined"
                :class="cn('flex items-center gap-3 rounded-xl border bg-card p-4 text-start shadow-sm transition',
                    filters.kind === tab.value ? 'border-primary ring-1 ring-ring/25' : 'border-border hover:border-primary/30')"
                @click="visit({ kind: tab.value })"
            >
                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg',
                    tab.tone === 'violet' ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/40 dark:text-violet-300'
                        : tab.tone === 'sky' ? 'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300' : 'bg-muted text-muted-foreground')"
                >
                    <component :is="tab.icon" class="h-5 w-5" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-foreground">{{ tab.label }}</span>
                    <span class="block truncate text-xs text-muted-foreground">{{ tab.hint }}</span>
                </span>
                <span class="text-xl font-bold tabular-nums text-foreground">{{ counts?.[tab.value] ?? 0 }}</span>
            </button>
        </nav>

        <section class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <!-- Barre : période, vue, département -->
            <header class="flex flex-col gap-3 border-b border-border p-4 xl:flex-row xl:items-center xl:justify-between">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center gap-1">
                        <Button size="icon-xs" variant="outline" aria-label="Période précédente" @click="step(-1)"><ChevronLeft class="h-4 w-4" /></Button>
                        <Button size="xs" variant="outline" @click="visit({ date: range.today })">Aujourd’hui</Button>
                        <Button size="icon-xs" variant="outline" aria-label="Période suivante" @click="step(1)"><ChevronRight class="h-4 w-4" /></Button>
                    </div>
                    <h2 class="text-base font-bold text-foreground">{{ rangeLabel }}</h2>
                    <Badge variant="outline"><Users class="h-3.5 w-3.5" />{{ summary.employees }} personne{{ summary.employees > 1 ? 's' : '' }}</Badge>
                    <Badge v-if="nightCount" variant="outline"><Moon class="h-3.5 w-3.5" />{{ nightCount }} de nuit ou 24 h</Badge>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="inline-flex rounded-lg bg-muted p-0.5" role="tablist" aria-label="Affichage">
                        <button
                            v-for="view in VIEWS"
                            :key="view.value"
                            type="button"
                            role="tab"
                            :aria-selected="filters.view === view.value"
                            :class="cn('inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition', filters.view === view.value ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')"
                            @click="visit({ view: view.value })"
                        >
                            <component :is="view.icon" class="h-3.5 w-3.5" />{{ view.label }}
                        </button>
                    </div>
                    <Select v-model="department" :options="departmentOptions" :icon="Building2" placeholder="Tous les départements" class="w-56 min-w-0" aria-label="Département" />
                </div>
            </header>

            <!-- Légende : la couleur ne porte jamais seule le sens -->
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-b border-border bg-muted/30 px-4 py-2 text-[11px] text-muted-foreground">
                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm border border-sky-300 bg-sky-100 dark:border-sky-800 dark:bg-sky-950" />Service</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm border border-violet-300 bg-violet-100 dark:border-violet-800 dark:bg-violet-950" />Garde</span>
                <span class="inline-flex items-center gap-1"><Sun class="h-3 w-3" />{{ PERIOD_LABELS.DAY }}</span>
                <span class="inline-flex items-center gap-1"><Moon class="h-3 w-3" />{{ PERIOD_LABELS.NIGHT }}</span>
                <span class="inline-flex items-center gap-1"><Clock class="h-3 w-3" />{{ PERIOD_LABELS.LONG }}</span>
            </div>

            <p v-if="truncated" class="flex items-center gap-2 border-b border-border bg-amber-50 px-4 py-2 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                <TriangleAlert class="h-3.5 w-3.5" />Période très chargée : seuls les 2 000 premiers créneaux sont affichés. Filtrez par département.
            </p>

            <!-- Semaine : le tableau de garde -->
            <div v-if="filters.view === 'week'" class="overflow-x-auto">
                <table v-if="rows.length" class="w-full min-w-[980px] table-fixed border-separate border-spacing-0 text-sm">
                    <thead>
                        <tr>
                            <th class="sticky left-0 z-10 w-56 border-b border-border bg-card px-3 py-2.5 text-start text-xs font-semibold text-muted-foreground">Personne</th>
                            <th v-for="day in days" :key="day" :class="cn('border-b border-s border-border px-2 py-2 text-center', isToday(day) ? 'bg-primary/5' : 'bg-card')">
                                <span :class="cn('block text-[11px] font-semibold uppercase tracking-wide', isToday(day) ? 'text-primary' : 'text-muted-foreground')">{{ fmt(day, { weekday: 'short' }) }}</span>
                                <span :class="cn('mx-auto mt-0.5 grid h-7 w-7 place-items-center rounded-full text-sm font-bold', isToday(day) ? 'bg-primary text-primary-foreground' : 'text-foreground')">{{ fmt(day, { day: 'numeric' }) }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="(row, index) in rows" :key="row.employee.uuid">
                            <tr v-if="index === 0 || rows[index - 1].department !== row.department">
                                <td colspan="8" class="border-b border-border bg-muted/50 px-3 py-1.5 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                                    <span class="inline-flex items-center gap-1.5"><Building2 class="h-3.5 w-3.5" />{{ row.department || 'Sans département' }}</span>
                                </td>
                            </tr>
                            <tr class="group/row">
                                <th class="sticky left-0 z-10 border-b border-border bg-card px-3 py-2 text-start font-normal">
                                    <span class="flex items-center gap-2.5">
                                        <EmployeePhoto :src="row.employee.photo_url" :name="row.employee.name" size="sm" />
                                        <span class="min-w-0">
                                            <Link :href="hrUrl(`/administration/employees/${row.employee.uuid}`)" class="block truncate text-sm font-semibold text-foreground hover:text-primary">{{ row.employee.name }}</Link>
                                            <span class="block truncate text-xs text-muted-foreground">{{ row.employee.job_title || row.employee.employee_number }}</span>
                                        </span>
                                    </span>
                                </th>
                                <td v-for="day in days" :key="day" :class="cn('group/cell h-16 border-b border-s border-border p-1 align-top', isToday(day) && 'bg-primary/[0.03]')">
                                    <div class="space-y-1">
                                        <PlanningShiftChip v-for="shift in row.cells[day]" :key="shift.uuid" :shift="shift" :editable="can('planning.update')" />
                                        <Link
                                            v-if="can('planning.create')"
                                            :href="createHref(day, row.employee.uuid)"
                                            :class="cn('flex h-6 w-full items-center justify-center rounded-md border border-dashed border-border text-muted-foreground transition hover:border-primary hover:text-primary',
                                                row.cells[day].length ? 'opacity-0 group-hover/cell:opacity-100 focus-visible:opacity-100' : 'opacity-0 group-hover/row:opacity-60 hover:!opacity-100 focus-visible:opacity-100')"
                                            :aria-label="`Planifier ${row.employee.name} le ${fmt(day, { weekday: 'long', day: 'numeric', month: 'long' })}`"
                                        ><Plus class="h-3.5 w-3.5" /></Link>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th class="sticky left-0 z-10 bg-muted/50 px-3 py-2 text-start text-xs font-semibold text-muted-foreground">{{ filters.kind === 'ON_CALL' ? 'De garde ce jour' : 'Créneaux du jour' }}</th>
                            <td v-for="day in days" :key="day" class="border-s border-border bg-muted/50 px-2 py-2 text-center text-xs font-bold tabular-nums text-foreground">{{ perDay[day] }}</td>
                        </tr>
                    </tfoot>
                </table>
                <EmptyState
                    v-else
                    :icon="filters.kind === 'ON_CALL' ? ShieldPlus : CalendarRange"
                    :title="filters.kind === 'ON_CALL' ? 'Aucune garde cette semaine' : 'Aucun créneau cette semaine'"
                    description="Changez de semaine, de département, ou planifiez quelqu’un."
                >
                </EmptyState>
            </div>

            <!-- Mois -->
            <div v-else-if="filters.view === 'month'" class="p-2 sm:p-3">
                <div class="grid grid-cols-7 overflow-hidden rounded-lg border border-border">
                    <div v-for="day in grid[0]" :key="`h-${day.date}`" class="border-b border-border bg-muted/50 px-2 py-1.5 text-center text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{{ fmt(day.date, { weekday: 'short' }) }}</div>
                    <template v-for="week in grid" :key="week[0].date">
                        <div
                            v-for="day in week"
                            :key="day.date"
                            :class="cn('group/cell min-h-[5.5rem] border-b border-s border-border p-1 sm:min-h-[7.5rem] sm:p-1.5 [&:nth-child(7n+1)]:border-s-0',
                                !day.inMonth && 'bg-muted/30', isToday(day.date) && 'bg-primary/[0.04]')"
                        >
                            <div class="mb-1 flex items-center justify-between gap-1">
                                <button
                                    type="button"
                                    :class="cn('grid h-6 w-6 place-items-center rounded-full text-xs font-bold transition hover:bg-accent',
                                        isToday(day.date) ? 'bg-primary text-primary-foreground hover:bg-primary' : day.inMonth ? 'text-foreground' : 'text-muted-foreground')"
                                    :aria-label="`Voir la semaine du ${fmt(day.date, { day: 'numeric', month: 'long' })}`"
                                    @click="visit({ view: 'week', date: day.date })"
                                >{{ fmt(day.date, { day: 'numeric' }) }}</button>
                                <Link
                                    v-if="can('planning.create')"
                                    :href="createHref(day.date)"
                                    class="grid h-5 w-5 place-items-center rounded text-muted-foreground opacity-0 transition hover:bg-accent hover:text-primary focus-visible:opacity-100 group-hover/cell:opacity-100"
                                    :aria-label="`Planifier le ${fmt(day.date, { day: 'numeric', month: 'long' })}`"
                                ><Plus class="h-3.5 w-3.5" /></Link>
                            </div>
                            <!-- Téléphone : un compte ; plus large : les créneaux. -->
                            <button
                                v-if="(byDay.get(day.date) ?? []).length"
                                type="button"
                                class="w-full rounded-md bg-primary/10 px-1 py-0.5 text-center text-[11px] font-bold text-primary sm:hidden"
                                @click="openDay = day.date"
                            >{{ (byDay.get(day.date) ?? []).length }}</button>
                            <div class="hidden space-y-1 sm:block">
                                <PlanningShiftChip v-for="shift in (byDay.get(day.date) ?? []).slice(0, 3)" :key="shift.uuid" :shift="shift" show-name :editable="can('planning.update')" />
                                <button
                                    v-if="(byDay.get(day.date) ?? []).length > 3"
                                    type="button"
                                    class="w-full rounded-md px-1.5 py-0.5 text-start text-[11px] font-semibold text-muted-foreground hover:bg-accent hover:text-foreground"
                                    @click="openDay = day.date"
                                >+ {{ (byDay.get(day.date) ?? []).length - 3 }} autre{{ (byDay.get(day.date) ?? []).length - 3 > 1 ? 's' : '' }}</button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Liste -->
            <div v-else>
                <div v-if="listDays.length" class="divide-y divide-border">
                    <section v-for="[day, dayShifts] in listDays" :key="day">
                        <div :class="cn('flex items-center gap-2 px-5 py-2', isToday(day) ? 'bg-primary/5' : 'bg-muted/40')">
                            <CalendarDays class="h-3.5 w-3.5 text-muted-foreground" />
                            <h3 class="text-xs font-bold capitalize text-foreground">{{ fmt(day, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) }}</h3>
                            <Badge variant="outline" class="ms-auto">{{ dayShifts.length }}</Badge>
                        </div>
                        <article v-for="shift in dayShifts" :key="shift.uuid" class="grid gap-3 border-t border-border px-5 py-3 first:border-t-0 md:grid-cols-[170px_minmax(0,1fr)_minmax(0,1fr)_auto] md:items-center">
                            <div class="flex items-center gap-2">
                                <component :is="PERIOD_ICONS[shiftPeriod(shift)]" class="h-4 w-4 text-muted-foreground" />
                                <div>
                                    <p class="text-sm font-bold tabular-nums text-foreground">{{ shiftTime(shift.starts_at) }} → {{ shiftTime(shift.ends_at) }}</p>
                                    <p class="text-xs text-muted-foreground">{{ PERIOD_LABELS[shiftPeriod(shift)] }} · {{ formatDuration(durationMinutes(shift)) }}</p>
                                </div>
                            </div>
                            <div class="flex min-w-0 items-center gap-2.5">
                                <EmployeePhoto :src="shift.employee.photo_url" :name="shift.employee.name" size="sm" />
                                <div class="min-w-0"><p class="truncate text-sm font-semibold text-foreground">{{ shift.employee.name }}</p><p class="truncate text-xs text-muted-foreground">{{ shift.employee.job_title || shift.employee.employee_number }}</p></div>
                            </div>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <Badge :variant="shift.kind === 'ON_CALL' ? 'secondary' : 'outline'">{{ shift.kind_label }}</Badge>
                                    <span class="truncate text-sm text-foreground">{{ shift.title || 'Sans objet' }}</span>
                                </div>
                                <p class="mt-0.5 truncate text-xs text-muted-foreground">{{ shift.department || shift.employee.department || 'Département non renseigné' }}<span v-if="shift.observation"> · {{ shift.observation }}</span></p>
                            </div>
                            <Button v-if="can('planning.update')" :as="Link" :href="hrUrl(`/administration/planning/${shift.uuid}/edit`)" size="icon-xs" variant="outline" title="Modifier" aria-label="Modifier"><Pencil class="h-3.5 w-3.5" /></Button>
                        </article>
                    </section>
                </div>
                <EmptyState v-else :icon="List" title="Rien sur cette période" description="Changez de semaine, de planning ou de département." />
            </div>
        </section>
    </div>

    <!-- Un jour du mois, en entier -->
    <Dialog
        :open="Boolean(openDay)"
        :title="openDay ? capitalize(fmt(openDay, { weekday: 'long', day: 'numeric', month: 'long' })) : ''"
        :description="`${openDayShifts.length} créneau${openDayShifts.length > 1 ? 'x' : ''}`"
        @update:open="(value) => { if (!value) openDay = null; }"
    >
        <div class="space-y-2">
            <div v-for="shift in openDayShifts" :key="shift.uuid" class="flex items-center gap-3">
                <EmployeePhoto :src="shift.employee.photo_url" :name="shift.employee.name" size="sm" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-foreground">{{ shift.employee.name }}</p>
                    <PlanningShiftChip :shift="shift" :editable="can('planning.update')" class="mt-0.5 w-fit" />
                </div>
            </div>
        </div>
        <template #footer>
            <Button type="button" variant="outline" @click="openDay = null">Fermer</Button>
            <Button v-if="can('planning.create')" :as="Link" :href="createHref(openDay ?? filters.date)"><Plus class="h-4 w-4" />Planifier ce jour</Button>
        </template>
    </Dialog>
</template>
