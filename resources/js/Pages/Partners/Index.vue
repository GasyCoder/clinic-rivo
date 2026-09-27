<script setup>
import { computed, nextTick, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Archive,
    Building2,
    Check,
    CircleOff,
    FileUser,
    Handshake,
    Layers,
    Mail,
    MapPin,
    Pencil,
    Phone,
    Plus,
    RotateCcw,
    Search,
    Stethoscope,
    UserRound,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import AddressEntryField from '@/Components/Administration/AddressEntryField.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
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
 * ADR-211 — le module Partenaires d'un site.
 *
 * Deux sortes de fiches : Médical (une personne du monde de la santé, qui peut
 * venir se faire soigner — l'accueil reprend alors sa fiche sans ressaisie) et
 * Autre (un organisme ou une personne : l'ISPSG, une entreprise). Le même écran
 * s'ouvre au site et au Super Admin depuis le portail ; le serveur revérifie
 * chaque geste. Un partenaire ne couvre encore aucun montant.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    partners: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    professions: { type: Array, default: () => [] },
    /** Le référentiel d'adresses du site, servi avec `address_entries.view`. */
    addresses: { type: Array, default: () => [] },
});

const { can } = usePermissions();
const onPortal = computed(() => Boolean(partnersContext()));

/* ------------------------------------------------------------------ */
/* Filtres : la liste est servie entière, le filtre ne recharge rien.  */
/* ------------------------------------------------------------------ */

const query = ref('');
const filter = ref('current');

const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const counts = computed(() => ({
    current: props.partners.filter((partner) => ! partner.archived).length,
    MEDICAL: props.partners.filter((partner) => ! partner.archived && partner.category === 'MEDICAL').length,
    OTHER: props.partners.filter((partner) => ! partner.archived && partner.category === 'OTHER').length,
    archived: props.partners.filter((partner) => partner.archived).length,
}));
const cards = [
    { value: 'current', label: 'En service', hint: 'médicaux et autres', icon: Layers, tone: 'bg-primary/10 text-primary' },
    { value: 'MEDICAL', label: 'Médicaux', hint: 'médecins, infirmiers…', icon: Stethoscope, tone: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300' },
    { value: 'OTHER', label: 'Autres', hint: 'écoles, entreprises…', icon: Building2, tone: 'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300' },
    { value: 'archived', label: 'Archivés', hint: 'restaurables', icon: Archive, tone: 'bg-muted text-muted-foreground' },
];

const shown = computed(() => {
    const terms = normalize(query.value).split(/\s+/).filter(Boolean);

    return props.partners.filter((partner) => {
        if (filter.value === 'archived') {
            if (! partner.archived) return false;
        } else if (partner.archived) {
            return false;
        } else if (filter.value !== 'current' && partner.category !== filter.value) {
            return false;
        }

        const haystack = normalize(`${partner.name} ${partner.profession_label ?? ''} ${partner.phone ?? ''} ${partner.email ?? ''} ${partner.address ?? ''}`);

        return terms.every((term) => haystack.includes(term));
    });
});

const initials = (partner) => String(partner.name ?? '')
    .split(/\s+/).filter(Boolean).slice(0, 2).map((word) => word[0]).join('').toUpperCase() || '?';

/* ------------------------------------------------------------------ */
/* Créer et modifier                                                   */
/* ------------------------------------------------------------------ */

const editing = ref(null);
const dialogOpen = ref(false);
const blank = {
    category: 'MEDICAL',
    last_name: '',
    first_name: '',
    profession: '',
    profession_detail: '',
    sex: '',
    birth_date: '',
    name: '',
    phone: '',
    email: '',
    address_entry_uuid: '',
    new_address_label: '',
    notes: '',
    active: true,
};
const form = useForm({ ...blank });
// L'adresse vient du référentiel du site ; sans le droit de le lire, le champ
// n'est ni montré ni envoyé — une fiche ne perd jamais son adresse en silence.
const canPickAddress = computed(() => can('address_entries.view'));
const addressMode = ref('existing');
const isMedical = computed(() => form.category === 'MEDICAL');
const categoryLocked = computed(() => Boolean(editing.value?.has_patient));

const professionOptions = computed(() => props.professions);
const sexOptions = [
    { value: '', label: 'Non renseigné' },
    { value: 'F', label: 'Féminin' },
    { value: 'M', label: 'Masculin' },
];
const CATEGORY_CARDS = {
    MEDICAL: { icon: Stethoscope, text: 'Une personne du monde de la santé : médecin, infirmier, laborantin… Elle peut venir se faire soigner.' },
    OTHER: { icon: Building2, text: 'Un organisme ou une personne : une école comme l’ISPSG, une entreprise…' },
};

const focusFirst = () => nextTick(() => document.getElementById(isMedical.value ? 'partner-last-name' : 'partner-name')?.focus());
const openCreate = () => {
    editing.value = null;
    form.defaults({ ...blank });
    form.reset();
    form.clearErrors();
    addressMode.value = 'existing';
    dialogOpen.value = true;
    focusFirst();
};
const openEdit = (partner) => {
    editing.value = partner;
    form.clearErrors();
    Object.assign(form, {
        category: partner.category,
        last_name: partner.last_name ?? '',
        first_name: partner.first_name ?? '',
        profession: partner.profession ?? '',
        profession_detail: partner.profession_detail ?? '',
        sex: partner.sex ?? '',
        birth_date: partner.birth_date ?? '',
        name: partner.category === 'OTHER' ? partner.name : '',
        phone: partner.phone ?? '',
        email: partner.email ?? '',
        address_entry_uuid: partner.address_entry_uuid ?? '',
        new_address_label: '',
        notes: partner.notes ?? '',
        active: partner.active,
    });
    addressMode.value = 'existing';
    dialogOpen.value = true;
    focusFirst();
};
const chooseCategory = (category) => {
    if (categoryLocked.value) return;
    form.category = category;
    form.clearErrors();
    focusFirst();
};
const closeDialog = () => {
    if (form.processing) return;
    dialogOpen.value = false;
};
const canSubmit = computed(() => (isMedical.value
    ? form.last_name.trim() && form.profession && (form.profession !== 'OTHER' || form.profession_detail.trim())
    : form.name.trim()));
const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => { dialogOpen.value = false; } };
    const payload = ({ address_entry_uuid: entry, new_address_label: newLabel, ...data }) => ({
        ...data,
        sex: data.sex || null,
        birth_date: data.birth_date || null,
        profession: data.profession || null,
        active: editing.value ? data.active : undefined,
        // Une seule source d'adresse part : l'entrée choisie ou la nouvelle.
        ...(canPickAddress.value ? {
            address_entry_uuid: addressMode.value === 'existing' ? (entry || null) : null,
            new_address_label: addressMode.value === 'new' ? (newLabel.trim() || null) : null,
        } : {}),
    });

    if (editing.value) {
        form.transform(payload).put(partnerUrl(`/${editing.value.uuid}`), options);
        return;
    }

    form.transform(payload).post(partnerUrl(), options);
};

