<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import {
    AlertTriangle, BadgeCheck, BarChart3, Building2, CalendarCheck, ChevronRight, ClipboardList, FlaskConical, Inbox, List, Microscope, RotateCcw, ScanBarcode, Search, Siren, TestTube, TestTubes, X,
} from 'lucide-vue-next';
import { formatDateTime, formatRelativeTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';
import { LAB_STATE_LABELS, LAB_STATE_TONES, LAB_STATUS_TONES, LAB_VIEWS } from '@/utilities/labWorkbench';
import { paymentBadge } from '@/utilities/labReception';
import { labUrl } from '@/utilities/labUrl';

defineOptions({ layout: AppLayout });

/**
 * ADR-213 / ADR-214 — la file du Laboratoire, par demande : chaque demande est
 * dans une seule vue, comptée par le serveur, jamais depuis la page affichée.
 * « À réceptionner » vient d'abord : rien ne se prélève avant le contrôle du
 * règlement. Les analyses confiées à l'extérieur sont un filtre, pas une vue.
 */
const props = defineProps({
    requests: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    view: { type: String, default: 'to_do' },
    search: { type: String, default: '' },
    sentOut: { type: Boolean, default: false },
});

const page = usePage();
const can = (permission) => (page.props.permissions ?? []).includes(permission);

const ICONS = { to_receive: Inbox, to_do: FlaskConical, to_redo: RotateCcw, to_validate: BadgeCheck, validated: CalendarCheck, all: List };

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

const pages = computed(() => props.requests?.links ?? []);
// Les libellés de Laravel portent des entités (« &laquo; Précédent ») : on les lit en texte, jamais en HTML.
const pageLabel = (label) => String(label).replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/&amp;/g, '&');

const emptyText = computed(() => ({
    to_receive: 'Aucune demande à réceptionner.',
    to_do: 'Aucune analyse à faire : la paillasse est à jour.',
    to_redo: 'Aucune analyse renvoyée à refaire.',
    to_validate: 'Aucun résultat rendu n’attend d’être envoyé.',
    validated: 'Aucune demande entièrement envoyée au médecin pour l’instant.',
    all: 'Aucune demande d’analyses.',
}[props.view] ?? 'Aucune demande.'));
</script>

<template>
    <Head title="Laboratoire" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><TestTubes class="h-6 w-6" /></span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-foreground">Laboratoire</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Analyses demandées — saisie des résultats, puis envoi au médecin.</p>
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

        <QueueCounters :tiles="tiles" @select="selectView" />

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
                        <template v-if="view === 'validated' || view === 'all'">Les plus récentes d’abord.</template>
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

            <ul class="divide-y divide-border">
                <li v-for="request in requests.data" :key="request.uuid">
                    <Link
                        :href="labUrl(`/laboratory/requests/${request.uuid}`)"
                        class="group grid gap-3 px-4 py-3.5 transition-colors hover:bg-muted/40 focus:outline-none focus-visible:bg-muted/50 md:grid-cols-[minmax(0,15rem)_minmax(0,1fr)_auto] md:items-center"
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
                            <p class="mt-1 text-[11px] text-muted-foreground" :title="formatDateTime(request.requested_at)">
                                {{ request.origin }} · {{ formatRelativeTime(request.requested_at) }}<template v-if="request.requested_by"> · {{ request.requested_by }}</template>
                            </p>
                        </div>

                        <div class="flex min-w-0 flex-wrap gap-1.5">
                            <Badge v-for="item in request.items" :key="item.uuid" :tone="LAB_STATUS_TONES[item.status]" class="max-w-full" :title="item.external ? `Confiée à ${item.external}` : undefined">
                                <Building2 v-if="item.external" class="h-3 w-3 shrink-0" aria-label="Confiée à l’extérieur" />
                                <span class="truncate">{{ item.name }}</span>
                                <span class="font-normal opacity-80">· {{ item.status_label }}</span>
                            </Badge>
                        </div>

                        <div class="flex items-center justify-between gap-2 md:justify-end">
                            <div class="flex flex-wrap gap-1.5">
                                <Badge v-if="paymentBadge(request.payment)" :tone="paymentBadge(request.payment).tone" :title="paymentBadge(request.payment).hint">{{ paymentBadge(request.payment).label }}</Badge>
                                <Badge v-if="request.samples_count" variant="outline"><TestTube class="h-3.5 w-3.5" /> {{ request.samples_count }}</Badge>
                                <Badge v-if="request.critical" tone="danger"><AlertTriangle class="h-3.5 w-3.5" /> {{ request.critical }} critique(s)</Badge>
                                <Badge v-if="request.pathological" tone="warning">{{ request.pathological }} pathologique(s)</Badge>
                                <Badge :tone="LAB_STATE_TONES[request.state]">{{ LAB_STATE_LABELS[request.state] }}</Badge>
                            </div>
                            <ChevronRight class="h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                        </div>
                    </Link>
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
    </div>
</template>
