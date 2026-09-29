<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import RefreshIcon from '@/Components/Shadcn/RefreshIcon.vue';
import LabSampleLinesEditor from '@/Components/Laboratory/LabSampleLinesEditor.vue';
import { ChevronDown, Info, Lock, Play, ShieldAlert, Wallet } from 'lucide-vue-next';
import { PAYMENT_TONES, emptySampleLine, sampleLinesPayload, sampleLinesTubeCount } from '@/utilities/labReception';
import { labUrl } from '@/utilities/labUrl';
import LabSiteOnlyAction from '@/Components/Laboratory/LabSiteOnlyAction.vue';

/**
 * ADR-217 — la prise en charge d'une demande au laboratoire. Comme dans
 * labo-vuejs, le technicien la « traite » d'un geste : la demande reçoit son
 * numéro de laboratoire et la saisie s'ouvre. Le règlement est affiché pour
 * information — le patient règle à la Caisse, il ne retient pas la paillasse.
 * Les prélèvements peuvent être enregistrés dans le même geste, ou plus tard.
 */
const props = defineProps({
    labRequest: { type: Object, required: true },
    payment: { type: Object, default: null },
    sampleOptions: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
});

const page = usePage();
const form = useForm({ samples: [] });
const showSamples = ref(false);
const openSamples = () => {
    showSamples.value = true;
    if (form.samples.length === 0) form.samples.push(emptySampleLine(props.sampleOptions));
};

const tubes = computed(() => (showSamples.value ? sampleLinesTubeCount(form.samples) : 0));
const requestError = computed(() => form.errors.request ?? page.props.errors?.request ?? form.errors.samples ?? null);
const due = computed(() => (props.payment?.lines ?? []).filter((line) => line.blocking));

// Avec des prélèvements : la réception les enregistre dans le même geste ; sinon, « Traiter ».
const start = () => {
    const samples = props.can.sample ? sampleLinesPayload(form.samples) : [];
    if (samples.length) {
        form.transform(() => ({ samples })).post(labUrl(`/laboratory/requests/${props.labRequest.uuid}/receive`), { preserveScroll: true });
    } else {
        form.transform(() => ({})).post(labUrl(`/laboratory/requests/${props.labRequest.uuid}/start`), { preserveScroll: true });
    }
};

const refreshing = ref(false);
const refresh = () => {
    refreshing.value = true;
    router.reload({ only: ['payment', 'labRequest'], onFinish: () => { refreshing.value = false; } });
};
</script>

<template>
    <Card class="overflow-hidden border-primary/30">
        <div class="flex flex-wrap items-center justify-between gap-3 bg-primary/5 px-4 py-3">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><Play class="h-4 w-4" /></span>
                <div>
                    <h2 class="text-base font-bold text-foreground">Demande pas encore commencée</h2>
                    <p class="text-xs text-muted-foreground">Traitez-la pour lui donner son numéro de laboratoire — ou saisissez directement un résultat ci-dessous : elle est prise en charge à la première saisie.</p>
                </div>
            </div>
            <LabSiteOnlyAction v-if="(can.start || can.site_only) && !labRequest.cancelled" label="Commencer le traitement" variant="default">
                <Button type="button" :disabled="form.processing" @click="start">
                    <Play class="h-4 w-4" /> Commencer le traitement<template v-if="tubes"> · {{ tubes }} tube(s)</template>
                </Button>
            </LabSiteOnlyAction>
            <p v-else-if="!can.start" class="flex items-center gap-1.5 text-xs text-muted-foreground"><Lock class="h-3.5 w-3.5" />Demande le droit « laboratory_results.create ».</p>
        </div>

        <div v-if="(payment && (due.length || payment.unbilled_count)) || (can.sample && sampleOptions && !can.site_only) || requestError" class="space-y-3 border-t border-border px-4 py-3">
            <!-- Le règlement : une information, jamais un verrou -->
            <div v-if="payment && (due.length || payment.unbilled_count)" class="flex flex-wrap items-start justify-between gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-200" role="status">
                <p class="flex gap-2">
                    <Wallet class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>
                        <strong>{{ payment.summary }}</strong>
                        <span class="block text-xs">Le patient règle à la Caisse ; le laboratoire n’encaisse rien et n’attend pas le règlement pour analyser.</span>
                    </span>
                </p>
                <Button type="button" size="xs" variant="ghost" :disabled="refreshing" @click="refresh">
                    <RefreshIcon :spinning="refreshing" class="h-3.5 w-3.5" /> Actualiser
                </Button>
            </div>
            <ul v-if="payment && (due.length || payment.unbilled_count)" class="flex flex-wrap gap-1.5">
                <li v-for="line in payment.lines" :key="line.uuid"><Badge :tone="PAYMENT_TONES[line.state]">{{ line.name }} · {{ line.label }}</Badge></li>
            </ul>
            <p v-if="payment?.unbilled_count" class="flex gap-2 text-xs text-muted-foreground">
                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />Une analyse n’est pas facturée : la Réception doit la régulariser.
            </p>

            <!-- Les prélèvements, facultatifs dans le même geste -->
            <template v-if="can.sample && sampleOptions && !can.site_only">
                <button v-if="!showSamples" type="button" class="flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline" @click="openSamples">
                    <ChevronDown class="h-4 w-4" /> Enregistrer les prélèvements maintenant (facultatif)
                </button>
                <div v-else>
                    <p class="mb-2 text-sm font-semibold text-foreground">Prélèvements <span class="font-normal text-muted-foreground">— facultatifs, ajoutables ensuite</span></p>
                    <LabSampleLinesEditor v-model="form.samples" :options="sampleOptions" :errors="form.errors" :disabled="form.processing" />
                </div>
            </template>

            <p v-if="requestError" class="flex gap-2 rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">
                <ShieldAlert class="mt-0.5 h-4 w-4 shrink-0" />{{ requestError }}
            </p>
        </div>
    </Card>
</template>