/* ------------------------------------------------------------------ */
/* Archiver et restaurer                                               */
/* ------------------------------------------------------------------ */

const archiving = ref(null);
const archiveForm = useForm({ reason: '' });
const openArchive = (partner) => {
    archiving.value = partner;
    archiveForm.reset();
    archiveForm.clearErrors();
};
const closeArchive = () => {
    if (archiveForm.processing) return;
    archiving.value = null;
};
const confirmArchive = () => archiveForm.delete(partnerUrl(`/${archiving.value.uuid}`), {
    preserveScroll: true,
    onSuccess: () => { archiving.value = null; },
});

const restoring = ref(null);
const restore = (partner) => {
    restoring.value = partner.uuid;
    router.post(partnerUrl(`/${partner.uuid}/restore`), {}, {
        preserveScroll: true,
        onFinish: () => { restoring.value = null; },
    });
};

const emptyText = computed(() => {
    if (query.value) return { title: 'Aucun résultat', text: 'Essayez un autre mot, ou effacez la recherche.' };
    if (props.partners.length) return { title: 'Rien dans ce filtre', text: 'Choisissez un autre filtre au-dessus.' };

    return {
        title: 'Aucun partenaire pour l’instant',
        text: 'Ajoutez un partenaire médical (un médecin, un infirmier…) ou un autre partenaire (une école, une entreprise).',
    };
});
</script>

