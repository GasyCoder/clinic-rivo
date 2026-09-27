<script setup>
import { computed } from 'vue';
import { Lock, Pill } from 'lucide-vue-next';
import StayPrescriptions from '@/Components/Hospitalization/StayPrescriptions.vue';
import Card from '@/Components/Shadcn/Card.vue';

/**
 * ADR-205 — l'ordonnance de la sage-femme, à son étape du parcours Maternité.
 *
 * Le même écran que l'ordonnance du séjour (ADR-162) : mêmes lignes, même
 * éditeur, même relecture (ADR-110, ADR-128), même confirmation signée
 * (ADR-106). Seules changent l'adresse des gestes et la délivrance, qui attend
 * ici le règlement à la Caisse (ADR-049).
 *
 * Sans le droit, l'étape ne se tait pas : elle nomme ce qui manque et où
 * l'accorder (ADR-154, ADR-158).
 */
const props = defineProps({
    orientationUuid: { type: String, required: true },
    /** `null` : le compte ne lit pas les ordonnances (`prescriptions.view`). */
    prescriptions: { type: Array, default: null },
    options: { type: Object, default: () => ({ medicines: [], routes: [] }) },
    capabilities: { type: Object, required: true },
    patient: { type: Object, required: true },
    careRecord: { type: Object, default: null },
    allergies: { type: Array, default: () => [] },
    /** Le dossier est encore ouvert : une ordonnance peut en partir. */
    active: { type: Boolean, default: false },
});

/** L'âge compte pour relire une dose (ADR-128) : calculé sur la naissance, sinon l'âge déclaré. */
const age = computed(() => {
    const birth = props.patient?.birth_date ? new Date(props.patient.birth_date) : null;

    if (birth && ! Number.isNaN(birth.getTime())) {
        const today = new Date();
        const beforeBirthday = today.getMonth() < birth.getMonth()
            || (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate());

        return today.getFullYear() - birth.getFullYear() - (beforeBirthday ? 1 : 0);
    }

    return props.patient?.declared_age ?? null;
});
const safety = computed(() => ({
    age: age.value,
    weightKg: props.careRecord?.weight_kg ? Number(props.careRecord.weight_kg) : null,
    allergies: props.allergies ?? [],
}));

const canPrescribe = computed(() => Boolean(props.capabilities.can_prescribe));
/** Ce qui manque au compte pour prescrire ici — dit plutôt que caché. */
const missingRight = computed(() => (props.active && ! canPrescribe.value));
</script>

<template>
    <div class="space-y-4">
        <Card v-if="missingRight" class="flex items-start gap-3 border-dashed p-4">
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground"><Lock class="h-4 w-4" aria-hidden="true" /></span>
            <div class="min-w-0 text-sm">
                <p class="font-semibold text-foreground">Prescrire demande les droits de prescription</p>
                <p class="mt-1 text-xs leading-5 text-muted-foreground">
                    « prescriptions.create », et voir le référentiel (« medicines.view », « stock.availability.view »).
                    Le profil sage-femme les recommande ; ils s’accordent dans Rôles &amp; permissions, au socle du rôle ou en exception sur le compte.
                </p>
            </div>
        </Card>

        <Card v-if="prescriptions === null && ! canPrescribe" class="flex items-center gap-3 p-4 text-xs text-muted-foreground">
            <Pill class="h-4 w-4 shrink-0" aria-hidden="true" />Ordonnances non visibles avec vos droits (prescriptions.view).
        </Card>
        <StayPrescriptions
            v-else
            :base-url="`/maternity/orientations/${orientationUuid}`"
            context="maternity"
            :prescriptions="prescriptions"
            :medicines="options.medicines ?? []"
            :routes="options.routes ?? []"
            :can-prescribe="canPrescribe"
            :patient="safety"
        />
    </div>
</template>
