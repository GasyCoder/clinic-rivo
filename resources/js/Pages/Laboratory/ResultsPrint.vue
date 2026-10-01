<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, BadgeCheck, CheckCircle2, Download, ExternalLink, FileText, FlaskConical, Hourglass, Info, Microscope, Printer, RotateCcw } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import SealedLabResult from '@/Components/Laboratory/SealedLabResult.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';
import { LAB_STATUS_TONES } from '@/utilities/labWorkbench';
import { approvalBadge, awaitingApproval, approvalSummary, itemApprovalLine } from '@/utilities/labApproval';
import { labUrl } from '@/utilities/labUrl';

/**
 * ADR-218 — le compte rendu de résultats d'analyses est un PDF produit par le
 * serveur, comme le laboratoire de la clinique le remettait (labo-vuejs) :
 * sections par discipline, Résultat · Val. réf. · Antériorité, notes de chaque
 * ligne, conclusion de chaque analyse puis conclusion générale. Cette page le
 * montre, le télécharge et l'imprime ; elle ne redessine pas une seconde
 * feuille, qui finirait par dire autre chose que le PDF.
 *
 * ADR-216 — la même page est celle du médecin (`context.mode = physician`) :
 * son PDF ne porte que ce qui lui a été envoyé, et, adressé à un confrère, rien
 * n'est servi avant une confirmation tracée (`sealed`).
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    labRequest: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    samples: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
    context: { type: Object, default: () => ({ mode: 'lab' }) },
    sealed: { type: Object, default: null },
    pending: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const physician = computed(() => props.context?.mode === 'physician');
const backHref = computed(() => (physician.value ? props.context.back_href : labUrl(`/laboratory/requests/${props.labRequest.uuid}`)));
const backLabel = computed(() => (physician.value ? props.context.back_label : 'Retour à la saisie'));
const patient = computed(() => props.labRequest.patient);

// Le médecin lit sur sa route (seulement ce qui lui a été envoyé) ; le laboratoire, sur la sienne.
const pdfUrl = computed(() => (physician.value
    ? `/resultats-analyses/${props.labRequest.uuid}/pdf`
    : labUrl(`/laboratory/requests/${props.labRequest.uuid}/resultats.pdf`)));
const downloadUrl = computed(() => `${pdfUrl.value}?telecharger=1`);
const provisional = computed(() => !physician.value && props.items.some((item) => item.status !== 'VALIDATED'));

// Amendement ADR-216 du 2026-09-29 — le médecin peut demander qu'un résultat reçu
// soit refait (droit « laboratory_results.return ») ; le laboratoire est prévenu.
const redoItem = ref(null);
const redoForm = useForm({ reason: '' });
const askRedo = (item) => { redoForm.reset(); redoForm.clearErrors(); redoItem.value = item; };
const submitRedo = () => redoForm.post(labUrl(`/laboratory/items/${redoItem.value.uuid}/return`), {
    preserveScroll: true,
    onSuccess: () => { redoItem.value = null; },
});

// Amendement ADR-216 quater — le médecin relit le compte rendu, puis valide ce
// qu'il a reçu : une analyse, ou toutes celles qui attendent. La Réception ne voit
// un résultat qu'une fois validé.
const awaiting = computed(() => awaitingApproval(props.items));
const summary = computed(() => approvalSummary(props.items));
const approving = ref(null); // [] = toutes celles qui attendent ; [uuid] = une analyse
const approveForm = useForm({ items: [] });
const askApprove = (item = null) => { approveForm.clearErrors(); approving.value = item ? [item] : awaiting.value; };
const submitApprove = () => {
    approveForm.items = approving.value.length === awaiting.value.length ? [] : approving.value.map((item) => item.uuid);
    approveForm.post(`/resultats-analyses/${props.labRequest.uuid}/valider`, {
        preserveScroll: true,
        onSuccess: () => { approving.value = null; },
    });
};
// Une analyse se lit d'un coup d'œil : un point de couleur et un mot, jamais une
// pastille criarde — la couleur ne porte jamais seule le sens.
const DOT = { success: 'bg-emerald-500', warning: 'bg-amber-500', danger: 'bg-red-500', primary: 'bg-primary' };
const itemState = (item) => {
    const badge = approvalBadge(item);
    const tone = badge ? badge.tone : (item.in_correction ? 'danger' : LAB_STATUS_TONES[item.status]);
    const label = badge ? badge.label : (item.in_correction ? 'En correction' : item.status_label);
    // Validée : une seule ligne dit qui et quand (« Validé par Dr … le … »).
    const line = itemApprovalLine(item, formatDateTime);
    return { dot: DOT[tone] ?? 'bg-muted-foreground/50', label: line?.tone === 'success' ? line.text : label, title: badge?.title ?? label };
};

// Le PDF se relit après une validation : il la porte au bas du compte rendu.
const pdfKey = computed(() => props.items.map((item) => `${item.uuid}:${item.approval?.state ?? ''}`).join('|'));

// Imprimer : le PDF affiché dans la page, sans ouvrir d'onglet.
const frame = ref(null);
const loaded = ref(false);
const print = () => {
    try {
        frame.value?.contentWindow?.focus();
        frame.value?.contentWindow?.print();
    } catch {
        window.open(pdfUrl.value, '_blank', 'noopener');
    }
};
</script>

<template>
    <Head :title="`Résultats · ${formatPatientName(patient)}`" />

    <!-- ADR-216 — adressés à un confrère : rien n'est servi avant la confirmation. -->
    <div v-if="sealed" class="mx-auto w-full max-w-[52rem] space-y-4">
        <Button v-if="backHref" :as="Link" :href="backHref" size="sm" variant="ghost"><ArrowLeft class="h-4 w-4" /> {{ backLabel }}</Button>
        <Card class="space-y-4 p-5">
            <div>
                <h1 class="text-lg font-bold text-foreground">Résultats d’analyses · {{ formatPatientName(patient) }}</h1>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    {{ patient.patient_number }} · passage {{ labRequest.episode_number }}<template v-if="labRequest.lab_number"> · n° {{ labRequest.lab_number }}</template>
                </p>
            </div>
            <SealedLabResult :seal="sealed" auto-open />
        </Card>
    </div>

    <div v-else class="w-full space-y-4">
        <!-- En-tête : qui, quoi, et les gestes du compte rendu -->
        <Card class="overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3 p-4">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><FileText class="h-5 w-5" /></span>
                <div class="min-w-0">
                    <h1 class="text-lg font-bold text-foreground">Compte rendu d’analyses · {{ formatPatientName(patient) }}</h1>
                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                        <span>{{ patient.patient_number }} · passage {{ labRequest.episode_number }}</span>
                        <span v-if="labRequest.lab_number">· n° <strong class="text-foreground">{{ labRequest.lab_number }}</strong></span>
                        <span v-if="labRequest.addressed_at">· adressés à {{ labRequest.recipient ?? 'aucun médecin (patient externe)' }}, le {{ formatDateTime(labRequest.addressed_at) }}</span>
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button v-if="backHref" :as="Link" :href="backHref" size="sm" variant="ghost"><ArrowLeft class="h-4 w-4" /> {{ backLabel }}</Button>
                <Button v-if="physician && can.bench_url" :as="Link" :href="can.bench_url" size="sm" variant="outline"><Microscope class="h-4 w-4" /> {{ can.bench_label }}</Button>
                <template v-if="items.length">
                    <Button type="button" size="sm" variant="outline" :disabled="!loaded" @click="print"><Printer class="h-4 w-4" /> Imprimer</Button>
                    <Button as="a" :href="pdfUrl" target="_blank" rel="noopener" size="sm" variant="outline"><ExternalLink class="h-4 w-4" /> Ouvrir</Button>
                    <Button as="a" :href="downloadUrl" size="sm"><Download class="h-4 w-4" /> Télécharger le PDF</Button>
                </template>
            </div>
            </div>

            <!--
                L'état du compte rendu : une ligne sobre sous l'en-tête, jamais un bandeau
                coloré. Amendement ADR-216 quater — la validation du médecin d'abord.
            -->
            <div v-if="(physician && awaiting.length) || summary.allApproved || provisional" class="flex flex-wrap items-center justify-between gap-3 border-t border-border bg-muted/30 px-4 py-2.5" role="status">
                <p v-if="physician && awaiting.length" class="flex min-w-0 items-center gap-2 text-sm text-muted-foreground">
                    <BadgeCheck class="h-4 w-4 shrink-0 text-primary" />
                    <span><span class="font-medium text-foreground">{{ awaiting.length === 1 ? '1 résultat attend votre validation' : `${awaiting.length} résultats attendent votre validation` }}</span> — relisez le compte rendu ; une fois validé, la Réception peut le remettre au patient.</span>
                </p>
                <p v-else-if="summary.allApproved" class="flex min-w-0 items-center gap-2 text-sm text-muted-foreground">
                    <CheckCircle2 class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    <span><span class="font-medium text-foreground">Résultats validés</span><template v-if="summary.by.length"> par {{ summary.by.join(', ') }}</template><template v-if="summary.at"> le {{ formatDateTime(summary.at) }}</template> — {{ physician ? 'la Réception peut remettre le compte rendu au patient.' : 'la Réception les voit dans « Résultats à remettre ».' }}</span>
                </p>
                <p v-else class="flex min-w-0 items-center gap-2 text-sm text-muted-foreground">
                    <Info class="h-4 w-4 shrink-0" />
                    <span><span class="font-medium text-foreground">Document provisoire</span> — au moins une analyse n’est pas encore envoyée au médecin ; le PDF le dit en tête.</span>
                </p>
                <template v-if="physician && awaiting.length">
                    <Button v-if="can.approve" type="button" size="sm" @click="askApprove()"><CheckCircle2 class="h-4 w-4" />{{ awaiting.length === 1 ? 'Valider' : `Tout valider (${awaiting.length})` }}</Button>
                    <p v-else class="text-xs text-muted-foreground">Valider demande le droit « laboratory_results.approve ».</p>
                </template>
            </div>
        </Card>

        <div class="grid gap-4 lg:grid-cols-[20rem_minmax(0,1fr)]">
            <!-- Ce que porte le compte rendu : une analyse par bloc, lisible sans se serrer -->
            <Card class="h-fit overflow-hidden">
                <div class="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
                    <p class="text-sm font-semibold text-foreground">Analyses du compte rendu</p>
                    <span v-if="items.length" class="rounded-full bg-muted px-2 py-0.5 text-xs font-medium tabular-nums text-muted-foreground">{{ items.length }}</span>
                </div>
                <ul v-if="items.length" class="divide-y divide-border">
                    <li v-for="item in items" :key="item.uuid" class="flex gap-3 px-4 py-3.5">
                        <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground"><FlaskConical class="h-4 w-4" /></span>
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <p class="text-sm font-medium leading-snug text-foreground">{{ item.name }}</p>
                            <p class="flex items-start gap-1.5 text-xs leading-relaxed text-muted-foreground" :title="itemState(item).title">
                                <span class="mt-[0.4rem] h-1.5 w-1.5 shrink-0 rounded-full" :class="itemState(item).dot" aria-hidden="true" />
                                {{ itemState(item).label }}
                            </p>
                            <div
                                v-if="physician && ((can.approve && item.approval?.state === 'AWAITING') || (can.return && !item.in_correction && item.status === 'VALIDATED'))"
                                class="flex flex-wrap items-center gap-1.5 pt-1"
                            >
                                <Button
                                    v-if="can.approve && item.approval?.state === 'AWAITING'"
                                    type="button"
                                    size="xs"
                                    variant="outline"
                                    @click="askApprove(item)"
                                >
                                    <CheckCircle2 class="h-3.5 w-3.5 text-primary" /> Valider
                                </Button>
                                <Button
                                    v-if="can.return && !item.in_correction && item.status === 'VALIDATED'"
                                    type="button"
                                    size="xs"
                                    variant="ghost"
                                    class="text-muted-foreground hover:text-destructive"
                                    :title="`Demander à refaire « ${item.name} »`"
                                    @click="askRedo(item)"
                                >
                                    <RotateCcw class="h-3.5 w-3.5" /> Demander à refaire
                                </Button>
                            </div>
                        </div>
                    </li>
                </ul>
                <p v-else class="px-4 py-4 text-sm text-muted-foreground">{{ physician ? 'Aucun résultat envoyé par le laboratoire pour l’instant.' : 'Aucune analyse rendue pour cette demande.' }}</p>
                <div v-if="physician && pending.length" class="border-t border-border bg-muted/30 px-4 py-3 text-xs text-muted-foreground">
                    <p class="flex items-center gap-1.5 font-medium text-foreground"><Hourglass class="h-3.5 w-3.5 text-muted-foreground" /> En cours au laboratoire</p>
                    <p class="mt-1 leading-relaxed">{{ pending.join(', ') }}</p>
                </div>
            </Card>

            <!-- Le PDF lui-même -->
            <Card class="overflow-hidden">
                <iframe
                    v-if="items.length"
                    :key="pdfKey"
                    ref="frame"
                    :src="pdfUrl"
                    :title="`Compte rendu d’analyses de ${formatPatientName(patient)}`"
                    class="block h-[78vh] min-h-[32rem] w-full bg-muted"
                    @load="loaded = true"
                />
                <div v-else class="flex h-64 flex-col items-center justify-center gap-2 p-6 text-center text-sm text-muted-foreground">
                    <FileText class="h-8 w-8" />
                    <p>Le compte rendu s’affichera ici dès qu’un résultat sera {{ physician ? 'envoyé' : 'saisi' }}.</p>
                </div>
            </Card>
        </div>
        <Dialog
            :open="redoItem !== null"
            title="Demander à refaire"
            :description="redoItem ? `« ${redoItem.name} » repart au laboratoire avec votre motif ; le technicien qui l’a envoyée est prévenu. La valeur reçue reste lisible, marquée « en correction ».` : ''"
            :dismissible="false"
            @update:open="(open) => { if (!open) redoItem = null; }"
        >
            <FormField label="Motif" :error="redoForm.errors.reason" required>
                <Textarea v-model="redoForm.reason" rows="3" placeholder="Ex. valeur incohérente avec la clinique, à contrôler…" />
            </FormField>
            <template #footer>
                <Button type="button" variant="outline" @click="redoItem = null">Annuler</Button>
                <Button type="button" variant="danger" :disabled="redoForm.processing || redoForm.reason.trim().length < 3" @click="submitRedo">
                    <AlertTriangle class="h-4 w-4" /> Demander à refaire
                </Button>
            </template>
        </Dialog>

        <ConfirmModal
            :open="approving !== null"
            :title="approving && approving.length === 1 ? `Valider « ${approving[0].name} »` : `Valider ${approving?.length ?? 0} résultats`"
            description="Vous avez relu le compte rendu et vous en êtes d’accord. Le résultat est validé à votre nom ; la Réception peut alors le remettre au patient."
            :confirm-label="approving && approving.length === 1 ? 'Valider le résultat' : 'Tout valider'"
            tone="success"
            :icon="BadgeCheck"
            :processing="approveForm.processing"
            :dismissible="false"
            @update:open="(open) => open || approveForm.processing || (approving = null)"
            @confirm="submitApprove"
        >
            <ul v-if="approving" class="space-y-1 text-sm">
                <li v-for="item in approving" :key="item.uuid" class="flex items-center gap-2 text-foreground"><FlaskConical class="h-3.5 w-3.5 text-muted-foreground" />{{ item.name }}</li>
            </ul>
            <p v-if="approveForm.errors.items" class="mt-2 text-sm font-medium text-destructive">{{ approveForm.errors.items }}</p>
        </ConfirmModal>

        <p v-if="items.length" class="text-xs text-muted-foreground">
            Le PDF ne s’affiche pas ? <a :href="pdfUrl" target="_blank" rel="noopener" class="font-medium text-primary underline-offset-2 hover:underline">Ouvrez-le dans un onglet</a> ou téléchargez-le.
        </p>
    </div>
</template>
