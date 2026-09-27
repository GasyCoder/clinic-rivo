<script setup>
import { computed } from 'vue';
import { Baby, CalendarClock, Check, CircleAlert, FlaskConical, HeartPulse, Info, Stethoscope, TriangleAlert } from 'lucide-vue-next';
import ClinicalSegmentedChoice from '@/Components/Clinical/ClinicalSegmentedChoice.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/utilities/date';

/**
 * ADR-204 — la finalisation, geste explicite et distinct de l'enregistrement.
 *
 * L'enregistrement automatique n'a jamais terminé quoi que ce soit : seule
 * cette confirmation clôt le dossier. Le récapitulatif **informe**, il ne
 * bloque rien de plus que le serveur — un résultat en attente, un rendez-vous
 * non programmé ou une date d'accouchement absente se disent, sans verrou.
 *
 * L'issue (terminer, ou terminer et orienter vers Médecine) reste le choix
 * explicite de la sage-femme (ADR-135).
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** `PRENATAL` | `DELIVERY` | null (dossier d'avant les parcours). */
    type: { type: String, default: null },
    completionLabel: { type: String, default: 'Terminer la prise en charge' },
    patientName: { type: String, required: true },
    episodeNumber: { type: String, required: true },
    /** Le dossier a encore des modifications que le serveur n'a pas reçues. */
    dirty: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    pregnancyLinked: { type: Boolean, default: true },
    /** `useForm({ medicine_note })`, tenu par la page. */
    completeForm: { type: Object, required: true },
    outcome: { type: String, required: true },
    /** ce que la sage-femme a saisi, lu tel quel */
    appointment: { type: Object, default: null },
    deliveredAt: { type: String, default: '' },
    newbornCount: { type: Number, default: 0 },
    examCount: { type: Number, default: 0 },
    pendingExamCount: { type: Number, default: 0 },
});
defineEmits(['update:open', 'update:outcome', 'confirm', 'save-now']);

const OUTCOMES = [
    { value: 'end', label: 'Terminer', description: 'La Maternité a fini' },
    { value: 'medicine', label: 'Terminer et orienter vers Médecine', description: 'Un médecin revoit la patiente', tone: 'warning' },
];
const toMedicine = computed(() => props.outcome === 'medicine');
const title = computed(() => ({
    PRENATAL: 'Terminer la consultation prénatale ?',
    DELIVERY: 'Clôturer l’accouchement ?',
})[props.type] ?? 'Terminer la prise en charge Maternité ?');

/** Ce qui sera vrai une fois le dossier clos — lu, jamais recalculé. */
const recap = computed(() => {
    const lines = [{
        icon: HeartPulse,
        tone: props.pregnancyLinked ? 'ok' : 'blocker',
        text: props.pregnancyLinked
            ? 'Consultation rattachée à la grossesse de la patiente.'
            : 'Choisissez d’abord la grossesse (Vue d’ensemble / Admission) : le serveur refuse de terminer sans ce choix.',
    }];

    if (props.type === 'DELIVERY') {
        lines.push(props.deliveredAt
            ? { icon: Baby, tone: 'ok', text: `La grossesse sera close « accouchée » à l’heure consignée : ${formatDateTime(props.deliveredAt)}.` }
            : { icon: TriangleAlert, tone: 'warning', text: 'Aucune date d’accouchement consignée : la grossesse restera en cours.' });
        lines.push({ icon: Baby, tone: 'info', text: props.newbornCount ? `${props.newbornCount} nouveau-né${props.newbornCount > 1 ? 's' : ''} consigné${props.newbornCount > 1 ? 's' : ''}.` : 'Aucun nouveau-né consigné.' });
    } else {
        lines.push(props.appointment?.enabled && props.appointment?.scheduled_at
            ? { icon: CalendarClock, tone: 'ok', text: `Rendez-vous programmé le ${formatDateTime(props.appointment.scheduled_at)} — il n’ouvre aucun passage.` }
            : { icon: CalendarClock, tone: 'info', text: 'Aucun rendez-vous programmé.' });
    }

    if (props.examCount) {
        lines.push({
            icon: FlaskConical,
            tone: 'info',
            text: props.pendingExamCount
                ? `${props.examCount} examen${props.examCount > 1 ? 's' : ''} demandé${props.examCount > 1 ? 's' : ''}, ${props.pendingExamCount} en attente de résultat : cela n’empêche pas de terminer, le résultat se lira dans le suivi de la grossesse.`
                : `${props.examCount} examen${props.examCount > 1 ? 's' : ''} demandé${props.examCount > 1 ? 's' : ''}, résultats disponibles.`,
        });
    }

    return lines;
});
const TONES = {
    ok: 'text-emerald-600 dark:text-emerald-400',
    info: 'text-muted-foreground',
    warning: 'text-amber-600 dark:text-amber-400',
    blocker: 'text-destructive',
};
const firstError = computed(() => Object.values(props.completeForm.errors ?? {})[0] ?? null);
</script>

