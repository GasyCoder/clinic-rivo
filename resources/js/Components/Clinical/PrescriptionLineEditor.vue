<script setup>
import { computed } from 'vue';
import FormError from '@/Components/UI/FormError.vue';
import Input from '@/Components/Shadcn/Input.vue';

/**
 * Composing a posology that reads like an instruction, not like three
 * numbers.
 *
 * The columns behind it are free text, so nothing in the database forced
 * "Dose : 500 / Fréquence : 3 / Durée : —". The form did, by offering bare
 * inputs. Here a value is always paired with its unit, and what gets stored
 * is the composed sentence — "500 mg", "3 fois/jour", "7 jours" — so whoever
 * reads the prescription next never has to guess the unit.
 */
const props = defineProps({
    line: { type: Object, required: true },
    routes: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
    /** Unit suggested by the medicine's own form (gélule, ampoule…). */
    quantityUnit: { type: String, default: 'unité(s)' },
    errorFor: { type: Function, default: () => null },
});

const emit = defineEmits(['update']);

const DOSE_UNITS = ['mg', 'g', 'ml', 'UI', 'µg', 'comprimé(s)', 'gélule(s)', 'goutte(s)', 'bouffée(s)', 'cuillère(s)'];
const FREQUENCIES = [
    '1 fois/jour', '2 fois/jour', '3 fois/jour', '4 fois/jour',
    'matin et soir', 'matin, midi et soir', 'toutes les 4 h', 'toutes les 6 h',
    'toutes les 8 h', 'toutes les 12 h', 'au coucher', 'si besoin',
];
const DURATION_UNITS = ['jours', 'semaines', 'mois', 'prise unique'];

const patch = (field, value) => emit('update', { field, value });

/**
 * Dose and duration are kept as one readable string in the existing columns.
 * Splitting them into value + unit columns would have meant a migration and
 * two more fields for something the text already expresses exactly.
 */
const setDose = (value, unit) => {
    const amount = String(value ?? '').trim();
    const chosen = unit ?? props.line._dose_unit ?? 'mg';
    emit('update', { field: '_dose_amount', value: amount });
    emit('update', { field: '_dose_unit', value: chosen });
    emit('update', { field: 'dosage', value: amount === '' ? '' : `${amount} ${chosen}` });
};

const setDuration = (value, unit) => {
    const amount = String(value ?? '').trim();
    const chosen = unit ?? props.line._duration_unit ?? 'jours';
    emit('update', { field: '_duration_amount', value: amount });
    emit('update', { field: '_duration_unit', value: chosen });

    if (chosen === 'prise unique') {
        emit('update', { field: 'duration', value: 'prise unique' });

        return;
    }

    emit('update', { field: 'duration', value: amount === '' ? '' : `${amount} ${chosen}` });
};

/** What the line will read as once saved — shown live, so no surprise. */
const preview = computed(() => [
    props.line.dosage,
    props.routes.find((r) => r.value === props.line.route)?.short_label,
    props.line.frequency,
    props.line.duration,
].filter(Boolean).join(' · '));
</script>

