<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Boxes, History, PackageOpen, PackageSearch, ReceiptText, Search } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import CareConsumableQueue from '@/Pages/Pharmacy/Partials/CareConsumableQueue.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    capabilities: { type: Object, required: true },
    careConsumables: { type: Object, required: true },
});

const search = ref('');
const tab = ref('to-serve');

const summary = computed(() => props.careConsumables.summary ?? {});
const requests = computed(() => props.careConsumables.requests ?? []);

/**
 * Les compteurs de la file (ADR-099, même composant que Soins, Médecine et
 * le Laboratoire). Les trois premiers viennent du serveur — recalculés
 * depuis la liste affichée, ils mentiraient dès qu'elle est tronquée.
 *
 * « Traitées récemment » est le seul compte issu de la page : la requête ne
 * ramène que les vingt dernières demandes servies ou annulées, et son
 * libellé le dit plutôt que de laisser croire à un total.
 */
const counterTiles = computed(() => [
    {
        value: 'to-serve',
        label: 'À servir',
        hint: 'Déclarés aux Soins, en Maternité ou au bloc',
        icon: PackageSearch,
        tone: 'amber',
        count: (summary.value.pending ?? 0) + (summary.value.partially_served ?? 0),
        active: tab.value === 'to-serve',
    },
    {
        value: 'partial',
        label: 'Servies partiellement',
        hint: 'Un reliquat reste à sortir',
        icon: PackageOpen,
        tone: 'sky',
        count: summary.value.partially_served ?? 0,
        active: tab.value === 'partial',
    },
    {
        value: '__lines__',
        label: 'Unités à sortir',
        hint: 'Tous passages confondus',
        icon: Boxes,
        tone: 'neutral',
        count: summary.value.lines_to_serve ?? 0,
        filterable: false,
    },
    {
        value: 'unbilled',
        label: 'Non facturés',
        hint: 'À régulariser à la Réception',
        icon: ReceiptText,
        tone: 'red',
        count: summary.value.unbilled_lines ?? 0,
        active: tab.value === 'unbilled',
    },
    {
        value: 'history',
        label: 'Traitées récemment',
        hint: '20 dernières demandes',
        icon: History,
        tone: 'emerald',
        count: requests.value.filter((request) => ! request.can_be_served).length,
        active: tab.value === 'history',
    },
]);
</script>

<template>
    <Head title="Consommables Soins" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <PageHeader
            eyebrow="Pharmacie"
            title="Consommables Soins"
            description="Le matériel déjà utilisé sur un patient, aux Soins, en Maternité ou au bloc opératoire. Sa sortie du stock n’attend aucun règlement."
            icon="user-check"
            tone="amber"
        >
            <template #actions>
                <label class="block w-full sm:w-80">
                    <span class="sr-only">Rechercher une demande</span>
                    <IconInput
                        v-model="search"
                        :icon="Search"
                        type="search"
                        placeholder="Patient, passage ou matériel…"
                    />
                </label>
            </template>
        </PageHeader>

        <QueueCounters class="lg:grid-cols-5" :tiles="counterTiles" @select="tab = $event" />

        <CareConsumableQueue
            :care-consumables="careConsumables"
            :capabilities="capabilities"
            :search="search"
            :tab="tab"
            @change-tab="tab = $event"
        />
    </div>
</template>
