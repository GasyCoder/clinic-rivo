<script setup>
import { computed, watch } from 'vue';
import { Calculator, RotateCcw } from 'lucide-vue-next';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import {
    composeAmount,
    DOSE_UNITS,
    DURATION_UNITS,
    FREQUENCIES,
    isUndosedForm,
    quantityBasis,
    suggestedQuantity,
} from '@/utilities/posology';

/**
 * Composing a posology that reads like an instruction, not like three
 * numbers.
 *
 * The columns behind it are free text, so nothing in the database forced
 * "Dose : 500 / Fréquence : 3 / Durée : —". The form did, by offering bare
 * inputs. Here a value is always paired with its unit, and what gets stored
 * is the composed sentence — "500 mg", "3 fois/jour", "7 jours" — so whoever
 * reads the prescription next never has to guess the unit.
 *
 * ADR-099 — libellés par `FormField`, listes par `Select` : les trois
 * `<select>` natifs habillés à la main avaient déjà divergé des champs
 * voisins (hauteur, chevron, anneau de focus) pour la même rangée.
 */
const props = defineProps({
    line: { type: Object, required: true },
    routes: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
    /** Unit suggested by the medicine's own form (gélule, ampoule…). */
    quantityUnit: { type: String, default: 'unité(s)' },
    /**
     * La forme pharmaceutique du référentiel (ADR-036). Elle décide si la
     * dose a un sens : une compresse stérile ne se dose pas, on en utilise
     * un nombre. Jamais déduite d'un libellé (ADR-052).
     */
    medicineForm: { type: String, default: null },
    errorFor: { type: Function, default: () => null },
});

const emit = defineEmits(['update']);


const asOptions = (values) => values.map((value) => ({ value, label: value }));
const doseUnitOptions = asOptions(DOSE_UNITS);
const durationUnitOptions = asOptions(DURATION_UNITS);

/**
 * « Non précisée » reste une entrée de la liste, jamais une absence : la
 * voie est facultative (ADR-083), et un champ vide sans intitulé se lirait
 * comme un oubli plutôt que comme un choix.
 */
const routeOptions = computed(() => [
    { value: '', label: 'Non précisée' },
    ...props.routes.map((route) => ({ value: route.value, label: route.label })),
]);

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
    emit('update', { field: 'dosage', value: composeAmount(amount, chosen) });
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

    emit('update', { field: 'duration', value: composeAmount(amount, chosen) });
};

/**
 * ADR-110 — la dose n'est pas demandée quand elle n'existe pas.
 *
 * « Compresses stériles · Paquet de 10 » réclamait « 500 mg » : un champ
 * obligatoire qu'on ne pouvait pas remplir honnêtement. Le serveur applique
 * la même règle ; l'écran ne fait que la refléter.
 */
const dosed = computed(() => !isUndosedForm(props.medicineForm));

/**
 * La quantité que la posologie implique — suggérée, jamais imposée.
 *
 * Elle est posée tant que le médecin n'a pas saisi la sienne ; dès qu'il
 * corrige le champ, sa valeur est une décision et n'est plus jamais réécrite
 * (même règle que le matériel suggéré par un acte de soins, ADR-072).
 */
const posologyInput = computed(() => ({
    frequency: props.line.frequency,
    durationAmount: props.line._duration_amount,
    durationUnit: props.line._duration_unit ?? 'jours',
}));

const suggestion = computed(() => suggestedQuantity(posologyInput.value));
const basis = computed(() => quantityBasis(posologyInput.value));

watch(suggestion, (value) => {
    if (value === null || props.line._quantity_touched) {
        return;
    }

    if (Number(props.line.quantity) !== value) {
        emit('update', { field: 'quantity', value });
    }
}, { immediate: true });

const setQuantity = (value) => {
    emit('update', { field: '_quantity_touched', value: true });
    emit('update', { field: 'quantity', value });
};

/**
 * Le chemin de retour vers le calcul, après une correction à la main.
 *
 * Sans lui, reprendre la quantité déduite exigeait de la recalculer de
 * tête : la suggestion ne revient jamais d'elle-même (c'est la règle), mais
 * la rendre inatteignable en ferait une aide à usage unique.
 */
