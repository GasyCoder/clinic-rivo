<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import { ArrowRightLeft, CircleCheck, FolderOpen, Search } from 'lucide-vue-next';
import { formatDateTime } from '@/utilities/date';

defineOptions({ layout: AppLayout });

/**
 * ADR-114 — les patients référés vers un autre établissement.
 *
 * La demande part en un clic depuis la consultation ; l'établissement se
 * complète ici, puis « Transfert effectué » constate le départ. Jusque-là,
 * le patient reste en soins. Cet écran n'encaisse rien.
 */
const props = defineProps({
    referrals: { type: Object, required: true },
    counts: { type: Object, required: true },
    filter: { type: String, default: 'pending' },
    search: { type: String, default: '' },
});

const query = ref(props.search);

const visit = (params) => router.get('/transferts', params, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
});

// La carte est le filtre, et le compte vient du serveur.
const tiles = computed(() => [
    { value: 'pending', label: 'À transférer', hint: 'Encore à la clinique', icon: ArrowRightLeft, tone: 'primary', count: props.counts.pending ?? 0, active: props.filter === 'pending' },
    { value: 'departed', label: 'Transférés', hint: 'Départ constaté', icon: CircleCheck, tone: 'neutral', count: props.counts.departed ?? 0, active: props.filter === 'departed' },
]);
</script>

<template>
    <Head title="Transferts" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <Card class="p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                        <ArrowRightLeft class="h-5 w-5" />
                    </span>
                    <div>
                        <h1 class="font-heading text-lg font-bold text-foreground">Transferts</h1>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            Les patients que le médecin a référés vers un autre établissement ou un autre site.
                        </p>
                    </div>
                </div>

                <form class="w-full sm:w-72" @submit.prevent="visit({ q: query, filter })">
                    <IconInput
                        v-model="query"
                        :icon="Search"
                        placeholder="Patient, passage, établissement…"
                        aria-label="Rechercher un transfert"
                    />
                </form>
            </div>
        </Card>

        <QueueCounters :tiles="tiles" @select="(value) => visit({ q: search, filter: value })" />

        <Card class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[860px] border-collapse text-sm">
                    <caption class="sr-only">Transferts</caption>
                    <thead>
                        <tr class="border-b border-border bg-muted/40 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            <th scope="col" class="px-4 py-3 text-start">Patient</th>
                            <th scope="col" class="px-4 py-3 text-start">Destination</th>
                            <th scope="col" class="px-4 py-3 text-start">Motif</th>
                            <th scope="col" class="px-4 py-3 text-start">{{ filter === 'pending' ? 'Demandé' : 'Parti' }}</th>
                            <th scope="col" class="px-4 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="referral in referrals.data" :key="referral.uuid" class="align-top">
                            <td class="px-4 py-3">
                                <Link :href="`/transferts/${referral.uuid}`" class="block font-semibold text-foreground hover:text-primary">{{ referral.patient.name }}</Link>
                                <span class="mt-0.5 block text-xs text-muted-foreground">
                                    {{ referral.patient.patient_number }} · Passage {{ referral.episode_number }}<template v-if="referral.patient.age !== null"> · {{ referral.patient.age }} ans</template>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="referral.facility" class="text-foreground">{{ referral.facility }}</span>
                                <span v-else class="text-amber-700 dark:text-amber-300">À préciser</span>
                            </td>
                            <td class="max-w-xs px-4 py-3">
                                <span class="line-clamp-2 text-foreground">{{ referral.reason || '—' }}</span>
                                <span class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                    <Badge v-if="referral.priority === 'URGENT'" variant="destructive">Urgent</Badge>
                                    <span v-if="referral.referred_by">Dr {{ referral.referred_by }}</span>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-foreground">
                                {{ formatDateTime(filter === 'pending' ? referral.referred_at : referral.departed_at) }}
                            </td>
                            <td class="px-4 py-3 text-end">
                                <Button :as="Link" :href="`/transferts/${referral.uuid}`" size="sm" variant="white-outline">
                                    <FolderOpen class="h-4 w-4" />Ouvrir
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="!referrals.data.length">
                            <td colspan="5" class="px-4 py-12 text-center text-sm text-muted-foreground">
                                {{ filter === 'pending' ? 'Aucun patient en attente de transfert.' : 'Aucun transfert effectué.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="referrals.links?.length > 3" class="flex flex-wrap items-center justify-center gap-1 border-t border-border p-3">
                <Link
                    v-for="link in referrals.links"
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
