<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { CircleCheck, CircleX, Clock, LogOut, NotebookText, Undo2, UserRoundCheck, UsersRound } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActivePassageBoard from '@/Components/Clinical/ActivePassageBoard.vue';
import SoinsTabs from '@/Components/Care/SoinsTabs.vue';
import SoinsWorkspaceHeader from '@/Components/Care/SoinsWorkspaceHeader.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { itemQuantity } from '@/utilities/episodePathway';

defineOptions({ layout: AppLayout });

/**
 * ADR-177 — les Soins voient tous les passages ouverts dont l'accueil est
 * terminé, pas seulement ceux que la désignation leur envoyait. La suggestion
 * de l'accueil est affichée, jamais un filtre caché.
 */
defineProps({
    passages: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    view: { type: String, default: 'waiting' },
    search: { type: String, default: '' },
});

const { can } = usePermissions();

/**
 * Ce qu'un médecin a demandé aux Soins pour ce passage (ADR-118) : qui,
 * quelle suite, quels actes. Une orientation sans demande n'en invente pas.
 */
const ITEM_ICONS = { DONE: CircleCheck, NOT_PERFORMED: CircleX, CANCELLED: CircleX, PARTIAL: Clock, PENDING: Clock };
const ITEM_TONES = {
    DONE: 'text-emerald-600 dark:text-emerald-300',
    NOT_PERFORMED: 'text-red-600 dark:text-red-300',
    CANCELLED: 'text-muted-foreground line-through',
    PARTIAL: 'text-amber-600 dark:text-amber-300',
    PENDING: 'text-muted-foreground',
};
</script>

<template>
    <Head title="Soins" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <SoinsTabs current="care" />

        <SoinsWorkspaceHeader
            :icon="UserRoundCheck"
            title="Soins"
            eyebrow="Workspace paramédical"
            description="Patients en attente par ordre d’arrivée, puis ceux pris en charge aux Soins, puis ceux déjà terminés."
        >
            <Button v-if="can('patients.view')" :as="Link" href="/patients" variant="outline">
                <UsersRound class="h-4 w-4" aria-hidden="true" />Dossiers patients
            </Button>
        </SoinsWorkspaceHeader>

        <ActivePassageBoard module="CARE" base-url="/care" :passages="passages" :counts="counts" :view="view" :search="search">
            <template #row-details="{ row }">
                <div v-if="row.care_request" class="mt-1.5 max-w-[260px] space-y-1 rounded-md border border-border bg-muted/30 px-2 py-1.5">
                    <p v-if="row.care_request.requested_by?.length" class="text-[11px] font-semibold text-foreground">Demande de {{ row.care_request.requested_by.join(', ') }}</p>
                    <ul v-if="row.care_request.items?.length" class="space-y-0.5">
                        <li v-for="(item, index) in row.care_request.items" :key="index" :class="cn('flex items-center gap-1 text-[11px]', ITEM_TONES[item.state] ?? ITEM_TONES.PENDING)">
                            <component :is="ITEM_ICONS[item.state] ?? Clock" class="h-3 w-3 shrink-0" aria-hidden="true" />
                            <span class="truncate">{{ item.name }} {{ itemQuantity(item.quantity) }}</span>
                            <span class="sr-only">— {{ item.state_label }}</span>
                        </li>
                    </ul>
                    <p
                        v-if="row.care_request.follow_up"
                        :class="cn('flex items-center gap-1 text-[11px] font-semibold', row.care_request.follow_up.code === 'RETURN_TO_MEDICINE' ? 'text-primary' : 'text-muted-foreground')"
                    >
                        <component :is="row.care_request.follow_up.code === 'RETURN_TO_MEDICINE' ? Undo2 : LogOut" class="h-3 w-3 shrink-0" aria-hidden="true" />
                        {{ row.care_request.follow_up.label }}
                    </p>
                </div>
            </template>

            <template #row-actions="{ row, toolClass }">
                <!-- ADR-118 — tous les journaux de traitement du patient, en un document ;
                     un passage terminé porte déjà son propre journal. -->
                <Button
                    v-if="can('treatment_journal.view') && !row.actions.journal_url"
                    :as="Link"
                    :href="`/patients/${row.episode.patient.uuid}/journaux-de-traitement`"
                    size="sm"
                    :class="toolClass"
                    variant="ghost"
                    title="Journaux de traitement du patient (PDF)"
                    aria-label="Journaux de traitement du patient"
                >
                    <NotebookText class="h-4 w-4" aria-hidden="true" />
                </Button>
            </template>
        </ActivePassageBoard>
    </div>
</template>
