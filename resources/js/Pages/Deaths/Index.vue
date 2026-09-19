<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormError from '@/Components/UI/FormError.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import ClinicalRichTextEditor from '@/Components/Clinical/ClinicalRichTextEditor.vue';
import { formatDate } from '@/utilities/date';
import { CircleCheck, FileSignature, HeartCrack, Printer, Search, SquarePen } from 'lucide-vue-next';

defineOptions({ layout: AppLayout });

/**
 * Le registre des décès (ADR-107).
 *
 * Il ne prononce aucun décès : la décision appartient à la sortie médicale
 * de la Consultation (ADR-035). Cet écran liste ce que la Médecine a déjà
 * décidé, et permet d'en signer l'acte de constatation.
 */
const props = defineProps({
    episodes: { type: Object, required: true },
    counts: { type: Object, required: true },
    filter: { type: String, default: 'all' },
    search: { type: String, default: '' },
    capabilities: { type: Object, required: true },
    // « Signatures — Lieu » par défaut : le site où l'acte est établi.
    defaultSignedPlace: { type: String, default: '' },
});

const query = ref(props.search);

const visit = (params) => router.get('/deces', params, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
});

// La carte est le filtre, et le compte vient du serveur — jamais de la page
// affichée, qui n'en montre que vingt.
const cards = computed(() => [
    { key: 'pending', label: 'Actes à établir', value: props.counts.pending ?? 0 },
    { key: 'recorded', label: 'Actes établis', value: props.counts.recorded ?? 0 },
    { key: 'all', label: 'Tous les décès', value: props.counts.all ?? 0 },
]);

const formatDateTime = (value) => value
    ? new Date(value).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })
    : '—';

/**
 * L'acte reprend ce que la sortie médicale a consigné, et reste corrigeable
 * ici : c'est le médecin qui constate qui signe ce qu'il écrit, il ne
 * contresigne pas la saisie d'un autre écran.
 */
const recording = ref(null);
const form = useForm({
    // Informations relatives au défunt — repris du dossier, corrigeables,
    // jamais écrits en retour dans le dossier patient.
    birth_place: '',
    address: '',
    father_name: '',
    mother_name: '',
    identity_document_number: '',
    identity_document_issued_on: '',
    identity_document_issued_place: '',
    // Le décès.
    death_occurred_at: '',
    death_place: '',
    death_causes: '',
    observations: '',
    // Signatures.
    signed_place: '',
});

const SEX_LABELS = { M: 'Masculin', F: 'Féminin' };

/**
 * Un patient peut n'avoir jamais eu de date de naissance exacte (ADR-030) —
 * seulement un âge déclaré à son arrivée. Afficher « Non renseignée » dans
 * ce cas ferait passer une donnée réelle pour une absence. Même règle que
 * l'acte imprimé (`CertificatePrint.vue`) : la date prime quand elle est
 * exacte, l'âge déclaré la remplace sinon.
 */
const birthLabel = (patient) => {
    if (patient.birth_date && !patient.birth_date_is_approximate) {
        return formatDate(patient.birth_date);
    }

    return patient.age !== null && patient.age !== undefined
        ? `${patient.age} ans (âge déclaré)`
        : 'Non renseignée au dossier';
};

/* ------------------------------------------------------------------ *
 * Assistant en trois étapes : moins de défilement que les quatre
 * sections empilées, chacune atteignable d'un clic (même principe que
 * « Décision & clôture », ADR-106 — pas de pied Précédent/Suivant qui
 * referait le travail des onglets).
 * ------------------------------------------------------------------ */
const STEPS = [
    { step: 1, label: 'État civil' },
    { step: 2, label: 'Décès' },
    { step: 3, label: 'Signatures' },
];

// Quelle étape porte chaque champ, pour y ramener le médecin si le serveur
// refuse : les champs restent tous dans le formulaire, seule leur étape
// est masquée, et une erreur sur une étape qu'on ne regarde plus serait
// invisible.
const STEP_OF_FIELD = {
    birth_place: 1, address: 1, father_name: 1, mother_name: 1,
    identity_document_number: 1, identity_document_issued_on: 1, identity_document_issued_place: 1,
    death_occurred_at: 2, death_place: 2, death_causes: 2, observations: 2,
    signed_place: 3, death_record: 3,
};

