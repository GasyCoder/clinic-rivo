<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Ban, BadgePercent, Banknote, Crown, Percent, Plus, UserCheck } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import OptionTile from '@/Components/Settings/OptionTile.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate } from '@/utilities/date';

/**
 * ADR-192 — les remises d'un patient dans son dossier : celle qui lui est propre
 * (accordée ou annulée par une personne habilitée, `discounts.approve`), et celles
 * auxquelles son statut lui donne droit (VIP, personnel). La Caisse applique la
 * plus avantageuse ; rien n'est calculé ici.
 */
const props = defineProps({
    patientUuid: { type: String, required: true },
    discounts: { type: Object, required: true },
});

const { can } = usePermissions();
const canApprove = computed(() => can('discounts.approve'));

const TYPES = [
    { value: 'PERCENT', label: 'Pourcentage', icon: Percent },
    { value: 'AMOUNT', label: 'Montant fixe', icon: Banknote },
];
const typeIcon = (value) => TYPES.find((type) => type.value === value)?.icon ?? Percent;

const inForce = computed(() => props.discounts.items.filter((item) => item.in_force));
const past = computed(() => props.discounts.items.filter((item) => ! item.in_force));

const grantOpen = ref(false);
const grantForm = useForm({ discount_type: 'PERCENT', discount_value: '', reason: '', valid_from: '', valid_until: '' });
const openGrant = () => {
    grantForm.reset();
    grantForm.clearErrors();
    grantOpen.value = true;
};
const submitGrant = () => grantForm.post(`/patients/${props.patientUuid}/discounts`, {
    preserveScroll: true,
    onSuccess: () => { grantOpen.value = false; },
});

const cancelTarget = ref(null);
const cancelForm = useForm({ reason: '' });
const openCancel = (item) => {
    cancelForm.reset();
    cancelForm.clearErrors();
    cancelTarget.value = item;
};
const submitCancel = () => cancelForm.post(`/patient-discounts/${cancelTarget.value.uuid}/cancel`, {
    preserveScroll: true,
    onSuccess: () => { cancelTarget.value = null; },
});

const period = (item) => (item.valid_until
    ? `du ${formatDate(item.valid_from)} au ${formatDate(item.valid_until)}`
    : `depuis le ${formatDate(item.valid_from)}`);
</script>

