<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Shadcn/Avatar.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import BedBoard from '@/Components/Hospitalization/BedBoard.vue';
import {
    BedDouble,
    BedSingle,
    Building2,
    CalendarDays,
    CircleAlert,
    ClipboardList,
    Clock,
    FileSpreadsheet,
    FileText,
    FolderOpen,
    HeartPulse,
    LayoutGrid,
    ListChecks,
    Search,
    Siren,
    Stethoscope,
    UserRound,
    Utensils,
    X,
} from 'lucide-vue-next';
import { formatDateTime } from '@/utilities/date';
import { doctorName } from '@/utilities/doctorName';
import { formatPatientInitials } from '@/utilities/patient';
import { stayDays } from '@/utilities/hospitalStay';

defineOptions({ layout: AppLayout });

/**
 * ADR-113 — les patients hospitalisés.
 *
 * Le séjour commence à la demande du médecin (admission automatique) et se
 * termine par sa sortie médicale. Cet écran ne facture aucun repas et
 * n'encaisse rien.
 *
 * ADR-165 — les patients cochés s'impriment ou s'exportent ensemble : fiches de
 * régime, dossiers médicaux, feuille de tour de salle, liste Excel. Rien d'autre
 * ne se fait en lot — une sortie, un transfert, un passage au bloc ou un
 * changement de lit se décident patient par patient, sur la page du séjour.
 */
const props = defineProps({
    stays: { type: Object, required: true },
    counts: { type: Object, required: true },
    search: { type: String, default: '' },
    /** ADR-164 — le plan des lits ; absent tant que le site n'en a configuré aucun. */
    beds: { type: Object, default: null },
    /** ADR-164 — l'onglet reste visible sans lit configuré : il dit où les créer. */
    bedsConfigured: { type: Boolean, default: false },
    /** ADR-165 — ce que ce compte peut lancer sur une sélection ; le serveur revérifie. */
    capabilities: { type: Object, default: () => ({}) },
    bulkLimit: { type: Number, default: 50 },
});

// La liste des patients, ou le plan des lits : deux lectures du même service.
const view = ref('patients');

const query = ref(props.search);

const visit = (params) => router.get('/hospitalisation', params, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
});

// ADR-156 — un seul compte : les patients réellement au lit. Les sorties se
// suivent à la Réception (« Sorties & règlements »), pas ici.
const tiles = computed(() => [
    { value: 'active', label: 'Hospitalisés', hint: 'Séjours en cours', icon: BedDouble, tone: 'primary', count: props.counts.active ?? 0, active: view.value === 'patients' },
    ...(props.beds ? [
        { value: 'free', label: 'Lits libres', hint: `Sur ${props.beds.summary.beds} lit${props.beds.summary.beds > 1 ? 's' : ''}`, icon: BedSingle, tone: 'emerald', count: props.beds.summary.free, active: view.value === 'beds' },
        ...(props.beds.unassigned.length ? [
            { value: 'unassigned', label: 'Sans lit', hint: 'Admis, lit à attribuer', icon: CircleAlert, tone: 'amber', count: props.beds.unassigned.length, active: false },
        ] : []),
    ] : []),
]);

const selectTile = (value) => {
    if (value === 'active') {
        view.value = 'patients';
        visit({ q: props.search });

        return;
    }

    view.value = 'beds';
};

const VIEWS = [
    { value: 'patients', label: 'Patients hospitalisés', icon: UserRound },
    { value: 'beds', label: 'Plan des lits', icon: LayoutGrid },
];

const PRIORITY = {
    URGENT: { label: 'Urgent', variant: 'destructive' },
};

/* ── Sélection multiple (ADR-165) ──────────────────────────────────────
 *
 * Ce que l'écran coche n'est qu'un ensemble d'UUID : chaque action relit les
 * séjours côté serveur et garde les droits de la feuille seule. Changer de page
 * ou de recherche change de liste : une sélection qui survivrait porterait sur
 * des patients qu'on ne voit plus. */
const rows = computed(() => props.stays?.data ?? []);
const selected = ref([]);
const isSelected = (uuid) => selected.value.includes(uuid);
const selectedRows = computed(() => rows.value.filter((stay) => isSelected(stay.uuid)));
const allSelected = computed(() => rows.value.length > 0 && rows.value.every((stay) => isSelected(stay.uuid)));
const someSelected = computed(() => selected.value.length > 0 && !allSelected.value);
const overLimit = computed(() => selectedRows.value.length > props.bulkLimit);

