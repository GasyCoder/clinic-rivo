<script setup>
import { CircleAlert, Info, Play } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import { formatPatientName } from '@/utilities/patient';

/**
 * Confirmation, jamais blocage : le soignant garde la décision, on lui
 * rappelle seulement qui attend devant (voir `useQueueSkipGuard`).
 */
const props = defineProps({
    /** `{ orientation, ahead, earlierPages }` ou `null` quand rien n'est en attente de décision. */
    pending: { type: Object, default: null },
    /** Depuis quand ce patient attend, dit comme sur la file. */
    waitedLabel: { type: Function, required: true },
    waitTone: { type: Function, required: true },
});
const emit = defineEmits(['confirm', 'cancel']);

const isEmergency = (row) => row.episode?.priority === 'EMERGENCY';
const emergencies = () => (props.pending?.ahead ?? []).filter(isEmergency);
</script>

<template>
    <Dialog
        :open="Boolean(pending)"
        :title="emergencies().length ? 'Une urgence attend avant ce patient' : 'Un patient attend avant celui-ci'"
        :description="pending ? `Vous allez prendre en charge ${formatPatientName(pending.orientation.episode.patient)}.` : ''"
        @update:open="value => { if (!value) emit('cancel'); }"
    >
        <template #icon>
            <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-full', emergencies().length ? 'bg-red-50 text-red-600 dark:bg-red-950/30 dark:text-red-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300']">
                <CircleAlert class="h-5 w-5" aria-hidden="true" />
            </span>
        </template>

        <div v-if="pending" class="space-y-3">
            <ul v-if="pending.ahead.length" class="space-y-1.5">
                <li
                    v-for="row in pending.ahead.slice(0, 4)"
                    :key="row.uuid"
                    :class="['flex items-center justify-between gap-3 rounded border px-3 py-2 text-sm', isEmergency(row) ? 'border-red-200 bg-red-50/60 dark:border-red-900 dark:bg-red-950/20' : 'border-border']"
                >
                    <span class="min-w-0">
                        <span class="block truncate font-semibold text-foreground">{{ formatPatientName(row.episode.patient) }}</span>
                        <span class="text-xs text-muted-foreground">{{ row.episode.episode_number }}<template v-if="isEmergency(row)"> · Urgence</template></span>
                    </span>
                    <span :class="['shrink-0 text-sm font-bold', waitTone(row)]">{{ waitedLabel(row) }}</span>
                </li>
            </ul>
            <p v-if="pending.ahead.length > 4" class="text-xs text-muted-foreground">
                … et {{ pending.ahead.length - 4 }} autre{{ pending.ahead.length - 4 > 1 ? 's' : '' }} patient{{ pending.ahead.length - 4 > 1 ? 's' : '' }} avant celui-ci.
            </p>
            <p v-if="pending.earlierPages" class="flex items-start gap-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
                <Info class="mt-px h-4 w-4 shrink-0" aria-hidden="true" />Les pages précédentes de la file contiennent d’autres patients arrivés avant, non affichés ici.
            </p>
            <p class="text-xs leading-5 text-muted-foreground">
                Prendre ce patient d’abord reste possible — dossier incomplet, patient absent, priorité clinique.
                Ce rappel n’empêche rien.
            </p>
        </div>

        <template #footer>
            <Button type="button" variant="white-outline" @click="emit('cancel')">Annuler</Button>
            <Button type="button" :variant="emergencies().length ? 'danger' : 'primary'" @click="emit('confirm')">
                <Play class="me-1.5 h-4 w-4" aria-hidden="true" />Prendre celui-ci quand même
            </Button>
        </template>
    </Dialog>
</template>
