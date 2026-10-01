<script setup>
import { computed, watch } from 'vue';
import {
    Baby, Binary, Calculator, FileText, FlaskConical, FolderTree, Hash, Info, Layers,
    ListChecks, Mars, Pencil, Ruler, Siren, Tag, ToggleLeft, Type, Users, Venus,
} from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Card from '@/Components/Shadcn/Card.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import RadioGroup from '@/Components/Shadcn/RadioGroup.vue';
import RadioGroupItem from '@/Components/Shadcn/RadioGroupItem.vue';
import SearchSelect from '@/Components/Shadcn/SearchSelect.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Switch from '@/Components/Shadcn/Switch.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import SubAnalysesEditor from './SubAnalysesEditor.vue';
import CriticalRangesField from './CriticalRangesField.vue';
import { criticalRangesCount } from '@/utilities/criticalRanges';
import {
    LEVELS, NEW_DISCIPLINE, RESULT_TYPES, automaticEntryMode, automaticEntryModeLabel, disciplineOptions, entryModesFor,
    isNumericResult, keepEntryModeCoherent, matchesCatalogItem, mayHaveParent, splitPredefinedValues, usesPredefinedValues,
} from '@/utilities/analysisForm';
import { cn } from '@/lib/cn';

/**
 * ADR-063, amendement du 2026-10-01 — la fiche d'une analyse, une étape à la
 * fois. Elle ne décide ni de l'étape ni de l'enregistrement : la création et
 * la fiche (`Pages/Analyses/*`) le font. Aucune règle n'est ici qui ne soit
 * aussi au serveur ; ce qui est caché (unité, valeurs, bornes) ne l'est que
 * parce que le type de résultat ne s'en sert pas.
 */
const props = defineProps({
    form: { type: Object, required: true },
    step: { type: String, required: true },
    analysisUuid: { type: String, default: null },
    hierarchyPath: { type: String, default: '' },
    catalogItems: { type: Array, required: true },
    parents: { type: Array, required: true },
    levels: { type: Array, required: true },
    resultTypes: { type: Array, required: true },
    entryModes: { type: Array, default: () => [] },
    // ADR-238 — les disciplines du référentiel, la discipline portée et ce que
    // « Automatique » donne pour l'analyse enregistrée.
    disciplines: { type: Array, default: () => [] },
    currentDiscipline: { type: Object, default: null },
    canCreateDiscipline: { type: Boolean, default: false },
    servedEntryMode: { type: Object, default: null },
});
const emit = defineEmits(['go']);

const levelIcons = { NORMAL: FlaskConical, PARENT: FolderTree, CHILD: Layers };
const typeIcons = { NUMERIC: Calculator, TEXT: Type, CHOICE: ListChecks, BOOLEAN: ToggleLeft };

const catalogOptions = computed(() => props.catalogItems.map((item) => ({ value: item.uuid, label: item.name, description: item.code, code: item.code, name: item.name })));
const selectedCatalogItem = computed(() => props.catalogItems.find((item) => item.uuid === props.form.catalog_item_uuid) ?? null);

// Un groupe ne se range jamais sous lui-même ni sous l'un de ses descendants ;
// le serveur le refuserait de toute façon, la liste ne le propose donc pas.
const availableParents = computed(() => props.parents.filter((parent) => {
    if (parent.catalog_item_uuid !== props.form.catalog_item_uuid) return false;
    if (! props.analysisUuid) return true;
    if (parent.uuid === props.analysisUuid) return false;
    return ! props.hierarchyPath || ! parent.path.startsWith(`${props.hierarchyPath} › `);
}));
const parentOptions = computed(() => [
    ...(props.form.level === 'PARENT' ? [{ value: '', label: 'Aucun — groupe principal' }] : []),
    ...availableParents.value.map((parent) => ({ value: parent.uuid, label: parent.path })),
]);
const selectedParent = computed(() => availableParents.value.find((parent) => parent.uuid === props.form.parent_uuid) ?? null);

// ADR-238 — la saisie suit le type coché : seuls les modes qui lui conviennent
// sont proposés, et un mode qui ne lui va plus redevient « Automatique ».
const automatic = computed(() => automaticEntryMode(props.form, props.entryModes, props.servedEntryMode));
const entryModeOptions = computed(() => [
    { value: '', label: automaticEntryModeLabel(automatic.value) },
    ...entryModesFor(props.entryModes, props.form.result_type).map((mode) => ({ value: mode.value, label: mode.label })),
]);
const modesForType = computed(() => entryModesFor(props.entryModes, props.form.result_type));
watch(() => props.form.result_type, () => keepEntryModeCoherent(props.form, props.entryModes));

