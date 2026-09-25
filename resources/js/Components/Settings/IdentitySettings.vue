<script setup>
import { computed } from 'vue';
import { AppWindow, Quote } from 'lucide-vue-next';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import SettingsAssetField from '@/Components/Settings/SettingsAssetField.vue';
import SettingsField from '@/Components/Settings/SettingsField.vue';
import SettingsSection from '@/Components/Settings/SettingsSection.vue';
import { monogramOf } from '@/lib/brand';

/**
 * Identité d'un site (ADR-184) : nom de l'application, devise, logo et icône,
 * avec l'aperçu de la barre latérale et de la page de connexion. Le texte part
 * avec « Enregistrer » ; un fichier s'enregistre seul, depuis son champ.
 */
const props = defineProps({
    form: { type: Object, required: true },
    fallbacks: { type: Object, default: () => ({}) },
    assets: { type: Object, default: () => ({}) },
    siteName: { type: String, default: '' },
    siteCode: { type: String, required: true },
    isPortal: { type: Boolean, default: false },
    limits: { type: Object, default: () => ({}) },
    colorPreview: { type: String, required: true },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['saved']);

const brandPreview = computed(() => String(props.form.app_name ?? '').trim() || props.fallbacks.app_name || 'Clinique Saint Georges');
const iconPreview = computed(() => props.assets?.icon?.data_url ?? null);
const taglinePreview = computed(() => String(props.form.app_tagline ?? '').trim() || props.fallbacks.app_tagline || '');
const placeLabel = computed(() => (props.isPortal ? 'Super Administration' : props.siteName));
</script>

<template>
    <SettingsSection id="identite" title="Identité" :description="`Le nom de l’application, sa devise, son logo et son icône sur ${siteName}.`">
        <div class="grid gap-8 cq-4xl:grid-cols-2">
            <SettingsField
                label="Nom de l’application"
                for="reglage-app-name"
                :description="`Titre des onglets, barre latérale, page de connexion et documents.${fallbacks.app_name ? ` Laissé vide : « ${fallbacks.app_name} ».` : ''}`"
                :error="form.errors.app_name"
            >
                <IconInput id="reglage-app-name" :icon="AppWindow" v-model="form.app_name" :placeholder="fallbacks.app_name || 'Clinique Saint Georges'" :disabled="readonly" maxlength="80" />
            </SettingsField>

            <SettingsField
                label="Devise ou slogan"
                for="reglage-app-tagline"
                :description="`Affichée sous le nom, sur la page de connexion (${String(form.app_tagline ?? '').length} / 150).${fallbacks.app_tagline ? ` Laissée vide : « ${fallbacks.app_tagline} ».` : ''}`"
                :error="form.errors.app_tagline"
            >
                <IconInput id="reglage-app-tagline" :icon="Quote" v-model="form.app_tagline" :placeholder="fallbacks.app_tagline || 'Ex. Ny fahasalamana no loharanon-karena'" :disabled="readonly" maxlength="150" />
            </SettingsField>
        </div>

        <div class="grid gap-8 sm:grid-cols-2">
            <SettingsField label="Logo" description="Page de connexion, factures, reçus et documents. Horizontal, sur fond clair.">
                <SettingsAssetField
                    kind="logo"
                    label="Logo"
                    :asset="assets?.logo"
                    :site-code="siteCode"
                    :max-kb="limits.asset_max_kb?.logo ?? 1024"
                    :mimes="limits.asset_mimes?.logo ?? 'png,jpg,jpeg,webp'"
                    :readonly="readonly"
                    shape="wide"
                    compact
                    hide-label
                    @saved="emit('saved')"
                />
            </SettingsField>

            <SettingsField label="Icône (favicon)" description="Onglet du navigateur et barre latérale. Carrée ; sans elle, les initiales.">
                <SettingsAssetField
                    kind="icon"
                    label="Icône (favicon)"
                    :asset="assets?.icon"
                    :site-code="siteCode"
                    :max-kb="limits.asset_max_kb?.icon ?? 512"
                    :mimes="limits.asset_mimes?.icon ?? 'png,ico,webp'"
                    :readonly="readonly"
                    shape="square"
                    compact
                    hide-label
                    @saved="emit('saved')"
                />
            </SettingsField>
        </div>

        <SettingsField label="Aperçu" description="La barre latérale et la page de connexion avec ces réglages.">
            <div class="grid gap-3 rounded-md border border-border bg-muted/40 p-3 sm:grid-cols-2" aria-label="Aperçu de la barre latérale et de la page de connexion">
                <div class="flex min-w-0 items-center gap-2.5 rounded-md border border-border bg-card px-3 py-3 shadow-sm">
                    <img v-if="iconPreview" :src="iconPreview" alt="" class="h-9 w-9 shrink-0 rounded-md bg-card object-contain ring-1 ring-border" />
                    <span v-else class="grid h-9 w-9 shrink-0 place-items-center rounded-md text-[11px] font-bold uppercase text-white" :style="{ backgroundColor: colorPreview }">{{ monogramOf(brandPreview) }}</span>
                    <span class="flex min-w-0 flex-col">
                        <span class="truncate text-sm font-semibold text-foreground">{{ brandPreview }}</span>
                        <span class="truncate text-xs text-muted-foreground">{{ placeLabel }}</span>
                    </span>
                </div>
                <div class="flex min-w-0 flex-col justify-center rounded-md border border-border bg-card px-3 py-3 shadow-sm">
                    <p class="truncate text-xs text-muted-foreground">{{ brandPreview }} · {{ placeLabel }}</p>
                    <p v-if="taglinePreview" class="mt-0.5 text-sm font-medium leading-snug text-foreground">{{ taglinePreview }}</p>
                    <p v-else class="mt-0.5 text-sm italic text-muted-foreground">Aucune devise affichée.</p>
                </div>
            </div>
        </SettingsField>
    </SettingsSection>
</template>
