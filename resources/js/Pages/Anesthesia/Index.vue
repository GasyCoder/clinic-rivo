<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { CircleCheck, ClipboardList, Eye, Scissors, Search, ShieldCheck, Syringe } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import SoinsTabs from '@/Components/Care/SoinsTabs.vue';
import SoinsWorkspaceHeader from '@/Components/Care/SoinsWorkspaceHeader.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

/**
 * L'espace Anesthésie du module Soins (ADR-048, ADR-135).
 *
 * Le dossier d'anesthésie est porté par la demande chirurgicale : la file suit
 * donc le dossier de l'évaluation pré-anesthésique jusqu'au bloc puis à la fin.
 * Les quatre étapes sont exclusives — la somme des comptes est le nombre de
 * dossiers — et lues sur des faits que la Chirurgie et l'anesthésie
 * enregistrent déjà, jamais sur un drapeau de plus.
 */
const props = defineProps({
    surgicalRequests: Object,
    search: String,
    stage: { type: String, default: 'assess' },
    counts: { type: Object, default: () => ({}) },
});
const { can } = usePermissions();
const query = ref(props.search ?? '');
let debounceTimer = null;

const visit = (stage = props.stage, value = query.value) => router.get(
    '/anesthesia',
    { stage: stage === 'assess' ? undefined : stage, q: value || undefined },
    { preserveState: true, preserveScroll: true, replace: true },
);

watch(query, (value) => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => visit(props.stage, value), 350);
});
const submitSearch = () => {
    clearTimeout(debounceTimer);
    visit();
};

/** Le compte vient du serveur : recalculé depuis la page affichée, il mentirait dès la deuxième. */
const counterTiles = computed(() => [
    {
        value: 'assess',
        label: 'À évaluer',
        hint: 'Évaluation à faire ou en cours',
        title: 'Évaluation pré-anesthésique pas encore validée, intervention pas encore au bloc',
        icon: ClipboardList,
        tone: 'amber',
        count: props.counts.assess,
        active: props.stage === 'assess',
    },
    {
        value: 'cleared',
        label: 'Transmis à Chirurgie',
        hint: 'Évaluation validée, avant le bloc',
        title: 'Évaluation validée : le dossier est prêt pour Chirurgie, qui n’est pas encore au bloc',
        icon: Scissors,
        tone: 'sky',
        count: props.counts.cleared,
        active: props.stage === 'cleared',
    },
    {
        value: 'intra',
        label: 'Au bloc',
        hint: 'Conduite anesthésique en cours',
        title: 'La Chirurgie est au bloc et le dossier anesthésique n’est pas encore validé',
        icon: Syringe,
        tone: 'primary',
        count: props.counts.intra,
        active: props.stage === 'intra',
    },
    {
        value: 'done',
        label: 'Terminés',
        hint: 'Intervention ou dossier terminé',
        title: 'Intervention terminée, ou dossier anesthésique validé',
        icon: CircleCheck,
        tone: 'emerald',
        count: props.counts.done,
        active: props.stage === 'done',
    },
]);

const EMPTY = {
    assess: { title: 'Aucun dossier à évaluer', hint: 'Les demandes chirurgicales dont l’évaluation pré-anesthésique reste à faire apparaissent ici.' },
    cleared: { title: 'Aucun dossier transmis à Chirurgie', hint: 'Un dossier apparaît ici dès que son évaluation pré-anesthésique est validée, tant que Chirurgie n’est pas au bloc.' },
    intra: { title: 'Aucune intervention au bloc', hint: 'Les dossiers dont la Chirurgie est au bloc apparaissent ici.' },
    done: { title: 'Aucun dossier terminé', hint: 'Les interventions terminées et les dossiers anesthésiques validés apparaissent ici.' },
};
const empty = computed(() => (props.search
    ? { title: 'Aucun dossier ne correspond à la recherche', hint: 'Modifiez la recherche ou changez d’étape ci-dessus.' }
    : (EMPTY[props.stage] ?? EMPTY.assess)));

const STAGE_TONES = { assess: 'warning', cleared: 'info', intra: 'primary', done: 'success' };

/** Ce que la ligne ajoute à l'étape : l'état de l'évaluation, ou de l'intervention. */
const detail = (request) => {
    const record = request.anesthesia_record;

    if (request.stage === 'assess') return record ? 'Brouillon en cours' : 'À commencer';
    if (request.stage === 'cleared') return `Validée le ${formatDateTime(record?.assessment_validated_at) ?? '—'}`;
    if (request.stage === 'intra') return 'Chirurgie au bloc';

    return record?.validated_at ? `Dossier validé le ${formatDateTime(record.validated_at)}` : 'Intervention terminée';
};

