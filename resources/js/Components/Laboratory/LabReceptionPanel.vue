<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import RefreshIcon from '@/Components/Shadcn/RefreshIcon.vue';
import LabSampleLinesEditor from '@/Components/Laboratory/LabSampleLinesEditor.vue';
import { ClipboardCheck, Hourglass, Info, Lock, ShieldAlert, Siren, Wallet } from 'lucide-vue-next';
import { PAYMENT_TONES, emptySampleLine, sampleLinesPayload, sampleLinesTubeCount } from '@/utilities/labReception';

/**
 * ADR-214 — la réception au laboratoire (CDC §14) : le contrôle du règlement,
 * puis les prélèvements dans le même geste. Le Laboratoire n'encaisse rien :
 * une analyse à régler renvoie à la Caisse.
 */
const props = defineProps({
    labRequest: { type: Object, required: true },
    payment: { type: Object, required: true },
    sampleOptions: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
});

const page = usePage();
const form = useForm({ samples: [] });
if (props.can.sample && props.sampleOptions) {
    form.samples.push(emptySampleLine(props.sampleOptions));
}

const tubes = computed(() => sampleLinesTubeCount(form.samples));
const requestError = computed(() => form.errors.request ?? page.props.errors?.request ?? form.errors.samples ?? null);

const confirmOpen = ref(false);
const receive = () => form
    .transform((data) => ({ samples: props.can.sample ? sampleLinesPayload(data.samples) : [] }))
    .post(`/laboratory/requests/${props.labRequest.uuid}/receive`, {
        preserveScroll: true,
        onFinish: () => { confirmOpen.value = false; },
    });

const refreshing = ref(false);
const refresh = () => {
    refreshing.value = true;
    router.reload({ only: ['payment', 'labRequest'], onFinish: () => { refreshing.value = false; } });
};
</script>

<template>
    <Card class="overflow-hidden border-primary/30">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-primary/5 px-4 py-3">
            <div class="flex items-center gap-2">
                <ClipboardCheck class="h-5 w-5 text-primary" />
                <div>
                    <h2 class="text-base font-bold text-foreground">Réception au laboratoire</h2>
                    <p class="text-xs text-muted-foreground">Contrôle du règlement, puis prélèvements et étiquettes.</p>
                </div>
            </div>
            <Button type="button" size="sm" variant="ghost" :disabled="refreshing" @click="refresh">
                <RefreshIcon :spinning="refreshing" class="h-4 w-4" /> Actualiser
            </Button>
        </header>

        <div class="space-y-4 p-4">
            <!-- Le contrôle du règlement -->
            <div
                class="flex gap-2 rounded-lg px-3 py-2.5 text-sm"
                :class="payment.cleared ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-amber-50 text-amber-800 dark:bg-amber-950/30 dark:text-amber-300'"
                role="status"
            >
                <component :is="payment.exemption === 'EMERGENCY' ? Siren : (payment.cleared ? Wallet : Hourglass)" class="mt-0.5 h-4 w-4 shrink-0" />
                <div>
                    <p class="font-semibold">{{ payment.summary }}</p>
                    <p v-if="!payment.cleared" class="mt-0.5 text-xs">Le patient règle à la Caisse ; le laboratoire n’encaisse rien. Actualisez une fois le règlement fait.</p>
                </div>
            </div>
            <ul class="divide-y divide-border rounded-lg border border-border">
                <li v-for="line in payment.lines" :key="line.uuid" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                    <span class="font-medium text-foreground">{{ line.name }}</span>
                    <Badge :tone="PAYMENT_TONES[line.state]">{{ line.label }}</Badge>
                </li>
            </ul>
            <p v-if="payment.unbilled_count" class="flex gap-2 text-xs text-muted-foreground">
                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                Une analyse non facturée ne retient pas le prélèvement — il n’y a rien à régler — mais la Réception doit la régulariser.
            </p>

            <!-- Les prélèvements, dans le même geste -->
            <template v-if="payment.cleared && can.receive">
                <div v-if="can.sample && sampleOptions">
                    <p class="mb-2 text-sm font-semibold text-foreground">Prélèvements <span class="font-normal text-muted-foreground">— facultatifs ici, ajoutables ensuite</span></p>
                    <LabSampleLinesEditor v-model="form.samples" :options="sampleOptions" :errors="form.errors" :disabled="form.processing" />
                </div>
                <p v-else class="flex gap-2 text-xs text-muted-foreground"><Lock class="mt-0.5 h-3.5 w-3.5 shrink-0" />Les prélèvements demandent le droit « laboratory_samples.create ».</p>
            </template>
            <p v-else-if="!can.receive" class="flex gap-2 text-xs text-muted-foreground"><Lock class="mt-0.5 h-3.5 w-3.5 shrink-0" />Réceptionner une demande demande le droit « laboratory_orders.receive ».</p>

            <p v-if="requestError" class="flex gap-2 rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">
                <ShieldAlert class="mt-0.5 h-4 w-4 shrink-0" />{{ requestError }}
            </p>
        </div>

        <footer v-if="can.receive && !labRequest.cancelled" class="flex flex-wrap items-center justify-end gap-2 border-t border-border bg-muted/30 px-4 py-3">
            <span v-if="tubes" class="me-auto text-xs text-muted-foreground">{{ tubes }} tube(s) à étiqueter</span>
            <Button type="button" :disabled="!payment.cleared || form.processing" @click="confirmOpen = true">
                <ClipboardCheck class="h-4 w-4" /> Réceptionner la demande
            </Button>
        </footer>

        <ConfirmModal
            v-model:open="confirmOpen"
            title="Réceptionner la demande ?"
            :description="tubes ? `La demande reçoit son numéro de laboratoire et ${tubes} prélèvement(s) sont enregistrés à votre nom.` : 'La demande reçoit son numéro de laboratoire. Les prélèvements pourront être ajoutés ensuite.'"
            confirm-label="Réceptionner"
            :processing="form.processing"
            @confirm="receive"
        />
    </Card>
</template>
