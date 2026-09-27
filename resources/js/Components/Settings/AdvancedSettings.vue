<script setup>
import { computed } from 'vue';
import { Save, Search } from 'lucide-vue-next';
import Select from '@/Components/Shadcn/Select.vue';
import AppearanceOptionIcon from '@/Components/Settings/AppearanceOptionIcon.vue';
import SettingsField from '@/Components/Settings/SettingsField.vue';
import SettingsSection from '@/Components/Settings/SettingsSection.vue';
import { CONTRASTS, DENSITIES, FONT_SIZES, MOTIONS, RADII } from '@/utilities/appearance';

/**
 * ADR-191 — les réglages « Avancé » du site : ce que tout le monde voit par
 * défaut. Chacun ajuste ensuite, dans « Mon profil », sa taille de texte, ses
 * animations et son contraste ; densité et arrondis restent ceux du site.
 * La valeur par défaut n'est jamais enregistrée : choisie, elle vide le champ.
 */
const props = defineProps({
    form: { type: Object, required: true },
    readonly: { type: Boolean, default: false },
});

/** « Compacte — champs et boutons plus bas » : la précision suit le nom, sans majuscule ni point. */
const lower = (text) => text.charAt(0).toLowerCase() + text.slice(1).replace(/\.$/, '');
const withHint = (options) => options.map((option) => ({ value: option.value, label: option.hint ? `${option.label} — ${lower(option.hint)}` : option.label }));

const FIELDS = [
    { field: 'ui_font_size', label: 'Taille du texte', fallback: '16', description: 'Agrandit ou réduit toute l’interface, comme un zoom.', options: FONT_SIZES.map((size) => ({ value: String(size), label: size === 16 ? '16 px (par défaut)' : `${size} px` })) },
    { field: 'ui_density', label: 'Densité', fallback: 'default', description: 'La hauteur des champs et des boutons.', options: withHint(DENSITIES) },
    { field: 'ui_radius', label: 'Arrondis', fallback: 'default', description: 'Les angles des cartes, des champs et des boutons.', options: RADII },
    { field: 'ui_motion', label: 'Animations', fallback: 'system', description: '« Réduites » coupe les glissements et les fondus.', options: withHint(MOTIONS) },
    { field: 'ui_contrast', label: 'Contraste', fallback: 'standard', description: 'Des bordures et un texte secondaire plus marqués.', options: CONTRASTS },
];

const valueOf = (item) => String(props.form[item.field] || item.fallback);
const choose = (item, value) => {
    props.form[item.field] = ! value || value === item.fallback ? '' : (item.field === 'ui_font_size' ? Number(value) : value);
};

const preview = computed(() => ({
    density: props.form.ui_density || 'default',
    radius: props.form.ui_radius || 'default',
}));
</script>

<template>
    <SettingsSection id="avance" title="Affichage avancé" description="Les valeurs par défaut du site. Chacun ajuste ensuite sa taille de texte, ses animations et son contraste dans « Mon profil ».">
        <div class="grid gap-6 sm:grid-cols-2 cq-4xl:grid-cols-3">
            <SettingsField v-for="item in FIELDS" :key="item.field" :label="item.label" :for="`reglage-${item.field}`" :description="item.description" :error="form.errors[item.field]">
                <Select :id="`reglage-${item.field}`" :model-value="valueOf(item)" :options="item.options" class="w-full" :disabled="readonly" @update:model-value="choose(item, $event)">
                    <template #leading="{ option }"><AppearanceOptionIcon :field="item.field" :value="option.value" /></template>
                </Select>
            </SettingsField>
        </div>

        <SettingsField label="Aperçu" description="Le texte, un champ et un bouton, avec ces réglages.">
            <!-- Densité et arrondis posés sur ce bloc seulement. -->
            <div class="rounded-md border border-border bg-muted/40 p-3">
                <div class="max-w-md rounded-lg border border-border bg-card p-3 shadow-sm" :data-density="preview.density" :data-radius="preview.radius" aria-label="Aperçu des réglages avancés">
                    <p class="mb-2.5 font-semibold text-foreground" :style="{ fontSize: `${(Number(form.ui_font_size) || 16) * 0.875}px` }">Aa · texte courant</p>
                    <div class="flex items-center gap-2">
                        <div class="flex h-[var(--control-h)] min-w-0 flex-1 items-center gap-2 rounded-lg border border-input bg-card px-3 text-sm text-muted-foreground"><Search class="h-4 w-4 shrink-0" />Rechercher</div>
                        <span class="inline-flex h-[var(--control-h)] shrink-0 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground"><Save class="h-4 w-4" />Enregistrer</span>
                    </div>
                </div>
            </div>
        </SettingsField>
    </SettingsSection>
</template>
