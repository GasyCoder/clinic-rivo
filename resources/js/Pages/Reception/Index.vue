<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime, formatRelativeTime } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({
    recentEpisodes: Array,
    presentVisitors: Array,
});

const { can } = usePermissions();
const canReceivePatients = computed(() => can('episodes.create'));
const canViewVisitors = computed(() => can('visitors.view'));
const activityTab = ref(canReceivePatients.value ? 'patients' : 'visitors');

const episodePatientHref = (episode) => episode.patient?.uuid && !episode.patient.deleted_at
    ? `/patients/${episode.patient.uuid}`
    : null;

const visitorInitials = (visitor) => visitor.full_name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('');

const visitorCategoryLabel = (category) => category === 'PROFESSIONAL'
    ? 'Professionnel'
    : 'Visite patient / famille';
</script>

<template>
    <Head title="Réception" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-6">
        <header>
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-primary-100 text-primary-600 dark:bg-primary-950 dark:text-primary-300">
                    <Icon class="text-2xl" name="card-view" />
                </span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-slate-700 dark:text-white">Réception</h1>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500 dark:text-slate-400">
                        Sélectionnez le parcours correspondant à la personne accueillie.
                    </p>
                </div>
            </div>
        </header>

        <section class="grid gap-4 lg:grid-cols-2" aria-label="Parcours de réception">
            <Link
                v-if="canReceivePatients"
                href="/reception/patients"
                class="group flex min-h-44 items-start gap-5 rounded-lg border border-gray-200 bg-white p-6 shadow-sm transition hover:border-primary-300 hover:shadow-md focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-primary-100 dark:border-gray-900 dark:bg-gray-950 dark:hover:border-primary-800 dark:focus-visible:ring-primary-950"
            >
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-gray-100 text-primary-600 dark:bg-gray-900 dark:text-primary-300">
                    <Icon class="text-2xl" name="user-add" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="flex items-center justify-between gap-4">
                        <span class="font-heading text-lg font-bold text-slate-700 dark:text-white">Réception patient</span>
                        <Icon class="text-xl text-slate-300 transition-transform group-hover:translate-x-1 group-hover:text-primary-500" name="arrow-right" />
                    </span>
                    <span class="mt-2 block max-w-xl text-sm leading-6 text-slate-500 dark:text-slate-400">
                        Rechercher ou créer un dossier patient, enregistrer son passage normal ou urgent.
                    </span>
                    <span class="mt-4 inline-flex items-center gap-2 text-xs font-medium text-slate-500">
                        <Icon class="text-base" name="activity" />
                        {{ recentEpisodes.length }} passage{{ recentEpisodes.length > 1 ? 's' : '' }} récent{{ recentEpisodes.length > 1 ? 's' : '' }}
                    </span>
                </span>
            </Link>

            <Link
                v-if="canViewVisitors"
                href="/reception/visitors"
                class="group flex min-h-44 items-start gap-5 rounded-lg border border-gray-200 bg-white p-6 shadow-sm transition hover:border-primary-300 hover:shadow-md focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-primary-100 dark:border-gray-900 dark:bg-gray-950 dark:hover:border-primary-800 dark:focus-visible:ring-primary-950"
            >
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-gray-100 text-slate-600 dark:bg-gray-900 dark:text-slate-300">
                    <Icon class="text-2xl" name="users" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="flex items-center justify-between gap-4">
                        <span class="font-heading text-lg font-bold text-slate-700 dark:text-white">Réception visiteur</span>
                        <Icon class="text-xl text-slate-300 transition-transform group-hover:translate-x-1 group-hover:text-primary-500" name="arrow-right" />
                    </span>
                    <span class="mt-2 block max-w-xl text-sm leading-6 text-slate-500 dark:text-slate-400">
                        Enregistrer les visites professionnelles et les visites auprès d’un patient ou de sa famille.
                    </span>
                    <span class="mt-4 inline-flex items-center gap-2 text-xs font-medium text-slate-500">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                        {{ presentVisitors.length }} visiteur{{ presentVisitors.length > 1 ? 's' : '' }} présent{{ presentVisitors.length > 1 ? 's' : '' }}
                    </span>
                </span>
            </Link>
        </section>

        <section v-if="canReceivePatients || canViewVisitors">
            <Card class="overflow-hidden shadow-sm">
                <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-1 rounded bg-gray-100 p-1 dark:bg-gray-1000">
                        <button v-if="canReceivePatients" type="button" :class="['rounded px-3 py-1.5 text-xs font-bold transition-colors', activityTab === 'patients' ? 'bg-white text-primary-600 shadow-sm dark:bg-gray-950 dark:text-primary-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200']" @click="activityTab = 'patients'">
                            Passages patients <span class="ms-1 text-slate-400">({{ recentEpisodes.length }})</span>
                        </button>
                        <button v-if="canViewVisitors" type="button" :class="['rounded px-3 py-1.5 text-xs font-bold transition-colors', activityTab === 'visitors' ? 'bg-white text-primary-600 shadow-sm dark:bg-gray-950 dark:text-primary-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200']" @click="activityTab = 'visitors'">
                            Visiteurs présents <span class="ms-1 text-slate-400">({{ presentVisitors.length }})</span>
                        </button>
                    </div>
                    <div class="flex items-center gap-3">
                        <p class="text-xs text-slate-400">{{ activityTab === 'patients' ? 'Dernières arrivées enregistrées.' : 'Entrées sans sortie enregistrée.' }}</p>
                        <Button :as="Link" :href="activityTab === 'patients' ? '/reception/patients' : '/reception/visitors'" size="sm" variant="white-outline">{{ activityTab === 'patients' ? 'Voir le parcours' : 'Ouvrir le registre' }}</Button>
                    </div>
                </div>

                <div v-if="activityTab === 'patients'">
                    <div v-if="recentEpisodes.length" class="divide-y divide-gray-200 dark:divide-gray-900">
                        <component
                            :is="episodePatientHref(episode) ? Link : 'div'"
                            v-for="episode in recentEpisodes"
                            :key="episode.id"
                            :href="episodePatientHref(episode) || undefined"
                            :class="['flex items-center gap-3 px-5 py-3', episodePatientHref(episode) ? 'transition hover:bg-gray-50 dark:hover:bg-gray-1000' : 'bg-gray-50/50 dark:bg-gray-1000/30']"
                        >
                            <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(episode.patient)" aria-hidden="true" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(episode.patient) }}</span>
                                <span class="mt-0.5 block text-xs text-slate-400">{{ episode.episode_number }} · {{ formatRelativeTime(episode.started_at) }}</span>
                            </span>
                            <span v-if="episode.patient?.deleted_at" class="inline-flex shrink-0 items-center gap-1 rounded border border-gray-200 px-2 py-1 text-[10px] font-bold uppercase text-slate-500 dark:border-gray-800 dark:text-slate-400"><Icon name="archive" /> Dossier archivé</span>
                            <span v-else-if="!episode.patient" class="inline-flex shrink-0 items-center gap-1 rounded border border-amber-200 px-2 py-1 text-[10px] font-bold uppercase text-amber-700 dark:border-amber-900 dark:text-amber-300"><Icon name="alert-circle" /> Patient indisponible</span>
                            <span v-if="episode.priority === 'EMERGENCY'" class="inline-flex shrink-0 items-center gap-1 rounded border border-red-200 px-2 py-1 text-[10px] font-bold uppercase text-red-600 dark:border-red-900 dark:text-red-300">
                                <Icon name="alert-circle" /> Urgence
                            </span>
                        </component>
                    </div>
                    <p v-else class="px-5 py-10 text-center text-sm text-slate-400">Aucun passage récent.</p>
                </div>

                <div v-else>
                    <div v-if="presentVisitors.length" class="divide-y divide-gray-200 dark:divide-gray-900">
                        <div v-for="visitor in presentVisitors" :key="visitor.uuid" class="flex items-center gap-3 px-5 py-3">
                            <Avatar rounded size="sm" variant="slate-pale" :text="visitorInitials(visitor)" aria-hidden="true" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ visitor.full_name }}</span>
                                <span class="mt-0.5 block truncate text-xs text-slate-400">{{ visitorCategoryLabel(visitor.category) }}</span>
                            </span>
                            <span class="shrink-0 text-xs font-medium text-slate-500" :title="formatDateTime(visitor.checked_in_at)">
                                {{ formatRelativeTime(visitor.checked_in_at) }}
                            </span>
                        </div>
                    </div>
                    <p v-else class="px-5 py-10 text-center text-sm text-slate-400">Aucun visiteur présent.</p>
                </div>
            </Card>
        </section>
    </div>
</template>
