<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import SurgerySection from '@/Components/Surgery/SurgerySection.vue';
import { formatDateTime } from '@/utilities/date';
import { CalendarClock, FileText, Info, MessageSquareText, PenLine, Pencil, Save, Scissors, UserRound, X } from 'lucide-vue-next';

/**
 * ADR-048 — la demande reçue par le bloc, relisible à chaque étape.
 *
 * Trois faits, chacun avec son repère : ce qu'on opère, qui l'a demandé et
 * quand, ce que le demandeur a transmis. La correction de l'intervention
 * (référentiel, « Autres » à préciser) et de la transmission se fait sur place ;
 * les règles restent celles du serveur (`PUT /surgery/{demande}`, surgery.update).
 */
const props = defineProps({
    surgicalRequest: { type: Object, required: true },
    procedures: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
});

const OTHER_CODE = 'SURG-OTHER';
const editing = ref(false);
const showFullTransmission = ref(false);

const initial = () => ({
    catalog_item_uuid: props.surgicalRequest.catalog_item?.uuid ?? '',
    procedure_details: props.surgicalRequest.procedure_details ?? '',
    notes: props.surgicalRequest.notes ?? '',
});
const form = useForm(initial());

const procedureOptions = computed(() => props.procedures.map((procedure) => ({ value: procedure.uuid, label: procedure.name })));
const selectedProcedure = computed(() => props.procedures.find((procedure) => procedure.uuid === form.catalog_item_uuid) ?? null);
const usesOtherProcedure = computed(() => selectedProcedure.value?.code === OTHER_CODE);

/** La transmission se lit en quatre lignes ; le reste se déplie à la demande. */
const transmissionIsLong = computed(() => {
    const notes = props.surgicalRequest.notes ?? '';

    return notes.split('\n').length > 4 || notes.length > 240;
});

const open = () => {
    form.defaults(initial());
    form.reset();
    form.clearErrors();
    editing.value = true;
};
const cancel = () => {
    form.reset();
    form.clearErrors();
    editing.value = false;
};
const submit = () => form
    .transform((data) => ({ ...data, procedure_details: usesOtherProcedure.value ? data.procedure_details : null }))
    .put(`/surgery/${props.surgicalRequest.uuid}`, {
        preserveScroll: true,
        onSuccess: () => { editing.value = false; },
    });
</script>

<template>
    <SurgerySection compact :icon="FileText" title="Demande" description="Ce que le demandeur a transmis au bloc." body-class="px-4 py-3">
        <template #actions>
            <Button v-if="canEdit && !editing" size="xs" variant="white-outline" type="button" @click="open"><Pencil class="h-3 w-3" />Modifier</Button>
        </template>

        <form v-if="editing" class="space-y-3" @submit.prevent="submit">
            <FormField label="Intervention" required :error="form.errors.catalog_item_uuid">
                <Select id="catalog_item_uuid" v-model="form.catalog_item_uuid" :options="procedureOptions" :icon="Scissors" placeholder="Choisir dans le référentiel" class="w-full" required />
            </FormField>
            <FormField v-if="usesOtherProcedure" label="Précision de l’intervention" required :error="form.errors.procedure_details">
                <IconInput id="procedure_details" v-model="form.procedure_details" :icon="PenLine" placeholder="Nommer l’intervention" required />
            </FormField>
            <FormField label="Transmission au bloc" hint="(facultatif)" :error="form.errors.notes">
                <Textarea id="notes" v-model="form.notes" rows="6" placeholder="Contexte utile pour l’équipe du bloc" />
            </FormField>
            <p class="flex items-start gap-1.5 text-[11px] leading-4 text-muted-foreground">
                <Info class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />Service, lit, diagnostic, priorité : ce que l’équipe du bloc doit savoir avant d’opérer.
            </p>
            <div class="flex justify-end gap-2 border-t border-border pt-3">
                <Button size="sm" variant="white-outline" type="button" @click="cancel"><X class="h-3.5 w-3.5" />Annuler</Button>
                <Button size="sm" type="submit" :disabled="form.processing || !form.isDirty"><Save class="h-3.5 w-3.5" />Enregistrer</Button>
            </div>
        </form>

        <ul v-else class="space-y-3 text-sm">
            <li class="flex gap-3">
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-primary/10 text-primary" aria-hidden="true"><Scissors class="h-3.5 w-3.5" /></span>
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">Intervention demandée</p>
                    <p class="font-semibold text-foreground">{{ surgicalRequest.procedure_name }}</p>
                    <p v-if="surgicalRequest.procedure_details" class="text-xs text-muted-foreground">{{ surgicalRequest.procedure_details }}</p>
                </div>
            </li>
            <li class="flex gap-3">
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-muted text-muted-foreground" aria-hidden="true"><UserRound class="h-3.5 w-3.5" /></span>
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">Demandée par</p>
                    <p class="truncate text-foreground">{{ surgicalRequest.requested_by?.name ?? 'Non renseigné' }}</p>
                    <p v-if="surgicalRequest.created_at" class="flex items-center gap-1 text-xs text-muted-foreground">
                        <CalendarClock class="h-3 w-3 shrink-0" aria-hidden="true" />{{ formatDateTime(surgicalRequest.created_at) }}
                    </p>
                </div>
            </li>
            <li class="flex gap-3">
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-muted text-muted-foreground" aria-hidden="true"><MessageSquareText class="h-3.5 w-3.5" /></span>
                <div class="min-w-0 flex-1">
                    <p class="text-xs text-muted-foreground">Transmission au bloc</p>
                    <p v-if="surgicalRequest.notes" :class="['whitespace-pre-line text-foreground', !showFullTransmission && 'line-clamp-4']">{{ surgicalRequest.notes }}</p>
                    <p v-else class="text-muted-foreground">Aucune transmission.</p>
                    <Button
                        v-if="transmissionIsLong"
                        size="xs"
                        variant="link"
                        class="mt-1 h-auto px-0"
                        type="button"
                        :aria-expanded="showFullTransmission"
                        @click="showFullTransmission = !showFullTransmission"
                    >{{ showFullTransmission ? 'Réduire' : 'Lire toute la transmission' }}</Button>
                </div>
            </li>
        </ul>
    </SurgerySection>
</template>
