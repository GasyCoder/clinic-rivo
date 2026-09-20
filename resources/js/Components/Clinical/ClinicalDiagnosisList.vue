<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormError from '@/Components/UI/FormError.vue';
import Input from '@/Components/Shadcn/Input.vue';
import { Ban, Pencil, TriangleAlert } from 'lucide-vue-next';

/**
 * Les diagnostics enregistrés, avec leur correction et leur retrait.
 *
 * L'ADR-081 place ces deux gestes « dans la carte Diagnostic », et l'ADR-089
 * a déplacé cette carte à « Décision & clôture ». Les endpoints existaient,
 * l'écran ne les appelait plus : une faute de frappe restait donc dans le
 * dossier sans aucun moyen de la rectifier.
 *
 * Rien n'est réécrit ni effacé (ADR-035) : corriger enregistre une nouvelle
 * ligne et annule l'ancienne, retirer conserve la ligne d'origine avec son
 * auteur et sa date. Les deux restent réservés à l'auteur de la saisie —
 * `can_edit` / `can_cancel` viennent du serveur, qui revérifie toujours.
 */
const props = defineProps({
    orientationUuid: { type: String, required: true },
    diagnoses: { type: Array, default: () => [] },
    /** L'étape où revenir après l'écriture — celle que le médecin a sous les yeux. */
    returnStep: { type: String, default: null },
    /** Vrai pour une vraie consultation : retirer le dernier diagnostic bloque la clôture (CDC §33.1). */
    requiredForClosure: { type: Boolean, default: false },
});

const editing = ref(null);
const editForm = useForm({ diagnosis_id: null, type: 'FINAL', description: '' });

const openEdit = (diagnosis) => {
    editing.value = diagnosis;
    editForm.clearErrors();
    editForm.diagnosis_id = diagnosis.id;
    editForm.type = diagnosis.type;
    editForm.description = diagnosis.description;
};

const closeEdit = () => {
    editing.value = null;
    editForm.reset();
    editForm.clearErrors();
};

const submitEdit = () => editForm
    .transform((data) => ({ ...data, return_step: props.returnStep }))
    .put(`/medicine/orientations/${props.orientationUuid}/diagnoses`, {
        preserveScroll: true,
        onSuccess: closeEdit,
    });

const removing = ref(null);
const removeForm = useForm({ diagnosis_id: null });

const openRemove = (diagnosis) => {
    removing.value = diagnosis;
    removeForm.clearErrors();
    removeForm.diagnosis_id = diagnosis.id;
};

const closeRemove = () => {
    removing.value = null;
    removeForm.reset();
    removeForm.clearErrors();
};

const submitRemove = () => removeForm
    .transform((data) => ({ ...data, return_step: props.returnStep }))
    .post(`/medicine/orientations/${props.orientationUuid}/diagnoses/cancel`, {
        preserveScroll: true,
        onSuccess: closeRemove,
    });
</script>

<template>
    <div>
    <ul class="space-y-1.5">
        <li
            v-for="diagnosis in diagnoses"
            :key="diagnosis.id"
            class="flex flex-wrap items-start justify-between gap-2 rounded-md border border-border bg-card px-3 py-2"
        >
            <span class="min-w-0 flex-1">
                <span class="block text-xs font-bold text-foreground">
                    {{ diagnosis.description }}
                    <!-- Un badge « Hypothèse » n'apparaît que sur une ligne qui
                         en est réellement une : les nouvelles saisies n'en
                         produisent plus (ADR-082). -->
                    <span
                        v-if="diagnosis.type === 'HYPOTHESIS'"
                        class="ms-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                    >Hypothèse</span>
                </span>
                <span class="mt-0.5 block truncate text-[10px] text-muted-foreground">
                    <span v-if="diagnosis.code" class="font-mono">{{ diagnosis.code }} · </span>{{ diagnosis.recorded_by }} · {{ new Date(diagnosis.recorded_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) }}
                </span>
            </span>

            <!-- Seul l'auteur corrige ou retire (ADR-035). Un autre compte ne
                 voit aucun bouton, plutôt qu'un bouton qui refuserait. -->
            <span v-if="diagnosis.can_edit || diagnosis.can_cancel" class="flex shrink-0 items-center gap-1.5">
                <Button v-if="diagnosis.can_edit" type="button" size="sm" variant="white-outline" @click="openEdit(diagnosis)">
                    <Pencil class="h-3.5 w-3.5" />Corriger
                </Button>
                <Button v-if="diagnosis.can_cancel" type="button" size="sm" variant="white-outline" @click="openRemove(diagnosis)">
                    <Ban class="h-3.5 w-3.5" />Retirer
                </Button>
            </span>
        </li>
    </ul>

    <!-- Corriger : une nouvelle ligne remplace l'ancienne, qui reste visible
         dans « Contexte clinique » (ADR-035, ADR-081). -->
    <Dialog
        :open="editing !== null"
        title="Corriger le diagnostic"
        description="La version précédente est conservée : elle reste lisible dans le contexte clinique, avec son auteur et sa date."
        :dismissible="false"
        close-label="Annuler"
        @update:open="closeEdit"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                <Pencil class="h-5 w-5" />
            </span>
        </template>

        <label class="block text-xs font-semibold text-foreground" for="diagnosis_correction">Diagnostic</label>
        <Input
            id="diagnosis_correction"
            v-model="editForm.description"
            class="mt-1.5"
            maxlength="5000"
            placeholder="Libellé du diagnostic"
        />
        <FormError class="mt-1" :message="editForm.errors.description" />
        <FormError class="mt-1" :message="editForm.errors.diagnosis_id" />

        <template #footer>
            <Button type="button" variant="outline" :disabled="editForm.processing" @click="closeEdit">Annuler</Button>
            <Button type="button" :disabled="editForm.processing || !editForm.description.trim()" @click="submitEdit">
                <Pencil class="h-4 w-4" />Enregistrer la correction
            </Button>
        </template>
    </Dialog>

    <!-- Retirer : la ligne d'origine n'est jamais supprimée (ADR-010, ADR-035).
         Elle quitte la conclusion active et reste tracée. -->
    <Dialog
        :open="removing !== null"
        title="Retirer ce diagnostic"
        :description="removing?.description ?? ''"
        :dismissible="false"
        close-label="Annuler"
        @update:open="closeRemove"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                <TriangleAlert class="h-5 w-5" />
            </span>
        </template>

        <p class="text-xs leading-5 text-muted-foreground">
            Le diagnostic quitte la conclusion de ce passage, mais n’est pas supprimé : il reste lisible dans
            « Contexte clinique », barré, avec son auteur, sa date et la vôtre. Vous pourrez en enregistrer un autre juste après.
        </p>
        <p
            v-if="requiredForClosure && diagnoses.length === 1"
            class="mt-2 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
        >
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            C’est le seul diagnostic de ce passage : sans diagnostic, la consultation ne peut pas être clôturée.
        </p>
        <FormError class="mt-2" :message="removeForm.errors.diagnosis_id" />

        <template #footer>
            <Button type="button" variant="outline" :disabled="removeForm.processing" @click="closeRemove">Conserver</Button>
            <Button type="button" variant="destructive" :disabled="removeForm.processing" @click="submitRemove">
                <Ban class="h-4 w-4" />Retirer le diagnostic
            </Button>
        </template>
    </Dialog>
    </div>
</template>
