<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { CircleAlert, FlaskConical, Send, Stethoscope, UserRoundX, Users } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import { cn } from '@/lib/cn';
import { labUrl } from '@/utilities/labUrl';
import { defaultRecipients, sendabilityOf } from '@/utilities/labSending';

/**
 * ADR-216 — envoyer les résultats au médecin. Un seul geste, qui les valide :
 * il n'y a plus de biologiste distinct. Le prescripteur est proposé d'office ;
 * une demande de l'accueil n'en a pas, le technicien choisit, ou « aucun
 * médecin » pour un patient externe. Le serveur revérifie tout.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    requestUuid: { type: String, required: true },
    items: { type: Array, default: () => [] },
    /** Les analyses cochées à l'ouverture ; vide = toutes celles qui peuvent partir. */
    preselected: { type: Array, default: () => [] },
    recipient: { type: Object, default: () => ({}) },
    recipients: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:open']);

const page = usePage();
const errors = computed(() => page.props.errors ?? {});

const rows = computed(() => props.items.map((item) => ({ item, ...sendabilityOf(item) })));
const chosen = ref([]);
// Amendement ADR-216 du 2026-09-29 (ter) — un, plusieurs ou tous les médecins ; ou aucun.
const targets = ref([]);
const nobody = ref(false);
const sending = ref(false);

watch(() => props.open, (open) => {
    if (!open) return;
    const sendable = rows.value.filter((row) => row.sendable).map((row) => row.item.uuid);
    chosen.value = props.preselected.length ? props.preselected.filter((uuid) => sendable.includes(uuid)) : sendable;
    const initial = defaultRecipients(props.recipient, props.recipients);
    targets.value = initial.uuids;
    nobody.value = initial.nobody;
}, { immediate: true });

const allTargets = computed(() => props.recipients.length > 0 && props.recipients.every((option) => targets.value.includes(option.uuid)));
const toggleTarget = (uuid, on) => {
    targets.value = on ? [...new Set([...targets.value, uuid])] : targets.value.filter((value) => value !== uuid);
    if (on) nobody.value = false;
};
const toggleAllTargets = () => {
    targets.value = allTargets.value ? [] : props.recipients.map((option) => option.uuid);
    if (targets.value.length) nobody.value = false;
};
// « Aucun médecin » exclut les autres choix, et inversement.
const setNobody = (on) => {
    nobody.value = on;
    if (on) targets.value = [];
};

// Par défaut, toutes les analyses terminées partent ; on peut n'en envoyer qu'une partie.
const sendableUuids = computed(() => rows.value.filter((row) => row.sendable).map((row) => row.item.uuid));
const allChosen = computed(() => sendableUuids.value.length > 0 && sendableUuids.value.every((uuid) => chosen.value.includes(uuid)));
const toggleAll = () => { chosen.value = allChosen.value ? [] : [...sendableUuids.value]; };
const waiting = computed(() => rows.value.filter((row) => !row.sendable && row.item.status !== 'VALIDATED').length);

const toggle = (uuid, on) => {
    chosen.value = on ? [...new Set([...chosen.value, uuid])] : chosen.value.filter((value) => value !== uuid);
};

const chosenRecipients = computed(() => props.recipients.filter((option) => targets.value.includes(option.uuid)));
const ready = computed(() => chosen.value.length > 0 && (nobody.value || targets.value.length > 0));

const submit = () => {
    if (!ready.value) return;
    sending.value = true;
    router.post(labUrl(`/laboratory/requests/${props.requestUuid}/send`), {
        items: chosen.value,
        to_nobody: nobody.value,
        recipient_uuids: nobody.value ? [] : targets.value,
    }, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
        onFinish: () => { sending.value = false; },
    });
};
</script>

