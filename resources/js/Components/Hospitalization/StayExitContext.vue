<script setup>
import { computed, ref } from 'vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { formatDateTime } from '@/utilities/date';
import { Activity, BedDouble, CircleAlert, CircleCheck, ClipboardList, ShieldAlert } from 'lucide-vue-next';

/**
 * ADR-162 — la colonne de repères de l'étape Sortie.
 *
 * Le formulaire garde toute sa largeur (ADR-132 l'avait sorti d'une colonne
 * étroite où il s'écrasait) ; cette colonne porte à côté ce que le médecin
 * relit en concluant, et que le formulaire ne montre pas : le séjour, les
 * allergies, le dernier relevé, et ce qui reste ouvert avant un acte définitif.
 *
 * Rien n'est ressaisi ni recalculé : tout vient de la page. Une section dont le
 * compte n'a pas le droit n'est pas servie (`null`) — elle se tait plutôt que
 * d'afficher un zéro qui se lirait « rien en cours » (ADR-102).
 */
const props = defineProps({
    stay: { type: Object, required: true },
    /** Le plus récent d'abord ; `null` sans `vitals.view`. */
    readings: { type: Array, default: null },
    /** Comptes de ce qui reste ouvert ; `null` quand la section n'est pas servie. */
    activePrescriptions: { type: Number, default: null },
    pendingExams: { type: Number, default: null },
    openSurgeries: { type: Number, default: null },
    openConsultations: { type: Number, default: 0 },
});

const emit = defineEmits(['navigate']);

const latest = computed(() => props.readings?.[0] ?? null);

// Un motif détaillé tiendrait la moitié de la colonne : replié à quatre lignes,
// il se lit en entier d'un clic.
const reasonOpen = ref(false);
const reasonIsLong = computed(() => (props.stay.request?.reason ?? '').length > 160);

// Jour de séjour, compté en jours calendaires. Cette colonne n'est jamais
// rendue côté serveur (l'onglet s'ouvre une fois la page montée) : lire
// l'heure ici ne peut pas désaccorder l'hydratation.
const stayDay = computed(() => {
    if (!props.stay.admitted_at) return null;
    const start = new Date(props.stay.admitted_at);
    const today = new Date();
    const days = Math.floor((Date.UTC(today.getFullYear(), today.getMonth(), today.getDate())
        - Date.UTC(start.getFullYear(), start.getMonth(), start.getDate())) / 86_400_000);

    return days + 1;
});

const bloodPressure = (reading) => (reading.blood_pressure_systolic && reading.blood_pressure_diastolic
    ? `${reading.blood_pressure_systolic}/${reading.blood_pressure_diastolic}`
    : null);

const vitals = computed(() => {
    const reading = latest.value;
    if (!reading) return [];

    return [
        ['TA', bloodPressure(reading), 'mmHg'],
        ['FC', reading.heart_rate, 'btt/mn'],
        ['SpO₂', reading.spo2, '%'],
        ['T°', reading.temperature_celsius, '°C'],
    ].filter(([, value]) => value !== null && value !== undefined && value !== '');
});

// Ce qui reste ouvert : l'ordonnance active devient le traitement de sortie,
// un examen sans résultat ou un bloc non terminé se relit avant de signer, et
// une consultation ouverte retient le passage hors de « Sorties & règlements ».
const checklist = computed(() => [
    props.activePrescriptions === null ? null : {
        key: 'ordonnances',
        count: props.activePrescriptions,
        pending: `${props.activePrescriptions} ordonnance${props.activePrescriptions > 1 ? 's' : ''} active${props.activePrescriptions > 1 ? 's' : ''}`,
        pendingHint: 'Reprise dans le traitement de sortie : décochez ce qui s’arrête.',
        done: 'Aucune ordonnance active',
        tone: 'info',
    },
    props.pendingExams === null ? null : {
        key: 'examens',
        count: props.pendingExams,
        pending: `${props.pendingExams} examen${props.pendingExams > 1 ? 's' : ''} sans résultat`,
        pendingHint: 'Le résultat arrivera après la sortie : prévoyez « Revenir avec les résultats ».',
        done: 'Aucun examen en attente de résultat',
        tone: 'warning',
    },
    props.openSurgeries === null ? null : {
        key: 'bloc',
        count: props.openSurgeries,
        pending: `${props.openSurgeries} passage${props.openSurgeries > 1 ? 's' : ''} au bloc non terminé${props.openSurgeries > 1 ? 's' : ''}`,
        pendingHint: 'Voyez avec l’équipe du bloc avant de faire sortir le patient.',
        done: 'Aucun passage au bloc en cours',
        tone: 'warning',
    },
    {
        key: 'consultations',
        count: props.openConsultations,
        pending: `${props.openConsultations} consultation${props.openConsultations > 1 ? 's' : ''} encore ouverte${props.openConsultations > 1 ? 's' : ''}`,
        pendingHint: 'Sans sa clôture, le passage n’atteindra pas « Sorties & règlements ».',
        done: 'Aucune consultation ouverte',
        tone: 'warning',
    },
].filter(Boolean));

const pendingCount = computed(() => checklist.value.filter((item) => item.count > 0 && item.tone === 'warning').length);
const alertVariant = (tone) => (tone === 'danger' ? 'destructive' : 'warning');
</script>

