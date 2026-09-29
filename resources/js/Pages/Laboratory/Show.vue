<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import LabItemEditor from '@/Components/Laboratory/LabItemEditor.vue';
import LabSendDialog from '@/Components/Laboratory/LabSendDialog.vue';
import LabReceptionPanel from '@/Components/Laboratory/LabReceptionPanel.vue';
import LabSamplesCard from '@/Components/Laboratory/LabSamplesCard.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import {
    ArrowLeft, Ban, Building2, ClipboardCheck, FileSignature, FileText, FlaskConical, History, Microscope, Printer, Send, Siren, Stethoscope, TestTubes,
} from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';
import { LAB_STATUS_TONES } from '@/utilities/labWorkbench';
import { labUrl } from '@/utilities/labUrl';
import LabSiteOnlyAction from '@/Components/Laboratory/LabSiteOnlyAction.vue';
import { sendableItems } from '@/utilities/labSending';

defineOptions({ layout: AppLayout });

/**
 * ADR-213 / ADR-214 — une demande d'analyses au laboratoire : la réception
 * (règlement, prélèvements), ses analyses à gauche et la saisie de celle qu'on
 * travaille à droite, puis la conclusion générale.
 *
 * ADR-216 — le technicien envoie les résultats au médecin, et cet envoi les
 * valide : il n'y a plus d'étape « biologiste ».
 */
const props = defineProps({
    labRequest: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    samples: { type: Array, default: () => [] },
    payment: { type: Object, default: null },
    sampleOptions: { type: Object, default: null },
    externalLabs: { type: Array, default: () => [] },
    microbiology: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
    recipient: { type: Object, default: () => ({}) },
    recipients: { type: Array, default: () => [] },
});

const firstToWork = () => (props.items.find((item) => item.editable) ?? props.items.find((item) => item.status === 'COMPLETED') ?? props.items[0])?.uuid ?? null;
const selected = ref(firstToWork());
watch(() => props.items.map((item) => item.uuid).join(','), () => {
    if (!props.items.some((item) => item.uuid === selected.value)) selected.value = firstToWork();
});
const current = computed(() => props.items.find((item) => item.uuid === selected.value) ?? null);

const anyRendered = computed(() => props.items.some((item) => ['COMPLETED', 'VALIDATED'].includes(item.status) || item.result_value));
const anySentOut = computed(() => props.items.some((item) => item.sent_out));
const allValidated = computed(() => props.items.length > 0 && props.items.every((item) => item.status === 'VALIDATED'));
const toSend = computed(() => sendableItems(props.items).length);
const canSendHere = computed(() => props.labRequest.received && !props.labRequest.cancelled);

// ADR-216 — envoyer au médecin. La saisie en cours part d'abord (enregistrement
// automatique), puis la fenêtre s'ouvre sur l'état relu du serveur.
const editor = ref(null);
const sendOpen = ref(false);
const preselected = ref([]);
const openSend = (uuids = []) => {
    const open = () => { preselected.value = uuids; sendOpen.value = true; };
    if (editor.value?.flush) editor.value.flush(open, () => {});
    else open();
};

// Conclusion générale (ADR-214), écrite par qui envoie les résultats (ADR-216)
const conclusionForm = useForm({ conclusion: props.labRequest.conclusion ?? '' });
watch(() => props.labRequest.conclusion, (value) => { conclusionForm.defaults({ conclusion: value ?? '' }); conclusionForm.reset(); });
const canConclude = computed(() => props.can.send && props.labRequest.received && !props.labRequest.cancelled && !allValidated.value);
const saveConclusion = () => conclusionForm.put(labUrl(`/laboratory/requests/${props.labRequest.uuid}/conclusion`), { preserveScroll: true });
</script>

