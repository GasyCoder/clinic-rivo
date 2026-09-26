<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Activity, CalendarClock, Clock, Download, History, LogIn, LogOut, Palmtree, Pencil, Plus, Printer, Search, TimerOff, UserCheck, Users,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Select from '@/Components/Shadcn/Select.vue';
import HrPagination from '../Partials/HrPagination.vue';
import HrStatCard from '../Partials/HrStatCard.vue';
import EmployeePhoto from '@/Components/Administration/EmployeePhoto.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import { cn } from '@/lib/cn';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime, formatTime, toDatetimeLocalInput } from '@/utilities/date';
import { formatMinutes, initials } from '@/utilities/hr';

defineOptions({ layout: AppLayout });

/*
 * ADR-066 — les sessions d'entrée et de sortie réellement enregistrées, sans
 * calcul de retard ni d'heures supplémentaires.
 *
 * « Sessions ouvertes » montre toutes les sorties manquantes, quelle que soit
 * leur date : le chiffre de l'accueil RH les compte toutes, et une sortie
 * oubliée le mois dernier ne se retrouvait pas dans la liste du mois.
 * Une session ouverte se clôt d'un geste — la même correction que
 * « Modifier », avec l'heure de sortie d'aujourd'hui.
 */
const props = defineProps({
    records: Object, employees: [Array, Object], filters: Object, summary: Object,
    /** ADR-198 — `today` (par défaut) ou `history`. */
    view: { type: String, default: 'history' },
    /** Les présences du jour : `{ rows, counts }`, seulement pour « Aujourd'hui ». */
    board: { type: Object, default: null },
});
const { can } = usePermissions();

const from = ref(props.filters.from);
const to = ref(props.filters.to);
const employee = ref(props.filters.employee ?? '');
const openOnly = computed(() => Boolean(props.filters.open));

const employeeOptions = computed(() => [
    { value: '', label: 'Tous les employés' },
    ...props.employees.map((item) => ({ value: item.uuid, label: `${item.name} (${item.employee_number})` })),
]);
const queryString = computed(() => new URLSearchParams({
    from: from.value, to: to.value, ...(employee.value ? { employee: employee.value } : {}),
}).toString());

const visit = (params) => router.get(hrUrl('/administration/attendance'), params, { preserveState: true, preserveScroll: true, replace: true });
const filter = () => visit({ from: from.value, to: to.value, employee: employee.value || undefined });

// --- Enregistrer la sortie ----------------------------------------------------
const closing = ref(null);
const closeForm = useForm({ employee_uuid: '', started_at: '', ended_at: '', observation: null });
const openClose = (record) => {
    closeForm.clearErrors();
    closing.value = record;
};
const closeNow = () => {
    const record = closing.value;
    closeForm.employee_uuid = record.employee.uuid;
    closeForm.started_at = toDatetimeLocalInput(record.started_at);
    closeForm.ended_at = toDatetimeLocalInput(new Date());
    closeForm.observation = record.observation;
    closeForm.put(hrUrl(`/administration/attendance/${record.uuid}`), {
        preserveScroll: true,
        onSuccess: () => { closing.value = null; },
    });
};
const closeErrors = computed(() => Object.values(closeForm.errors));

