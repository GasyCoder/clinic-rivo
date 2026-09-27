<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    AtSign,
    Building2,
    CheckCircle2,
    KeyRound,
    Search,
    ShieldCheck,
    Sparkles,
    TriangleAlert,
    Undo2,
    UserMinus,
    UserPlus,
    Users,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import NoticesButton from '@/Components/Shadcn/NoticesButton.vue';
import RefreshIcon from '@/Components/Shadcn/RefreshIcon.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import StaffAccessGrantDialog from '@/Components/StaffAccess/StaffAccessGrantDialog.vue';
import StaffAccessHandoverCard from '@/Components/StaffAccess/StaffAccessHandoverCard.vue';
import UserAccessTabs from '@/Components/SuperAdmin/UserAccessTabs.vue';
import { cn } from '@/lib/cn';
import { useToastStore } from '@/stores/toast';
import { formatDate } from '@/utilities/date';
import { HANDOVER_GROUPS, handoverGroup, isRecent } from '@/utilities/staffAccess';
import { avatarTone, initialsOf } from '@/utilities/webmail';

/**
 * ADR-197 — l'accès du personnel, sur le portail.
 *
 * Le RH de chaque site ajoute ses employés ; ils arrivent ici, « À créer ». Le
 * Super Admin crée en un geste leur adresse pro et leur compte RIVO, sans aucun
 * mot de passe (ADR-202), puis les annonce au RH du site, qui prévient chaque
 * employé : celui-ci choisit son mot de passe à sa première connexion.
 * Un employé qui n'a besoin d'aucun accès (entretien, gardiennage…) se range
 * dans « Sans accès », réversible. Tout passe par l'API des sites.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    sites: { type: Array, default: () => [] },
    hosting: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const toast = useToastStore();
const tab = ref('pending');
const site = ref(props.filters.site && props.sites.some((entry) => entry.code === props.filters.site) ? props.filters.site : null);
const query = ref('');
const selected = ref(new Set());
const grantOpen = ref(false);
const grantEmployees = ref([]);
const refreshing = ref(false);

const online = computed(() => props.sites.filter((entry) => entry.ok));
const offline = computed(() => props.sites.filter((entry) => !entry.ok));
const scoped = computed(() => online.value.filter((entry) => !site.value || entry.code === site.value));

const withSite = (entry, rows) => rows.map((row) => ({ ...row, site_code: entry.code, site_name: entry.name, key: `${entry.code}:${row.uuid}` }));
const fold = (value) => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const matches = (row) => {
    const words = fold(query.value).split(/\s+/).filter(Boolean);
    const haystack = fold([row.name, row.employee_number, row.job_title, row.department, row.site_name].join(' '));

    return words.every((word) => haystack.includes(word));
};

const pending = computed(() => scoped.value.flatMap((entry) => withSite(entry, entry.pending)).filter(matches));
const waived = computed(() => scoped.value.flatMap((entry) => withSite(entry, entry.waived)).filter(matches));
const handovers = computed(() => scoped.value.flatMap((entry) => entry.handovers.map((handover) => ({ ...handover, site_code: entry.code, site_name: entry.name, receivers: entry.receivers, accounts: entry.accounts ?? [] }))));
const drafts = computed(() => handovers.value.filter((handover) => handover.status === 'DRAFT'));

/* ADR-199 / ADR-202 — les remises filtrées par état : à envoyer, délai dépassé, en attente, tous connectés. */
const handoverFilter = ref('all');
const handoverCounts = computed(() => Object.fromEntries(HANDOVER_GROUPS.map((group) => [group.key, handovers.value.filter((handover) => handoverGroup(handover.status) === group.key).length])));
/** Ce qui attend un geste d'abord (à envoyer, puis chez le RH), le plus récent en tête dans chaque groupe. */
const GROUP_ORDER = Object.fromEntries(HANDOVER_GROUPS.map((group, index) => [group.key, index]));
const shownHandovers = computed(() => handovers.value
    .filter((handover) => handoverFilter.value === 'all' || handoverGroup(handover.status) === handoverFilter.value)
    .map((handover, index) => ({ handover, index }))
    .sort((a, b) => (GROUP_ORDER[handoverGroup(a.handover.status)] - GROUP_ORDER[handoverGroup(b.handover.status)]) || (a.index - b.index))
    .map(({ handover }) => handover));

/** Tous sites confondus, quel que soit le filtre : c'est ce que dit l'onglet du module. */
const totalPending = computed(() => online.value.reduce((sum, entry) => sum + entry.pending.length, 0));

