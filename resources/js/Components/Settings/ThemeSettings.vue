<script setup>
import { computed, ref, watch } from 'vue';
import { Check, ClipboardCopy, Download, Moon, Save, Sun, TriangleAlert, Upload } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Tabs from '@/Components/Shadcn/Tabs.vue';
import TabsContent from '@/Components/Shadcn/TabsContent.vue';
import TabsList from '@/Components/Shadcn/TabsList.vue';
import TabsTrigger from '@/Components/Shadcn/TabsTrigger.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import ColorField from '@/Components/Settings/ColorField.vue';
import SettingsField from '@/Components/Settings/SettingsField.vue';
import SettingsSection from '@/Components/Settings/SettingsSection.vue';
import ThemeSwatch from '@/Components/Settings/ThemeSwatch.vue';
import { useToastStore } from '@/stores/toast';
import {
    COLOR_FIELDS,
    MIN_TEXT_CONTRAST,
    THEME_FIELDS,
    contrastBetween,
    detectPreset,
    effectiveColors,
    exportTheme,
    parseThemeImport,
    presetValues,
    previewTokens,
    tripletToHex,
} from '@/utilities/themePalette';

/**
 * ADR-191 — le thème d'un site : un préréglage, puis pour chaque mode (clair,
 * sombre) la couleur d'accentuation, l'arrière-plan et l'avant-plan. Modifier
 * une couleur fait passer le thème en « Personnalisé ». Le serveur refuse un
 * texte illisible (contraste < 4,5) ; l'écran le dit avant.
 */
const props = defineProps({
    form: { type: Object, required: true },
    presets: { type: Object, default: () => ({}) },
    readonly: { type: Boolean, default: false },
    siteName: { type: String, default: '' },
});

const toast = useToastStore();
/** Un onglet par mode : ses trois couleurs, son aperçu et la lisibilité de son texte. */
const MODES = [
    { mode: 'light', label: 'Mode clair', icon: Sun, description: 'Accentuation, arrière-plan et avant-plan quand l’interface est claire. Les couleurs d’alerte (rouge, ambre, vert) ne changent pas.' },
    { mode: 'dark', label: 'Mode sombre', icon: Moon, description: 'Les mêmes couleurs quand l’interface est sombre. Les couleurs d’alerte (rouge, ambre, vert) ne changent pas.' },
];
const activeMode = ref('light');

const presetOptions = computed(() => [
    ...Object.entries(props.presets).map(([value, preset]) => ({ value, label: preset.label })),
    { value: 'custom', label: 'Personnalisé', disabled: true },
]);

/**
 * La vignette de chaque thème de la liste : ses couleurs clair / sombre. « Personnalisé »
 * montre celles réellement réglées — l'accent sombre non réglé, tel qu'il sera déduit.
 */
const swatchOf = (key) => {
    const preset = props.presets[key];
    if (preset) return { light: preset.light, dark: preset.dark };

    const light = effectiveColors(props.form, 'light');
    const dark = effectiveColors(props.form, 'dark');

    return {
        light: { primary: light.primary ?? light.lightPrimary, background: light.background },
        dark: { primary: derivedDarkPrimary.value, background: dark.background },
    };
};

const choosePreset = (key) => {
    if (! key || key === 'custom') return;
    Object.assign(props.form, presetValues(key, props.presets));
    props.form.theme_preset = key;
};

// Toute retouche d'une couleur dit si le thème reste un préréglage ou devient personnalisé.
watch(() => COLOR_FIELDS.map((field) => props.form[field]).join('|'), () => {
    props.form.theme_preset = detectPreset(props.form, props.presets);
});

const ROLES = [
    { role: 'primary', label: 'Accentuation', hint: 'Boutons, liens, sélection.' },
    { role: 'background', label: 'Arrière-plan', hint: 'Le fond des pages.' },
    { role: 'foreground', label: 'Avant-plan', hint: 'Le texte ; les surfaces en découlent.' },
];

const fieldOf = (mode, role) => THEME_FIELDS[mode][role];

