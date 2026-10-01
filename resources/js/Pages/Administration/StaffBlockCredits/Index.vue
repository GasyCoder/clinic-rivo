<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    CircleAlert,
    Clock3,
    History,
    LockKeyhole,
    Plus,
    Search,
    ShieldCheck,
    UserRound,
    Users,
    Wallet,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Shadcn/Avatar.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import HrPagination from '../Partials/HrPagination.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { hrUrl } from '@/utilities/hrUrl';
import { currencyLabel, formatMoney } from '@/utilities/money';

/**
 * ADR-030 — le crédit Bloc du personnel est un registre RH/Finance immuable.
 * Cet écran ne recalcule ni ne corrige le registre : il sélectionne un employé,
 * présente la projection envoyée par Laravel et soumet une allocation motivée.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    employees: { type: Object, required: true },
    selectedEmployee: { type: Object, default: null },
    movements: { type: Object, default: null },
    filters: { type: Object, default: () => ({}) },
});

const { can } = usePermissions();
const query = ref(props.filters.q ?? '');
const allocationOpen = ref(false);
const newIdempotencyKey = () => crypto.randomUUID();
const allocationForm = useForm({ amount: '', reason: '', idempotency_key: newIdempotencyKey() });

const employeeName = (employee) => [employee?.last_name, employee?.first_name].filter(Boolean).join(' ');
const employeeInitials = (employee) => [employee?.last_name?.[0], employee?.first_name?.[0]].filter(Boolean).join('').toUpperCase();

const creditMetrics = computed(() => props.selectedEmployee ? [
    { key: 'allocated', label: 'Total alloué', value: props.selectedEmployee.credit.allocated, tone: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300' },
    { key: 'consumed', label: 'Consommé au Bloc', value: props.selectedEmployee.credit.consumed, tone: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300' },
    { key: 'reversed', label: 'Total réversé', value: props.selectedEmployee.credit.reversed, tone: 'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300' },
] : []);

const projectedBalance = computed(() => Number(props.selectedEmployee?.credit.available ?? 0) + Number(allocationForm.amount || 0));
const allocationReady = computed(() => Number(allocationForm.amount) > 0 && allocationForm.reason.trim().length >= 5);

const submitSearch = () => router.get(hrUrl('/administration/staff-block-credits'), {
    q: query.value || undefined,
    employee: props.selectedEmployee?.uuid,
}, { preserveState: true, preserveScroll: true, replace: true });

const clearSearch = () => {
    query.value = '';
    submitSearch();
};

const selectEmployee = (employee) => router.get(hrUrl('/administration/staff-block-credits'), {
    q: query.value || undefined,
    employee: employee.uuid,
}, { preserveState: true, preserveScroll: true, replace: true });

const resetAllocation = () => {
    allocationForm.reset();
    allocationForm.clearErrors();
    allocationForm.idempotency_key = newIdempotencyKey();
};

const onAllocationOpenChange = (open) => {
    if (open) {
        allocationOpen.value = true;
        return;
    }

    if (! allocationForm.processing) {
        allocationOpen.value = false;
        resetAllocation();
    }
};

watch(() => props.selectedEmployee?.uuid, () => {
    allocationOpen.value = false;
    resetAllocation();
});

const allocate = () => {
    if (! props.selectedEmployee || ! allocationReady.value) return;

    allocationForm.post(hrUrl(`/administration/staff-block-credits/${props.selectedEmployee.uuid}`), {
        preserveScroll: true,
        onSuccess: () => {
            allocationOpen.value = false;
            resetAllocation();
        },
    });
};

const formatDateTime = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : '—';

const movementBadge = (type) => ({
    ALLOCATION: { variant: 'default', icon: Plus },
    CONSUMPTION: { variant: 'warning', icon: Wallet },
    REVERSAL: { variant: 'secondary', icon: History },
}[type] ?? { variant: 'outline', icon: Clock3 });
</script>

<template>
    <Head title="Crédit forfaitaire Bloc" />

    <div class="w-full space-y-4">
        <PageHeader
            eyebrow="Ressources humaines · Avantage du personnel"
            title="Crédit forfaitaire Bloc"
            description="Consultez le solde disponible, allouez un crédit motivé et retrouvez chaque mouvement du registre."
            :icon="Wallet"
            compact
        />

        <Card class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                    <ShieldCheck class="h-4 w-4" />
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-foreground">Un registre protégé, jamais réécrit</p>
                    <p class="mt-0.5 text-xs leading-5 text-muted-foreground">Le montant est décidé manuellement. Les consommations du Bloc et les réversions restent traçables ligne par ligne.</p>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap gap-1.5 ps-12 sm:ps-0">
                <Badge variant="outline"><LockKeyhole class="h-3 w-3" />Immuable</Badge>
                <Badge variant="outline"><ShieldCheck class="h-3 w-3" />Audité</Badge>
            </div>
        </Card>

        <div class="grid items-start gap-4 xl:grid-cols-[21rem_minmax(0,1fr)]">
            <Card class="overflow-hidden xl:sticky xl:top-4">
                <header class="border-b border-border px-4 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="flex items-center gap-2 text-sm font-bold text-foreground"><Users class="h-4 w-4 text-primary" />Personnel</h2>
                            <p class="mt-0.5 text-xs tabular-nums text-muted-foreground">{{ employees.total }} dossier{{ employees.total > 1 ? 's' : '' }}</p>
                        </div>
                        <Badge variant="secondary">Sélectionnez</Badge>
                    </div>

                    <form class="mt-3 flex gap-2" role="search" @submit.prevent="submitSearch">
                        <div class="relative min-w-0 flex-1">
                            <IconInput v-model="query" :icon="Search" type="search" placeholder="Nom ou matricule…" aria-label="Rechercher un employé" />
                            <button
                                v-if="query"
                                type="button"
                                class="absolute end-2 top-1/2 grid h-7 w-7 -translate-y-1/2 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                                aria-label="Effacer la recherche"
                                @click="clearSearch"
                            ><X class="h-3.5 w-3.5" /></button>
                        </div>
                        <Button type="submit" size="icon" variant="outline" aria-label="Lancer la recherche"><Search class="h-4 w-4" /></Button>
                    </form>
                </header>

                <div v-if="employees.data.length" class="max-h-[34rem] divide-y divide-border overflow-y-auto">
                    <button
                        v-for="employee in employees.data"
                        :key="employee.uuid"
                        type="button"
                        :aria-pressed="selectedEmployee?.uuid === employee.uuid"
                        :class="cn(
                            'group flex w-full items-center gap-3 border-s-2 px-3 py-3 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                            selectedEmployee?.uuid === employee.uuid
                                ? 'border-primary bg-primary/5'
                                : 'border-transparent hover:bg-accent/60',
                        )"
                        @click="selectEmployee(employee)"
                    >
                        <Avatar :initials="employeeInitials(employee)" :variant="selectedEmployee?.uuid === employee.uuid ? 'primary-pale' : 'slate-pale'" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold text-foreground">{{ employeeName(employee) }}</span>
                            <span class="mt-0.5 block truncate text-xs text-muted-foreground">{{ employee.employee_number }} · {{ employee.job_title || 'Fonction non renseignée' }}</span>
                        </span>
                        <span class="shrink-0 text-end">
                            <span class="block text-xs font-bold tabular-nums text-foreground">{{ formatMoney(employee.credit.available) }}</span>
                            <span :class="cn('mt-0.5 block text-[10px] font-medium', employee.active ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground')">{{ employee.active ? 'Disponible' : 'Inactif' }}</span>
                        </span>
                    </button>
                </div>

                <div v-else class="flex flex-col items-center gap-2 px-5 py-10 text-center">
                    <span class="grid h-11 w-11 place-items-center rounded-full bg-muted text-muted-foreground"><Search class="h-5 w-5" /></span>
                    <p class="text-sm font-semibold text-foreground">Aucun employé trouvé</p>
                    <p class="text-xs leading-5 text-muted-foreground">Essayez un autre nom ou matricule.</p>
                    <Button v-if="query" type="button" size="sm" variant="outline" @click="clearSearch">Effacer la recherche</Button>
                </div>

                <HrPagination :paginator="employees" />
            </Card>

            <main v-if="selectedEmployee" class="min-w-0 space-y-4">
                <Card class="overflow-hidden">
                    <header class="flex flex-col gap-3 border-b border-border px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 items-center gap-3">
                            <Avatar :initials="employeeInitials(selectedEmployee)" variant="primary-pale" size="lg" />
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="truncate text-base font-bold text-foreground">{{ employeeName(selectedEmployee) }}</h2>
                                    <Badge :variant="selectedEmployee.active ? 'success' : 'outline'">{{ selectedEmployee.active ? 'Dossier actif' : 'Inactif / archivé' }}</Badge>
                                </div>
                                <p class="mt-1 truncate text-xs text-muted-foreground">{{ selectedEmployee.employee_number }} · {{ selectedEmployee.job_title || 'Fonction non renseignée' }}</p>
                            </div>
                        </div>
                        <Button
                            v-if="can('staff_block_credits.allocate') && selectedEmployee.active"
                            type="button"
                            size="sm"
                            @click="allocationOpen = true"
                        ><Plus class="h-4 w-4" />Allouer un crédit</Button>
                    </header>

                    <div class="grid gap-2.5 p-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-xl border border-primary/20 bg-primary/5 p-3.5">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-primary">Solde disponible</p>
                                <Wallet class="h-4 w-4 text-primary" />
                            </div>
                            <p class="mt-2 text-xl font-bold tabular-nums tracking-tight text-foreground">{{ formatMoney(selectedEmployee.credit.available) }}</p>
                        </div>
                        <div v-for="metric in creditMetrics" :key="metric.key" class="rounded-xl border border-border bg-card p-3.5">
                            <span :class="cn('grid h-7 w-7 place-items-center rounded-md', metric.tone)"><History class="h-3.5 w-3.5" /></span>
                            <p class="mt-2 text-[11px] font-medium text-muted-foreground">{{ metric.label }}</p>
                            <p class="mt-0.5 text-sm font-bold tabular-nums text-foreground">{{ formatMoney(metric.value) }}</p>
                        </div>
                    </div>

                    <div v-if="! selectedEmployee.active" class="flex items-start gap-2 border-t border-border bg-muted/40 px-4 py-3 text-xs text-muted-foreground">
                        <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                        <p>Ce dossier est inactif ou archivé : son registre reste consultable, mais aucune nouvelle allocation n’est possible.</p>
                    </div>
                </Card>

                <Card class="overflow-hidden">
                    <header class="flex flex-col gap-2 border-b border-border px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="flex items-center gap-2 text-sm font-bold text-foreground"><History class="h-4 w-4 text-primary" />Historique du registre</h2>
                            <p class="mt-0.5 text-xs text-muted-foreground">{{ movements?.total ?? 0 }} mouvement{{ (movements?.total ?? 0) > 1 ? 's' : '' }}, du plus récent au plus ancien</p>
                        </div>
                        <Badge variant="outline"><LockKeyhole class="h-3 w-3" />Lecture seule</Badge>
                    </header>

                    <div v-if="movements?.data?.length" class="overflow-x-auto">
                        <table class="w-full min-w-[52rem] text-sm">
                            <caption class="sr-only">Mouvements du crédit Bloc de {{ employeeName(selectedEmployee) }}</caption>
                            <thead class="border-b border-border bg-muted/35 text-[11px] text-muted-foreground">
                                <tr>
                                    <th class="px-4 py-2.5 text-start font-semibold">Date</th>
                                    <th class="px-3 py-2.5 text-start font-semibold">Mouvement</th>
                                    <th class="px-3 py-2.5 text-end font-semibold">Montant</th>
                                    <th class="px-3 py-2.5 text-start font-semibold">Évolution du solde</th>
                                    <th class="px-4 py-2.5 text-start font-semibold">Traçabilité</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="movement in movements.data" :key="movement.uuid" class="transition-colors hover:bg-muted/25">
                                    <td class="whitespace-nowrap px-4 py-3 text-xs tabular-nums text-muted-foreground">{{ formatDateTime(movement.created_at) }}</td>
                                    <td class="px-3 py-3">
                                        <Badge :variant="movementBadge(movement.movement_type).variant">
                                            <component :is="movementBadge(movement.movement_type).icon" class="h-3 w-3" />
                                            {{ movement.movement_type_label }}
                                        </Badge>
                                    </td>
                                    <td :class="cn('whitespace-nowrap px-3 py-3 text-end font-bold tabular-nums', Number(movement.amount) < 0 ? 'text-destructive' : 'text-emerald-600 dark:text-emerald-400')">
                                        {{ Number(movement.amount) > 0 ? '+' : '' }}{{ formatMoney(movement.amount) }}
                                    </td>
                                    <td class="px-3 py-3">
                                        <span class="inline-flex items-center gap-1.5 whitespace-nowrap text-xs tabular-nums">
                                            <span class="text-muted-foreground">{{ formatMoney(movement.balance_before) }}</span>
                                            <ArrowRight class="h-3.5 w-3.5 text-muted-foreground" />
                                            <strong class="font-bold text-foreground">{{ formatMoney(movement.balance_after) }}</strong>
                                        </span>
                                    </td>
                                    <td class="max-w-sm px-4 py-3 text-xs leading-5 text-muted-foreground">
                                        <span v-if="movement.episode_number" class="block font-semibold text-foreground">Épisode {{ movement.episode_number }}</span>
                                        <span class="block">{{ movement.billable_item_description || movement.reason || 'Sans détail' }}</span>
                                        <span class="block text-[10px]">Par {{ movement.created_by || 'le système' }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="flex flex-col items-center gap-2 px-5 py-10 text-center">
                        <span class="grid h-11 w-11 place-items-center rounded-full bg-muted text-muted-foreground"><History class="h-5 w-5" /></span>
                        <p class="text-sm font-semibold text-foreground">Aucun mouvement</p>
                        <p class="max-w-md text-xs leading-5 text-muted-foreground">La première allocation apparaîtra ici avec son auteur, son motif et le solde obtenu.</p>
                    </div>

                    <HrPagination :paginator="movements" />
                </Card>
            </main>

            <Card v-else class="flex min-h-72 flex-col items-center justify-center gap-3 border-dashed px-6 py-12 text-center">
                <span class="grid h-12 w-12 place-items-center rounded-full bg-primary/10 text-primary"><UserRound class="h-6 w-6" /></span>
                <div>
                    <h2 class="text-sm font-bold text-foreground">Choisissez un employé</h2>
                    <p class="mt-1 max-w-md text-sm leading-6 text-muted-foreground">Son solde, ses totaux et l’historique immuable du crédit Bloc s’afficheront ici.</p>
                </div>
            </Card>
        </div>

        <Dialog
            :open="allocationOpen"
            title="Allouer un crédit Bloc"
            :description="selectedEmployee ? `${employeeName(selectedEmployee)} · ${selectedEmployee.employee_number}` : ''"
            :dismissible="! allocationForm.processing"
            @update:open="onAllocationOpenChange"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Wallet class="h-5 w-5" /></span>
            </template>

            <form id="staff-block-credit-allocation" class="space-y-4" @submit.prevent="allocate">
                <div class="grid grid-cols-2 gap-2 rounded-xl border border-border bg-muted/35 p-3 text-sm">
                    <div>
                        <p class="text-xs text-muted-foreground">Solde actuel</p>
                        <p class="mt-1 font-bold tabular-nums text-foreground">{{ formatMoney(selectedEmployee?.credit.available) }}</p>
                    </div>
                    <div class="border-s border-border ps-3">
                        <p class="text-xs text-muted-foreground">Solde après allocation</p>
                        <p class="mt-1 font-bold tabular-nums text-primary">{{ formatMoney(projectedBalance) }}</p>
                    </div>
                </div>

                <FormField label="Montant à allouer" required :error="allocationForm.errors.amount" :icon="Wallet">
                    <div class="relative">
                        <Input v-model="allocationForm.amount" type="number" min="1" step="0.01" inputmode="decimal" class="pe-16" autofocus required />
                        <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-xs font-semibold text-muted-foreground">{{ currencyLabel() }}</span>
                    </div>
                </FormField>

                <FormField label="Motif de la décision" required hint="5 caractères minimum" :error="allocationForm.errors.reason" :icon="ShieldCheck">
                    <Textarea v-model="allocationForm.reason" rows="3" minlength="5" maxlength="1000" required placeholder="Expliquez pourquoi ce crédit est accordé…" />
                </FormField>

                <div class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/35 dark:text-amber-200">
                    <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                    <p>La confirmation ajoute une ligne auditée au registre. Elle ne pourra pas être modifiée ni supprimée.</p>
                </div>

                <p v-if="allocationForm.errors.idempotency_key" class="text-xs font-medium text-destructive">{{ allocationForm.errors.idempotency_key }}</p>
            </form>

            <template #footer>
                <Button type="button" variant="outline" :disabled="allocationForm.processing" @click="onAllocationOpenChange(false)">Annuler</Button>
                <Button type="submit" form="staff-block-credit-allocation" :disabled="allocationForm.processing || ! allocationReady">
                    <ShieldCheck class="h-4 w-4" />{{ allocationForm.processing ? 'Enregistrement…' : 'Confirmer l’allocation' }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
