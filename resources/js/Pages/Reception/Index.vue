<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { AlertTriangle, ArrowRight, ClipboardPlus, Clock3, LayoutDashboard, UserRoundPlus, UsersRound } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Shadcn/Avatar.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime, formatRelativeTime } from '@/utilities/date';
import { formatPatientInitials, formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

const props = defineProps({ recentEpisodes: Array, presentVisitors: Array });
const { can } = usePermissions();
const canReceivePatients = computed(() => can('episodes.create'));
const canViewVisitors = computed(() => can('visitors.view'));
const activityTab = ref(canReceivePatients.value ? 'patients' : 'visitors');
const episodePatientHref = (episode) => episode.patient?.uuid && !episode.patient.deleted_at ? `/patients/${episode.patient.uuid}` : null;
const visitorInitials = (visitor) => visitor.full_name.trim().split(/\s+/).slice(0, 2).map((part) => part[0]?.toUpperCase()).join('');
const visitorCategoryLabel = (category) => category === 'PROFESSIONAL' ? 'Professionnel' : 'Visite patient / famille';
</script>

<template>
    <Head title="Réception" />

    <div class="mx-auto w-full max-w-[1480px] space-y-5">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex items-start gap-3.5">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary ring-1 ring-primary/15"><LayoutDashboard class="h-5 w-5" /></span>
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-primary">Accueil clinique</p>
                    <h1 class="mt-1 font-heading text-2xl font-bold tracking-tight text-foreground">Réception</h1>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground">Choisissez le parcours adapté à la personne accueillie, puis suivez l’activité récente.</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Badge v-if="canReceivePatients" variant="secondary"><Clock3 class="h-3.5 w-3.5" />{{ recentEpisodes.length }} passage{{ recentEpisodes.length > 1 ? 's' : '' }} récent{{ recentEpisodes.length > 1 ? 's' : '' }}</Badge>
                <Badge v-if="canViewVisitors" variant="success"><span class="h-1.5 w-1.5 rounded-full bg-current" />{{ presentVisitors.length }} présent{{ presentVisitors.length > 1 ? 's' : '' }}</Badge>
            </div>
        </header>

        <section class="grid gap-4 lg:grid-cols-2" aria-label="Parcours de réception">
            <Link v-if="canReceivePatients" href="/reception/patients" class="group relative overflow-hidden rounded-xl border border-border bg-card p-6 text-card-foreground shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/35 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30">
                <span class="absolute inset-x-0 top-0 h-1 bg-primary" />
                <span class="flex min-h-32 items-start gap-4">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary"><UserRoundPlus class="h-5 w-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center justify-between gap-4"><span class="font-heading text-lg font-bold text-foreground">Réception patient</span><ArrowRight class="h-5 w-5 text-muted-foreground transition-transform group-hover:translate-x-1 group-hover:text-primary" /></span>
                        <span class="mt-2 block max-w-xl text-sm leading-6 text-muted-foreground">Rechercher ou créer un dossier, enregistrer le passage et déclencher le parcours normal ou urgent.</span>
                        <span class="mt-4 inline-flex items-center gap-2 text-xs font-semibold text-primary"><ClipboardPlus class="h-4 w-4" />Nouvelle prise en charge</span>
                    </span>
                </span>
            </Link>

            <Link v-if="canViewVisitors" href="/reception/visitors" class="group relative overflow-hidden rounded-xl border border-border bg-card p-6 text-card-foreground shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/35 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30">
                <span class="absolute inset-x-0 top-0 h-1 bg-muted-foreground/40" />
                <span class="flex min-h-32 items-start gap-4">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-secondary text-secondary-foreground"><UsersRound class="h-5 w-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center justify-between gap-4"><span class="font-heading text-lg font-bold text-foreground">Réception visiteur</span><ArrowRight class="h-5 w-5 text-muted-foreground transition-transform group-hover:translate-x-1 group-hover:text-primary" /></span>
                        <span class="mt-2 block max-w-xl text-sm leading-6 text-muted-foreground">Enregistrer les visites professionnelles et celles auprès d’un patient ou de sa famille.</span>
                        <span class="mt-4 inline-flex items-center gap-2 text-xs font-semibold text-muted-foreground"><span class="h-2 w-2 rounded-full bg-emerald-500" />{{ presentVisitors.length }} visiteur{{ presentVisitors.length > 1 ? 's' : '' }} sur site</span>
                    </span>
                </span>
            </Link>
        </section>

        <Card v-if="canReceivePatients || canViewVisitors" class="overflow-hidden">
            <div class="flex flex-col gap-3 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="inline-flex w-fit items-center gap-1 rounded-lg border border-border bg-muted/40 p-1" role="tablist" aria-label="Activité de la réception">
                    <button v-if="canReceivePatients" type="button" :class="['rounded-md px-3 py-2 text-xs font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30', activityTab === 'patients' ? 'bg-card text-primary shadow-sm' : 'text-muted-foreground hover:text-foreground']" role="tab" :aria-selected="activityTab === 'patients'" @click="activityTab = 'patients'">Passages patients <span class="ms-1 opacity-60">{{ recentEpisodes.length }}</span></button>
                    <button v-if="canViewVisitors" type="button" :class="['rounded-md px-3 py-2 text-xs font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30', activityTab === 'visitors' ? 'bg-card text-primary shadow-sm' : 'text-muted-foreground hover:text-foreground']" role="tab" :aria-selected="activityTab === 'visitors'" @click="activityTab = 'visitors'">Visiteurs présents <span class="ms-1 opacity-60">{{ presentVisitors.length }}</span></button>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <p class="text-xs text-muted-foreground">{{ activityTab === 'patients' ? 'Arrivées des dernières 24 heures' : 'Entrées sans sortie enregistrée' }}</p>
                    <Button :as="Link" :href="activityTab === 'patients' ? '/reception/patients' : '/reception/visitors'" size="sm" variant="outline">{{ activityTab === 'patients' ? 'Ouvrir le parcours' : 'Ouvrir le registre' }}<ArrowRight class="h-4 w-4" /></Button>
                </div>
            </div>

            <div v-if="activityTab === 'patients'">
                <div v-if="recentEpisodes.length" class="divide-y divide-border">
                    <component :is="episodePatientHref(episode) ? Link : 'div'" v-for="episode in recentEpisodes" :key="episode.id" :href="episodePatientHref(episode) || undefined" :class="['flex items-center gap-3 px-5 py-3.5', episodePatientHref(episode) ? 'transition-colors hover:bg-muted/45' : 'bg-muted/30']">
                        <Avatar size="sm" :initials="formatPatientInitials(episode.patient)" :emergency="episode.priority === 'EMERGENCY'" aria-hidden="true" />
                        <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold text-foreground">{{ formatPatientName(episode.patient) }}</span><span class="mt-0.5 block text-xs text-muted-foreground">{{ episode.episode_number }} · {{ formatRelativeTime(episode.started_at) }}</span></span>
                        <Badge v-if="episode.patient?.deleted_at" variant="outline">Dossier archivé</Badge>
                        <Badge v-else-if="!episode.patient" variant="warning"><AlertTriangle class="h-3 w-3" />Patient indisponible</Badge>
                        <Badge v-if="episode.priority === 'EMERGENCY'" variant="destructive"><AlertTriangle class="h-3 w-3" />Urgence</Badge>
                    </component>
                </div>
                <div v-else class="px-5 py-12 text-center"><Clock3 class="mx-auto h-8 w-8 text-muted-foreground/50" /><p class="mt-3 text-sm font-medium text-foreground">Aucune arrivée depuis 24 heures</p><p class="mt-1 text-xs text-muted-foreground">Les passages plus anciens restent dans le dossier du patient et dans « Sorties &amp; règlements ».</p></div>
            </div>

            <div v-else>
                <div v-if="presentVisitors.length" class="divide-y divide-border">
                    <div v-for="visitor in presentVisitors" :key="visitor.uuid" class="flex items-center gap-3 px-5 py-3.5">
                        <Avatar size="sm" :initials="visitorInitials(visitor)" aria-hidden="true" />
                        <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold text-foreground">{{ visitor.full_name }}</span><span class="mt-0.5 block truncate text-xs text-muted-foreground">{{ visitorCategoryLabel(visitor.category) }}</span></span>
                        <span class="shrink-0 text-xs font-medium text-muted-foreground" :title="formatDateTime(visitor.checked_in_at)">{{ formatRelativeTime(visitor.checked_in_at) }}</span>
                    </div>
                </div>
                <div v-else class="px-5 py-12 text-center"><UsersRound class="mx-auto h-8 w-8 text-muted-foreground/50" /><p class="mt-3 text-sm font-medium text-foreground">Aucun visiteur présent</p><p class="mt-1 text-xs text-muted-foreground">Le registre est à jour.</p></div>
            </div>
        </Card>
    </div>
</template>