<template>
    <Dialog
        :open="open"
        title="Envoyer au médecin"
        description="L’envoi rend le résultat définitif : il ne se modifie plus. Une erreur se corrige par « Renvoyer à refaire », avec un motif."
        size="lg"
        :dismissible="false"
        @update:open="emit('update:open', $event)"
    >
        <div class="space-y-5">
            <fieldset>
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <legend class="text-sm font-medium text-foreground">
                        Destinataires <span class="text-destructive">*</span>
                        <span class="font-normal text-muted-foreground">— {{ nobody ? 'aucun médecin' : `${targets.length} sur ${recipients.length}` }}</span>
                    </legend>
                    <Button v-if="recipients.length > 1" type="button" size="xs" variant="ghost" @click="toggleAllTargets">
                        <Users class="h-3.5 w-3.5" /> {{ allTargets ? 'Tout décocher' : 'Tous les médecins' }}
                    </Button>
                </div>
                <ul class="max-h-56 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                    <li v-for="option in recipients" :key="option.uuid" class="flex items-center gap-3 px-3 py-2">
                        <Checkbox
                            :id="`recipient-${option.uuid}`"
                            :model-value="targets.includes(option.uuid)"
                            @update:model-value="toggleTarget(option.uuid, $event)"
                        />
                        <label :for="`recipient-${option.uuid}`" class="flex min-w-0 flex-1 cursor-pointer flex-wrap items-center gap-x-2 gap-y-0.5 text-sm">
                            <Stethoscope class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <span class="font-semibold text-foreground">{{ option.name }}</span>
                            <span v-if="option.detail" class="text-xs text-muted-foreground">{{ option.detail }}</span>
                            <Badge v-if="option.prescriber" tone="primary">Prescripteur</Badge>
                        </label>
                    </li>
                    <li class="flex items-center gap-3 bg-muted/30 px-3 py-2">
                        <Checkbox id="recipient-nobody" :model-value="nobody" @update:model-value="setNobody($event)" />
                        <label for="recipient-nobody" class="flex min-w-0 flex-1 cursor-pointer items-center gap-2 text-sm text-foreground">
                            <UserRoundX class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" /> Aucun médecin — patient externe
                        </label>
                    </li>
                </ul>
                <p class="mt-1.5 text-xs text-muted-foreground">
                    <template v-if="nobody">Aucun médecin n’est notifié : le résultat reste lisible par les comptes autorisés, sans confirmation.</template>
                    <template v-else-if="chosenRecipients.length">{{ chosenRecipients.map((option) => option.name).join(', ') }} {{ chosenRecipients.length > 1 ? 'sont notifiés et les lisent' : 'est notifié et le lit' }} librement. Les autres médecins pourront l’ouvrir après confirmation, tracée.</template>
                    <template v-else>Cochez un ou plusieurs médecins, ou « Aucun médecin » pour un patient externe.</template>
                </p>
                <p v-if="errors.recipient_uuid || errors.recipient_uuids" class="mt-1 text-xs text-destructive">{{ errors.recipient_uuid ?? errors.recipient_uuids }}</p>
            </fieldset>

            <fieldset>
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <legend class="text-sm font-medium text-foreground">
                        Analyses à envoyer
                        <span class="font-normal text-muted-foreground">— {{ chosen.length }} sur {{ sendableUuids.length }} terminée{{ sendableUuids.length > 1 ? 's' : '' }}</span>
                    </legend>
                    <Button v-if="sendableUuids.length > 1" type="button" size="xs" variant="ghost" @click="toggleAll">
                        {{ allChosen ? 'Tout décocher' : 'Tout cocher' }}
                    </Button>
                </div>
                <p class="mb-2 text-xs text-muted-foreground">
                    Par défaut, toutes les analyses terminées partent ensemble ; décochez celles qui doivent attendre.
                    <template v-if="waiting"> {{ waiting }} analyse{{ waiting > 1 ? 's ne sont' : ' n’est' }} pas encore terminée{{ waiting > 1 ? 's' : '' }} : elle{{ waiting > 1 ? 's partiront' : ' partira' }} plus tard.</template>
                </p>
                <ul class="divide-y divide-border rounded-lg border border-border">
                    <li v-for="row in rows" :key="row.item.uuid" :class="cn('flex items-start gap-3 px-3 py-2.5', !row.sendable && 'opacity-70')">
                        <Checkbox
                            :id="`send-${row.item.uuid}`"
                            class="mt-0.5"
                            :model-value="chosen.includes(row.item.uuid)"
                            :disabled="!row.sendable"
                            @update:model-value="toggle(row.item.uuid, $event)"
                        />
                        <label :for="`send-${row.item.uuid}`" class="min-w-0 flex-1 cursor-pointer">
                            <span class="flex flex-wrap items-center gap-2 text-sm font-semibold text-foreground">
                                <FlaskConical class="h-4 w-4 text-muted-foreground" aria-hidden="true" />{{ row.item.name }}
                                <Badge v-if="row.correction" tone="warning">Correction</Badge>
                            </span>
                            <span class="mt-0.5 block text-xs text-muted-foreground">{{ row.note }}</span>
                        </label>
                    </li>
                </ul>
            </fieldset>

            <p v-if="errors.items" class="flex items-start gap-2 rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">
                <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ errors.items }}
            </p>
        </div>

        <template #footer>
            <Button type="button" variant="outline" @click="emit('update:open', false)">Annuler</Button>
            <Button type="button" :disabled="!ready || sending" @click="submit">
                <Send class="h-4 w-4" />
                {{ allChosen && chosen.length > 1 ? `Tout envoyer (${chosen.length})` : chosen.length > 1 ? `Envoyer les ${chosen.length} analyses` : 'Envoyer' }}
            </Button>
        </template>
    </Dialog>
</template>