const currentStep = ref(1);

watch(() => form.errors, (errors) => {
    const fields = Object.keys(errors);
    if (fields.length === 0) return;

    const steps = fields.map((field) => STEP_OF_FIELD[field]).filter(Boolean);
    if (steps.length) currentStep.value = Math.min(...steps);
}, { deep: true });

/** Un texte riche vidé (`<p><br></p>`) ne compte pas comme rempli (ADR-073). */
const isFilled = (value) => Boolean(value && value.replace(/<[^>]*>/g, '').trim().length > 0);

const stepDone = (step) => {
    if (step !== 2) return false;

    return isFilled(form.death_occurred_at) && isFilled(form.death_place) && isFilled(form.death_causes);
};

/** `datetime-local` n'accepte ni fuseau ni secondes. */
const toLocalInput = (value) => {
    if (!value) return '';
    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? ''
        : new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};

const openRecord = (episode) => {
    recording.value = episode;
    currentStep.value = 1;
    form.clearErrors();
    form.birth_place = episode.patient.birth_place ?? '';
    form.address = episode.patient.address ?? '';
    form.father_name = '';
    form.mother_name = '';
    form.identity_document_number = episode.patient.identity_document_number ?? '';
    form.identity_document_issued_on = '';
    form.identity_document_issued_place = '';
    form.death_occurred_at = toLocalInput(episode.death_occurred_at);
    form.death_place = episode.death_place ?? '';
    form.death_causes = episode.death_causes ?? '';
    form.observations = '';
    form.signed_place = props.defaultSignedPlace ?? '';
};

const closeRecord = () => {
    recording.value = null;
    form.reset();
    form.clearErrors();
};

const submitRecord = () => form.post(`/deces/${recording.value.uuid}/acte`, {
    preserveScroll: true,
    onSuccess: closeRecord,
});
</script>

