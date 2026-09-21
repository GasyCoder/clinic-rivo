<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import ClinicalDischargeForm from '@/Components/Clinical/ClinicalDischargeForm.vue';
import StayDiagnosisAdd from '@/Components/Hospitalization/StayDiagnosisAdd.vue';
import { formatDateTime } from '@/utilities/date';
import { doctorName } from '@/utilities/doctorName';
import { Ambulance, DoorOpen, Send, Undo2 } from 'lucide-vue-next';

/**
 * ADR-162 — la sortie d'un patient hospitalisé, sur la page du séjour et là
 * seulement. Le même formulaire que la consultation (ADR-107) ; le diagnostic
 * final est toujours exigé (ADR-094). Un transfert ne se prononce pas : il se
 * demande, et le séjour se termine au départ du patient (ADR-161).
 */
const props = defineProps({
    stayUuid: { type: String, required: true },
    types: { type: Array, default: () => [] },
    /** ADR-147 — ce que le dossier a déjà conclu : [{ id, description }]. */
    diagnoses: { type: Array, default: () => [] },
    /** Les lignes des ordonnances actives du séjour, déjà composées. */
    prescriptionLines: { type: Array, default: () => [] },
    canDischarge: { type: Boolean, default: false },
    /**
     * ADR-147 — poser un diagnostic sans quitter la sortie. Ce qui est déjà
     * consigné arrive coché ; ce qui manque s'ajoute ici et arrive coché aussi.
     */
    canAddDiagnosis: { type: Boolean, default: false },
    canRequestTransfer: { type: Boolean, default: false },
    /** Les autres sites de la clinique : [{ code, name, destination }]. */
    transferDestinations: { type: Array, default: () => [] },
    referral: { type: Object, default: null },
});

const page = usePage();
/** Qui signe l'acte : le compte connecté, titré une seule fois. */
const signer = computed(() => doctorName(page.props.auth?.user?.name));

const pad = (value) => String(value).padStart(2, '0');
const nowInput = () => {
    const now = new Date();

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
};

const showDischarge = ref(false);
const dischargeForm = useForm({
    type: 'NORMAL',
    final_diagnosis: '',
    patient_condition: '',
    discharge_prescription: '',
    recommendations: '',
    follow_up_at: '',
    observations: '',
    transfer_destination: '',
    death_occurred_at: '',
    death_place: '',
    death_causes: '',
    discharged_at: nowInput(),
});
// Prononcer la sortie est un acte signé (ADR-106).
const confirmingDischarge = ref(false);
const typeLabel = computed(() => props.types.find((type) => type.value === dischargeForm.type)?.label ?? '');
const submitDischarge = () => dischargeForm.post(`/hospitalisation/${props.stayUuid}/sortie`, {
    preserveScroll: true,
    onFinish: () => { confirmingDischarge.value = false; },
});

const PRIORITIES = [
    { value: 'NORMAL', label: 'Normale' },
    { value: 'URGENT', label: 'Urgente' },
    { value: 'LOW', label: 'Faible' },
];
const confirmingTransfer = ref(false);
const transferForm = useForm({ priority: 'NORMAL', facility: '' });

// Un autre site de la clinique se choisit dans la liste ; un établissement
// extérieur se saisit (« Autre établissement »). Laissé vide, il se précise
// ensuite dans le module Transferts (ADR-114).
const OTHER_FACILITY = 'OTHER';
const facilityChoice = ref('');
const facilityOther = ref('');
const facilityOptions = computed(() => [
    { value: '', label: 'À préciser plus tard' },
    ...props.transferDestinations.map((site) => ({ value: site.destination, label: site.destination })),
    { value: OTHER_FACILITY, label: 'Autre établissement…' },
]);
watch([facilityChoice, facilityOther], ([choice, other]) => {
    transferForm.facility = choice === OTHER_FACILITY ? other.trim() : choice;
});
const submitTransfer = () => transferForm.post(`/hospitalisation/${props.stayUuid}/transfert`, {
    preserveScroll: true,
    onFinish: () => { confirmingTransfer.value = false; },
});

