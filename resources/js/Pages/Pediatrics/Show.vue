<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import FormError from '@/Components/UI/FormError.vue';
import ClinicalDischargeForm from '@/Components/Clinical/ClinicalDischargeForm.vue';
import { ArrowLeft, Baby, DoorOpen, FolderOpen, HandHeart } from 'lucide-vue-next';
import { formatDateTime } from '@/utilities/date';

defineOptions({ layout: AppLayout });

/**
 * ADR-114 — un enfant orienté en Pédiatrie.
 *
 * Ce que Médecine a déjà consigné est relu, jamais ressaisi : motif,
 * diagnostics, allergies. La prise en charge se conclut par la sortie
 * médicale, le même formulaire que la consultation et l'hospitalisation.
 */
const props = defineProps({
    orientation: { type: Object, required: true },
    episode: { type: Object, required: true },
    patient: { type: Object, required: true },
    allergies: { type: Array, default: () => [] },
    discharge: { type: Object, default: null },
    dischargeTypes: { type: Array, default: () => [] },
    capabilities: { type: Object, required: true },
});

const STATUS = {
    PENDING: { label: 'En attente', variant: 'warning' },
    IN_PROGRESS: { label: 'Pris en charge', variant: 'default' },
    COMPLETED: { label: 'Terminé', variant: 'outline' },
    CANCELLED: { label: 'Annulé', variant: 'outline' },
};

const accepting = ref(false);
const accept = () => router.post(`/pediatrie/${props.orientation.uuid}/prise-en-charge`, {}, {
    preserveScroll: true,
    onStart: () => { accepting.value = true; },
    onFinish: () => { accepting.value = false; },
});

const toLocalDateTimeInput = (value = new Date()) => {
    const date = new Date(value);

    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};
const showDischarge = ref(false);
const dischargeForm = useForm({
    type: 'NORMAL',
    // Le diagnostic posé en consultation est repris, corrigeable.
    final_diagnosis: props.orientation.diagnoses.join(' ; '),
    patient_condition: '',
    discharge_prescription: '',
    recommendations: '',
    follow_up_at: '',
    observations: '',
    transfer_destination: '',
    death_occurred_at: '',
    death_place: '',
    death_causes: '',
    discharged_at: toLocalDateTimeInput(),
});
const submitDischarge = () => dischargeForm.post(`/pediatrie/${props.orientation.uuid}/sortie`, { preserveScroll: true });

const allergyLabel = computed(() => (props.allergies.length ? props.allergies.join(', ') : 'Aucune allergie connue au dossier'));
</script>

<template>
    <Head :title="`Pédiatrie — ${patient.name}`" />

    <div class="mx-auto w-full max-w-screen-xl space-y-5">
        <Card class="p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Baby class="h-5 w-5" /></span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="font-heading text-lg font-bold text-foreground">{{ patient.name }}</h1>
                            <Badge :variant="STATUS[orientation.status]?.variant ?? 'outline'">{{ STATUS[orientation.status]?.label ?? orientation.status_label }}</Badge>
                            <Badge v-if="episode.priority === 'EMERGENCY'" variant="destructive">Urgence</Badge>
                        </div>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            {{ patient.patient_number }} · Passage {{ episode.episode_number }}<template v-if="patient.age !== null"> · {{ patient.age }} ans</template>
                            · Orienté le {{ formatDateTime(orientation.oriented_at) }}<template v-if="orientation.requested_by"> par Dr {{ orientation.requested_by }}</template>
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button :as="Link" href="/pediatrie" size="sm" variant="white-outline"><ArrowLeft class="h-4 w-4" />Pédiatrie</Button>
                    <Button :as="Link" :href="episode.url" size="sm" variant="white-outline"><FolderOpen class="h-4 w-4" />Passage</Button>
                </div>
            </div>
        </Card>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_24rem]">
            <Card class="min-w-0 p-5">
                <h2 class="text-sm font-semibold text-foreground">Orientation par Médecine</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-xs text-muted-foreground">Motif</dt><dd class="whitespace-pre-line text-foreground">{{ orientation.reason || '—' }}</dd></div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Diagnostics de la consultation</dt>
                        <dd v-if="orientation.diagnoses.length" class="text-foreground">
                            <ul class="list-inside list-disc"><li v-for="diagnosis in orientation.diagnoses" :key="diagnosis">{{ diagnosis }}</li></ul>
                        </dd>
                        <dd v-else class="text-muted-foreground">Aucun diagnostic consigné</dd>
                    </div>
                    <div><dt class="text-xs text-muted-foreground">Allergies</dt><dd :class="allergies.length ? 'font-medium text-destructive' : 'text-foreground'">{{ allergyLabel }}</dd></div>
                    <div v-if="orientation.accepted_at">
                        <dt class="text-xs text-muted-foreground">Prise en charge</dt>
                        <dd class="text-foreground">{{ formatDateTime(orientation.accepted_at) }}<template v-if="orientation.accepted_by"> · {{ orientation.accepted_by }}</template></dd>
                    </div>
                </dl>
            </Card>

            <aside class="space-y-5">
                <Card v-if="capabilities.can_accept" class="p-5">
                    <h2 class="text-sm font-semibold text-foreground">Prise en charge</h2>
                    <p class="mt-1 text-xs text-muted-foreground">L’enfant attend en Pédiatrie.</p>
                    <Button type="button" size="sm" class="mt-3 w-full" :disabled="accepting" @click="accept">
                        <HandHeart class="h-4 w-4" />Prendre en charge
                    </Button>
                </Card>

                <Card v-if="discharge" class="p-5">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><DoorOpen class="h-4 w-4 text-muted-foreground" />Sortie</h2>
                    <dl class="mt-3 space-y-2.5 text-sm">
                        <div><dt class="text-xs text-muted-foreground">Type</dt><dd class="text-foreground">{{ discharge.type_label }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Date</dt><dd class="text-foreground">{{ formatDateTime(discharge.discharged_at) }}<span v-if="discharge.discharged_by" class="block text-xs text-muted-foreground">Prononcée par Dr {{ discharge.discharged_by }}</span></dd></div>
                        <div v-if="discharge.final_diagnosis"><dt class="text-xs text-muted-foreground">Diagnostic final</dt><dd class="whitespace-pre-line text-foreground">{{ discharge.final_diagnosis }}</dd></div>
                        <div v-if="discharge.patient_condition"><dt class="text-xs text-muted-foreground">État du patient</dt><dd class="text-foreground">{{ discharge.patient_condition }}</dd></div>
                    </dl>
                </Card>

                <Card v-else-if="capabilities.can_discharge" class="p-5">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><DoorOpen class="h-4 w-4 text-muted-foreground" />Sortie médicale</h2>
                    <p class="mt-1 text-xs text-muted-foreground">Elle termine la prise en charge Pédiatrie. Le passage rejoint ensuite « Sorties & règlements ».</p>
                    <Button v-if="!showDischarge" type="button" size="sm" variant="outline" class="mt-3 w-full" @click="showDischarge = true"><DoorOpen class="h-4 w-4" />Prononcer la sortie</Button>
                    <div v-else class="mt-3">
                        <ClinicalDischargeForm
                            :form="dischargeForm"
                            :types="dischargeTypes"
                            :requires-diagnosis="true"
                            @submit="submitDischarge"
                            @cancel="showDischarge = false"
                        />
                        <FormError :message="dischargeForm.errors.medical_discharge" />
                    </div>
                </Card>
            </aside>
        </div>
    </div>
</template>
