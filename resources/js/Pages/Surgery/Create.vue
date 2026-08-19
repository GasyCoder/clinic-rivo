<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import CardBody from '@/Components/UI/CardBody.vue';
import FormError from '@/Components/UI/FormError.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormLabel from '@/Components/UI/FormLabel.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import { formatDate } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    episodes: Array,
    search: String,
});

const query = ref(props.search ?? '');
const selectedEpisode = ref(null);

const runSearch = (value) => {
    router.get('/surgery/create', value ? { q: value } : {}, { preserveState: true, preserveScroll: true, replace: true });
};

// Live search: fires 350ms after the last keystroke, including when the
// field is cleared back to empty — no need to press Enter for either case.
let debounceTimer = null;
watch(query, (value) => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => runSearch(value), 350);
});

// Enter still triggers an immediate search, bypassing the debounce.
const submitSearch = () => {
    clearTimeout(debounceTimer);
    runSearch(query.value);
};

const selectEpisode = (episode) => {
    selectedEpisode.value = episode;
    form.episode_uuid = episode.uuid;
};

const form = useForm({
    episode_uuid: selectedEpisode.value?.uuid ?? '',
    procedure_name: '',
    notes: '',
});

const canSubmit = computed(() => Boolean(form.episode_uuid) && form.procedure_name.trim() !== '');

const submit = () => form.post('/surgery');
</script>

