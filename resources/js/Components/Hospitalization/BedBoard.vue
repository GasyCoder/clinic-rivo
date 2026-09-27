<script setup>
import { Link } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { BED_STATE, careLevelVariant } from '@/utilities/hospitalBeds';
import { formatDateTime } from '@/utilities/date';
import { BedDouble, CircleAlert, Wrench } from 'lucide-vue-next';

/**
 * ADR-164 — le plan des lits du site : qui occupe quoi, ce qui est libre, ce
 * qui est hors service, et les patients admis qui n'ont pas encore de lit.
 * L'occupation se lit sur les séjours en cours ; rien n'y est saisi.
 */
defineProps({
    services: { type: Array, default: () => [] },
    unassigned: { type: Array, default: () => [] },
});

const freeIn = (service) => service.rooms.reduce((total, room) => total + room.beds.filter((bed) => bed.state === 'FREE').length, 0);
const bedsIn = (service) => service.rooms.reduce((total, room) => total + room.beds.length, 0);
</script>

<template>
    <div class="space-y-4">
        <Card v-if="unassigned.length" class="border-amber-300 bg-amber-50/60 p-4 dark:border-amber-500/40 dark:bg-amber-500/5">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground">
                <CircleAlert class="h-4 w-4 text-amber-600 dark:text-amber-400" aria-hidden="true" />
                {{ unassigned.length > 1 ? `${unassigned.length} patients admis sans lit` : 'Un patient admis sans lit' }}
            </h2>
            <ul class="mt-2 flex flex-wrap gap-2">
                <li v-for="stay in unassigned" :key="stay.stay_uuid">
                    <Link :href="`/hospitalisation/${stay.stay_uuid}`" class="inline-flex flex-col rounded-md border border-border bg-card px-3 py-1.5 text-xs hover:border-primary">
                        <span class="font-semibold text-foreground">{{ stay.patient }}</span>
                        <span class="text-muted-foreground">Passage {{ stay.episode_number }} · admis le {{ formatDateTime(stay.admitted_at) }}</span>
                    </Link>
                </li>
            </ul>
        </Card>

        <div class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
            <span v-for="state in ['FREE', 'OCCUPIED', 'OUT_OF_SERVICE']" :key="state" class="inline-flex items-center gap-1.5">
                <span :class="['h-2.5 w-2.5 rounded-sm border', BED_STATE[state].swatch]" />{{ BED_STATE[state].label }}
            </span>
        </div>

        <Card v-for="service in services" :key="service.uuid" class="overflow-hidden">
            <header class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border px-4 py-3">
                <h2 class="text-sm font-bold text-foreground">{{ service.name }}</h2>
                <Badge :variant="careLevelVariant(service.care_level)">{{ service.care_level_label }}</Badge>
                <span class="ms-auto text-xs text-muted-foreground">{{ freeIn(service) }} libre{{ freeIn(service) > 1 ? 's' : '' }} sur {{ bedsIn(service) }}</span>
            </header>
            <p v-if="!service.rooms.length" class="px-4 py-3 text-sm text-muted-foreground">Aucune chambre dans ce service.</p>
            <div v-else class="grid gap-3 p-4 md:grid-cols-2 2xl:grid-cols-3">
                <div v-for="room in service.rooms" :key="room.uuid" class="rounded-lg border border-border bg-background p-3">
                    <p class="text-xs font-semibold text-foreground">{{ room.name }}</p>
                    <ul class="mt-2 space-y-1.5">
                        <li v-for="bed in room.beds" :key="bed.uuid">
                            <component
                                :is="bed.occupant?.stay_uuid ? Link : 'div'"
                                :href="bed.occupant?.stay_uuid ? `/hospitalisation/${bed.occupant.stay_uuid}` : undefined"
                                :class="['flex items-center gap-2 rounded-md border px-2.5 py-1.5 text-xs', BED_STATE[bed.state].chip]"
                            >
                                <BedDouble v-if="bed.state !== 'OUT_OF_SERVICE'" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                <Wrench v-else class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                <span class="font-semibold">{{ bed.label }}</span>
                                <span v-if="bed.occupant" class="min-w-0 truncate">{{ bed.occupant.patient ?? bed.occupant.episode_number }}</span>
                                <span v-else-if="bed.state === 'OUT_OF_SERVICE'" class="min-w-0 truncate" :title="bed.out_of_service_reason">Hors service</span>
                                <span v-else>Libre</span>
                            </component>
                        </li>
                    </ul>
                </div>
            </div>
        </Card>
    </div>
</template>