const pages = computed(() => props.surgicalRequests.links ?? []);
</script>

<template>
    <Head title="Anesthésie" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <SoinsTabs current="anesthesia" />

        <SoinsWorkspaceHeader
            :icon="ShieldCheck"
            tone="violet"
            eyebrow="Workspace paramédical spécialisé"
            title="Anesthésie"
            description="Consultation pré-anesthésique, examens et conduite anesthésique, de l’évaluation au bloc."
        >
            <form class="w-full lg:w-96" role="search" @submit.prevent="submitSearch">
                <IconInput v-model="query" :icon="Search" type="search" placeholder="Patient, passage ou intervention…" autocomplete="off" aria-label="Rechercher un dossier d’anesthésie" />
            </form>
        </SoinsWorkspaceHeader>

        <QueueCounters :tiles="counterTiles" @select="visit($event)" />

        <Card class="overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[960px] border-collapse">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Patient</th>
                            <th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Intervention</th>
                            <th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Programmation</th>
                            <th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Anesthésiste</th>
                            <th class="px-4 py-2.5 text-start text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Étape</th>
                            <th class="px-4 py-2.5 text-end text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="request in surgicalRequests.data" :key="request.uuid" class="transition-colors hover:bg-muted/40">
                            <td class="px-4 py-3">
                                <div class="flex min-w-[220px] items-center gap-3">
                                    <Avatar rounded size="sm" variant="primary-pale" :text="formatPatientInitials(request.episode.patient)" />
                                    <div class="min-w-0">
                                        <Link v-if="can('patients.view')" :href="`/patients/${request.episode.patient.uuid}`" class="block truncate text-sm font-bold text-foreground hover:text-primary">{{ formatPatientName(request.episode.patient) }}</Link>
                                        <span v-else class="block truncate text-sm font-bold text-foreground">{{ formatPatientName(request.episode.patient) }}</span>
                                        <small class="text-muted-foreground">{{ request.episode.episode_number }}</small>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm font-medium text-foreground">{{ request.procedure_name }}</td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ formatDateTime(request.scheduled_at) ?? 'Non programmée' }}</td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ request.anesthesia_record?.anesthetist?.name ?? 'Non affecté' }}</td>
                            <td class="px-4 py-3">
                                <Badge :tone="STAGE_TONES[request.stage] ?? 'neutral'">{{ request.stage_label }}</Badge>
                                <p class="mt-1 text-[11px] text-muted-foreground">{{ detail(request) }}</p>
                            </td>
                            <td class="px-4 py-3 text-end">
                                <Button :as="Link" :href="`/anesthesia/${request.uuid}`" size="sm" variant="outline" :aria-label="`Ouvrir le dossier ${request.procedure_name}`" title="Ouvrir le dossier">
                                    <Eye class="h-4 w-4" />Ouvrir
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="surgicalRequests.data.length === 0">
                            <td colspan="6" class="px-5 py-12 text-center">
                                <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-violet-50 text-violet-500 dark:bg-violet-950/40">
                                    <ShieldCheck class="h-5 w-5" />
                                </span>
                                <p class="mt-3 text-sm font-semibold text-foreground">{{ empty.title }}</p>
                                <p class="mx-auto mt-1 max-w-md text-xs text-muted-foreground">{{ empty.hint }}</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="pages.length > 3" class="flex flex-wrap items-center justify-between gap-3 border-t border-border p-4" aria-label="Pagination">
                <span class="text-xs text-muted-foreground">Page {{ surgicalRequests.current_page }} sur {{ surgicalRequests.last_page }}</span>
                <div class="flex flex-wrap gap-1">
                    <template v-for="link in pages" :key="link.label">
                        <Button v-if="link.url" :as="Link" :href="link.url" size="sm" :variant="link.active ? 'primary' : 'outline'" :aria-current="link.active ? 'page' : undefined" preserve-state preserve-scroll>
                            <span v-html="link.label" />
                        </Button>
                        <Button v-else type="button" size="sm" variant="outline" disabled>
                            <span v-html="link.label" />
                        </Button>
                    </template>
                </div>
            </nav>
        </Card>
    </div>
</template>
