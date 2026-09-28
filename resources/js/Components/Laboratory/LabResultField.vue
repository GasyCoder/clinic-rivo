<script setup>
import { computed, ref } from 'vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { Bug, Plus, X } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { NUGENT_KEYS, nugentReading, nugentScore } from '@/utilities/labWorkbench';

/**
 * ADR-213 — la saisie d'une analyse, selon son mode. Le composant écrit dans la
 * ligne du formulaire (`entry`) ; le serveur revalide chaque mode.
 */
const props = defineProps({
    node: { type: Object, required: true },
    entry: { type: Object, required: true },
    options: { type: Object, default: () => ({}) },
    microbiology: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
});

const choiceOptions = computed(() => [{ value: '', label: '—' }, ...(props.node.choices ?? []).map((choice) => ({ value: choice, label: choice }))]);
const pairOptions = (values) => [{ value: '', label: '—' }, ...(values ?? []).map((value) => ({ value, label: value }))];

const setValue = (value) => { props.entry.value = value ?? ''; };
const setDetail = (value) => { props.entry.selections = { ...(props.entry.selections ?? {}), detail: value ?? '' }; };

const toggleChoice = (choice, checked) => {
    const current = Array.isArray(props.entry.selections) ? props.entry.selections : [];
    props.entry.selections = checked ? [...new Set([...current, choice])] : current.filter((value) => value !== choice);
};

// Nugent
const nugentParts = computed(() => props.options.nugent_parts ?? []);
const nugentOptions = (max) => [{ value: '', label: '—' }, ...Array.from({ length: max + 1 }, (_, index) => ({ value: String(index), label: String(index) }))];
const setNugent = (key, value) => {
    props.entry.selections = { ...(props.entry.selections ?? {}), [key]: value === '' ? '' : Number(value) };
};
const score = computed(() => (props.node.entry_mode === 'NUGENT' ? nugentScore(props.entry.selections) : null));
const reading = computed(() => nugentReading(score.value));
const nugentMissing = computed(() => NUGENT_KEYS.filter((key) => [undefined, null, ''].includes(props.entry.selections?.[key])).length);

// Culture
const cultureOptions = computed(() => [{ value: '', label: '—' }, ...(props.options.culture ?? [])]);
const bacteriaIndex = computed(() => new Map(props.microbiology.flatMap((family) => family.bacteria.map((bacterium) => [bacterium.uuid, { ...bacterium, family: family.name }]))));
const pickedBacteria = computed(() => (props.entry.selections?.bacteria ?? []).map((uuid) => bacteriaIndex.value.get(uuid) ?? { uuid, name: 'Germe archivé', family: '' }));
const bacteriumToAdd = ref('');
const bacteriaChoices = computed(() => props.microbiology
    .map((family) => ({
        label: family.name,
        items: family.bacteria
            .filter((bacterium) => !(props.entry.selections?.bacteria ?? []).includes(bacterium.uuid))
            .map((bacterium) => ({ value: bacterium.uuid, label: bacterium.name })),
    }))
    .filter((group) => group.items.length));
const addBacterium = (uuid) => {
    if (!uuid) return;
    const current = props.entry.selections?.bacteria ?? [];
    if (current.length >= 6) return;
    props.entry.selections = { ...(props.entry.selections ?? {}), bacteria: [...current, uuid] };
    bacteriumToAdd.value = '';
};
const removeBacterium = (uuid) => {
    props.entry.selections = { ...props.entry.selections, bacteria: (props.entry.selections?.bacteria ?? []).filter((value) => value !== uuid) };
};
const setOther = (value) => { props.entry.selections = { ...(props.entry.selections ?? {}), other: value ?? '' }; };

const inputClass = 'h-9';
</script>

