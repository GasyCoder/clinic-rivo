<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    CalendarPlus,
    CheckCircle2,
    Eye,
    Filter,
    FolderOpen,
    LayoutGrid,
    List,
    Pencil,
    Phone,
    RotateCcw,
    Search,
    Trash2,
    UserPlus,
    Users,
    WalletCards,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Shadcn/Avatar.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import CheckBox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime, formatRelativeTime } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({
    layout: AppLayout,
});

const props = defineProps({
    patients: Object,
    search: String,
    filters: Object,
    summary: Object,
});

const { can } = usePermissions();
const canViewPatient = computed(() => can('patients.view'));
const canUpdatePatient = computed(() => can('patients.update'));
const canDeletePatient = computed(() => can('patients.delete'));

const query = ref(props.search ?? '');
const typeFilter = ref(props.filters?.type ?? '');
const emergencyFilter = ref(props.filters?.emergency ?? '');
const storedViewMode = (() => {
    try {
        return localStorage.getItem('rivo:patients:view');
    } catch {
        return null;
    }
})();
const viewMode = ref(storedViewMode ?? 'list');
const selectedUuids = ref([]);
const deleteTargets = ref([]);
const deleteReason = ref('');
const deleteError = ref('');
const deleteProcessing = ref(false);

const currentPageUuids = computed(() => props.patients.data.map((patient) => patient.uuid));
const allCurrentSelected = computed(() => currentPageUuids.value.length > 0
    && currentPageUuids.value.every((uuid) => selectedUuids.value.includes(uuid))
);

const submitFilters = () => {
    selectedUuids.value = [];
    router.get('/patients', {
        q: query.value || undefined,
        type: typeFilter.value || undefined,
        emergency: emergencyFilter.value || undefined,
    }, { preserveState: true, replace: true });
};

const TYPE_LABELS = {
    STANDARD: 'Standard',
    MUTUAL: 'Mutuelle',
    STAFF: 'Personnel',
};
const EMERGENCY_LABELS = {
    active: 'Urgence en cours',
    none: 'Sans urgence',
};
const typeOptions = [
    { value: '', label: 'Tous les types' },
    { value: 'STANDARD', label: 'Standard' },
    { value: 'MUTUAL', label: 'Mutuelle' },
    { value: 'STAFF', label: 'Personnel' },
];
const emergencyOptions = [
    { value: '', label: 'Toutes les situations' },
    { value: 'active', label: 'Urgence en cours' },
    { value: 'none', label: 'Sans urgence' },
];

const activeFilters = computed(() => {
    const chips = [];

    if (query.value) {
        chips.push({ key: 'q', label: `« ${query.value} »` });
    }
    if (typeFilter.value) {
        chips.push({ key: 'type', label: TYPE_LABELS[typeFilter.value] ?? typeFilter.value });
    }
    if (emergencyFilter.value) {
        chips.push({ key: 'emergency', label: EMERGENCY_LABELS[emergencyFilter.value] ?? emergencyFilter.value });
    }

    return chips;
});

const clearFilter = (key) => {
    if (key === 'q') query.value = '';
    if (key === 'type') typeFilter.value = '';
    if (key === 'emergency') emergencyFilter.value = '';

    submitFilters();
};

const resetFilters = () => {
    query.value = '';
    typeFilter.value = '';
    emergencyFilter.value = '';
    submitFilters();
};

const updateTypeFilter = (value) => {
    typeFilter.value = value;
    submitFilters();
};

const updateEmergencyFilter = (value) => {
    emergencyFilter.value = value;
    submitFilters();
};

/** Jumps from the "Urgence en cours" counter straight into its own filter. */
const focusEmergencies = () => {
    emergencyFilter.value = 'active';
    submitFilters();
};

const setViewMode = (mode) => {
    viewMode.value = mode;
    try {
        localStorage.setItem('rivo:patients:view', mode);
    } catch {
        // Private browsing or storage disabled — the toggle still works
        // for this visit, it just won't be remembered next time.
    }
};

const toggleCurrentPage = (checked) => {
    if (checked) {
        selectedUuids.value = [...new Set([...selectedUuids.value, ...currentPageUuids.value])];
        return;
    }

    selectedUuids.value = selectedUuids.value.filter((uuid) => !currentPageUuids.value.includes(uuid));
};

