<script setup>
import { computed } from 'vue';
import { Baby, CalendarCheck, CalendarClock, CalendarHeart, CircleCheck, FlaskConical, History, Hourglass, Link2, Pencil, Repeat, ScanLine, ShieldAlert } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { formatDate, formatDateTime } from '@/utilities/date';
import { DUE_DATE_TONES, dueDateCountdown } from '@/utilities/pregnancyDueDate';

/**
 * ADR-204 — ce que la sage-femme doit savoir d'un coup d'œil, en tête du
 * parcours : la grossesse (DPA, terme, DDR, consultations, facteurs de risque)
 * et, le Jour J, ce qu'a donné le suivi paraclinique. Tout vient du serveur ;
 * rien n'est calculé ici, hormis le délai jusqu'à la DPA.
 *
 * C'est la **seule** carte de la grossesse du parcours : l'étape 1 affichait
 * une seconde carte « Grossesse actuelle » avec les mêmes repères. Ce qu'elle
 * seule portait vit désormais ici — continuer la grossesse quand une seule est
 * active, corriger la datation, le statut, les facteurs de risque. L'historique
 * complet s'ouvre sans quitter le parcours.
 */
const props = defineProps({
    encounter: { type: Object, required: true },
    pregnancy: { type: Object, default: null },
    /** `{ counts: { lab, imaging, pending }, restricted }` — servi par `PregnancyParaclinicalHistory`. */
    paraclinical: { type: Object, default: null },
    upcomingAppointments: { type: Array, default: () => [] },
    canChangeEncounter: { type: Boolean, default: false },
    canCorrectDating: { type: Boolean, default: false },
    /** Une seule grossesse active, pas encore reliée à ce passage : elle se continue d'ici. */
    continuable: { type: Boolean, default: false },
    /** Le choix « continuer » est fait, en attente d'enregistrement. */
    continued: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});
defineEmits(['open-history', 'change-encounter', 'continue', 'correct-dating']);

const isDelivery = computed(() => props.encounter.effective === 'DELIVERY');
const awaitingLink = computed(() => props.continuable && ! props.continued);
const eyebrow = computed(() => {
    if (! props.pregnancy) return 'Grossesse à relier';
    if (props.continuable) return 'Grossesse active trouvée';

    return props.pregnancy.status === 'ONGOING' ? 'Grossesse actuelle' : 'Grossesse';
});
const nextAppointment = computed(() => props.upcomingAppointments[0] ?? null);
const otherAppointments = computed(() => Math.max(0, props.upcomingAppointments.length - 1));
/** La DPA se cherche en premier : elle a sa case teintée et dit dans combien de temps elle tombe. */
const dueDate = computed(() => dueDateCountdown(props.pregnancy?.estimated_due_date, props.pregnancy?.status));
/** Une consultation relue garde le terme de son passage (ADR-201) : l'écran le nomme ainsi. */
const snapshotTerm = computed(() => props.pregnancy?.gestational_age_source === 'snapshot');
const facts = computed(() => {
    const pregnancy = props.pregnancy;

    if (! pregnancy) return [];

    return [
        { key: 'dpa', label: 'DPA', value: formatDate(pregnancy.estimated_due_date) ?? 'Non renseignée' },
        {
            key: 'term',
            label: snapshotTerm.value ? 'Terme au passage' : 'Terme actuel',
            value: pregnancy.gestational_age_label ?? 'Non calculable',
            title: pregnancy.gestational_age_label
                ? (snapshotTerm.value
                    ? 'Terme enregistré avec cette consultation : une correction de datation ne le réécrit pas.'
                    : 'Calculé par le serveur depuis la datation de la grossesse.')
                : undefined,
        },
        { key: 'ddr', label: 'DDR', value: formatDate(pregnancy.last_menstrual_period) ?? 'Non renseignée' },
        { key: 'visits', label: isDelivery.value ? 'Consultations prénatales' : 'Consultations', value: String(pregnancy.consultations_count ?? 0) },
        { key: 'last', label: 'Dernière consultation', value: formatDateTime(pregnancy.last_consultation_at) ?? 'Aucune' },
    ];
});
</script>