<template>
    <Head title="Décès" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <Card class="p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground">
                        <HeartCrack class="h-5 w-5" />
                    </span>
                    <div>
                        <h1 class="font-heading text-lg font-bold text-foreground">Décès</h1>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            Les passages dont la Médecine a prononcé le décès, et l’acte de constatation à établir.
                            Cet écran ne prononce aucun décès et n’encaisse rien.
                        </p>
                    </div>
                </div>

                <form class="w-full sm:w-72" @submit.prevent="visit({ q: query, filter })">
                    <IconInput
                        id="death_search"
                        v-model="query"
                        :icon="Search"
                        placeholder="Patient, n° patient ou passage…"
                        aria-label="Rechercher dans le registre des décès"
                    />
                </form>
            </div>
        </Card>

        <div class="grid gap-3 sm:grid-cols-3">
            <button
                v-for="card in cards"
                :key="card.key"
                type="button"
                :aria-pressed="filter === card.key"
                :class="['rounded-xl border p-4 text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    filter === card.key ? 'border-primary/40 bg-primary/5' : 'border-border bg-card hover:bg-accent']"
                @click="visit({ q: search, filter: card.key })"
            >
                <span class="block text-xs font-semibold text-muted-foreground">{{ card.label }}</span>
                <strong class="mt-1 block font-heading text-2xl tabular-nums text-foreground">{{ card.value }}</strong>
            </button>
        </div>

        <Card class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[840px] border-collapse text-sm">
                    <caption class="sr-only">Registre des décès</caption>
                    <thead>
                        <tr class="border-b border-border bg-muted/40 text-start text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            <th scope="col" class="px-4 py-3 text-start">Patient</th>
                            <th scope="col" class="px-4 py-3 text-start">Décès</th>
                            <th scope="col" class="px-4 py-3 text-start">Acte de constatation</th>
                            <th scope="col" class="px-4 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="episode in episodes.data" :key="episode.uuid" class="align-top">
                            <td class="px-4 py-3">
                                <Link :href="episode.episode_url" class="block font-semibold text-foreground hover:text-primary">{{ episode.patient.name }}</Link>
                                <span class="mt-0.5 block text-xs text-muted-foreground">
                                    {{ episode.patient.patient_number }} · Passage {{ episode.episode_number }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="block text-foreground">{{ formatDateTime(episode.death_occurred_at) }}</span>
                                <span class="mt-0.5 block text-xs text-muted-foreground">{{ episode.death_place || 'Lieu non précisé' }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <template v-if="episode.record">
                                    <Badge variant="success">Établi</Badge>
                                    <span class="mt-1 block text-xs text-muted-foreground">
                                        {{ episode.record.constated_by }} · {{ formatDateTime(episode.record.constated_at) }}
                                    </span>
                                </template>
                                <!-- Un acte qui manque est un document que la
                                     famille n'a pas : il est nommé, jamais
                                     laissé à un tiret muet. -->
                                <Badge v-else variant="warning">À établir</Badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    <Button v-if="episode.can_record" type="button" size="sm" @click="openRecord(episode)">
                                        <FileSignature class="h-4 w-4" />Saisir l’acte de décès
                                    </Button>
                                    <Button
                                        v-if="episode.record"
                                        :as="Link"
                                        :href="`/deces/${episode.uuid}/acte/impression`"
                                        target="_blank"
                                        size="sm"
                                        variant="white-outline"
                                    >
                                        <Printer class="h-4 w-4" />Imprimer l’acte
                                    </Button>
                                    <Button :as="Link" :href="episode.episode_url" size="sm" variant="white-outline">
                                        <SquarePen class="h-4 w-4" />Voir le passage
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!episodes.data.length">
                            <td colspan="4" class="px-4 py-12 text-center text-sm text-muted-foreground">
                                Aucun décès dans ce filtre.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="episodes.links?.length > 3" class="flex flex-wrap items-center justify-center gap-1 border-t border-border p-3">
                <Link
                    v-for="link in episodes.links"
                    :key="link.label"
                    :href="link.url ?? ''"
                    :class="['rounded-md px-3 py-1.5 text-xs font-semibold transition-colors',
                        link.active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent',
                        !link.url && 'pointer-events-none opacity-40']"
                    v-html="link.label"
                />
            </div>
        </Card>
    </div>

    <!-- Signer l'acte. Non fermable au clic extérieur : c'est un document
         médico-légal, pas une fenêtre qu'on parcourt. -->
    <Dialog
        :open="recording !== null"
        title="Acte de constatation de décès"
        :description="recording ? `${recording.patient.name} · ${recording.patient.patient_number} · Passage ${recording.episode_number}` : ''"
        :dismissible="false"
        size="xl"
        body-class="max-h-[68vh] overflow-y-auto"
        close-label="Annuler"
        @update:open="closeRecord"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground">
                <FileSignature class="h-5 w-5" />
            </span>
        </template>

        <div v-if="recording" class="space-y-4">
            <!-- Contexte fixe, visible quelle que soit l'étape : qui signe,
                 et qui est le défunt — pour ne jamais avoir à revenir en
                 arrière pour se souvenir de l'un ou l'autre. -->
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-md bg-muted px-3 py-2.5 text-sm sm:grid-cols-4">
                <div><dt class="text-[11px] text-muted-foreground">Médecin traitant</dt><dd class="font-medium text-foreground">Dr {{ $page.props.auth.user.name }}</dd></div>
                <div><dt class="text-[11px] text-muted-foreground">Nom et prénom</dt><dd class="font-medium text-foreground">{{ recording.patient.name }}</dd></div>
                <div>
                    <dt class="text-[11px] text-muted-foreground">Date de naissance</dt>
                    <dd class="font-medium text-foreground">{{ birthLabel(recording.patient) }}</dd>
                </div>
                <div><dt class="text-[11px] text-muted-foreground">Sexe</dt><dd class="font-medium text-foreground">{{ SEX_LABELS[recording.patient.sex] ?? '—' }}</dd></div>
            </dl>

            <!-- Les trois étapes du « Certificat médical de constatation de
                 décès » de la clinique : ce qu'on remplit ici est ce qui
                 s'imprime, case pour case. Aucune n'est verrouillée par une
                 autre — un médecin qui sait déjà tout peut aller droit à
                 « Signatures ». -->
            <nav class="flex flex-wrap items-center gap-1.5" aria-label="Étapes de l’acte de décès">
                <button
                    v-for="item in STEPS"
                    :key="item.step"
                    type="button"
                    :aria-current="currentStep === item.step ? 'step' : undefined"
                    :class="['inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
                        currentStep === item.step
                            ? 'border-primary/30 bg-primary/10 text-primary'
                            : 'border-border bg-card text-muted-foreground hover:bg-accent hover:text-foreground']"
                    @click="currentStep = item.step"
                >
                    <CircleCheck v-if="stepDone(item.step)" class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                    <span v-else class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full border border-current text-[9px] tabular-nums" aria-hidden="true">{{ item.step }}</span>
                    {{ item.label }}
                </button>
            </nav>

            <section v-show="currentStep === 1" class="space-y-3">
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormField label="Lieu de naissance" :error="form.errors.birth_place">
                        <Input id="death_birth_place" v-model="form.birth_place" maxlength="255" />
                    </FormField>
                    <FormField label="Adresse" :error="form.errors.address">
                        <Input id="death_address" v-model="form.address" maxlength="500" />
                    </FormField>
                    <FormField label="Fils / Fille de" :error="form.errors.father_name">
                        <Input id="death_father_name" v-model="form.father_name" maxlength="255" />
                    </FormField>
                    <FormField label="et de" :error="form.errors.mother_name">
                        <Input id="death_mother_name" v-model="form.mother_name" maxlength="255" />
                    </FormField>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <FormField label="CNI N°" :error="form.errors.identity_document_number">
                        <Input id="death_cni_number" v-model="form.identity_document_number" maxlength="100" />
                    </FormField>
                    <FormField label="délivrée le" :error="form.errors.identity_document_issued_on">
                        <Input id="death_cni_issued_on" v-model="form.identity_document_issued_on" type="date" />
                    </FormField>
                    <FormField label="à" :error="form.errors.identity_document_issued_place">
                        <Input id="death_cni_issued_place" v-model="form.identity_document_issued_place" maxlength="255" />
                    </FormField>
                </div>
            </section>

            <section v-show="currentStep === 2" class="space-y-3">
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormField label="Date et heure du décès" required :error="form.errors.death_occurred_at">
                        <Input id="death_occurred_at" v-model="form.death_occurred_at" type="datetime-local" />
                    </FormField>
                    <FormField label="Lieu du décès" required :error="form.errors.death_place">
                        <Input id="death_place" v-model="form.death_place" maxlength="255" />
                    </FormField>
                </div>
                <FormField label="Causes du décès" required :error="form.errors.death_causes">
                    <ClinicalRichTextEditor
                        id="death_causes"
                        v-model="form.death_causes"
                        :max-length="5000"
                        min-height-class="min-h-24"
                        toolbar-label="Mise en forme — causes du décès"
                    />
                </FormField>
                <FormField label="Observations" :error="form.errors.observations">
                    <ClinicalRichTextEditor
                        id="death_observations"
                        v-model="form.observations"
                        :max-length="5000"
                        min-height-class="min-h-16"
                        toolbar-label="Mise en forme — observations"
                    />
                </FormField>
            </section>

            <section v-show="currentStep === 3" class="space-y-3">
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormField as="div" label="Date">
                        <p class="flex h-10 items-center text-sm text-muted-foreground">Celle de l’enregistrement, posée par le serveur.</p>
                    </FormField>
                    <FormField label="Lieu" :error="form.errors.signed_place">
                        <Input id="death_signed_place" v-model="form.signed_place" maxlength="255" />
                    </FormField>
                </div>
                <p class="text-xs leading-5 text-muted-foreground">
                    Ces valeurs reprennent la sortie médicale et le dossier, et restent corrigeables ici : vous signez ce que vous écrivez.
                    Le dossier patient n’est pas modifié.
                    L’acte est établi sous votre responsabilité, en tant que <strong class="font-semibold text-foreground">{{ $page.props.auth.user.name }}</strong>,
                    et l’heure de constatation est celle du serveur. Un passage ne porte qu’un acte.
                </p>
                <FormError :message="form.errors.death_record" />
            </section>
        </div>

        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="closeRecord">Annuler</Button>
            <Button type="button" :disabled="form.processing" @click="submitRecord">
                <FileSignature class="h-4 w-4" />Établir l’acte
            </Button>
        </template>
    </Dialog>
</template>

