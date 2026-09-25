<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Activity, Clock, Download, LogOut, Pencil, Plus, Printer, TimerOff, Users,
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
const props = defineProps({ records: Object, employees: [Array, Object], filters: Object, summary: Object });
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