/** Vide, la couleur d'origine s'applique — sauf l'accent sombre, déduit de celui du mode clair : on montre celui qui s'affichera. */
const derivedDarkPrimary = computed(() => tripletToHex(previewTokens(props.form, 'dark')['--primary']));
const fallbackOf = (mode, role) => (mode === 'dark' && role === 'primary' ? derivedDarkPrimary.value : props.presets.rivo?.[mode]?.[role] ?? '');
const fallbackLabelOf = (mode, role) => (mode === 'dark' && role === 'primary' ? 'déduite du mode clair' : 'd’origine');
const errorOf = (mode, role) => props.form.errors?.[fieldOf(mode, role)] ?? '';

/** Pour chaque mode : ses variables d'aperçu et la lisibilité de son texte. */
const palettes = computed(() => Object.fromEntries(MODES.map(({ mode }) => {
    const colors = effectiveColors(props.form, mode);
    const contrast = contrastBetween(colors.background, colors.foreground);

    return [mode, { tokens: previewTokens(props.form, mode), contrast, readable: contrast >= MIN_TEXT_CONTRAST }];
})));
const ratio = (value) => value.toFixed(1).replace('.', ',');

/* Export, copie, import : le thème voyage en JSON, entre sites ou vers une sauvegarde. */
const themeName = computed(() => props.presets[props.form.theme_preset]?.label ?? 'Personnalisé');
const themeJson = () => JSON.stringify(exportTheme(props.form, themeName.value), null, 2);