<template>
    <div class="min-w-0">
        <!-- Nombre -->
        <div v-if="node.entry_mode === 'NUMERIC'" class="flex items-center gap-2">
            <Input
                :model-value="entry.value"
                inputmode="decimal"
                :class="cn(inputClass, 'w-32 font-semibold tabular-nums')"
                :disabled="disabled"
                :aria-label="node.designation"
                placeholder="Valeur"
                @update:model-value="setValue"
            />
            <span v-if="node.unit" class="text-xs text-muted-foreground">{{ node.unit }}</span>
        </div>

        <!-- Texte libre -->
        <Textarea
            v-else-if="node.entry_mode === 'TEXT'"
            :model-value="entry.value"
            rows="2"
            :disabled="disabled"
            :aria-label="node.designation"
            placeholder="Résultat"
            @update:model-value="setValue"
        />

        <!-- Choix -->
        <Select
            v-else-if="node.entry_mode === 'CHOICE'"
            :model-value="entry.value"
            :options="choiceOptions"
            :disabled="disabled"
            :aria-label="node.designation"
            class="w-full sm:w-64"
            @update:model-value="setValue"
        />

        <!-- Plusieurs choix -->
        <div v-else-if="node.entry_mode === 'MULTI_CHOICE'" class="flex flex-wrap gap-x-4 gap-y-2">
            <label v-for="choice in node.choices" :key="choice" class="inline-flex items-center gap-2 text-sm">
                <Checkbox
                    :model-value="Array.isArray(entry.selections) && entry.selections.includes(choice)"
                    :disabled="disabled"
                    @update:model-value="(checked) => toggleChoice(choice, checked)"
                />
                {{ choice }}
            </label>
            <p v-if="!node.choices?.length" class="text-xs text-muted-foreground">Aucune valeur n’est définie au catalogue.</p>
        </div>

        <!-- Négatif / Positif (± valeur, ± précision) -->
        <div v-else-if="['NEG_POS', 'NEG_POS_VALUE', 'NEG_POS_CHOICE'].includes(node.entry_mode)" class="flex flex-wrap items-center gap-2">
            <div class="inline-flex rounded-lg border border-border p-0.5" role="radiogroup" :aria-label="node.designation">
                <button
                    v-for="value in options.negative_positive"
                    :key="value"
                    type="button"
                    role="radio"
                    :aria-checked="entry.value === value"
                    :disabled="disabled"
                    :class="cn('rounded-md px-3 py-1 text-xs font-semibold transition-colors disabled:opacity-60',
                        entry.value === value ? (value === 'Positif' ? 'bg-destructive text-destructive-foreground' : 'bg-emerald-600 text-white') : 'text-muted-foreground hover:bg-accent')"
                    @click="setValue(entry.value === value ? '' : value)"
                >{{ value }}</button>
            </div>
            <Input
                v-if="node.entry_mode === 'NEG_POS_VALUE'"
                :model-value="entry.selections?.detail ?? ''"
                :class="cn(inputClass, 'w-44')"
                :disabled="disabled || !entry.value"
                placeholder="Valeur (titre, taux…)"
                @update:model-value="setDetail"
            />
            <Select
                v-else-if="node.entry_mode === 'NEG_POS_CHOICE' && node.choices?.length"
                :model-value="entry.selections?.detail ?? ''"
                :options="choiceOptions"
                :disabled="disabled || !entry.value"
                placeholder="Précision"
                class="w-full sm:w-56"
                @update:model-value="setDetail"
            />
            <span v-if="node.unit" class="text-xs text-muted-foreground">{{ node.unit }}</span>
        </div>

        <!-- Absence / Présence -->
        <Select
            v-else-if="node.entry_mode === 'ABSENCE_PRESENCE'"
            :model-value="entry.value"
            :options="pairOptions(options.absence_presence)"
            :disabled="disabled"
            :aria-label="node.designation"
            class="w-full sm:w-48"
            @update:model-value="setValue"
        />

        <!-- Nugent -->
        <div v-else-if="node.entry_mode === 'NUGENT'" class="space-y-2">
            <div class="grid gap-2 sm:grid-cols-3">
                <label v-for="part in nugentParts" :key="part.key" class="space-y-1">
                    <span class="block text-[11px] font-semibold text-muted-foreground">{{ part.label }} (0–{{ part.max }})</span>
                    <Select
                        :model-value="entry.selections?.[part.key] === undefined || entry.selections?.[part.key] === '' ? '' : String(entry.selections[part.key])"
                        :options="nugentOptions(part.max)"
                        :disabled="disabled"
                        @update:model-value="(value) => setNugent(part.key, value)"
                    />
                </label>
            </div>
            <p class="flex flex-wrap items-center gap-2 text-xs">
                <template v-if="score !== null">
                    <span class="font-bold text-foreground">Score {{ score }}/10</span>
                    <Badge :tone="reading.tone">{{ reading.label }}</Badge>
                </template>
                <span v-else-if="nugentMissing < 3" class="text-muted-foreground">Il manque {{ nugentMissing }} sous-score(s) pour calculer le score.</span>
            </p>
        </div>

        <!-- Culture -->
        <div v-else-if="node.entry_mode === 'CULTURE'" class="space-y-2">
            <Select
                :model-value="entry.value"
                :options="cultureOptions"
                :disabled="disabled"
                :aria-label="node.designation"
                class="w-full sm:w-72"
                @update:model-value="setValue"
            />
            <div v-if="entry.value === 'GROWTH'" class="space-y-2 rounded-lg border border-dashed border-border p-3">
                <p class="flex items-center gap-1.5 text-xs font-semibold text-foreground"><Bug class="h-3.5 w-3.5" /> Germes identifiés <span class="font-normal text-muted-foreground">(six au plus)</span></p>
                <div class="flex flex-wrap gap-1.5">
                    <Badge v-for="bacterium in pickedBacteria" :key="bacterium.uuid" variant="outline" class="gap-1">
                        <span class="italic">{{ bacterium.name }}</span>
                        <span v-if="bacterium.family" class="text-[10px] text-muted-foreground">· {{ bacterium.family }}</span>
                        <button v-if="!disabled" type="button" class="ms-0.5 rounded hover:text-destructive" :aria-label="`Retirer ${bacterium.name}`" @click="removeBacterium(bacterium.uuid)"><X class="h-3 w-3" /></button>
                    </Badge>
                    <span v-if="!pickedBacteria.length" class="text-xs text-amber-700 dark:text-amber-400">Nommez au moins un germe : il faut un germe pour terminer.</span>
                </div>
                <div v-if="!disabled && pickedBacteria.length < 6" class="flex items-center gap-2">
                    <Select
                        v-model="bacteriumToAdd"
                        :options="bacteriaChoices"
                        :icon="Plus"
                        placeholder="Ajouter un germe"
                        class="w-full sm:w-80"
                        @update:model-value="addBacterium"
                    />
                </div>
                <p v-if="!microbiology.length" class="text-xs text-muted-foreground">Le référentiel des germes est vide : ajoutez-le depuis « Germes & antibiotiques ».</p>
            </div>
            <Input
                v-else-if="entry.value === 'OTHER'"
                :model-value="entry.selections?.other ?? ''"
                :class="inputClass"
                :disabled="disabled"
                placeholder="Préciser"
                @update:model-value="setOther"
            />
        </div>
    </div>
</template>