const counts = computed(() => ({
    pending: scoped.value.reduce((sum, entry) => sum + entry.pending.length, 0),
    handovers: handovers.value.length,
    waived: scoped.value.reduce((sum, entry) => sum + entry.waived.length, 0),
}));

const TABS = [
    { key: 'pending', label: 'À créer', icon: UserPlus },
    { key: 'handovers', label: 'Accès créés', icon: KeyRound },
    { key: 'waived', label: 'Sans accès', icon: UserMinus },
];

watch([site, tab, query], () => { selected.value = new Set(); });

const allSelected = computed(() => pending.value.length > 0 && pending.value.every((row) => selected.value.has(row.key)));
const someSelected = computed(() => !allSelected.value && pending.value.some((row) => selected.value.has(row.key)));
const toggleAll = (value) => { selected.value = value ? new Set(pending.value.map((row) => row.key)) : new Set(); };
const toggleOne = (key, value) => {
    const next = new Set(selected.value);
    if (value) next.add(key);
    else next.delete(key);
    selected.value = next;
};

const rolesBySite = computed(() => Object.fromEntries(props.sites.map((entry) => [entry.code, entry.roles])));
const receiversBySite = computed(() => Object.fromEntries(props.sites.map((entry) => [entry.code, entry.receivers])));
const accountsBySite = computed(() => Object.fromEntries(props.sites.map((entry) => [entry.code, entry.accounts ?? []])));
/** ADR-202 — le délai de première connexion le plus court parmi les sites des employés choisis. */
const activationDays = computed(() => {
    const codes = new Set(grantEmployees.value.map((employee) => employee.site_code));
    const days = props.sites.filter((entry) => codes.has(entry.code)).map((entry) => entry.activation_days ?? 14);

    return days.length ? Math.min(...days) : 14;
});

const openGrant = (rows) => {
    grantEmployees.value = rows;
    grantOpen.value = true;
};
const grantSelection = () => openGrant(pending.value.filter((row) => selected.value.has(row.key)));

const reload = () => {
    refreshing.value = true;
    router.reload({ only: ['sites'], onFinish: () => { refreshing.value = false; } });
};

const onFinished = () => {
    selected.value = new Set();
    reload();
};

// --- Requêtes JSON (le portail relaie l'API du site) ---
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const post = async (url, body = {}) => {
    const response = await fetch(url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok) {
        const first = Object.values(json.errors ?? {}).flat()[0];
        throw new Error(first ?? json.message ?? 'Le site a refusé.');
    }

    return json;
};

// --- Envoyer une remise restée en attente ---
const sendingUuid = ref(null);
const send = async (handover) => {
    sendingUuid.value = handover.uuid;
    try {
        const json = await post(`/super-admin/staff-access/${handover.site_code}/handovers/${handover.uuid}/send`);
        toast.success(json.message ?? 'Accès envoyés au RH.');
        reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        sendingUuid.value = null;
    }
};

// --- Sans accès ---
const waiveTarget = ref(null);
const waiveReason = ref('');
const waiveError = ref('');
const waiving = ref(false);
const openWaive = (row) => {
    waiveTarget.value = row;
    waiveReason.value = '';
    waiveError.value = '';
};
const waive = async () => {
    waiving.value = true;
    waiveError.value = '';
    try {
        const json = await post(`/super-admin/staff-access/${waiveTarget.value.site_code}/employees/${waiveTarget.value.uuid}/waive`, { reason: waiveReason.value });
        toast.success(json.message ?? 'Aucun accès nécessaire.');
        waiveTarget.value = null;
        reload();
    } catch (error) {
        waiveError.value = error.message;
    } finally {
        waiving.value = false;
    }
};
const unwaive = async (row) => {
    try {
        const json = await post(`/super-admin/staff-access/${row.site_code}/employees/${row.uuid}/unwaive`);
        toast.success(json.message ?? 'Remis dans « À créer ».');
        reload();
    } catch (error) {
        toast.error(error.message);
    }
};

const notices = computed(() => [
    ...(!props.hosting.ready ? [{ key: 'hosting', icon: TriangleAlert, tone: 'warning', title: 'Création impossible pour l’instant', text: props.hosting.refusal }] : []),
    ...offline.value.map((entry) => ({ key: `offline-${entry.code}`, icon: TriangleAlert, tone: 'warning', title: `${entry.name} injoignable`, text: entry.message ?? 'Ses employés ne sont pas listés.' })),
    { key: 'password', icon: KeyRound, tone: 'info', title: 'Aucun mot de passe créé', text: 'L’employé tape son adresse sur la page de connexion et choisit lui-même son mot de passe, qui ouvre aussi sa messagerie. Personne d’autre ne le connaît.' },
    { key: 'handover', icon: ShieldCheck, tone: 'info', title: 'Annonce au RH', text: 'Le RH dit à chaque employé que son compte existe et où se connecter. Il voit qui s’est connecté, et rouvre la première connexion d’un compte qui a laissé passer le délai.' },
]);

