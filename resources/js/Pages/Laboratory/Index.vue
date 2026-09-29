<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import LabBulkReport from '@/Components/Laboratory/LabBulkReport.vue';
import LabTrashDialog from '@/Components/Laboratory/LabTrashDialog.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import DropdownMenu from '@/Components/Shadcn/DropdownMenu.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import {
    AlertTriangle, Archive, ArchiveRestore, BadgeCheck, BarChart3, Building2, CalendarCheck, ClipboardList, Ellipsis, FlaskConical, List, Microscope, Play, RotateCcw, ScanBarcode, Search, Siren, TestTube, TestTubes, Trash2, UserRound, X,
} from 'lucide-vue-next';
import { formatDateTime, formatRelativeTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';
import { labRowButton } from '@/utilities/labRowActions';
import { LAB_STATE_LABELS, LAB_STATE_TONES, LAB_STATUS_TONES, LAB_VIEWS, rowAction } from '@/utilities/labWorkbench';
import { paymentBadge } from '@/utilities/labReception';
import { labUrl } from '@/utilities/labUrl';
import { headerCheckState, rowMenuItems, selectionActions, toggleSelection } from '@/utilities/labSelection';

defineOptions({ layout: AppLayout });

/**
 * ADR-213 / ADR-214 — la file du Laboratoire, par demande : chaque demande est
 * dans une seule vue, comptée par le serveur, jamais depuis la page affichée.
 * Les analyses confiées à l'extérieur sont un filtre, pas une vue.
 *
 * ADR-217 — comme dans labo-vuejs, chaque ligne porte son geste : « Traiter »
 * prend la demande en charge et ouvre la paillasse, quel que soit le règlement,
 * qui ne se lit plus que pour information.
 */
const props = defineProps({
    requests: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    view: { type: String, default: 'to_do' },
    search: { type: String, default: '' },
    sentOut: { type: Boolean, default: false },
    canStart: { type: Boolean, default: false },
    manage: { type: Object, default: () => ({ archive: false, trash: false, max: 50 }) },
});

const page = usePage();
const can = (permission) => (page.props.permissions ?? []).includes(permission);

const ICONS = { to_do: FlaskConical, to_redo: RotateCcw, to_validate: BadgeCheck, validated: CalendarCheck, all: List, archived: Archive };

const tiles = computed(() => LAB_VIEWS.map((view) => ({
    ...view,
    icon: ICONS[view.value],
    count: props.counts[view.value] ?? 0,
    active: props.view === view.value,
})));

const query = ref(props.search);
let timer = null;
const visit = ({ view = props.view, q = query.value, externe = props.sentOut } = {}) => router.get(labUrl('/laboratory'), {
    view,
    ...(q ? { q } : {}),
    ...(externe ? { externe: 1 } : {}),
}, { preserveScroll: true, preserveState: true, replace: true });
const selectView = (view) => visit({ view });
const toggleSentOut = () => visit({ externe: !props.sentOut });
watch(query, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => visit({ q: value }), 350);
});
// Un code-barres scanné finit par Entrée : on cherche tout de suite, le serveur ouvre la demande.
const searchNow = () => { clearTimeout(timer); visit({ q: query.value }); };

const actionOf = (request) => labRowButton(rowAction(request, props.canStart));
const openUrl = (request) => labUrl(`/laboratory/requests/${request.uuid}`);
const starting = ref(null);
// « Traiter » : la demande est prise en charge, puis la paillasse s'ouvre (le serveur redirige).
const start = (request) => {
    starting.value = request.uuid;
    router.post(labUrl(`/laboratory/requests/${request.uuid}/start`), {}, { onFinish: () => { starting.value = null; } });
};

const pages = computed(() => props.requests?.links ?? []);
// Les libellés de Laravel portent des entités (« &laquo; Précédent ») : on les lit en texte, jamais en HTML.
const pageLabel = (label) => String(label).replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/&amp;/g, '&');