const togglePatient = (uuid, checked) => {
    selectedUuids.value = checked
        ? [...new Set([...selectedUuids.value, uuid])]
        : selectedUuids.value.filter((selectedUuid) => selectedUuid !== uuid);
};

const openDeleteDialog = (patients) => {
    if (!canDeletePatient.value) {
        return;
    }

    deleteTargets.value = patients;
    deleteReason.value = '';
    deleteError.value = '';
};

const closeDeleteDialog = () => {
    if (deleteProcessing.value) {
        return;
    }

    deleteTargets.value = [];
    deleteReason.value = '';
    deleteError.value = '';
};

const handleDeleteDialogOpen = (open) => {
    if (!open) {
        closeDeleteDialog();
    }
};

const openBulkDeleteDialog = () => {
    openDeleteDialog(props.patients.data.filter((patient) => selectedUuids.value.includes(patient.uuid)));
};

const confirmDelete = () => {
    const reason = deleteReason.value.trim();

    if (!reason) {
        deleteError.value = 'Indiquez le motif de la suppression.';
        return;
    }

    deleteError.value = '';
    const targets = [...deleteTargets.value];
    const targetUuids = targets.map((patient) => patient.uuid);
    const options = {
        preserveScroll: true,
        onStart: () => {
            deleteProcessing.value = true;
        },
        onSuccess: () => {
            selectedUuids.value = selectedUuids.value.filter((uuid) => !targetUuids.includes(uuid));
            deleteTargets.value = [];
            deleteReason.value = '';
        },
        onFinish: () => {
            deleteProcessing.value = false;
        },
    };

    if (targets.length === 1) {
        router.delete(`/patients/${targets[0].uuid}`, {
            ...options,
            data: { reason },
        });
        return;
    }

    router.post('/patients/bulk-delete', {
        patient_uuids: targetUuids,
        reason,
    }, options);
};

const SEX_LABELS = { M: 'Homme', F: 'Femme' };
const sexLabel = (patient) => SEX_LABELS[patient.sex] ?? 'Sexe non renseigné';
const patientTypeLabel = (patient) => TYPE_LABELS[patient.patient_type] ?? 'Standard';

/**
 * "Femme · 35 ans" — sex and age are read together in practice, so they
 * share one column instead of two nearly empty ones.
 */
const patientProfile = (patient) => {
    const age = patient.age ?? patient.declared_age;
    const ageLabel = age !== null && age !== undefined && age !== ''
        ? `${age} an${age > 1 ? 's' : ''}`
        : 'âge non renseigné';

    return `${sexLabel(patient)} · ${ageLabel}`;
};

const ageIsDeclared = (patient) => !patient.birth_date && (patient.declared_age ?? null) !== null;

/**
 * Quatre états mutuellement exclusifs, du plus au moins urgent.
 *
 * « Passage en cours » ne dit qu'une chose : un passage est ouvert — jamais
 * une appréciation clinique du patient. Il couvrait aussi le passage dont
 * Médecine avait déjà conclu, si bien qu'un médecin venant de clôturer
 * relisait ici « en cours » et croyait à une contradiction. Ce cas porte
 * désormais son propre libellé : la partie clinique est finie, c'est la
 * Réception qui doit prononcer la sortie.
 *
 * Un patient peut avoir plusieurs passages : celui encore en soins prime sur
 * celui qui n'attend qu'un règlement.
 */
const presenceState = (patient) => {
    if (patient.active_emergency_episodes_count > 0) {
        return {
            label: 'Urgence',
            variant: 'destructive',
            dot: 'bg-red-500',
        };
    }

    const awaitingSettlement = patient.settlement_episodes_count ?? 0;

    if ((patient.open_episodes_count ?? 0) - awaitingSettlement > 0) {
        return {
            label: 'Passage en cours',
            variant: 'warning',
            dot: 'bg-amber-500',
        };
    }

    if (awaitingSettlement > 0) {
        return {
            label: 'En attente de règlement',
            variant: 'secondary',
            dot: 'bg-sky-500',
        };
    }

    return {
        label: 'Aucun passage ouvert',
        variant: 'outline',
        dot: 'bg-slate-300 dark:bg-slate-600',
    };
};

