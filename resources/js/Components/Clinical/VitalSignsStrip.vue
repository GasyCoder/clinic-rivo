<script setup>
import { computed, ref } from 'vue';
import { Activity, ArrowDown, ArrowUp, ChevronRight, Gauge, HeartPulse, ShieldAlert, Thermometer, TriangleAlert, Wind } from 'lucide-vue-next';
import { formatTime } from '@/utilities/date';
import { cn } from '@/lib/cn';

/**
 * Les constantes du passage — **un seul bloc**, valeurs et anomalies
 * comprises.
 *
 * Elles ont d'abord vécu dans deux bandeaux : les valeurs ici, les alertes
 * juste en dessous. Les deux affichaient exactement les mêmes quatre
 * mesures, si bien que « 32 °C » se lisait deux fois à dix pixels
 * d'intervalle. Une anomalie n'est pas une information distincte de la
 * constante : c'est son interprétation, et elle appartient donc à la même
 * pastille.
 *
 * Trois règles portent ce composant :
 *
 * 1. **Jamais la couleur seule.** Une anomalie porte une icône, une flèche
 *    de sens, un libellé écrit et un `aria-label` complet.
 * 2. **Aucun seuil calculé ici.** Les classifications viennent du serveur
 *    (`CareRecordReadModel`, ADR-038 à ADR-041) ; une seconde
 *    implémentation des bornes finirait par diverger de la première. Ce
 *    n'est pas un moteur de diagnostic : ce sont des alertes de sécurité.
 * 3. **Le moment compte.** « à l'arrivée » n'est pas un détail : ces
 *    valeurs ont été relevées par les Soins, pas pendant la consultation.
 */
const props = defineProps({
    careRecord: { type: Object, default: null },
    /** Tension déjà composée « 170/120 » par le parent. */
    bloodPressure: { type: String, default: null },
    /** Allergies du dossier permanent, résumées en fin de bandeau. */
    allergies: { type: Array, default: () => [] },
    /** Horodatage du relevé — `null` masque le repère plutôt que d'en inventer un. */
    recordedAt: { type: String, default: null },
});

const showDetails = ref(false);

const ICONS = {
    blood_pressure: Gauge,
    heart_rate: HeartPulse,
    spo2: Wind,
    temperature: Thermometer,
};

/**
 * Le sens de l'écart, pas seulement sa gravité : 32 °C et 40 °C sont tous
 * deux critiques mais appellent des gestes opposés. Déduit du code renvoyé
 * par le serveur, jamais d'une comparaison refaite ici.
 */
const directionOf = (assessment) => {
    const code = assessment?.code ?? '';

    if (/LOW|HYPO|BRADY/.test(code)) return 'down';
    if (/HIGH|HYPER|FEVER|TACHY/.test(code)) return 'up';

    return null;
};

const severityOf = (assessment) => {
    if (!assessment || assessment.tone === 'success') return 'normal';

    return assessment.tone === 'danger' ? 'critical' : 'warning';
};

/**
 * « SpO₂ très basse » sous une pastille déjà intitulée « SpO₂ » répète
 * l'abréviation. On retire ce préfixe quand le serveur l'a mis ; les
 * libellés qui n'en portent pas — « Hypothermie possible » — passent
 * inchangés.
 */
const interpretationOf = (assessment, short) => {
    const label = assessment?.label;

    if (!label) return null;

    const escaped = short.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const stripped = label.replace(new RegExp(`^${escaped}\\s+`, 'i'), '');

    return stripped.charAt(0).toLocaleUpperCase('fr') + stripped.slice(1);
};