<template>
    <aside class="space-y-4" aria-label="Repères du séjour">
        <!-- Le séjour -->
        <Card class="p-4">
            <h3 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                <BedDouble class="h-3.5 w-3.5" aria-hidden="true" />Le séjour
            </h3>
            <dl class="mt-3 space-y-2.5 text-sm">
                <div>
                    <dt class="text-xs text-muted-foreground">Entré le</dt>
                    <dd class="text-foreground">
                        {{ formatDateTime(stay.admitted_at) }}
                        <span v-if="stayDay" class="ms-1 rounded bg-muted px-1.5 py-0.5 text-[11px] font-semibold text-muted-foreground">Jour {{ stayDay }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Service · chambre</dt>
                    <dd class="text-foreground">{{ [stay.service, stay.room_bed].filter(Boolean).join(' · ') || 'Non renseigné' }}</dd>
                </div>
                <div v-if="stay.care_level_label">
                    <dt class="text-xs text-muted-foreground">Niveau de soins</dt>
                    <dd class="text-foreground">{{ stay.care_level_label }}</dd>
                </div>
                <div v-if="stay.request?.reason">
                    <dt class="text-xs text-muted-foreground">Motif d’hospitalisation</dt>
                    <dd :class="['whitespace-pre-line text-foreground', reasonIsLong && !reasonOpen ? 'line-clamp-4' : '']">{{ stay.request.reason }}</dd>
                    <button
                        v-if="reasonIsLong"
                        type="button"
                        class="mt-1 text-[11px] font-semibold text-primary hover:underline"
                        :aria-expanded="reasonOpen"
                        @click="reasonOpen = !reasonOpen"
                    >{{ reasonOpen ? 'Replier' : 'Lire tout le motif' }}</button>
                </div>
            </dl>
        </Card>

        <!-- Allergies : relues avant tout traitement de sortie. -->
        <Card :class="['p-4', stay.allergies?.length ? 'border-destructive/40 bg-destructive/5' : '']">
            <h3 :class="['flex items-center gap-2 text-xs font-semibold uppercase tracking-wide', stay.allergies?.length ? 'text-destructive' : 'text-muted-foreground']">
                <ShieldAlert class="h-3.5 w-3.5" aria-hidden="true" />Allergies
            </h3>
            <p v-if="stay.allergies?.length" class="mt-2 text-sm font-medium text-destructive">{{ stay.allergies.join(', ') }}</p>
            <p v-else class="mt-2 text-sm text-muted-foreground">Aucune allergie connue au dossier.</p>
        </Card>

        <!-- Dernier relevé : utile pour l'état du patient à la sortie. -->
        <Card v-if="readings !== null" class="p-4">
            <div class="flex items-center justify-between gap-2">
                <h3 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    <Activity class="h-3.5 w-3.5" aria-hidden="true" />Dernier relevé
                </h3>
                <button type="button" class="text-[11px] font-semibold text-primary hover:underline" @click="emit('navigate', 'surveillance')">Surveillance</button>
            </div>
            <template v-if="latest">
                <p class="mt-1 text-[11px] text-muted-foreground">
                    {{ formatDateTime(latest.measured_at) }}<template v-if="latest.measured_by"> · {{ latest.measured_by }}</template>
                </p>
                <dl v-if="vitals.length" class="mt-2.5 grid grid-cols-2 gap-2">
                    <div v-for="[label, value, unit] in vitals" :key="label" class="rounded-md bg-muted/40 px-2.5 py-1.5">
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{{ label }}</dt>
                        <dd class="text-sm font-semibold tabular-nums text-foreground">{{ value }} <span class="text-[10px] font-normal text-muted-foreground">{{ unit }}</span></dd>
                    </div>
                </dl>
                <div v-if="latest.alerts?.length" class="mt-2 flex flex-wrap gap-1">
                    <Badge v-for="alert in latest.alerts" :key="alert.label" :variant="alertVariant(alert.tone)" :title="alert.message ?? alert.label">{{ alert.label }}</Badge>
                </div>
            </template>
            <p v-else class="mt-2 text-xs text-muted-foreground">Aucun relevé pendant le séjour ; celui de l’arrivée est dans la fiche Soins.</p>
        </Card>

        <!-- Avant de conclure : la sortie est un acte médical définitif. -->
        <Card class="p-4">
            <h3 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                <ClipboardList class="h-3.5 w-3.5" aria-hidden="true" />Avant de conclure
                <Badge v-if="pendingCount" variant="warning" class="ms-auto">{{ pendingCount }} à relire</Badge>
            </h3>
            <ul class="mt-3 space-y-2">
                <li v-for="item in checklist" :key="item.key">
                    <!-- Seul ce qui reste à relire mène quelque part. -->
                    <component
                        :is="item.count > 0 ? 'button' : 'div'"
                        :type="item.count > 0 ? 'button' : undefined"
                        :class="['flex w-full items-start gap-2 rounded-md px-1.5 py-1 text-start text-xs', item.count > 0 ? 'transition-colors hover:bg-accent' : '']"
                        @click="item.count > 0 && emit('navigate', item.key)"
                    >
                        <CircleCheck v-if="item.count === 0" class="mt-px h-3.5 w-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                        <CircleAlert v-else :class="['mt-px h-3.5 w-3.5 shrink-0', item.tone === 'warning' ? 'text-amber-600 dark:text-amber-400' : 'text-primary']" aria-hidden="true" />
                        <span class="min-w-0">
                            <span :class="item.count === 0 ? 'text-muted-foreground' : 'font-semibold text-foreground'">{{ item.count === 0 ? item.done : item.pending }}</span>
                            <span v-if="item.count > 0" class="mt-0.5 block text-[11px] text-muted-foreground">{{ item.pendingHint }}</span>
                        </span>
                    </component>
                </li>
            </ul>
        </Card>
    </aside>
</template>
