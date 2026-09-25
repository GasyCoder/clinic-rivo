<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Archive, Ban, Banknote, Info, Percent, Plus, TicketPercent, Trash2, UserCheck } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import OptionTile from '@/Components/Settings/OptionTile.vue';
import SettingsField from '@/Components/Settings/SettingsField.vue';
import SettingsSection from '@/Components/Settings/SettingsSection.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate } from '@/utilities/date';

/**
 * ADR-192 — les remises d'un site : celle du personnel (réglée ici, enregistrée
 * avec le reste des paramètres) et les coupons (créés et archivés tout de suite,
 * par l'API du site). La remise VIP se règle avec ses seuils, dans Patients VIP. Les règles de calcul appartiennent au serveur : une seule
 * remise par facture, la plus avantageuse, sur la part à la charge du patient.
 */
const props = defineProps({
    form: { type: Object, required: true },
    discounts: { type: Object, default: () => ({}) },
    siteCode: { type: String, required: true },
    siteName: { type: String, default: '' },
    isPortal: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
});

const { can } = usePermissions();

const TYPES = [
    { value: '', label: 'Aucune remise', icon: Ban },
    { value: 'PERCENT', label: 'Pourcentage', icon: Percent },
    { value: 'AMOUNT', label: 'Montant fixe', icon: Banknote },
];
const typeIcon = (value) => TYPES.find((type) => type.value === (value ?? ''))?.icon ?? Ban;

/** Choisir « Aucune remise » vide aussi la valeur : les deux vont ensemble. */
const chooseType = (who, value) => {
    props.form[`${who}_discount_type`] = value || '';
    if (! value) props.form[`${who}_discount_value`] = '';
};

const RULES = computed(() => [
    {
        who: 'staff',
        label: 'Personnel de la clinique',
        icon: UserCheck,
        hint: `Pour un patient relié à la fiche d’un employé en poste (${props.discounts.staff_linked ?? 0} sur ce site). La prise en charge du personnel ne change pas : la remise porte sur ce qui reste à sa charge — prestations non couvertes, dépassement du crédit Bloc.`,
    },
]);


const coupons = computed(() => props.discounts.coupons ?? []);
const activeCoupons = computed(() => coupons.value.filter((coupon) => ! coupon.archived));
const archivedCoupons = computed(() => coupons.value.filter((coupon) => coupon.archived));
const couponState = (coupon) => {
    if (coupon.archived) return { label: 'Archivé', variant: 'outline' };
    if (coupon.unusable_reason) return { label: 'Inutilisable', variant: 'warning' };

    return { label: 'Valable', variant: 'success' };
};
const validity = (coupon) => {
    if (coupon.valid_from && coupon.valid_until) return `du ${formatDate(coupon.valid_from)} au ${formatDate(coupon.valid_until)}`;
    if (coupon.valid_until) return `jusqu’au ${formatDate(coupon.valid_until)}`;
    if (coupon.valid_from) return `à partir du ${formatDate(coupon.valid_from)}`;

    return 'sans limite de date';
};

const couponOpen = ref(false);
const couponForm = useForm({ code: '', label: '', discount_type: 'PERCENT', discount_value: '', valid_from: '', valid_until: '', max_uses: '' });
const openCoupon = () => {
    couponForm.reset();
    couponForm.clearErrors();
    couponOpen.value = true;
};
const submitCoupon = () => couponForm
    .transform((values) => ({ ...values, code: values.code.trim().toUpperCase(), site_code: props.siteCode }))
    .post('/super-admin/settings/coupons', {
        preserveScroll: true,
        onSuccess: () => { couponOpen.value = false; },
    });

/**
 * Supprimer définitivement un coupon archivé : seulement s'il n'a jamais servi
 * (le serveur le dit par `deletion_blocker`, et le revérifie). Un coupon qui a
 * servi reste archivé, pour l'historique des factures qui le citent.
 */
const deleteTarget = ref(null);
const deleteForm = useForm({});
const openDelete = (coupon) => {
    deleteForm.clearErrors();
    deleteTarget.value = coupon;
};
const submitDelete = () => deleteForm
    .transform(() => ({ site_code: props.siteCode }))
    .delete(`/super-admin/settings/coupons/${deleteTarget.value.uuid}`, {
        preserveScroll: true,
        onSuccess: () => { deleteTarget.value = null; },
    });