<template>
    <div class="space-y-3">
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-[11px] font-bold text-foreground">Dose <span class="text-destructive">*</span></label>
                <div class="flex gap-2">
                    <Input
                        :model-value="line._dose_amount ?? ''"
                        :disabled="disabled"
                        inputmode="decimal"
                        maxlength="20"
                        placeholder="500"
                        aria-label="Quantité de la dose"
                        class="min-w-0 flex-1"
                        @update:model-value="setDose($event, null)"
                    />
                    <select
                        :value="line._dose_unit ?? 'mg'"
                        :disabled="disabled"
                        aria-label="Unité de la dose"
                        class="h-9 w-28 shrink-0 rounded-lg border border-input bg-card px-2 text-sm text-foreground shadow-sm outline-none transition-colors focus-visible:border-primary/60 focus-visible:ring-2 focus-visible:ring-ring/25 disabled:cursor-not-allowed disabled:opacity-50"
                        @change="setDose(line._dose_amount, $event.target.value)"
                    >
                        <option v-for="unit in DOSE_UNITS" :key="unit" :value="unit">{{ unit }}</option>
                    </select>
                </div>
                <FormError :message="errorFor('dosage')" />
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-bold text-foreground">Voie</label>
                <select
                    :value="line.route ?? ''"
                    :disabled="disabled"
                    aria-label="Voie d’administration"
                    class="h-9 w-full rounded-lg border border-input bg-card px-2 text-sm text-foreground shadow-sm outline-none transition-colors focus-visible:border-primary/60 focus-visible:ring-2 focus-visible:ring-ring/25 disabled:cursor-not-allowed disabled:opacity-50"
                    @change="patch('route', $event.target.value || null)"
                >
                    <option value="">Non précisée</option>
                    <option v-for="route in routes" :key="route.value" :value="route.value">{{ route.label }}</option>
                </select>
                <FormError :message="errorFor('route')" />
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-[11px] font-bold text-foreground">Fréquence <span class="text-destructive">*</span></label>
                <!-- Liste ouverte : les rythmes courants en un clic, la saisie
                     libre reste possible pour le reste. -->
                <Input
                    :model-value="line.frequency"
                    :disabled="disabled"
                    maxlength="255"
                    list="prescription-frequencies"
                    placeholder="3 fois/jour"
                    aria-label="Fréquence de prise"
                    @update:model-value="patch('frequency', $event)"
                />
                <datalist id="prescription-frequencies">
                    <option v-for="frequency in FREQUENCIES" :key="frequency" :value="frequency" />
                </datalist>
                <FormError :message="errorFor('frequency')" />
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-bold text-foreground">Durée</label>
                <div class="flex gap-2">
                    <Input
                        :model-value="line._duration_amount ?? ''"
                        :disabled="disabled || line._duration_unit === 'prise unique'"
                        inputmode="numeric"
                        maxlength="10"
                        placeholder="7"
                        aria-label="Durée du traitement"
                        class="min-w-0 flex-1"
                        @update:model-value="setDuration($event, null)"
                    />
                    <select
                        :value="line._duration_unit ?? 'jours'"
                        :disabled="disabled"
                        aria-label="Unité de durée"
                        class="h-9 w-32 shrink-0 rounded-lg border border-input bg-card px-2 text-sm text-foreground shadow-sm outline-none transition-colors focus-visible:border-primary/60 focus-visible:ring-2 focus-visible:ring-ring/25 disabled:cursor-not-allowed disabled:opacity-50"
                        @change="setDuration(line._duration_amount, $event.target.value)"
                    >
                        <option v-for="unit in DURATION_UNITS" :key="unit" :value="unit">{{ unit }}</option>
                    </select>
                </div>
                <FormError :message="errorFor('duration')" />
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-[11px] font-bold text-foreground">Quantité totale <span class="text-destructive">*</span></label>
                <div class="flex items-center gap-2">
                    <Input
                        :model-value="line.quantity"
                        :disabled="disabled"
                        inputmode="numeric"
                        aria-label="Quantité totale à délivrer"
                        class="min-w-0 flex-1"
                        @update:model-value="patch('quantity', $event)"
                    />
                    <span class="shrink-0 text-[11px] text-muted-foreground">{{ quantityUnit }}</span>
                </div>
                <FormError :message="errorFor('quantity')" />
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-bold text-foreground">Instructions <span class="font-normal text-muted-foreground">· facultatif</span></label>
                <Input
                    :model-value="line.instructions"
                    :disabled="disabled"
                    maxlength="1000"
                    placeholder="Après les repas…"
                    aria-label="Instructions de prise"
                    @update:model-value="patch('instructions', $event)"
                />
                <FormError :message="errorFor('instructions')" />
            </div>
        </div>

        <!-- Ce que la ligne dira une fois enregistrée : le médecin voit le
             résultat avant de valider, jamais une suite de nombres. -->
        <p v-if="preview" class="rounded-lg border border-border bg-muted/50 px-2.5 py-1.5 text-[11px] text-muted-foreground">
            <span class="font-semibold">Se lira :</span> {{ preview }}
        </p>
    </div>
</template>
