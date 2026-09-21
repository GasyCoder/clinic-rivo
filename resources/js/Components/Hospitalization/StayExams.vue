<script setup>
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import ImagingReportDialog from '@/Components/Clinical/ImagingReportDialog.vue';
import ImagingReportDocument from '@/Components/Medicine/ImagingReportDocument.vue';
import { formatDateTime } from '@/utilities/date';
import { doctorName } from '@/utilities/doctorName';
import { Eye, FlaskConical, Hourglass, CircleCheck, Pencil, Printer, ScanLine, Search, Send, Undo2 } from 'lucide-vue-next';

/**
 * ADR-162 — analyses et imagerie demandées depuis le séjour.
 *
 * Mêmes catalogues, même facturation à la demande (ADR-105) et même fenêtre
 * de compte rendu qu'en consultation : seule l'origine change. La demande est
 * un acte signé (ADR-106), confirmée examen par examen.
 *
 * ADR-163 — une demande sans résultat se retire d'ici, comme en consultation
 * (ADR-079) : elle reste lisible, « Retirée », et ce qu'elle avait porté au
 * compte du patient est annulé.
 */
const props = defineProps({
    stayUuid: { type: String, required: true },
    labRequests: { type: Array, default: null },
    imagingRequests: { type: Array, default: null },
    labCatalog: { type: Array, default: () => [] },
    imagingCatalog: { type: Array, default: () => [] },
    canRequestLab: { type: Boolean, default: false },
    canRequestImaging: { type: Boolean, default: false },
    orientationUuid: { type: String, default: '' },
    templates: { type: Array, default: () => [] },
    templateRights: { type: Object, default: () => ({}) },
    patientLabel: { type: String, default: '' },
});

const page = usePage();
/** Qui signe l'acte : le compte connecté, titré une seule fois. */
const signer = computed(() => doctorName(page.props.auth?.user?.name));
const fold = (value) => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** Un sélecteur par famille : analyses (Laboratoire) ou imagerie. */
const makePicker = (kind) => {
    const search = ref('');
    const chosen = ref(new Set());
    const form = useForm({ items: [], notes: '' });
    const catalog = computed(() => (kind === 'lab' ? props.labCatalog : props.imagingCatalog));
    const filtered = computed(() => {
        const query = fold(search.value.trim());

        return (query === '' ? catalog.value : catalog.value.filter((item) => fold(item.name).includes(query) || fold(item.code).includes(query))).slice(0, 40);
    });
    const toggle = (uuid) => {
        const next = new Set(chosen.value);
        next.has(uuid) ? next.delete(uuid) : next.add(uuid);
        chosen.value = next;
    };
    const names = computed(() => catalog.value.filter((item) => chosen.value.has(item.uuid)).map((item) => item.name));

    return { kind, search, chosen, form, filtered, toggle, names };
};
const lab = makePicker('lab');
const imaging = makePicker('imaging');

const confirming = ref(null);
const submit = () => {
    const picker = confirming.value;
    const path = picker.kind === 'lab' ? 'analyses' : 'imagerie';

    picker.form
        .transform((data) => ({ items: [...picker.chosen.value].map((uuid) => ({ catalog_item_uuid: uuid })), notes: data.notes }))
        .post(`/hospitalisation/${props.stayUuid}/${path}`, {
            preserveScroll: true,
            onSuccess: () => {
                picker.chosen.value = new Set();
                picker.form.reset();
                confirming.value = null;
            },
            onError: () => { confirming.value = null; },
        });
};

const STATUS = {
    REQUESTED: { label: 'En attente', variant: 'warning' },
    PARTIAL: { label: 'Partiel', variant: 'secondary' },
    COMPLETED: { label: 'Résultats rendus', variant: 'success' },
    CANCELLED: { label: 'Retirée', variant: 'outline' },
};
const status = (value) => STATUS[value] ?? { label: value, variant: 'outline' };

