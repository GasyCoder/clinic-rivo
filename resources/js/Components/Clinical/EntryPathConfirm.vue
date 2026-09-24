<script setup>
import { computed } from 'vue';
import { Bandage, Info, Play, Stethoscope } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import { formatPatientName } from '@/utilities/patient';

/**
 * ADR-177, amendement — par où ce patient devrait entrer, dit au moment où l'on
 * s'apprête à le prendre à contre-sens (`EpisodeEntryPath`).
 *
 * ```text
 * CARE_FIRST     la Médecine est prévenue : attendu aux Soins d'abord.
 *                Faire les soins soi-même (avec les droits Soins) ou consulter
 *                quand même — la décision reste au médecin.
 * MEDICINE_ONLY  les Soins sont informés : attendu directement en Médecine.
 *                Rien ne se prend d'ici ; le serveur le refuse aussi.
 * ```
 *
 * Le titre, le message et les raisons viennent du serveur : l'écran n'écrit
 * aucune règle.
 */
const props = defineProps({
    /** La ligne du tableau, avec son `pathway`, ou `null` quand rien n'est en attente de décision. */
    row: { type: Object, default: null },
    /** Une prise en charge part : les boutons attendent la réponse. */
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['consult', 'care', 'cancel']);

const pathway = computed(() => props.row?.pathway ?? null);
const blocking = computed(() => Boolean(pathway.value?.blocking));
</script>

<template>
    <Dialog
        :open="Boolean(row && pathway)"
        :size="blocking ? 'md' : 'lg'"
        :title="pathway?.title ?? ''"
        :description="row ? formatPatientName(row.episode.patient) + ' · ' + row.episode.episode_number : ''"
        @update:open="value => { if (!value) emit('cancel'); }"
    >
        <template #icon>
            <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-full', blocking ? 'bg-sky-50 text-sky-700 dark:bg-sky-950/30 dark:text-sky-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300']">
                <component :is="blocking ? Stethoscope : Bandage" class="h-5 w-5" aria-hidden="true" />
            </span>
        </template>

        <div v-if="pathway" class="space-y-3">
            <ul v-if="pathway.reasons?.length" class="space-y-1.5" aria-label="Ce qui l'indique">
                <li
                    v-for="(reason, index) in pathway.reasons"
                    :key="index"
                    class="flex items-start gap-2 rounded-md border border-border bg-muted/30 px-3 py-2 text-sm text-foreground"
                >
                    <Info class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />{{ reason }}
                </li>
            </ul>
            <p class="text-sm leading-6 text-muted-foreground">{{ pathway.message }}</p>
            <p v-if="!blocking && !pathway.care_take_charge_url" class="text-xs leading-5 text-muted-foreground">
                Faire les soins vous-même demande les droits Soins (« care.create », « care.update ») ;
                sans eux, laissez le patient aux Soins ou consultez-le directement.
            </p>
        </div>

        <template #footer>
            <template v-if="blocking">
                <Button type="button" variant="primary" @click="emit('cancel')">Compris</Button>
            </template>
            <template v-else>
                <Button type="button" variant="white-outline" :disabled="busy" @click="emit('cancel')">Annuler</Button>
                <Button
                    v-if="pathway?.care_take_charge_url"
                    type="button"
                    variant="warning-outline"
                    :disabled="busy"
                    @click="emit('care')"
                >
                    <Bandage class="me-1.5 h-4 w-4" aria-hidden="true" />Faire les soins moi-même
                </Button>
                <Button type="button" variant="primary" :disabled="busy" @click="emit('consult')">
                    <Play class="me-1.5 h-4 w-4" aria-hidden="true" />Consulter quand même
                </Button>
            </template>
        </template>
    </Dialog>
</template>