const vitals = computed(() => {
    const record = props.careRecord ?? {};

    return [
        { key: 'blood_pressure', label: 'TA', longLabel: 'Tension artérielle', value: props.bloodPressure, unit: 'mmHg', assessment: record.blood_pressure_assessment },
        { key: 'heart_rate', label: 'FC', longLabel: 'Fréquence cardiaque', value: record.heart_rate, unit: 'bpm', assessment: record.heart_rate_assessment },
        { key: 'spo2', label: 'SpO₂', longLabel: 'Saturation en oxygène', value: record.spo2, unit: '%', assessment: record.spo2_assessment },
        {
            key: 'temperature',
            label: 'T°',
            longLabel: 'Température',
            value: record.temperature_celsius === null || record.temperature_celsius === undefined
                ? null
                : String(Number(record.temperature_celsius)),
            unit: '°C',
            assessment: record.temperature_assessment,
        },
    ]
        // Une constante non relevée n'est pas une constante normale : elle
        // n'a simplement pas été prise, et on ne la montre pas.
        .filter((vital) => vital.value !== null && vital.value !== undefined && vital.value !== '')
        .map((vital) => {
            const severity = severityOf(vital.assessment);
            const direction = directionOf(vital.assessment);

            return {
                ...vital,
                severity,
                direction,
                icon: ICONS[vital.key] ?? Activity,
                arrow: direction === 'down' ? ArrowDown : ArrowUp,
                interpretation: severity === 'normal' ? null : interpretationOf(vital.assessment, vital.label),
                ariaLabel: [
                    vital.longLabel,
                    `${vital.value} ${vital.unit}`,
                    severity === 'normal' ? 'valeur normale' : vital.assessment?.label,
                ].filter(Boolean).join(', '),
            };
        });
});

const abnormal = computed(() => vitals.value.filter((vital) => vital.severity !== 'normal'));
const hasCritical = computed(() => vitals.value.some((vital) => vital.severity === 'critical'));

const allergyLabel = computed(() => (props.allergies.length
    ? props.allergies.map((allergy) => allergy.substance).join(', ')
    : 'Aucune enregistrée'));

const TONE = {
    critical: {
        wrapper: 'border-red-200 bg-red-50/70 dark:border-red-900/60 dark:bg-red-950/25',
        value: 'text-red-700 dark:text-red-300',
        icon: 'text-red-500 dark:text-red-400',
        text: 'text-red-600 dark:text-red-300',
    },
    warning: {
        wrapper: 'border-amber-200 bg-amber-50/70 dark:border-amber-900/60 dark:bg-amber-950/25',
        value: 'text-amber-700 dark:text-amber-300',
        icon: 'text-amber-500 dark:text-amber-400',
        text: 'text-amber-600 dark:text-amber-300',
    },
    normal: {
        wrapper: 'border-border bg-muted/30',
        value: 'text-foreground',
        icon: 'text-muted-foreground',
        text: 'text-muted-foreground',
    },
};
</script>