// La discipline d'une sous-analyse est celle de son groupe : rien à choisir.
const inheritsDiscipline = computed(() => props.form.level === 'CHILD' || (props.form.level === 'PARENT' && Boolean(props.form.parent_uuid)));
const disciplineChoices = computed(() => disciplineOptions(props.disciplines, props.currentDiscipline, { canCreate: props.canCreateDiscipline }));
const disciplineName = computed(() => {
    if (props.form.lab_discipline_uuid === NEW_DISCIPLINE) return props.form.new_discipline_name?.trim() || '';
    return disciplineChoices.value.find((option) => option.value && option.value === props.form.lab_discipline_uuid)?.label ?? '';
});
const entryMode = computed({
    get: () => props.form.entry_mode ?? '',
    set: (value) => { props.form.entry_mode = value || null; },
});

const numeric = computed(() => isNumericResult(props.form));
const choices = computed(() => usesPredefinedValues(props.form));
const predefinedValues = computed(() => splitPredefinedValues(props.form.predefined_values_text));
const criticalCount = computed(() => criticalRangesCount(props.form.critical_ranges));
const referenceFields = [
    { key: 'reference_general', label: 'Générale', hint: 'tous les patients', icon: Users },
    { key: 'reference_male', label: 'Homme', icon: Mars },
    { key: 'reference_female', label: 'Femme', icon: Venus },
    { key: 'reference_child_male', label: 'Enfant garçon', icon: Baby },
    { key: 'reference_child_female', label: 'Enfant fille', icon: Baby },
];
const referencesCount = computed(() => referenceFields.filter((field) => String(props.form[field.key] ?? '').trim()).length);

// Changer de prestation ou de niveau invalide le groupe parent choisi.
const setCatalogItem = (uuid) => {
    if (uuid === props.form.catalog_item_uuid) return;
    props.form.catalog_item_uuid = uuid;
    props.form.parent_uuid = '';
};
const setLevel = (level) => {
    if (level === props.form.level) return;
    props.form.level = level;
    if (! mayHaveParent(level)) props.form.parent_uuid = '';
};

const err = (field) => props.form.errors?.[field] ?? '';
const summary = computed(() => [
    {
        step: 'identite', label: 'Identité', icon: Tag,
        title: props.form.designation || 'Désignation à renseigner',
        lines: [
            `${props.form.code || 'Code à renseigner'} · ${LEVELS[props.form.level]?.label ?? props.form.level}`,
            selectedCatalogItem.value ? `${selectedCatalogItem.value.code} · ${selectedCatalogItem.value.name}` : 'Prestation à choisir',
            ...(selectedParent.value ? [`Dans ${selectedParent.value.path}`] : []),
        ],
    },
    {
        step: 'resultat', label: 'Résultat', icon: Binary,
        title: `${RESULT_TYPES[props.form.result_type]?.label ?? props.form.result_type}${props.form.unit ? ` · ${props.form.unit}` : ''}`,
        lines: [
            props.entryModes.find((mode) => mode.value === props.form.entry_mode)?.label ?? automaticEntryModeLabel(automatic.value),
            ...(choices.value ? [predefinedValues.value.length ? predefinedValues.value.join(' · ') : 'Aucune valeur proposée'] : []),
            ...(inheritsDiscipline.value ? ['Discipline du groupe'] : [disciplineName.value || 'Sans discipline']),
        ],
    },
    {
        step: 'normes', label: 'Normes', icon: Ruler,
        title: referencesCount.value ? `${referencesCount.value} référence(s)` : 'Aucune référence',
        lines: numeric.value ? [criticalCount.value ? `${criticalCount.value} profil(s) avec bornes critiques` : 'Aucune borne critique'] : [],
    },
    ...(props.form.level === 'PARENT' ? [{
        step: 'sous-analyses', label: 'Sous-analyses', icon: Layers,
        title: `${props.form.children.length} sous-analyse(s)`,
        lines: props.form.children.slice(0, 4).map((child) => child.designation || child.code || 'Sans désignation'),
    }] : []),
]);
</script>