<template>
    <Dialog :open="open" :title="title" description="Le dossier passe en lecture seule et le passage quitte « En cours chez moi ». Les données déjà enregistrées sont conservées." @update:open="$emit('update:open', $event)">
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-950/35 dark:text-emerald-300"><Check class="h-5 w-5" /></span>
        </template>

        <!-- Terminer rend le dossier non modifiable : ce qui n'est pas encore
             enregistré doit l'être avant, pas après. -->
        <div v-if="dirty" class="space-y-3">
            <p class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-200">
                <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                Le dossier porte des modifications non enregistrées : terminer maintenant les perdrait. Enregistrez-les d’abord.
            </p>
            <Button type="button" size="sm" variant="outline" :disabled="saving" @click="$emit('save-now')">{{ saving ? 'Enregistrement…' : 'Enregistrer maintenant' }}</Button>
        </div>
        <div v-else class="space-y-4">
            <p class="text-sm text-muted-foreground">Le dossier de {{ patientName }} sera clos pour le passage {{ episodeNumber }}.</p>

            <ul class="space-y-2 rounded-lg border border-border bg-muted/30 p-3 text-xs leading-5" aria-label="Récapitulatif">
                <li v-for="(line, index) in recap" :key="index" class="flex items-start gap-2">
                    <component :is="line.tone === 'blocker' ? CircleAlert : (line.tone === 'info' ? Info : line.icon)" :class="cn('mt-0.5 h-3.5 w-3.5 shrink-0', TONES[line.tone])" aria-hidden="true" />
                    <span class="text-foreground">{{ line.text }}</span>
                </li>
            </ul>

            <ClinicalSegmentedChoice
                :model-value="outcome"
                name="maternity-outcome"
                label="Et ensuite ?"
                :options="OUTCOMES"
                :clearable="false"
                @update:model-value="$emit('update:outcome', $event)"
            />

            <div v-if="toMedicine" class="space-y-3">
                <p class="flex items-start gap-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs leading-5 text-sky-800 dark:border-sky-900 dark:bg-sky-950/25 dark:text-sky-200">
                    <Stethoscope class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    La patiente rejoint la file du médecin sur ce même passage.
                </p>
                <FormField label="Message pour le médecin" hint="Facultatif" :error="completeForm.errors.medicine_note">
                    <Textarea v-model="completeForm.medicine_note" rows="3" maxlength="1000" placeholder="Ce que le médecin doit savoir avant de la revoir…" />
                </FormField>
            </div>
            <p v-else class="text-xs text-muted-foreground">
                Si plus aucun service n’a la patiente, son passage n’attend plus que la Réception pour la sortie.
            </p>
            <FormError v-if="firstError && ! completeForm.errors.medicine_note">{{ firstError }}</FormError>
        </div>

        <template #footer>
            <Button type="button" variant="outline" :disabled="completeForm.processing" @click="$emit('update:open', false)">Annuler</Button>
            <Button type="button" :variant="toMedicine ? 'primary' : 'success'" :disabled="completeForm.processing || dirty || saving" @click="$emit('confirm')">
                <component :is="toMedicine ? Stethoscope : Check" class="h-4 w-4" />
                {{ completeForm.processing ? 'Clôture…' : (toMedicine ? `${completionLabel} et orienter` : completionLabel) }}
            </Button>
        </template>
    </Dialog>
</template>