const canRestoreSuggestion = computed(() => suggestion.value !== null
    && Number(props.line.quantity) !== suggestion.value);

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
            <!-- ADR-110 — un consommable ne se dose pas : le champ disparaît
                 au lieu de rester vide et obligatoire. -->
            <FormField
                v-if="dosed"
                as="div"
                label="Dose"
                required
                :error="errorFor('dosage')"
            >
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
                    <Select
                        :model-value="line._dose_unit ?? 'mg'"
                        :options="doseUnitOptions"
                        :disabled="disabled"
                        aria-label="Unité de la dose"
                        class="w-28 min-w-0 shrink-0"
                        @update:model-value="setDose(line._dose_amount, $event)"
                    />
                </div>
            </FormField>

            <FormField as="div" label="Voie" :error="errorFor('route')">
                <Select
                    :model-value="line.route ?? ''"
                    :options="routeOptions"
                    :disabled="disabled"
                    placeholder="Non précisée"
                    aria-label="Voie d’administration"
                    class="w-full min-w-0"
                    @update:model-value="patch('route', $event || null)"
                />
            </FormField>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <FormField as="div" label="Fréquence" required :error="errorFor('frequency')">
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
            </FormField>

            <FormField as="div" label="Durée" :error="errorFor('duration')">
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
                    <Select
                        :model-value="line._duration_unit ?? 'jours'"
                        :options="durationUnitOptions"
                        :disabled="disabled"
                        aria-label="Unité de durée"
                        class="w-32 min-w-0 shrink-0"
                        @update:model-value="setDuration(line._duration_amount, $event)"
                    />
                </div>
            </FormField>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <FormField as="div" label="Quantité totale" required :error="errorFor('quantity')">
                <!-- L'unité appartient au champ : posée à côté en texte nu,
                     elle se lisait comme une note et non comme ce que le
                     nombre compte. -->
                <div class="flex">
                    <Input
                        :model-value="line.quantity"
                        :disabled="disabled"
                        inputmode="numeric"
                        aria-label="Quantité totale à délivrer"
                        class="min-w-0 flex-1 rounded-e-none"
                        @update:model-value="setQuantity($event)"
                    />
                    <span class="inline-flex h-10 shrink-0 items-center rounded-e-lg border border-s-0 border-input bg-muted px-3 text-xs font-medium text-muted-foreground">
                        {{ quantityUnit }}
                    </span>
                </div>
                <!-- Sur quoi le chiffre repose, jamais un total tombé du ciel. -->
                <p v-if="basis" class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] leading-4 text-muted-foreground">
                    <span class="inline-flex items-center gap-1.5">
                        <Calculator class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ basis }}
                    </span>
                    <button
                        v-if="canRestoreSuggestion"
                        type="button"
                        :disabled="disabled"
                        class="inline-flex items-center gap-1 font-semibold text-primary hover:underline disabled:pointer-events-none disabled:opacity-50"
                        @click="setQuantity(suggestion)"
                    >
                        <RotateCcw class="h-3 w-3" aria-hidden="true" />Utiliser {{ suggestion }}
                    </button>
                </p>
            </FormField>

            <FormField as="div" label="Instructions" hint="· facultatif" :error="errorFor('instructions')">
                <Input
                    :model-value="line.instructions"
                    :disabled="disabled"
                    maxlength="1000"
                    placeholder="Après les repas…"
                    aria-label="Instructions de prise"
                    @update:model-value="patch('instructions', $event)"
                />
            </FormField>
        </div>

        <!-- Ce que la ligne dira une fois enregistrée : le médecin voit le
             résultat avant de valider, jamais une suite de nombres. -->
        <p
            v-if="preview"
            class="flex flex-wrap items-baseline gap-x-2 gap-y-1 rounded-lg border border-border bg-muted/40 px-3 py-2 text-xs text-foreground"
        >
            <span class="text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Se lira</span>
            <span class="font-medium">{{ preview }}</span>
        </p>
    </div>
</template>