<template>
    <div class="space-y-4">
        <!-- 1 · Identité ------------------------------------------------- -->
        <template v-if="step === 'identite'">
            <Card class="space-y-5 p-5 sm:p-6">
                <header class="space-y-1">
                    <h2 class="flex items-center gap-2 text-base font-semibold text-foreground"><Tag class="h-4 w-4 text-primary" /> Quelle analyse ?</h2>
                    <p class="text-sm text-muted-foreground">La prestation porte le tarif et la facturation ; cette fiche ne décrit que le résultat.</p>
                </header>

                <FormField as="div" label="Prestation Laboratoire" required :error="err('catalog_item_uuid')" :icon="FlaskConical">
                    <SearchSelect
                        id="catalog_item_uuid"
                        :model-value="form.catalog_item_uuid"
                        :options="catalogOptions"
                        :filter="matchesCatalogItem"
                        placeholder="Choisir la prestation facturée"
                        search-placeholder="Rechercher par nom ou code…"
                        empty-text="Aucune prestation Laboratoire ne correspond."
                        :invalid="Boolean(err('catalog_item_uuid'))"
                        @update:model-value="setCatalogItem"
                    />
                </FormField>

                <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_14rem]">
                    <FormField label="Désignation" required :error="err('designation')" :icon="FileText">
                        <Input id="designation" v-model="form.designation" placeholder="Hémoglobine" autocomplete="off" :aria-invalid="Boolean(err('designation'))" />
                    </FormField>
                    <FormField label="Code" required hint="unique" :error="err('code')" :icon="Hash">
                        <Input id="code" v-model="form.code" placeholder="NFS-HB" autocomplete="off" class="font-mono uppercase" :aria-invalid="Boolean(err('code'))" />
                    </FormField>
                </div>
            </Card>

            <Card class="space-y-4 p-5 sm:p-6">
                <header class="space-y-1">
                    <h2 class="flex items-center gap-2 text-base font-semibold text-foreground"><FolderTree class="h-4 w-4 text-primary" /> Où se range-t-elle ?</h2>
                    <p class="text-sm text-muted-foreground">Une analyse seule, un groupe qui réunit plusieurs résultats, ou un résultat dans un groupe.</p>
                </header>

                <RadioGroup :model-value="form.level" class="grid gap-3 md:grid-cols-3" aria-label="Niveau" @update:model-value="setLevel">
                    <label
                        v-for="level in levels"
                        :key="level"
                        :class="cn(
                            'flex cursor-pointer gap-3 rounded-lg border p-4 transition-colors hover:bg-accent/50',
                            form.level === level ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border',
                        )"
                    >
                        <RadioGroupItem :value="level" class="mt-1" />
                        <span class="min-w-0 space-y-1">
                            <span class="flex items-center gap-2 text-sm font-semibold text-foreground"><component :is="levelIcons[level]" class="h-4 w-4 text-primary" />{{ LEVELS[level]?.label ?? level }}</span>
                            <span class="block text-xs leading-5 text-muted-foreground">{{ LEVELS[level]?.hint }}</span>
                        </span>
                    </label>
                </RadioGroup>
                <p v-if="err('level')" class="text-sm text-destructive">{{ err('level') }}</p>

                <FormField
                    v-if="mayHaveParent(form.level)"
                    as="div"
                    :label="form.level === 'CHILD' ? 'Groupe parent' : 'Groupe parent'"
                    :required="form.level === 'CHILD'"
                    :hint="form.level === 'PARENT' ? '(facultatif : un groupe peut être dans un autre)' : ''"
                    :error="err('parent_uuid')"
                    :icon="FolderTree"
                >
                    <Select
                        v-if="parentOptions.length"
                        id="parent_uuid"
                        v-model="form.parent_uuid"
                        :options="parentOptions"
                        :placeholder="form.level === 'CHILD' ? 'Choisir le groupe' : 'Aucun — groupe principal'"
                        class="w-full"
                    />
                    <p v-else class="rounded-md border border-dashed border-border px-3 py-2.5 text-sm text-muted-foreground">
                        {{ form.catalog_item_uuid ? 'Cette prestation n’a encore aucun groupe : créez d’abord le groupe, puis ses sous-analyses.' : 'Choisissez d’abord la prestation : ses groupes apparaîtront ici.' }}
                    </p>
                </FormField>
            </Card>
        </template>

        <!-- 2 · Résultat ------------------------------------------------- -->
        <template v-else-if="step === 'resultat'">
            <Card class="space-y-4 p-5 sm:p-6">
                <header class="space-y-1">
                    <h2 class="flex items-center gap-2 text-base font-semibold text-foreground"><Binary class="h-4 w-4 text-primary" /> Quel résultat ?</h2>
                    <p class="text-sm text-muted-foreground">Le type décide de ce que la paillasse saisit, et de ce que les étapes suivantes demandent.</p>
                </header>
                <RadioGroup v-model="form.result_type" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Type de résultat">
                    <label
                        v-for="type in resultTypes"
                        :key="type"
                        :class="cn(
                            'flex cursor-pointer gap-3 rounded-lg border p-4 transition-colors hover:bg-accent/50',
                            form.result_type === type ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border',
                        )"
                    >
                        <RadioGroupItem :value="type" class="mt-1" />
                        <span class="min-w-0 space-y-1">
                            <span class="flex items-center gap-2 text-sm font-semibold text-foreground"><component :is="typeIcons[type]" class="h-4 w-4 text-primary" />{{ RESULT_TYPES[type]?.label ?? type }}</span>
                            <span class="block text-xs leading-5 text-muted-foreground">{{ RESULT_TYPES[type]?.hint }}</span>
                            <span class="block font-mono text-[11px] text-muted-foreground">Ex. {{ RESULT_TYPES[type]?.example }}</span>
                        </span>
                    </label>
                </RadioGroup>
                <p v-if="err('result_type')" class="text-sm text-destructive">{{ err('result_type') }}</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField v-if="numeric" label="Unité" hint="(facultatif)" :error="err('unit')" :icon="Ruler">
                        <Input id="unit" v-model="form.unit" placeholder="g/dL, mmol/L…" autocomplete="off" />
                    </FormField>
                    <FormField v-if="entryModes.length" as="div" label="Saisie au laboratoire" :error="err('entry_mode')" :icon="Pencil">
                        <Select id="entry_mode" v-model="entryMode" :options="entryModeOptions" class="w-full min-w-0" />
                    </FormField>
                </div>
                <p v-if="entryModes.length" class="-mt-2 text-xs text-muted-foreground">
                    {{ modesForType.length > 1
                        ? `Pour un résultat « ${RESULT_TYPES[form.result_type]?.label ?? form.result_type} » : ${modesForType.map((mode) => mode.label).join(' · ')}.`
                        : 'Un seul mode de saisie pour ce type de résultat.' }}
                    Un autre mode se demande au développement : chaque saisie a son écran, son contrôle et son impression.
                </p>
            </Card>

            <Card v-if="choices" class="space-y-3 p-5 sm:p-6">
                <FormField label="Valeurs proposées à la paillasse" :error="err('predefined_values') || err('predefined_values_text')" :icon="ListChecks">
                    <Input id="predefined_values_text" v-model="form.predefined_values_text" placeholder="Positif | Négatif | Indéterminé" autocomplete="off" />
                </FormField>
                <p class="text-xs text-muted-foreground">Séparez les valeurs par une barre verticale « | ».</p>
                <div v-if="predefinedValues.length" class="flex flex-wrap gap-1.5">
                    <Badge v-for="value in predefinedValues" :key="value" variant="secondary">{{ value }}</Badge>
                </div>
            </Card>

            <Card class="space-y-4 p-5 sm:p-6">
                <header class="space-y-1">
                    <h2 class="flex items-center gap-2 text-base font-semibold text-foreground"><Info class="h-4 w-4 text-primary" /> Présentation</h2>
                    <p class="text-sm text-muted-foreground">Comment l'analyse se range et s'imprime sur le compte rendu.</p>
                </header>
                <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_10rem]">
                    <FormField v-if="inheritsDiscipline" as="div" label="Discipline" :icon="FlaskConical">
                        <p class="flex h-[var(--control-h)] items-center rounded-md border border-dashed border-border px-3 text-sm text-muted-foreground">Celle de son groupe</p>
                    </FormField>
                    <FormField v-else as="div" label="Discipline" hint="(facultatif)" :error="err('lab_discipline_uuid')" :icon="FlaskConical">
                        <Select id="lab_discipline_uuid" v-model="form.lab_discipline_uuid" :options="disciplineChoices" class="w-full min-w-0" />
                    </FormField>
                    <FormField label="Ordre" :error="err('display_order')" :icon="Hash">
                        <Input id="display_order" v-model.number="form.display_order" type="number" min="0" />
                    </FormField>
                </div>
                <FormField
                    v-if="!inheritsDiscipline && form.lab_discipline_uuid === NEW_DISCIPLINE"
                    label="Nom de la nouvelle discipline"
                    :error="err('new_discipline_name')"
                    :icon="FlaskConical"
                    required
                >
                    <Input id="new_discipline_name" v-model="form.new_discipline_name" maxlength="120" placeholder="IMMUNOLOGIE" autocomplete="off" />
                </FormField>
                <p v-if="!inheritsDiscipline" class="-mt-2 text-xs text-muted-foreground">La discipline range la feuille de paillasse et la section du compte rendu. Les sous-analyses prennent celle du groupe.</p>
                <FormField label="Description" hint="(facultatif)" :error="err('description')" :icon="FileText">
                    <Textarea id="description" v-model="form.description" rows="2" placeholder="Méthode, précision utile au laboratoire" />
                </FormField>
                <label class="flex items-center justify-between gap-4 rounded-lg border border-border px-4 py-3">
                    <span><span class="block text-sm font-medium text-foreground">Nom en gras sur le compte rendu</span><span class="block text-xs text-muted-foreground">Pour un titre de groupe ou une analyse à faire ressortir.</span></span>
                    <Switch v-model="form.is_bold" aria-label="Nom en gras sur le compte rendu" />
                </label>
            </Card>
        </template>

        <!-- 3 · Normes --------------------------------------------------- -->
        <template v-else-if="step === 'normes'">
            <Card class="space-y-4 p-5 sm:p-6">
                <header class="flex flex-wrap items-start justify-between gap-2">
                    <div class="space-y-1">
                        <h2 class="flex items-center gap-2 text-base font-semibold text-foreground"><Ruler class="h-4 w-4 text-primary" /> Valeurs de référence</h2>
                        <p class="text-sm text-muted-foreground">Imprimées à côté du résultat. Le profil le plus précis l'emporte ; sans lui, la générale.</p>
                    </div>
                    <Badge variant="outline">Facultatif</Badge>
                </header>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <FormField v-for="field in referenceFields" :key="field.key" :label="field.label" :hint="field.hint ? `(${field.hint})` : ''" :error="err(field.key)" :icon="field.icon">
                        <Input :id="field.key" v-model="form[field.key]" :placeholder="numeric ? '12 – 16' : 'Négatif'" autocomplete="off" />
                    </FormField>
                </div>
            </Card>

            <Card class="space-y-3 p-5 sm:p-6">
                <header class="space-y-1">
                    <h2 class="flex items-center gap-2 text-base font-semibold text-foreground"><Siren class="h-4 w-4 text-destructive" /> Bornes critiques</h2>
                    <p class="text-sm text-muted-foreground">Au-delà, le résultat est marqué critique d'office à la paillasse. Saisies par la clinique, jamais proposées par RIVO.</p>
                </header>
                <CriticalRangesField v-if="numeric && form.critical_ranges" v-model="form.critical_ranges" :errors="form.errors" class="max-w-2xl" />
                <p v-else class="rounded-md border border-dashed border-border px-3 py-3 text-sm text-muted-foreground">
                    Les bornes critiques ne valent que pour un résultat numérique.
                    <button type="button" class="font-medium text-primary underline-offset-2 hover:underline" @click="emit('go', 'resultat')">Changer le type de résultat</button>
                </p>
            </Card>
        </template>

        <!-- 4 · Sous-analyses -------------------------------------------- -->
        <template v-else-if="step === 'sous-analyses'">
            <SubAnalysesEditor
                v-if="form.level === 'PARENT'"
                :children="form.children"
                :form="form"
                path-prefix="children"
                :result-types="resultTypes"
                :entry-modes="entryModes"
                :depth="0"
                :max-depth="1"
            />
            <Card v-else class="p-6 text-center text-sm text-muted-foreground">
                Une analyse « {{ LEVELS[form.level]?.label }} » n'a pas de sous-analyses.
                <button type="button" class="font-medium text-primary underline-offset-2 hover:underline" @click="emit('go', 'identite')">En faire un groupe</button>
            </Card>
        </template>

        <!-- 5 · Récapitulatif -------------------------------------------- -->
        <template v-else>
            <div class="grid gap-3 md:grid-cols-2">
                <button
                    v-for="item in summary"
                    :key="item.step"
                    type="button"
                    class="group rounded-xl border border-border bg-card p-4 text-start shadow-sm transition-colors hover:border-primary/40 hover:bg-accent/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    @click="emit('go', item.step)"
                >
                    <span class="flex items-center justify-between gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        <span class="flex items-center gap-1.5"><component :is="item.icon" class="h-3.5 w-3.5 text-primary" />{{ item.label }}</span>
                        <Pencil class="h-3.5 w-3.5 opacity-60 group-hover:opacity-100" aria-hidden="true" />
                    </span>
                    <strong class="mt-2 block truncate text-sm text-foreground">{{ item.title }}</strong>
                    <span v-for="line in item.lines" :key="line" class="mt-0.5 block truncate text-xs text-muted-foreground">{{ line }}</span>
                </button>
            </div>
            <Card class="flex items-start gap-3 p-4 text-sm text-muted-foreground">
                <Info class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                <p>Une sous-analyse retirée est désactivée, jamais supprimée ; un troisième niveau se crée à part, rattaché par son « Groupe parent ».</p>
            </Card>
        </template>
    </div>
</template>
