<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { CircleCheck, PillBottle } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';

/**
 * ADR-037 — les médicaments qu'un médecin a ajoutés à la main sur une
 * ordonnance, faute de les trouver au référentiel Pharmacie. Aucun stock ni
 * prix n'a été engagé. « Marquer traité » consigne la suite donnée ; cela ne
 * crée ni médicament ni stock.
 */
const props = defineProps({
    lines: { type: Array, default: () => [] },
    /** L'adresse d'enregistrement d'une ligne traitée, donnée par l'écran. */
    reviewUrl: { type: Function, required: true },
    canReview: { type: Boolean, default: false },
});

const target = ref(null);
const form = useForm({ note: '' });

const open = (line) => {
    target.value = line;
    form.reset();
    form.clearErrors();
};
const close = () => {
    if (form.processing) return;
    target.value = null;
};
const submit = () => form.post(props.reviewUrl(target.value.id), {
    preserveScroll: true,
    onSuccess: () => { target.value = null; },
});

const formatDateTime = (value) => (value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
    : '—');
const posology = (line) => [line.dosage, line.frequency, line.duration].filter(Boolean).join(' · ');
</script>

<template>
    <Card class="min-w-0 overflow-hidden">
        <div class="flex items-start gap-3 p-5">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border border-border text-foreground"><PillBottle class="h-5 w-5" /></span>
            <div class="min-w-0">
                <h2 class="text-lg font-semibold leading-7 text-foreground">Médicaments à référencer</h2>
                <p class="text-sm text-muted-foreground">{{ lines.length }} demande{{ lines.length > 1 ? 's' : '' }} en attente</p>
                <p class="mt-1.5 max-w-2xl text-xs leading-5 text-muted-foreground">
                    Ajoutés à la main sur une ordonnance, faute d’être au référentiel Pharmacie. Aucun stock ni prix n’a été engagé. Les ajouter au référentiel reste un geste de la Pharmacie ; ici, on consigne la suite donnée.
                </p>
            </div>
        </div>

        <ul v-if="lines.length" class="divide-y divide-border border-t border-border">
            <li v-for="line in lines" :key="line.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-foreground">{{ line.medication_name }}<span class="ms-2 text-xs font-normal tabular-nums text-muted-foreground">× {{ line.quantity }}</span></p>
                    <p v-if="posology(line)" class="mt-1 text-xs text-muted-foreground">{{ posology(line) }}</p>
                    <p v-if="line.instructions" class="mt-1 text-xs text-muted-foreground">{{ line.instructions }}</p>
                    <p class="mt-2 text-xs text-muted-foreground">
                        Prescrit par {{ line.prescribed_by || 'non renseigné' }} · passage <span class="font-mono">{{ line.episode_number || '—' }}</span> · patient <span class="font-mono">{{ line.patient_number || '—' }}</span> · {{ formatDateTime(line.prescribed_at) }}
                    </p>
                </div>
                <Button v-if="canReview" type="button" size="sm" variant="outline" class="shrink-0" @click="open(line)"><CircleCheck class="h-4 w-4" />Marquer traité</Button>
            </li>
        </ul>
        <div v-else class="border-t border-border px-5 py-14 text-center">
            <p class="text-sm font-medium text-foreground">Aucune demande en attente</p>
            <p class="mt-1 text-sm text-muted-foreground">Les médicaments ajoutés à la main par un médecin apparaîtront ici.</p>
        </div>
    </Card>

    <Dialog
        :open="target !== null"
        :title="target ? `Marquer « ${target.medication_name} » traité` : ''"
        description="Décrivez la suite donnée : ajouté au référentiel sous tel code, doublon, non retenu… Cela ne crée ni médicament ni stock."
        :dismissible="false"
        @update:open="(value) => value || close()"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border border-border text-foreground"><PillBottle class="h-5 w-5" /></span>
        </template>
        <form id="pending-medicine-form" @submit.prevent="submit">
            <FormField label="Suite donnée" required :error="form.errors.note || form.errors.line">
                <Textarea v-model="form.note" :rows="3" placeholder="Ex. ajouté au référentiel sous MED-0231" />
            </FormField>
        </form>
        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="close">Annuler</Button>
            <Button type="submit" form="pending-medicine-form" :disabled="form.processing || ! form.note.trim()">
                <CircleCheck class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : 'Marquer traité' }}
            </Button>
        </template>
    </Dialog>
</template>
