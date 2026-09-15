<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { CalendarCheck, CircleAlert, Stethoscope, UserRound } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import { financialModeLabel as modeLabel } from '@/utilities/financialMode';
import { formatDate, formatDayTime } from '@/utilities/date';
import { formatPatientInitials } from '@/utilities/patient';

/**
 * L'en-tête clinique : qui est ce patient, pourquoi il est là, et les
 * actions qui l'entourent — sur une seule ligne.
 *
 * Trois zones lues de gauche à droite, séparées par un filet plutôt que par
 * des cadres : l'identité, les repères de la rencontre, les actions. Aucune
 * constante ici : elles ont leur propre bandeau, et les mêler à l'identité
 * produisait la ligne tassée que cet écran avait.
 *
 * Les repères cliniques (`consultationType`, `doctor`, `startedAt`) sont
 * facultatifs : Soins et Maternité partagent ce composant sans en passer
 * aucun et gardent un en-tête d'identité seule.
 */
const props = defineProps({
    patient: { type: Object, required: true },
    episode: { type: Object, required: true },
    reason: { type: String, default: null },
    backHref: { type: String, default: null },
    backLabel: { type: String, default: 'Retour' },
    consultationType: { type: String, default: null },
    doctor: { type: String, default: null },
    startedAt: { type: String, default: null },
    showIdentity: { type: Boolean, default: true },
});

const isEmergency = computed(() => props.episode.priority === 'EMERGENCY');
/** Les initiales du patient, pas son sexe : c'est son dossier qu'on ouvre. */
const initials = computed(() => formatPatientInitials(props.patient));
const sexLabel = computed(() => (props.patient.sex === 'F' ? 'Femme' : 'Homme'));
const sexSymbol = computed(() => (props.patient.sex === 'F' ? '♀' : '♂'));
const birthDate = computed(() => formatDate(props.patient.birth_date));
const financialMode = computed(() => modeLabel(props.episode.financial_mode));

/**
 * Composés ici et non dans le template : un repère sans valeur disparaît
 * au lieu d'afficher un tiret muet, et la mise en page reste une boucle.
 */
const facts = computed(() => [
    {
        key: 'motive',
        icon: Stethoscope,
        label: 'Consultation en cours',
        value: props.consultationType,
    },
    {
        key: 'doctor',
        icon: UserRound,
        label: 'Médecin',
        value: props.doctor ? `Dr ${props.doctor}` : null,
    },
    {
        key: 'when',
        icon: CalendarCheck,
        label: 'Prise en charge',
        value: formatDayTime(props.startedAt),
    },
].filter((fact) => Boolean(fact.value)));
</script>

<template>
    <header
        :class="['rounded-xl border bg-card shadow-sm', isEmergency
            ? 'border-destructive/40 border-s-4 border-s-destructive'
            : 'border-border']"
    >
        <div class="flex flex-col gap-4 px-4 py-3 2xl:flex-row 2xl:items-center 2xl:gap-6">
            <!-- QUI -->
            <div v-if="showIdentity" class="flex min-w-0 items-center gap-3 2xl:shrink-0">
                <span
                    :class="['flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-bold tracking-wide', isEmergency
                        ? 'bg-destructive text-destructive-foreground'
                        : 'bg-primary/10 text-primary']"
                    aria-hidden="true"
                >{{ initials }}</span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="truncate text-lg font-bold leading-tight tracking-tight text-foreground">
                            {{ patient.first_name }} {{ patient.last_name }}
                            <span class="font-normal text-muted-foreground" aria-hidden="true">{{ sexSymbol }}</span>
                            <span class="sr-only">, {{ sexLabel }}</span>
                        </h1>
                        <span
                            v-if="isEmergency"
                            class="inline-flex items-center gap-1 rounded-md bg-destructive px-2 py-0.5 text-xs font-bold uppercase tracking-wide text-destructive-foreground"
                        >
                            <CircleAlert class="h-3 w-3" aria-hidden="true" />Urgence
                        </span>
                    </div>
                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm text-muted-foreground">
                        <span v-if="patient.age !== null && patient.age !== undefined">{{ patient.age }} ans</span>
                        <template v-if="birthDate">
                            <span class="text-border" aria-hidden="true">|</span>
                            <span>Né{{ patient.sex === 'F' ? 'e' : '' }} le {{ birthDate }}</span>
                        </template>
                        <span class="text-border" aria-hidden="true">|</span>
                        <span>Dossier <span class="font-medium text-foreground">{{ patient.patient_number }}</span></span>
                        <span class="text-border" aria-hidden="true">|</span>
                        <span>Passage <span class="font-medium text-foreground">{{ episode.episode_number }}</span></span>
                        <template v-if="financialMode">
                            <span class="text-border" aria-hidden="true">|</span><span>{{ financialMode }}</span>
                        </template>
                    </p>
                </div>
            </div>

            <!-- POURQUOI -->
            <dl
                v-if="facts.length"
                class="flex min-w-0 flex-1 flex-wrap items-center gap-x-8 gap-y-3 2xl:border-s 2xl:border-border 2xl:ps-6"
            >
                <div v-for="fact in facts" :key="fact.key" class="flex min-w-0 items-center gap-2.5">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden="true">
                        <component :is="fact.icon" class="h-4 w-4" />
                    </span>
                    <div class="min-w-0">
                        <dt class="text-xs leading-4 text-muted-foreground">{{ fact.label }}</dt>
                        <dd class="truncate text-sm font-semibold leading-5 text-foreground">{{ fact.value }}</dd>
                    </div>
                </div>
            </dl>

            <!-- ACTIONS -->
            <div class="flex shrink-0 flex-wrap items-center gap-2 2xl:justify-end">
                <slot name="actions" />
                <Button v-if="backHref" :as="Link" :href="backHref" size="sm" variant="white-outline">
                    {{ backLabel }}
                </Button>
            </div>
        </div>

        <p v-if="reason" class="truncate border-t border-border px-4 py-2 text-xs text-muted-foreground" :title="reason">
            <span class="font-medium text-foreground">Orientation :</span> {{ reason }}
        </p>

        <slot />
    </header>
</template>