// ADR-163 — tant que le patient n'est pas parti, le transfert se retire : il
// reste en base, annulé, avec son motif, et le séjour continue.
const cancellingTransfer = ref(false);
const transferCancelForm = useForm({ reason: '' });
const openTransferCancel = () => {
    transferCancelForm.reset();
    transferCancelForm.clearErrors();
    cancellingTransfer.value = true;
};
const submitTransferCancel = () => transferCancelForm.post(`/hospitalisation/${props.stayUuid}/transfert/${props.referral.uuid}/annuler`, {
    preserveScroll: true,
    onSuccess: () => { cancellingTransfer.value = false; },
});
</script>

<template>
    <div class="space-y-5">
        <Card v-if="canDischarge" class="p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><DoorOpen class="h-4 w-4 text-muted-foreground" />Sortie médicale</h2>
                    <p class="mt-1 text-xs text-muted-foreground">Elle termine le séjour ; le passage rejoint ensuite « Sorties &amp; règlements » à la Réception.</p>
                </div>
                <Button v-if="!showDischarge" type="button" size="sm" variant="warning" @click="showDischarge = true"><DoorOpen class="h-4 w-4" />Prononcer la sortie</Button>
            </div>
            <div v-if="showDischarge" class="mt-4">
                <ClinicalDischargeForm
                    :form="dischargeForm"
                    :types="types"
                    :diagnoses="diagnoses"
                    :prescription-lines="prescriptionLines"
                    :requires-diagnosis="true"
                    narrow
                    :diagnosis-hint="canAddDiagnosis
                        ? 'Aucun diagnostic consigné : ajoutez-le ci-dessous, il arrivera déjà coché.'
                        : 'Aucun diagnostic consigné, et vous n’avez pas le droit d’en poser un (diagnoses.create).'"
                    @submit="confirmingDischarge = true"
                    @cancel="showDischarge = false"
                >
                    <!-- Ce qui est déjà consigné reste coché ; ce qui manque
                         s'ajoute ici, enregistré sur le séjour (ADR-147). -->
                    <template v-if="canAddDiagnosis" #diagnosis-actions>
                        <StayDiagnosisAdd
                            :stay-uuid="stayUuid"
                            :label="diagnoses.length ? 'Ajouter un autre diagnostic' : 'Ajouter le diagnostic'"
                            class="mt-2"
                        />
                    </template>
                </ClinicalDischargeForm>
                <FormError :message="dischargeForm.errors.medical_discharge" />
            </div>
        </Card>

        <Card v-if="canRequestTransfer || referral" class="p-5">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><Ambulance class="h-4 w-4 text-muted-foreground" />Transfert vers un autre établissement</h2>
            <template v-if="referral">
                <p class="mt-2 text-sm text-foreground">
                    Demandé le {{ formatDateTime(referral.referred_at) }}<template v-if="referral.referred_by"> par {{ doctorName(referral.referred_by) }}</template>
                    <template v-if="referral.facility"> · vers {{ referral.facility }}</template>
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ referral.departed_at ? `Parti le ${formatDateTime(referral.departed_at)}.` : 'Le patient reste au lit jusqu’au départ, constaté dans le module Transferts.' }}
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <Button v-if="referral.url" :as="Link" :href="referral.url" size="sm" variant="outline"><Ambulance class="h-4 w-4" />Ouvrir dans Transferts</Button>
                    <Button v-if="referral.can_cancel" type="button" size="sm" variant="outline" class="text-destructive" @click="openTransferCancel"><Undo2 class="h-4 w-4" />Annuler le transfert</Button>
                </div>
            </template>
            <template v-else>
                <p class="mt-1 text-xs text-muted-foreground">Le séjour se terminera au départ du patient. Choisissez un autre site de la clinique ou un autre établissement — ou précisez-le ensuite dans le module Transferts.</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem]">
                    <FormField label="Établissement (facultatif)" :error="transferForm.errors.facility">
                        <Select v-model="facilityChoice" class="w-full" :options="facilityOptions" placeholder="À préciser plus tard" aria-label="Établissement destinataire" />
                    </FormField>
                    <FormField label="Priorité" :error="transferForm.errors.priority">
                        <Select v-model="transferForm.priority" class="w-full" :options="PRIORITIES" aria-label="Priorité du transfert" />
                    </FormField>
                    <FormField v-if="facilityChoice === OTHER_FACILITY" label="Nom de l’établissement" required class="sm:col-span-2">
                        <Input v-model="facilityOther" maxlength="255" placeholder="Ex. : CHU Mahajanga" autofocus />
                    </FormField>
                </div>
                <FormError :message="transferForm.errors.referral" />
                <div class="mt-3 flex justify-end">
                    <Button type="button" size="sm" :disabled="transferForm.processing || (facilityChoice === OTHER_FACILITY && !facilityOther.trim())" @click="confirmingTransfer = true"><Send class="h-4 w-4" />Demander le transfert</Button>
                </div>
            </template>
        </Card>

        <Dialog v-model:open="confirmingDischarge" :title="dischargeForm.type === 'DECEASED' ? 'Confirmer le décès' : 'Confirmer la sortie médicale'" description="Le séjour se termine : le lit est libéré." size="md" :dismissible="false">
            <dl class="space-y-1.5 text-sm">
                <div><dt class="text-xs text-muted-foreground">Type</dt><dd class="text-foreground">{{ typeLabel }}</dd></div>
                <div><dt class="text-xs text-muted-foreground">Date</dt><dd class="text-foreground">{{ formatDateTime(dischargeForm.discharged_at) }}</dd></div>
                <div v-if="dischargeForm.final_diagnosis"><dt class="text-xs text-muted-foreground">Diagnostic final</dt><dd class="whitespace-pre-line text-foreground">{{ dischargeForm.final_diagnosis }}</dd></div>
                <div v-if="dischargeForm.patient_condition"><dt class="text-xs text-muted-foreground">État du patient</dt><dd class="text-foreground">{{ dischargeForm.patient_condition }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-muted-foreground">Sous la responsabilité de <span class="font-semibold text-foreground">{{ signer }}</span>.</p>
            <template #footer>
                <Button type="button" size="sm" variant="ghost" :disabled="dischargeForm.processing" @click="confirmingDischarge = false">Revenir</Button>
                <Button type="button" size="sm" variant="warning" :disabled="dischargeForm.processing" @click="submitDischarge"><DoorOpen class="h-4 w-4" />{{ dischargeForm.type === 'DECEASED' ? 'Je confirme le décès' : 'Je prononce la sortie' }}</Button>
            </template>
        </Dialog>

        <Dialog v-model:open="cancellingTransfer" title="Annuler le transfert" description="Le patient n’est pas parti : la demande est retirée, sans être effacée, et le séjour continue." size="md" :dismissible="false">
            <form id="transfer-cancel" class="space-y-3" @submit.prevent="submitTransferCancel">
                <p class="text-sm text-foreground">{{ referral?.facility || 'Établissement non précisé' }} · demandé le {{ formatDateTime(referral?.referred_at) }}</p>
                <FormField label="Motif (facultatif)" :error="transferCancelForm.errors.reason">
                    <Textarea v-model="transferCancelForm.reason" :rows="2" maxlength="500" placeholder="Ex. : amélioration clinique, établissement indisponible" />
                </FormField>
                <FormError :message="transferCancelForm.errors.referral" />
            </form>
            <template #footer>
                <Button type="button" size="sm" variant="ghost" :disabled="transferCancelForm.processing" @click="cancellingTransfer = false">Garder le transfert</Button>
                <Button type="submit" form="transfer-cancel" size="sm" variant="destructive" :disabled="transferCancelForm.processing"><Undo2 class="h-4 w-4" />Annuler le transfert</Button>
            </template>
        </Dialog>

        <Dialog v-model:open="confirmingTransfer" title="Demander le transfert" description="La demande part au module Transferts ; le patient reste au lit jusqu’au départ." size="md" :dismissible="false">
            <p class="text-sm text-foreground">{{ transferForm.facility || 'Établissement à préciser' }} · priorité {{ PRIORITIES.find((option) => option.value === transferForm.priority)?.label.toLowerCase() }}</p>
            <p class="mt-3 text-xs text-muted-foreground">Sous la responsabilité de <span class="font-semibold text-foreground">{{ signer }}</span>.</p>
            <template #footer>
                <Button type="button" size="sm" variant="ghost" @click="confirmingTransfer = false">Revenir</Button>
                <Button type="button" size="sm" :disabled="transferForm.processing" @click="submitTransfer"><Send class="h-4 w-4" />Je demande le transfert</Button>
            </template>
        </Dialog>
    </div>
</template>