<template>
    <Head title="Partenaires" />

    <div class="w-full space-y-5">
        <PartnersPortalBar />

        <PageHeader
            eyebrow="Référentiels · Partenaires"
            title="Partenaires"
            description="Les partenaires de la clinique : des personnes du monde de la santé, et d’autres partenaires comme les écoles ou les entreprises. À l’accueil, un partenaire médical se retrouve sans ressaisie."
            :icon="Handshake"
        >
            <template #actions>
                <Button v-if="can('partner_organizations.create')" type="button" @click="openCreate">
                    <Plus class="h-4 w-4" />Nouveau partenaire
                </Button>
            </template>
        </PageHeader>

        <!-- Compteurs : chaque carte est un filtre. -->
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" role="group" aria-label="Filtrer les partenaires">
            <button
                v-for="card in cards"
                :key="card.value"
                type="button"
                :aria-pressed="filter === card.value"
                :class="cn(
                    'relative flex items-center gap-3 rounded-xl border bg-card p-3.5 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    filter === card.value ? 'border-primary ring-1 ring-primary' : 'border-border hover:border-primary/40 hover:bg-accent/40',
                )"
                @click="filter = card.value"
            >
                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', card.tone)"><component :is="card.icon" class="h-5 w-5" /></span>
                <span class="min-w-0">
                    <span class="block text-2xl font-bold leading-none tabular-nums text-foreground">{{ counts[card.value] }}</span>
                    <span class="mt-1 block text-xs font-semibold leading-tight text-foreground">{{ card.label }}</span>
                    <span class="block text-[11px] leading-tight text-muted-foreground">{{ card.hint }}</span>
                </span>
                <Check v-if="filter === card.value" class="absolute end-3 top-3 h-4 w-4 text-primary" aria-hidden="true" />
            </button>
        </div>

        <Card class="overflow-hidden">
            <div class="flex flex-col gap-3 border-b border-border px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="flex items-center gap-2 text-sm text-muted-foreground">
                    <Handshake class="h-4 w-4" />
                    <span>Un partenaire ne couvre encore aucun montant : son patient est facturé au tarif Standard.</span>
                </p>
                <div class="relative w-full sm:w-72">
                    <IconInput v-model="query" :icon="Search" type="search" placeholder="Nom, métier, téléphone…" aria-label="Rechercher un partenaire" class="pe-9" />
                    <button v-if="query" type="button" class="absolute inset-y-0 end-0 grid w-9 place-items-center text-muted-foreground hover:text-foreground" aria-label="Effacer la recherche" @click="query = ''">
                        <X class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <ul v-if="shown.length" class="divide-y divide-border">
                <li v-for="partner in shown" :key="partner.uuid" class="flex flex-wrap items-start gap-x-4 gap-y-2 px-4 py-3.5 transition-colors hover:bg-accent/30">
                    <span
                        :class="cn(
                            'grid h-10 w-10 shrink-0 place-items-center rounded-full text-sm font-bold',
                            partner.archived ? 'bg-muted text-muted-foreground'
                                : partner.category === 'MEDICAL' ? 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300'
                                    : 'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300',
                        )"
                        aria-hidden="true"
                    >
                        <template v-if="partner.category === 'MEDICAL'">{{ initials(partner) }}</template>
                        <Building2 v-else class="h-5 w-5" />
                    </span>

                    <div class="min-w-0 flex-1 basis-56">
                        <p class="flex flex-wrap items-center gap-2">
                            <span :class="cn('text-sm font-semibold', partner.archived ? 'text-muted-foreground line-through' : 'text-foreground')">{{ partner.name }}</span>
                            <Badge :variant="partner.category === 'MEDICAL' ? 'secondary' : 'outline'">
                                <component :is="partner.category === 'MEDICAL' ? Stethoscope : Building2" class="h-3 w-3" />{{ partner.category_label }}
                            </Badge>
                            <Badge v-if="partner.profession_label" variant="outline">{{ partner.profession_label }}</Badge>
                            <Badge v-if="partner.archived" variant="outline">Archivé</Badge>
                            <Badge v-else-if="! partner.active" variant="warning"><CircleOff class="h-3 w-3" />Inactif</Badge>
                        </p>
                        <p class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            <span v-if="partner.phone" class="inline-flex items-center gap-1"><Phone class="h-3.5 w-3.5" />{{ partner.phone }}</span>
                            <span v-if="partner.email" class="inline-flex items-center gap-1"><Mail class="h-3.5 w-3.5" />{{ partner.email }}</span>
                            <span v-if="partner.address" class="inline-flex items-center gap-1"><MapPin class="h-3.5 w-3.5" />{{ partner.address }}</span>
                            <span v-if="! partner.phone && ! partner.email && ! partner.address">Aucune coordonnée</span>
                        </p>
                        <p v-if="partner.has_patient || partner.episodes_count" class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                            <template v-if="partner.has_patient">
                                <Link v-if="partner.patient && ! onPortal" :href="`/patients/${partner.patient.uuid}`" class="inline-flex items-center gap-1 font-semibold text-primary hover:underline">
                                    <FileUser class="h-3.5 w-3.5" />Dossier patient {{ partner.patient.patient_number }}
                                </Link>
                                <span v-else class="inline-flex items-center gap-1 text-muted-foreground"><FileUser class="h-3.5 w-3.5" />A un dossier patient{{ partner.patient ? ` · ${partner.patient.patient_number}` : '' }}</span>
                            </template>
                            <span v-if="partner.episodes_count" class="text-muted-foreground">{{ partner.episodes_count }} passage{{ partner.episodes_count > 1 ? 's' : '' }} pris en charge</span>
                        </p>
                        <p v-if="partner.notes" class="mt-1 line-clamp-2 text-xs leading-5 text-muted-foreground">{{ partner.notes }}</p>
                        <p v-if="partner.archived && partner.delete_reason" class="mt-1 text-xs text-muted-foreground">Motif : {{ partner.delete_reason }}</p>
                    </div>

                    <div class="ms-auto flex shrink-0 items-center gap-1">
                        <template v-if="! partner.archived">
                            <Button v-if="can('partner_organizations.update')" type="button" size="sm" icon variant="ghost" :title="`Modifier ${partner.name}`" :aria-label="`Modifier ${partner.name}`" @click="openEdit(partner)">
                                <Pencil class="h-4 w-4" />
                            </Button>
                            <Button v-if="can('partner_organizations.archive')" type="button" size="sm" icon variant="ghost" class="hover:text-destructive" :title="`Archiver ${partner.name}`" :aria-label="`Archiver ${partner.name}`" @click="openArchive(partner)">
                                <Archive class="h-4 w-4" />
                            </Button>
                        </template>
                        <Button v-else-if="can('partner_organizations.restore')" type="button" size="sm" variant="outline" :disabled="restoring === partner.uuid" @click="restore(partner)">
                            <RotateCcw class="h-4 w-4" />Restaurer
                        </Button>
                    </div>
                </li>
            </ul>

            <div v-else class="px-5 py-14 text-center">
                <span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-muted text-muted-foreground"><Handshake class="h-6 w-6" /></span>
                <p class="mt-3 text-sm font-bold text-foreground">{{ emptyText.title }}</p>
                <p class="mx-auto mt-1 max-w-md text-xs leading-5 text-muted-foreground">{{ emptyText.text }}</p>
                <Button v-if="! query && ! partners.length && can('partner_organizations.create')" type="button" class="mt-4" @click="openCreate"><Plus class="h-4 w-4" />Nouveau partenaire</Button>
            </div>
        </Card>

        <!-- Créer / modifier -->
        <Dialog
            :open="dialogOpen"
            size="xl"
            :title="editing ? `Modifier « ${editing.name} »` : 'Nouveau partenaire'"
            :description="editing ? 'Les passages déjà pris en charge gardent le nom qu’ils ont enregistré.' : 'Choisissez d’abord de quel partenaire il s’agit.'"
            :dismissible="false"
            @update:open="(value) => value || closeDialog()"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><component :is="editing ? Pencil : Handshake" class="h-5 w-5" /></span>
            </template>

            <form id="partner-form" class="grid gap-6" novalidate @submit.prevent="submit">
                <fieldset>
                    <legend class="mb-2 text-sm font-medium text-foreground">Type de partenaire</legend>
                    <div class="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Type de partenaire">
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
                            <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-lg', form.category === category.value ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')">
                                <component :is="CATEGORY_CARDS[category.value]?.icon ?? Handshake" class="h-4 w-4" />
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-bold text-foreground">{{ category.label }}</span>
                                <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">{{ CATEGORY_CARDS[category.value]?.text }}</span>
                            </span>
                        </button>
                    </div>
                    <p v-if="categoryLocked" class="mt-2 text-xs text-muted-foreground">Cette fiche est reliée au dossier patient de la même personne : elle reste un partenaire médical.</p>
                    <p v-if="form.errors.category" class="mt-2 text-xs font-medium text-destructive">{{ form.errors.category }}</p>
                </fieldset>

                <!-- Deux colonnes : qui est le partenaire, puis comment le joindre. -->
                <div class="grid gap-6 lg:grid-cols-2 lg:gap-8">
                    <section class="grid content-start gap-4" aria-labelledby="partner-identity-title">
                        <h3 id="partner-identity-title" class="flex items-center gap-2 text-sm font-bold text-foreground">
                            <span class="grid h-7 w-7 place-items-center rounded-md bg-primary/10 text-primary"><component :is="isMedical ? UserRound : Building2" class="h-4 w-4" /></span>
                            {{ isMedical ? 'Identité' : 'Partenaire' }}
                        </h3>

                        <template v-if="isMedical">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <FormField label="Nom" required :error="form.errors.last_name">
                                    <IconInput id="partner-last-name" v-model="form.last_name" :icon="UserRound" maxlength="255" placeholder="Ex. RAKOTO" :aria-invalid="Boolean(form.errors.last_name)" />
                                </FormField>
                                <FormField label="Prénom" hint="(facultatif)" :error="form.errors.first_name">
                                    <IconInput v-model="form.first_name" :icon="UserRound" maxlength="255" placeholder="Ex. Fara" />
                                </FormField>
                            </div>
                            <FormField label="Métier" required as="div" :error="form.errors.profession">
                                <Select v-model="form.profession" :options="professionOptions" :icon="Stethoscope" placeholder="Choisir un métier" class="w-full" aria-label="Métier" />
                            </FormField>
                            <FormField v-if="form.profession === 'OTHER'" label="Précisez le métier" required :error="form.errors.profession_detail">
                                <IconInput v-model="form.profession_detail" :icon="Stethoscope" maxlength="100" placeholder="Ex. Kinésithérapeute" />
                            </FormField>
                            <fieldset class="rounded-xl border border-border bg-muted/30 p-4">
                                <legend class="px-1 text-xs font-bold uppercase tracking-wide text-muted-foreground">Pour ouvrir son dossier patient <span class="font-normal normal-case tracking-normal">(facultatif)</span></legend>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <FormField label="Sexe" as="div" :error="form.errors.sex">
                                        <Select v-model="form.sex" :options="sexOptions" class="w-full" aria-label="Sexe" />
                                    </FormField>
                                    <FormField label="Date de naissance" as="div" :error="form.errors.birth_date">
                                        <DatePicker v-model="form.birth_date" :invalid="Boolean(form.errors.birth_date)" />
                                    </FormField>
                                </div>
                                <p class="mt-3 text-xs leading-5 text-muted-foreground">S’il vient se faire soigner, l’accueil reprend cette fiche au lieu de tout ressaisir.</p>
                            </fieldset>
                        </template>

                        <FormField v-else label="Nom ou identité" required :error="form.errors.name">
                            <IconInput id="partner-name" v-model="form.name" :icon="Building2" maxlength="255" placeholder="Ex. ISPSG, une entreprise, une personne…" :aria-invalid="Boolean(form.errors.name)" />
                        </FormField>
                    </section>

                    <section class="grid content-start gap-4 lg:border-s lg:border-border lg:ps-8" aria-labelledby="partner-contact-title">
                        <h3 id="partner-contact-title" class="flex items-center gap-2 text-sm font-bold text-foreground">
                            <span class="grid h-7 w-7 place-items-center rounded-md bg-cyan-50 text-cyan-700 dark:bg-cyan-950/40 dark:text-cyan-300"><Phone class="h-4 w-4" /></span>
                            Coordonnées
                        </h3>
                        <FormField label="Téléphone" hint="(facultatif)" :error="form.errors.phone">
                            <IconInput v-model="form.phone" :icon="Phone" type="tel" maxlength="40" placeholder="Ex. 034 00 000 00" />
                        </FormField>
                        <FormField label="Email" hint="(facultatif)" :error="form.errors.email">
                            <IconInput v-model="form.email" :icon="Mail" type="email" maxlength="255" placeholder="contact@exemple.mg" />
                        </FormField>
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
                            <Textarea v-model="form.notes" :rows="3" maxlength="2000" placeholder="Convention, personne à joindre…" />
                        </FormField>

                        <label v-if="editing" for="partner-active" class="flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-muted/40 px-3.5 py-3">
                            <Checkbox id="partner-active" v-model="form.active" class="mt-0.5" />
                            <span>
                                <span class="block text-sm font-semibold text-foreground">Proposé à l’accueil</span>
                                <span class="block text-xs leading-5 text-muted-foreground">Décoché, les passages qui le citent le gardent, mais l’accueil ne le propose plus.</span>
                            </span>
                        </label>
                    </section>
                </div>
            </form>

            <template #footer>
                <Button type="button" variant="outline" :disabled="form.processing" @click="closeDialog">Annuler</Button>
                <Button type="submit" form="partner-form" :disabled="form.processing || ! canSubmit">
                    <Check class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Ajouter' }}
                </Button>
            </template>
        </Dialog>

        <!-- Archiver -->
        <Dialog
            :open="archiving !== null"
            :title="archiving ? `Archiver « ${archiving.name} »` : ''"
            description="Rien n’est supprimé : le partenaire se restaure à tout moment depuis le filtre « Archivés »."
            :dismissible="false"
            @update:open="(value) => value || closeArchive()"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-300"><Archive class="h-5 w-5" /></span>
            </template>
            <form id="partner-archive-form" class="space-y-4" novalidate @submit.prevent="confirmArchive">
                <p v-if="archiving?.has_patient || archiving?.episodes_count" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                    <FileUser class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>Les passages qui le citent et son dossier patient restent tels quels ; l’accueil ne le proposera plus.</span>
                </p>
                <FormField label="Motif" required :error="archiveForm.errors.reason">
                    <Textarea v-model="archiveForm.reason" :rows="3" maxlength="1000" placeholder="Pourquoi ce partenaire n’est-il plus proposé ?" />
                </FormField>
            </form>
            <template #footer>
                <Button type="button" variant="outline" :disabled="archiveForm.processing" @click="closeArchive">Annuler</Button>
                <Button type="submit" form="partner-archive-form" variant="destructive" :disabled="archiveForm.processing || ! archiveForm.reason.trim()">
                    <Archive class="h-4 w-4" />{{ archiveForm.processing ? 'Archivage…' : 'Archiver' }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