const toggleRow = (uuid, on) => {
    selected.value = on
        ? [...new Set([...selected.value, uuid])]
        : selected.value.filter((id) => id !== uuid);
};
const toggleAll = (on) => { selected.value = on ? rows.value.map((stay) => stay.uuid) : []; };

watch(() => [props.stays?.current_page, props.search], () => { selected.value = []; });
watch(rows, (list) => {
    const present = new Set(list.map((stay) => stay.uuid));

    selected.value = selected.value.filter((uuid) => present.has(uuid));
});

const selectionQuery = computed(() => selectedRows.value
    .map((stay) => `uuids[]=${encodeURIComponent(stay.uuid)}`)
    .join('&'));
const bulkHref = (path) => `/hospitalisation/selection/${path}?${selectionQuery.value}`;

const patientLabel = (stay) => `${stay.patient.name} · ${stay.episode_number}`;
</script>

<template>
    <Head title="Hospitalisation" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <Card class="p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                        <BedDouble class="h-5 w-5" />
                    </span>
                    <div>
                        <h1 class="font-heading text-lg font-bold text-foreground">Hospitalisation</h1>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            Les patients hospitalisés sur demande du médecin, et leur fiche de régime.
                        </p>
                    </div>
                </div>

                <form class="w-full sm:w-72" @submit.prevent="visit({ q: query })">
                    <IconInput
                        v-model="query"
                        :icon="Search"
                        placeholder="Patient, n° patient ou passage…"
                        aria-label="Rechercher un patient hospitalisé"
                    />
                </form>
            </div>
        </Card>

        <QueueCounters :tiles="tiles" @select="selectTile" />

        <div class="inline-flex rounded-lg border border-border bg-muted/50 p-1" role="tablist" aria-label="Affichage">
            <button
                v-for="option in VIEWS"
                :key="option.value"
                type="button"
                role="tab"
                :aria-selected="view === option.value"
                :class="['inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30',
                    view === option.value ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground']"
                @click="view = option.value"
            >
                <component :is="option.icon" class="h-4 w-4" aria-hidden="true" />{{ option.label }}
            </button>
        </div>

        <BedBoard v-if="beds && view === 'beds'" :services="beds.services" :unassigned="beds.unassigned" />

        <!-- ADR-164 — sans lit configuré, l'onglet le dit : un onglet absent se lirait « fonction inexistante ». -->
        <Card v-else-if="view === 'beds'" class="p-6">
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground">
                    <BedSingle class="h-5 w-5" />
                </span>
                <div class="space-y-1 text-sm">
                    <p class="font-semibold text-foreground">Aucun lit n’est encore configuré pour ce site</p>
                    <p class="text-muted-foreground">
                        Les services, les chambres et leur nombre de lits se créent depuis le portail Super Administration,
                        rubrique « Services, chambres et lits ». Dès le premier lit créé, ce plan montre chaque lit libre,
                        occupé ou hors service, et chaque séjour propose « Attribuer un lit ».
                    </p>
                    <p class="text-muted-foreground">En attendant, la chambre et le lit se notent à la main sur la page de chaque séjour.</p>
                </div>
            </div>
        </Card>

        <Card v-else class="overflow-hidden">
            <!-- ADR-165 — actions groupées : n'apparaissent qu'avec une sélection.
                 Toutes en lecture ; aucune décision médicale ne se prend en lot. -->
            <div
                v-if="selectedRows.length"
                class="flex flex-wrap items-center gap-2 border-b border-primary/20 bg-primary/5 px-4 py-3"
                role="toolbar"
                aria-label="Actions sur les patients sélectionnés"
            >
                <p class="me-2 flex items-center gap-2 text-sm font-semibold text-foreground">
                    <ListChecks class="h-4 w-4 text-primary" aria-hidden="true" />
                    {{ selectedRows.length }} patient{{ selectedRows.length > 1 ? 's' : '' }} sélectionné{{ selectedRows.length > 1 ? 's' : '' }}
                </p>

                <template v-if="!overLimit">
                    <Button :as="Link" :href="bulkHref('tour-de-salle')" size="sm" variant="white-outline" title="Une feuille récapitulative pour la visite">
                        <ClipboardList class="h-4 w-4" />Tour de salle
                    </Button>
                    <Button :as="Link" :href="bulkHref('regimes')" size="sm" variant="white-outline" title="Une fiche de régime par page">
                        <Utensils class="h-4 w-4" />Fiches de régime
                    </Button>
                    <Button
                        v-if="capabilities.can_print_medical_records"
                        :as="Link"
                        :href="bulkHref('dossiers-medicaux')"
                        size="sm"
                        variant="white-outline"
                        title="Un dossier médical par page"
                    >
                        <FileText class="h-4 w-4" />Dossiers médicaux
                    </Button>
                    <Button
                        v-if="capabilities.can_export"
                        as="a"
                        :href="bulkHref('export')"
                        size="sm"
                        variant="white-outline"
                        title="Télécharger la sélection au format Excel"
                    >
                        <FileSpreadsheet class="h-4 w-4" />Exporter Excel
                    </Button>
                </template>
                <p v-else class="text-sm text-amber-700 dark:text-amber-300">Au plus {{ bulkLimit }} patients à la fois.</p>

                <Button type="button" size="sm" variant="ghost" class="ms-auto text-muted-foreground" @click="selected = []">
                    <X class="h-4 w-4" />Tout désélectionner
                </Button>
            </div>

            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[980px] border-collapse text-sm">
                    <caption class="sr-only">Patients hospitalisés</caption>
                    <thead>
                        <tr class="border-b border-border bg-muted/40 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            <th scope="col" class="w-10 px-4 py-3">
                                <Checkbox
                                    :model-value="allSelected"
                                    :class="someSelected ? 'opacity-70' : ''"
                                    :disabled="rows.length === 0"
                                    aria-label="Sélectionner tous les patients de la page"
                                    :title="someSelected ? 'Sélection partielle : cliquer pour tout sélectionner' : undefined"
                                    @update:model-value="toggleAll"
                                />
                            </th>
                            <th scope="col" class="px-4 py-3 text-start"><span class="inline-flex items-center gap-1.5"><UserRound class="h-3.5 w-3.5" aria-hidden="true" />Patient</span></th>
                            <th scope="col" class="px-4 py-3 text-start"><span class="inline-flex items-center gap-1.5"><Stethoscope class="h-3.5 w-3.5" aria-hidden="true" />Motif</span></th>
                            <th scope="col" class="px-4 py-3 text-start"><span class="inline-flex items-center gap-1.5"><Building2 class="h-3.5 w-3.5" aria-hidden="true" />Service · Chambre</span></th>
                            <th scope="col" class="px-4 py-3 text-start"><span class="inline-flex items-center gap-1.5"><CalendarDays class="h-3.5 w-3.5" aria-hidden="true" />Entrée</span></th>
                            <th scope="col" class="px-4 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="stay in stays.data"
                            :key="stay.uuid"
                            :class="['align-top transition-colors hover:bg-muted/30', isSelected(stay.uuid) && 'bg-primary/5 hover:bg-primary/10']"
                        >
                            <td class="w-10 px-4 py-3.5">
                                <Checkbox
                                    :model-value="isSelected(stay.uuid)"
                                    :aria-label="`Sélectionner ${patientLabel(stay)}`"
                                    @update:model-value="toggleRow(stay.uuid, $event)"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-start gap-3">
                                    <Avatar
                                        size="sm"
                                        :text="formatPatientInitials(stay.patient)"
                                        :emergency="stay.priority === 'URGENT'"
                                        variant="primary-pale"
                                        aria-hidden="true"
                                    />
                                    <div class="min-w-0">
                                        <Link :href="`/hospitalisation/${stay.uuid}`" class="block font-semibold text-foreground hover:text-primary">{{ stay.patient.name }}</Link>
                                        <span class="mt-0.5 block text-xs text-muted-foreground">
                                            <span class="font-mono">{{ stay.patient.patient_number }}</span> · Passage {{ stay.episode_number }}<template v-if="stay.patient.age !== null"> · {{ stay.patient.age }} ans</template>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="max-w-xs px-4 py-3">
                                <span class="line-clamp-2 text-foreground">{{ stay.reason || '—' }}</span>
                                <span class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                    <Badge v-if="PRIORITY[stay.priority]" :variant="PRIORITY[stay.priority].variant"><Siren class="h-3 w-3" />{{ PRIORITY[stay.priority].label }}</Badge>
                                    <span v-if="stay.requested_by" class="inline-flex items-center gap-1"><Stethoscope class="h-3 w-3" aria-hidden="true" />{{ doctorName(stay.requested_by) }}</span>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="flex items-center gap-1.5 text-foreground"><Building2 class="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />{{ stay.service || 'Service non précisé' }}</span>
                                <Badge v-if="stay.needs_bed" variant="warning" class="mt-1.5"><BedDouble class="h-3 w-3" />Lit à attribuer</Badge>
                                <span v-else class="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground"><BedDouble class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ stay.room_bed || 'Chambre / lit non renseigné' }}</span>
                                <!-- ADR-161 — un patient en surveillance continue ou en
                                     réanimation se voit d'un coup d'œil dans la liste. -->
                                <Badge v-if="stay.care_level && stay.care_level !== 'STANDARD'" :variant="stay.care_level === 'INTENSIVE' ? 'destructive' : 'warning'" class="mt-1.5"><HeartPulse class="h-3 w-3" />{{ stay.care_level_label }}</Badge>
                            </td>
                            <td class="px-4 py-3">
                                <span class="flex items-center gap-1.5 text-foreground"><CalendarDays class="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />{{ formatDateTime(stay.admitted_at) }}</span>
                                <span v-if="stay.discharged_at" class="mt-1 block text-xs text-muted-foreground">Sorti le {{ formatDateTime(stay.discharged_at) }} · {{ stay.discharge_type }}</span>
                                <template v-else>
                                    <span class="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground"><Clock class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ stayDays(stay) }}</span>
                                    <span class="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground"><Utensils class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ stay.diet_entries_count }} ligne{{ stay.diet_entries_count > 1 ? 's' : '' }} de régime</span>
                                </template>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <Button
                                        :as="Link"
                                        :href="`/hospitalisation/${stay.uuid}/regime/impression`"
                                        size="icon-xs"
                                        variant="ghost"
                                        class="text-muted-foreground"
                                        :title="`Fiche de régime — ${stay.patient.name}`"
                                        :aria-label="`Imprimer la fiche de régime de ${patientLabel(stay)}`"
                                    >
                                        <Utensils class="h-4 w-4" />
                                    </Button>
                                    <Button
                                        v-if="capabilities.can_print_medical_records"
                                        :as="Link"
                                        :href="`/passages/${stay.episode_uuid}/dossier-medical`"
                                        size="icon-xs"
                                        variant="ghost"
                                        class="text-muted-foreground"
                                        :title="`Dossier médical — ${stay.patient.name}`"
                                        :aria-label="`Ouvrir le dossier médical de ${patientLabel(stay)}`"
                                    >
                                        <FileText class="h-4 w-4" />
                                    </Button>
                                    <Button :as="Link" :href="`/hospitalisation/${stay.uuid}`" size="sm" variant="white-outline" class="ms-1">
                                        <FolderOpen class="h-4 w-4" />Ouvrir
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!stays.data.length">
                            <td colspan="6" class="px-4 py-12 text-center">
                                <span class="mx-auto mb-2 grid h-10 w-10 place-items-center rounded-full bg-muted text-muted-foreground">
                                    <BedDouble class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <span class="text-sm text-muted-foreground">
                                    {{ search ? 'Aucun séjour ne correspond à cette recherche.' : 'Aucun patient hospitalisé actuellement.' }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="stays.links?.length > 3" class="flex flex-wrap items-center justify-center gap-1 border-t border-border p-3">
                <Link
                    v-for="link in stays.links"
                    :key="link.label"
                    :href="link.url ?? ''"
                    :class="['rounded-md px-3 py-1.5 text-xs font-semibold transition-colors',
                        link.active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent',
                        !link.url && 'pointer-events-none opacity-40']"
                    v-html="link.label"
                />
            </div>
        </Card>
    </div>
</template>