const canCreate = computed(() => props.can.create && props.hosting.ready);
</script>

<template>
    <Head title="Accès du personnel" />

    <div class="flex flex-col gap-6">
        <!-- ADR-199 — un seul module : les comptes, et l'arrivée des nouveaux employés. -->
        <UserAccessTabs current="staff-access" :pending="totalPending" />

        <!-- En-tête -->
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary" aria-hidden="true"><KeyRound class="h-5 w-5" /></span>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">Utilisateurs</p>
                    <h1 class="font-heading text-2xl font-bold text-foreground">Accès du personnel</h1>
                    <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                        L’arrivée d’un employé : le RH l’ajoute, vous créez ici son adresse professionnelle et son compte RIVO en un geste — le rôle proposé par sa fonction, aucun mot de passe —, puis le RH du site le prévient : il se connecte avec son adresse et choisit son mot de passe. Les comptes existants se gèrent dans l’onglet « Comptes ».
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                <Button type="button" variant="white-outline" size="sm" :aria-busy="refreshing" :disabled="refreshing" @click="reload">
                    <RefreshIcon :spinning="refreshing" class="h-4 w-4" /> Actualiser
                </Button>
                <NoticesButton :notices="notices" />
            </div>
        </header>

        <!-- Sites -->
        <div class="flex flex-wrap gap-1.5" role="group" aria-label="Filtrer par site">
            <button
                type="button"
                :aria-pressed="!site"
                :class="cn('inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition-colors', !site ? 'bg-foreground text-background' : 'bg-card text-muted-foreground ring-1 ring-border hover:text-foreground')"
                @click="site = null"
            ><Building2 class="h-3.5 w-3.5" aria-hidden="true" />Tous les sites</button>
            <button
                v-for="entry in sites"
                :key="entry.code"
                type="button"
                :aria-pressed="site === entry.code"
                :disabled="!entry.ok"
                :title="entry.ok ? undefined : entry.message"
                :class="cn('inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60',
                    site === entry.code ? 'bg-foreground text-background' : 'bg-card text-muted-foreground ring-1 ring-border hover:text-foreground')"
                @click="site = site === entry.code ? null : entry.code"
            >
                {{ entry.name }}
                <span v-if="entry.ok && entry.pending.length" class="rounded-full bg-destructive px-1.5 text-[10px] font-bold tabular-nums text-destructive-foreground">{{ entry.pending.length }}</span>
                <span v-else-if="!entry.ok" class="text-[10px] font-medium">injoignable</span>
            </button>
        </div>

        <section class="min-w-0 rounded-xl border border-border bg-card shadow-sm">
            <!-- Onglets -->
            <div class="flex flex-wrap items-center gap-1 border-b border-border px-3 pt-2" role="tablist" aria-label="Accès du personnel">
                <button
                    v-for="entry in TABS"
                    :key="entry.key"
                    type="button"
                    role="tab"
                    :aria-selected="tab === entry.key"
                    :class="cn('-mb-px inline-flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
                        tab === entry.key ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground')"
                    @click="tab = entry.key"
                >
                    <component :is="entry.icon" class="h-4 w-4" aria-hidden="true" />
                    {{ entry.label }}
                    <span :class="cn('rounded-full px-1.5 py-0.5 text-[11px] font-bold tabular-nums',
                        entry.key === 'pending' && counts.pending ? 'bg-destructive text-destructive-foreground'
                            : entry.key === 'handovers' && drafts.length ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300'
                                : 'bg-muted text-muted-foreground')"
                    >{{ counts[entry.key] }}</span>
                </button>
            </div>

            <!-- ============ À CRÉER ============ -->
            <div v-show="tab === 'pending'" role="tabpanel">
                <div class="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center">
                    <div class="flex items-center gap-3">
                        <Checkbox
                            :model-value="allSelected ? true : (someSelected ? 'indeterminate' : false)"
                            :disabled="!pending.length || !canCreate"
                            aria-label="Tout sélectionner"
                            @update:model-value="toggleAll"
                        />
                        <span class="text-xs text-muted-foreground">
                            <template v-if="selected.size">{{ selected.size }} sélectionné{{ selected.size > 1 ? 's' : '' }}</template>
                            <template v-else>{{ pending.length }} employé{{ pending.length > 1 ? 's' : '' }} sans accès</template>
                        </span>
                    </div>
                    <div class="min-w-0 flex-1 sm:max-w-sm sm:ms-auto">
                        <IconInput v-model="query" :icon="Search" type="search" placeholder="Nom, matricule, fonction, site…" aria-label="Chercher un employé" />
                    </div>
                    <Button type="button" :disabled="!selected.size || !canCreate" @click="grantSelection">
                        <UserPlus class="h-4 w-4" aria-hidden="true" />Créer les accès<template v-if="selected.size"> ({{ selected.size }})</template>
                    </Button>
                </div>

                <p v-if="!hosting.ready" class="flex items-start gap-2 border-b border-border bg-amber-50 px-4 py-2.5 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                    <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ hosting.refusal }}
                </p>

                <ul v-if="pending.length" class="divide-y divide-border">
                    <li v-for="row in pending" :key="row.key" class="flex flex-col gap-3 px-4 py-3.5 transition-colors hover:bg-accent/30 sm:flex-row sm:items-center">
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <Checkbox :model-value="selected.has(row.key)" :disabled="!canCreate" :aria-label="`Sélectionner ${row.name}`" @update:model-value="toggleOne(row.key, $event)" />
                            <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-full text-xs font-bold', avatarTone(row.uuid))" aria-hidden="true">{{ initialsOf({ name: row.name }) }}</span>
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold text-foreground">
                                    <span class="truncate">{{ row.name }}</span>
                                    <Badge v-if="isRecent(row.created_at)" tone="primary" class="py-0 text-[10px]"><Sparkles class="h-3 w-3" />Nouveau</Badge>
                                </p>
                                <p class="truncate text-xs text-muted-foreground">
                                    {{ [row.job_title, row.department, row.employee_number].filter(Boolean).join(' · ') || '—' }}
                                </p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted-foreground/80">
                                    <span v-if="!site" class="inline-flex items-center gap-1"><Building2 class="h-3 w-3" aria-hidden="true" />{{ row.site_name }}</span>
                                    <span v-if="row.created_at">Ajouté le {{ formatDate(row.created_at) }}</span>
                                    <span v-if="row.mailbox" class="inline-flex items-center gap-1"><AtSign class="h-3 w-3" aria-hidden="true" />{{ row.mailbox.address }} · {{ row.mailbox.status === 'ACTIVE' ? 'adresse existante' : row.mailbox.status === 'REQUESTED' ? 'demandée' : 'suspendue' }}</span>
                                </p>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5 ps-7 sm:ps-0">
                            <Button type="button" size="sm" variant="white-outline" :disabled="!canCreate" @click="openGrant([row])">
                                <UserPlus class="h-4 w-4" aria-hidden="true" />Créer l’accès
                            </Button>
                            <Button type="button" size="sm" variant="ghost" icon :disabled="!can.create" :title="`Aucun accès nécessaire pour ${row.name}`" :aria-label="`Aucun accès nécessaire pour ${row.name}`" @click="openWaive(row)">
                                <UserMinus class="h-4 w-4" aria-hidden="true" />
                            </Button>
                        </div>
                    </li>
                </ul>
                <div v-else class="flex flex-col items-center gap-2 px-6 py-16 text-center">
                    <span class="grid h-12 w-12 place-items-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-400"><CheckCircle2 class="h-5 w-5" aria-hidden="true" /></span>
                    <p class="text-sm font-semibold text-foreground">{{ query ? 'Aucun employé ne correspond' : 'Tout le personnel a son accès' }}</p>
                    <p class="max-w-sm text-xs text-muted-foreground">{{ query ? 'Changez la recherche.' : 'Dès qu’un RH ajoute un employé, il apparaît ici et vous êtes prévenu dans la cloche.' }}</p>
                </div>
            </div>

            <!-- ============ ACCÈS CRÉÉS ============ -->
            <div v-show="tab === 'handovers'" role="tabpanel">
                <template v-if="handovers.length">
                    <!-- Filtres par état : ce qui attend un geste d'abord -->
                    <div class="flex flex-wrap gap-1.5 border-b border-border px-4 py-3" role="group" aria-label="Filtrer les remises">
                        <button
                            v-for="entry in [{ key: 'all', label: 'Toutes' }, ...HANDOVER_GROUPS]"
                            :key="entry.key"
                            type="button"
                            :aria-pressed="handoverFilter === entry.key"
                            :disabled="entry.key !== 'all' && !handoverCounts[entry.key]"
                            :class="cn('inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50',
                                handoverFilter === entry.key ? 'bg-foreground text-background' : 'bg-card text-muted-foreground ring-1 ring-border hover:text-foreground')"
                            @click="handoverFilter = entry.key"
                        >
                            {{ entry.label }}
                            <span :class="cn('rounded-full px-1.5 text-[10px] font-bold tabular-nums',
                                entry.key === 'todo' && handoverCounts.todo ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300'
                                    : handoverFilter === entry.key ? 'bg-background/20' : 'bg-muted')"
                            >{{ entry.key === 'all' ? handovers.length : handoverCounts[entry.key] }}</span>
                        </button>
                    </div>
                    <div class="space-y-4 p-4">
                        <StaffAccessHandoverCard
                            v-for="handover in shownHandovers"
                            :key="`${handover.site_code}:${handover.uuid}`"
                            :handover="handover"
                            :can-send="Boolean(can.create)"
                            :can-designate="Boolean(can.designate)"
                            :sending="sendingUuid === handover.uuid"
                            :show-site="!site"
                            @send="send"
                            @designated="reload"
                        />
                        <p v-if="!shownHandovers.length" class="py-8 text-center text-sm text-muted-foreground">Aucune remise dans ce filtre.</p>
                    </div>
                </template>
                <div v-else class="flex flex-col items-center gap-2 px-6 py-16 text-center">
                    <span class="grid h-12 w-12 place-items-center rounded-full bg-muted text-muted-foreground"><KeyRound class="h-5 w-5" aria-hidden="true" /></span>
                    <p class="text-sm font-semibold text-foreground">Aucun accès créé ces 30 derniers jours</p>
                    <p class="max-w-sm text-xs text-muted-foreground">Les accès créés depuis « À créer » apparaissent ici : leur envoi au RH, puis la première connexion de chaque employé.</p>
                </div>
            </div>

            <!-- ============ SANS ACCÈS ============ -->
            <div v-show="tab === 'waived'" role="tabpanel">
                <ul v-if="waived.length" class="divide-y divide-border">
                    <li v-for="row in waived" :key="row.key" class="flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center">
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-full text-xs font-bold opacity-70', avatarTone(row.uuid))" aria-hidden="true">{{ initialsOf({ name: row.name }) }}</span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-foreground">{{ row.name }}</p>
                                <p class="truncate text-xs text-muted-foreground">{{ [row.job_title, row.site_name].filter(Boolean).join(' · ') }}</p>
                                <p class="mt-0.5 text-[11px] text-muted-foreground/80">« {{ row.waived_reason }} » — {{ row.waived_by ?? 'Super Admin' }}, le {{ formatDate(row.waived_at) }}</p>
                            </div>
                        </div>
                        <Button type="button" size="sm" variant="white-outline" :disabled="!can.create" @click="unwaive(row)">
                            <Undo2 class="h-4 w-4" aria-hidden="true" />Remettre dans « À créer »
                        </Button>
                    </li>
                </ul>
                <div v-else class="flex flex-col items-center gap-2 px-6 py-16 text-center">
                    <span class="grid h-12 w-12 place-items-center rounded-full bg-muted text-muted-foreground"><Users class="h-5 w-5" aria-hidden="true" /></span>
                    <p class="text-sm font-semibold text-foreground">Aucun employé sans accès</p>
                    <p class="max-w-sm text-xs text-muted-foreground">Un employé qui n’a besoin ni de RIVO ni d’une adresse (entretien, gardiennage…) se range ici, sans rien effacer.</p>
                </div>
            </div>
        </section>
    </div>

    <StaffAccessGrantDialog
        v-model:open="grantOpen"
        :employees="grantEmployees"
        :roles-by-site="rolesBySite"
        :receivers-by-site="receiversBySite"
        :accounts-by-site="accountsBySite"
        :can-designate="Boolean(can.designate)"
        :domain="hosting.domain"
        :activation-days="activationDays"
        @finished="onFinished"
    />

    <ConfirmModal
        :open="Boolean(waiveTarget)"
        :title="waiveTarget ? `Aucun accès pour ${waiveTarget.name} ?` : ''"
        description="Il quitte « À créer ». Rien n’est effacé : vous pourrez le remettre à tout moment."
        confirm-label="Aucun accès nécessaire"
        tone="warning"
        :icon="UserMinus"
        :processing="waiving"
        :disabled="waiveReason.trim().length < 3"
        @update:open="(value) => { if (!value) waiveTarget = null; }"
        @confirm="waive"
    >
        <FormField label="Pourquoi ?" required :error="waiveError">
            <Textarea v-model="waiveReason" rows="2" placeholder="Ex. agent d’entretien, n’utilise pas l’ordinateur" />
        </FormField>
    </ConfirmModal>
</template>
