<script setup>
import { computed } from 'vue';
import { ClipboardList, Eye, FileText, FlaskConical, Hourglass, ListChecks, ScanLine } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import ClinicalSubsection from '@/Components/Clinical/ClinicalSubsection.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';

/**
 * ADR-204 — étape 5 : la synthèse de la consultation, écrite par la
 * sage-femme. Le logiciel ne formule aucun diagnostic : il rappelle seulement
 * ce qui a été demandé et ce qui attend encore un résultat.
 *
 * Les actes réalisés et le matériel utilisé se déclarent ici (`#acts`).
 */
const props = defineProps({
    form: { type: Object, required: true },
    readOnly: { type: Boolean, default: false },
    labRequests: { type: Array, default: null },
    imagingRequests: { type: Array, default: null },
});

const requested = computed(() => [
    ...(props.labRequests ?? []).map((request) => ({ ...request, kind: 'LAB' })),
    ...(props.imagingRequests ?? []).map((request) => ({ ...request, kind: 'IMAGING' })),
].filter((request) => request.status !== 'CANCELLED'));
const exams = computed(() => requested.value.flatMap((request) => request.items.map((item) => ({
    uuid: item.uuid,
    kind: request.kind,
    exam: item.exam,
    done: Boolean(item.resulted_at),
}))));
const pending = computed(() => exams.value.filter((exam) => ! exam.done).length);
</script>

<template>
    <div class="space-y-4">
        <fieldset class="space-y-4" :disabled="readOnly">
            <ClinicalSubsection :icon="FileText" tone="rose" title="Synthèse clinique" description="Ce qu’il faut retenir de cette consultation, en quelques lignes.">
                <Textarea v-model="form.prenatal_data.clinical_summary" :rows="4" aria-label="Synthèse clinique" placeholder="Ce qu’il faut retenir de cette consultation…" />
                <FormError v-if="form.errors['prenatal_data.clinical_summary']" class="mt-1">{{ form.errors['prenatal_data.clinical_summary'] }}</FormError>
            </ClinicalSubsection>

            <div class="grid gap-4 lg:grid-cols-2">
                <ClinicalSubsection :icon="Eye" tone="rose" title="Éléments à surveiller" description="À revoir à la prochaine consultation.">
                    <Textarea v-model="form.prenatal_data.watch_points" :rows="3" aria-label="Éléments à surveiller" placeholder="Tension, prise de poids, résultat attendu… (facultatif)" />
                    <FormError v-if="form.errors['prenatal_data.watch_points']" class="mt-1">{{ form.errors['prenatal_data.watch_points'] }}</FormError>
                </ClinicalSubsection>
                <ClinicalSubsection :icon="ListChecks" tone="rose" title="Conduite à tenir / plan" description="Ce qui est décidé pour la suite.">
                    <Textarea v-model="form.prenatal_data.plan" :rows="3" aria-label="Conduite à tenir / plan" placeholder="Conseils, traitement, prochain examen… (facultatif)" />
                    <FormError v-if="form.errors['prenatal_data.plan']" class="mt-1">{{ form.errors['prenatal_data.plan'] }}</FormError>
                </ClinicalSubsection>
            </div>
        </fieldset>

        <ClinicalSubsection :icon="ClipboardList" tone="rose" title="Examens demandés à cette consultation" description="Rappel : un résultat en attente n’empêche pas de terminer.">
            <template #aside>
                <Badge v-if="pending" tone="warning" class="gap-1 px-2 py-0.5 text-[10px]"><Hourglass class="h-3 w-3" aria-hidden="true" />{{ pending }} en attente</Badge>
            </template>
            <ul v-if="exams.length" class="flex flex-wrap gap-1.5">
                <li v-for="exam in exams" :key="exam.uuid">
                    <Badge :tone="exam.done ? 'success' : 'warning'" class="gap-1">
                        <component :is="exam.kind === 'LAB' ? FlaskConical : ScanLine" class="h-3 w-3" aria-hidden="true" />{{ exam.exam }} · {{ exam.done ? 'résultat' : 'en attente' }}
                    </Badge>
                </li>
            </ul>
            <p v-else class="text-xs text-muted-foreground">Aucun examen demandé à cette consultation.</p>
            <p v-if="pending" class="mt-2 text-[11px] text-muted-foreground">Il se lira dans le suivi de la grossesse.</p>
        </ClinicalSubsection>

        <slot name="acts" />
    </div>
</template>
