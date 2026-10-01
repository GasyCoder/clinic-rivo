<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import LabSampleLinesEditor from '@/Components/Laboratory/LabSampleLinesEditor.vue';
import TubeChip from '@/Components/Laboratory/TubeChip.vue';
import { Ban, Plus, Printer, TestTubes } from 'lucide-vue-next';
import { formatDateTime } from '@/utilities/date';
import { emptySampleLine, sampleLinesPayload, sampleLinesTubeCount } from '@/utilities/labReception';
import { labUrl } from '@/utilities/labUrl';
import LabSiteOnlyAction from '@/Components/Laboratory/LabSiteOnlyAction.vue';

/**
 * ADR-214 — les prélèvements d'une demande reçue : chaque tube, son
 * code-barres, qui l'a prélevé ; un tube non conforme garde son motif et on en
 * enregistre un autre. Les étiquettes s'impriment d'ici.
 */
const props = defineProps({
    labRequest: { type: Object, required: true },
    samples: { type: Array, default: () => [] },
    sampleOptions: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
});

const active = computed(() => props.samples.filter((sample) => !sample.rejected));

const addOpen = ref(false);
const addForm = useForm({ samples: [] });
const openAdd = () => {
    addForm.reset();
    addForm.clearErrors();
    addForm.samples = [emptySampleLine(props.sampleOptions)];
    addOpen.value = true;
};
const tubes = computed(() => sampleLinesTubeCount(addForm.samples));
const add = () => addForm
    .transform((data) => ({ samples: sampleLinesPayload(data.samples) }))
    .post(labUrl(`/laboratory/requests/${props.labRequest.uuid}/samples`), { preserveScroll: true, onSuccess: () => { addOpen.value = false; } });

const rejecting = ref(null);
const rejectForm = useForm({ reason: '' });
const openReject = (sample) => { rejecting.value = sample; rejectForm.reset(); rejectForm.clearErrors(); };
const reject = () => rejectForm.post(labUrl(`/laboratory/samples/${rejecting.value.uuid}/reject`), {
    preserveScroll: true,
    onSuccess: () => { rejecting.value = null; },
});
const labelsHref = (sample = null) => labUrl(`/laboratory/requests/${props.labRequest.uuid}/etiquettes`) + (sample ? `?samples[]=${sample.uuid}` : '');
</script>

<template>
    <Card class="overflow-hidden">
        <header class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-3 py-2">
            <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><TestTubes class="h-3.5 w-3.5" /> Prélèvements · {{ active.length }}</p>
            <div class="flex gap-1">
                <Button v-if="active.length" :as="Link" :href="labelsHref()" size="xs" variant="outline"><Printer class="h-3.5 w-3.5" /> Étiquettes</Button>
                <LabSiteOnlyAction v-if="(can.sample && sampleOptions || can.site_only) && !labRequest.cancelled" label="Ajouter" size="xs">
                    <Button type="button" size="xs" variant="outline" @click="openAdd"><Plus class="h-3.5 w-3.5" /> Ajouter</Button>
                </LabSiteOnlyAction>
            </div>
        </header>
        <ul v-if="samples.length" class="max-h-[40vh] divide-y divide-border overflow-y-auto">
            <li v-for="sample in samples" :key="sample.uuid" :class="['px-3 py-2', sample.rejected && 'bg-muted/40']">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p :class="['truncate font-mono text-xs font-semibold', sample.rejected ? 'text-muted-foreground line-through' : 'text-foreground']">{{ sample.barcode }}</p>
                        <p class="truncate text-xs text-foreground">{{ sample.sample_type }}</p>
                        <TubeChip :tube="sample.tube" />
                        <p class="mt-0.5 text-[11px] text-muted-foreground">{{ formatDateTime(sample.collected_at) }}<template v-if="sample.collected_by"> · {{ sample.collected_by }}</template></p>
                        <p v-if="sample.rejected" class="mt-1 text-[11px] text-destructive">Non conforme<template v-if="sample.rejected.by"> ({{ sample.rejected.by }})</template> : {{ sample.rejected.reason }}</p>
                    </div>
                    <div v-if="!sample.rejected" class="flex shrink-0 gap-0.5">
                        <Button :as="Link" :href="labelsHref(sample)" size="icon-xs" variant="ghost" :aria-label="`Imprimer l’étiquette ${sample.barcode}`" :title="`Imprimer l’étiquette ${sample.barcode}`"><Printer class="h-3.5 w-3.5" /></Button>
                        <Button v-if="can.reject_sample && !labRequest.cancelled" type="button" size="icon-xs" variant="ghost" :aria-label="`Déclarer ${sample.barcode} non conforme`" title="Déclarer non conforme" @click="openReject(sample)"><Ban class="h-3.5 w-3.5 text-destructive" /></Button>
                    </div>
                </div>
            </li>
        </ul>
        <p v-else class="px-3 py-4 text-center text-xs text-muted-foreground">Aucun prélèvement enregistré.</p>
        <p v-if="samples.some((sample) => sample.rejected) && !active.length" class="border-t border-border px-3 py-2 text-xs text-amber-700 dark:text-amber-300">
            <Badge tone="warning">À reprélever</Badge> Aucun prélèvement conforme.
        </p>

        <Dialog v-model:open="addOpen" title="Ajouter des prélèvements" :description="`Demande ${labRequest.lab_number} — les codes-barres suivent ceux déjà posés.`" size="lg" :dismissible="false">
            <LabSampleLinesEditor v-model="addForm.samples" :options="sampleOptions" :errors="addForm.errors" :disabled="addForm.processing" />
            <p v-if="addForm.errors.samples" class="mt-2 text-sm text-destructive">{{ addForm.errors.samples }}</p>
            <template #footer>
                <Button type="button" variant="outline" @click="addOpen = false">Annuler</Button>
                <Button type="button" :disabled="addForm.processing || !tubes" @click="add"><Plus class="h-4 w-4" /> Enregistrer {{ tubes || '' }} tube(s)</Button>
            </template>
        </Dialog>

        <Dialog :open="Boolean(rejecting)" title="Prélèvement non conforme" :description="rejecting ? `${rejecting.barcode} — ${rejecting.sample_type}. Il reste dans le dossier avec votre motif.` : ''" :dismissible="false" @update:open="(value) => { if (!value) rejecting = null; }">
            <FormField label="Motif" :error="rejectForm.errors.reason" required>
                <Textarea v-model="rejectForm.reason" rows="3" placeholder="Ex. hémolysé, coagulé, volume insuffisant, tube mal identifié…" />
            </FormField>
            <template #footer>
                <Button type="button" variant="outline" @click="rejecting = null">Annuler</Button>
                <Button type="button" variant="danger" :disabled="rejectForm.processing || rejectForm.reason.trim().length < 3" @click="reject"><Ban class="h-4 w-4" /> Déclarer non conforme</Button>
            </template>
        </Dialog>
    </Card>
</template>
