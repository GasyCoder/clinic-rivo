<script setup>
import { BriefcaseBusiness, UserRound } from 'lucide-vue-next';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import SettingsAssetField from '@/Components/Settings/SettingsAssetField.vue';
import SettingsField from '@/Components/Settings/SettingsField.vue';
import SettingsSection from '@/Components/Settings/SettingsSection.vue';

/** Le directeur général et sa signature, apposée sur les documents RH (ADR-184). */
defineProps({
    form: { type: Object, required: true },
    fallbacks: { type: Object, default: () => ({}) },
    assets: { type: Object, default: () => ({}) },
    siteCode: { type: String, required: true },
    limits: { type: Object, default: () => ({}) },
    readonly: { type: Boolean, default: false },
});
</script>

<template>
    <SettingsSection id="direction" title="Direction" description="Le directeur général signe les documents administratifs des RH (attestations, contrats…). La signature est copiée dans chaque document au moment où il est produit.">
        <div class="grid gap-6 sm:grid-cols-2">
            <SettingsField label="Nom du directeur général" for="reglage-director-name" :error="form.errors.director_name">
                <IconInput id="reglage-director-name" :icon="UserRound" v-model="form.director_name" placeholder="Prénom et nom" :disabled="readonly" maxlength="150" />
            </SettingsField>
            <SettingsField label="Titre" for="reglage-director-title" :description="`Sous le nom, au bas des documents. Vide : « ${fallbacks.director_title || 'Directeur général'} ».`" :error="form.errors.director_title">
                <IconInput id="reglage-director-title" :icon="BriefcaseBusiness" v-model="form.director_title" :placeholder="fallbacks.director_title || 'Directeur général'" :disabled="readonly" maxlength="150" />
            </SettingsField>
        </div>
        <SettingsField label="Signature" description="Une image PNG à fond transparent. Elle n’a aucune adresse publique ; la remplacer ne change aucun document déjà produit.">
            <SettingsAssetField
                kind="signature"
                label="Signature"
                :asset="assets?.signature"
                :site-code="siteCode"
                :max-kb="limits.asset_max_kb?.signature ?? 512"
                :mimes="limits.asset_mimes?.signature ?? 'png,jpg,jpeg,webp'"
                :readonly="readonly"
                shape="paper"
                compact
                hide-label
            />
        </SettingsField>
    </SettingsSection>
</template>
