<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/UI/Card.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import SoinsWorkspaceHeader from '@/Components/Care/SoinsWorkspaceHeader.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';
import { BedDouble, Baby, CalendarCheck2, CalendarDays, CircleCheck, Eye, ListChecks, Scissors, Search, Stethoscope, UserCheck, UserRound } from 'lucide-vue-next';

defineOptions({
    layout: AppLayout,
});

const props = defineProps({
    surgicalRequests: Object,
    search: String,
    view: { type: String, default: 'to_plan' },
    counts: { type: Object, default: () => ({}) },
});

const { can } = usePermissions();

const query = ref(props.search ?? '');

const visit = (params) => {
    router.get('/surgery', params, { preserveState: true, preserveScroll: true, replace: true });
};

const runSearch = (value) => visit({ ...(value ? { q: value } : {}), view: props.view });

// Live search: fires 350ms after the last keystroke, including when the
// field is cleared back to empty (which resets to the unfiltered list) —
// no need to press Enter for either case.
let debounceTimer = null;
watch(query, (value) => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => runSearch(value), 350);
});

// Enter still triggers an immediate search, bypassing the debounce.
const submitSearch = () => {
    clearTimeout(debounceTimer);
    runSearch(query.value);
};

/**
 * ADR-135 — les vues suivent le parcours réel d'un dossier : à programmer,
 * programmé, au bloc, terminé. Elles sont exclusives, leurs comptes viennent
 * du serveur, et la carte est le filtre.
 */
const tiles = computed(() => [
    { value: 'to_plan', label: 'À programmer', hint: 'Demandes reçues', icon: ListChecks, tone: 'amber', count: props.counts.to_plan ?? 0, active: props.view === 'to_plan' },
    { value: 'planned', label: 'Programmées', hint: 'Date posée, bilan préop.', icon: CalendarCheck2, tone: 'primary', count: props.counts.planned ?? 0, active: props.view === 'planned' },
    { value: 'in_block', label: 'Au bloc', hint: 'Intervention en cours', icon: BedDouble, tone: 'sky', count: props.counts.in_block ?? 0, active: props.view === 'in_block' },
    { value: 'done', label: 'Terminées', hint: 'Opérées ou sorties', icon: CircleCheck, tone: 'emerald', count: props.counts.done ?? 0, active: props.view === 'done' },
]);

const STATUS_LABELS = {
    PENDING: 'En attente',
    SCHEDULED: 'Programmée',
    PREOPERATIVE_VALIDATED: 'Bilan préop. validé',
    IN_PROGRESS: 'En cours',
    COMPLETED: 'Terminée',
    DISCHARGED: 'Sortie',
};

// Les tons viennent des variantes partagées, jamais d'une palette recopiée :
// un même état se lit pareil dans tous les modules (ADR-099).
const STATUS_VARIANTS = {
    PENDING: 'outline',
    SCHEDULED: 'secondary',
    PREOPERATIVE_VALIDATED: 'secondary',
    IN_PROGRESS: 'warning',
    COMPLETED: 'success',
    DISCHARGED: 'outline',
};

const EMPTY_STATES = {
    to_plan: 'Aucune demande à programmer.',
    planned: 'Aucune intervention programmée.',
    in_block: 'Personne au bloc actuellement.',
    done: 'Aucune intervention terminée.',
};

/**
 * ADR-159 — une demande du bloc naît à la Réception, en consultation ou en
 * Maternité ; jamais ici. L'icône dit d'où elle vient, le libellé vient du
 * serveur, et une demande antérieure à cette trace ne l'invente pas.
 */
const ORIGIN_ICONS = {
    RECEPTION: UserRound,
    MEDICINE: Stethoscope,
    MATERNITY: Baby,
    HOSPITALIZATION: BedDouble,
};

const originIcon = (origin) => ORIGIN_ICONS[origin] ?? null;

const statusLabel = (status) => STATUS_LABELS[status] ?? status;
const statusVariant = (status) => STATUS_VARIANTS[status] ?? 'outline';
</script>

