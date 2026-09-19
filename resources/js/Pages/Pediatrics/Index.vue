<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import { Baby, CircleCheck, FolderOpen, Search } from 'lucide-vue-next';
import { formatDateTime } from '@/utilities/date';

defineOptions({ layout: AppLayout });

/**
 * ADR-114 — la file Pédiatrie : les enfants orientés par Médecine.
 *
 * Volontairement simple — file, prise en charge, sortie médicale. Aucune
 * fiche pédiatrique n'est inventée tant que la clinique n'en a pas fourni une.
 */
const props = defineProps({
    orientations: { type: Object, required: true },
    counts: { type: Object, required: true },
    filter: { type: String, default: 'active' },
    search: { type: String, default: '' },
});

const query = ref(props.search);

const visit = (params) => router.get('/pediatrie', params, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
});

const tiles = computed(() => [
    { value: 'active', label: 'En cours', hint: 'En attente ou pris en charge', icon: Baby, tone: 'primary', count: props.counts.active ?? 0, active: props.filter === 'active' },
    { value: 'completed', label: 'Terminés', hint: 'Sortie prononcée', icon: CircleCheck, tone: 'neutral', count: props.counts.completed ?? 0, active: props.filter === 'completed' },
]);

const STATUS = {
    PENDING: { label: 'En attente', variant: 'warning' },
    IN_PROGRESS: { label: 'Pris en charge', variant: 'default' },
    COMPLETED: { label: 'Terminé', variant: 'outline' },
};
</script>

<template>
    <Head title="Pédiatrie" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <Card class="p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                        <Baby class="h-5 w-5" />
                    </span>
                    <div>
                        <h1 class="font-heading text-lg font-bold text-foreground">Pédiatrie</h1>
                        <p class="mt-0.5 text-sm text-muted-foreground">Les enfants orientés vers la Pédiatrie par le médecin.</p>
                    </div>
                </div>

                <form class="w-full sm:w-72" @submit.prevent="visit({ q: query, filter })">
                    <IconInput v-model="query" :icon="Search" placeholder="Patient, n° patient ou passage…" aria-label="Rechercher un patient en Pédiatrie" />
                </form>
            </div>
        </Card>

        <QueueCounters :tiles="tiles" @select="(value) => visit({ q: search, filter: value })" />

        <Card class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] border-collapse text-sm">
                    <caption class="sr-only">File Pédiatrie</caption>
                    <thead>
                        <tr class="border-b border-border bg-muted/40 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            <th scope="col" class="px-4 py-3 text-start">Patient</th>
                            <th scope="col" class="px-4 py-3 text-start">Motif</th>
                            <th scope="col" class="px-4 py-3 text-start">État</th>
                            <th scope="col" class="px-4 py-3 text-start">{{ filter === 'active' ? 'Orienté' : 'Terminé' }}</th>
                            <th scope="col" class="px-4 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="orientation in orientations.data" :key="orientation.uuid" class="align-top">
                            <td class="px-4 py-3">
                                <Link :href="`/pediatrie/${orientation.uuid}`" class="block font-semibold text-foreground hover:text-primary">{{ orientation.patient.name }}</Link>
                                <span class="mt-0.5 block text-xs text-muted-foreground">
                                    {{ orientation.patient.patient_number }} · Passage {{ orientation.episode_number }}<template v-if="orientation.patient.age !== null"> · {{ orientation.patient.age }} ans</template>
                                </span>
                            </td>
                            <td class="max-w-xs px-4 py-3">
                                <span class="line-clamp-2 text-foreground">{{ orientation.reason || '—' }}</span>
                                <Badge v-if="orientation.priority === 'EMERGENCY'" variant="destructive" class="mt-1">Urgence</Badge>
                            </td>
                            <td class="px-4 py-3">
                                <Badge :variant="STATUS[orientation.status]?.variant ?? 'outline'">{{ STATUS[orientation.status]?.label ?? orientation.status_label }}</Badge>
                                <span v-if="orientation.accepted_by" class="mt-1 block text-xs text-muted-foreground">{{ orientation.accepted_by }}</span>
                            </td>
                            <td class="px-4 py-3 text-foreground">{{ formatDateTime(filter === 'active' ? orientation.oriented_at : orientation.completed_at) }}</td>
                            <td class="px-4 py-3 text-end">
                                <Button :as="Link" :href="`/pediatrie/${orientation.uuid}`" size="sm" variant="white-outline"><FolderOpen class="h-4 w-4" />Ouvrir</Button>
                            </td>
                        </tr>
                        <tr v-if="!orientations.data.length">
                            <td colspan="5" class="px-4 py-12 text-center text-sm text-muted-foreground">
                                {{ filter === 'active' ? 'Aucun enfant en attente en Pédiatrie.' : 'Aucune prise en charge terminée.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="orientations.links?.length > 3" class="flex flex-wrap items-center justify-center gap-1 border-t border-border p-3">
                <Link
                    v-for="link in orientations.links"
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
