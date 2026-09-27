<script setup>
import { computed, ref, watch } from 'vue';
import { ArrowRight, Bandage, CircleCheck, Signpost, Stethoscope } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { formatPatientName } from '@/utilities/patient';

/**
 * ADR-177, amendements du 2026-09-27 — la Médecine envoie un patient aux Soins,
 * à tout moment du passage.
 *
 * Il apparaît aux Soins comme une vraie demande, « orienté par Médecine ». Ce
 * qui suit les soins est servi par le serveur (`send_to_care.then`) : retour
 * chez le médecin, fin du parcours clinique aux Soins, ou choix de l'infirmier.
 * Le moment (`send_to_care.moment`) change seulement ce que la fenêtre dit.
 */
const props = defineProps({
    /** La ligne du tableau, avec `actions.send_to_care`, ou `null` quand rien n'est à confirmer. */
    row: { type: Object, default: null },
    busy: { type: Boolean, default: false },
    /** Le refus du serveur, à afficher sous la consigne. */
    error: { type: String, default: '' },
});
const emit = defineEmits(['confirm', 'cancel']);

const note = ref('');
watch(() => props.row?.uuid, () => { note.value = ''; });

const offer = computed(() => props.row?.actions?.send_to_care ?? null);
const TITLES = {
    BEFORE: 'Envoyer aux Soins avant la consultation',
    DURING: 'Envoyer aux Soins pendant la consultation',
    AFTER: 'Envoyer aux Soins après la consultation',
};
const title = computed(() => TITLES[offer.value?.moment] ?? 'Envoyer aux Soins');
const then = computed(() => offer.value?.then ?? (offer.value?.returns_to_medicine ? 'MEDICINE' : 'CHOICE'));
const THEN_STEPS = {
    MEDICINE: { label: 'Retour en Médecine', icon: Stethoscope },
    FINISH: { label: 'Fin aux Soins', icon: CircleCheck },
    CHOICE: { label: 'Suite choisie aux Soins', icon: Signpost },
};
const thenStep = computed(() => THEN_STEPS[then.value] ?? THEN_STEPS.CHOICE);
/** Ce que le médecin garde, selon le moment : sa place, sa consultation, ou rien à reprendre. */
const keeps = computed(() => ({
    BEFORE: 'Il garde sa place ici : vous le voyez « aux Soins » dans votre file.',
    DURING: 'Votre consultation reste ouverte : vous la reprenez à son retour.',
    AFTER: 'Votre consultation est close : ce soin s’ajoute au passage, sans la rouvrir.',
})[offer.value?.moment] ?? null);
</script>

<template>
    <Dialog
        :open="Boolean(offer)"
        size="lg"
        :title="title"
        :description="row ? formatPatientName(row.episode.patient) + ' · ' + row.episode.episode_number : ''"
        :dismissible="!busy"
        @update:open="value => { if (!value) emit('cancel'); }"
    >
        <template #icon>
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                <Bandage class="h-5 w-5" aria-hidden="true" />
            </span>
        </template>

        <div v-if="row" class="space-y-4">
            <!-- Le trajet du patient, dans l'ordre : aux Soins, puis de retour ici. -->
            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-foreground" :aria-label="`Trajet du patient : Soins, puis ${thenStep.label}`">
                <span class="inline-flex items-center gap-1.5 rounded-md border border-border bg-muted/40 px-2.5 py-1.5">
                    <Bandage class="h-4 w-4 text-muted-foreground" aria-hidden="true" />Soins
                </span>
                <ArrowRight class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                <span class="inline-flex items-center gap-1.5 rounded-md border border-primary/30 bg-primary/5 px-2.5 py-1.5">
                    <component :is="thenStep.icon" class="h-4 w-4 text-primary" aria-hidden="true" />{{ thenStep.label }}
                </span>
            </p>

            <ul class="space-y-1.5 text-sm leading-6 text-muted-foreground">
                <li>Le patient passe dans la file des Soins, « orienté par Médecine ».</li>
                <li v-if="keeps">{{ keeps }}</li>
                <li v-if="then === 'MEDICINE'">À la fin des soins, le patient revient chez le médecin : c’est la suite prévue pour ce passage.</li>
                <li v-else-if="then === 'FINISH'">À la fin des soins, son parcours clinique est terminé : il rejoint la Réception pour sa sortie.</li>
                <li v-else>Rien n’était prévu pour ce passage : à la fin des soins, l’infirmier choisit de vous le renvoyer ou de terminer aux Soins.</li>
            </ul>

            <FormField as="div" label="Consigne pour les Soins" hint="(facultatif)" :error="error">
                <Textarea
                    id="send_to_care_note"
                    v-model="note"
                    rows="3"
                    maxlength="500"
                    placeholder="Ex. prendre les constantes et le poids ; injection IM prescrite"
                />
            </FormField>
        </div>

        <template #footer>
            <Button type="button" variant="white-outline" :disabled="busy" @click="emit('cancel')">Annuler</Button>
            <Button type="button" variant="primary" :disabled="busy" @click="emit('confirm', note)">
                <Bandage class="me-1.5 h-4 w-4" aria-hidden="true" />{{ busy ? 'Envoi…' : 'Envoyer aux Soins' }}
            </Button>
        </template>
    </Dialog>
</template>