// --- Aujourd'hui (ADR-198) ----------------------------------------------------
// Ce qui est enregistré, pas un jugement : aucun retard ni absence n'est calculé.
const STATES = {
    PRESENT: { label: 'Présent', hint: 'Entrée pointée, pas de sortie', icon: UserCheck, tone: 'emerald', badge: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-200' },
    EXPECTED: { label: 'Attendus au planning', hint: 'Créneau aujourd’hui, pas encore pointé', icon: CalendarClock, tone: 'amber', badge: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-200' },
    LEFT: { label: 'Partis', hint: 'Sortie pointée aujourd’hui', icon: LogOut, tone: 'slate', badge: 'bg-muted text-muted-foreground' },
    ON_LEAVE: { label: 'En congé', hint: 'Congé accepté ce jour', icon: Palmtree, tone: 'sky', badge: 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-200' },
    OFF: { label: 'Sans créneau', hint: 'Ni planning, ni pointage', icon: Users, tone: 'violet', badge: 'bg-muted text-muted-foreground' },
};
const STATE_LABELS = { PRESENT: 'Présent', EXPECTED: 'Attendu', LEFT: 'Parti', ON_LEAVE: 'En congé', OFF: 'Sans créneau' };
const boardState = ref('ALL');
const boardQuery = ref('');
const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const boardRows = computed(() => {
    const words = normalize(boardQuery.value).split(/\s+/).filter(Boolean);

    return (props.board?.rows ?? [])
        .filter((row) => boardState.value === 'ALL' || row.state === boardState.value)
        .filter((row) => words.every((word) => normalize(`${row.employee.name} ${row.employee.employee_number} ${row.employee.job_title ?? ''} ${row.employee.department ?? ''}`).includes(word)));
});
// Vue n'expose pas URLSearchParams au gabarit : l'adresse se compose ici.
const historyHref = (row) => hrUrl(`/administration/attendance?${new URLSearchParams({ employee: row.employee.uuid })}`);
const toggleState = (state) => { boardState.value = boardState.value === state ? 'ALL' : state; };
const hhmm = (iso) => (iso ? formatTime(iso) : '');
const frenchDate = (iso) => (iso ? iso.split('-').reverse().join('/') : '');

// Entrée en un clic, maintenant ; une personne en congé demande d'abord confirmation.
const entryForm = useForm({ employee_uuid: '', started_at: '', ended_at: null, observation: null });
const confirmingEntry = ref(null);
const clockIn = (row) => {
    if (row.state === 'ON_LEAVE' && confirmingEntry.value !== row) {
        confirmingEntry.value = row;

        return;
    }
    entryForm.employee_uuid = row.employee.uuid;
    entryForm.started_at = toDatetimeLocalInput(new Date());
    entryForm.post(hrUrl('/administration/attendance'), {
        preserveScroll: true,
        onFinish: () => { confirmingEntry.value = null; },
    });
};
const entryErrors = computed(() => Object.values(entryForm.errors));
const closeFromBoard = (row) => openClose({ uuid: row.open_session.uuid, started_at: row.open_session.started_at, observation: row.open_session.observation, employee: row.employee });
</script>

<template>
    <Head title="Présences" />

    <div class="space-y-5">
        <PageHeader
            eyebrow="Ressources humaines"
            title="Présences"
            description="Les entrées et sorties réellement enregistrées. Aucun retard ni heure supplémentaire n’est calculé automatiquement."
            :icon="Clock"
            tone="violet"
        >
            <template #actions>
                <Button v-if="can('attendance.print')" :as="Link" :href="hrUrl(`/administration/attendance/print?${queryString}`)" variant="outline"><Printer class="h-4 w-4" />Imprimer</Button>
                <Button v-if="can('attendance.export')" as="a" :href="hrUrl(`/administration/attendance/export?${queryString}`)" variant="outline"><Download class="h-4 w-4" />Exporter</Button>
                <Button v-if="can('attendance.create')" :as="Link" :href="hrUrl('/administration/attendance/create')"><Plus class="h-4 w-4" />Nouvelle présence</Button>
            </template>
        </PageHeader>

        <!-- ADR-198 — le jour d'abord ; l'historique garde la période, l'employé et les sessions ouvertes. -->
        <nav class="flex w-fit gap-1 rounded-xl border border-border bg-card p-1 shadow-sm" aria-label="Présences">
            <Link :href="hrUrl('/administration/attendance')" :aria-current="view === 'today' ? 'page' : undefined" :class="cn('inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold transition-colors', view === 'today' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-accent hover:text-foreground')"><UserCheck class="h-4 w-4" />Aujourd’hui</Link>
            <Link :href="hrUrl('/administration/attendance?vue=historique')" :aria-current="view === 'history' ? 'page' : undefined" :class="cn('inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold transition-colors', view === 'history' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-accent hover:text-foreground')"><History class="h-4 w-4" />Historique</Link>
        </nav>

        <template v-if="view === 'today' && board">
            <section class="grid grid-cols-2 gap-3 md:grid-cols-4" aria-label="Filtrer la journée">
                <button v-for="key in ['PRESENT', 'EXPECTED', 'LEFT', 'ON_LEAVE']" :key="key" type="button" class="text-start" :aria-pressed="boardState === key" @click="toggleState(key)">
                    <HrStatCard :label="STATES[key].label" :value="board.counts[key] ?? 0" :hint="STATES[key].hint" :icon="STATES[key].icon" :tone="STATES[key].tone" :active="boardState === key" />
                </button>
            </section>

            <section class="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                <div class="flex flex-wrap items-center gap-2 border-b border-border p-4">
                    <IconInput v-model="boardQuery" :icon="Search" type="search" class="w-full sm:w-80" placeholder="Nom, matricule, fonction…" aria-label="Rechercher une personne" />
                    <span class="text-sm text-muted-foreground">{{ boardRows.length }} personne{{ boardRows.length > 1 ? 's' : '' }}<template v-if="boardState !== 'ALL'"> · {{ STATES[boardState].label.toLowerCase() }}</template></span>
                    <Button v-if="boardState !== 'ALL'" size="sm" variant="ghost" @click="boardState = 'ALL'">Tout afficher</Button>
                    <span class="ms-auto text-xs text-muted-foreground">Aucun retard ni absence n’est calculé : l’écran montre ce qui est enregistré.</span>
                </div>
                <div v-if="entryErrors.length" class="border-b border-border bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-950/30 dark:text-red-200" role="alert">
                    <p v-for="message in entryErrors" :key="message">{{ message }}</p>
                </div>

                <ul v-if="boardRows.length" class="divide-y divide-border">
                    <li v-for="row in boardRows" :key="row.employee.uuid" class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center">
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <EmployeePhoto :src="row.employee.photo_url" :name="row.employee.name" size="md" />
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-1.5">
                                    <Link v-if="can('employees.view')" :href="hrUrl(`/administration/employees/${row.employee.uuid}`)" class="truncate font-semibold text-foreground hover:text-primary hover:underline">{{ row.employee.name }}</Link>
                                    <span v-else class="truncate font-semibold text-foreground">{{ row.employee.name }}</span>
                                    <Badge v-if="row.employee.is_intern" variant="secondary" class="px-1.5 py-0 text-[10px] uppercase">Stagiaire</Badge>
                                </p>
                                <p class="truncate text-xs text-muted-foreground">{{ row.employee.employee_number }}<template v-if="row.employee.job_title"> · {{ row.employee.job_title }}</template><template v-if="row.employee.department"> · {{ row.employee.department }}</template></p>
                            </div>
                        </div>

                        <div class="min-w-0 sm:w-72">
                            <span :class="cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold', STATES[row.state].badge)">
                                <component :is="STATES[row.state].icon" class="h-3.5 w-3.5" />{{ STATE_LABELS[row.state] }}
                            </span>
                            <p class="mt-1 text-xs text-muted-foreground">
                                <template v-if="row.state === 'PRESENT'">Entrée à {{ hhmm(row.open_session.started_at) }}<template v-if="row.minutes_today"> · déjà {{ formatMinutes(row.minutes_today) }} aujourd’hui</template></template>
                                <template v-else-if="row.state === 'LEFT'">Sortie à {{ hhmm(row.last_exit) }} · {{ formatMinutes(row.minutes_today) }} au total</template>
                                <template v-else-if="row.state === 'ON_LEAVE'">{{ row.leave.type ? `${row.leave.type} · ` : '' }}jusqu’au {{ frenchDate(row.leave.until) }}</template>
                                <template v-else-if="row.state === 'EXPECTED'">Créneau {{ hhmm(row.shift.starts_at) }} – {{ hhmm(row.shift.ends_at) }}<template v-if="row.shift.kind === 'ON_CALL'"> · garde</template></template>
                                <template v-else>Aucun créneau au planning aujourd’hui</template>
                            </p>
                            <p v-if="row.shift && row.state !== 'EXPECTED'" class="text-[11px] text-muted-foreground">Planning : {{ hhmm(row.shift.starts_at) }} – {{ hhmm(row.shift.ends_at) }}</p>
                        </div>

                        <div class="flex shrink-0 items-center gap-1.5 sm:justify-end">
                            <Button v-if="row.state === 'PRESENT' && can('attendance.update')" size="sm" variant="outline" @click="closeFromBoard(row)"><LogOut class="h-4 w-4" />Sortie</Button>
                            <Button v-else-if="can('attendance.create')" size="sm" :variant="row.state === 'EXPECTED' ? 'default' : 'outline'" :disabled="entryForm.processing" @click="clockIn(row)"><LogIn class="h-4 w-4" />Entrée</Button>
                            <Button :as="Link" :href="historyHref(row)" size="icon" variant="ghost" title="Ses présences" :aria-label="`Présences de ${row.employee.name}`"><History class="h-4 w-4" /></Button>
                        </div>
                    </li>
                </ul>
                <EmptyState v-else :icon="Users" title="Personne dans cette vue" description="Changez le filtre ou la recherche." />
            </section>
        </template>

        <template v-else>
        <section class="grid gap-3 sm:grid-cols-3">
            <HrStatCard label="Sessions" :value="summary.sessions" :hint="openOnly ? 'Sans sortie, toutes dates' : 'Sur la période affichée'" :icon="Activity" tone="violet" />
            <HrStatCard label="Employés concernés" :value="summary.employees" hint="Personnel distinct" :icon="Users" tone="sky" />
            <Link
                :href="openOnly ? hrUrl('/administration/attendance') : hrUrl('/administration/attendance?open=1')"
                preserve-scroll
                :aria-current="openOnly ? 'page' : undefined"
            >
                <HrStatCard label="Sessions ouvertes" :value="summary.open" :hint="openOnly ? 'Affichées · revenir à la période' : 'Sortie non renseignée · voir'" :icon="TimerOff" tone="amber" :active="openOnly" />
            </Link>
        </section>

        <section class="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
            <p v-if="openOnly" class="flex flex-wrap items-center justify-between gap-2 border-b border-border bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                <span>Toutes les sessions sans heure de sortie, quelle que soit leur date.</span>
                <Button size="sm" variant="outline" @click="filter">Revenir à la période</Button>
            </p>
            <form v-else class="grid gap-3 border-b border-border p-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_2fr_auto] lg:items-end" @submit.prevent="filter">
                <FormField label="Du" as="div"><DatePicker id="attendance_from" v-model="from" /></FormField>
                <FormField label="Au" as="div"><DatePicker id="attendance_to" v-model="to" :min="from" /></FormField>
                <FormField label="Employé" as="div"><Select v-model="employee" :options="employeeOptions" placeholder="Tous les employés" class="w-full" /></FormField>
                <Button type="submit">Afficher</Button>
            </form>

            <div v-if="records.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[860px] text-sm">
                    <thead class="bg-muted/50 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-5 py-3 text-start">Employé</th>
                            <th scope="col" class="px-5 py-3 text-start">Entrée</th>
                            <th scope="col" class="px-5 py-3 text-start">Sortie</th>
                            <th scope="col" class="px-5 py-3 text-start">Durée</th>
                            <th scope="col" class="px-5 py-3 text-start">Observation</th>
                            <th scope="col" class="px-5 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="record in records.data" :key="record.uuid" class="transition hover:bg-muted/30">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">{{ initials(record.employee.name) }}</span>
                                    <div class="min-w-0">
                                        <Link
                                            v-if="can('employees.view')"
                                            :href="hrUrl(`/administration/employees/${record.employee.uuid}`)"
                                            class="font-semibold text-foreground hover:text-primary hover:underline"
                                        >{{ record.employee.name }}</Link>
                                        <p v-else class="font-semibold text-foreground">{{ record.employee.name }}</p>
                                        <p class="font-mono text-xs text-muted-foreground">{{ record.employee.employee_number }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3.5 text-foreground">{{ formatDateTime(record.started_at) }}</td>
                            <td class="px-5 py-3.5 text-foreground">{{ record.ended_at ? formatDateTime(record.ended_at) : '—' }}</td>
                            <td class="px-5 py-3.5">
                                <Badge v-if="record.minutes === null" tone="warning">Ouverte</Badge>
                                <span v-else class="font-semibold tabular-nums text-foreground">{{ formatMinutes(record.minutes) }}</span>
                            </td>
                            <td class="max-w-xs px-5 py-3.5 text-muted-foreground">{{ record.observation || '—' }}</td>
                            <td class="px-5 py-3.5">
                                <div class="flex justify-end gap-1">
                                    <Button v-if="record.minutes === null && can('attendance.update')" size="sm" variant="outline" @click="openClose(record)"><LogOut class="h-4 w-4" />Sortie</Button>
                                    <Button v-if="can('attendance.update')" :as="Link" :href="hrUrl(`/administration/attendance/${record.uuid}/edit`)" size="icon" variant="ghost" title="Corriger" :aria-label="`Corriger la présence de ${record.employee.name}`"><Pencil class="h-4 w-4" /></Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState
                v-else
                :icon="Clock"
                :title="openOnly ? 'Aucune session ouverte' : 'Aucune présence sur cette période'"
                :description="openOnly ? 'Toutes les sorties sont renseignées.' : 'Élargissez la période, changez d’employé ou enregistrez une nouvelle session.'"
            />
            <HrPagination :paginator="records" />
        </section>

        </template>

        <ConfirmModal
            :open="Boolean(confirmingEntry)"
            title="Enregistrer une entrée pendant un congé ?"
            :description="confirmingEntry ? `${confirmingEntry.employee.name} est en congé aujourd’hui (${confirmingEntry.leave?.type ?? 'congé accepté'}). L’entrée est enregistrée à l’heure actuelle ; le congé n’est pas modifié.` : ''"
            :confirm-label="`Entrée à ${formatTime(new Date())}`"
            tone="primary"
            :icon="LogIn"
            :processing="entryForm.processing"
            @update:open="(value) => { if (!value) confirmingEntry = null; }"
            @confirm="clockIn(confirmingEntry)"
        />

        <ConfirmModal
            :open="Boolean(closing)"
            title="Enregistrer la sortie"
            :description="closing ? `${closing.employee.name} est entré(e) le ${formatDateTime(closing.started_at)}. La sortie est enregistrée à l’heure actuelle ; elle reste corrigeable ensuite.` : ''"
            :confirm-label="`Sortie à ${formatTime(new Date())}`"
            tone="primary"
            :icon="LogOut"
            :processing="closeForm.processing"
            @update:open="(value) => { if (!value) closing = null; }"
            @confirm="closeNow"
        >
            <div v-if="closeErrors.length" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200" role="alert">
                <p v-for="message in closeErrors" :key="message">{{ message }}</p>
            </div>
        </ConfirmModal>
    </div>
</template>