// ADR-220 — la sélection multiple : archiver, désarchiver, mettre à la corbeille.
const selecting = computed(() => Boolean(props.manage?.archive || props.manage?.trash));
const selected = ref([]);
watch(() => (props.requests?.data ?? []).map((row) => row.uuid).join(','), () => { selected.value = []; });
const rows = computed(() => props.requests?.data ?? []);
const headerState = computed(() => headerCheckState(rows.value, selected.value));
const actions = computed(() => selectionActions(rows.value, selected.value));
const isSelected = (uuid) => selected.value.includes(uuid);
const toggle = (uuid) => { selected.value = toggleSelection(selected.value, uuid, props.manage?.max ?? 50); };
const toggleAll = () => {
    selected.value = headerState.value === true ? [] : rows.value.slice(0, props.manage?.max ?? 50).map((row) => row.uuid);
};

const busy = ref(false);
const runBulk = (action, uuids, reason = null) => {
    if (!uuids.length) return;
    busy.value = true;
    router.post(labUrl('/laboratory/requests/bulk'), { action, uuids, ...(reason ? { reason } : {}) }, {
        preserveScroll: true,
        onSuccess: () => { selected.value = []; trashTarget.value = null; },
        onFinish: () => { busy.value = false; },
    });
};

// La corbeille : une ligne, ou la sélection.
const trashTarget = ref(null);
const trashError = ref('');
const openTrash = (uuids, refused = 0) => { trashError.value = ''; trashTarget.value = { uuids, refused }; };
const confirmTrash = (reason) => {
    if (!trashTarget.value) return;
    router.post(labUrl('/laboratory/requests/bulk'), { action: 'trash', uuids: trashTarget.value.uuids, reason }, {
        preserveScroll: true,
        onStart: () => { busy.value = true; },
        onSuccess: () => { selected.value = []; trashTarget.value = null; },
        onError: (errors) => { trashError.value = errors.reason ?? errors.uuids ?? Object.values(errors)[0] ?? ''; },
        onFinish: () => { busy.value = false; },
    });
};

const onRowMenu = (request, key) => {
    if (key === 'trash') {
        openTrash([request.uuid]);
        return;
    }
    runBulk(key, [request.uuid]);
};

const emptyText = computed(() => ({
    to_do: 'Aucune demande à traiter : la paillasse est à jour.',
    to_redo: 'Aucune analyse renvoyée à refaire.',
    to_validate: 'Aucun résultat rendu n’attend d’être envoyé.',
    validated: 'Aucune demande entièrement envoyée au médecin pour l’instant.',
    all: 'Aucune demande d’analyses.',
    archived: 'Aucune demande archivée. Une demande dont tout est envoyé au médecin se range depuis « Envoyées ».',
}[props.view] ?? 'Aucune demande.'));
</script>