// ── Retirer une demande (ADR-163) ───────────────────────────────────────────
const withdrawing = ref(null);
const withdrawForm = useForm({ reason: '' });
const openWithdraw = (request, kind) => {
    withdrawForm.reset();
    withdrawForm.clearErrors();
    withdrawing.value = { request, kind };
};
const submitWithdraw = () => {
    const { request, kind } = withdrawing.value;

    withdrawForm.post(`/hospitalisation/${props.stayUuid}/${kind === 'lab' ? 'analyses' : 'imagerie'}/${request.uuid}/retirer`, {
        preserveScroll: true,
        onSuccess: () => { withdrawing.value = null; },
    });
};

// ── Compte rendu d'imagerie : la même fenêtre qu'en consultation ────────────
const reporting = ref(null);
const openReport = (item, mode) => { reporting.value = { item, mode }; };
const viewing = ref(null);
</script>

<template>
    <div class="space-y-5">
        <div v-if="canRequestLab || canRequestImaging" class="grid gap-5 xl:grid-cols-2">
            <template v-for="picker in [canRequestLab ? lab : null, canRequestImaging ? imaging : null].filter(Boolean)" :key="picker.kind">
                <Card class="flex min-w-0 flex-col p-5">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground">
                        <component :is="picker.kind === 'lab' ? FlaskConical : ScanLine" class="h-4 w-4 text-muted-foreground" />
                        {{ picker.kind === 'lab' ? 'Demander des analyses' : 'Demander un examen d’imagerie' }}
                    </h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ picker.kind === 'lab' ? 'Transmises au Laboratoire et portées au compte du patient.' : 'ECG, échographie : le compte rendu se saisit ci-dessous.' }}
                    </p>
                    <IconInput v-model="picker.search.value" :icon="Search" class="mt-3" placeholder="Rechercher" :aria-label="picker.kind === 'lab' ? 'Rechercher une analyse' : 'Rechercher un examen'" />
                    <ul class="mt-2 max-h-56 space-y-1 overflow-y-auto">
                        <li v-for="item in picker.filtered.value" :key="item.uuid">
                            <label class="flex cursor-pointer items-center gap-2 rounded-md border border-border bg-card px-2.5 py-1.5 text-xs hover:bg-accent">
                                <Checkbox :model-value="picker.chosen.value.has(item.uuid)" @update:model-value="picker.toggle(item.uuid)" />
                                <span class="min-w-0 flex-1 truncate text-foreground">{{ item.name }}</span>
                                <span class="shrink-0 text-muted-foreground">{{ item.code }}</span>
                            </label>
                        </li>
                        <li v-if="!picker.filtered.value.length" class="px-2 py-3 text-center text-xs text-muted-foreground">Aucun examen trouvé.</li>
                    </ul>
                    <FormField label="Renseignements cliniques" class="mt-3">
                        <Textarea v-model="picker.form.notes" :rows="2" maxlength="2000" placeholder="Facultatif : ce que le service doit savoir." />
                    </FormField>
                    <FormError :message="picker.form.errors.lab_request || picker.form.errors.imaging_request || picker.form.errors.items" />
                    <div class="mt-auto flex justify-end pt-3">
                        <Button type="button" size="sm" :disabled="!picker.chosen.value.size || picker.form.processing" @click="confirming = picker">
                            <Send class="h-4 w-4" />Transmettre ({{ picker.chosen.value.size }})
                        </Button>
                    </div>
                </Card>
            </template>
        </div>

        <Card class="p-5">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><FlaskConical class="h-4 w-4 text-muted-foreground" />Analyses du séjour</h2>
            <p v-if="labRequests === null" class="mt-3 text-xs text-muted-foreground">Non visible avec vos droits (laboratory_orders.view).</p>
            <p v-else-if="!labRequests.length" class="mt-3 text-xs text-muted-foreground">Aucune analyse demandée depuis ce séjour.</p>
            <ul v-else class="mt-3 space-y-2">
                <li v-for="request in labRequests" :key="request.uuid" class="rounded-md border border-border bg-card p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                        <span><span class="font-semibold text-foreground">{{ formatDateTime(request.requested_at) }}</span><template v-if="request.requested_by"> · {{ doctorName(request.requested_by) }}</template></span>
                        <span class="flex items-center gap-2">
                            <Badge :variant="status(request.status).variant">{{ status(request.status).label }}</Badge>
                            <Button v-if="request.can_cancel" type="button" size="xs" variant="outline" class="text-destructive" @click="openWithdraw(request, 'lab')"><Undo2 class="h-3.5 w-3.5" />Retirer</Button>
                        </span>
                    </div>
                    <p v-if="request.status === 'CANCELLED' && request.cancel_reason" class="mt-1 text-xs text-muted-foreground">Motif : {{ request.cancel_reason }}</p>
                    <ul class="mt-2 space-y-1 text-sm">
                        <li v-for="item in request.items" :key="item.uuid" class="flex flex-wrap items-baseline gap-x-2">
                            <component :is="item.resulted_at ? CircleCheck : Hourglass" class="h-3.5 w-3.5 self-center text-muted-foreground" :aria-label="item.resulted_at ? 'Résultat rendu' : 'En attente'" />
                            <span class="font-medium text-foreground">{{ item.exam }}</span>
                            <span v-if="item.result" class="text-xs text-muted-foreground">{{ item.result }}</span>
                        </li>
                    </ul>
                </li>
            </ul>
        </Card>

        <Card class="p-5">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><ScanLine class="h-4 w-4 text-muted-foreground" />Imagerie du séjour</h2>
            <p v-if="imagingRequests === null" class="mt-3 text-xs text-muted-foreground">Non visible avec vos droits (imaging_orders.view).</p>
            <p v-else-if="!imagingRequests.length" class="mt-3 text-xs text-muted-foreground">Aucun examen d’imagerie demandé depuis ce séjour.</p>
            <ul v-else class="mt-3 space-y-2">
                <li v-for="request in imagingRequests" :key="request.uuid" class="rounded-md border border-border bg-card p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                        <span><span class="font-semibold text-foreground">{{ formatDateTime(request.requested_at) }}</span><template v-if="request.requested_by"> · {{ doctorName(request.requested_by) }}</template></span>
                        <span class="flex items-center gap-2">
                            <Badge :variant="status(request.status).variant">{{ status(request.status).label }}</Badge>
                            <Button v-if="request.can_cancel" type="button" size="xs" variant="outline" class="text-destructive" @click="openWithdraw(request, 'imaging')"><Undo2 class="h-3.5 w-3.5" />Retirer</Button>
                        </span>
                    </div>
                    <p v-if="request.status === 'CANCELLED' && request.cancel_reason" class="mt-1 text-xs text-muted-foreground">Motif : {{ request.cancel_reason }}</p>
                    <ul class="mt-2 space-y-1.5">
                        <li v-for="item in request.items" :key="item.uuid" class="flex flex-wrap items-center gap-2 text-sm">
                            <component :is="item.resulted_at ? CircleCheck : Hourglass" class="h-3.5 w-3.5 text-muted-foreground" :aria-label="item.resulted_at ? 'Compte rendu enregistré' : 'En attente'" />
                            <span class="font-medium text-foreground">{{ item.exam }}</span>
                            <span class="ms-auto flex items-center gap-1">
                                <Button v-if="item.can_record" type="button" size="xs" variant="outline" @click="openReport(item, 'record')"><Pencil class="h-3.5 w-3.5" />Saisir</Button>
                                <Button v-if="item.document" type="button" size="icon-xs" variant="ghost" :aria-label="`Voir le compte rendu — ${item.exam}`" title="Voir" @click="viewing = item"><Eye class="h-3.5 w-3.5" /></Button>
                                <Button v-if="item.can_correct" type="button" size="icon-xs" variant="ghost" :aria-label="`Modifier le compte rendu — ${item.exam}`" title="Modifier" @click="openReport(item, 'correct')"><Pencil class="h-3.5 w-3.5" /></Button>
                                <Button v-if="item.print_url" as="a" :href="item.print_url" size="icon-xs" variant="ghost" :aria-label="`Imprimer le compte rendu — ${item.exam}`" title="Imprimer"><Printer class="h-3.5 w-3.5" /></Button>
                            </span>
                        </li>
                    </ul>
                </li>
            </ul>
        </Card>

        <Dialog :open="confirming !== null" :title="confirming?.kind === 'lab' ? 'Transmettre les analyses' : 'Transmettre l’examen d’imagerie'" description="Chaque examen part au service et rejoint le compte du patient." size="md" :dismissible="false" @update:open="(value) => { if (!value) confirming = null; }">
            <ul class="space-y-1 text-sm">
                <li v-for="name in confirming?.names.value ?? []" :key="name" class="rounded-md border border-border px-3 py-1.5 text-foreground">{{ name }}</li>
            </ul>
            <p class="mt-3 text-xs text-muted-foreground">Sous la responsabilité de <span class="font-semibold text-foreground">{{ signer }}</span>.</p>
            <template #footer>
                <Button type="button" size="sm" variant="ghost" @click="confirming = null">Revenir</Button>
                <Button type="button" size="sm" :disabled="confirming?.form.processing" @click="submit"><Send class="h-4 w-4" />Je transmets</Button>
            </template>
        </Dialog>

        <Dialog
            :open="withdrawing !== null"
            :title="withdrawing?.kind === 'lab' ? 'Retirer les analyses' : 'Retirer l’examen d’imagerie'"
            description="Aucun résultat n’a été saisi : la demande quitte la file du service. Elle reste lisible, « Retirée », et ce qu’elle avait porté au compte du patient est annulé."
            size="md"
            :dismissible="false"
            @update:open="(value) => { if (!value) withdrawing = null; }"
        >
            <form id="stay-exam-withdraw" class="space-y-3" @submit.prevent="submitWithdraw">
                <ul class="space-y-1 text-sm">
                    <li v-for="item in withdrawing?.request.items ?? []" :key="item.uuid" class="rounded-md border border-border px-3 py-1.5 text-foreground">{{ item.exam }}</li>
                </ul>
                <FormField label="Motif (facultatif)" :error="withdrawForm.errors.reason">
                    <Textarea v-model="withdrawForm.reason" :rows="2" maxlength="500" placeholder="Ex. : finalement inutile" />
                </FormField>
                <FormError :message="withdrawForm.errors.request" />
            </form>
            <template #footer>
                <Button type="button" size="sm" variant="ghost" :disabled="withdrawForm.processing" @click="withdrawing = null">Garder la demande</Button>
                <Button type="submit" form="stay-exam-withdraw" size="sm" variant="destructive" :disabled="withdrawForm.processing"><Undo2 class="h-4 w-4" />Retirer</Button>
            </template>
        </Dialog>

        <ImagingReportDialog
            :item="reporting ? reporting.item : null"
            :mode="reporting?.mode ?? 'record'"
            :orientation-uuid="orientationUuid"
            :subtitle="patientLabel"
            :templates="templates"
            :template-rights="templateRights"
            @close="reporting = null"
            @saved="reporting = null"
        />

        <Dialog :open="viewing !== null" size="wide" body-class="max-h-[72vh] overflow-y-auto" :title="viewing ? `Compte rendu — ${viewing.exam}` : 'Compte rendu'" :description="patientLabel" @update:open="(value) => { if (!value) viewing = null; }">
            <ImagingReportDocument v-if="viewing?.document" :document="viewing.document" />
        </Dialog>
    </div>
</template>
