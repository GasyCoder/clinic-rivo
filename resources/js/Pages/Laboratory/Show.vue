<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import LabItemEditor from '@/Components/Laboratory/LabItemEditor.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import {
    ArrowLeft, BadgeCheck, Ban, FlaskConical, Microscope, Printer, Siren, TestTubes,
} from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';
import { LAB_STATUS_TONES } from '@/utilities/labWorkbench';

defineOptions({ layout: AppLayout });

/**
 * ADR-213 — une demande d'analyses à la paillasse : ses analyses à gauche,
 * la saisie de celle qu'on travaille à droite.
 */
const props = defineProps({
    labRequest: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    microbiology: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});

const firstToWork = () => (props.items.find((item) => item.editable) ?? props.items.find((item) => item.status === 'COMPLETED') ?? props.items[0])?.uuid ?? null;
const selected = ref(firstToWork());
watch(() => props.items.map((item) => item.uuid).join(','), () => {
    if (!props.items.some((item) => item.uuid === selected.value)) selected.value = firstToWork();
});
const current = computed(() => props.items.find((item) => item.uuid === selected.value) ?? null);

const toValidate = computed(() => props.items.filter((item) => item.status === 'COMPLETED').length);
const anyRendered = computed(() => props.items.some((item) => ['COMPLETED', 'VALIDATED'].includes(item.status) || item.result_value));

const confirmValidateAll = ref(false);
const validatingAll = ref(false);
const validateAll = () => {
    validatingAll.value = true;
    router.post(`/laboratory/requests/${props.labRequest.uuid}/validate`, {}, {
        preserveScroll: true,
        onFinish: () => { validatingAll.value = false; confirmValidateAll.value = false; },
    });
};
</script>

<template>
    <Head :title="`Laboratoire · ${formatPatientName(labRequest.patient)}`" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <Button :as="Link" href="/laboratory" variant="ghost" size="sm"><ArrowLeft class="h-4 w-4" /> File du laboratoire</Button>
            <div class="flex flex-wrap items-center gap-2">
                <Button v-if="can.microbiology" :as="Link" href="/laboratory/microbiologie" variant="outline" size="sm">
                    <Microscope class="h-4 w-4" /> Germes & antibiotiques
                </Button>
                <Button v-if="anyRendered" :as="Link" :href="`/laboratory/requests/${labRequest.uuid}/impression`" variant="outline" size="sm">
                    <Printer class="h-4 w-4" /> Feuille de résultats
                </Button>
                <Button v-if="can.validate && toValidate > 1 && !labRequest.cancelled" type="button" size="sm" variant="success" @click="confirmValidateAll = true">
                    <BadgeCheck class="h-4 w-4" /> Valider les {{ toValidate }} analyses
                </Button>
            </div>
        </div>

        <Card class="p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><TestTubes class="h-6 w-6" /></span>
                    <div class="min-w-0">
                        <h1 class="flex flex-wrap items-center gap-2 text-xl font-bold text-foreground">
                            {{ formatPatientName(labRequest.patient) }}
                            <Badge v-if="labRequest.emergency" tone="danger"><Siren class="h-3.5 w-3.5" /> Urgence</Badge>
                        </h1>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            {{ labRequest.patient.patient_number }} · passage {{ labRequest.episode_number }}
                            <template v-if="labRequest.patient.age !== null && labRequest.patient.age !== undefined"> · {{ labRequest.patient.age }} ans</template>
                            <template v-if="labRequest.patient.sex"> · {{ labRequest.patient.sex === 'M' ? 'Homme' : 'Femme' }}</template>
                        </p>
                    </div>
                </div>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-3">
                    <div><dt class="text-muted-foreground">Origine</dt><dd class="font-semibold text-foreground">{{ labRequest.origin }}</dd></div>
                    <div><dt class="text-muted-foreground">Demandée le</dt><dd class="font-semibold text-foreground">{{ formatDateTime(labRequest.requested_at) }}</dd></div>
                    <div v-if="labRequest.requested_by"><dt class="text-muted-foreground">Par</dt><dd class="font-semibold text-foreground">{{ labRequest.requested_by }}</dd></div>
                </dl>
            </div>
            <p v-if="labRequest.notes" class="mt-3 rounded-lg bg-muted/40 px-3 py-2 text-sm text-foreground"><span class="font-semibold">Renseignements : </span>{{ labRequest.notes }}</p>
            <p v-if="labRequest.cancelled" class="mt-3 flex items-start gap-2 rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">
                <Ban class="mt-0.5 h-4 w-4 shrink-0" />
                Demande retirée par le prescripteur<template v-if="labRequest.cancel_reason"> : {{ labRequest.cancel_reason }}</template>. Elle ne se travaille plus.
            </p>
        </Card>

        <div class="grid gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
            <nav aria-label="Analyses de la demande" class="lg:sticky lg:top-20 lg:self-start">
                <Card class="overflow-hidden">
                    <p class="border-b border-border px-3 py-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Analyses demandées · {{ items.length }}</p>
                    <ul class="max-h-[60vh] divide-y divide-border overflow-y-auto">
                        <li v-for="item in items" :key="item.uuid">
                            <button
                                type="button"
                                :aria-current="item.uuid === selected ? 'true' : undefined"
                                :class="cn('flex w-full items-start gap-2 px-3 py-2.5 text-left transition-colors hover:bg-muted/40 focus:outline-none focus-visible:bg-muted/60',
                                    item.uuid === selected && 'bg-primary/5 ring-1 ring-inset ring-primary/30')"
                                @click="selected = item.uuid"
                            >
                                <FlaskConical class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-foreground">{{ item.name }}</span>
                                    <span class="mt-1 flex flex-wrap gap-1">
                                        <Badge :tone="LAB_STATUS_TONES[item.status]">{{ item.status_label }}</Badge>
                                        <Badge v-if="item.critical_count" tone="danger"><Siren class="h-3 w-3" />{{ item.critical_count }}</Badge>
                                    </span>
                                </span>
                            </button>
                        </li>
                    </ul>
                </Card>
            </nav>

            <LabItemEditor
                v-if="current"
                :key="current.uuid"
                :item="current"
                :options="options"
                :microbiology="microbiology"
                :can="can"
                :cancelled="labRequest.cancelled"
            />
            <Card v-else class="p-8 text-center text-sm text-muted-foreground">Aucune analyse dans cette demande.</Card>
        </div>
    </div>

    <ConfirmModal
        v-model:open="confirmValidateAll"
        title="Valider toutes les analyses terminées ?"
        :description="`${toValidate} analyses terminées seront validées. Validées, elles ne se modifient plus.`"
        confirm-label="Valider"
        tone="success"
        :processing="validatingAll"
        @confirm="validateAll"
    />
</template>
