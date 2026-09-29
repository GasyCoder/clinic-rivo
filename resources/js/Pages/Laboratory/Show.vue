<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import LabItemEditor from '@/Components/Laboratory/LabItemEditor.vue';
import ResizableSplit from '@/Components/UI/ResizableSplit.vue';
import LabSendDialog from '@/Components/Laboratory/LabSendDialog.vue';
import LabReceptionPanel from '@/Components/Laboratory/LabReceptionPanel.vue';
import LabSamplesCard from '@/Components/Laboratory/LabSamplesCard.vue';
import LabRequestManage from '@/Components/Laboratory/LabRequestManage.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import ClinicalSaveStatus from '@/Components/Clinical/ClinicalSaveStatus.vue';
import { useAutosave } from '@/composables/useAutosave';
import {
    Archive, ArrowLeft, Ban, BadgeCheck, Building2, CheckCheck, ClipboardCheck, ClipboardList, Download, FileSignature, FileText, History, Loader, Microscope, RotateCcw, Send, Siren, Stethoscope, TestTubes, Trash2, Wallet,
} from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';
import { itemProgress, labTaskState } from '@/utilities/labWorkbench';
import { labUrl } from '@/utilities/labUrl';
import LabSiteOnlyAction from '@/Components/Laboratory/LabSiteOnlyAction.vue';
import { sendableItems } from '@/utilities/labSending';

defineOptions({ layout: AppLayout });

