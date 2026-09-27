<script setup>
import { computed } from 'vue';
import { Check, History, MessageSquareText, PenLine, ScrollText } from 'lucide-vue-next';
import ClinicalSegmentedChoice from '@/Components/Clinical/ClinicalSegmentedChoice.vue';
import ClinicalSubsection from '@/Components/Clinical/ClinicalSubsection.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import { withIcons } from '@/utilities/maternityFieldIcons';
import { cn } from '@/lib/cn';

/**
 * ADR-204 — étape 2 : pourquoi la patiente vient, et ce qu'elle rapporte
 * depuis la dernière consultation.
 *
 * Ces choix **structurent** la consultation ; ils ne produisent aucun
 * diagnostic. Rien n'est obligatoire. Les libellés viennent du serveur
 * (`MaternityEncounterFields`) ; seule l'icône de chaque choix est posée ici.
 */
const props = defineProps({
    form: { type: Object, required: true },
    options: { type: Object, required: true },
    readOnly: { type: Boolean, default: false },
});

const visitReasons = computed(() => withIcons('visit_reasons', props.options.visit_reasons));
const reportedOptions = computed(() => withIcons('reported_since_last', props.options.reported_since_last));

const reported = () => props.form.prenatal_data.reported_since_last ?? [];
const reportedCount = computed(() => reported().length);
const toggleReported = (value) => {
    if (props.readOnly) return;

    const current = new Set(reported());
    current.has(value) ? current.delete(value) : current.add(value);
    // L'ordre du serveur, jamais celui des clics : le dossier se relit toujours pareil.
    props.form.prenatal_data.reported_since_last = props.options.reported_since_last
        .map((option) => option.value)
        .filter((option) => current.has(option));
};
</script>

<template>
    <fieldset class="space-y-4" :disabled="readOnly">
        <ClinicalSubsection :icon="MessageSquareText" tone="rose" title="Motif de consultation" description="Pourquoi la patiente vient aujourd’hui.">
            <div class="space-y-4">
                <ClinicalSegmentedChoice
                    v-model="form.prenatal_data.visit_reason"
                    name="prenatal-visit-reason"
                    :options="visitReasons"
                    :disabled="readOnly"
                />
                <FormField label="Précision du motif" hint="facultatif" :error="form.errors['prenatal_data.visit_reason_details']">
                    <IconInput v-model="form.prenatal_data.visit_reason_details" :icon="PenLine" maxlength="1000" placeholder="Ex. : contrôle du bilan, douleurs pelviennes…" />
                </FormField>
            </div>
        </ClinicalSubsection>

        <ClinicalSubsection :icon="History" tone="rose" title="Depuis la dernière consultation" description="Ce que la patiente rapporte — à cocher, sans conclusion.">
            <template #aside>
                <span v-if="reportedCount" class="rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-700 dark:bg-rose-950/60 dark:text-rose-300">
                    {{ reportedCount }} coché{{ reportedCount > 1 ? 's' : '' }}
                </span>
            </template>
            <div class="space-y-4">
                <!-- Des cartes à cocher : l'icône et l'état se lisent d'un coup d'œil, la case reste pour le clavier. -->
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4" role="group" aria-label="Rapporté depuis la dernière consultation">
                    <button
                        v-for="option in reportedOptions"
                        :key="option.value"
                        type="button"
                        role="checkbox"
                        :aria-checked="reported().includes(option.value)"
                        :disabled="readOnly"
                        :class="cn(
                            'flex min-h-11 items-center gap-2.5 rounded-lg border px-3 py-2 text-start text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40 disabled:cursor-not-allowed disabled:opacity-60',
                            reported().includes(option.value)
                                ? 'border-rose-300 bg-rose-50 text-foreground dark:border-rose-800 dark:bg-rose-950/30'
                                : 'border-border bg-card text-foreground hover:bg-accent',
                        )"
                        @click="toggleReported(option.value)"
                    >
                        <span
                            :class="cn(
                                'grid h-8 w-8 shrink-0 place-items-center rounded-md',
                                reported().includes(option.value) ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300' : 'bg-muted text-muted-foreground',
                            )"
                        >
                            <component :is="option.icon" v-if="option.icon" class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <span class="min-w-0 flex-1 leading-5">{{ option.label }}</span>
                        <span
                            :class="cn(
                                'grid h-4 w-4 shrink-0 place-items-center rounded border',
                                reported().includes(option.value) ? 'border-rose-600 bg-rose-600 text-white' : 'border-input bg-card',
                            )"
                            aria-hidden="true"
                        >
                            <Check v-if="reported().includes(option.value)" class="h-3 w-3" />
                        </span>
                    </button>
                </div>
                <FormField as="div" :icon="PenLine" label="Notes / évolution depuis la dernière consultation" :error="form.errors['prenatal_data.interval_notes']">
                    <Textarea v-model="form.prenatal_data.interval_notes" :rows="3" placeholder="Ce qui s’est passé, ce que la patiente décrit…" />
                </FormField>
            </div>
        </ClinicalSubsection>

        <ClinicalSubsection :icon="ScrollText" tone="rose" title="Contexte obstétrical" description="Antécédents et contexte utile pour la suite du suivi.">
            <Textarea v-model="form.obstetric_context" :rows="3" aria-label="Contexte obstétrical" placeholder="Grossesses précédentes, césarienne, antécédents utiles… (facultatif)" />
            <FormError v-if="form.errors.obstetric_context" class="mt-1">{{ form.errors.obstetric_context }}</FormError>
        </ClinicalSubsection>
    </fieldset>
</template>