const isEmergency = (patient) => patient.active_emergency_episodes_count > 0;
const rowAccent = (patient) => (isEmergency(patient)
    ? 'border-l-2 border-l-red-500'
    : 'border-l-2 border-l-transparent');

const lastVisitLabel = (patient) => (patient.last_visit_at
    ? formatRelativeTime(patient.last_visit_at)
    : 'Aucune visite');
const lastVisitTitle = (patient) => (patient.last_visit_at
    ? formatDateTime(patient.last_visit_at)
    : 'Ce dossier n’a encore aucun passage enregistré');
const visitCountLabel = (patient) => {
    const count = patient.episodes_count ?? 0;

    return count > 0 ? `${count} passage${count > 1 ? 's' : ''}` : null;
};

const summaryTiles = computed(() => [
    {
        key: 'total',
        label: 'Dossiers patients',
        value: props.summary?.total ?? props.patients.total,
        icon: Users,
        tone: 'bg-secondary text-secondary-foreground',
    },
    {
        key: 'emergency',
        label: 'Urgence en cours',
        value: props.summary?.emergency ?? 0,
        icon: AlertTriangle,
        tone: 'bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-300',
        action: (props.summary?.emergency ?? 0) > 0 ? focusEmergencies : null,
    },
    {
        key: 'in_progress',
        label: 'Passages en cours',
        value: props.summary?.in_progress ?? 0,
        icon: Activity,
        tone: 'bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-300',
    },
    {
        key: 'created_this_month',
        label: 'Nouveaux ce mois',
        value: props.summary?.created_this_month ?? 0,
        icon: CalendarPlus,
        tone: 'bg-accent text-accent-foreground',
    },
]);

const rangeLabel = computed(() => {
    const { from, to, total } = props.patients;

    if (!total) {
        return 'Aucun résultat';
    }

    return `${from}–${to} sur ${total}`;
});

watch(
    () => currentPageUuids.value.join(','),
    () => {
        selectedUuids.value = selectedUuids.value.filter((uuid) => currentPageUuids.value.includes(uuid));
    },
);
</script>