/**
 * ADR-213 / ADR-214 — une demande d'analyses au laboratoire : ses analyses à
 * gauche et la saisie de celle qu'on travaille à droite, puis la conclusion
 * générale.
 *
 * ADR-217 — rien n'attend avant la saisie : ni réception, ni règlement. Une
 * demande pas encore commencée se « traite » d'un geste, ou se prend en
 * charge à la première saisie ; le règlement est affiché pour information.
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
    manage: { type: Object, default: () => ({}) },
    addableAnalyses: { type: Array, default: () => [] },
});

// ADR-220 — gérer la demande : ajouter, retirer, renseigner, archiver, corbeille.
const manager = ref(null);

const TASK_ICONS = { pending: ClipboardList, progress: Loader, completed: CheckCheck, validated: BadgeCheck, redo: RotateCcw };

const firstToWork = () => (props.items.find((item) => item.editable) ?? props.items.find((item) => item.status === 'COMPLETED') ?? props.items[0])?.uuid ?? null;
const selected = ref(firstToWork());
watch(() => props.items.map((item) => item.uuid).join(','), () => {
    if (!props.items.some((item) => item.uuid === selected.value)) selected.value = firstToWork();
});
const current = computed(() => props.items.find((item) => item.uuid === selected.value) ?? null);
// Une analyse terminée : on passe à la suivante qui reste à faire, s'il y en a une.
const selectNextToWork = (uuid) => {
    const next = props.items.find((item) => item.uuid !== uuid && item.editable);
    if (next) selected.value = next.uuid;
};

// ADR-218 — le compte rendu PDF porte tout ce qui a un résultat, envoyé ou non.
const anyRendered = computed(() => props.items.some((item) => ['COMPLETED', 'VALIDATED'].includes(item.status) || item.result_value
    || (item.nodes ?? []).some((node) => node.result)));
const anySentOut = computed(() => props.items.some((item) => item.sent_out));
const allValidated = computed(() => props.items.length > 0 && props.items.every((item) => item.status === 'VALIDATED'));
const toSend = computed(() => sendableItems(props.items).length);
const canSendHere = computed(() => !props.labRequest.cancelled);
const doneCount = computed(() => props.items.filter((item) => ['COMPLETED', 'VALIDATED'].includes(item.status)).length);
const duePayment = computed(() => (props.payment?.lines ?? []).filter((line) => line.blocking));

// ADR-216 — envoyer au médecin. La saisie en cours part d'abord (enregistrement
// automatique), puis la fenêtre s'ouvre sur l'état relu du serveur.
const editor = ref(null);
const sendOpen = ref(false);
const preselected = ref([]);
// Conclusion générale (ADR-214), écrite par qui envoie les résultats (ADR-216).
// Elle s'enregistre d'elle-même, comme la saisie : plus de bouton à part.
const conclusionForm = useForm({ conclusion: props.labRequest.conclusion ?? '' });
const canConclude = computed(() => props.can.send && !props.labRequest.cancelled && !allValidated.value);
const conclusionAutosave = useAutosave(conclusionForm, (options) => conclusionForm.put(
    labUrl(`/laboratory/requests/${props.labRequest.uuid}/conclusion`), options,
), { enabled: () => canConclude.value });
// Une valeur venue du serveur ne remplace jamais une frappe en cours.
watch(() => props.labRequest.conclusion, (value) => {
    if (!conclusionForm.isDirty) {
        conclusionForm.defaults({ conclusion: value ?? '' });
        conclusionForm.reset();
    }
});

const openSend = (uuids = []) => {
    const open = () => { preselected.value = uuids; sendOpen.value = true; };
    // La conclusion en cours part aussi avant la fenêtre d'envoi.
    const flushEditor = () => (editor.value?.flush ? editor.value.flush(open, () => {}) : open());
    conclusionAutosave.flush(flushEditor, () => {});
};
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
                    <FileText class="h-4 w-4" /> Compte rendu PDF
                </Button>
                <Button v-if="anyRendered" as="a" :href="labUrl(`/laboratory/requests/${labRequest.uuid}/resultats.pdf?telecharger=1`)" variant="ghost" size="sm" title="Télécharger le compte rendu PDF" aria-label="Télécharger le compte rendu PDF">
                    <Download class="h-4 w-4" />
                </Button>
                <LabRequestManage ref="manager" :lab-request="labRequest" :items="items" :manage="manage" :addable="addableAnalyses" />
                <!-- Amendement ADR-216 du 2026-09-29 — l'envoi, pour une, plusieurs ou toutes les
                     analyses terminées (toutes par défaut) ; « Terminer » est au pied de chaque saisie. -->
                <LabSiteOnlyAction v-if="(can.send || can.site_only) && canSendHere && !allValidated" label="Envoyer au médecin" variant="default">
                    <span class="inline-flex" :title="toSend === 0 ? 'Terminez d’abord au moins une analyse, au pied de sa saisie.' : `${toSend} analyse${toSend > 1 ? 's terminées prêtes' : ' terminée prête'} à partir`">
                        <Button type="button" size="sm" :disabled="toSend === 0" @click="openSend()">
                            <Send class="h-4 w-4" /> Envoyer au médecin<template v-if="toSend > 0"> · {{ toSend }}</template>
                        </Button>
                    </span>
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
                        <dt class="text-muted-foreground">Prise en charge</dt>
                        <dd v-if="labRequest.received" class="font-semibold text-foreground">{{ formatDateTime(labRequest.received_at) }}<template v-if="labRequest.received_by"> · {{ labRequest.received_by }}</template></dd>
                        <dd v-else class="font-semibold text-muted-foreground">Pas encore commencée</dd>
                    </div>
                </dl>
            </div>
            <p v-if="labRequest.notes" class="mt-3 rounded-lg bg-muted/40 px-3 py-2 text-sm text-foreground"><span class="font-semibold">Renseignements : </span>{{ labRequest.notes }}</p>
            <p v-if="labRequest.received && duePayment.length" class="mt-3 flex items-center gap-2 text-xs text-amber-800 dark:text-amber-300" :title="payment?.summary">
                <Wallet class="h-3.5 w-3.5 shrink-0" />À régler à la Caisse : {{ duePayment.map((line) => line.name).join(', ') }} — n’empêche pas l’analyse.
            </p>
            <p v-if="labRequest.payment_exemption" class="mt-3 flex items-center gap-2 text-xs text-muted-foreground"><ClipboardCheck class="h-3.5 w-3.5" />{{ labRequest.payment_exemption }}</p>
            <p v-if="manage.archived" class="mt-3 flex items-center gap-2 rounded-lg bg-muted/60 px-3 py-2 text-sm text-muted-foreground" role="status">
                <Archive class="h-4 w-4 shrink-0" />
                Demande archivée<template v-if="manage.archived_at"> le {{ formatDateTime(manage.archived_at) }}</template> : elle n’est plus dans la file. « Gérer » › Désarchiver pour la corriger.
            </p>
            <p v-if="labRequest.cancelled" class="mt-3 flex items-start gap-2 rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">
                <Ban class="mt-0.5 h-4 w-4 shrink-0" />
                Demande retirée par le prescripteur<template v-if="labRequest.cancel_reason"> : {{ labRequest.cancel_reason }}</template>. Elle ne se travaille plus.
            </p>
        </Card>

        <LabReceptionPanel
            v-if="!labRequest.received && !labRequest.cancelled"
            :lab-request="labRequest"
            :payment="payment"
            :sample-options="sampleOptions"
            :can="can"
        />

        <!-- ADR-219 — les tâches et l'analyse ouverte, séparées par une barre que l'on
             glisse (souris, tactile, clavier ; double-clic pour revenir au réglage).
             La largeur reste sur le poste, jamais envoyée au serveur. -->
        <ResizableSplit
            storage-key="rivo:laboratory:tasks-split"
            :default-ratio="0.25"
            :min-ratio="0.17"
            :max-ratio="0.45"
            start-label="panneau Tâche(s) à traiter"
            end-label="panneau de l’analyse ouverte"
        >
        <template #start>
            <div class="space-y-4 lg:sticky lg:top-20">
                <nav aria-label="Analyses de la demande">
                    <Card class="overflow-hidden">
                        <div class="flex items-center gap-3 border-b border-border px-4 py-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground"><ClipboardList class="h-5 w-5" /></span>
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-foreground">Tâche(s) à traiter</p>
                                <p class="text-xs text-muted-foreground">{{ doneCount }}/{{ items.length }} terminée(s)</p>
                            </div>
                        </div>
                        <ul class="max-h-[60vh] space-y-2 overflow-y-auto p-3">
                            <li v-for="item in items" :key="item.uuid" class="group relative">
                                <button
                                    type="button"
                                    :aria-current="item.uuid === selected ? 'true' : undefined"
                                    :class="cn('w-full rounded-xl border p-3 text-left transition-all hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                        labTaskState(item.status).card,
                                        item.uuid === selected && 'ring-2 ring-primary/60 ring-offset-1 ring-offset-background')"
                                    @click="selected = item.uuid"
                                >
                                    <span class="flex items-start gap-3">
                                        <span :class="cn('flex h-8 w-8 shrink-0 items-center justify-center rounded-lg shadow-sm', labTaskState(item.status).square)" aria-hidden="true">
                                            <component :is="TASK_ICONS[labTaskState(item.status).icon]" :class="cn('h-4 w-4', item.status === 'IN_PROGRESS' && 'motion-safe:animate-spin [animation-duration:3s]')" />
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="flex items-center justify-between gap-2">
                                                <span class="truncate text-sm font-bold text-foreground">{{ item.code || item.name }}</span>
                                                <Badge :tone="labTaskState(item.status).badge" class="shrink-0">{{ labTaskState(item.status).label }}</Badge>
                                            </span>
                                            <span v-if="item.code" class="mt-0.5 block truncate text-xs text-muted-foreground">{{ item.name }}</span>
                                            <span v-if="itemProgress(item).total" class="mt-2 flex items-center gap-2" :aria-label="`${itemProgress(item).done} résultat(s) saisi(s) sur ${itemProgress(item).total}`">
                                                <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"><span :class="cn('block h-full rounded-full transition-all', labTaskState(item.status).bar)" :style="{ width: `${Math.round(itemProgress(item).ratio * 100)}%` }" /></span>
                                                <span class="text-[11px] tabular-nums text-muted-foreground">{{ itemProgress(item).done }}/{{ itemProgress(item).total }}</span>
                                            </span>
                                            <span v-if="item.sent_out || item.critical_count" class="mt-1.5 flex flex-wrap gap-1">
                                                <Badge v-if="item.sent_out" tone="info" :title="`Confiée à ${item.sent_out.laboratory}`"><Building2 class="h-3 w-3" /> Extérieur</Badge>
                                                <Badge v-if="item.critical_count" tone="danger"><Siren class="h-3 w-3" />{{ item.critical_count }} critique(s)</Badge>
                                            </span>
                                        </span>
                                    </span>
                                </button>
                                <Button
                                    v-if="manager?.canRemove(item)"
                                    type="button"
                                    size="icon-xs"
                                    variant="ghost"
                                    class="absolute -right-1.5 -top-1.5 h-6 w-6 rounded-full border border-border bg-card text-muted-foreground opacity-0 shadow-sm transition-opacity hover:text-destructive focus-visible:opacity-100 group-hover:opacity-100 group-focus-within:opacity-100 [@media(hover:none)]:opacity-100"
                                    :title="`Retirer « ${item.name} » de la demande`"
                                    :aria-label="`Retirer « ${item.name} » de la demande`"
                                    @click="manager.openRemove(item)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                </Button>
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
        </template>

        <template #end>
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
                    @completed="selectNextToWork"
                />
                <Card v-else class="p-8 text-center text-sm text-muted-foreground">Aucune analyse dans cette demande.</Card>

                <Card v-if="canConclude || labRequest.conclusion" class="p-4">
                    <div class="mb-2 flex flex-wrap items-center gap-2">
                        <FileSignature class="h-4 w-4 text-primary" />
                        <h2 class="text-sm font-bold text-foreground">Conclusion générale</h2>
                        <Badge v-if="canConclude" variant="outline" class="text-[10px]">facultative</Badge>
                        <span class="text-xs text-muted-foreground">— une synthèse pour toute la demande, imprimée sous les résultats et lue par le médecin</span>
                    </div>
                    <template v-if="canConclude">
                        <FormField :error="conclusionForm.errors.conclusion">
                            <Textarea v-model="conclusionForm.conclusion" rows="3" placeholder="Ex. anémie microcytaire à contrôler ; à corréler à la clinique…" />
                        </FormField>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <ClinicalSaveStatus :saving="conclusionAutosave.saving.value" :saved-at="conclusionAutosave.savedAt.value" :dirty="conclusionForm.isDirty" :failed="conclusionAutosave.failed.value" retryable @retry="conclusionAutosave.retry" />
                            <span v-if="labRequest.conclusion_by" class="ms-auto text-xs text-muted-foreground">{{ labRequest.conclusion_by }} · {{ formatDateTime(labRequest.conclusion_at) }}</span>
                        </div>
                    </template>
                    <template v-else>
                        <p class="whitespace-pre-line text-sm text-foreground">{{ labRequest.conclusion }}</p>
                        <p v-if="labRequest.conclusion_by" class="mt-1 text-xs text-muted-foreground">{{ labRequest.conclusion_by }} · {{ formatDateTime(labRequest.conclusion_at) }}</p>
                    </template>
                </Card>
            </div>
        </template>
        </ResizableSplit>
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
