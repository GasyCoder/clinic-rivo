<script setup>
import { computed } from 'vue';
import { Banknote } from 'lucide-vue-next';
import Select from '@/Components/Shadcn/Select.vue';
import OptionTile from '@/Components/Settings/OptionTile.vue';
import SettingsField from '@/Components/Settings/SettingsField.vue';
import SettingsSection from '@/Components/Settings/SettingsSection.vue';
import { formatMoney } from '@/utilities/money';

/** L'écriture de l'Ariary d'un site (ADR-184) : seule l'écriture change, aucun montant n'est converti. */
const props = defineProps({
    form: { type: Object, required: true },
    currencyLabels: { type: Array, default: () => ['Ar', 'Ariary', 'MGA'] },
    readonly: { type: Boolean, default: false },
});

const CURRENCY_POSITIONS = [{ value: 'after', label: 'Après le nombre' }, { value: 'before', label: 'Avant le nombre' }];
const CURRENCY_DECIMALS = [{ value: '0', label: 'Si besoin' }, { value: '2', label: 'Toujours deux' }];

/** Ce que chaque option change, écrit en petit dans sa vignette : « Ar1 » / « 1Ar », « 1 » / « 1,00 ». */
const POSITION_GLYPHS = { before: 'Ar1', after: '1Ar' };
const DECIMAL_GLYPHS = { 0: '1', 2: '1,00' };

const pickLabel = (value) => {
    if (value) props.form.currency_label = value;
};

const moneyExamples = computed(() => {
    const format = {
        label: props.form.currency_label,
        position: props.form.currency_position,
        decimals: Number(props.form.currency_decimals) === 2 ? 2 : 0,
    };

    return [12500, 4500.5, 150000].map((value) => formatMoney(value, 'MGA', format));
});
</script>

<template>
    <SettingsSection id="monnaie" title="Monnaie" description="Comment l’Ariary s’écrit sur les écrans et les documents. Seule l’écriture change : aucun montant n’est converti.">
        <div class="grid gap-6 sm:grid-cols-3">
            <SettingsField label="Unité" for="reglage-currency-label" description="Le mot écrit à côté des montants." :error="form.errors.currency_label">
                <Select id="reglage-currency-label" :model-value="form.currency_label" :options="currencyLabels.map((label) => ({ value: label, label }))" class="w-full" :disabled="readonly" @update:model-value="pickLabel">
                    <template #leading><OptionTile><Banknote class="h-3.5 w-3.5" /></OptionTile></template>
                </Select>
            </SettingsField>
            <SettingsField label="Position" for="reglage-currency-position" description="Avant ou après le nombre." :error="form.errors.currency_position">
                <Select id="reglage-currency-position" v-model="form.currency_position" :options="CURRENCY_POSITIONS" class="w-full" :disabled="readonly">
                    <template #leading="{ option }"><OptionTile><span class="font-mono text-[9px] font-semibold leading-none tracking-tighter">{{ POSITION_GLYPHS[option.value] }}</span></OptionTile></template>
                </Select>
            </SettingsField>
            <SettingsField label="Décimales" for="reglage-currency-decimals" description="« Si besoin » garde les centimes d’un prix." :error="form.errors.currency_decimals">
                <Select id="reglage-currency-decimals" :model-value="String(Number(form.currency_decimals) === 2 ? 2 : 0)" :options="CURRENCY_DECIMALS" class="w-full" :disabled="readonly" @update:model-value="form.currency_decimals = Number($event)">
                    <template #leading="{ option }"><OptionTile><span class="font-mono font-semibold leading-none tracking-tighter" :class="option.value === '2' ? 'text-[8px]' : 'text-[10px]'">{{ DECIMAL_GLYPHS[option.value] }}</span></OptionTile></template>
                </Select>
            </SettingsField>
        </div>
        <SettingsField label="Aperçu" description="Trois montants, écrits avec ces réglages.">
            <ul class="max-w-2xl divide-y divide-border rounded-md border border-border" aria-label="Aperçu des montants">
                <li v-for="example in moneyExamples" :key="example" class="flex items-center gap-2 px-4 py-2.5 font-mono text-sm tabular-nums text-foreground"><Banknote class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />{{ example }}</li>
            </ul>
        </SettingsField>
    </SettingsSection>
</template>