<template>
    <Card class="p-5">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2"><BadgePercent class="h-4 w-4 text-primary" /><h2 class="text-sm font-bold text-foreground">Remises</h2></div>
            <Button v-if="canApprove" size="xs" variant="ghost" type="button" @click="openGrant"><Plus class="h-3.5 w-3.5" />Accorder</Button>
        </div>

        <!-- Ce à quoi son statut lui donne droit, réglé par site. -->
        <ul v-if="discounts.status.length" class="mt-3 space-y-1.5">
            <li v-for="item in discounts.status" :key="item.source" class="flex items-center gap-2 text-xs text-foreground">
                <OptionTile><Crown v-if="item.source === 'VIP'" class="h-3.5 w-3.5" /><UserCheck v-else class="h-3.5 w-3.5" /></OptionTile>
                <span><span class="font-semibold">{{ item.label }}</span> · {{ item.describe }}</span>
            </li>
        </ul>

        <!-- Sa remise propre. -->
        <ul v-if="inForce.length" class="mt-3 space-y-2">
            <li v-for="item in inForce" :key="item.uuid" class="rounded-lg border border-emerald-200 bg-emerald-50/60 px-3 py-2 dark:border-emerald-900 dark:bg-emerald-950/20">
                <div class="flex items-start justify-between gap-2">
                    <p class="flex items-center gap-2 text-sm font-semibold text-foreground"><component :is="typeIcon(item.discount_type)" class="h-3.5 w-3.5 text-emerald-600" />Remise patient · {{ item.describe }}</p>
                    <Button v-if="canApprove" size="xs" variant="ghost" type="button" title="Annuler cette remise" @click="openCancel(item)"><Ban class="h-3.5 w-3.5" /></Button>
                </div>
                <p class="mt-0.5 text-xs text-muted-foreground">{{ item.reason }}</p>
                <p class="mt-0.5 text-[11px] text-muted-foreground">{{ period(item) }}<template v-if="item.created_by"> · accordée par {{ item.created_by }}</template></p>
            </li>
        </ul>

        <p v-if="! discounts.status.length && ! inForce.length" class="mt-3 text-sm text-muted-foreground">Aucune remise pour ce patient.</p>
        <p class="mt-3 text-[11px] leading-4 text-muted-foreground">Une seule remise par facture : la Caisse applique la plus avantageuse, sur la part à la charge du patient.</p>

        <details v-if="past.length" class="mt-3 text-xs">
            <summary class="cursor-pointer text-muted-foreground hover:text-foreground">Anciennes remises ({{ past.length }})</summary>
            <ul class="mt-2 space-y-1.5">
                <li v-for="item in past" :key="item.uuid" class="text-muted-foreground">
                    <span class="font-medium text-foreground">{{ item.describe }}</span> · {{ period(item) }}
                    <Badge v-if="item.cancelled_at" variant="outline" class="ms-1">Annulée</Badge>
                    <p v-if="item.cancel_reason" class="mt-0.5">{{ item.cancel_reason }}</p>
                </li>
            </ul>
        </details>
    </Card>

    <Dialog :open="grantOpen" title="Accorder une remise à ce patient" description="Appliquée par la Caisse si elle est la plus avantageuse, sur la part à sa charge." :dismissible="! grantForm.processing" @update:open="grantOpen = $event">
        <template #icon><span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><BadgePercent class="h-5 w-5" /></span></template>
        <form id="patient-discount-form" class="space-y-4" @submit.prevent="submitGrant">
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField label="Type" required :error="grantForm.errors.discount_type">
                    <Select id="patient-discount-type" v-model="grantForm.discount_type" :options="TYPES" class="w-full">
                        <template #leading="{ option }"><OptionTile><component :is="typeIcon(option.value)" class="h-3.5 w-3.5" /></OptionTile></template>
                    </Select>
                </FormField>
                <FormField label="Valeur" required :error="grantForm.errors.discount_value">
                    <div class="relative">
                        <Input id="patient-discount-value" v-model="grantForm.discount_value" type="number" min="0" step="0.01" class="pe-10" :placeholder="grantForm.discount_type === 'PERCENT' ? '10' : '5000'" />
                        <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-sm text-muted-foreground">{{ grantForm.discount_type === 'PERCENT' ? '%' : 'Ar' }}</span>
                    </div>
                </FormField>
                <FormField label="À partir du" hint="(aujourd’hui si vide)" :error="grantForm.errors.valid_from">
                    <DatePicker v-model="grantForm.valid_from" />
                </FormField>
                <FormField label="Jusqu’au" hint="(sans fin si vide)" :error="grantForm.errors.valid_until">
                    <DatePicker v-model="grantForm.valid_until" />
                </FormField>
            </div>
            <FormField label="Motif" required :error="grantForm.errors.reason">
                <Textarea id="patient-discount-reason" v-model="grantForm.reason" rows="3" placeholder="Pourquoi ce patient reçoit cette remise" />
            </FormField>
        </form>
        <template #footer>
            <Button type="button" variant="outline" :disabled="grantForm.processing" @click="grantOpen = false">Annuler</Button>
            <Button type="submit" form="patient-discount-form" :disabled="grantForm.processing">Accorder la remise</Button>
        </template>
    </Dialog>

    <Dialog :open="cancelTarget !== null" title="Annuler la remise de ce patient ?" :description="cancelTarget ? `Remise de ${cancelTarget.describe}. Les factures qui l’ont déjà reçue la gardent.` : ''" :dismissible="! cancelForm.processing" @update:open="(open) => { if (! open) cancelTarget = null; }">
        <form id="patient-discount-cancel-form" @submit.prevent="submitCancel">
            <FormField label="Motif" required :error="cancelForm.errors.reason">
                <Textarea id="patient-discount-cancel-reason" v-model="cancelForm.reason" rows="3" placeholder="Pourquoi cette remise est annulée" />
            </FormField>
        </form>
        <template #footer>
            <Button type="button" variant="outline" :disabled="cancelForm.processing" @click="cancelTarget = null">Garder la remise</Button>
            <Button type="submit" form="patient-discount-cancel-form" variant="destructive" :disabled="cancelForm.processing">Annuler la remise</Button>
        </template>
    </Dialog>
</template>