const download = () => {
    const blob = new Blob([themeJson()], { type: 'application/json' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `theme-${(props.siteName || 'rivo').toLowerCase().replace(/[^a-z0-9]+/g, '-')}.json`;
    link.click();
    URL.revokeObjectURL(link.href);
};

const copy = async () => {
    try {
        await navigator.clipboard.writeText(themeJson());
        toast.success('Thème copié : collez-le dans « Importer » sur un autre site.');
    } catch {
        toast.error('La copie a été refusée par le navigateur : utilisez « Exporter ».');
    }
};

const importOpen = ref(false);
const importText = ref('');
const importError = ref('');
const openImport = () => {
    importText.value = '';
    importError.value = '';
    importOpen.value = true;
};
const readFile = async (event) => {
    const file = event.target.files?.[0];
    if (file) importText.value = await file.text();
};
const applyImport = () => {
    const parsed = parseThemeImport(importText.value);
    if (! parsed.ok) {
        importError.value = parsed.error;

        return;
    }
    Object.assign(props.form, parsed.values);
    importOpen.value = false;
    toast.success(`Thème ${parsed.name ? `« ${parsed.name} » ` : ''}importé : vérifiez-le puis enregistrez.`);
};
</script>

<template>
    <SettingsSection id="theme" title="Thème" :description="`Les couleurs du mode clair et du mode sombre sur ${siteName}.`">
        <template #actions>
            <Button type="button" variant="outline" size="sm" title="Télécharger le thème (fichier JSON)" @click="download"><Download class="h-4 w-4" />Exporter</Button>
            <Button type="button" variant="outline" size="sm" title="Copier le thème, pour le coller sur un autre site" @click="copy"><ClipboardCopy class="h-4 w-4" />Copier</Button>
            <Button v-if="! readonly" type="button" variant="outline" size="sm" title="Importer un thème exporté depuis un autre site" @click="openImport"><Upload class="h-4 w-4" />Importer</Button>
        </template>

        <SettingsField label="Thème de départ" for="reglage-theme-preset" description="Un point de départ : chaque couleur reste modifiable, et une retouche le fait passer en « Personnalisé ».">
            <Select id="reglage-theme-preset" :model-value="form.theme_preset || 'custom'" :options="presetOptions" placeholder="Personnalisé" class="w-full sm:w-72" :disabled="readonly" @update:model-value="choosePreset">
                <template #leading="{ option }"><ThemeSwatch :colors="swatchOf(option.value)" /></template>
            </Select>
        </SettingsField>

        <Tabs v-model="activeMode">
            <TabsList class="grid w-full grid-cols-2 sm:w-80" aria-label="Mode réglé">
                <TabsTrigger v-for="item in MODES" :key="item.mode" :value="item.mode">
                    <component :is="item.icon" class="h-4 w-4" />{{ item.label }}
                    <span v-if="! palettes[item.mode].readable" class="h-1.5 w-1.5 rounded-full bg-destructive" aria-label="contraste insuffisant" />
                </TabsTrigger>
            </TabsList>

            <TabsContent v-for="item in MODES" :key="item.mode" :value="item.mode">
                <p class="mb-6 text-sm text-muted-foreground">{{ item.description }}</p>
                <div class="grid gap-8 md:grid-cols-2">
                    <div class="space-y-6">
                        <SettingsField
                            v-for="role in ROLES"
                            :key="role.role"
                            :label="role.label"
                            :for="`reglage-${fieldOf(item.mode, role.role)}`"
                            :description="role.hint"
                            :error="errorOf(item.mode, role.role)"
                        >
                            <ColorField
                                :id="`reglage-${fieldOf(item.mode, role.role)}`"
                                v-model="form[fieldOf(item.mode, role.role)]"
                                :label="`${role.label} (${item.mode === 'light' ? 'clair' : 'sombre'})`"
                                :fallback="fallbackOf(item.mode, role.role)"
                                :fallback-label="fallbackLabelOf(item.mode, role.role)"
                                :invalid="Boolean(errorOf(item.mode, role.role))"
                                :disabled="readonly"
                            />
                        </SettingsField>
                    </div>

                    <SettingsField label="Aperçu">
                        <!-- Aperçu du mode : ses variables sont posées sur ce bloc seulement. -->
                        <div class="overflow-hidden rounded-md border border-border" :style="palettes[item.mode].tokens" aria-hidden="true">
                            <div class="bg-background p-3 text-foreground">
                                <div class="rounded-md border border-border bg-card p-3 text-card-foreground shadow-sm">
                                    <p class="flex items-center gap-1.5 text-sm font-semibold"><component :is="item.icon" class="h-4 w-4 text-primary" />Dossier patient</p>
                                    <p class="mt-0.5 text-xs text-muted-foreground">Texte secondaire, atténué.</p>
                                    <span class="mt-3 block h-8 rounded-md border border-input bg-card px-2.5 text-xs leading-8 text-muted-foreground">Rechercher un patient…</span>
                                    <div class="mt-3 flex flex-wrap items-center gap-1.5">
                                        <span class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-semibold text-primary-foreground"><Save class="h-3.5 w-3.5" />Enregistrer</span>
                                        <span class="inline-flex h-8 items-center rounded-md bg-accent px-3 text-xs font-semibold text-accent-foreground">Sélection</span>
                                        <span class="inline-flex h-8 items-center rounded-md bg-muted px-3 text-xs text-muted-foreground">Atténué</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p :class="['flex items-start gap-1.5 text-[0.8rem] leading-5', palettes[item.mode].readable ? 'text-muted-foreground' : 'font-medium text-destructive']" role="status">
                            <Check v-if="palettes[item.mode].readable" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /><TriangleAlert v-else class="mt-0.5 h-4 w-4 shrink-0" />
                            <span v-if="palettes[item.mode].readable">Texte lisible : contraste {{ ratio(palettes[item.mode].contrast) }} (au moins 4,5).</span>
                            <span v-else>Texte illisible : contraste {{ ratio(palettes[item.mode].contrast) }}, il faut au moins 4,5. L’enregistrement sera refusé.</span>
                        </p>
                    </SettingsField>
                </div>
            </TabsContent>
        </Tabs>

        <Dialog :open="importOpen" title="Importer un thème" description="Collez un thème exporté depuis un autre site, ou choisissez son fichier JSON. Rien n’est enregistré avant « Enregistrer »." @update:open="importOpen = $event">
            <div class="space-y-4">
                <SettingsField label="Fichier" for="reglage-theme-fichier">
                    <input id="reglage-theme-fichier" type="file" accept="application/json,.json" class="block w-full text-sm text-muted-foreground file:me-3 file:h-8 file:rounded-md file:border file:border-input file:bg-background file:px-3 file:text-sm file:font-medium file:text-foreground" @change="readFile" />
                </SettingsField>
                <SettingsField label="Ou collez le thème" for="reglage-theme-json" :error="importError">
                    <Textarea id="reglage-theme-json" v-model="importText" rows="8" spellcheck="false" class="font-mono text-xs" placeholder='{ "rivo_theme": 1, "light": { … }, "dark": { … } }' />
                </SettingsField>
            </div>
            <template #footer>
                <Button type="button" variant="outline" @click="importOpen = false">Annuler</Button>
                <Button type="button" :disabled="! importText.trim()" @click="applyImport">Appliquer ce thème</Button>
            </template>
        </Dialog>
    </SettingsSection>
</template>
