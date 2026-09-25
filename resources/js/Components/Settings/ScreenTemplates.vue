<script setup>
import { computed } from 'vue';
import { ImageIcon } from 'lucide-vue-next';
import SettingsAssetField from '@/Components/Settings/SettingsAssetField.vue';
import SettingsField from '@/Components/Settings/SettingsField.vue';
import SettingsSection from '@/Components/Settings/SettingsSection.vue';
import Label from '@/Components/Shadcn/Label.vue';
import RadioGroup from '@/Components/Shadcn/RadioGroup.vue';
import RadioGroupItem from '@/Components/Shadcn/RadioGroupItem.vue';

/**
 * ADR-184, ADR-191 — la disposition des pages de connexion et de « Mon profil ».
 *
 * Trois champs, une question chacun : quel modèle pour la connexion, quelle
 * image de fond, quel modèle pour « Mon profil ». Chaque modèle se choisit sur
 * un schéma, dans un groupe radio (flèches du clavier) ; la photo n'apparaît
 * qu'une fois, dans son champ. Seule la présentation change : formulaires,
 * droits et règles restent les mêmes.
 */
const props = defineProps({
    form: { type: Object, required: true },
    authTemplates: { type: Array, default: () => [] },
    profileTemplates: { type: Array, default: () => [] },
    colorPreview: { type: String, required: true },
    assets: { type: Object, default: () => ({}) },
    siteCode: { type: String, required: true },
    siteName: { type: String, default: '' },
    limits: { type: Object, default: () => ({}) },
    fallbackBackground: { type: String, default: '' },
    readonly: { type: Boolean, default: false },
});
const emit = defineEmits(['saved']);

const AUTH_HINTS = {
    COVER: 'image plein écran, formulaire sur une carte.',
    SPLIT: 'formulaire à gauche, image à droite.',
    CENTERED: 'carte centrée, sans image.',
};
const PROFILE_HINTS = {
    SIDEBAR: 'menu à gauche, section à droite.',
    BANNER: 'bandeau au nom, puis onglets.',
};

const chosenAuth = computed(() => props.authTemplates.find((option) => option.value === props.form.auth_template) ?? null);
const chosenProfile = computed(() => props.profileTemplates.find((option) => option.value === props.form.profile_template) ?? null);
const authUsesBackground = computed(() => chosenAuth.value?.uses_background ?? true);

/** Une teinte de la couleur principale, pour la zone « image » des schémas. */
const tint = (alpha) => `${props.colorPreview}${alpha}`;

/** Le choix d'un modèle, comme le sélecteur de thème de shadcn/ui : une vignette encadrée quand elle est choisie. */
const OPTION_CLASS = 'cursor-pointer [&:has([data-state=checked])>div]:border-primary [&:has(:focus-visible)>div]:ring-2 [&:has(:focus-visible)>div]:ring-ring/40 [&:has([data-disabled])]:cursor-not-allowed [&:has([data-disabled])]:opacity-60';
</script>

