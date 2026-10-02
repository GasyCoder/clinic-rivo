<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Palette, RotateCcw, Save } from 'lucide-vue-next';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Switch from '@/Components/Shadcn/Switch.vue';
import Slider from '@/Components/Shadcn/Slider.vue';
import Input from '@/Components/Shadcn/Input.vue';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/utilities/pharmacyStatus';
import {
    PRICE_COMPARISON_DEFAULTS, PRICE_GAPS, PRICE_STYLES, gapLabel, orderQuotes, priceTier, resolvePriceComparison, tierStyles,
} from '@/utilities/priceComparison';

/**
 * ADR-242 — couleurs et marquage des prix du comparateur, propres au compte.
 * L'aperçu montre trois offres fictives avec les réglages en cours de saisie ;
 * rien n'est enregistré avant « Enregistrer ».
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    settings: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:open']);

const draft = ref(resolvePriceComparison(props.settings));
watch(() => props.open, (open) => { if (open) draft.value = resolvePriceComparison(props.settings); });

const saving = ref(false);
const errors = ref({});

const sample = [
    { key: 'a', supplier_name: 'Fournisseur A', price: 36000 },
    { key: 'b', supplier_name: 'Fournisseur B', price: 30000 },
    { key: 'c', supplier_name: 'Fournisseur C', price: 33000 },
];
const preview = computed(() => orderQuotes(sample, draft.value).map((quote) => {
    const info = priceTier(sample, quote, draft.value);

    return { ...quote, info, styles: tierStyles(info.tier, draft.value), gap: gapLabel(info, draft.value, formatMoney) };
}));
const validHex = (value) => /^#[0-9A-Fa-f]{6}$/.test(String(value ?? ''));
const colors = computed(() => [
    { key: 'best_color', label: 'Moins cher' },
    { key: 'middle_color', label: 'Intermédiaire', disabled: !draft.value.color_middle },
    { key: 'worst_color', label: 'Plus cher' },
]);
const tierText = { best: 'le moins cher', worst: 'le plus cher', middle: '' };

const toggles = [
    { key: 'color_middle', label: 'Colorer les prix intermédiaires', hint: 'Sinon, seuls le moins cher et le plus cher se colorent.' },
    { key: 'show_labels', label: 'Écrire « le moins cher » / « le plus cher »', hint: 'La couleur ne porte jamais seule le sens.' },
    { key: 'sort_by_price', label: 'Ranger les offres du moins cher au plus cher', hint: 'Sinon, dans l’ordre des fournisseurs.' },
    { key: 'show_legend', label: 'Afficher la légende au-dessus du tableau', hint: '' },
];

const save = (reset = false) => {
    saving.value = true;
    errors.value = {};
    router.put('/profil/comparateur-prix', reset ? { reset: true } : draft.value, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('update:open', false),
        onError: (bag) => { errors.value = bag; },
        onFinish: () => { saving.value = false; },
    });
};
</script>

<template>
    <Dialog :open="open" title="Affichage des prix" description="Comment le comparateur montre le moins cher et le plus cher. Propre à votre compte." size="lg" @update:open="(value) => emit('update:open', value)">
        <template #icon><Palette class="h-5 w-5" /></template>

        <div class="space-y-6">
            <section>
                <h3 class="text-sm font-semibold text-foreground">Aperçu</h3>
                <div class="mt-2 flex flex-wrap gap-2 rounded-lg border border-dashed border-border p-3">
                    <div v-for="quote in preview" :key="quote.key" class="min-w-44 rounded-lg border border-border px-3 py-2" :style="quote.styles.card">
                        <span class="block text-sm font-medium text-foreground">{{ quote.supplier_name }}</span>
                        <span class="block text-xs" :style="quote.styles.price">
                            {{ formatMoney(quote.price) }}<span v-if="draft.show_labels && tierText[quote.info.tier]"> · {{ tierText[quote.info.tier] }}</span>
                        </span>
                        <span v-if="quote.gap" class="block text-[11px] text-muted-foreground">{{ quote.gap }}</span>
                    </div>
                </div>
            </section>

            <section>
                <h3 class="text-sm font-semibold text-foreground">Couleurs</h3>
                <div class="mt-2 grid gap-2 sm:grid-cols-3">
                    <div
                        v-for="color in colors"
                        :key="color.key"
                        :class="cn('rounded-lg border border-border p-2.5', color.disabled && 'opacity-50')"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-semibold text-foreground">{{ color.label }}</span>
                            <button
                                v-if="draft[color.key] !== PRICE_COMPARISON_DEFAULTS[color.key] && !color.disabled"
                                type="button"
                                class="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                                :aria-label="`${color.label} : couleur d’origine`"
                                title="Couleur d’origine"
                                @click="draft[color.key] = PRICE_COMPARISON_DEFAULTS[color.key]"
                            ><RotateCcw class="h-3.5 w-3.5" /></button>
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                            <label class="relative h-8 w-8 shrink-0 cursor-pointer overflow-hidden rounded-md border border-input shadow-sm" :style="{ backgroundColor: validHex(draft[color.key]) ? draft[color.key] : PRICE_COMPARISON_DEFAULTS[color.key] }">
                                <span class="sr-only">Choisir : {{ color.label }}</span>
                                <input
                                    type="color"
                                    class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                                    :value="(validHex(draft[color.key]) ? draft[color.key] : PRICE_COMPARISON_DEFAULTS[color.key]).toLowerCase()"
                                    :disabled="color.disabled"
                                    @input="draft[color.key] = $event.target.value.toUpperCase()"
                                >
                            </label>
                            <Input
                                :model-value="draft[color.key]"
                                maxlength="7"
                                spellcheck="false"
                                :disabled="color.disabled"
                                :aria-label="`${color.label} (code #RRVVBB)`"
                                :class="cn('h-8 min-w-0 flex-1 font-mono text-xs uppercase', (errors[color.key] || !validHex(draft[color.key])) && 'border-destructive')"
                                @update:model-value="(value) => { draft[color.key] = String(value).toUpperCase(); }"
                            />
                        </div>
                    </div>
                </div>
            </section>

            <section>
                <h3 class="text-sm font-semibold text-foreground">Marquage</h3>
                <div class="mt-2 grid gap-2 sm:grid-cols-3" role="radiogroup" aria-label="Marquage">
                    <button
                        v-for="style in PRICE_STYLES"
                        :key="style.value"
                        type="button"
                        role="radio"
                        :aria-checked="draft.style === style.value"
                        :class="cn('rounded-lg border px-3 py-2 text-start transition', draft.style === style.value ? 'border-primary bg-primary/10' : 'border-border hover:border-primary/40')"
                        @click="draft.style = style.value"
                    >
                        <span class="block text-sm font-semibold text-foreground">{{ style.label }}</span>
                        <span class="block text-xs text-muted-foreground">{{ style.description }}</span>
                    </button>
                </div>
            </section>

            <section>
                <h3 class="text-sm font-semibold text-foreground">Écart au moins cher</h3>
                <div class="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="Écart affiché">
                    <button
                        v-for="gap in PRICE_GAPS"
                        :key="gap.value"
                        type="button"
                        role="radio"
                        :aria-checked="draft.show_gap === gap.value"
                        :class="cn('rounded-full border px-3 py-1.5 text-xs font-semibold transition', draft.show_gap === gap.value ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground hover:bg-muted')"
                        @click="draft.show_gap = gap.value"
                    >{{ gap.label }} <span class="font-normal opacity-80">{{ gap.example }}</span></button>
                </div>

                <div class="mt-4">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium text-foreground">Signaler « le plus cher » à partir de</span>
                        <span class="font-semibold tabular-nums text-foreground">{{ draft.min_gap_percent }} %</span>
                    </div>
                    <Slider v-model="draft.min_gap_percent" :min="0" :max="100" :step="5" aria-label="Écart minimal pour signaler le plus cher" class="mt-2" />
                    <p class="mt-1 text-xs text-muted-foreground">En deçà, le plus cher se lit comme un prix intermédiaire : un écart de quelques ariary ne mérite pas le rouge.</p>
                </div>
            </section>

            <section class="space-y-3">
                <label v-for="toggle in toggles" :key="toggle.key" class="flex items-start justify-between gap-4">
                    <span>
                        <span class="block text-sm font-medium text-foreground">{{ toggle.label }}</span>
                        <span v-if="toggle.hint" class="block text-xs text-muted-foreground">{{ toggle.hint }}</span>
                    </span>
                    <Switch v-model="draft[toggle.key]" :aria-label="toggle.label" />
                </label>
            </section>

            <p v-if="Object.keys(errors).length" class="text-sm font-medium text-destructive">{{ Object.values(errors)[0] }}</p>
        </div>

        <template #footer>
            <Button type="button" variant="ghost" :disabled="saving" @click="save(true)"><RotateCcw class="h-4 w-4" />Valeurs d’origine</Button>
            <Button type="button" variant="outline" :disabled="saving" @click="emit('update:open', false)">Annuler</Button>
            <Button type="button" :disabled="saving" @click="save(false)"><Save class="h-4 w-4" />{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</Button>
        </template>
    </Dialog>
</template>
