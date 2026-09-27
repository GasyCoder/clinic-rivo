<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import SurgerySection from '@/Components/Surgery/SurgerySection.vue';
import { CheckCircle2, Info, Lock, Save } from 'lucide-vue-next';
import { formatDateTime } from '@/utilities/date';

/**
 * Le feu vert chirurgical avant bloc (SCHEDULED → PREOPERATIVE_VALIDATED).
 *
 * Il ne se confirme qu'une fois l'intervention programmée — la transition que
 * le serveur refuse sinon — et le bouton dit pourquoi il attend, au lieu de
 * rester gris sans un mot. Une fois confirmé, le contrôle est verrouillé.
 */
const props = defineProps({
    surgicalRequest: Object,
    canUpdate: Boolean,
    /** Le droit surgery.preoperative.validate ; le statut est lu ici. */
    canValidate: Boolean,
    /** Rang dans l'étape Préparation (ADR-048). */
    order: { type: Number, default: null },
});

const base = computed(() => `/surgery/${props.surgicalRequest.uuid}`);
const form = useForm({ preoperative_notes: props.surgicalRequest.preoperative_notes ?? '' });
const validating = ref(false);
const validated = computed(() => Boolean(props.surgicalRequest.preoperative_validated_at));
const scheduled = computed(() => props.surgicalRequest.status === 'SCHEDULED');
// Fait une fois confirmé, à faire une fois programmé, en attente avant.
const sectionState = computed(() => {
    if (validated.value) return 'done';

    return scheduled.value ? 'todo' : (props.surgicalRequest.status === 'PENDING' ? 'waiting' : null);
});
const dirty = computed(() => (form.preoperative_notes ?? '') !== (props.surgicalRequest.preoperative_notes ?? ''));

// Pourquoi « Confirmer » attend : dit en toutes lettres, jamais un bouton gris muet.
const blocker = computed(() => {
    if (props.surgicalRequest.status === 'PENDING') return 'Programmez d’abord l’intervention (chirurgien et date).';
    if (!scheduled.value) return null;
    if (!props.surgicalRequest.preoperative_notes) return 'Enregistrez d’abord les observations de l’équipe chirurgicale.';
    if (dirty.value) return 'Enregistrez les observations modifiées avant de confirmer.';

    return null;
});

const submit = () => form.transform((data) => ({ ...data, _method: 'put' })).post(base.value, { preserveScroll: true });
const validate = () => {
    validating.value = true;
    router.post(`${base.value}/preoperative/validate`, {}, { preserveScroll: true, onFinish: () => { validating.value = false; } });
};
</script>

<template>
    <SurgerySection
        id="surgery-preoperative"
        class="xl:col-span-12"
        :icon="CheckCircle2"
        title="Feu vert chirurgical avant bloc"
        :order="order"
        :state="sectionState"
        waiting-label="Après la programmation"
        description="L’équipe chirurgicale confirme que l’organisation est prête. Ce contrôle ne répète ni l’examen clinique, ni la décision de l’anesthésiste."
    >
        <template #waiting>Se confirme une fois l’intervention programmée (date et chirurgiens), à l’étape Dossier.</template>
        <template v-if="validated" #badge>
            <Badge variant="outline"><Lock class="h-3 w-3" />Verrouillé</Badge>
        </template>

        <div v-if="validated" class="space-y-2">
            <p class="whitespace-pre-line rounded-lg border border-border bg-muted/30 p-3 text-sm text-foreground">{{ surgicalRequest.preoperative_notes || 'Aucune observation chirurgicale.' }}</p>
            <p class="flex items-center gap-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                <Lock class="h-3.5 w-3.5" aria-hidden="true" />
                Feu vert confirmé<template v-if="surgicalRequest.preoperative_validated_by"> par {{ surgicalRequest.preoperative_validated_by.name }}</template> le {{ formatDateTime(surgicalRequest.preoperative_validated_at) }} — contrôle verrouillé.
            </p>
        </div>

        <form v-else-if="canUpdate" class="space-y-3" @submit.prevent="submit">
            <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_16rem]">
                <FormField label="Observations ou réserve chirurgicale" :error="form.errors.preoperative_notes">
                    <Textarea id="preoperative_notes" v-model="form.preoperative_notes" rows="3" placeholder="Organisation, matériel ou consigne à transmettre…" />
                </FormField>
                <aside class="rounded-lg border border-border bg-muted/30 p-3 text-xs leading-5 text-muted-foreground">
                    <strong class="block text-foreground">Déjà au dossier, pas ressaisi ici</strong>
                    Constantes et actes des Soins · examen anesthésique · résultats paracliniques.
                </aside>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <p v-if="blocker" class="me-auto flex items-center gap-1.5 text-xs text-muted-foreground"><Info class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ blocker }}</p>
                <Button type="submit" variant="white-outline" :disabled="form.processing || !dirty"><Save class="h-4 w-4" />Enregistrer les observations</Button>
                <Button
                    v-if="canValidate && scheduled"
                    type="button"
                    :disabled="validating || Boolean(blocker)"
                    @click="validate"
                ><CheckCircle2 class="h-4 w-4" />Confirmer le feu vert</Button>
            </div>
        </form>

        <p v-else class="whitespace-pre-line text-sm text-muted-foreground">{{ surgicalRequest.preoperative_notes || 'Aucune observation chirurgicale pour l’instant.' }}</p>
    </SurgerySection>
</template>
