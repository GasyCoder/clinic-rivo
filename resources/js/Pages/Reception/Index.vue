<script setup>
import { computed } from 'vue';
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

        <section class="grid gap-5 xl:grid-cols-2">
            <Card v-if="canReceivePatients" class="overflow-hidden shadow-sm">
                <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                    <div>
                        <h2 class="text-sm font-bold text-slate-700 dark:text-white">Passages patients récents</h2>
                        <p class="mt-0.5 text-xs text-slate-400">Dernières arrivées enregistrées.</p>
                    </div>
                    <Button :as="Link" href="/reception/patients" size="sm" variant="white-outline">Voir le parcours</Button>
                </div>

                <div v-if="recentEpisodes.length" class="divide-y divide-gray-200 dark:divide-gray-900">
                    <Link
                        v-for="episode in recentEpisodes"
                        :key="episode.id"
                        :href="`/patients/${episode.patient.uuid}`"
                        class="flex items-center gap-3 px-5 py-3 transition hover:bg-gray-50 dark:hover:bg-gray-1000"
                    >
                        <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(episode.patient)" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-bold text-slate-700 dark:text-white">{{ formatPatientName(episode.patient) }}</span>
                            <span class="mt-0.5 block text-xs text-slate-400">{{ episode.episode_number }} · {{ formatRelativeTime(episode.started_at) }}</span>
                        </span>
                        <span v-if="episode.priority === 'EMERGENCY'" class="inline-flex shrink-0 items-center gap-1 rounded border border-red-200 px-2 py-1 text-[10px] font-bold uppercase text-red-600 dark:border-red-900 dark:text-red-300">
                            <Icon name="alert-circle" /> Urgence
                        </span>
                    </Link>
                </div>
                <p v-else class="px-5 py-10 text-center text-sm text-slate-400">Aucun passage récent.</p>
            </Card>

            <Card v-if="canViewVisitors" class="overflow-hidden shadow-sm">
                <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                    <div>
                        <h2 class="text-sm font-bold text-slate-700 dark:text-white">Visiteurs présents</h2>
                        <p class="mt-0.5 text-xs text-slate-400">Entrées sans sortie enregistrée.</p>
                    </div>
                    <Button :as="Link" href="/reception/visitors" size="sm" variant="white-outline">Ouvrir le registre</Button>
                </div>

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
            </Card>
        </section>
    </div>
</template>
