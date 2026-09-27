<script setup>
import { computed } from 'vue';
import Avatar from '@/Components/Shadcn/Avatar.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { CalendarDays, ClipboardPlus, DoorOpen, HeartPulse, MapPin, ShieldCheck, Stethoscope } from 'lucide-vue-next';
import { formatDate, formatDateTime } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';
import { surgeryStatus } from '@/utilities/surgicalRequestStatus';

/**
 * ADR-048 — l'en-tête du dossier du bloc, en deux lignes serrées.
 *
 * L'intervention et son statut, puis ce qu'on regarde avant d'agir : qui, quel
 * âge, quel chirurgien et quand, quelle salle. La transmission du demandeur
 * reste sous les yeux à chaque étape, pas seulement dans la première.
 */
const props = defineProps({
    surgicalRequest: Object,
    workspace: { type: String, default: 'surgery' },
    /** La transmission se relit ici à chaque étape, sauf là où elle est déjà affichée en entier. */
    showNotes: { type: Boolean, default: true },
});

const patient = computed(() => props.surgicalRequest.episode?.patient ?? {});
const age = computed(() => {
    if (patient.value.declared_age !== null && patient.value.declared_age !== undefined) {
        return `${patient.value.declared_age} ans${patient.value.birth_date ? ' (déclaré)' : ''}`;
    }
    if (!patient.value.birth_date) return 'Âge non renseigné';

    const birth = new Date(patient.value.birth_date);
    const today = new Date();
    let years = today.getFullYear() - birth.getFullYear();
    const monthDifference = today.getMonth() - birth.getMonth();
    if (monthDifference < 0 || (monthDifference === 0 && today.getDate() < birth.getDate())) years -= 1;

    return `${years} ans${patient.value.birth_date_is_approximate ? ' estimé' : ''}`;
});

const sexLabel = computed(() => ({ M: 'Masculin', F: 'Féminin' }[patient.value.sex] ?? patient.value.sex ?? 'Sexe non renseigné'));
const status = computed(() => surgeryStatus(props.surgicalRequest.status));
const workspaceMeta = computed(() => props.workspace === 'anesthesia'
    ? { label: 'Dossier anesthésique', icon: ShieldCheck }
    : { label: 'Dossier chirurgical', icon: DoorOpen });
const originLabel = computed(() => ({
    RECEPTION: 'Demande initiée à la Réception',
    MEDICINE: 'Décision prise en consultation',
    MATERNITY: 'Orientation depuis la Maternité',
    HOSPITALIZATION: 'Transfert depuis l’Hospitalisation',
}[props.surgicalRequest.origin] ?? 'Origine non renseignée'));

/** ADR-168 — les aides sont les membres « Chirurgien » de l'équipe. */
const assistants = computed(() => (props.surgicalRequest.team_members ?? [])
    .filter((member) => member.function === 'SURGEON' && member.user?.id !== props.surgicalRequest.surgeon?.id)
    .map((member) => member.user?.name)
    .filter(Boolean));

const facts = computed(() => [
    {
        label: 'Âge et sexe',
        value: `${age.value} · ${sexLabel.value}`,
        title: patient.value.birth_date ? `Né(e) le ${formatDate(patient.value.birth_date)}` : 'Date de naissance non renseignée',
        icon: HeartPulse,
    },
    {
        label: 'Chirurgien',
        value: props.surgicalRequest.surgeon
            ? props.surgicalRequest.surgeon.name + (assistants.value.length ? ` + ${assistants.value.length} aide${assistants.value.length > 1 ? 's' : ''}` : '')
            : 'Chirurgien non affecté',
        title: assistants.value.length ? `Aides : ${assistants.value.join(', ')}` : 'Chirurgien principal',
        icon: Stethoscope,
        muted: !props.surgicalRequest.surgeon,
    },
    {
        label: 'Programmée le',
        value: props.surgicalRequest.scheduled_at ? formatDateTime(props.surgicalRequest.scheduled_at) : 'Non programmée',
        icon: CalendarDays,
        muted: !props.surgicalRequest.scheduled_at,
    },
    { label: 'Salle', value: props.surgicalRequest.operating_room ?? 'Salle non affectée', icon: MapPin, muted: !props.surgicalRequest.operating_room },
]);
</script>

<template>
    <Card class="overflow-hidden">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-3 px-5 py-3.5">
            <div class="flex min-w-[18rem] flex-1 items-center gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true">
                    <component :is="workspaceMeta.icon" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="font-heading text-lg font-bold tracking-tight text-foreground">{{ surgicalRequest.procedure_name }}</h1>
                        <Badge :variant="status.variant">{{ status.label }}</Badge>
                        <!-- Faits à garder sous les yeux à côté du statut : patient hospitalisé… -->
                        <slot name="status" />
                    </div>
                    <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground">
                        <span>{{ workspaceMeta.label }}</span><span aria-hidden="true">·</span><span>{{ originLabel }}</span>
                        <template v-if="surgicalRequest.episode?.episode_number"><span aria-hidden="true">·</span><span>Passage {{ surgicalRequest.episode.episode_number }}</span></template>
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 sm:ms-auto print:hidden"><slot name="actions" /></div>
        </div>

        <dl class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-border bg-muted/20 px-5 py-2.5 text-sm">
            <div class="flex min-w-0 items-center gap-2">
                <Avatar size="sm" variant="primary-pale" :initials="formatPatientInitials(patient)" aria-hidden="true" />
                <dt class="sr-only">Patient</dt>
                <dd class="min-w-0">
                    <span class="font-semibold text-foreground">{{ formatPatientName(patient) }}</span>
                    <span class="ms-1.5 font-mono text-xs text-muted-foreground">{{ patient.patient_number ?? 'N° patient absent' }}</span>
                </dd>
            </div>
            <div v-for="fact in facts" :key="fact.label" class="flex min-w-0 items-center gap-1.5" :title="fact.title ?? fact.label">
                <component :is="fact.icon" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                <dt class="sr-only">{{ fact.label }}</dt>
                <dd :class="['truncate', fact.muted ? 'text-muted-foreground' : 'text-foreground']">{{ fact.value }}</dd>
            </div>
        </dl>

        <p v-if="showNotes && surgicalRequest.notes" class="flex items-start gap-2 border-t border-border px-5 py-2.5 text-xs text-muted-foreground" :title="surgicalRequest.notes">
            <ClipboardPlus class="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary" aria-hidden="true" />
            <span class="line-clamp-2"><strong class="font-semibold text-foreground">Transmission :</strong> {{ surgicalRequest.notes }}</span>
        </p>
    </Card>
</template>
