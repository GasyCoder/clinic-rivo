<script setup>
import { computed } from 'vue';
import { MapPin } from 'lucide-vue-next';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Select from '@/Components/Shadcn/Select.vue';
import { cn } from '@/lib/cn';

/**
 * Le champ « Adresse » d'une fiche : une entrée du référentiel d'adresses du
 * site (ADR-042), ou une nouvelle entrée qui le rejoint à l'enregistrement —
 * jamais un texte libre à côté du référentiel. Le serveur reçoit
 * `address_entry_uuid` ou `new_address_label`, jamais les deux.
 *
 * `addresses` : `[{ uuid, label, available }]`. Une adresse archivée qu'une
 * fiche porte déjà reste affichée, marquée, et ne se choisit plus.
 */
const props = defineProps({
    addresses: { type: Array, default: () => [] },
    entry: { type: String, default: '' },
    newLabel: { type: String, default: '' },
    mode: { type: String, default: 'existing' },
    label: { type: String, default: 'Adresse' },
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
    /** Le droit d'ajouter une adresse au référentiel (`address_entries.create`). */
    canCreate: { type: Boolean, default: true },
    /** Les identifiants des champs : un résumé d'erreurs y mène le regard. */
    entryId: { type: String, default: 'address_entry_uuid' },
    newLabelId: { type: String, default: 'new_address_label' },
});
const emit = defineEmits(['update:entry', 'update:newLabel', 'update:mode']);

const MODES = [
    { key: 'existing', label: 'Référentiel' },
    { key: 'new', label: 'Nouvelle' },
];

const currentMode = computed(() => (props.canCreate ? props.mode : 'existing'));
const options = computed(() => [
    { value: '', label: 'Non renseignée' },
    ...props.addresses.map((address) => ({
        value: address.uuid,
        label: address.available === false ? `${address.label} — archivée` : address.label,
        disabled: address.available === false,
    })),
]);

/** Changer de source vide l'autre champ : les deux ne partent jamais ensemble. */
const setMode = (mode) => {
    if (mode === currentMode.value) return;
    emit('update:mode', mode);
    if (mode === 'new') emit('update:entry', '');
    else emit('update:newLabel', '');
};
</script>

<template>
    <FormField as="div" :label="label" :hint="hint" :error="error">
        <template v-if="canCreate" #action>
            <span class="inline-flex rounded-md bg-muted p-0.5" role="group" aria-label="Source de l’adresse">
                <button
                    v-for="option in MODES"
                    :key="option.key"
                    type="button"
                    :aria-pressed="currentMode === option.key"
                    :class="cn(
                        'rounded px-2.5 py-0.5 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        currentMode === option.key ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                    )"
                    @click="setMode(option.key)"
                >{{ option.label }}</button>
            </span>
        </template>
        <Select
            v-if="currentMode === 'existing'"
            :id="entryId"
            :model-value="entry"
            :options="options"
            :icon="MapPin"
            placeholder="Non renseignée"
            class="w-full"
            :aria-label="label"
            :aria-invalid="Boolean(error)"
            @update:model-value="(value) => emit('update:entry', value ?? '')"
        />
        <template v-else>
            <IconInput
                :id="newLabelId"
                :model-value="newLabel"
                :icon="MapPin"
                maxlength="255"
                placeholder="Saisir une nouvelle adresse"
                aria-label="Nouvelle adresse"
                :aria-invalid="Boolean(error)"
                :class="cn(error && 'border-destructive focus-visible:border-destructive focus-visible:ring-destructive/25')"
                @update:model-value="(value) => emit('update:newLabel', value ?? '')"
            />
            <span class="mt-1.5 block text-xs text-muted-foreground">Ajoutée une seule fois au référentiel du site, à l’enregistrement.</span>
        </template>
    </FormField>
</template>
