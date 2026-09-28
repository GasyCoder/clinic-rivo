<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    Check,
    FileUser,
    Handshake,
    Mail,
    MapPin,
    Pencil,
    Phone,
    Stethoscope,
    UserRound,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import AddressEntryField from '@/Components/Administration/AddressEntryField.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import PartnersPortalBar from '@/Components/Partners/PartnersPortalBar.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { partnerUrl, partnersContext } from '@/utilities/partnerUrl';

/**
 * ADR-211 (amendement du 2026-09-28) — créer ou modifier un partenaire sur sa
 * propre page, au lieu d'une fenêtre trop étroite pour deux colonnes. La même
 * page s'ouvre au site et au Super Admin depuis le portail ; le serveur
 * revérifie chaque geste. Sexe et date de naissance ne sont plus demandés :
 * l'accueil ne reprend plus une fiche pour ouvrir un dossier patient — un
 * partenaire se choisit à la prise en charge du passage. Omis, les anciennes
 * valeurs restent en base.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    /** La fiche à modifier, ou `null` pour en créer une. */
    partner: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
    professions: { type: Array, default: () => [] },
    /** Le référentiel d'adresses du site, servi avec `address_entries.view`. */
    addresses: { type: Array, default: () => [] },
});

const { can } = usePermissions();
const onPortal = computed(() => Boolean(partnersContext()));
const editing = computed(() => props.partner);
const back = partnerUrl();

const form = useForm({
    category: props.partner?.category ?? 'MEDICAL',
    last_name: props.partner?.last_name ?? '',
    first_name: props.partner?.first_name ?? '',
    profession: props.partner?.profession ?? '',
    profession_detail: props.partner?.profession_detail ?? '',
    name: props.partner?.category === 'OTHER' ? props.partner.name : '',
    phone: props.partner?.phone ?? '',
    email: props.partner?.email ?? '',
    address_entry_uuid: props.partner?.address_entry_uuid ?? '',
    new_address_label: '',
    notes: props.partner?.notes ?? '',
    active: props.partner?.active ?? true,
});

// L'adresse vient du référentiel du site ; sans le droit de le lire, le champ
// n'est ni montré ni envoyé — une fiche ne perd jamais son adresse en silence.
const canPickAddress = computed(() => can('address_entries.view'));
const addressMode = ref('existing');
const isMedical = computed(() => form.category === 'MEDICAL');
const categoryLocked = computed(() => Boolean(props.partner?.has_patient));

const CATEGORY_CARDS = {
    MEDICAL: { icon: Stethoscope, text: 'Une personne du monde de la santé : médecin, infirmier, laborantin…' },
    OTHER: { icon: Building2, text: 'Un organisme ou une personne : une école comme l’ISPSG, une entreprise…' },
};

const focusFirst = () => nextTick(() => document.getElementById(isMedical.value ? 'partner-last-name' : 'partner-name')?.focus());
onMounted(focusFirst);

const chooseCategory = (category) => {
    if (categoryLocked.value) return;
    form.category = category;
    form.clearErrors();
    focusFirst();
};

const canSubmit = computed(() => (isMedical.value
    ? form.last_name.trim() && form.profession && (form.profession !== 'OTHER' || form.profession_detail.trim())
    : form.name.trim()));

const submit = () => {
    const payload = ({ address_entry_uuid: entry, new_address_label: newLabel, ...data }) => ({
        ...data,
        profession: data.profession || null,
        active: editing.value ? data.active : undefined,
        // Une seule source d'adresse part : l'entrée choisie ou la nouvelle.
        ...(canPickAddress.value ? {
            address_entry_uuid: addressMode.value === 'existing' ? (entry || null) : null,
            new_address_label: addressMode.value === 'new' ? (newLabel.trim() || null) : null,
        } : {}),
    });

    if (editing.value) {
        form.transform(payload).put(partnerUrl(`/${editing.value.uuid}`));
        return;
    }

    form.transform(payload).post(partnerUrl());
};
</script>

