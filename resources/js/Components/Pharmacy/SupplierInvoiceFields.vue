<script setup>
import { computed, ref } from 'vue';
import { CalendarDays, FileText, Paperclip, X } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { cn } from '@/lib/cn';
import { formatDate } from '@/utilities/date';
import { formatMoney } from '@/utilities/pharmacyStatus';

/**
 * ADR-171 — les informations d'une facture fournisseur, telles qu'elles sont
 * imprimées sur son papier : numéro, montant, éventuelle échéance.
 *
 * La date est celle du jour et ne se saisit pas ; elle ne s'ouvre que si la
 * facture porte une autre date. L'échéance se choisit en un clic (30, 60
 * jours) plutôt qu'en tapant un calendrier.
 */
const props = defineProps({
    // Objet réactif porté par le formulaire appelant : { invoice_number,
    // invoice_date, due_date, total_amount, notes, attachment }
    form: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    // Clé du champ dans les erreurs serveur (« invoice.invoice_number »).
    prefix: { type: String, default: '' },
    // Ce que la réception laisse attendre, quand le coût est visible.
    proposedTotal: { type: [String, Number], default: null },
});

const today = new Date().toISOString().slice(0, 10);
const customDate = ref(props.form.invoice_date !== today);
const error = (field) => props.errors[props.prefix ? `${props.prefix}.${field}` : field];

const addDays = (days) => {
    const date = new Date(`${props.form.invoice_date || today}T00:00:00`);
    date.setDate(date.getDate() + days);

    return date.toISOString().slice(0, 10);
};
const terms = [
    { label: 'À réception', value: () => '' },
    { label: '15 jours', value: () => addDays(15) },
    { label: '30 jours', value: () => addDays(30) },
    { label: '60 jours', value: () => addDays(60) },
];
const customDue = ref(false);
const activeTerm = computed(() => terms.findIndex((term) => term.value() === (props.form.due_date ?? '')));

const fileName = computed(() => props.form.attachment?.name ?? '');
const pickFile = (event) => { props.form.attachment = event.target.files?.[0] ?? null; };
const chip = (active) => cn(
    'rounded-full border px-3 py-1 text-xs font-semibold transition',
    active ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground hover:bg-muted',
);
</script>

<template>
    <div class="grid gap-4 md:grid-cols-2">
        <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-foreground">Numéro de la facture <span class="text-red-500">*</span></span>
            <Input v-model="form.invoice_number" maxlength="100" placeholder="Ex. FA-2026-0142" autocomplete="off" />
            <span v-if="error('invoice_number')" class="mt-1 block text-xs text-destructive">{{ error('invoice_number') }}</span>
        </label>

        <label class="block">
            <span class="mb-1.5 block text-sm font-semibold text-foreground">Montant total <span class="text-red-500">*</span></span>
            <span class="relative block">
                <Input v-model="form.total_amount" type="number" min="0.01" step="0.01" class="pe-14 text-end font-semibold tabular-nums" placeholder="0" />
                <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-xs text-muted-foreground">MGA</span>
            </span>
            <button
                v-if="proposedTotal && Number(form.total_amount) !== Number(proposedTotal)"
                type="button"
                class="mt-1 text-xs font-semibold text-primary hover:underline"
                @click="form.total_amount = String(proposedTotal)"
            >Reprendre {{ formatMoney(proposedTotal) }} — ce qui a été reçu</button>
            <span v-if="error('total_amount')" class="mt-1 block text-xs text-destructive">{{ error('total_amount') }}</span>
        </label>

        <div class="block">
            <span class="mb-1.5 block text-sm font-semibold text-foreground">Date de la facture</span>
            <div v-if="!customDate" class="flex h-10 items-center justify-between gap-2 rounded-lg border border-border bg-muted/40 px-3 text-sm">
                <span class="inline-flex items-center gap-2 font-semibold text-foreground"><CalendarDays class="h-4 w-4 text-muted-foreground" />{{ formatDate(form.invoice_date) }}</span>
                <button type="button" class="text-xs font-semibold text-primary hover:underline" @click="customDate = true">Autre date</button>
            </div>
            <div v-else class="flex items-center gap-2">
                <DatePicker v-model="form.invoice_date" :max="today" />
                <Button type="button" size="icon" variant="ghost" aria-label="Revenir à aujourd’hui" @click="form.invoice_date = today; customDate = false"><X class="h-4 w-4" /></Button>
            </div>
            <span v-if="error('invoice_date')" class="mt-1 block text-xs text-destructive">{{ error('invoice_date') }}</span>
        </div>

        <div class="block">
            <span class="mb-1.5 block text-sm font-semibold text-foreground">Échéance <span class="font-normal text-muted-foreground">(facultatif)</span></span>
            <div v-if="!customDue" class="flex flex-wrap items-center gap-1.5">
                <button
                    v-for="(term, index) in terms"
                    :key="term.label"
                    type="button"
                    :class="chip(activeTerm === index)"
                    @click="form.due_date = term.value()"
                >{{ term.label }}</button>
                <button type="button" :class="chip(false)" @click="customDue = true">Autre date</button>
            </div>
            <div v-else class="flex items-center gap-2">
                <DatePicker v-model="form.due_date" :min="form.invoice_date" />
                <Button type="button" size="icon" variant="ghost" aria-label="Revenir aux délais habituels" @click="form.due_date = ''; customDue = false"><X class="h-4 w-4" /></Button>
            </div>
            <span v-if="form.due_date && !customDue" class="mt-1 block text-xs text-muted-foreground">À payer avant le {{ formatDate(form.due_date) }}</span>
            <span v-if="error('due_date')" class="mt-1 block text-xs text-destructive">{{ error('due_date') }}</span>
        </div>

        <label class="block md:col-span-2">
            <span class="mb-1.5 block text-sm font-semibold text-foreground">Remarque <span class="font-normal text-muted-foreground">(facultatif)</span></span>
            <Textarea v-model="form.notes" rows="2" maxlength="2000" placeholder="Ex. remise de 5 % appliquée" />
        </label>

        <div class="md:col-span-2">
            <label :class="cn('flex cursor-pointer items-center gap-3 rounded-xl border border-dashed border-border px-4 py-3 transition hover:border-primary/50', fileName && 'border-solid bg-muted/30')">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><component :is="fileName ? FileText : Paperclip" class="h-5 w-5" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-foreground">{{ fileName || 'Joindre la facture (facultatif)' }}</span>
                    <span class="block text-xs text-muted-foreground">PDF, photo ou Excel — 10 Mo au maximum</span>
                </span>
                <span class="shrink-0 text-xs font-semibold text-primary">{{ fileName ? 'Changer' : 'Choisir' }}</span>
                <input type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.xlsx" @change="pickFile">
            </label>
            <span v-if="error('attachment')" class="mt-1 block text-xs text-destructive">{{ error('attachment') }}</span>
        </div>
    </div>
</template>
