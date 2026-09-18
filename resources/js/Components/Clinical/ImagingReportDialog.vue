<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { CircleCheck, FileText, PenLine } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import ClinicalRichTextEditor from '@/Components/Clinical/ClinicalRichTextEditor.vue';
import FormError from '@/Components/UI/FormError.vue';

/**
 * La saisie d'un compte rendu d'imagerie — une seule, où qu'on l'ouvre.
 *
 * Elle existait en deux exemplaires : une fenêtre complète dans « Demandes
 * d'examens » (feuilles de la clinique, observations, ADR-108) et un éditeur
 * réduit dans la consultation, sans feuilles. Deux outils pour écrire la même
 * colonne finissent par produire deux comptes rendus différents pour le même
 * type d'examen. Les deux écrans ouvrent désormais ce composant.
 *
 * Le serveur reste l'unique garde : il refuse un second compte rendu sur le
 * même examen (`RecordImagingResultAction`) — un compte rendu enregistré n'est
 * jamais réécrit.
 */
const props = defineProps({
    /** `{ uuid, exam }` de la ligne d'examen, ou `null` fenêtre fermée. */
    item: { type: Object, default: null },
    orientationUuid: { type: String, default: '' },
    /** Qui et quel passage : l'en-tête que le médecin relit avant d'écrire. */
    subtitle: { type: String, default: '' },
    templates: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'saved']);

const form = useForm({ result_value: '', result_notes: '' });

watch(() => props.item?.uuid, () => {
    form.reset();
    form.clearErrors();
    pendingTemplate.value = null;
});

/**
 * Les feuilles de la clinique (ADR-108). Le médecin choisit la sienne : rien
 * n'est déduit du nom de l'examen (ADR-052).
 */
const pendingTemplate = ref(null);

const hasContent = computed(() => form.result_value
    .replace(/<[^>]*>/g, '')
    .replace(/&nbsp;| /g, ' ')
    .trim() !== '');

const chooseTemplate = (template) => {
    // Une feuille remplace tout le compte rendu : sur un champ déjà écrit,
    // on demande avant, on n'écrase jamais.
    if (hasContent.value) {
        pendingTemplate.value = template;

        return;
    }

    applyTemplate(template);
};

const applyTemplate = (template) => {
    form.result_value = template.body_html;
    pendingTemplate.value = null;
};

const close = () => {
    pendingTemplate.value = null;
    emit('close');
};

const submit = () => {
    if (!props.item) {
        return;
    }

    form.post(`/medicine/orientations/${props.orientationUuid}/imaging-requests/${props.item.uuid}/result`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('saved');
        },
    });
};
</script>

<template>
    <Dialog
        :open="item !== null"
        size="wide"
        body-class="max-h-[78vh] overflow-y-auto"
        :dismissible="false"
        :title="item ? `Compte rendu — ${item.exam}` : 'Compte rendu'"
        :description="subtitle"
        @update:open="(value) => value || close()"
    >
        <template #icon><PenLine class="h-5 w-5" /></template>

        <!-- Le compte rendu occupe les deux tiers : c'est lui que le médecin
             écrit vraiment. -->
        <form v-if="item" class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-4 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <label for="imaging_report" class="mb-1.5 block text-sm font-medium text-foreground">
                        Compte rendu<span class="ms-0.5 text-destructive">*</span>
                    </label>

                    <!-- L'en-tête, l'identité du patient et la signature ne
                         sont pas dans le canevas : l'impression les porte. -->
                    <div v-if="templates.length" class="mb-2 flex flex-wrap items-center gap-1.5">
                        <span class="text-[11px] font-semibold text-muted-foreground">Feuille :</span>
                        <Button
                            v-for="template in templates"
                            :key="template.key"
                            type="button"
                            size="sm"
                            variant="white-outline"
                            :title="template.description"
                            @click="chooseTemplate(template)"
                        >
                            <FileText class="h-3.5 w-3.5" />{{ template.label }}
                        </Button>
                    </div>

                    <ClinicalRichTextEditor
                        id="imaging_report"
                        v-model="form.result_value"
                        :max-length="5000"
                        min-height-class="min-h-[48vh]"
                        placeholder="Technique, constatations, conclusion…"
                    />
                    <FormError class="mt-1" :message="form.errors.result_value" />
                </div>

                <div>
                    <label for="imaging_notes" class="mb-1.5 block text-sm font-medium text-foreground">
                        Observations complémentaires<span class="ms-1 font-normal text-muted-foreground">· facultatif</span>
                    </label>
                    <ClinicalRichTextEditor
                        id="imaging_notes"
                        v-model="form.result_notes"
                        :max-length="5000"
                        min-height-class="min-h-[48vh]"
                        placeholder="Ce que le compte rendu ne porte pas."
                    />
                    <FormError class="mt-1" :message="form.errors.result_notes" />
                </div>
            </div>

            <p class="text-[11px] leading-4 text-muted-foreground">
                Un compte rendu enregistré n’est jamais réécrit : la demande cesse d’attendre un résultat et ne peut plus être retirée.
            </p>
        </form>

        <template #footer>
            <Button type="button" variant="white-outline" size="sm" @click="close">Annuler</Button>
            <Button type="button" size="sm" :disabled="form.processing" @click="submit">
                <CircleCheck class="h-4 w-4" />Enregistrer le compte rendu
            </Button>
        </template>
    </Dialog>

    <Dialog
        :open="pendingTemplate !== null"
        title="Remplacer le compte rendu ?"
        :description="pendingTemplate?.label ?? ''"
        :dismissible="false"
        close-label="Conserver ma saisie"
        @update:open="pendingTemplate = null"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                <FileText class="h-5 w-5" />
            </span>
        </template>

        <p class="text-xs leading-5 text-muted-foreground">
            Ce compte rendu porte déjà du texte. Insérer cette feuille le remplacera entièrement.
            Rien n’est encore enregistré : vous pouvez revenir en arrière.
        </p>

        <template #footer>
            <Button type="button" variant="outline" @click="pendingTemplate = null">Conserver ma saisie</Button>
            <Button type="button" @click="applyTemplate(pendingTemplate)">
                <FileText class="h-4 w-4" />Insérer la feuille
            </Button>
        </template>
    </Dialog>
</template>
