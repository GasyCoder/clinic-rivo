<script setup>
import DateTimePicker from '@/Components/Shadcn/DateTimePicker.vue';
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Select from '@/Components/Shadcn/Select.vue';
import ShadcnDialog from '@/Components/Shadcn/Dialog.vue';
import ClinicalRichTextEditor from '@/Components/Clinical/ClinicalRichTextEditor.vue';
import ClinicalRichTextDisplay from '@/Components/Clinical/ClinicalRichTextDisplay.vue';
import FormError from '@/Components/UI/FormError.vue';
import { ArrowLeft, ArrowRightLeft, Check, CircleCheck, FolderOpen, Pencil, Printer } from 'lucide-vue-next';
import { formatDateTime } from '@/utilities/date';

defineOptions({ layout: AppLayout });

/**
 * ADR-114 — un transfert.
 *
 * La demande est partie en un clic depuis la consultation, reprise du
 * dossier. Ici on complète l'établissement et le résumé, on imprime la lettre
 * de référence, puis on constate le départ : « Transfert effectué ». Jusque-là
 * le patient reste en soins ; ensuite son passage rejoint « Sorties &
 * règlements ».
 */
const props = defineProps({
    referral: { type: Object, required: true },
    priorities: { type: Array, default: () => [] },
    destinations: { type: Array, default: () => [] },
    capabilities: { type: Object, required: true },
});

const statusBadge = computed(() => {
    if (props.referral.status === 'CANCELLED') return { label: 'Annulé', variant: 'outline' };
    if (props.referral.departed) return { label: 'Transféré', variant: 'success' };

    return { label: 'À transférer', variant: 'warning' };
});

