<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { SquareArrowOutUpRight } from 'lucide-vue-next';
import VitalSignsStrip from '@/Components/Clinical/VitalSignsStrip.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';

/**
 * Les constantes du passage, relevées une seule fois par les Soins et lues ici
 * comme en Médecine et au bloc (ADR-054). La Maternité ne les ressaisit pas :
 * une seconde version d'une mesure que personne n'a prise deux fois finirait
 * par contredire la première (ADR-077). Les corriger se fait sur la fiche qui
 * les porte (ADR-092/093), jamais dans un second formulaire.
 */
const props = defineProps({
    careRecord: { type: Object, default: null },
    careRecordUrl: { type: String, default: null },
    allergies: { type: Array, default: () => [] },
});

const bloodPressure = computed(() => {
    const systolic = props.careRecord?.blood_pressure_systolic;
    const diastolic = props.careRecord?.blood_pressure_diastolic;

    return systolic && diastolic ? `${systolic}/${diastolic}` : null;
});
</script>

<template>
    <div class="space-y-2">
        <VitalSignsStrip
            v-if="careRecord"
            :care-record="careRecord"
            :blood-pressure="bloodPressure"
            :allergies="allergies"
            :recorded-at="careRecord.updated_at ?? careRecord.created_at"
        />

        <!-- Sans passage par les Soins, il n'y a aucune constante à montrer :
             on le dit plutôt que d'afficher des tirets qui se liraient « normal ». -->
        <Card v-else class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
            <p class="text-xs text-muted-foreground">Aucune constante relevée pour ce passage : la patiente n’est pas passée par les Soins, ou votre compte ne peut pas les consulter.</p>
            <Button v-if="careRecordUrl" :as="Link" :href="careRecordUrl" size="sm" variant="outline">
                <SquareArrowOutUpRight class="h-4 w-4" />Ouvrir la fiche de soins
            </Button>
        </Card>

        <div v-if="careRecord && careRecordUrl" class="flex justify-end">
            <Button :as="Link" :href="careRecordUrl" size="sm" variant="ghost">
                <SquareArrowOutUpRight class="h-4 w-4" />Ouvrir la fiche de soins complète
            </Button>
        </div>
    </div>
</template>
