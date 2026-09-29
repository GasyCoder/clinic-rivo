<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, Download, ExternalLink, FileText, FlaskConical, Hourglass, Microscope, Printer, RotateCcw, TriangleAlert } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import SealedLabResult from '@/Components/Laboratory/SealedLabResult.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';
import { LAB_STATUS_TONES } from '@/utilities/labWorkbench';
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
        <Card class="flex flex-wrap items-start justify-between gap-3 p-4">
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
        </Card>

        <div v-if="provisional" class="flex gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200" role="status">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
            <p>Document provisoire : au moins une analyse n’est pas encore envoyée au médecin. Le PDF le dit en tête.</p>
        </div>

        <div class="grid gap-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
            <!-- Ce que porte le compte rendu -->
            <Card class="h-fit p-3">
                <p class="px-1 pb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Analyses du compte rendu</p>
                <ul v-if="items.length" class="space-y-1">
                    <li v-for="item in items" :key="item.uuid" class="flex items-start justify-between gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-muted/50">
                        <span class="flex min-w-0 items-start gap-2">
                            <FlaskConical class="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                            <span class="min-w-0 text-foreground">{{ item.name }}</span>
                        </span>
                        <span class="flex shrink-0 flex-col items-end gap-1">
                            <Badge :tone="item.in_correction ? 'danger' : LAB_STATUS_TONES[item.status]">{{ item.in_correction ? 'En correction' : item.status_label }}</Badge>
                            <Button
                                v-if="physician && can.return && !item.in_correction && item.status === 'VALIDATED'"
                                type="button"
                                size="xs"
                                variant="ghost"
                                class="text-destructive hover:text-destructive"
                                @click="askRedo(item)"
                            >
                                <RotateCcw class="h-3.5 w-3.5" /> Demander à refaire
                            </Button>
                        </span>
                    </li>
                </ul>
                <p v-else class="px-1 text-sm text-muted-foreground">{{ physician ? 'Aucun résultat envoyé par le laboratoire pour l’instant.' : 'Aucune analyse rendue pour cette demande.' }}</p>
                <div v-if="physician && pending.length" class="mt-3 border-t border-border px-1 pt-2 text-xs text-muted-foreground">
                    <p class="flex items-center gap-1 font-semibold"><Hourglass class="h-3 w-3" /> En cours au laboratoire</p>
                    <p class="mt-1">{{ pending.join(', ') }}</p>
                </div>
            </Card>

            <!-- Le PDF lui-même -->
            <Card class="overflow-hidden">
                <iframe
                    v-if="items.length"
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

        <p v-if="items.length" class="text-xs text-muted-foreground">
            Le PDF ne s’affiche pas ? <a :href="pdfUrl" target="_blank" rel="noopener" class="font-medium text-primary underline-offset-2 hover:underline">Ouvrez-le dans un onglet</a> ou téléchargez-le.
        </p>
    </div>
</template>