<template>
    <Head title="Chirurgie" />

    <div class="mx-auto w-full max-w-[1500px] space-y-5">
        <SoinsWorkspaceHeader
            :icon="Scissors"
            eyebrow="Bloc opératoire"
            title="Chirurgie"
            description="Les demandes du bloc, de leur réception à la sortie du patient."
            tone="violet"
        >
            <form class="w-full sm:w-72" role="search" @submit.prevent="submitSearch">
                <IconInput
                    v-model="query"
                    :icon="Search"
                    type="search"
                    placeholder="Patient, n° passage ou acte"
                    autocomplete="off"
                    aria-label="Rechercher une demande"
                />
            </form>
        </SoinsWorkspaceHeader>

        <QueueCounters :tiles="tiles" @select="(value) => visit({ ...(search ? { q: search } : {}), view: value })" />

        <Card class="overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[940px] border-collapse text-sm">
                    <caption class="sr-only">Liste des demandes de chirurgie</caption>
                    <thead class="bg-muted/40">
                        <tr class="border-b border-border text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                            <th class="px-4 py-2.5 text-start">Patient</th>
                            <th class="px-4 py-2.5 text-start">Intervention</th>
                            <th class="px-4 py-2.5 text-start">Origine</th>
                            <th class="px-4 py-2.5 text-start">Chirurgien</th>
                            <th class="px-4 py-2.5 text-start">Programmée le</th>
                            <th class="px-4 py-2.5 text-start">Statut</th>
                            <th class="px-4 py-2.5 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="request in surgicalRequests.data"
                            :key="request.uuid"
                            class="align-top transition-colors hover:bg-muted/40"
                        >
                            <td class="px-4 py-3">
                                <div v-if="request.episode?.patient" class="flex min-w-[220px] items-center gap-3">
                                    <Avatar rounded size="sm" variant="primary-pale" :text="formatPatientInitials(request.episode.patient)" aria-hidden="true" />
                                    <div class="min-w-0">
                                        <Link v-if="can('patients.view')" :href="`/patients/${request.episode.patient.uuid}`" class="block truncate font-bold text-foreground hover:text-primary">
                                            {{ formatPatientName(request.episode.patient) }}
                                        </Link>
                                        <span v-else class="block truncate font-bold text-foreground">{{ formatPatientName(request.episode.patient) }}</span>
                                        <span class="mt-0.5 block text-xs text-muted-foreground">{{ request.episode.episode_number }}</span>
                                    </div>
                                </div>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <td class="px-4 py-3 font-medium text-foreground">
                                {{ request.procedure_name }}
                                <!-- ADR-160 — un lit l'attend : le bloc doit le savoir. -->
                                <span
                                    v-if="request.hospital_stay"
                                    class="mt-1 flex items-center gap-1 text-xs font-normal text-sky-700 dark:text-sky-300"
                                    :title="[request.hospital_stay.service, request.hospital_stay.room_bed].filter(Boolean).join(' · ') || 'Service et chambre non renseignés'"
                                >
                                    <BedDouble class="h-3.5 w-3.5" />Hospitalisé<template v-if="request.hospital_stay.room_bed"> · {{ request.hospital_stay.room_bed }}</template>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    v-if="request.origin_label"
                                    class="inline-flex items-center gap-1.5 text-muted-foreground"
                                    :title="request.origin_description"
                                >
                                    <component :is="originIcon(request.origin)" v-if="originIcon(request.origin)" class="h-4 w-4" />
                                    {{ request.origin_label }}
                                </span>
                                <span v-else class="text-xs text-muted-foreground/70" title="Demande enregistrée avant que l’origine ne soit tracée.">
                                    Non renseignée
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="request.surgeon" class="inline-flex items-center gap-2 text-muted-foreground">
                                    <UserCheck class="h-4 w-4" />{{ request.surgeon.name }}
                                </span>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="request.scheduled_at" class="inline-flex items-center gap-2 text-muted-foreground">
                                    <CalendarDays class="h-4 w-4" />{{ formatDateTime(request.scheduled_at) }}
                                </span>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <td class="px-4 py-3">
                                <Badge :variant="statusVariant(request.status)">{{ statusLabel(request.status) }}</Badge>
                            </td>
                            <td class="px-4 py-3 text-end">
                                <Button :as="Link" :href="`/surgery/${request.uuid}`" size="sm" variant="white-outline">
                                    <Eye class="h-4 w-4" />Ouvrir
                                </Button>
                            </td>
                        </tr>

                        <tr v-if="surgicalRequests.data.length === 0">
                            <td colspan="7" class="px-5 py-14 text-center">
                                <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                    <Scissors class="h-5 w-5" />
                                </span>
                                <p class="mt-3 text-sm font-medium text-foreground">{{ EMPTY_STATES[view] }}</p>
                                <p v-if="search" class="mt-1 text-xs text-muted-foreground">
                                    Aucun dossier ne correspond à cette recherche.
                                </p>
                                <p v-else class="mx-auto mt-1 max-w-md text-xs text-muted-foreground">
                                    Une demande arrive ici quand la Réception inscrit l’acte à l’arrivée du patient,
                                    ou quand le médecin l’inscrit dans sa conduite à tenir. Le bloc ne crée pas de demande.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="surgicalRequests.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-border p-4">
                <span class="text-xs text-muted-foreground">Page {{ surgicalRequests.current_page }} sur {{ surgicalRequests.last_page }}</span>
                <div class="flex flex-wrap items-center gap-1">
                    <template v-for="(link, index) in surgicalRequests.links" :key="index">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-state
                            :class="[
                                'rounded px-3 py-1.5 text-sm',
                                link.active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted',
                            ]"
                            v-html="link.label"
                        />
                        <span v-else class="rounded px-3 py-1.5 text-sm text-muted-foreground/50" v-html="link.label" />
                    </template>
                </div>
            </div>
        </Card>
    </div>
</template>
