<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Baby, CircleCheck, FileText, HeartPulse, Search, UserCheck } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    orientations: { type: Object, default: () => ({ data: [], links: [] }) },
    counts: { type: Object, default: () => ({}) },
    filter: { type: String, default: 'active' },
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
 * Les deux cartes étaient écrites à la main ici : elles rejoignent le
 * composant partagé pour qu'une file ne change pas d'allure selon le
 * service. Le compte reste celui du serveur — recalculé depuis la page
 * affichée, il mentirait dès la deuxième.
 */
const counterTiles = computed(() => [
    {
        value: 'active',
        label: 'À prendre en charge',
        hint: 'Patientes en attente',
        icon: Baby,
        tone: 'red',
        count: props.counts.active,
        active: props.filter === 'active',
    },
    {
        value: 'completed',
        label: 'Prises en charge terminées',
        hint: 'Dossiers clos',
        icon: CircleCheck,
        tone: 'emerald',
        count: props.counts.completed,
        active: props.filter === 'completed',
    },
]);

/** Le ton du statut, pas ses classes : le `Badge` porte déjà le vocabulaire. */
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

    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-rose-50 text-rose-700 dark:bg-rose-950/30 dark:text-rose-300">
                    <HeartPulse class="h-5 w-5" />
                </span>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-rose-600 dark:text-rose-300">Workspace paramédical spécialisé</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold tracking-tight text-foreground">Maternité</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Orientations, suivi obstétrical, accouchement et nouveau-né sur le même passage.</p>
                </div>
            </div>
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
        </header>

        <QueueCounters class="lg:grid-cols-2" :tiles="counterTiles" @select="visit" />

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
                        <Badge :tone="statusTone(orientation.status)">{{ orientation.status_label }}</Badge>
                        <p class="mt-1.5">Orientée le {{ formatDate(orientation.oriented_at) }}</p>
                        <p v-if="orientation.accepted_by" class="mt-1">Prise par {{ orientation.accepted_by }}</p>
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
                    <p class="mt-3 text-sm font-semibold text-foreground">Aucune orientation Maternité dans cette vue</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Changez de filtre ci-dessus, ou la Réception et la Médecine n’ont encore orienté personne.
                    </p>
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
