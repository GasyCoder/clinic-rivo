<script setup>
import { computed, ref } from 'vue';
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
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import PartnersPortalBar from '@/Components/Partners/PartnersPortalBar.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { partnerUrl, partnersContext } from '@/utilities/partnerUrl';

/**
 * ADR-211 — le module Partenaires d'un site.
 *
 * Deux sortes de fiches : Médical (une personne du monde de la santé) et Autre
 * (un organisme ou une personne : l'ISPSG, une entreprise). À l'accueil, un
 * partenaire se choisit à la prise en charge du passage. Le même écran
 * s'ouvre au site et au Super Admin depuis le portail ; le serveur revérifie
 * chaque geste. Un partenaire ne couvre encore aucun montant.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    partners: { type: Array, default: () => [] },
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
/* Créer et modifier : chacun sur sa page (Partners/Form).             */
/* ------------------------------------------------------------------ */

const createUrl = partnerUrl('/nouveau');
const editUrl = (partner) => partnerUrl(`/${partner.uuid}/modifier`);

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
            description="Les partenaires de la clinique : des personnes du monde de la santé, et d’autres partenaires comme les écoles ou les entreprises. À l’accueil, un partenaire se choisit à l’étape Prise en charge du passage."
            :icon="Handshake"
        >
            <template #actions>
                <Button v-if="can('partner_organizations.create')" :as="Link" :href="createUrl">
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
                            <Button v-if="can('partner_organizations.update')" :as="Link" :href="editUrl(partner)" size="sm" icon variant="ghost" :title="`Modifier ${partner.name}`" :aria-label="`Modifier ${partner.name}`">
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
                <Button v-if="! query && ! partners.length && can('partner_organizations.create')" :as="Link" :href="createUrl" class="mt-4"><Plus class="h-4 w-4" />Nouveau partenaire</Button>
            </div>
        </Card>

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