<template>
    <Card :class="['overflow-hidden', awaitingLink ? 'border-rose-300 dark:border-rose-800' : '']">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3 sm:px-5">
            <div class="flex min-w-0 items-center gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300">
                    <component :is="isDelivery ? Baby : CalendarHeart" class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">{{ eyebrow }}</p>
                    <div class="mt-0.5 flex flex-wrap items-center gap-2">
                        <h2 class="text-base font-bold text-foreground">{{ encounter.label }}</h2>
                        <Badge v-if="pregnancy" tone="info" class="px-2 py-0.5 text-[10px]">{{ pregnancy.reference }}</Badge>
                        <Badge v-if="pregnancy && pregnancy.status !== 'ONGOING'" tone="success" class="px-2 py-0.5 text-[10px]">{{ pregnancy.status_label }}</Badge>
                        <Badge v-if="encounter.finalized" tone="success" class="px-2 py-0.5 text-[10px]">Terminé</Badge>
                        <Badge v-else-if="encounter.is_legacy" variant="outline" class="px-2 py-0.5 text-[10px]">Dossier antérieur au choix du parcours</Badge>
                    </div>
                    <p v-if="awaitingLink" class="mt-1 text-xs text-muted-foreground">Reliez ce passage à cette grossesse pour enregistrer le dossier : le choix est explicite, jamais automatique.</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="continuable"
                    type="button"
                    size="sm"
                    :variant="continued ? 'success' : 'primary'"
                    :disabled="disabled"
                    @click="$emit('continue', pregnancy.uuid)"
                >
                    <CircleCheck v-if="continued" class="h-4 w-4" aria-hidden="true" />
                    <Link2 v-else class="h-4 w-4" aria-hidden="true" />
                    {{ continued ? 'Grossesse sélectionnée' : 'Continuer cette grossesse' }}
                </Button>
                <Button v-if="pregnancy" type="button" size="sm" variant="outline" @click="$emit('open-history')">
                    <History class="h-4 w-4" />{{ isDelivery ? 'Voir le suivi complet de la grossesse' : 'Voir l’historique complet' }}
                </Button>
                <Button v-if="pregnancy && canCorrectDating" type="button" size="sm" variant="ghost" @click="$emit('correct-dating')">
                    <Pencil class="h-3.5 w-3.5" aria-hidden="true" />Corriger la datation
                </Button>
                <Button v-if="canChangeEncounter" type="button" size="sm" variant="ghost" @click="$emit('change-encounter')">
                    <Repeat class="h-4 w-4" />Changer de parcours
                </Button>
            </div>
        </div>

        <dl v-if="facts.length" class="grid grid-cols-2 gap-px bg-border sm:grid-cols-3 lg:grid-cols-5">
            <div
                v-for="fact in facts"
                :key="fact.key"
                :title="fact.title"
                :class="[
                    // Cinq repères : le dernier prend la ligne entière plutôt que de laisser une case vide.
                    'px-4 py-2.5 last:col-span-2 sm:px-5 lg:last:col-span-1',
                    fact.key === 'dpa' ? 'bg-rose-50 dark:bg-rose-950/25' : 'bg-card',
                ]"
            >
                <template v-if="fact.key === 'dpa'">
                    <dt class="flex items-center gap-1 text-[10px] font-bold uppercase tracking-wide text-rose-700 dark:text-rose-300">
                        <CalendarCheck class="h-3.5 w-3.5" aria-hidden="true" />{{ fact.label }}
                        <span class="sr-only">— date prévue d’accouchement</span>
                    </dt>
                    <dd class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="text-base font-bold tabular-nums text-rose-700 dark:text-rose-300">{{ fact.value }}</span>
                        <span v-if="dueDate" :class="['rounded-full px-2 py-px text-[11px] font-semibold', DUE_DATE_TONES[dueDate.tone]]">{{ dueDate.label }}</span>
                    </dd>
                </template>
                <template v-else>
                    <dt class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">{{ fact.label }}</dt>
                    <dd class="mt-0.5 text-sm font-semibold text-foreground">{{ fact.value }}</dd>
                </template>
            </div>
        </dl>
        <p v-else class="px-5 py-3 text-xs text-muted-foreground">Reliez d’abord cette prise en charge à la grossesse de la patiente — continuer celle en cours ou en créer une.</p>

        <!-- Les facteurs de risque se lisent à chaque étape, comme une allergie. -->
        <div v-if="pregnancy?.risk_factors" class="flex items-start gap-2 border-t border-border bg-amber-50/60 px-4 py-2.5 text-xs text-amber-900 sm:px-5 dark:bg-amber-950/20 dark:text-amber-100">
            <ShieldAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <p><strong>Facteurs de risque consignés :</strong> {{ pregnancy.risk_factors }}</p>
        </div>

        <!-- Le Jour J : ce qu'a donné le suivi, sans quitter l'accouchement. -->
        <div v-if="isDelivery && paraclinical" class="flex flex-wrap items-center gap-2 border-t border-border bg-muted/30 px-4 py-2.5 text-xs sm:px-5">
            <span class="font-semibold text-muted-foreground">Paraclinique :</span>
            <Badge v-if="! paraclinical.restricted.lab" variant="outline" class="gap-1"><FlaskConical class="h-3 w-3" aria-hidden="true" />{{ paraclinical.counts.lab }} analyse{{ paraclinical.counts.lab > 1 ? 's' : '' }}</Badge>
            <Badge v-if="! paraclinical.restricted.imaging" variant="outline" class="gap-1"><ScanLine class="h-3 w-3" aria-hidden="true" />{{ paraclinical.counts.imaging }} imagerie{{ paraclinical.counts.imaging > 1 ? 's' : '' }}</Badge>
            <Badge :tone="paraclinical.counts.pending ? 'warning' : 'success'" class="gap-1"><Hourglass class="h-3 w-3" aria-hidden="true" />Résultats en attente : {{ paraclinical.counts.pending }}</Badge>
        </div>

        <p v-if="nextAppointment && ! isDelivery" class="flex flex-wrap items-center gap-2 border-t border-border px-4 py-2 text-xs text-muted-foreground sm:px-5">
            <CalendarClock class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span>Rendez-vous prévu le <strong class="text-foreground">{{ formatDateTime(nextAppointment.scheduled_at) }}</strong> — {{ nextAppointment.reason }}</span>
            <Badge v-if="nextAppointment.is_past" variant="outline" class="px-1.5 py-0 text-[10px]">passé</Badge>
            <span v-if="otherAppointments" class="text-muted-foreground" :title="upcomingAppointments.slice(1).map((appointment) => `${formatDateTime(appointment.scheduled_at)} — ${appointment.reason}`).join('\n')">
                + {{ otherAppointments }} autre{{ otherAppointments > 1 ? 's' : '' }}
            </span>
        </p>
    </Card>
</template>