<template>
    <Head :title="`Laboratoire · ${formatPatientName(labRequest.patient)}`" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <Button :as="Link" :href="labUrl('/laboratory')" variant="ghost" size="sm"><ArrowLeft class="h-4 w-4" /> File du laboratoire</Button>
            <div class="flex flex-wrap items-center gap-2">
                <Button v-if="can.history" :as="Link" :href="labUrl(`/laboratory/patients/${labRequest.patient.uuid}/historique`)" variant="outline" size="sm">
                    <History class="h-4 w-4" /> Historique du patient
                </Button>
                <Button v-if="can.microbiology" :as="Link" :href="labUrl('/laboratory/microbiologie')" variant="outline" size="sm">
                    <Microscope class="h-4 w-4" /> Germes & antibiotiques
                </Button>
                <Button v-if="anySentOut" :as="Link" :href="labUrl(`/laboratory/requests/${labRequest.uuid}/bon-envoi`)" variant="outline" size="sm">
                    <FileText class="h-4 w-4" /> Bon d’envoi
                </Button>
                <Button v-if="anyRendered" :as="Link" :href="labUrl(`/laboratory/requests/${labRequest.uuid}/impression`)" variant="outline" size="sm">
                    <Printer class="h-4 w-4" /> Feuille de résultats
                </Button>
                <LabSiteOnlyAction v-if="(can.send || can.site_only) && canSendHere && toSend > 0" label="Envoyer au médecin" variant="default">
                    <Button type="button" size="sm" @click="openSend()">
                        <Send class="h-4 w-4" /> Envoyer au médecin<template v-if="toSend > 1"> · {{ toSend }}</template>
                    </Button>
                </LabSiteOnlyAction>
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
                            <Badge v-if="labRequest.lab_number" variant="outline" class="font-mono">{{ labRequest.lab_number }}</Badge>
                        </h1>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            {{ labRequest.patient.patient_number }} · passage {{ labRequest.episode_number }}
                            <template v-if="labRequest.patient.age !== null && labRequest.patient.age !== undefined"> · {{ labRequest.patient.age }} ans</template>
                            <template v-if="labRequest.patient.sex"> · {{ labRequest.patient.sex === 'M' ? 'Homme' : 'Femme' }}</template>
                        </p>
                    </div>
                </div>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-5">
                    <div><dt class="text-muted-foreground">Origine</dt><dd class="font-semibold text-foreground">{{ labRequest.origin }}</dd></div>
                    <div><dt class="text-muted-foreground">Demandée le</dt><dd class="font-semibold text-foreground">{{ formatDateTime(labRequest.requested_at) }}</dd></div>
                    <div v-if="labRequest.requested_by"><dt class="text-muted-foreground">Par</dt><dd class="font-semibold text-foreground">{{ labRequest.requested_by }}</dd></div>
                    <div>
                        <dt class="text-muted-foreground">Résultats adressés à</dt>
                        <dd v-if="recipient.addressed" class="flex items-center gap-1 font-semibold text-foreground">
                            <Stethoscope class="h-3.5 w-3.5 text-primary" aria-hidden="true" />{{ recipient.name ?? 'Aucun médecin (patient externe)' }}
                        </dd>
                        <dd v-else class="font-semibold text-muted-foreground">Pas encore envoyés</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Réception</dt>
                        <dd v-if="labRequest.received" class="font-semibold text-foreground">{{ formatDateTime(labRequest.received_at) }}<template v-if="labRequest.received_by"> · {{ labRequest.received_by }}</template></dd>
                        <dd v-else class="font-semibold text-amber-700 dark:text-amber-300">À réceptionner</dd>
                    </div>
                </dl>
            </div>
            <p v-if="labRequest.notes" class="mt-3 rounded-lg bg-muted/40 px-3 py-2 text-sm text-foreground"><span class="font-semibold">Renseignements : </span>{{ labRequest.notes }}</p>
            <p v-if="labRequest.payment_exemption" class="mt-3 flex items-center gap-2 text-xs text-muted-foreground"><ClipboardCheck class="h-3.5 w-3.5" />{{ labRequest.payment_exemption }}</p>
            <p v-if="labRequest.cancelled" class="mt-3 flex items-start gap-2 rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">
                <Ban class="mt-0.5 h-4 w-4 shrink-0" />
                Demande retirée par le prescripteur<template v-if="labRequest.cancel_reason"> : {{ labRequest.cancel_reason }}</template>. Elle ne se travaille plus.
            </p>
        </Card>

        <LabReceptionPanel
            v-if="!labRequest.received && !labRequest.cancelled && payment"
            :lab-request="labRequest"
            :payment="payment"
            :sample-options="sampleOptions"
            :can="can"
        />

        <div class="grid gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
            <div class="space-y-4 lg:sticky lg:top-20 lg:self-start">
                <nav aria-label="Analyses de la demande">
                    <Card class="overflow-hidden">
                        <p class="border-b border-border px-3 py-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Analyses demandées · {{ items.length }}</p>
                        <ul class="max-h-[50vh] divide-y divide-border overflow-y-auto">
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
                                            <Badge v-if="item.sent_out" tone="info" :title="`Confiée à ${item.sent_out.laboratory}`"><Building2 class="h-3 w-3" /> Extérieur</Badge>
                                            <Badge v-if="item.critical_count" tone="danger"><Siren class="h-3 w-3" />{{ item.critical_count }}</Badge>
                                        </span>
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </Card>
                </nav>

                <LabSamplesCard
                    v-if="labRequest.received"
                    :lab-request="labRequest"
                    :samples="samples"
                    :sample-options="sampleOptions"
                    :can="can"
                />
            </div>

            <div class="space-y-4">
                <LabItemEditor
                    v-if="current"
                    ref="editor"
                    :key="current.uuid"
                    :item="current"
                    :options="options"
                    :microbiology="microbiology"
                    :can="can"
                    :cancelled="labRequest.cancelled"
                    :received="labRequest.received"
                    :request-uuid="labRequest.uuid"
                    :external-labs="externalLabs"
                    @send="openSend([$event])"
                />
                <Card v-else class="p-8 text-center text-sm text-muted-foreground">Aucune analyse dans cette demande.</Card>

                <Card v-if="labRequest.received && (canConclude || labRequest.conclusion)" class="p-4">
                    <div class="mb-2 flex items-center gap-2">
                        <FileSignature class="h-4 w-4 text-primary" />
                        <h2 class="text-sm font-bold text-foreground">Conclusion générale</h2>
                        <span class="text-xs text-muted-foreground">— imprimée sous tous les résultats, lue par le médecin</span>
                    </div>
                    <template v-if="canConclude">
                        <FormField :error="conclusionForm.errors.conclusion">
                            <Textarea v-model="conclusionForm.conclusion" rows="3" placeholder="Synthèse de la demande, commentaire au prescripteur…" />
                        </FormField>
                        <div class="mt-2 flex items-center justify-end gap-2">
                            <span v-if="labRequest.conclusion_by" class="me-auto text-xs text-muted-foreground">{{ labRequest.conclusion_by }} · {{ formatDateTime(labRequest.conclusion_at) }}</span>
                            <Button type="button" size="sm" :disabled="conclusionForm.processing || !conclusionForm.isDirty" @click="saveConclusion">Enregistrer la conclusion</Button>
                        </div>
                    </template>
                    <template v-else>
                        <p class="whitespace-pre-line text-sm text-foreground">{{ labRequest.conclusion }}</p>
                        <p v-if="labRequest.conclusion_by" class="mt-1 text-xs text-muted-foreground">{{ labRequest.conclusion_by }} · {{ formatDateTime(labRequest.conclusion_at) }}</p>
                    </template>
                </Card>
            </div>
        </div>
    </div>

    <LabSendDialog
        v-if="can.send"
        v-model:open="sendOpen"
        :request-uuid="labRequest.uuid"
        :items="items"
        :preselected="preselected"
        :recipient="recipient"
        :recipients="recipients"
    />
</template>