// ── Compléter la demande ────────────────────────────────────────────────────
const editing = ref(false);
const requestForm = useForm({
    facility: props.referral.facility ?? '',
    reason: props.referral.reason ?? '',
    diagnosis: props.referral.diagnosis ?? '',
    clinical_summary: props.referral.clinical_summary ?? '',
    treatments_given: props.referral.treatments_given ?? '',
    recommendations: props.referral.recommendations ?? '',
    notes: props.referral.notes ?? '',
    priority: props.referral.priority ?? 'NORMAL',
});
const saveRequest = () => requestForm.put(`/transferts/${props.referral.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { editing.value = false; },
});
const incomplete = computed(() => !props.referral.facility);

// ── Transfert effectué ──────────────────────────────────────────────────────
// Un fait constaté, signé comme les autres actes (ADR-106) : la fenêtre
// relit où part le patient et quand, et n'est pas fermable au clic extérieur.
const toLocalDateTimeInput = (value = new Date()) => {
    const date = new Date(value);

    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};
const showDeparture = ref(false);
const departureForm = useForm({
    facility: props.referral.facility ?? '',
    departed_at: toLocalDateTimeInput(),
    departure_notes: '',
});
const openDeparture = () => {
    departureForm.clearErrors();
    departureForm.facility = props.referral.facility ?? '';
    departureForm.departed_at = toLocalDateTimeInput();
    showDeparture.value = true;
};
const confirmDeparture = () => departureForm.post(`/transferts/${props.referral.uuid}/depart`, {
    preserveScroll: true,
    onSuccess: () => { showDeparture.value = false; },
});

// Le HTML vient du serveur, déjà assaini (même liste blanche que
// l'interrogatoire) : un texte ancien y garde ses retours à la ligne.
const blocks = computed(() => [
    { label: 'Motif du transfert', html: props.referral.reason_html },
    { label: 'Diagnostic', html: props.referral.diagnosis_html },
    { label: 'Résumé clinique et examens', html: props.referral.clinical_summary_html },
    { label: 'Traitements administrés ou prescrits', html: props.referral.treatments_given_html },
    { label: 'Recommandations', html: props.referral.recommendations_html },
    { label: 'Observations', html: props.referral.notes_html },
]);

/** Les champs rédigés du formulaire, dans l'ordre de la lettre. */
const RICH_FIELDS = [
    { key: 'reason', label: 'Motif du transfert', max: 3000, height: 'min-h-24' },
    { key: 'diagnosis', label: 'Diagnostic', max: 3000, height: 'min-h-24' },
    { key: 'clinical_summary', label: 'Résumé clinique et examens', max: 5000, height: 'min-h-56' },
    { key: 'treatments_given', label: 'Traitements administrés ou prescrits', max: 3000, height: 'min-h-28' },
    { key: 'recommendations', label: 'Recommandations', max: 3000, height: 'min-h-24' },
    { key: 'notes', label: 'Observations', max: 3000, height: 'min-h-24' },
];
</script>

<template>
    <Head :title="`Transfert — ${referral.patient.name}`" />

    <div class="mx-auto w-full max-w-screen-xl space-y-5">
        <Card class="p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                        <ArrowRightLeft class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="font-heading text-lg font-bold text-foreground">{{ referral.patient.name }}</h1>
                            <Badge :variant="statusBadge.variant">{{ statusBadge.label }}</Badge>
                            <Badge v-if="referral.priority === 'URGENT'" variant="destructive">Urgent</Badge>
                        </div>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            {{ referral.patient.patient_number }} · Passage {{ referral.episode.episode_number }}<template v-if="referral.patient.age !== null"> · {{ referral.patient.age }} ans</template>
                            · Demandé le {{ formatDateTime(referral.referred_at) }}<template v-if="referral.referred_by"> par Dr {{ referral.referred_by }}</template>
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button :as="Link" href="/transferts" size="sm" variant="white-outline"><ArrowLeft class="h-4 w-4" />Transferts</Button>
                    <Button :as="Link" :href="referral.episode.url" size="sm" variant="white-outline"><FolderOpen class="h-4 w-4" />Passage</Button>
                    <Button :as="Link" :href="`/transferts/${referral.uuid}/impression`" size="sm" variant="white-outline"><Printer class="h-4 w-4" />Lettre de référence</Button>
                </div>
            </div>
        </Card>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <Card class="min-w-0 p-5">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-foreground">Demande de transfert</h2>
                    <Button v-if="capabilities.can_manage && !editing" type="button" size="sm" variant="outline" @click="editing = true">
                        <Pencil class="h-3.5 w-3.5" />{{ incomplete ? 'Compléter' : 'Modifier' }}
                    </Button>
                </div>
                <p v-if="incomplete && !editing && !referral.departed" class="mt-2 text-xs text-amber-700 dark:text-amber-300">
                    L’établissement destinataire est encore à préciser.
                </p>

                <dl v-if="!editing" class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">Établissement destinataire</dt>
                        <dd class="font-medium text-foreground">{{ referral.facility || '—' }}</dd>
                    </div>
                    <div v-for="block in blocks" :key="block.label">
                        <dt class="text-xs text-muted-foreground">{{ block.label }}</dt>
                        <dd class="text-foreground">
                            <ClinicalRichTextDisplay v-if="block.html" :html="block.html" />
                            <span v-else>—</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Priorité</dt>
                        <dd class="text-foreground">{{ referral.priority_label }}</dd>
                    </div>
                </dl>

                <form v-else class="mt-4 space-y-3" @submit.prevent="saveRequest">
                    <FormField label="Établissement destinataire" hint="Un autre site de la clinique, ou un établissement extérieur." :error="requestForm.errors.facility">
                        <Input v-model="requestForm.facility" list="transfer-destinations" maxlength="255" placeholder="Ex. : CHU Androva" />
                        <datalist id="transfer-destinations">
                            <option v-for="destination in destinations" :key="destination" :value="destination" />
                        </datalist>
                    </FormField>
                    <FormField v-for="field in RICH_FIELDS" :key="field.key" :label="field.label" :error="requestForm.errors[field.key]">
                        <ClinicalRichTextEditor
                            :id="`transfer_${field.key}`"
                            v-model="requestForm[field.key]"
                            :max-length="field.max"
                            :min-height-class="field.height"
                            :toolbar-label="`Mise en forme — ${field.label}`"
                        />
                    </FormField>
                    <FormField label="Priorité" :error="requestForm.errors.priority">
                        <Select v-model="requestForm.priority" :options="priorities" aria-label="Priorité" />
                    </FormField>
                    <FormError :message="requestForm.errors.referral" />
                    <div class="flex justify-end gap-2">
                        <Button type="button" size="sm" variant="ghost" @click="editing = false; requestForm.reset()">Annuler</Button>
                        <Button type="submit" size="sm" :disabled="requestForm.processing"><Check class="h-4 w-4" />Enregistrer</Button>
                    </div>
                </form>
            </Card>

            <aside class="space-y-5">
                <Card v-if="referral.departed" class="p-5">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><CircleCheck class="h-4 w-4 text-emerald-600" />Transfert effectué</h2>
                    <dl class="mt-3 space-y-2.5 text-sm">
                        <div><dt class="text-xs text-muted-foreground">Départ</dt><dd class="text-foreground">{{ formatDateTime(referral.departed_at) }}<span v-if="referral.departed_by" class="block text-xs text-muted-foreground">Constaté par {{ referral.departed_by }}</span></dd></div>
                        <div><dt class="text-xs text-muted-foreground">Vers</dt><dd class="text-foreground">{{ referral.facility }}</dd></div>
                        <div v-if="referral.departure_notes"><dt class="text-xs text-muted-foreground">Observations</dt><dd class="whitespace-pre-line text-foreground">{{ referral.departure_notes }}</dd></div>
                        <div v-if="referral.episode.medical_status_label"><dt class="text-xs text-muted-foreground">Statut médical du passage</dt><dd class="text-foreground">{{ referral.episode.medical_status_label }}</dd></div>
                    </dl>
                </Card>

                <Card v-else-if="referral.status === 'CANCELLED'" class="p-5">
                    <h2 class="text-sm font-semibold text-foreground">Demande annulée</h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Annulée le {{ formatDateTime(referral.cancelled_at) }}<template v-if="referral.cancelled_by"> par {{ referral.cancelled_by }}</template>. Elle reste conservée pour l’historique.
                    </p>
                </Card>

                <Card v-else-if="capabilities.can_manage" class="p-5">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><ArrowRightLeft class="h-4 w-4 text-muted-foreground" />Départ du patient</h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Le patient reste en soins jusqu’à son départ. Une fois constaté, son passage rejoint « Sorties & règlements ».
                    </p>
                    <Button type="button" size="sm" class="mt-3 w-full" @click="openDeparture">
                        <CircleCheck class="h-4 w-4" />Transfert effectué
                    </Button>
                </Card>
            </aside>
        </div>
    </div>

    <ShadcnDialog
        :open="showDeparture"
        title="Confirmer le transfert"
        :description="`${referral.patient.name} · ${referral.patient.patient_number} · Passage ${referral.episode.episode_number}`"
        :dismissible="false"
        close-label="Annuler"
        @update:open="showDeparture = $event"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                <ArrowRightLeft class="h-5 w-5" />
            </span>
        </template>

        <div class="space-y-3">
            <FormField label="Établissement destinataire" required :error="departureForm.errors.facility">
                <Input v-model="departureForm.facility" list="transfer-destinations-confirm" maxlength="255" />
                <datalist id="transfer-destinations-confirm">
                    <option v-for="destination in destinations" :key="destination" :value="destination" />
                </datalist>
            </FormField>
            <FormField label="Date et heure du départ" required :error="departureForm.errors.departed_at">
                <DateTimePicker v-model="departureForm.departed_at" />
            </FormField>
            <FormField label="Observations" hint="Facultatif — moyen de transport, accompagnant…" :error="departureForm.errors.departure_notes">
                <Textarea v-model="departureForm.departure_notes" :rows="2" maxlength="3000" />
            </FormField>
            <FormError :message="departureForm.errors.departure" />
            <p class="text-xs leading-5 text-muted-foreground">
                Vous constatez le départ en tant que <strong class="font-semibold text-foreground">{{ $page.props.auth.user.name }}</strong>.
            </p>
        </div>

        <template #footer>
            <Button type="button" variant="outline" :disabled="departureForm.processing" @click="showDeparture = false">Revenir</Button>
            <Button type="button" :disabled="departureForm.processing || !departureForm.facility.trim()" @click="confirmDeparture">
                <CircleCheck class="h-4 w-4" />Je confirme le transfert
            </Button>
        </template>
    </ShadcnDialog>
</template>