const archiveTarget = ref(null);
const archiveForm = useForm({ reason: '' });
const openArchive = (coupon) => {
    archiveForm.reset();
    archiveForm.clearErrors();
    archiveTarget.value = coupon;
};
const submitArchive = () => archiveForm
    .transform((values) => ({ ...values, site_code: props.siteCode }))
    .post(`/super-admin/settings/coupons/${archiveTarget.value.uuid}/archive`, {
        preserveScroll: true,
        onSuccess: () => { archiveTarget.value = null; },
    });
</script>

<template>
    <SettingsSection id="remises" title="Remises" :description="isPortal ? 'Les remises portent sur les factures d’un site.' : `Les remises accordées à la Caisse de ${siteName}.`">
        <div v-if="isPortal" class="flex items-start gap-3 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
            <Info class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            Le portail n’émet aucune facture : choisissez un site en haut de la page pour régler ses remises et ses coupons.
        </div>

        <template v-else>
            <!-- Comment la Caisse choisit : écrit une fois, en tête. -->
            <ul class="grid gap-3 text-sm cq-4xl:grid-cols-3">
                <li class="flex items-start gap-3 rounded-lg border border-border bg-card p-3">
                    <OptionTile>1</OptionTile><span><span class="font-medium text-foreground">Une seule remise par facture</span><span class="block text-xs text-muted-foreground">La Caisse applique la plus avantageuse pour le patient.</span></span>
                </li>
                <li class="flex items-start gap-3 rounded-lg border border-border bg-card p-3">
                    <OptionTile><Percent class="h-3.5 w-3.5" /></OptionTile><span><span class="font-medium text-foreground">Sur la part du patient</span><span class="block text-xs text-muted-foreground">Après la mutuelle et la prise en charge du personnel.</span></span>
                </li>
                <li class="flex items-start gap-3 rounded-lg border border-border bg-card p-3">
                    <OptionTile><Ban class="h-3.5 w-3.5" /></OptionTile><span><span class="font-medium text-foreground">Avant tout paiement</span><span class="block text-xs text-muted-foreground">Une facture qui a reçu de l’argent ne change plus.</span></span>
                </li>
            </ul>

            <!-- Personnel -->
            <div v-for="rule in RULES" :key="rule.who" class="space-y-4 rounded-xl border border-border p-4 sm:p-5">
                <h4 class="flex items-center gap-2 text-base font-medium text-foreground"><component :is="rule.icon" class="h-4 w-4 text-primary" aria-hidden="true" />{{ rule.label }}</h4>
                <div class="grid gap-6 sm:grid-cols-2">
                    <SettingsField label="Remise" :for="`reglage-${rule.who}-discount-type`" :error="form.errors[`${rule.who}_discount_type`]">
                        <Select :id="`reglage-${rule.who}-discount-type`" :model-value="form[`${rule.who}_discount_type`] || ''" :options="TYPES" class="w-full" :disabled="readonly" @update:model-value="chooseType(rule.who, $event)">
                            <template #leading="{ option }"><OptionTile><component :is="typeIcon(option.value)" class="h-3.5 w-3.5" /></OptionTile></template>
                        </Select>
                    </SettingsField>
                    <SettingsField v-if="form[`${rule.who}_discount_type`]" label="Valeur" :for="`reglage-${rule.who}-discount-value`" :description="form[`${rule.who}_discount_type`] === 'PERCENT' ? 'De la part à la charge du patient.' : 'Déduit de la part à la charge du patient, jamais au-delà.'" :error="form.errors[`${rule.who}_discount_value`]">
                        <div class="relative">
                            <Input :id="`reglage-${rule.who}-discount-value`" v-model="form[`${rule.who}_discount_value`]" type="number" min="0" :max="form[`${rule.who}_discount_type`] === 'PERCENT' ? 100 : undefined" step="0.01" class="pe-10" :disabled="readonly" />
                            <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-sm text-muted-foreground">{{ form[`${rule.who}_discount_type`] === 'PERCENT' ? '%' : 'Ar' }}</span>
                        </div>
                    </SettingsField>
                </div>
                <p class="flex items-start gap-2 text-[0.8rem] leading-5 text-muted-foreground">
                    <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span>{{ rule.hint }}</span>
                </p>
            </div>

            <p class="flex items-start gap-2 text-[0.8rem] leading-5 text-muted-foreground">
                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                La remise propre à un patient précis s’accorde depuis son dossier, sur le site, par une personne habilitée (droit « discounts.approve »). Le crédit Bloc du personnel se gère dans les Ressources humaines du site.
            </p>

            <!-- Coupons : créés et archivés tout de suite, sans « Enregistrer ». -->
            <SettingsField v-if="can('discount_coupons.view')" label="Coupons" description="Un code saisi à la Caisse. Il se crée et s’archive tout de suite ; un code qui a servi ne se réutilise pas.">
                <div class="overflow-hidden rounded-lg border border-border">
                    <div class="flex items-center justify-between gap-3 border-b border-border bg-muted/50 px-4 py-2.5">
                        <span class="flex items-center gap-2 text-sm font-medium text-foreground"><TicketPercent class="h-4 w-4 text-muted-foreground" aria-hidden="true" />{{ activeCoupons.length }} coupon{{ activeCoupons.length > 1 ? 's' : '' }}</span>
                        <Button v-if="can('discount_coupons.create')" type="button" size="sm" @click="openCoupon"><Plus class="h-3.5 w-3.5" />Nouveau coupon</Button>
                    </div>
                    <ul v-if="activeCoupons.length" class="divide-y divide-border">
                        <li v-for="coupon in activeCoupons" :key="coupon.uuid" class="flex flex-wrap items-center gap-3 px-4 py-3">
                            <OptionTile><component :is="typeIcon(coupon.discount_type)" class="h-3.5 w-3.5" /></OptionTile>
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-2 text-sm"><span class="font-mono font-semibold text-foreground">{{ coupon.code }}</span><span class="text-muted-foreground">{{ coupon.describe }}</span><Badge :variant="couponState(coupon).variant">{{ couponState(coupon).label }}</Badge></p>
                                <p class="text-xs text-muted-foreground">{{ coupon.label ? `${coupon.label} · ` : '' }}{{ validity(coupon) }} · {{ coupon.uses_count }}{{ coupon.max_uses ? ` / ${coupon.max_uses}` : '' }} utilisation{{ coupon.uses_count > 1 ? 's' : '' }}<template v-if="coupon.unusable_reason"> · {{ coupon.unusable_reason }}</template></p>
                            </div>
                            <Button v-if="can('discount_coupons.archive')" type="button" variant="ghost" size="sm" title="Archiver ce coupon" @click="openArchive(coupon)"><Archive class="h-3.5 w-3.5" />Archiver</Button>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-6 text-center text-sm text-muted-foreground">Aucun coupon sur ce site.</p>
                </div>
                <details v-if="archivedCoupons.length" class="overflow-hidden rounded-lg border border-border" data-archived-coupons>
                    <summary class="flex cursor-pointer items-center gap-2 bg-muted/30 px-4 py-2.5 text-sm font-medium text-muted-foreground hover:text-foreground">
                        <Archive class="h-4 w-4" aria-hidden="true" />Coupons archivés ({{ archivedCoupons.length }})
                    </summary>
                    <ul class="divide-y divide-border border-t border-border">
                        <li v-for="coupon in archivedCoupons" :key="coupon.uuid" class="flex flex-wrap items-center gap-3 px-4 py-2.5">
                            <OptionTile><component :is="typeIcon(coupon.discount_type)" class="h-3.5 w-3.5" /></OptionTile>
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-2 text-sm"><span class="font-mono font-semibold text-foreground">{{ coupon.code }}</span><span class="text-muted-foreground">{{ coupon.describe }}</span><Badge variant="outline">Archivé</Badge></p>
                                <p class="text-xs text-muted-foreground">{{ coupon.label ? `${coupon.label} · ` : '' }}{{ coupon.uses_count }} utilisation{{ coupon.uses_count > 1 ? 's' : '' }}</p>
                            </div>
                            <Button
                                v-if="can('discount_coupons.force_delete')"
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="h-8 w-8 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                :disabled="Boolean(coupon.deletion_blocker)"
                                :title="coupon.deletion_blocker || `Supprimer définitivement ${coupon.code}`"
                                :aria-label="coupon.deletion_blocker ? `${coupon.code} : ${coupon.deletion_blocker}` : `Supprimer définitivement le coupon ${coupon.code}`"
                                data-delete-coupon
                                @click="openDelete(coupon)"
                            >
                                <Trash2 class="h-4 w-4" aria-hidden="true" />
                            </Button>
                        </li>
                    </ul>
                </details>
                <p v-if="deleteForm.errors.coupon || deleteForm.errors.site_code" class="text-[0.8rem] font-medium text-destructive" role="alert">{{ deleteForm.errors.coupon || deleteForm.errors.site_code }}</p>
            </SettingsField>
        </template>
    </SettingsSection>

    <Dialog :open="couponOpen" title="Nouveau coupon" :description="`Sur ${siteName}. Le code se saisit à la Caisse ; la remise porte sur la part à la charge du patient.`" :dismissible="! couponForm.processing" @update:open="couponOpen = $event">
        <template #icon><span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><TicketPercent class="h-5 w-5" /></span></template>
        <form id="coupon-form" class="space-y-4" @submit.prevent="submitCoupon">
            <div class="grid gap-4 sm:grid-cols-2">
                <SettingsField label="Code" for="coupon-code" description="Lettres, chiffres, « - » et « _ »." :error="couponForm.errors.code">
                    <IconInput id="coupon-code" v-model="couponForm.code" :icon="TicketPercent" class="font-mono uppercase" maxlength="40" placeholder="RENTREE-2026" />
                </SettingsField>
                <SettingsField label="Libellé" for="coupon-label" description="Facultatif, affiché à la Caisse." :error="couponForm.errors.label">
                    <Input id="coupon-label" v-model="couponForm.label" maxlength="150" placeholder="Campagne de dépistage" />
                </SettingsField>
                <SettingsField label="Type" for="coupon-type" :error="couponForm.errors.discount_type">
                    <Select id="coupon-type" v-model="couponForm.discount_type" :options="TYPES.slice(1)" class="w-full">
                        <template #leading="{ option }"><OptionTile><component :is="typeIcon(option.value)" class="h-3.5 w-3.5" /></OptionTile></template>
                    </Select>
                </SettingsField>
                <SettingsField label="Valeur" for="coupon-value" :error="couponForm.errors.discount_value">
                    <div class="relative">
                        <Input id="coupon-value" v-model="couponForm.discount_value" type="number" min="0" step="0.01" class="pe-10" :placeholder="couponForm.discount_type === 'PERCENT' ? '10' : '5000'" />
                        <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-sm text-muted-foreground">{{ couponForm.discount_type === 'PERCENT' ? '%' : 'Ar' }}</span>
                    </div>
                </SettingsField>
                <SettingsField label="Valable à partir du" description="Facultatif." :error="couponForm.errors.valid_from">
                    <DatePicker v-model="couponForm.valid_from" />
                </SettingsField>
                <SettingsField label="Jusqu’au" description="Facultatif." :error="couponForm.errors.valid_until">
                    <DatePicker v-model="couponForm.valid_until" />
                </SettingsField>
                <SettingsField label="Nombre d’utilisations" for="coupon-max-uses" description="Vide : sans limite." :error="couponForm.errors.max_uses">
                    <Input id="coupon-max-uses" v-model="couponForm.max_uses" type="number" min="1" step="1" placeholder="Sans limite" />
                </SettingsField>
            </div>
        </form>
        <template #footer>
            <Button type="button" variant="outline" :disabled="couponForm.processing" @click="couponOpen = false">Annuler</Button>
            <Button type="submit" form="coupon-form" :disabled="couponForm.processing"><Plus class="h-4 w-4" />Créer le coupon</Button>
        </template>
    </Dialog>

    <ConfirmModal
        :open="deleteTarget !== null"
        :title="deleteTarget ? `Supprimer définitivement ${deleteTarget.code} ?` : ''"
        description="Ce coupon n’a jamais servi : il disparaît de la liste et son code pourra être réutilisé. L’audit garde la trace de ce qu’il était. Cette suppression ne peut pas être annulée."
        confirm-label="Supprimer définitivement"
        tone="danger"
        :icon="Trash2"
        :processing="deleteForm.processing"
        @update:open="(open) => { if (! open) deleteTarget = null; }"
        @confirm="submitDelete"
    />

    <Dialog :open="archiveTarget !== null" title="Archiver ce coupon ?" :description="archiveTarget ? `${archiveTarget.code} ne pourra plus servir. Les factures qui l’ont reçu gardent leur remise.` : ''" :dismissible="! archiveForm.processing" @update:open="(open) => { if (! open) archiveTarget = null; }">
        <form id="coupon-archive-form" @submit.prevent="submitArchive">
            <SettingsField label="Motif" for="coupon-archive-reason" :error="archiveForm.errors.reason">
                <Textarea id="coupon-archive-reason" v-model="archiveForm.reason" rows="3" placeholder="Pourquoi ce coupon est archivé" />
            </SettingsField>
        </form>
        <template #footer>
            <Button type="button" variant="outline" :disabled="archiveForm.processing" @click="archiveTarget = null">Garder le coupon</Button>
            <Button type="submit" form="coupon-archive-form" variant="destructive" :disabled="archiveForm.processing"><Archive class="h-4 w-4" />Archiver</Button>
        </template>
    </Dialog>
</template>
