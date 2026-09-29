<script setup>
import { computed } from 'vue';
import { Archive, Coins, Gift, Tag } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import ClinicalSaveStatus from '@/Components/Clinical/ClinicalSaveStatus.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import ShadSelect from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { useSectionAutosave } from '@/composables/useSectionAutosave';
import { cn } from '@/lib/cn';
import { currencyLabel } from '@/utilities/money';

/**
 * ADR-221 — un avantage ou une prime déjà déclaré : corrigé sur place,
 * enregistré tout seul. Le retirer demande un motif (il reste dans l'historique).
 */
const props = defineProps({
    benefit: { type: Object, required: true },
    typeOptions: { type: Array, required: true },
    frequencies: { type: Array, required: true },
    url: { type: String, required: true },
    canEdit: { type: Boolean, default: false },
});
defineEmits(['retire']);

const { form, state, savedAt, retry } = useSectionAutosave(`benefit:${props.benefit.uuid}`, {
    benefit_type_uuid: props.benefit.benefit_type_uuid ?? '',
    amount: props.benefit.amount ?? '',
    reason: props.benefit.reason ?? '',
    frequency: props.benefit.frequency ?? 'MONTHLY',
    starts_on: props.benefit.starts_on ?? '',
    ends_on: props.benefit.ends_on ?? '',
}, () => props.url, {
    canEdit: () => props.canEdit,
    ready: () => form.benefit_type_uuid !== '' && String(form.reason ?? '').trim().length >= 3 && form.starts_on !== '',
});

const monthly = computed(() => form.frequency === 'MONTHLY');
const types = computed(() => props.typeOptions.map((item) => ({ value: item.uuid, label: item.available ? item.label : `${item.label} — archivé`, disabled: ! item.available && item.uuid !== props.benefit.benefit_type_uuid })));
</script>

<template>
    <article :class="cn('rounded-xl border bg-card p-4 shadow-sm', benefit.current ? 'border-border' : 'border-dashed border-border bg-muted/30')">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <p class="flex items-center gap-2 text-sm font-semibold text-foreground">
                <span class="grid h-8 w-8 place-items-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300"><Gift class="h-4 w-4" /></span>
                {{ benefit.type }}
                <span v-if="! benefit.current" class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">Pas en cours</span>
            </p>
            <div class="flex items-center gap-2">
                <p v-if="state === 'incomplete'" class="text-xs font-medium text-amber-600 dark:text-amber-400">Type, motif et début sont exigés</p>
                <ClinicalSaveStatus v-else :saving="state === 'saving'" :saved-at="savedAt" :dirty="state === 'dirty'" :failed="state === 'failed'" retryable @retry="retry" />
                <Button v-if="canEdit" type="button" size="sm" icon variant="ghost" class="hover:text-destructive" :title="`Retirer ${benefit.type}`" :aria-label="`Retirer ${benefit.type}`" @click="$emit('retire', benefit)">
                    <Archive class="h-4 w-4" />
                </Button>
            </div>
        </div>
        <fieldset :disabled="! canEdit" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <FormField as="div" label="Type" :error="form.errors.benefit_type_uuid">
                <ShadSelect v-model="form.benefit_type_uuid" :options="types" :icon="Tag" class="w-full" aria-label="Type d’avantage" :disabled="! canEdit" />
            </FormField>
            <FormField label="Montant" hint="(facultatif en nature)" :error="form.errors.amount">
                <div class="relative">
                    <IconInput v-model="form.amount" :icon="Coins" inputmode="decimal" class="pe-12 tabular-nums" placeholder="Ex. 50 000" />
                    <span class="pointer-events-none absolute inset-y-0 end-0 grid place-items-center pe-3 text-xs font-medium text-muted-foreground">{{ currencyLabel() }}</span>
                </div>
            </FormField>
            <FormField as="div" label="Fréquence" :error="form.errors.frequency">
                <div class="inline-flex w-full rounded-md bg-muted p-0.5" role="radiogroup" aria-label="Fréquence">
                    <button
                        v-for="frequency in frequencies"
                        :key="frequency.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.frequency === frequency.value"
                        :class="cn('flex-1 rounded px-2 py-1.5 text-xs font-semibold transition-colors', form.frequency === frequency.value ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')"
                        @click="form.frequency = frequency.value"
                    >{{ frequency.label }}</button>
                </div>
            </FormField>
            <div class="grid grid-cols-2 gap-2">
                <FormField as="div" :label="monthly ? 'Début' : 'Date'" :error="form.errors.starts_on">
                    <DatePicker v-model="form.starts_on" :aria-label="monthly ? 'Début' : 'Date'" :disabled="! canEdit" />
                </FormField>
                <FormField v-if="monthly" as="div" label="Fin" hint="(facultatif)" :error="form.errors.ends_on">
                    <DatePicker v-model="form.ends_on" aria-label="Fin" :min="form.starts_on || undefined" :disabled="! canEdit" />
                </FormField>
            </div>
            <FormField label="Motif" class="sm:col-span-2 xl:col-span-4" :error="form.errors.reason">
                <Textarea v-model="form.reason" :rows="2" maxlength="1000" placeholder="Pourquoi cet avantage est accordé" />
            </FormField>
        </fieldset>
        <p v-if="benefit.created_by" class="mt-2 text-[11px] text-muted-foreground">Déclaré par {{ benefit.created_by }}</p>
    </article>
</template>
