<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Baby, CircleCheck, FileText, HeartPulse, Scissors, Search, Stethoscope, UserCheck } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import SoinsTabs from '@/Components/Care/SoinsTabs.vue';
import SoinsWorkspaceHeader from '@/Components/Care/SoinsWorkspaceHeader.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    orientations: { type: Object, default: () => ({ data: [], links: [] }) },
    counts: { type: Object, default: () => ({}) },
    filter: { type: String, default: 'waiting' },
    search: { type: String, default: '' },
});

const q = ref(props.search ?? '');

const visit = (filter = props.filter) => router.get(
    '/maternity',
    { filter, q: q.value || undefined },
    { preserveState: true, replace: true },
);

const patientName = (item) => `${item.episode.patient.first_name} ${item.episode.patient.last_name}`;

const formatDate = (value) => (value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
    : '—');

/**
 * Les vues de la file, en cartes (ADR-135). Elles suivent le parcours réel
 * d'une patiente et sont exclusives : la somme des comptes est le nombre de
 * dossiers. « À prendre » reste la vue par défaut — le travail à faire —, les
 * deux autres disent où sont allées celles qui ont quitté la Maternité.
 *
 * Le compte reste celui du serveur : recalculé depuis la page affichée, il
 * mentirait dès la deuxième.
 */
const counterTiles = computed(() => [
    {
        value: 'waiting',
        label: 'À prendre',
        hint: 'Personne ne les a encore prises',
        title: 'Orientées vers la Maternité, en attente d’une sage-femme',
        icon: Baby,
        tone: 'amber',
        count: props.counts.waiting,
        active: props.filter === 'waiting',
    },
    {
        value: 'active',
        label: 'En cours',
        hint: 'Prises en charge',
        title: 'Prises en charge par la Maternité, pas encore terminées',
        icon: HeartPulse,
        tone: 'red',
        count: props.counts.active,
        active: props.filter === 'active',
    },
    {
        value: 'doctor',
        label: 'Orientées vers Médecine',
        hint: 'Le médecin a encore la patiente',
        title: 'Terminées à la Maternité, orientées vers Médecine et pas encore terminées par le médecin',
        icon: Stethoscope,
        tone: 'sky',
        count: props.counts.doctor,
        active: props.filter === 'doctor',
    },
    {
        value: 'completed',
        label: 'Terminées',
        hint: 'Dossiers clos',
        title: 'Prise en charge terminée, sans suite en cours',
        icon: CircleCheck,
        tone: 'emerald',
        count: props.counts.completed,
        active: props.filter === 'completed',
    },
]);

/** Ce que dit la vue vide : chaque vue a son propre « rien à signaler ». */
const EMPTY = {
    waiting: { title: 'Aucune patiente à prendre en charge', hint: 'La file est vide : la Réception et la Médecine n’ont orienté personne vers la Maternité.' },
    active: { title: 'Aucune prise en charge en cours', hint: 'Les patientes que vous ou une collègue avez prises en charge apparaissent ici jusqu’à la fin de la prise en charge.' },
    doctor: { title: 'Aucune patiente chez le médecin', hint: 'Les patientes orientées vers Médecine à la fin de la prise en charge apparaissent ici, jusqu’à ce que le médecin ait terminé.' },
    completed: { title: 'Aucune prise en charge terminée', hint: 'Les dossiers clos apparaissent ici, sans suite en cours.' },
};
const empty = computed(() => EMPTY[props.filter] ?? EMPTY.waiting);

/** Le ton du statut, pas ses classes : le `Badge` porte déjà le vocabulaire. */
const medicineTone = (medicine) => ({
    PENDING: 'warning',
    IN_PROGRESS: 'info',
}[medicine.status] ?? 'success');

const statusTone = (status) => ({
    PENDING: 'warning',
    IN_PROGRESS: 'info',
    COMPLETED: 'success',
}[status] ?? 'neutral');

/** Une seule prise en charge à la fois : un double clic n'en ouvre pas deux. */
const accepting = ref(null);

const accept = (orientation) => {
    if (accepting.value) return;

    accepting.value = orientation.uuid;
    router.post(`/maternity/orientations/${orientation.uuid}/accept`, {}, {
        preserveScroll: true,
        onFinish: () => { accepting.value = null; },
    });
};

/**
 * Laravel renvoie aussi les bornes « Précédent »/« Suivant » sans URL quand
 * on est au bout : elles restent affichées, désactivées, pour que la barre
 * ne change pas de largeur d'une page à l'autre.
 */
const pages = computed(() => props.orientations.links ?? []);
</script>

