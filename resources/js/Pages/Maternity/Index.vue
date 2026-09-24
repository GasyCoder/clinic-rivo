<script setup>
import { Head } from '@inertiajs/vue3';
import { HeartPulse, Scissors, Stethoscope } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActivePassageBoard from '@/Components/Clinical/ActivePassageBoard.vue';
import SoinsTabs from '@/Components/Care/SoinsTabs.vue';
import SoinsWorkspaceHeader from '@/Components/Care/SoinsWorkspaceHeader.vue';
import Badge from '@/Components/Shadcn/Badge.vue';

defineOptions({ layout: AppLayout });

/**
 * ADR-177 — la Maternité lit le même tableau des passages que les Soins et la
 * Médecine. Ce qui lui est propre se lit sur chaque ligne : où est allée la
 * patiente ensuite — le médecin, la césarienne (ADR-135). Jamais deviné :
 * une vraie orientation Médecine, une vraie demande chirurgicale.
 */
defineProps({
    passages: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    view: { type: String, default: 'waiting' },
    search: { type: String, default: '' },
    /** Par UUID de passage : `{ medicine, cesarean }`. */
    followUps: { type: Object, default: () => ({}) },
});

/** Le ton du statut, pas ses classes : le `Badge` porte déjà le vocabulaire. */
const medicineTone = (medicine) => ({ PENDING: 'warning', IN_PROGRESS: 'info' }[medicine.status] ?? 'success');
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
            description="Patientes en attente par ordre d’arrivée, puis celles prises en charge, puis celles déjà terminées."
        />

        <ActivePassageBoard module="MATERNITY" base-url="/maternity" :passages="passages" :counts="counts" :view="view" :search="search">
            <template #row-details="{ row }">
                <div v-if="followUps[row.uuid]?.medicine || followUps[row.uuid]?.cesarean" class="mt-1.5 flex max-w-[260px] flex-wrap gap-1">
                    <Badge v-if="followUps[row.uuid].medicine" :tone="medicineTone(followUps[row.uuid].medicine)" class="px-2 py-0.5 text-[11px]">
                        <Stethoscope class="h-3 w-3" aria-hidden="true" />{{ followUps[row.uuid].medicine.label }}
                    </Badge>
                    <Badge v-if="followUps[row.uuid].cesarean" tone="warning" class="px-2 py-0.5 text-[11px]">
                        <Scissors class="h-3 w-3" aria-hidden="true" />{{ followUps[row.uuid].cesarean.label }}
                    </Badge>
                    <span v-if="followUps[row.uuid].medicine?.doctor" class="w-full text-[11px] text-muted-foreground">Médecin : {{ followUps[row.uuid].medicine.doctor }}</span>
                </div>
            </template>
        </ActivePassageBoard>
    </div>
</template>