<template>
    <Head title="Patients" />

    <div class="mx-auto w-full max-w-[1600px] space-y-5 pb-8 text-foreground">
        <header class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-start gap-3.5">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary text-primary-foreground shadow-sm">
                    <Users class="h-5 w-5" />
                </span>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="font-heading text-2xl font-bold tracking-tight text-foreground">Patients</h1>
                        <Badge variant="secondary">Répertoire clinique</Badge>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Retrouvez l’identité permanente du patient et l’état de son passage actuel.
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button v-if="can('cash.view')" :as="Link" href="/cash" variant="outline">
                    <WalletCards class="h-4 w-4" />
                    Caisse
                </Button>
                <Button v-if="can('episodes.create')" :as="Link" href="/reception/patients/type">
                    <UserPlus class="h-4 w-4" />
                    Nouvelle arrivée
                </Button>
            </div>
        </header>

        <section class="grid grid-cols-2 gap-3 xl:grid-cols-4" aria-label="Indicateurs des patients">
            <Card
                v-for="tile in summaryTiles"
                :key="tile.key"
                :class="[
                    'relative overflow-hidden p-4 transition-all sm:p-5',
                    tile.action && 'hover:-translate-y-0.5 hover:border-red-200 hover:shadow-md dark:hover:border-red-900',
                ]"
            >
                <button
                    v-if="tile.action"
                    type="button"
                    class="absolute inset-0 z-10 rounded-xl focus:outline-none focus:ring-2 focus:ring-inset focus:ring-ring"
                    :aria-label="`Afficher : ${tile.label}`"
                    @click="tile.action()"
                ></button>
                <div class="pointer-events-none relative flex items-start justify-between gap-3">
                    <div>
                        <p class="text-2xl font-bold tracking-tight text-foreground">{{ tile.value }}</p>
                        <p class="mt-1 text-xs font-medium text-muted-foreground sm:text-sm">{{ tile.label }}</p>
                        <span v-if="tile.action" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-red-600 dark:text-red-300">
                            Voir les urgences <ArrowRight class="h-3 w-3" />
                        </span>
                    </div>
                    <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-lg', tile.tone]">
                        <component :is="tile.icon" class="h-5 w-5" />
                    </span>
                </div>
            </Card>
        </section>

        <Card class="overflow-visible">
            <div class="space-y-4 p-4 sm:p-5">
                <div class="flex flex-col gap-3 xl:flex-row xl:items-center">
                    <form class="relative min-w-0 flex-1 xl:max-w-xl" role="search" @submit.prevent="submitFilters">
                        <Search class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            v-model="query"
                            class="ps-9 pe-20"
                            type="search"
                            placeholder="Nom, numéro patient ou téléphone…"
                            autocomplete="off"
                            aria-label="Rechercher un patient"
                        />
                        <button type="submit" class="absolute end-1.5 top-1/2 -translate-y-1/2 rounded-md px-2.5 py-1 text-xs font-semibold text-primary hover:bg-accent">
                            Rechercher
                        </button>
                    </form>

                    <div class="flex flex-col gap-2 sm:flex-row">
                        <Select
                            :model-value="typeFilter"
                            :options="typeOptions"
                            aria-label="Filtrer par type de dossier"
                            @update:model-value="updateTypeFilter"
                        />
                        <Select
                            :model-value="emergencyFilter"
                            :options="emergencyOptions"
                            aria-label="Filtrer par situation"
                            @update:model-value="updateEmergencyFilter"
                        />
                    </div>

                    <div class="flex items-center justify-between gap-3 xl:justify-end">
                        <span class="whitespace-nowrap text-xs font-medium text-muted-foreground">{{ rangeLabel }}</span>
                        <div class="inline-flex rounded-lg border border-border bg-muted/50 p-1" role="group" aria-label="Mode d’affichage">
                            <Button
                                size="icon"
                                variant="ghost"
                                :class="viewMode === 'list' && 'bg-card text-foreground shadow-sm hover:bg-card'"
                                type="button"
                                aria-label="Vue liste"
                                :aria-pressed="viewMode === 'list'"
                                @click="setViewMode('list')"
                            ><List class="h-4 w-4" /></Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                :class="viewMode === 'grid' && 'bg-card text-foreground shadow-sm hover:bg-card'"
                                type="button"
                                aria-label="Vue grille"
                                :aria-pressed="viewMode === 'grid'"
                                @click="setViewMode('grid')"
                            ><LayoutGrid class="h-4 w-4" /></Button>
                        </div>
                    </div>
                </div>

                <div v-if="activeFilters.length" class="flex flex-wrap items-center gap-2 border-t border-border pt-4">
                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground">
                        <Filter class="h-3.5 w-3.5" /> Filtres actifs
                    </span>
                    <button
                        v-for="chip in activeFilters"
                        :key="chip.key"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border border-border bg-secondary px-2.5 py-1 text-xs font-medium text-secondary-foreground transition-colors hover:border-red-200 hover:bg-red-50 hover:text-red-700 dark:hover:border-red-900 dark:hover:bg-red-950/40 dark:hover:text-red-300"
                        :title="`Retirer le filtre ${chip.label}`"
                        @click="clearFilter(chip.key)"
                    >
                        {{ chip.label }} <X class="h-3 w-3" />
                    </button>
                    <Button size="sm" variant="ghost" type="button" class="h-7 px-2 text-xs" @click="resetFilters">
                        <RotateCcw class="h-3 w-3" /> Tout effacer
                    </Button>
                </div>

                <div v-if="canDeletePatient && selectedUuids.length" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary/20 bg-accent px-3.5 py-3">
                    <span class="inline-flex items-center gap-2 text-sm font-semibold text-accent-foreground">
                        <CheckCircle2 class="h-4 w-4" />
                        {{ selectedUuids.length }} dossier{{ selectedUuids.length > 1 ? 's' : '' }} sélectionné{{ selectedUuids.length > 1 ? 's' : '' }}
                    </span>
                    <div class="flex items-center gap-2">
                        <Button size="sm" variant="ghost" type="button" @click="selectedUuids = []">Annuler</Button>
                        <Button size="sm" variant="destructive" type="button" @click="openBulkDeleteDialog">
                            <Trash2 class="h-4 w-4" /> Archiver
                        </Button>
                    </div>
                </div>
            </div>
        </Card>

        <Card class="overflow-hidden">
            <div class="flex items-center justify-between gap-3 border-b border-border px-4 py-3.5 sm:px-5">
                <div>
                    <h2 class="text-sm font-bold text-foreground">Dossiers patients</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">Un dossier permanent peut contenir plusieurs passages.</p>
                </div>
                <Badge variant="outline">{{ rangeLabel }}</Badge>
            </div>

            <div v-if="viewMode === 'list'" class="overflow-x-auto">
                <table class="w-full min-w-[980px] border-collapse">
                    <caption class="sr-only">Liste des patients</caption>
                    <thead>
                        <tr class="border-b border-border bg-muted/55">
                            <th v-if="canDeletePatient" class="w-12 px-4 py-3 text-center">
                                <CheckBox
                                    id="patients-select-all"
                                    :model-value="allCurrentSelected"
                                    aria-label="Sélectionner tous les patients de cette page"
                                    @update:model-value="toggleCurrentPage"
                                />
                            </th>
                            <th class="px-5 py-3 text-start text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Patient</th>
                            <th class="px-5 py-3 text-start text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Catégorie</th>
                            <th class="px-5 py-3 text-start text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Identité</th>
                            <th class="px-5 py-3 text-start text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Activité</th>
                            <th class="px-5 py-3 text-start text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Situation actuelle</th>
                            <th class="px-5 py-3 text-end text-[11px] font-bold uppercase tracking-wider text-muted-foreground"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="patient in patients.data"
                            :key="patient.uuid"
                            :class="[
                                'group transition-colors',
                                isEmergency(patient) ? 'bg-red-50/45 hover:bg-red-50/75 dark:bg-red-950/10 dark:hover:bg-red-950/20' : 'hover:bg-muted/45',
                            ]"
                        >
                            <td v-if="canDeletePatient" :class="['px-4 py-3.5 text-center', rowAccent(patient)]">
                                <CheckBox
                                    :id="`patient-${patient.uuid}`"
                                    :model-value="selectedUuids.includes(patient.uuid)"
                                    :aria-label="`Sélectionner ${formatPatientName(patient)}`"
                                    @update:model-value="togglePatient(patient.uuid, $event)"
                                />
                            </td>
                            <td :class="['px-5 py-3.5', canDeletePatient ? '' : rowAccent(patient)]">
                                <div class="flex min-w-[250px] items-center gap-3">
                                    <Avatar :initials="formatPatientInitials(patient)" :emergency="isEmergency(patient)" aria-hidden="true" />
                                    <div class="min-w-0">
                                        <Link v-if="canViewPatient" :href="`/patients/${patient.uuid}`" class="block truncate text-sm font-bold text-foreground hover:text-primary">
                                            {{ formatPatientName(patient) }}
                                        </Link>
                                        <span v-else class="block truncate text-sm font-bold text-foreground">{{ formatPatientName(patient) }}</span>
                                        <span class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                            <span class="inline-flex items-center gap-1.5 font-medium"><FolderOpen class="h-3.5 w-3.5" />{{ patient.patient_number }}</span>
                                            <a v-if="patient.phone" :href="`tel:${patient.phone}`" class="inline-flex items-center gap-1.5 hover:text-primary"><Phone class="h-3.5 w-3.5" />{{ patient.phone }}</a>
                                            <span v-else class="inline-flex items-center gap-1.5 italic"><Phone class="h-3.5 w-3.5" />Non renseigné</span>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3.5"><Badge variant="secondary" :title="patient.patient_type_label">{{ patientTypeLabel(patient) }}</Badge></td>
                            <td class="px-5 py-3.5">
                                <p class="text-sm font-medium text-foreground">{{ patientProfile(patient) }}</p>
                                <p v-if="ageIsDeclared(patient)" class="mt-1 text-xs text-muted-foreground">Âge déclaré</p>
                            </td>
                            <td class="px-5 py-3.5">
                                <p class="text-sm font-medium text-foreground" :title="lastVisitTitle(patient)">{{ lastVisitLabel(patient) }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">{{ visitCountLabel(patient) ?? 'Aucun passage' }}</p>
                            </td>
                            <td class="px-5 py-3.5">
                                <Badge :variant="presenceState(patient).variant">
                                    <span :class="['h-1.5 w-1.5 rounded-full', presenceState(patient).dot]"></span>
                                    {{ presenceState(patient).label }}
                                </Badge>
                            </td>
                            <td class="px-5 py-3.5 text-end">
                                <div class="inline-flex items-center gap-1">
                                    <Button v-if="canViewPatient" :as="Link" :href="`/patients/${patient.uuid}`" size="sm" variant="outline" :aria-label="`Ouvrir le dossier de ${formatPatientName(patient)}`">
                                        <Eye class="h-4 w-4" /> Ouvrir
                                    </Button>
                                    <Button v-if="canUpdatePatient && patient.patient_type !== 'STAFF'" :as="Link" :href="`/patients/${patient.uuid}/edit`" size="icon" variant="ghost" :aria-label="`Modifier ${formatPatientName(patient)}`" title="Modifier le dossier">
                                        <Pencil class="h-4 w-4" />
                                    </Button>
                                    <Button v-if="canDeletePatient" size="icon" variant="ghost" class="text-muted-foreground hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-950/40 dark:hover:text-red-300" type="button" :aria-label="`Archiver ${formatPatientName(patient)}`" title="Archiver le dossier" @click="openDeleteDialog([patient])">
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else-if="patients.data.length" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                <article
                    v-for="patient in patients.data"
                    :key="patient.uuid"
                    :class="[
                        'flex min-h-64 flex-col rounded-xl border p-4 transition-all hover:-translate-y-0.5 hover:shadow-md',
                        isEmergency(patient) ? 'border-red-200 bg-red-50/40 dark:border-red-900 dark:bg-red-950/10' : 'border-border bg-card',
                    ]"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <Avatar :initials="formatPatientInitials(patient)" :emergency="isEmergency(patient)" />
                            <div class="min-w-0">
                                <Link v-if="canViewPatient" :href="`/patients/${patient.uuid}`" class="block truncate text-sm font-bold text-foreground hover:text-primary">{{ formatPatientName(patient) }}</Link>
                                <span v-else class="block truncate text-sm font-bold text-foreground">{{ formatPatientName(patient) }}</span>
                                <span class="mt-1 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><FolderOpen class="h-3.5 w-3.5" />{{ patient.patient_number }}</span>
                            </div>
                        </div>
                        <CheckBox v-if="canDeletePatient" :id="`patient-grid-${patient.uuid}`" :model-value="selectedUuids.includes(patient.uuid)" :aria-label="`Sélectionner ${formatPatientName(patient)}`" @update:model-value="togglePatient(patient.uuid, $event)" />
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <Badge :variant="presenceState(patient).variant"><span :class="['h-1.5 w-1.5 rounded-full', presenceState(patient).dot]"></span>{{ presenceState(patient).label }}</Badge>
                        <Badge variant="secondary">{{ patientTypeLabel(patient) }}</Badge>
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-3 text-xs">
                        <div class="col-span-2 rounded-lg bg-muted/55 p-3">
                            <dt class="font-medium text-muted-foreground">Identité</dt>
                            <dd class="mt-1 font-semibold text-foreground">{{ patientProfile(patient) }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-muted-foreground">Dernière visite</dt>
                            <dd class="mt-1 font-semibold text-foreground" :title="lastVisitTitle(patient)">{{ lastVisitLabel(patient) }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-muted-foreground">Téléphone</dt>
                            <dd class="mt-1 truncate font-semibold text-foreground">
                                <a v-if="patient.phone" :href="`tel:${patient.phone}`" class="hover:text-primary">{{ patient.phone }}</a>
                                <span v-else class="italic text-muted-foreground">Non renseigné</span>
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-auto flex items-center justify-between gap-2 border-t border-border pt-4">
                        <Button v-if="canViewPatient" :as="Link" :href="`/patients/${patient.uuid}`" size="sm" variant="link" class="h-auto p-0">
                            Ouvrir le dossier <ArrowRight class="h-3.5 w-3.5" />
                        </Button>
                        <span v-else></span>
                        <div class="flex gap-1">
                            <Button v-if="canUpdatePatient && patient.patient_type !== 'STAFF'" :as="Link" :href="`/patients/${patient.uuid}/edit`" size="icon" variant="ghost" :aria-label="`Modifier ${formatPatientName(patient)}`"><Pencil class="h-4 w-4" /></Button>
                            <Button v-if="canDeletePatient" size="icon" variant="ghost" class="text-muted-foreground hover:text-red-600" type="button" :aria-label="`Archiver ${formatPatientName(patient)}`" @click="openDeleteDialog([patient])"><Trash2 class="h-4 w-4" /></Button>
                        </div>
                    </div>
                </article>
            </div>

            <div v-if="patients.data.length === 0" class="px-6 py-16 text-center">
                <span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-muted text-muted-foreground"><Users class="h-6 w-6" /></span>
                <h3 class="mt-4 text-sm font-bold text-foreground">Aucun patient trouvé</h3>
                <p class="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">Modifiez votre recherche ou commencez une nouvelle prise en charge.</p>
                <Button v-if="activeFilters.length" class="mt-5" size="sm" variant="outline" type="button" @click="resetFilters">
                    <RotateCcw class="h-4 w-4" /> Réinitialiser les filtres
                </Button>
            </div>

            <div v-if="patients.last_page > 1" class="flex flex-col gap-3 border-t border-border bg-muted/25 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                <span class="text-xs text-muted-foreground">{{ rangeLabel }} · page {{ patients.current_page }} sur {{ patients.last_page }}</span>
                <nav class="flex flex-wrap items-center gap-1" aria-label="Pagination des patients">
                    <template v-for="(link, index) in patients.links" :key="index">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-state
                            :class="[
                                'inline-flex min-h-8 min-w-8 items-center justify-center rounded-md px-2.5 text-sm font-medium transition-colors',
                                link.active ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                            ]"
                            v-html="link.label"
                        />
                        <span v-else class="inline-flex min-h-8 min-w-8 items-center justify-center rounded-md px-2.5 text-sm text-muted-foreground/40" v-html="link.label" />
                    </template>
                </nav>
            </div>
        </Card>

        <Dialog
            :open="deleteTargets.length > 0"
            :title="deleteTargets.length > 1 ? `Archiver ${deleteTargets.length} dossiers ?` : 'Archiver ce dossier patient ?'"
            description="Le dossier sera placé dans la corbeille par Soft Delete. Les données cliniques ne seront jamais supprimées définitivement."
            @update:open="handleDeleteDialogOpen"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-300"><Trash2 class="h-5 w-5" /></span>
            </template>

            <ul class="max-h-36 space-y-1 overflow-y-auto rounded-lg border border-border bg-muted/30 p-2">
                <li v-for="target in deleteTargets" :key="target.uuid" class="flex items-center justify-between gap-3 rounded-md px-2.5 py-2 text-sm">
                    <span class="truncate font-semibold text-foreground">{{ formatPatientName(target) }}</span>
                    <span class="shrink-0 text-xs text-muted-foreground">{{ target.patient_number }}</span>
                </li>
            </ul>

            <div class="mt-4 space-y-2">
                <label for="delete_reason" class="text-sm font-semibold text-foreground">Motif de l’archivage <span class="text-red-500">*</span></label>
                <textarea
                    id="delete_reason"
                    v-model="deleteReason"
                    rows="3"
                    autofocus
                    placeholder="Ex. dossier créé en double"
                    class="flex w-full resize-y rounded-lg border border-input bg-card px-3 py-2 text-sm text-foreground shadow-sm outline-none transition-colors placeholder:text-muted-foreground focus:border-red-400 focus:ring-2 focus:ring-red-100 dark:focus:ring-red-950"
                    @input="deleteError = ''"
                ></textarea>
                <p v-if="deleteError" class="flex items-center gap-1.5 text-xs font-medium text-red-600"><AlertTriangle class="h-3.5 w-3.5" />{{ deleteError }}</p>
            </div>

            <template #footer>
                <Button variant="outline" type="button" :disabled="deleteProcessing" @click="closeDeleteDialog">Annuler</Button>
                <Button variant="destructive" type="button" :disabled="deleteProcessing" @click="confirmDelete">
                    <Trash2 class="h-4 w-4" />
                    {{ deleteProcessing ? 'Archivage…' : 'Confirmer l’archivage' }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