<template>
    <Head :title="editing ? `Modifier ${editing.name}` : 'Nouveau partenaire'" />

    <div class="w-full space-y-5">
        <PartnersPortalBar />

        <PageHeader
            compact
            eyebrow="Référentiels · Partenaires"
            :title="editing ? `Modifier « ${editing.name} »` : 'Nouveau partenaire'"
            :description="editing ? 'Les passages déjà pris en charge gardent le nom qu’ils ont enregistré.' : 'Choisissez d’abord de quel partenaire il s’agit, puis renseignez sa fiche.'"
            :icon="editing ? Pencil : Handshake"
        >
            <template #actions>
                <Button :as="Link" :href="back" variant="outline" size="sm"><ArrowLeft class="h-4 w-4" />Retour aux partenaires</Button>
            </template>
        </PageHeader>

        <form id="partner-form" class="space-y-5" novalidate @submit.prevent="submit">
            <!-- Le type : il décide des champs de l'identité. -->
            <Card class="p-5 sm:p-6">
                <fieldset>
                    <legend class="mb-3 text-sm font-bold text-foreground">Type de partenaire</legend>
                    <div class="grid gap-3 md:grid-cols-2" role="radiogroup" aria-label="Type de partenaire">
                        <button
                            v-for="category in categories"
                            :key="category.value"
                            type="button"
                            role="radio"
                            :aria-checked="form.category === category.value"
                            :disabled="categoryLocked && form.category !== category.value"
                            :class="cn(
                                'flex items-start gap-3 rounded-xl border p-4 text-start transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                                form.category === category.value ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border hover:border-primary/40 hover:bg-accent/40',
                            )"
                            @click="chooseCategory(category.value)"
                        >
                            <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', form.category === category.value ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')">
                                <component :is="CATEGORY_CARDS[category.value]?.icon ?? Handshake" class="h-5 w-5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-bold text-foreground">{{ category.label }}</span>
                                <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">{{ CATEGORY_CARDS[category.value]?.text }}</span>
                            </span>
                            <Check v-if="form.category === category.value" class="h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                        </button>
                    </div>
                    <p v-if="categoryLocked" class="mt-3 flex items-center gap-2 text-xs text-muted-foreground">
                        <FileUser class="h-3.5 w-3.5 shrink-0" />Cette fiche est reliée au dossier patient de la même personne : elle reste un partenaire médical.
                    </p>
                    <p v-if="form.errors.category" class="mt-2 text-xs font-medium text-destructive">{{ form.errors.category }}</p>
                </fieldset>
            </Card>

            <!-- Deux colonnes : qui est le partenaire, puis comment le joindre. -->
            <div class="grid gap-5 xl:grid-cols-2">
                <Card class="p-5 sm:p-6">
                    <section class="grid content-start gap-4" aria-labelledby="partner-identity-title">
                        <h2 id="partner-identity-title" class="flex items-center gap-2 text-sm font-bold text-foreground">
                            <span class="grid h-8 w-8 place-items-center rounded-md bg-primary/10 text-primary"><component :is="isMedical ? UserRound : Building2" class="h-4 w-4" /></span>
                            {{ isMedical ? 'Identité' : 'Partenaire' }}
                        </h2>

                        <template v-if="isMedical">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <FormField label="Nom" required :error="form.errors.last_name">
                                    <IconInput id="partner-last-name" v-model="form.last_name" :icon="UserRound" maxlength="255" placeholder="Ex. RAKOTO" :aria-invalid="Boolean(form.errors.last_name)" />
                                </FormField>
                                <FormField label="Prénom" hint="(facultatif)" :error="form.errors.first_name">
                                    <IconInput v-model="form.first_name" :icon="UserRound" maxlength="255" placeholder="Ex. Fara" />
                                </FormField>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <FormField label="Métier" required as="div" :error="form.errors.profession">
                                    <Select v-model="form.profession" :options="professions" :icon="Stethoscope" placeholder="Choisir un métier" class="w-full" aria-label="Métier" />
                                </FormField>
                                <FormField v-if="form.profession === 'OTHER'" label="Précisez le métier" required :error="form.errors.profession_detail">
                                    <IconInput v-model="form.profession_detail" :icon="Stethoscope" maxlength="100" placeholder="Ex. Kinésithérapeute" />
                                </FormField>
                            </div>
                            <p v-if="editing?.has_patient" class="flex flex-wrap items-center gap-2 text-xs">
                                <Link v-if="editing.patient && ! onPortal" :href="`/patients/${editing.patient.uuid}`" class="inline-flex items-center gap-1 font-semibold text-primary hover:underline">
                                    <FileUser class="h-3.5 w-3.5" />Dossier patient {{ editing.patient.patient_number }}
                                </Link>
                                <span v-else class="inline-flex items-center gap-1 text-muted-foreground"><FileUser class="h-3.5 w-3.5" />A un dossier patient{{ editing.patient ? ` · ${editing.patient.patient_number}` : '' }}</span>
                            </p>
                        </template>

                        <FormField v-else label="Nom ou identité" required :error="form.errors.name">
                            <IconInput id="partner-name" v-model="form.name" :icon="Building2" maxlength="255" placeholder="Ex. ISPSG, une entreprise, une personne…" :aria-invalid="Boolean(form.errors.name)" />
                        </FormField>
                    </section>
                </Card>

                <Card class="p-5 sm:p-6">
                    <section class="grid content-start gap-4" aria-labelledby="partner-contact-title">
                        <h2 id="partner-contact-title" class="flex items-center gap-2 text-sm font-bold text-foreground">
                            <span class="grid h-8 w-8 place-items-center rounded-md bg-cyan-50 text-cyan-700 dark:bg-cyan-950/40 dark:text-cyan-300"><Phone class="h-4 w-4" /></span>
                            Coordonnées
                        </h2>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField label="Téléphone" hint="(facultatif)" :error="form.errors.phone">
                                <IconInput v-model="form.phone" :icon="Phone" type="tel" maxlength="40" placeholder="Ex. 034 00 000 00" />
                            </FormField>
                            <FormField label="Email" hint="(facultatif)" :error="form.errors.email">
                                <IconInput v-model="form.email" :icon="Mail" type="email" maxlength="255" placeholder="contact@exemple.mg" />
                            </FormField>
                        </div>
                        <AddressEntryField
                            v-if="canPickAddress"
                            v-model:entry="form.address_entry_uuid"
                            v-model:new-label="form.new_address_label"
                            v-model:mode="addressMode"
                            :addresses="addresses"
                            :can-create="can('address_entries.create')"
                            hint="(facultatif)"
                            :error="form.errors.address_entry_uuid || form.errors.new_address_label"
                        />
                        <p v-else-if="editing?.address" class="flex items-center gap-2 text-sm text-muted-foreground"><MapPin class="h-4 w-4 shrink-0" />{{ editing.address }}</p>
                        <FormField label="Remarque" hint="(facultatif)" :error="form.errors.notes">
                            <Textarea v-model="form.notes" :rows="4" maxlength="2000" placeholder="Convention, personne à joindre…" />
                        </FormField>

                        <label v-if="editing" for="partner-active" class="flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-muted/40 px-3.5 py-3">
                            <Checkbox id="partner-active" v-model="form.active" class="mt-0.5" />
                            <span>
                                <span class="block text-sm font-semibold text-foreground">Proposé à l’accueil</span>
                                <span class="block text-xs leading-5 text-muted-foreground">Décoché, les passages qui le citent le gardent, mais l’accueil ne le propose plus.</span>
                            </span>
                        </label>
                    </section>
                </Card>
            </div>

            <!-- Le pied, toujours à portée même sur un petit écran. -->
            <div class="sticky bottom-0 z-10 flex flex-col-reverse gap-2 rounded-xl border border-border bg-card/95 px-5 py-3 shadow-sm backdrop-blur sm:flex-row sm:items-center sm:justify-end">
                <p v-if="! canSubmit" class="me-auto text-xs text-muted-foreground">
                    {{ isMedical ? 'Le nom et le métier sont obligatoires.' : 'Le nom ou l’identité est obligatoire.' }}
                </p>
                <Button :as="Link" :href="back" variant="outline">Annuler</Button>
                <Button type="submit" :disabled="form.processing || ! canSubmit">
                    <Check class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Ajouter le partenaire' }}
                </Button>
            </div>
        </form>
    </div>
</template>
