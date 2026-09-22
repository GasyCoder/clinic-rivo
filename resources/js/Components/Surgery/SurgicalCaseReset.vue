<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Archive, RotateCcw, TriangleAlert } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';

/**
 * ADR-171 — « Réinitialiser » : remet le dossier du bloc à zéro quand ce qui
 * a été saisi est faux.
 *
 * Toujours derrière une confirmation : la fenêtre dit ce qui sera retiré, exige
 * un motif et une case cochée, et ne se ferme pas au clic à côté. Le serveur
 * archive tout avant d'effacer et revérifie chaque règle — ce composant ne
 * décide de rien.
 */
const props = defineProps({
    surgicalRequest: { type: Object, required: true },
});

const open = ref(false);
const understood = ref(false);
const form = useForm({ reason: '' });

const request = computed(() => props.surgicalRequest);
const count = (value) => (Array.isArray(value) ? value.length : 0);

/** Ce qui sera retiré, pour que la personne voie la portée avant de confirmer. */
const scope = computed(() => [
    { label: 'Programmation (date, chirurgiens, salle, consignes)', present: Boolean(request.value.scheduled_at || request.value.surgeon_id || request.value.operating_room) },
    { label: `Équipe de bloc (${count(request.value.team_members)})`, present: count(request.value.team_members) > 0 },
    { label: 'Feu vert préopératoire', present: Boolean(request.value.preoperative_validated_at || request.value.preoperative_assessed_at) },
    { label: 'Entrée au bloc', present: Boolean(request.value.block_entry) },
    { label: 'Checklists de sécurité', present: count(request.value.safety_checklists) > 0 },
    { label: 'Intervention', present: Boolean(request.value.intervention) },
    { label: 'Compte rendu opératoire', present: Boolean(request.value.report) },
    { label: 'Sortie du bloc et surveillance', present: Boolean(request.value.block_exit) || count(request.value.observations) > 0 },
    { label: `Traitements, complications et notes (${count(request.value.treatment_items) + count(request.value.complications) + count(request.value.care_notes)})`, present: count(request.value.treatment_items) + count(request.value.complications) + count(request.value.care_notes) > 0 },
    { label: 'Dossier d’anesthésie (consultation, décision, conduite)', present: Boolean(request.value.anesthesia_record) },
    { label: 'Sortie de Chirurgie', present: Boolean(request.value.discharged_at) },
].filter((row) => row.present));

const canConfirm = computed(() => understood.value && form.reason.trim().length >= 3 && !form.processing);

const openDialog = () => {
    form.reset();
    form.clearErrors();
    understood.value = false;
    open.value = true;
};

const confirm = () => form.post(`/surgery/${request.value.uuid}/reset`, {
    preserveScroll: true,
    onSuccess: () => { open.value = false; },
});
</script>

<template>
    <Button type="button" variant="outline" size="sm" class="text-destructive hover:text-destructive" @click="openDialog">
        <RotateCcw class="h-4 w-4" aria-hidden="true" />Réinitialiser
    </Button>

    <Dialog
        :open="open"
        title="Réinitialiser le dossier du bloc"
        description="Tout ce qui a été saisi sur ce dossier est retiré et la demande repart « À programmer ». L’intervention demandée, son origine et le demandeur sont gardés."
        :dismissible="false"
        @update:open="(value) => { if (!value && !form.processing) open = false; }"
    >
        <template #icon><TriangleAlert class="h-5 w-5 text-destructive" aria-hidden="true" /></template>

        <div class="space-y-4">
            <div v-if="scope.length" class="rounded-lg border border-destructive/30 bg-destructive/5 p-3">
                <p class="text-xs font-semibold text-destructive">Sera retiré du dossier</p>
                <ul class="mt-1.5 list-disc space-y-0.5 ps-5 text-sm text-foreground">
                    <li v-for="row in scope" :key="row.label">{{ row.label }}</li>
                </ul>
            </div>

            <p class="flex items-start gap-2 text-xs text-muted-foreground">
                <Archive class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                Rien n’est perdu : ces saisies sont archivées avec votre nom, l’heure et le motif. Le matériel déjà servi par la Pharmacie ne se défait pas ici ; une demande de matériel pas encore servie est annulée.
            </p>

            <FormField label="Motif" required :error="form.errors.reason">
                <Textarea v-model="form.reason" :rows="3" placeholder="Mauvais patient, saisie en double, intervention démarrée par erreur…" />
            </FormField>

            <label class="flex cursor-pointer items-start gap-2.5 text-sm text-foreground">
                <Checkbox v-model="understood" class="mt-0.5" />
                <span>Je confirme la réinitialisation : le dossier du bloc repart à zéro.</span>
            </label>

            <p v-if="form.errors.reset" class="text-sm text-destructive" role="alert">{{ form.errors.reset }}</p>
        </div>

        <template #footer>
            <Button variant="white-outline" type="button" :disabled="form.processing" @click="open = false">Garder le dossier</Button>
            <Button variant="destructive" type="button" :disabled="!canConfirm" @click="confirm">
                <RotateCcw class="h-4 w-4" aria-hidden="true" />{{ form.processing ? 'Réinitialisation…' : 'Réinitialiser' }}
            </Button>
        </template>
    </Dialog>
</template>
