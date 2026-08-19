<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Icon from '@/Components/UI/Icon.vue';

defineOptions({
    layout: AppLayout,
});

const props = defineProps({
    patient: Object,
});

const sexLabel = (sex) => (sex === 'M' ? 'Masculin' : 'Féminin');

const initials = (patient) => `${patient.first_name?.[0] ?? ''}${patient.last_name?.[0] ?? ''}`.toUpperCase();

const administrativeStatusLabels = {
    PENDING_ORIENTATION: 'En attente d’orientation',
    ORIENTED: 'Orienté',
    IN_CARE: 'En cours de soins',
    PENDING_SETTLEMENT: 'En attente de règlement',
    DISCHARGED: 'Sorti',
};

const statusLabels = {
    OPEN: 'Ouvert',
    CLOSED: 'Clos',
    CANCELLED: 'Annulé',
};

const statusBadgeClass = (status) => ({
    OPEN: 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400',
    CLOSED: 'bg-slate-100 text-slate-600 dark:bg-slate-900 dark:text-slate-400',
    CANCELLED: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-400',
}[status] ?? 'bg-slate-100 text-slate-600');

const severityLabels = {
    MILD: 'Légère',
    MODERATE: 'Modérée',
    SEVERE: 'Sévère',
};
</script>

<template>
    <Head :title="`${patient.last_name} ${patient.first_name}`" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <Avatar size="lg" variant="primary-pale" :text="initials(patient)" />
                <div>
                    <h1 class="font-heading text-2xl font-bold text-slate-700 dark:text-white">
                        {{ patient.last_name }} {{ patient.first_name }}
                    </h1>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ patient.patient_number }} · {{ sexLabel(patient.sex) }} · né(e) le {{ patient.birth_date }}
                    </p>
                </div>
            </div>

            <Link :href="`/patients/${patient.id}/episodes`" method="post" as="button">
                <Button size="rg" variant="primary">
                    <Icon class="text-xl/4.5" name="plus" />
                    <span class="ms-2">Nouveau passage</span>
                </Button>
            </Link>
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <!-- Identity & contact -->
            <div class="space-y-5 lg:col-span-1">
                <div class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950">
                    <h2 class="mb-4 text-sm font-bold uppercase tracking-wide text-slate-500">Identité</h2>
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                            <Icon class="text-slate-400" name="call" />
                            <span>{{ patient.phone ?? 'Non renseigné' }}</span>
                        </div>
                        <div class="flex items-start gap-2 text-slate-600 dark:text-slate-300">
                            <Icon class="mt-0.5 text-slate-400" name="map-pin" />
                            <span>{{ patient.address ?? 'Non renseignée' }}</span>
                        </div>
                    </dl>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950">
                    <h2 class="mb-4 text-sm font-bold uppercase tracking-wide text-slate-500">Personne à contacter</h2>
                    <div v-if="patient.emergency_contact_name" class="space-y-1 text-sm text-slate-600 dark:text-slate-300">
                        <p class="font-medium text-slate-700 dark:text-white">{{ patient.emergency_contact_name }}</p>
                        <p v-if="patient.emergency_contact_relationship">{{ patient.emergency_contact_relationship }}</p>
                        <p v-if="patient.emergency_contact_phone">{{ patient.emergency_contact_phone }}</p>
                    </div>
                    <p v-else class="text-sm text-slate-400">Non renseignée.</p>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-slate-500">
                        <Icon class="text-red-500" name="alert-circle" />
                        Allergies
                    </h2>
                    <ul v-if="patient.allergies.length > 0" class="space-y-2 text-sm">
                        <li v-for="allergy in patient.allergies" :key="allergy.id" class="text-slate-600 dark:text-slate-300">
                            <span class="font-medium text-slate-700 dark:text-white">{{ allergy.substance }}</span>
                            <span v-if="allergy.severity" class="ms-1 text-xs text-red-500">({{ severityLabels[allergy.severity] }})</span>
                            <p v-if="allergy.reaction" class="text-xs text-slate-400">{{ allergy.reaction }}</p>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-400">Aucune allergie connue.</p>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950">
                    <h2 class="mb-4 text-sm font-bold uppercase tracking-wide text-slate-500">Antécédents médicaux</h2>
                    <ul v-if="patient.antecedents.length > 0" class="space-y-2 text-sm text-slate-600 dark:text-slate-300">
                        <li v-for="antecedent in patient.antecedents" :key="antecedent.id">
                            {{ antecedent.description }}
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-400">Aucun antécédent connu.</p>
                </div>
            </div>

            <!-- Episodes -->
            <div class="lg:col-span-2">
                <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
                    <h2 class="border-b border-gray-200 p-5 text-sm font-bold uppercase tracking-wide text-slate-500 dark:border-gray-900">
                        Historique des passages
                    </h2>

                    <div v-if="patient.episodes.length === 0" class="p-10 text-center text-sm text-slate-400">
                        Aucun passage enregistré.
                    </div>

                    <ul v-else class="divide-y divide-gray-200 dark:divide-gray-900">
                        <li v-for="episode in patient.episodes" :key="episode.id" class="flex flex-wrap items-center justify-between gap-3 p-5">
                            <div>
                                <p class="text-sm font-medium text-slate-700 dark:text-white">
                                    {{ episode.episode_number }}
                                    <span :class="['ms-2 rounded px-2 py-0.5 text-xs font-medium', statusBadgeClass(episode.status)]">
                                        {{ statusLabels[episode.status] }}
                                    </span>
                                </p>
                                <p class="mt-1 text-xs text-slate-400">
                                    Démarré le {{ episode.started_at }} · {{ administrativeStatusLabels[episode.administrative_status] }}
                                </p>
                            </div>

                            <Link
                                v-if="episode.status === 'OPEN' && episode.administrative_status === 'PENDING_ORIENTATION'"
                                :href="`/episodes/${episode.id}/orient`"
                                method="post"
                                as="button"
                            >
                                <Button size="sm" variant="white-outline">Orienter</Button>
                            </Link>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>