<template>
    <Head title="Maternité" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <SoinsTabs current="maternity" />

        <SoinsWorkspaceHeader
            :icon="HeartPulse"
            tone="rose"
            eyebrow="Workspace paramédical spécialisé"
            title="Maternité"
            description="Orientations, suivi obstétrical, accouchement et nouveau-né sur le même passage."
        >
            <div class="w-full lg:w-96">
                <IconInput
                    v-model="q"
                    :icon="Search"
                    type="search"
                    placeholder="Patiente, numéro patient ou passage…"
                    aria-label="Rechercher une patiente"
                    @keyup.enter="visit()"
                />
            </div>
        </SoinsWorkspaceHeader>

        <QueueCounters class="lg:grid-cols-4" :tiles="counterTiles" @select="visit" />

        <Card class="overflow-hidden">
            <div class="divide-y divide-border">
                <article
                    v-for="orientation in orientations.data"
                    :key="orientation.uuid"
                    class="grid gap-4 p-4 transition-colors hover:bg-accent/40 lg:grid-cols-[70px_minmax(0,1.5fr)_minmax(180px,1fr)_auto] lg:items-center"
                >
                    <div class="text-center">
                        <span v-if="orientation.queue_number" class="text-[10px] font-bold uppercase text-muted-foreground">File</span>
                        <strong class="block text-xl tabular-nums text-rose-700 dark:text-rose-300">
                            {{ orientation.queue_number ? `N° ${orientation.queue_number}` : '—' }}
                        </strong>
                    </div>

                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-sm font-bold text-foreground">{{ patientName(orientation) }}</h2>
                            <!-- L'urgence est une propriété du passage, jamais
                                 de la patiente (ADR-021, ADR-056). -->
                            <Badge v-if="orientation.episode.priority === 'EMERGENCY'" tone="danger" class="uppercase">Urgence</Badge>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            <span class="font-mono">{{ orientation.episode.patient.patient_number }}</span> · Passage {{ orientation.episode.episode_number }}
                        </p>
                        <p v-if="orientation.reason" class="mt-2 line-clamp-2 text-xs text-muted-foreground">{{ orientation.reason }}</p>
                    </div>

                    <div class="text-xs text-muted-foreground">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <Badge :tone="statusTone(orientation.status)">{{ orientation.status_label }}</Badge>
                            <!-- La suite d'une patiente n'est jamais devinée : c'est
                                 une vraie orientation Médecine ou une vraie demande
                                 chirurgicale sur le même passage (ADR-135). -->
                            <Badge v-if="orientation.follow_up?.medicine" :tone="medicineTone(orientation.follow_up.medicine)">
                                <Stethoscope class="h-3.5 w-3.5" />{{ orientation.follow_up.medicine.label }}
                            </Badge>
                            <Badge v-if="orientation.follow_up?.cesarean" tone="warning">
                                <Scissors class="h-3.5 w-3.5" />{{ orientation.follow_up.cesarean.label }}
                            </Badge>
                        </div>
                        <p class="mt-1.5">Orientée le {{ formatDate(orientation.oriented_at) }}</p>
                        <p v-if="orientation.status === 'COMPLETED' && orientation.completed_at" class="mt-1">Terminée le {{ formatDate(orientation.completed_at) }}</p>
                        <p v-if="orientation.accepted_by" class="mt-1">Prise par {{ orientation.accepted_by }}</p>
                        <p v-if="orientation.follow_up?.medicine?.doctor" class="mt-1">Médecin : {{ orientation.follow_up.medicine.doctor }}</p>
                    </div>

                    <div>
                        <!-- Un POST, pas un lien : l'ancienne version
                             imbriquait un <Button> dans un <Link as="button">,
                             c'est-à-dire un bouton dans un bouton. -->
                        <Button
                            v-if="orientation.status === 'PENDING'"
                            type="button"
                            variant="primary"
                            :disabled="accepting === orientation.uuid"
                            @click="accept(orientation)"
                        >
                            <UserCheck class="h-4 w-4" />{{ accepting === orientation.uuid ? 'Ouverture…' : 'Prendre en charge' }}
                        </Button>
                        <Button
                            v-else
                            :as="Link"
                            :href="`/maternity/orientations/${orientation.uuid}`"
                            variant="outline"
                        >
                            <FileText class="h-4 w-4" />{{ orientation.status === 'COMPLETED' ? 'Consulter' : 'Ouvrir le dossier' }}
                        </Button>
                    </div>
                </article>

                <div v-if="! orientations.data.length" class="px-5 py-16 text-center">
                    <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-muted text-muted-foreground">
                        <HeartPulse class="h-5 w-5" />
                    </span>
                    <p class="mt-3 text-sm font-semibold text-foreground">{{ empty.title }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">{{ empty.hint }}</p>
                </div>
            </div>

            <nav v-if="pages.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-border p-4" aria-label="Pagination">
                <template v-for="link in pages" :key="link.label">
                    <Button
                        v-if="link.url"
                        :as="Link"
                        :href="link.url"
                        size="sm"
                        :variant="link.active ? 'primary' : 'outline'"
                        :aria-current="link.active ? 'page' : undefined"
                        preserve-scroll
                    >
                        <!-- Laravel renvoie « &laquo; Précédent » : l'entité
                             doit être rendue, pas affichée telle quelle. -->
                        <span v-html="link.label" />
                    </Button>
                    <Button v-else type="button" size="sm" variant="outline" disabled>
                        <span v-html="link.label" />
                    </Button>
                </template>
            </nav>
        </Card>
    </div>
</template>
