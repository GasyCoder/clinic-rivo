<script setup>
import { computed } from 'vue';
import { ArrowDown, ArrowUp, ChevronUp, TriangleAlert } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { formatPatientInitials } from '@/utilities/patient';

/**
 * L'en-tête réduit à ce qui doit rester visible en défilant.
 *
 * L'en-tête complet — identité, repères, orientation, constantes détaillées,
 * frise des six étapes — fait près de 450 px. Le garder entier en position
 * collante mangeait la moitié de la hauteur utile dès le premier coup de
 * molette, sur un écran où le médecin saisit du texte.
 *
 * Ce bandeau ne conserve donc que les trois réponses qu'on ne doit jamais
 * aller rechercher : **qui**, **quel risque**, **où j'en suis**. Tout le
 * reste redevient accessible en remontant.
 *
 * Il vit dans une surcouche de hauteur nulle : l'afficher ou le masquer ne
 * change pas la hauteur du document, donc le contenu ne saute jamais.
 *
 * Il ne recalcule rien : les anomalies lui sont passées déjà classées par le
 * serveur (ADR-039 à ADR-041).
 */
const props = defineProps({
    patient: { type: Object, required: true },
    episode: { type: Object, required: true },
    /** `vitalAlerts` de la page : uniquement les constantes hors bornes. */
    alerts: { type: Array, default: () => [] },
    stepLabel: { type: String, default: null },
    stepPosition: { type: Number, default: null },
    stepTotal: { type: Number, default: null },
});

// Remonter, et non « déplier » : l'en-tête complet vit en haut du document.
defineEmits(['expand']);

const initials = computed(() => formatPatientInitials(props.patient));
const isEmergency = computed(() => props.episode.priority === 'EMERGENCY');
const hasCritical = computed(() => props.alerts.some((alert) => alert.severity === 'danger'));

/**
 * Le sens de l'écart, déduit du libellé déjà calculé par le serveur — une
 * flèche vers le bas et une flèche vers le haut n'appellent pas le même
 * geste, et la couleur seule ne le dit pas.
 */
const arrowFor = (alert) => (/bass|hypo|faible/i.test(alert.title ?? '') ? ArrowDown : ArrowUp);

const toneFor = (severity) => (severity === 'danger'
    ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-300'
    : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300');
</script>

<template>
    <div class="flex items-center gap-x-4 gap-y-2 rounded-xl border border-border bg-card px-3 py-2 shadow-sm">
        <!-- QUI -->
        <div class="flex min-w-0 shrink-0 items-center gap-2.5">
            <span
                :class="cn('flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                    isEmergency ? 'bg-destructive text-destructive-foreground' : 'bg-primary/10 text-primary')"
                aria-hidden="true"
            >{{ initials }}</span>
            <div class="min-w-0">
                <p class="truncate text-sm font-bold leading-4 text-foreground">
                    {{ patient.first_name }} {{ patient.last_name }}
                </p>
                <!-- Le dossier **et** le passage. Un patient peut en avoir
                     plusieurs ; n'afficher que son numéro de dossier laissait
                     ignorer lequel de ses passages est ouvert. -->
                <p class="truncate text-xs leading-4 text-muted-foreground">
                    {{ patient.patient_number }}
                    <span class="text-border" aria-hidden="true">·</span>
                    <span class="font-medium text-foreground">{{ episode.episode_number }}</span>
                </p>
            </div>
        </div>

        <!-- QUEL RISQUE -->
        <ul v-if="alerts.length" class="flex min-w-0 flex-1 items-center gap-1.5 overflow-hidden">
            <li
                v-for="alert in alerts"
                :key="alert.label"
                :class="cn('flex shrink-0 items-center gap-1 rounded-md border px-2 py-1 text-xs font-semibold', toneFor(alert.severity))"
                :title="`${alert.label} — ${alert.title}`"
            >
                <span class="font-medium opacity-80">{{ alert.short ?? alert.label }}</span>
                <span class="tabular-nums">{{ alert.reading }}</span>
                <component :is="arrowFor(alert)" class="h-3 w-3 shrink-0" aria-hidden="true" />
            </li>
        </ul>
        <p v-else class="min-w-0 flex-1 truncate text-xs text-muted-foreground">Constantes dans les bornes attendues.</p>

        <!-- OÙ J'EN SUIS -->
        <div v-if="stepLabel" class="hidden shrink-0 items-center gap-2 border-s border-border ps-4 md:flex">
            <TriangleAlert v-if="hasCritical" class="h-4 w-4 shrink-0 text-red-500 dark:text-red-400" aria-hidden="true" />
            <div class="min-w-0 text-end">
                <p v-if="stepPosition && stepTotal" class="text-xs leading-4 text-muted-foreground">
                    Étape {{ stepPosition }} sur {{ stepTotal }}
                </p>
                <p class="truncate text-sm font-semibold leading-4 text-foreground">{{ stepLabel }}</p>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2 border-s border-border ps-3">
            <slot name="actions" />
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-md border border-border bg-card px-2 py-1.5 text-xs font-semibold text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                @click="$emit('expand')"
            >
                <ChevronUp class="h-3.5 w-3.5" aria-hidden="true" />
                <span class="hidden lg:inline">Haut de page</span>
                <span class="sr-only lg:hidden">Revenir à l’en-tête complet</span>
            </button>
        </div>
    </div>
</template>
