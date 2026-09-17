<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormError from '@/Components/UI/FormError.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { FileSignature, HeartCrack, Printer, Search, SquarePen } from 'lucide-vue-next';

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
    death_occurred_at: '',
    death_place: '',
    death_causes: '',
    observations: '',
});

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
    form.clearErrors();
    form.death_occurred_at = toLocalInput(episode.death_occurred_at);
    form.death_place = episode.death_place ?? '';
    form.death_causes = episode.death_causes ?? '';
    form.observations = '';
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
        close-label="Annuler"
        @update:open="closeRecord"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground">
                <FileSignature class="h-5 w-5" />
            </span>
        </template>

        <div class="space-y-3">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-semibold text-foreground" for="death_occurred_at">Date et heure du décès *</label>
                    <Input id="death_occurred_at" v-model="form.death_occurred_at" class="mt-1.5" type="datetime-local" />
                    <FormError class="mt-1" :message="form.errors.death_occurred_at" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground" for="death_place">Lieu du décès *</label>
                    <Input id="death_place" v-model="form.death_place" class="mt-1.5" maxlength="255" />
                    <FormError class="mt-1" :message="form.errors.death_place" />
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground" for="death_causes">Causes constatées *</label>
                <Textarea id="death_causes" v-model="form.death_causes" class="mt-1.5" :rows="3" maxlength="5000" />
                <FormError class="mt-1" :message="form.errors.death_causes" />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground" for="death_observations">Observations</label>
                <Textarea id="death_observations" v-model="form.observations" class="mt-1.5" :rows="2" maxlength="5000" />
                <FormError class="mt-1" :message="form.errors.observations" />
            </div>
        </div>

        <p class="mt-3 text-xs leading-5 text-muted-foreground">
            Ces valeurs reprennent la sortie médicale et restent corrigeables ici : vous signez ce que vous écrivez.
            L’acte est établi sous votre responsabilité, en tant que <strong class="font-semibold text-foreground">{{ $page.props.auth.user.name }}</strong>,
            et l’heure de constatation est celle du serveur. Un passage ne porte qu’un acte.
        </p>
        <FormError class="mt-2" :message="form.errors.death_record" />

        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="closeRecord">Annuler</Button>
            <Button type="button" :disabled="form.processing" @click="submitRecord">
                <FileSignature class="h-4 w-4" />Établir l’acte
            </Button>
        </template>
    </Dialog>
</template>