<template>
    <section
        v-if="vitals.length"
        class="overflow-hidden rounded-xl border border-border bg-card shadow-sm"
        aria-label="Constantes relevées aux Soins"
    >
        <!-- Titre, décompte des anomalies et accès au détail : une seule
             ligne de tête, pas un second bandeau. -->
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 pt-3">
            <div class="flex shrink-0 items-center gap-2.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground" aria-hidden="true">
                    <Activity class="h-4 w-4" />
                </span>
                <div>
                    <p class="text-sm font-semibold leading-5 text-foreground">
                        Constantes <span class="font-normal text-muted-foreground">(à l’arrivée)</span>
                    </p>
                    <p v-if="recordedAt" class="text-xs leading-4 text-muted-foreground">{{ formatTime(recordedAt) }}</p>
                </div>
            </div>

            <p
                v-if="abnormal.length"
                :class="cn('flex min-w-0 flex-1 items-center gap-2 text-sm font-semibold',
                    hasCritical ? 'text-red-700 dark:text-red-300' : 'text-amber-700 dark:text-amber-300')"
                role="status"
                aria-live="polite"
            >
                <TriangleAlert :class="cn('h-4 w-4 shrink-0', hasCritical ? 'text-red-500 dark:text-red-400' : 'text-amber-500 dark:text-amber-400')" aria-hidden="true" />
                {{ abnormal.length }} anomalie{{ abnormal.length > 1 ? 's' : '' }} à évaluer
                <span class="truncate font-normal text-muted-foreground">· corrélez avec le contexte clinique</span>
            </p>
            <p v-else class="min-w-0 flex-1 text-sm text-muted-foreground">Aucune valeur hors des bornes attendues.</p>

            <button
                v-if="abnormal.length"
                type="button"
                class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-semibold text-foreground shadow-sm transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                :aria-expanded="showDetails"
                aria-controls="vital-signs-details"
                @click="showDetails = !showDetails"
            >
                {{ showDetails ? 'Masquer le détail' : 'Voir les détails' }}
                <ChevronRight :class="cn('h-3.5 w-3.5 transition-transform', showDetails && 'rotate-90')" aria-hidden="true" />
            </button>
        </div>

        <!-- Les valeurs, chacune portant sa propre interprétation. -->
        <div class="flex flex-wrap items-stretch gap-x-4 gap-y-3 px-4 pb-3 pt-2.5">
            <ul class="flex min-w-0 flex-1 flex-wrap items-stretch gap-2">
                <li
                    v-for="vital in vitals"
                    :key="vital.key"
                    :class="cn('flex min-w-40 flex-1 items-start gap-2.5 rounded-lg border px-3 py-2', TONE[vital.severity].wrapper)"
                    :aria-label="vital.ariaLabel"
                    :title="vital.assessment?.message"
                >
                    <component :is="vital.icon" :class="cn('mt-0.5 h-4 w-4 shrink-0', TONE[vital.severity].icon)" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="text-xs leading-4 text-muted-foreground">{{ vital.label }}</p>
                        <p class="flex items-baseline gap-1">
                            <span :class="cn('text-sm font-bold leading-5 tabular-nums', TONE[vital.severity].value)">{{ vital.value }}</span>
                            <span class="text-xs text-muted-foreground">{{ vital.unit }}</span>
                        </p>
                        <p v-if="vital.interpretation" :class="cn('mt-0.5 text-xs font-medium leading-4', TONE[vital.severity].text)">
                            {{ vital.interpretation }}
                        </p>
                    </div>
                    <component
                        :is="vital.arrow"
                        v-if="vital.severity !== 'normal' && vital.direction"
                        :class="cn('mt-0.5 h-4 w-4 shrink-0', TONE[vital.severity].icon)"
                        aria-hidden="true"
                    />
                </li>
            </ul>

            <div class="flex shrink-0 items-center gap-2 border-s border-border ps-4">
                <ShieldAlert
                    :class="cn('h-4 w-4 shrink-0', allergies.length ? 'text-red-500 dark:text-red-400' : 'text-muted-foreground')"
                    aria-hidden="true"
                />
                <div class="min-w-0">
                    <p class="text-xs leading-4 text-muted-foreground">Allergies</p>
                    <p
                        :class="cn('truncate text-sm font-semibold leading-5', allergies.length ? 'text-red-700 dark:text-red-300' : 'text-foreground')"
                        :title="allergyLabel"
                    >{{ allergyLabel }}</p>
                </div>
            </div>
        </div>

        <!-- Le détail n'ajoute que ce que la pastille ne porte pas : la
             conduite attendue. Il ne répète ni la valeur ni son libellé. -->
        <ul
            v-show="showDetails"
            id="vital-signs-details"
            class="space-y-1.5 border-t border-border bg-muted/20 px-4 py-3"
        >
            <li v-for="vital in abnormal" :key="`detail-${vital.key}`" class="text-xs leading-5">
                <span class="font-semibold text-foreground">{{ vital.longLabel }}</span>
                <span class="text-muted-foreground"> — {{ vital.assessment?.message }}</span>
            </li>
        </ul>
    </section>
</template>
