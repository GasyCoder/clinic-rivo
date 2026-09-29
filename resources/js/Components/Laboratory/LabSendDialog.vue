<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { CircleAlert, FlaskConical, Send, Stethoscope, UserRoundX } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Select from '@/Components/Shadcn/Select.vue';
import { cn } from '@/lib/cn';
import { labUrl } from '@/utilities/labUrl';
import { NO_RECIPIENT, defaultRecipient, sendabilityOf } from '@/utilities/labSending';

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
const target = ref('');
const sending = ref(false);

watch(() => props.open, (open) => {
    if (!open) return;
    const sendable = rows.value.filter((row) => row.sendable).map((row) => row.item.uuid);
    chosen.value = props.preselected.length ? props.preselected.filter((uuid) => sendable.includes(uuid)) : sendable;
    target.value = defaultRecipient(props.recipient, props.recipients);
}, { immediate: true });

// Par défaut, toutes les analyses terminées partent ; on peut n'en envoyer qu'une partie.
const sendableUuids = computed(() => rows.value.filter((row) => row.sendable).map((row) => row.item.uuid));
const allChosen = computed(() => sendableUuids.value.length > 0 && sendableUuids.value.every((uuid) => chosen.value.includes(uuid)));
const toggleAll = () => { chosen.value = allChosen.value ? [] : [...sendableUuids.value]; };
const waiting = computed(() => rows.value.filter((row) => !row.sendable && row.item.status !== 'VALIDATED').length);

const toggle = (uuid, on) => {
    chosen.value = on ? [...new Set([...chosen.value, uuid])] : chosen.value.filter((value) => value !== uuid);
};

const options = computed(() => [
    ...props.recipients.map((option) => ({
        value: option.uuid,
        label: `${option.name}${option.detail ? ` — ${option.detail}` : ''}${option.prescriber ? ' · prescripteur' : ''}`,
    })),
    { value: NO_RECIPIENT, label: 'Aucun médecin — patient externe' },
]);
const chosenRecipient = computed(() => props.recipients.find((option) => option.uuid === target.value) ?? null);
const ready = computed(() => chosen.value.length > 0 && target.value !== '');

const submit = () => {
    if (!ready.value) return;
    sending.value = true;
    const toNobody = target.value === NO_RECIPIENT;
    router.post(labUrl(`/laboratory/requests/${props.requestUuid}/send`), {
        items: chosen.value,
        to_nobody: toNobody,
        recipient_uuid: toNobody ? null : target.value,
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
            <FormField label="Destinataire" :error="errors.recipient_uuid" required>
                <Select v-model="target" :options="options" :icon="target === NO_RECIPIENT ? UserRoundX : Stethoscope" placeholder="Choisir le médecin" />
                <p class="mt-1.5 text-xs text-muted-foreground">
                    <template v-if="target === NO_RECIPIENT">Aucun médecin n’est notifié : le résultat reste lisible par les comptes autorisés, sans confirmation.</template>
                    <template v-else-if="chosenRecipient">{{ chosenRecipient.name }} est notifié. Les autres médecins pourront l’ouvrir après confirmation, tracée.</template>
                    <template v-else>Cette demande n’a pas de médecin prescripteur : choisissez à qui l’envoyer.</template>
                </p>
            </FormField>

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
