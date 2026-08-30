<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/UI/Icon.vue';
import ActivityTrendChart from '@/Components/Dashboard/ActivityTrendChart.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({
    overview: {
        type: Object,
        default: () => ({ generated_at: null, metrics: [], trend: { dates: [], series: [] } }),
    },
});

const page = usePage();
const { can } = usePermissions();
const user = computed(() => page.props.auth?.user ?? {});
const roleName = computed(() => user.value.role?.name ?? 'Profil opérationnel');
const userInitials = computed(() => user.value.name
    ?.split(' ')
    .filter(Boolean)
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase() ?? '');
const generatedDate = computed(() => {
    const date = props.overview.generated_at ? new Date(props.overview.generated_at) : new Date();
    const value = new Intl.DateTimeFormat('fr-FR', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(date);

    return value.charAt(0).toLocaleUpperCase('fr-FR') + value.slice(1);
});

const workspaceCatalog = [
    { title: 'Réception', description: 'Passages, urgences et orientation initiale.', icon: 'card-view', link: '/reception', permission: 'reception.view', tone: 'navy' },
    { title: 'Caisse', description: 'Factures, règlements et session de caisse.', icon: 'wallet', link: '/cash', permission: 'cash.view', tone: 'green' },
    { title: 'Patients', description: 'Dossiers administratifs et historique des passages.', icon: 'users', link: '/patients', permission: 'patients.view', tone: 'cyan' },
    { title: 'Médecine', description: 'File d’attente et consultations médicales.', icon: 'activity', link: '/medicine', permission: 'consultations.view', tone: 'ocean' },
    { title: 'Soins', description: 'Orientations, constantes et fiches de soins.', icon: 'user-check', link: '/care', permission: 'care.view', tone: 'green' },
    { title: 'Chirurgie', description: 'Demandes, programmation et suivi du Bloc.', icon: 'masks', link: '/surgery', permission: 'surgery.view', tone: 'yellow' },
    { title: 'Anesthésie', description: 'Évaluations et dossiers anesthésiques autorisés.', icon: 'shield-check', link: '/anesthesia', permission: 'anesthesia.view', tone: 'cyan' },
    { title: 'Pharmacie', description: 'Ordonnances, délivrances, lots et stock.', icon: 'capsule', link: '/pharmacy', permission: 'pharmacy.view', tone: 'green' },
    { title: 'Ressources humaines', description: 'Employés et opérations RH autorisées.', icon: 'briefcase', link: '/administration', permission: 'employees.view', tone: 'navy' },
    { title: 'Logistique', description: 'Inventaire et équipements de la clinique.', icon: 'package', link: '/logistics', permission: 'logistics.view', tone: 'yellow' },
    { title: 'Gardiennage', description: 'Présences visiteurs et contrôle des sorties.', icon: 'shield-check', link: '/reception/visitors', permission: 'guarding.view', tone: 'ocean' },
    { title: 'Utilisateurs & accès', description: 'Comptes locaux et permissions attribuées.', icon: 'users', link: '/administration/users', permission: 'users.view', tone: 'cyan' },
    { title: 'Référentiels & tarifs', description: 'Désignations, routage et grilles tarifaires.', icon: 'setting-alt', link: '/administration/catalog', permission: 'catalog.items.view', tone: 'navy' },
];

const workspaces = computed(() => workspaceCatalog.filter((workspace) => can(workspace.permission)));
const primaryAction = computed(() => {
    if (can('episodes.create')) {
        return {
            label: 'Nouvelle prise en charge',
            link: '/reception/patients',
            icon: 'plus',
        };
    }

    const workspace = workspaces.value[0];

    return workspace ? {
        label: `Ouvrir ${workspace.title}`,
        link: workspace.link,
        icon: 'arrow-right',
    } : null;
});

const toneClasses = {
    navy: { icon: 'bg-primary-100 text-primary-800 dark:bg-primary-950/60 dark:text-primary-300', value: 'text-primary-800 dark:text-primary-300' },
    ocean: { icon: 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300', value: 'text-sky-800 dark:text-sky-300' },
    cyan: { icon: 'bg-cyan-100 text-cyan-800 dark:bg-cyan-950/60 dark:text-cyan-300', value: 'text-cyan-800 dark:text-cyan-300' },
    green: { icon: 'bg-green-100 text-green-800 dark:bg-green-950/60 dark:text-green-300', value: 'text-green-800 dark:text-green-300' },
    yellow: { icon: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-950/60 dark:text-yellow-300', value: 'text-yellow-800 dark:text-yellow-300' },
};
const tone = (name) => toneClasses[name] ?? toneClasses.navy;
</script>

<template>
    <Head title="Vue d’ensemble" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-7">
        <header class="flex flex-col gap-5 border-b border-gray-200 pb-6 sm:flex-row sm:items-end sm:justify-between dark:border-gray-900">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary-700 dark:text-primary-300">
                    {{ page.props.site.name }} · Espace opérationnel
                </p>
                <h1 class="mt-2 font-heading text-2xl font-bold tracking-tight text-slate-800 dark:text-white">Vue d’ensemble</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500 dark:text-slate-400">
                    Bonjour {{ user.name }}. Voici l’activité accessible à votre profil aujourd’hui.
                </p>
            </div>

            <div class="flex flex-col gap-3 sm:items-end">
                <p class="text-xs text-slate-400">{{ generatedDate }}</p>
                <Link
                    v-if="primaryAction"
                    :href="primaryAction.link"
                    class="inline-flex items-center justify-center rounded-md bg-primary-700 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition-colors hover:bg-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300 focus-visible:ring-offset-2 dark:bg-primary-600 dark:hover:bg-primary-500"
                >
                    <Icon class="me-2 text-base" :name="primaryAction.icon" />{{ primaryAction.label }}
                </Link>
            </div>
        </header>

        <section v-if="overview.metrics?.length">
            <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="font-heading text-base font-bold text-slate-700 dark:text-white">Activité aujourd’hui</h2>
                    <p class="mt-1 text-xs text-slate-400">Indicateurs calculés à partir des données autorisées.</p>
                </div>
                <span class="text-xs text-slate-400">Mise à jour à l’ouverture de la page</span>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:flex xl:flex-nowrap xl:overflow-x-auto xl:pb-1">
                <component
                    :is="metric.href ? Link : 'article'"
                    v-for="metric in overview.metrics"
                    :key="metric.key"
                    :href="metric.href || undefined"
                    class="group rounded-lg border border-gray-200 bg-white p-4 shadow-sm transition-colors dark:border-gray-900 dark:bg-gray-950 xl:min-w-[170px] xl:flex-1"
                    :class="metric.href ? 'hover:border-primary-300 dark:hover:border-primary-800' : ''"
                >
                    <div class="flex items-start justify-between gap-4">
                        <span :class="['flex h-8 w-8 items-center justify-center rounded-md text-base', tone(metric.tone).icon]"><Icon :name="metric.icon" /></span>
                        <p :class="['font-heading text-2xl font-bold leading-none', tone(metric.tone).value]">{{ metric.value }}</p>
                    </div>
                    <div class="mt-3 flex items-center justify-between gap-3">
                        <h3 class="text-[13px] font-bold leading-5 text-slate-700 dark:text-white">{{ metric.label }}</h3>
                        <Icon v-if="metric.href" class="shrink-0 text-slate-300 transition-colors group-hover:text-primary-600" name="arrow-right" />
                    </div>
                    <p class="mt-1 text-[11px] leading-4 text-slate-400">{{ metric.description }}</p>
                </component>
            </div>
        </section>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">
            <ActivityTrendChart :trend="overview.trend" />

            <aside class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <div class="border-b border-gray-100 p-5 dark:border-gray-900">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-primary-100 font-heading text-sm font-bold text-primary-800 dark:bg-primary-950 dark:text-primary-300">{{ userInitials }}</span>
                        <div class="min-w-0">
                            <h2 class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ user.name }}</h2>
                            <p class="mt-0.5 truncate text-xs text-slate-400">{{ roleName }}</p>
                        </div>
                    </div>
                </div>

                <dl class="divide-y divide-gray-100 px-5 text-xs dark:divide-gray-900">
                    <div class="flex items-center justify-between gap-4 py-3.5"><dt class="text-slate-400">Établissement</dt><dd class="text-end font-semibold text-slate-600 dark:text-slate-300">{{ page.props.site.name }}</dd></div>
                    <div class="flex items-center justify-between gap-4 py-3.5"><dt class="text-slate-400">Code du site</dt><dd class="font-mono font-bold text-slate-600 dark:text-slate-300">{{ page.props.site.code }}</dd></div>
                    <div class="flex items-center justify-between gap-4 py-3.5"><dt class="text-slate-400">Espaces accessibles</dt><dd class="font-bold text-slate-600 dark:text-slate-300">{{ workspaces.length }}</dd></div>
                </dl>

                <div class="m-5 border-t border-gray-100 pt-4 dark:border-gray-900">
                    <p class="flex items-start gap-2 text-xs leading-5 text-slate-400">
                        <Icon class="mt-0.5 shrink-0 text-base text-primary-600 dark:text-primary-300" name="shield-check" />
                        Les données et raccourcis affichés suivent vos permissions individuelles.
                    </p>
                </div>
            </aside>
        </div>

        <section class="min-w-0">
            <div class="mb-3">
                <h2 class="font-heading text-base font-bold text-slate-700 dark:text-white">Vos espaces de travail</h2>
                <p class="mt-1 text-xs text-slate-400">Accès directs aux modules attribués à votre compte.</p>
            </div>

            <div v-if="workspaces.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Link v-for="workspace in workspaces" :key="workspace.title" :href="workspace.link" class="group flex min-h-[118px] items-start gap-4 rounded-lg border border-gray-200 bg-white p-5 shadow-sm transition-colors hover:border-primary-300 dark:border-gray-900 dark:bg-gray-950 dark:hover:border-primary-800">
                    <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-md text-lg', tone(workspace.tone).icon]"><Icon :name="workspace.icon" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-start justify-between gap-3">
                            <strong class="text-sm text-slate-700 dark:text-white">{{ workspace.title }}</strong>
                            <Icon class="shrink-0 text-slate-300 transition-colors group-hover:text-primary-600" name="arrow-right" />
                        </span>
                        <span class="mt-1 block text-xs leading-5 text-slate-400">{{ workspace.description }}</span>
                    </span>
                </Link>
            </div>

            <div v-else class="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-10 text-center dark:border-gray-800 dark:bg-gray-950">
                <h3 class="text-sm font-bold text-slate-700 dark:text-white">Aucun espace métier attribué</h3>
                <p class="mx-auto mt-1 max-w-md text-xs leading-5 text-slate-400">Votre compte est actif, mais aucune permission de module ne lui est actuellement accordée. Contactez l’administrateur local.</p>
            </div>
        </section>
    </div>
</template>
