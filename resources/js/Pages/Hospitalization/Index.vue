<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import { BedDouble, FolderOpen, Search } from 'lucide-vue-next';
import { formatDateTime } from '@/utilities/date';

defineOptions({ layout: AppLayout });

/**
 * ADR-113 — les patients hospitalisés.
 *
 * Le séjour commence à la demande du médecin (admission automatique) et se
 * termine par sa sortie médicale. Cet écran ne facture aucun repas et
 * n'encaisse rien.
 */
const props = defineProps({
    stays: { type: Object, required: true },
    counts: { type: Object, required: true },
    search: { type: String, default: '' },
});

const query = ref(props.search);

const visit = (params) => router.get('/hospitalisation', params, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
});

// ADR-156 — un seul compte : les patients réellement au lit. Les sorties se
// suivent à la Réception (« Sorties & règlements »), pas ici.
const tiles = computed(() => [
    { value: 'active', label: 'Hospitalisés', hint: 'Séjours en cours', icon: BedDouble, tone: 'primary', count: props.counts.active ?? 0, active: true },
]);

const PRIORITY = {
    URGENT: { label: 'Urgent', variant: 'destructive' },
};

/** Durée du séjour en jours révolus, depuis l'admission. */
const stayDays = (stay) => {
    const end = stay.discharged_at ? new Date(stay.discharged_at) : new Date();
    const days = Math.floor((end - new Date(stay.admitted_at)) / 86400000);

    return days < 1 ? 'Moins d’un jour' : `${days} jour${days > 1 ? 's' : ''}`;
};
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

        <QueueCounters :tiles="tiles" @select="() => visit({ q: search })" />

        <Card class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[860px] border-collapse text-sm">
                    <caption class="sr-only">Patients hospitalisés</caption>
                    <thead>
                        <tr class="border-b border-border bg-muted/40 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            <th scope="col" class="px-4 py-3 text-start">Patient</th>
                            <th scope="col" class="px-4 py-3 text-start">Motif</th>
                            <th scope="col" class="px-4 py-3 text-start">Service · Chambre</th>
                            <th scope="col" class="px-4 py-3 text-start">Entrée</th>
                            <th scope="col" class="px-4 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="stay in stays.data" :key="stay.uuid" class="align-top">
                            <td class="px-4 py-3">
                                <Link :href="`/hospitalisation/${stay.uuid}`" class="block font-semibold text-foreground hover:text-primary">{{ stay.patient.name }}</Link>
                                <span class="mt-0.5 block text-xs text-muted-foreground">
                                    {{ stay.patient.patient_number }} · Passage {{ stay.episode_number }}<template v-if="stay.patient.age !== null"> · {{ stay.patient.age }} ans</template>
                                </span>
                            </td>
                            <td class="max-w-xs px-4 py-3">
                                <span class="line-clamp-2 text-foreground">{{ stay.reason || '—' }}</span>
                                <span class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                    <Badge v-if="PRIORITY[stay.priority]" :variant="PRIORITY[stay.priority].variant">{{ PRIORITY[stay.priority].label }}</Badge>
                                    <span v-if="stay.requested_by">Dr {{ stay.requested_by }}</span>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="block text-foreground">{{ stay.service || 'Service non précisé' }}</span>
                                <span class="mt-0.5 block text-xs text-muted-foreground">{{ stay.room_bed || 'Chambre / lit non renseigné' }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="block text-foreground">{{ formatDateTime(stay.admitted_at) }}</span>
                                <span class="mt-0.5 block text-xs text-muted-foreground">
                                    <template v-if="stay.discharged_at">Sorti le {{ formatDateTime(stay.discharged_at) }} · {{ stay.discharge_type }}</template>
                                    <template v-else>{{ stayDays(stay) }} · {{ stay.diet_entries_count }} ligne{{ stay.diet_entries_count > 1 ? 's' : '' }} de régime</template>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-end">
                                <Button :as="Link" :href="`/hospitalisation/${stay.uuid}`" size="sm" variant="white-outline">
                                    <FolderOpen class="h-4 w-4" />Ouvrir
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="!stays.data.length">
                            <td colspan="5" class="px-4 py-12 text-center text-sm text-muted-foreground">
                                {{ search ? 'Aucun séjour ne correspond à cette recherche.' : 'Aucun patient hospitalisé actuellement.' }}
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
