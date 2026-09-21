<script setup>
import { computed, ref, useId } from 'vue';
import { Activity, ArrowDown, ArrowUp, ChevronDown, CircleCheck, Gauge, HeartPulse, ShieldAlert, Thermometer, TriangleAlert, Wind } from 'lucide-vue-next';
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
 *
 * **Une ligne, pas un panneau** (2026-09-21, demande du propriétaire). Deux
 * rangées de tuiles à trois lignes occupaient ~150 px au-dessus de chaque
 * étape. Chaque constante tient désormais dans une pastille d'une ligne —
 * sigle, valeur, unité, et pour une anomalie sa flèche et son libellé
 * court — et le décompte des anomalies ouvre lui-même le détail. Rien de ce
 * que les trois règles exigent n'a été retiré.
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
// Deux bandeaux peuvent coexister sur une page : l'identifiant du détail est
// unique, sinon `aria-controls` désignerait le mauvais.
const detailsId = `vital-signs-details-${useId()}`;

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
        class="rounded-xl border border-border bg-card shadow-sm"
        aria-label="Constantes relevées aux Soins"
    >
        <!-- Une seule ligne : titre, constantes, allergies, anomalies. Elle
             passe à la ligne sur un écran étroit, sans rien masquer. -->
        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-2 px-3 py-2">
            <p class="flex w-full shrink-0 items-center gap-1.5 text-sm font-semibold text-foreground sm:w-auto">
                <Activity class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                Constantes
                <span class="text-xs font-normal text-muted-foreground">à l’arrivée<template v-if="recordedAt"> · {{ formatTime(recordedAt) }}</template></span>
            </p>

            <span class="hidden h-5 w-px bg-border sm:block" aria-hidden="true" />

            <!-- Les valeurs, chacune portant sa propre interprétation. -->
            <ul class="flex w-full min-w-0 flex-wrap items-center gap-1.5 sm:w-auto sm:flex-1">
                <li
                    v-for="vital in vitals"
                    :key="vital.key"
                    :class="cn('inline-flex items-center gap-1 whitespace-nowrap rounded-md border px-1.5 py-1', TONE[vital.severity].wrapper)"
                    :aria-label="vital.ariaLabel"
                    :title="vital.assessment?.message"
                >
                    <component :is="vital.icon" :class="cn('h-3.5 w-3.5 shrink-0', TONE[vital.severity].icon)" aria-hidden="true" />
                    <span class="text-[11px] font-medium text-muted-foreground">{{ vital.label }}</span>
                    <span :class="cn('text-sm font-bold leading-5 tabular-nums', TONE[vital.severity].value)">{{ vital.value }}</span>
                    <span class="text-[11px] text-muted-foreground">{{ vital.unit }}</span>
                    <template v-if="vital.severity !== 'normal'">
                        <component
                            :is="vital.arrow"
                            v-if="vital.direction"
                            :class="cn('h-3.5 w-3.5 shrink-0', TONE[vital.severity].icon)"
                            aria-hidden="true"
                        />
                        <span v-if="vital.interpretation" :class="cn('text-[11px] font-semibold', TONE[vital.severity].text)">{{ vital.interpretation }}</span>
                    </template>
                </li>
            </ul>

            <!-- Une allergie connue se lit en rouge et en toutes lettres ; son
                 absence, en une phrase courte — jamais un vide. -->
            <p
                v-if="allergies.length"
                class="inline-flex min-w-0 max-w-full shrink items-center gap-1 rounded-md border border-red-200 bg-red-50/70 px-1.5 py-1 dark:border-red-900/60 dark:bg-red-950/25"
                :title="allergyLabel"
            >
                <ShieldAlert class="h-3.5 w-3.5 shrink-0 text-red-500 dark:text-red-400" aria-hidden="true" />
                <span class="text-[11px] font-medium text-red-600 dark:text-red-300">Allergies</span>
                <span class="truncate text-xs font-semibold text-red-700 sm:max-w-[16rem] dark:text-red-300">{{ allergyLabel }}</span>
            </p>
            <p
                v-else
                class="inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-border bg-muted/30 px-1.5 py-1 text-xs text-muted-foreground"
                title="Aucune allergie enregistrée au dossier"
            >
                <ShieldAlert class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Aucune allergie
            </p>

            <!-- Le décompte ouvre lui-même le détail : un seul geste. -->
            <template v-if="abnormal.length">
                <span class="sr-only" role="status" aria-live="polite">{{ abnormal.length }} anomalie{{ abnormal.length > 1 ? 's' : '' }} à évaluer</span>
                <button
                    type="button"
                    :class="cn('inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-md border px-2 py-1 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
                        hasCritical
                            ? 'border-red-200 bg-red-50/70 text-red-700 hover:bg-red-100 dark:border-red-900/60 dark:bg-red-950/25 dark:text-red-300'
                            : 'border-amber-200 bg-amber-50/70 text-amber-700 hover:bg-amber-100 dark:border-amber-900/60 dark:bg-amber-950/25 dark:text-amber-300')"
                    :aria-expanded="showDetails"
                    :aria-controls="detailsId"
                    :title="showDetails ? 'Masquer la conduite attendue' : 'Voir la conduite attendue'"
                    @click="showDetails = !showDetails"
                >
                    <TriangleAlert class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ abnormal.length }} à évaluer
                    <ChevronDown :class="cn('h-3.5 w-3.5 shrink-0 transition-transform', showDetails && 'rotate-180')" aria-hidden="true" />
                </button>
            </template>
            <p v-else class="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground">
                <CircleCheck class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />Dans les bornes
            </p>
        </div>

        <!-- Le détail n'ajoute que ce que la pastille ne porte pas : la
             conduite attendue. Il ne répète ni la valeur ni son libellé. -->
        <div
            v-show="showDetails"
            :id="detailsId"
            class="border-t border-border bg-muted/20 px-3 py-2"
        >
            <ul class="space-y-1">
                <li v-for="vital in abnormal" :key="`detail-${vital.key}`" class="text-xs leading-5">
                    <span class="font-semibold text-foreground">{{ vital.longLabel }}</span>
                    <span class="text-muted-foreground"> — {{ vital.assessment?.message }}</span>
                </li>
            </ul>
            <p class="mt-1 text-[11px] text-muted-foreground">Une aide au dépistage : corrélez avec le contexte clinique.</p>
        </div>
    </section>
</template>
