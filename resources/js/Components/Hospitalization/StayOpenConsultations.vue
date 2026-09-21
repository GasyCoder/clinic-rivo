<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import { formatDateTime } from '@/utilities/date';
import { doctorName } from '@/utilities/doctorName';
import { CircleAlert, ClipboardCheck, ExternalLink, Undo2 } from 'lucide-vue-next';

/**
 * ADR-163 — les consultations du passage encore ouvertes.
 *
 * Tant qu'une seule l'est, un service a encore le patient : après la sortie,
 * le passage n'atteint pas « Sorties & règlements ». L'écran nomme chacune, dit
 * ce qui manque pour la clôturer, et la clôture d'un clic quand plus rien ne
 * manque — jamais à la place du médecin : le serveur revérifie la clôture.
 *
 * Une visite de service (plus aucune ne s'ouvre) se clôture de la même façon,
 * ou s'annule tant qu'elle n'a rien produit qui doive lui survivre. C'est sa
 * seule présence sur cette page : une visite close ou annulée se relit sur la
 * page du passage, avec le reste du dossier.
 */
const props = defineProps({
    stayUuid: { type: String, required: true },
    consultations: { type: Array, default: () => [] },
    /** Le séjour est terminé : la consultation ouverte retient désormais le règlement. */
    stayEnded: { type: Boolean, default: false },
});

const closing = ref(null);
const close = (consultation) => {
    closing.value = consultation.uuid;
    router.post(`/hospitalisation/${props.stayUuid}/consultations/${consultation.uuid}/cloturer`, {}, {
        preserveScroll: true,
        onFinish: () => { closing.value = null; },
    });
};

const cancelling = ref(null);
const cancelForm = useForm({ reason: '' });
const openCancel = (consultation) => {
    cancelForm.reset();
    cancelForm.clearErrors();
    cancelling.value = consultation;
};
const submitCancel = () => cancelForm.post(`/hospitalisation/${props.stayUuid}/visites/${cancelling.value.uuid}/annuler`, {
    preserveScroll: true,
    onSuccess: () => { cancelling.value = null; },
});
</script>

<template>
    <Card v-if="consultations.length" class="border-amber-300 bg-amber-50/60 p-5 dark:border-amber-500/40 dark:bg-amber-500/5">
        <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground">
            <CircleAlert class="h-4 w-4 text-amber-600 dark:text-amber-400" aria-hidden="true" />
            {{ consultations.length > 1 ? `${consultations.length} consultations encore ouvertes` : 'Une consultation encore ouverte' }}
        </h2>
        <p class="mt-1 text-xs text-muted-foreground">
            <template v-if="stayEnded">Le séjour est terminé, mais le passage n’atteindra « Sorties &amp; règlements » qu’après leur clôture.</template>
            <template v-else>À clôturer avant la sortie : sinon le passage n’atteindra pas « Sorties &amp; règlements » une fois le patient sorti.</template>
        </p>
        <ul class="mt-3 space-y-2">
            <li
                v-for="consultation in consultations"
                :key="consultation.uuid"
                class="rounded-md border border-border bg-card px-3 py-2.5 text-xs"
            >
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    <Badge variant="warning">{{ consultation.kind_label }}</Badge>
                    <span class="font-semibold text-foreground">{{ formatDateTime(consultation.consulted_at) }}</span>
                    <span v-if="consultation.doctor" class="text-muted-foreground">{{ doctorName(consultation.doctor) }}</span>
                    <span class="ms-auto flex flex-wrap gap-2">
                        <Button v-if="consultation.can_close" type="button" size="xs" :disabled="closing === consultation.uuid" @click="close(consultation)">
                            <ClipboardCheck class="h-3.5 w-3.5" aria-hidden="true" />Clôturer
                        </Button>
                        <Button :as="Link" :href="consultation.url" size="xs" variant="white-outline">
                            <ExternalLink class="h-3.5 w-3.5" aria-hidden="true" />{{ consultation.closure_blockers.length ? 'Compléter' : 'Ouvrir' }}
                        </Button>
                        <Button v-if="consultation.can_cancel" type="button" size="xs" variant="outline" class="text-destructive" @click="openCancel(consultation)">
                            <Undo2 class="h-3.5 w-3.5" aria-hidden="true" />Annuler la visite
                        </Button>
                    </span>
                </div>
                <ul v-if="consultation.closure_blockers.length" class="mt-2 space-y-0.5 text-muted-foreground">
                    <li v-for="blocker in consultation.closure_blockers" :key="blocker">· {{ blocker }}</li>
                </ul>
                <p v-if="consultation.kind === 'VISIT' && !consultation.can_cancel" class="mt-1.5 text-muted-foreground">
                    <template v-if="consultation.cancel_reserved_to_author">Seul le médecin qui l’a ouverte peut l’annuler ; elle reste clôturable.</template>
                    <template v-else-if="consultation.cancel_blockers.length">Ne s’annule plus : {{ consultation.cancel_blockers.join(' ') }} Clôturez-la.</template>
                </p>
            </li>
        </ul>

        <Dialog
            :open="cancelling !== null"
            title="Annuler la visite de service"
            description="La visite n’est pas effacée : elle reste lisible sur la page du passage, statut « Annulée ». Le patient reste hospitalisé."
            size="md"
            :dismissible="false"
            @update:open="(value) => { if (!value) cancelling = null; }"
        >
            <form id="visit-cancel" class="space-y-3" @submit.prevent="submitCancel">
                <p class="text-sm text-foreground">
                    Visite du <strong class="font-semibold">{{ formatDateTime(cancelling?.consulted_at) }}</strong>.
                    Une demande au bloc encore « À programmer » qu’elle aurait transmise est retirée avec elle.
                </p>
                <FormField label="Motif (facultatif)" :error="cancelForm.errors.reason">
                    <Textarea v-model="cancelForm.reason" :rows="2" maxlength="500" placeholder="Ex. : ouverte par erreur" />
                </FormField>
                <FormError :message="cancelForm.errors.visit" />
            </form>
            <template #footer>
                <Button type="button" variant="white-outline" :disabled="cancelForm.processing" @click="cancelling = null">Garder la visite</Button>
                <Button type="submit" form="visit-cancel" variant="destructive" :disabled="cancelForm.processing"><Undo2 class="h-4 w-4" />Annuler la visite</Button>
            </template>
        </Dialog>
    </Card>
</template>
