<script setup>
import { ChartColumn, CreditCard, Hash, Landmark, Mail, MapPin, Phone } from 'lucide-vue-next';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import SettingsField from '@/Components/Settings/SettingsField.vue';
import SettingsSection from '@/Components/Settings/SettingsSection.vue';

/** L'identité légale imprimée sur les factures, reçus et documents d'un site (ADR-184). */
defineProps({
    form: { type: Object, required: true },
    fallbacks: { type: Object, default: () => ({}) },
    siteName: { type: String, default: '' },
    readonly: { type: Boolean, default: false },
});
</script>

<template>
    <SettingsSection id="legal" title="Identité légale" :description="`Imprimée sur les factures, reçus et documents — ${siteName}. Un champ vide garde la valeur de la configuration du site, indiquée en gris.`">
        <SettingsField label="Adresse" for="reglage-legal-address" description="L’adresse imprimée sous le nom de l’établissement." :error="form.errors.legal_address">
            <IconInput id="reglage-legal-address" :icon="MapPin" v-model="form.legal_address" :placeholder="fallbacks.legal_address || 'Adresse de l’établissement'" :disabled="readonly" maxlength="255" />
        </SettingsField>
        <div class="grid gap-6 sm:grid-cols-2 cq-4xl:grid-cols-3">
            <SettingsField label="NIF" for="reglage-legal-nif" description="Numéro d’identification fiscale." :error="form.errors.legal_nif">
                <IconInput id="reglage-legal-nif" :icon="Hash" v-model="form.legal_nif" :placeholder="fallbacks.legal_nif || 'Numéro d’identification fiscale'" :disabled="readonly" maxlength="40" />
            </SettingsField>
            <SettingsField label="STAT" for="reglage-legal-stat" description="Numéro statistique." :error="form.errors.legal_stat">
                <IconInput id="reglage-legal-stat" :icon="ChartColumn" v-model="form.legal_stat" :placeholder="fallbacks.legal_stat || 'Numéro statistique'" :disabled="readonly" maxlength="40" />
            </SettingsField>
            <SettingsField label="Téléphone" for="reglage-legal-phone" :error="form.errors.legal_phone">
                <IconInput id="reglage-legal-phone" :icon="Phone" v-model="form.legal_phone" type="tel" :placeholder="fallbacks.legal_phone || 'Ex. 020 00 000 00'" :disabled="readonly" maxlength="40" />
            </SettingsField>
            <SettingsField label="Email" for="reglage-legal-email" :error="form.errors.legal_email">
                <IconInput id="reglage-legal-email" :icon="Mail" v-model="form.legal_email" type="email" :placeholder="fallbacks.legal_email || 'contact@exemple.mg'" :disabled="readonly" maxlength="150" />
            </SettingsField>
            <SettingsField label="Banque" for="reglage-bank-name" :error="form.errors.bank_name">
                <IconInput id="reglage-bank-name" :icon="Landmark" v-model="form.bank_name" placeholder="Nom de la banque" :disabled="readonly" maxlength="150" />
            </SettingsField>
            <SettingsField label="N° de compte bancaire" for="reglage-bank-account" description="S’imprime sur les factures dès qu’il est renseigné." :error="form.errors.bank_account">
                <IconInput id="reglage-bank-account" :icon="CreditCard" v-model="form.bank_account" class="font-mono" placeholder="Numéro de compte ou RIB" :disabled="readonly" maxlength="60" />
            </SettingsField>
        </div>
    </SettingsSection>
</template>