<template>
    <SettingsSection id="ecrans" title="Écrans & modèles" :description="`La disposition des pages de connexion et de « Mon profil » sur ${siteName}.`">
        <!-- 1 · Pages de connexion -->
        <SettingsField label="Pages de connexion" :description="chosenAuth ? `${chosenAuth.label} — ${AUTH_HINTS[chosenAuth.value]} S’applique à la connexion, au mot de passe oublié, à la réinitialisation et à l’activation.` : ''">
            <RadioGroup v-model="form.auth_template" :disabled="readonly" class="grid max-w-lg grid-cols-3 gap-4 pt-1" aria-label="Modèle des pages de connexion">
                <Label v-for="option in authTemplates" :key="option.value" :class="OPTION_CLASS">
                    <RadioGroupItem :value="option.value" class="sr-only" />
                    <div class="rounded-md border-2 border-border bg-card p-1 transition-colors hover:border-primary/40">
                        <span class="relative block h-16 w-full overflow-hidden rounded-sm bg-muted/60" aria-hidden="true">
                            <template v-if="option.value === 'COVER'">
                                <span class="absolute inset-0" :style="{ backgroundColor: tint('40') }" />
                                <ImageIcon class="absolute start-2 top-2 h-3.5 w-3.5 text-card/90" />
                                <span class="absolute bottom-[16%] end-[10%] top-[16%] w-[38%] rounded-sm bg-card p-1 shadow-sm">
                                    <span class="block h-1 rounded bg-muted" /><span class="mt-0.5 block h-1 rounded bg-muted" />
                                    <span class="mt-1 block h-1.5 rounded" :style="{ backgroundColor: colorPreview }" />
                                </span>
                            </template>
                            <template v-else-if="option.value === 'SPLIT'">
                                <span class="absolute inset-y-0 start-0 w-1/2 bg-card p-1.5 pt-3">
                                    <span class="block h-1 rounded bg-muted" /><span class="mt-0.5 block h-1 rounded bg-muted" />
                                    <span class="mt-1 block h-1.5 rounded" :style="{ backgroundColor: colorPreview }" />
                                </span>
                                <span class="absolute inset-y-0 end-0 grid w-1/2 place-items-center" :style="{ backgroundColor: tint('40') }">
                                    <ImageIcon class="h-3.5 w-3.5 text-card/90" />
                                </span>
                            </template>
                            <template v-else>
                                <span class="absolute inset-x-[26%] bottom-[14%] top-[14%] rounded-sm border border-border bg-card p-1 shadow-sm">
                                    <span class="block h-1 rounded bg-muted" /><span class="mt-0.5 block h-1 rounded bg-muted" />
                                    <span class="mt-1 block h-1.5 rounded" :style="{ backgroundColor: colorPreview }" />
                                </span>
                            </template>
                        </span>
                    </div>
                    <span class="block w-full p-2 text-center text-sm font-normal">{{ option.label }}</span>
                </Label>
            </RadioGroup>
        </SettingsField>

        <!-- 2 · Image de fond : une seule fois, là où elle se règle. -->
        <SettingsField label="Image de fond" :description="authUsesBackground ? 'Pour les modèles Couverture et Partagé. Une photo en paysage, d’au moins 1600 px de large.' : 'Le modèle Centré n’affiche pas d’image : celle-ci servira si vous changez de modèle.'">
            <SettingsAssetField
                kind="background"
                label="Image de fond"
                description="Pour les modèles Couverture et Partagé. Une photo en paysage, d’au moins 1600 px de large."
                :asset="assets?.background"
                :site-code="siteCode"
                :max-kb="limits.asset_max_kb?.background ?? 2048"
                :mimes="limits.asset_mimes?.background ?? 'jpg,jpeg,png,webp'"
                :readonly="readonly"
                shape="cover"
                compact
                hide-label
                :fallback-url="fallbackBackground"
                fallback-label="Image par défaut"
                @saved="emit('saved')"
            />
        </SettingsField>

        <!-- 3 · Page « Mon profil » -->
        <SettingsField label="Page « Mon profil »" :description="chosenProfile ? `${chosenProfile.label} — ${PROFILE_HINTS[chosenProfile.value]} La page où chacun lit son compte, ses droits et son apparence.` : ''">
            <RadioGroup v-model="form.profile_template" :disabled="readonly" class="grid max-w-lg grid-cols-3 gap-4 pt-1" aria-label="Modèle de la page Mon profil">
                <Label v-for="option in profileTemplates" :key="option.value" :class="OPTION_CLASS">
                    <RadioGroupItem :value="option.value" class="sr-only" />
                    <div class="rounded-md border-2 border-border bg-card p-1 transition-colors hover:border-primary/40">
                        <span class="relative block h-16 w-full overflow-hidden rounded-sm bg-muted/60 p-1.5" aria-hidden="true">
                            <template v-if="option.value === 'SIDEBAR'">
                                <span class="absolute inset-y-1.5 start-1.5 w-[28%] rounded-sm bg-card p-1">
                                    <span class="mx-auto block h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: colorPreview }" />
                                    <span class="mt-1 block h-1 rounded" :style="{ backgroundColor: tint('33') }" /><span class="mt-0.5 block h-1 rounded bg-muted" />
                                </span>
                                <span class="absolute inset-y-1.5 end-1.5 start-[34%] rounded-sm bg-card p-1">
                                    <span class="block h-1 w-1/3 rounded bg-muted" /><span class="mt-1 block h-3 rounded bg-muted/70" /><span class="mt-0.5 block h-3 rounded bg-muted/70" />
                                </span>
                            </template>
                            <template v-else>
                                <span class="absolute inset-x-1.5 top-1.5 h-[44%] overflow-hidden rounded-sm bg-card">
                                    <span class="block h-1/2" :style="{ backgroundColor: tint('55') }" />
                                    <span class="absolute start-1.5 top-[30%] block h-3 w-3 rounded-full border-2 border-card" :style="{ backgroundColor: colorPreview }" />
                                </span>
                                <span class="absolute inset-x-1.5 bottom-1.5 top-[54%] rounded-sm bg-card p-1">
                                    <span class="flex gap-1"><span class="block h-1 w-1/5 rounded" :style="{ backgroundColor: colorPreview }" /><span class="block h-1 w-1/5 rounded bg-muted" /><span class="block h-1 w-1/5 rounded bg-muted" /></span>
                                    <span class="mt-1 block h-2 rounded bg-muted/70" />
                                </span>
                            </template>
                        </span>
                    </div>
                    <span class="block w-full p-2 text-center text-sm font-normal">{{ option.label }}</span>
                </Label>
            </RadioGroup>
        </SettingsField>
    </SettingsSection>
</template>