<template>
    <Head title="Nouvelle demande de chirurgie" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-6 lg:space-y-8">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                    <Icon class="text-2xl" name="grid-alt" />
                </span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-slate-700 dark:text-white">Nouvelle demande de chirurgie</h1>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-400">Rattachez la demande à un passage ouvert et décrivez l'acte prévu.</p>
                </div>
            </div>
            <Button :as="Link" href="/surgery" size="rg" variant="white-outline">
                <Icon class="text-lg" name="arrow-left" /><span class="ms-2">Chirurgie</span>
            </Button>
        </header>

        <form class="grid grid-cols-1 gap-6 xl:grid-cols-3 xl:items-start" @submit.prevent="submit">
            <Card class="shadow-sm xl:col-span-2">
                <CardBody>
                    <div class="mb-5 flex items-center gap-2 border-b border-gray-200 pb-4 dark:border-gray-900">
                        <span class="flex h-8 w-8 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400">
                            <Icon class="text-base" name="user" />
                        </span>
                        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">1. Passage concerné</h2>
                    </div>

                    <div v-if="selectedEpisode" class="flex items-center justify-between gap-3 rounded border border-primary-200 bg-primary-50 px-4 py-3 dark:border-primary-900 dark:bg-primary-950/30">
                        <div class="flex min-w-0 items-center gap-3">
                            <Avatar rounded size="sm" variant="primary-pale" :text="formatPatientInitials(selectedEpisode.patient)" aria-hidden="true" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(selectedEpisode.patient) }}</p>
                                <p class="text-xs text-slate-400">{{ selectedEpisode.episode_number }} · ouvert le {{ formatDate(selectedEpisode.started_at) }}</p>
                            </div>
                        </div>
                        <Button size="xs" variant="white-outline" type="button" @click="selectedEpisode = null; form.episode_uuid = ''">Changer</Button>
                    </div>

                    <template v-else>
                        <div class="relative mb-3">
                            <Input v-model="query" icon="start" type="search" placeholder="Nom, numéro patient ou n° passage" autocomplete="off" @keydown.enter.prevent="submitSearch" />
                            <button type="button" class="absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400" aria-label="Rechercher" @click="submitSearch">
                                <Icon class="text-lg/4.5" name="search" />
                            </button>
                        </div>

                        <ul v-if="episodes.length" class="max-h-80 space-y-1.5 overflow-y-auto rounded border border-gray-200 p-1.5 dark:border-gray-900">
                            <li v-for="episode in episodes" :key="episode.uuid">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-3 rounded px-2.5 py-2 text-start transition-colors hover:bg-gray-50 dark:hover:bg-gray-1000"
                                    @click="selectEpisode(episode)"
                                >
                                    <Avatar rounded size="sm" variant="primary-pale" :text="formatPatientInitials(episode.patient)" aria-hidden="true" />
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(episode.patient) }}</p>
                                        <p class="text-xs text-slate-400">{{ episode.episode_number }} · ouvert le {{ formatDate(episode.started_at) }}</p>
                                    </div>
                                </button>
                            </li>
                        </ul>
                        <p v-else class="rounded border border-dashed border-gray-200 px-3 py-6 text-center text-sm text-slate-400 dark:border-gray-800">
                            Aucun passage ouvert ne correspond. Un passage doit d'abord être créé depuis la Réception.
                        </p>
                    </template>
                    <FormError v-if="form.errors.episode_uuid">{{ form.errors.episode_uuid }}</FormError>

                    <div class="mb-5 mt-7 flex items-center gap-2 border-b border-gray-200 pb-4 dark:border-gray-900">
                        <span class="flex h-8 w-8 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400">
                            <Icon class="text-base" name="activity" />
                        </span>
                        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">2. Acte prévu</h2>
                    </div>

                    <div class="grid grid-cols-1 gap-5">
                        <FormGroup class="!mb-0">
                            <FormLabel class="mb-1.5" for="procedure_name">Acte <span class="text-red-500">*</span></FormLabel>
                            <Input id="procedure_name" v-model="form.procedure_name" placeholder="Ex. Appendicectomie" required />
                            <FormError v-if="form.errors.procedure_name">{{ form.errors.procedure_name }}</FormError>
                        </FormGroup>

                        <FormGroup class="!mb-0">
                            <FormLabel class="mb-1.5" for="notes">Notes</FormLabel>
                            <textarea
                                id="notes"
                                v-model="form.notes"
                                rows="4"
                                placeholder="Motif, contexte clinique…"
                                class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none transition-all placeholder:text-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950"
                            ></textarea>
                            <FormError v-if="form.errors.notes">{{ form.errors.notes }}</FormError>
                        </FormGroup>
                    </div>
                </CardBody>
            </Card>

            <Card class="shadow-sm">
                <CardBody>
                    <h2 class="mb-4 text-sm font-bold uppercase tracking-wide text-slate-500">Récapitulatif</h2>
                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="text-xs text-slate-400">Patient</dt>
                            <dd class="mt-0.5 font-medium text-slate-700 dark:text-white">{{ selectedEpisode ? formatPatientName(selectedEpisode.patient) : 'Non sélectionné' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-400">Passage</dt>
                            <dd class="mt-0.5 font-medium text-slate-700 dark:text-white">{{ selectedEpisode?.episode_number ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-400">Acte</dt>
                            <dd class="mt-0.5 font-medium text-slate-700 dark:text-white">{{ form.procedure_name || '—' }}</dd>
                        </div>
                    </dl>

                    <p class="mt-5 flex items-start gap-2 rounded border border-gray-200 bg-gray-50/70 p-3 text-xs leading-5 text-slate-400 dark:border-gray-900 dark:bg-gray-1000/40">
                        <Icon class="mt-0.5 shrink-0 text-sm" name="info" />
                        Le chirurgien et la date d'intervention se programment à l'étape suivante, une fois la demande créée.
                    </p>

                    <div class="mt-6 flex flex-col gap-3 border-t border-gray-200 pt-5 dark:border-gray-900">
                        <Button size="rg" variant="primary" type="submit" :disabled="form.processing || !canSubmit" class="justify-center">
                            <Icon class="text-lg" name="plus" /><span class="ms-2">{{ form.processing ? 'Création…' : 'Créer la demande' }}</span>
                        </Button>
                        <Button :as="Link" href="/surgery" size="rg" variant="white-outline" class="justify-center">Annuler</Button>
                    </div>
                </CardBody>
            </Card>
        </form>
    </div>
</template>