<template>
    <Head title="Laboratoire" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-4">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><TestTubes class="h-5 w-5" /></span>
                <div>
                    <h1 class="font-heading text-xl font-bold -tracking-snug text-foreground">Laboratoire</h1>
                    <p class="mt-0.5 text-sm text-muted-foreground">Analyses demandées — saisie des résultats, puis envoi au médecin.</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Badge variant="outline"><CalendarCheck class="h-3.5 w-3.5" /> {{ counts.validated_today ?? 0 }} envoyée(s) aujourd’hui</Badge>
                <Button :as="Link" :href="labUrl('/laboratory/paillasse')" variant="outline" size="sm">
                    <ClipboardList class="h-4 w-4" /> Feuille de paillasse
                </Button>
                <Button v-if="can('laboratory_reports.view')" :as="Link" :href="labUrl('/laboratory/rapports')" variant="outline" size="sm">
                    <BarChart3 class="h-4 w-4" /> Rapports
                </Button>
                <Button v-if="can('lab_sample_types.view')" :as="Link" :href="labUrl('/laboratory/prelevements')" variant="outline" size="sm">
                    <TestTube class="h-4 w-4" /> Prélèvements & tubes
                </Button>
                <Button v-if="can('lab_microbiology.view')" :as="Link" :href="labUrl('/laboratory/microbiologie')" variant="outline" size="sm">
                    <Microscope class="h-4 w-4" /> Germes & antibiotiques
                </Button>
            </div>
        </header>

        <!-- ADR-219 — une bande serrée : les cinq vues sur une ligne, la liste reste en vue. -->
        <QueueCounters :tiles="tiles" compact class="sm:grid-cols-3 lg:grid-cols-6" @select="selectView" />

        <LabBulkReport />

        <Card class="overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3">
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        size="xs"
                        :variant="sentOut ? 'secondary' : 'outline'"
                        :aria-pressed="sentOut"
                        class="rounded-full"
                        @click="toggleSentOut"
                    >
                        <Building2 class="h-3.5 w-3.5" /> Confiées à l’extérieur
                        <strong class="tabular-nums">{{ counts.sent_out ?? 0 }}</strong>
                        <X v-if="sentOut" class="h-3 w-3" />
                    </Button>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="view === 'validated' || view === 'all' || view === 'archived'">Les plus récentes d’abord.</template>
                        <template v-else>Les plus anciennes d’abord : elles attendent depuis le plus longtemps.</template>
                    </p>
                </div>
                <div class="w-full sm:w-80">
                    <IconInput
                        v-model="query"
                        :icon="query ? Search : ScanBarcode"
                        class="w-full"
                        placeholder="Patient, dossier ou n° de labo"
                        title="Recherchez un patient, un dossier ou un n° de laboratoire — ou scannez le code-barres d’un tube"
                        aria-label="Rechercher une demande ou scanner un code-barres"
                        @keydown.enter.prevent="searchNow"
                    />
                </div>
            </div>

            <!-- ADR-220 — la sélection : ce que chaque geste prendra, dit avant le clic. -->
            <div v-if="selecting && rows.length" :class="['flex flex-wrap items-center gap-2 border-b border-border px-4 py-2', selected.length ? 'sticky top-0 z-10 bg-primary/5 backdrop-blur' : 'bg-muted/30']">
                <label class="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                    <Checkbox :model-value="headerState" aria-label="Sélectionner toute la page" @update:model-value="toggleAll" />
                    <span v-if="selected.length" class="text-foreground"><strong class="tabular-nums">{{ selected.length }}</strong> sélectionnée{{ selected.length > 1 ? 's' : '' }}</span>
                    <span v-else>Tout sélectionner</span>
                </label>
                <template v-if="selected.length">
                    <span class="mx-1 h-4 w-px bg-border" aria-hidden="true" />
                    <Button
                        v-if="manage.archive && view !== 'archived'"
                        type="button"
                        size="xs"
                        variant="outline"
                        :disabled="busy || !actions.archive.length"
                        :title="actions.refused.archive ? `${actions.refused.archive} demande(s) ont encore du travail en cours : elles ne se rangent pas.` : undefined"
                        @click="runBulk('archive', actions.archive)"
                    >
                        <Archive class="h-3.5 w-3.5" /> Archiver <span class="tabular-nums opacity-70">{{ actions.archive.length }}</span>
                    </Button>
                    <Button v-if="manage.archive && actions.unarchive.length" type="button" size="xs" variant="outline" :disabled="busy" @click="runBulk('unarchive', actions.unarchive)">
                        <ArchiveRestore class="h-3.5 w-3.5" /> Désarchiver <span class="tabular-nums opacity-70">{{ actions.unarchive.length }}</span>
                    </Button>
                    <Button
                        v-if="manage.trash"
                        type="button"
                        size="xs"
                        variant="danger-outline"
                        :disabled="busy || !actions.trash.length"
                        :title="actions.refused.trash ? `${actions.refused.trash} demande(s) ont des résultats envoyés : elles ne partent pas.` : undefined"
                        @click="openTrash(actions.trash, actions.refused.trash)"
                    >
                        <Trash2 class="h-3.5 w-3.5" /> Corbeille <span class="tabular-nums opacity-70">{{ actions.trash.length }}</span>
                    </Button>
                    <Button type="button" size="xs" variant="ghost" class="ms-auto" @click="selected = []"><X class="h-3.5 w-3.5" /> Effacer la sélection</Button>
                </template>
            </div>

            <ul class="divide-y divide-border">
                <li
                    v-for="request in requests.data"
                    :key="request.uuid"
                    :class="[
                        'grid gap-3 px-4 py-3 transition-colors hover:bg-muted/40 lg:items-center',
                        selecting ? 'grid-cols-[auto_minmax(0,1fr)] lg:grid-cols-[auto_minmax(0,1fr)_auto]' : 'lg:grid-cols-[minmax(0,1fr)_auto]',
                        isSelected(request.uuid) ? 'bg-primary/5' : '',
                    ]"
                >
                    <div v-if="selecting" class="flex items-start pt-0.5 lg:items-center lg:pt-0">
                        <Checkbox :model-value="isSelected(request.uuid)" :aria-label="`Sélectionner la demande de ${formatPatientName(request.patient)}`" @update:model-value="toggle(request.uuid)" />
                    </div>
                    <Link
                        :href="openUrl(request)"
                        class="grid min-w-0 gap-3 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-ring md:grid-cols-[minmax(0,19rem)_minmax(0,1fr)_auto] md:items-center"
                    >
                        <div class="min-w-0">
                            <p class="flex items-center gap-2 truncate text-sm font-bold text-foreground">
                                <Siren v-if="request.emergency" class="h-4 w-4 shrink-0 text-destructive" aria-label="Urgence" />
                                {{ formatPatientName(request.patient) }}
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                <span v-if="request.lab_number" class="font-mono font-semibold text-foreground">{{ request.lab_number }} · </span>
                                {{ request.patient.patient_number }} · {{ request.episode_number }}
                                <template v-if="request.patient.age !== null"> · {{ request.patient.age }} ans</template>
                                <template v-if="request.patient.sex"> · {{ request.patient.sex === 'M' ? 'H' : 'F' }}</template>
                            </p>
                            <p class="mt-1 truncate text-[11px] text-muted-foreground" :title="`${request.origin} · ${formatDateTime(request.requested_at)}${request.requested_by ? ` · ${request.requested_by}` : ''}`">
                                {{ request.origin }} · {{ formatRelativeTime(request.requested_at) }}<template v-if="request.requested_by"> · {{ request.requested_by }}</template>
                            </p>
                            <p v-if="request.started_by" class="mt-0.5 flex items-center gap-1 text-[11px] text-muted-foreground">
                                <UserRound class="h-3 w-3" aria-hidden="true" /> Pris en charge par {{ request.started_by }}
                            </p>
                        </div>

                        <div class="flex min-w-0 flex-wrap gap-1.5">
                            <Badge v-for="item in request.items" :key="item.uuid" :tone="LAB_STATUS_TONES[item.status]" class="max-w-full" :title="item.external ? `Confiée à ${item.external}` : undefined">
                                <Building2 v-if="item.external" class="h-3 w-3 shrink-0" aria-label="Confiée à l’extérieur" />
                                <span class="truncate">{{ item.name }}</span>
                                <span class="font-normal opacity-80">· {{ item.status_label }}</span>
                            </Badge>
                        </div>

                        <div class="flex flex-wrap gap-1.5 md:justify-end">
                            <Badge v-if="paymentBadge(request.payment)" :tone="paymentBadge(request.payment).tone" :title="paymentBadge(request.payment).hint">{{ paymentBadge(request.payment).label }}</Badge>
                            <Badge v-if="request.samples_count" variant="outline"><TestTube class="h-3.5 w-3.5" /> {{ request.samples_count }}</Badge>
                            <Badge v-if="request.critical" tone="danger"><AlertTriangle class="h-3.5 w-3.5" /> {{ request.critical }} critique(s)</Badge>
                            <Badge v-if="request.pathological" tone="warning">{{ request.pathological }} pathologique(s)</Badge>
                            <Badge v-if="request.archived" variant="outline" :title="request.archived_at ? `Archivée le ${formatDateTime(request.archived_at)}` : undefined"><Archive class="h-3.5 w-3.5" /> Archivée</Badge>
                            <Badge v-else :tone="LAB_STATE_TONES[request.state]">{{ LAB_STATE_LABELS[request.state] }}</Badge>
                        </div>
                    </Link>

                    <!-- ADR-217 — le geste de la ligne ; ADR-220 — ranger, corbeille dans « … » -->
                    <div :class="['flex items-center justify-end gap-1.5', selecting ? 'col-span-2 lg:col-span-1' : '']">
                        <Button
                            v-if="actionOf(request).key === 'start'"
                            type="button"
                            size="sm"
                            class="w-full lg:w-auto lg:min-w-[7.5rem]"
                            :disabled="starting === request.uuid"
                            :aria-label="`Traiter la demande de ${formatPatientName(request.patient)}`"
                            @click="start(request)"
                        >
                            <Play class="h-4 w-4" /> Traiter
                        </Button>
                        <Button
                            v-else
                            :as="Link"
                            :href="openUrl(request)"
                            size="sm"
                            :variant="actionOf(request).variant"
                            class="w-full lg:w-auto lg:min-w-[7.5rem]"
                        >
                            <component :is="actionOf(request).icon" class="h-4 w-4" /> {{ actionOf(request).label }}
                        </Button>
                        <DropdownMenu v-if="rowMenuItems(request, manage).length" :items="rowMenuItems(request, manage)" label="Demande" @select="onRowMenu(request, $event)">
                            <template #trigger>
                                <Button type="button" size="icon" variant="ghost" :aria-label="`Plus d’actions pour la demande de ${formatPatientName(request.patient)}`" :disabled="busy">
                                    <Ellipsis class="h-4 w-4" />
                                </Button>
                            </template>
                        </DropdownMenu>
                    </div>
                </li>
                <li v-if="requests.data.length === 0" class="px-4 py-14 text-center">
                    <FlaskConical class="mx-auto h-8 w-8 text-muted-foreground/60" aria-hidden="true" />
                    <p class="mt-2 text-sm text-muted-foreground">{{ search ? 'Aucune demande ne correspond à la recherche.' : emptyText }}</p>
                </li>
            </ul>

            <nav v-if="pages.length > 3" class="flex flex-wrap items-center justify-between gap-3 border-t border-border p-4 text-xs text-muted-foreground" aria-label="Pagination">
                <span>{{ requests.from }}–{{ requests.to }} sur {{ requests.total }}</span>
                <div class="flex flex-wrap gap-1">
                    <template v-for="link in pages" :key="link.label">
                        <Button v-if="link.url" :as="Link" :href="link.url" size="xs" :variant="link.active ? 'default' : 'outline'" :aria-current="link.active ? 'page' : undefined" preserve-scroll preserve-state>
                            {{ pageLabel(link.label) }}
                        </Button>
                        <Button v-else type="button" size="xs" variant="outline" disabled>{{ pageLabel(link.label) }}</Button>
                    </template>
                </div>
            </nav>
        </Card>

        <LabTrashDialog
            :open="trashTarget !== null"
            :count="trashTarget?.uuids.length ?? 0"
            :refused="trashTarget?.refused ?? 0"
            :processing="busy"
            :error="trashError"
            @update:open="(open) => { if (!open) trashTarget = null; }"
            @confirm="confirmTrash"
        />
    </div>
</template>
