<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Ban, Banknote, BadgePercent, Check, Crown, Percent, ShieldCheck, Users } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import OptionTile from '@/Components/Settings/OptionTile.vue';
import FormError from '@/Components/UI/FormError.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

/**
 * Seuils des patients VIP, site par site (ADR-133).
 *
 * Un patient est VIP quand il a **à la fois** assez de passages **et** assez
 * d'argent encaissé sur une fenêtre glissante. Les trois valeurs se règlent
 * ici pour chaque site ; le statut lui-même n'est jamais saisi, il se recalcule
 * à chaque lecture. Ce que ces seuils donnent aujourd'hui s'affiche avant
 * d'enregistrer : régler un seuil à l'aveugle n'aurait pas de sens.
 *
 * ADR-192 — la remise VIP se règle ici aussi, avec les seuils : facultative, un
 * pourcentage ou un montant sur la part à la charge du patient, appliquée par la
 * Caisse si elle est la plus avantageuse.
 */
const props = defineProps({
    sites: { type: Array, default: () => [] },
});

const { can } = usePermissions();
const canUpdate = computed(() => can('patient_vip.update'));

const selectedCode = ref(props.sites.find((site) => site.ok)?.site.code ?? props.sites[0]?.site.code);
const selected = computed(() => props.sites.find((site) => site.site.code === selectedCode.value));
// Les valeurs viennent du site lui-même : chacun répond pour ses propres seuils.
const current = computed(() => selected.value?.data ?? null);

const form = useForm({ site_code: '', enabled: true, min_episodes: 5, min_amount: 1000000, window_months: 12, discount_type: '', discount_value: '' });

const DISCOUNT_TYPES = [
    { value: '', label: 'Aucune remise', icon: Ban },
    { value: 'PERCENT', label: 'Pourcentage', icon: Percent },
    { value: 'AMOUNT', label: 'Montant fixe', icon: Banknote },
];
const discountIcon = (value) => DISCOUNT_TYPES.find((type) => type.value === (value ?? ''))?.icon ?? Ban;
/** « Aucune remise » vide aussi la valeur : les deux vont ensemble. */
const chooseDiscountType = (value) => {
    form.discount_type = value || '';
    if (! value) form.discount_value = '';
};
/** « 10 », jamais « 10.00 » : la même valeur ne compte pas deux fois comme modifiée. */
const plain = (value) => (value === null || value === undefined || value === '' ? '' : String(Number(value)));

const load = () => {
    const data = current.value;

    form.site_code = selectedCode.value;
    form.clearErrors();

    // Un site sans réglage n'a pas de VIP : le formulaire propose des valeurs de
    // départ que le Super Admin doit **choisir** — rien n'est appliqué tant qu'il
    // n'a pas enregistré.
    form.enabled = data?.configured ? Boolean(data.enabled) : true;
    form.min_episodes = data?.min_episodes ?? 5;
    form.min_amount = data?.min_amount !== null && data?.min_amount !== undefined ? Number(data.min_amount) : 1000000;
    form.window_months = data?.window_months ?? 12;
    form.discount_type = data?.discount_type ?? '';
    form.discount_value = plain(data?.discount_value);
};

watch(selectedCode, load, { immediate: true });
watch(() => props.sites, load);

const dirty = computed(() => {
    const data = current.value;

    if (!data?.configured) {
        return true;
    }

    return form.enabled !== Boolean(data.enabled)
        || Number(form.min_episodes) !== Number(data.min_episodes)
        || Number(form.min_amount) !== Number(data.min_amount)
        || Number(form.window_months) !== Number(data.window_months)
        || (form.discount_type || '') !== (data.discount_type ?? '')
        || plain(form.discount_value) !== plain(data.discount_value);
});

// --- Aperçu : ce que ces seuils donneraient sur ce site, sans rien écrire.
const preview = ref(null);
const previewError = ref('');
const previewing = ref(false);
let previewTimer = null;
let previewSeq = 0;

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const runPreview = async () => {
    const seq = ++previewSeq;
    previewing.value = true;
    previewError.value = '';

    try {
        const response = await fetch('/super-admin/patient-vip/preview', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                site_code: selectedCode.value,
                enabled: form.enabled,
                min_episodes: Number(form.min_episodes),
                min_amount: Number(form.min_amount),
                window_months: Number(form.window_months),
            }),
        });
        const payload = await response.json().catch(() => ({}));

        // Une réponse tardive d'une frappe précédente ne remplace pas la plus récente.
        if (seq !== previewSeq) {
            return;
        }

        if (!response.ok) {
            preview.value = null;
            previewError.value = payload?.message ?? 'Aperçu indisponible : le site ne répond pas.';

            return;
        }

        preview.value = payload.data;
    } catch {
        if (seq === previewSeq) {
            preview.value = null;
            previewError.value = 'Aperçu indisponible : le site ne répond pas.';
        }
    } finally {
        if (seq === previewSeq) {
            previewing.value = false;
        }
    }
};

const schedulePreview = () => {
    clearTimeout(previewTimer);

    if (!selected.value?.ok) {
        return;
    }

    previewTimer = setTimeout(runPreview, 450);
};

watch(() => [selectedCode.value, form.enabled, form.min_episodes, form.min_amount, form.window_months], schedulePreview, { immediate: true });
onBeforeUnmount(() => clearTimeout(previewTimer));

const number = (value) => Number(value ?? 0).toLocaleString('fr-FR');
const formatDate = (iso) => (iso ? new Date(iso).toLocaleString('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }) : null);

const submit = () => {
    form.transform((data) => ({
        ...data,
        min_episodes: Number(data.min_episodes),
        min_amount: Number(data.min_amount),
        window_months: Number(data.window_months),
        discount_type: data.discount_type || null,
        discount_value: data.discount_type && data.discount_value !== '' ? Number(data.discount_value) : null,
    })).put('/super-admin/patient-vip', { preserveScroll: true });
};
</script>

<template>
    <Head title="Patients VIP" />

    <div class="w-full space-y-5 pb-8">
        <header class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div class="flex items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary text-primary-foreground shadow-sm">
                    <Crown class="h-5 w-5" />
                </span>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Super Administration</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold tracking-tight text-foreground">Patients VIP</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Un patient VIP est très fréquent <strong>et</strong> a beaucoup apporté à la clinique. Réglez les seuils de chaque site.
                    </p>
                </div>
            </div>
            <span class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-xs text-muted-foreground">
                <ShieldCheck class="h-4 w-4" />Écritures auditées sur le site destinataire
            </span>
        </header>

        <Card class="overflow-hidden">
            <div class="flex gap-1 overflow-x-auto border-b border-border bg-muted/50 p-2" role="tablist" aria-label="Site">
                <button
                    v-for="site in sites"
                    :key="site.site.code"
                    type="button"
                    role="tab"
                    :aria-selected="selectedCode === site.site.code"
                    :class="[
                        'inline-flex min-w-40 items-center justify-center gap-2 rounded-md px-4 py-2.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30',
                        selectedCode === site.site.code ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                    ]"
                    @click="selectedCode = site.site.code"
                >
                    <span :class="['h-2 w-2 rounded-full', site.ok ? 'bg-emerald-500' : 'bg-red-500']" />
                    {{ site.site.name }}
                </button>
            </div>

            <div v-if="!selected?.ok" class="px-6 py-12 text-center" role="alert">
                <p class="text-sm font-semibold text-foreground">Ce site ne répond pas pour le moment.</p>
                <p class="mt-1 text-xs text-muted-foreground">{{ selected?.message ?? 'Les seuils ne peuvent être ni lus ni modifiés.' }}</p>
            </div>

            <div v-else class="grid gap-0 lg:grid-cols-[1fr_20rem]">
                <form class="space-y-5 p-5 sm:p-6" @submit.prevent="submit">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-base font-bold text-foreground">Seuils de {{ selected.site.name }}</h2>
                        <Badge v-if="current?.configured" :variant="current.enabled ? 'success' : 'outline'">
                            {{ current.enabled ? 'Actif' : 'Désactivé' }}
                        </Badge>
                        <Badge v-else variant="warning">Non réglé — aucun patient VIP</Badge>
                    </div>

                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-border p-3.5">
                        <Checkbox v-model="form.enabled" class="mt-0.5" aria-label="Activer les patients VIP sur ce site" :disabled="!canUpdate" />
                        <span>
                            <span class="block text-sm font-semibold text-foreground">Activer les patients VIP sur ce site</span>
                            <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">
                                Désactivé, plus aucun patient n’est VIP : la catégorie disparaît de la liste sans rien effacer.
                            </span>
                        </span>
                    </label>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <FormField label="Passages au moins" required :error="form.errors.min_episodes">
                            <Input v-model="form.min_episodes" type="number" min="1" max="1000" step="1" inputmode="numeric" :disabled="!canUpdate" />
                        </FormField>
                        <FormField label="Argent encaissé (Ar)" required :error="form.errors.min_amount">
                            <Input v-model="form.min_amount" type="number" min="0" step="1000" inputmode="numeric" :disabled="!canUpdate" />
                        </FormField>
                        <FormField label="Sur les derniers (mois)" required :error="form.errors.window_months">
                            <Input v-model="form.window_months" type="number" min="1" max="120" step="1" inputmode="numeric" :disabled="!canUpdate" />
                        </FormField>
                    </div>

                    <p class="text-xs leading-5 text-muted-foreground">
                        Il faut remplir <strong>les deux</strong> conditions. « Encaissé » est l’argent réellement reçu à la Caisse (paiements enregistrés),
                        jamais un montant facturé ni une prise en charge de mutuelle. Les passages annulés ne comptent pas.
                    </p>

                    <!-- ADR-192 — la remise VIP, réglée avec les seuils. -->
                    <div class="space-y-4 rounded-lg border border-border p-4">
                        <div>
                            <h3 class="flex items-center gap-2 text-sm font-semibold text-foreground"><BadgePercent class="h-4 w-4 text-primary" />Remise VIP</h3>
                            <p class="mt-0.5 text-xs leading-5 text-muted-foreground">
                                Facultative. Calculée sur la part à la charge du patient ; la Caisse l’applique si c’est la plus avantageuse (une seule remise par facture).
                            </p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField label="Remise" :error="form.errors.discount_type">
                                <Select id="vip-discount-type" :model-value="form.discount_type || ''" :options="DISCOUNT_TYPES" class="w-full" :disabled="!canUpdate" @update:model-value="chooseDiscountType">
                                    <template #leading="{ option }"><OptionTile><component :is="discountIcon(option.value)" class="h-3.5 w-3.5" /></OptionTile></template>
                                </Select>
                            </FormField>
                            <FormField v-if="form.discount_type" label="Valeur" required :error="form.errors.discount_value">
                                <div class="relative">
                                    <Input id="vip-discount-value" v-model="form.discount_value" type="number" min="0" :max="form.discount_type === 'PERCENT' ? 100 : undefined" step="0.01" class="pe-10" :disabled="!canUpdate" />
                                    <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-sm text-muted-foreground">{{ form.discount_type === 'PERCENT' ? '%' : 'Ar' }}</span>
                                </div>
                            </FormField>
                        </div>
                        <p v-if="form.discount_type && !form.enabled" class="text-xs text-amber-700 dark:text-amber-300">La catégorie VIP est désactivée : cette remise ne s’appliquera à personne.</p>
                    </div>

                    <FormError :message="form.errors.site_code" />

                    <div v-if="canUpdate" class="flex justify-end gap-3 border-t border-border pt-4">
                        <Button type="submit" :disabled="form.processing || !dirty">
                            <Check class="h-4 w-4" />{{ current?.configured ? 'Enregistrer les seuils' : 'Enregistrer et activer' }}
                        </Button>
                    </div>
                </form>

                <aside class="space-y-4 border-t border-border bg-muted/30 p-5 sm:p-6 lg:border-s lg:border-t-0" aria-live="polite">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Avec ces seuils</p>
                        <p v-if="previewError" class="mt-2 text-sm text-destructive">{{ previewError }}</p>
                        <template v-else-if="preview">
                            <p class="mt-2 flex items-baseline gap-2">
                                <span class="text-3xl font-bold tabular-nums text-foreground" :class="previewing && 'opacity-50'">{{ number(preview.vip_count) }}</span>
                                <span class="text-sm text-muted-foreground">patient{{ preview.vip_count > 1 ? 's' : '' }} VIP sur {{ number(preview.patients_count) }}</span>
                            </p>
                            <p v-if="!form.enabled" class="mt-1 text-xs text-muted-foreground">Désactivé : aucun patient ne serait VIP.</p>
                            <p v-else-if="preview.rule" class="mt-1 text-xs leading-5 text-muted-foreground">{{ preview.rule }}.</p>
                        </template>
                        <p v-else class="mt-2 text-sm text-muted-foreground">Calcul…</p>
                    </div>

                    <div class="border-t border-border pt-4">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Actuellement enregistré</p>
                        <p v-if="current?.configured" class="mt-2 text-sm text-foreground">
                            <span class="font-semibold tabular-nums">{{ number(current.vip_count) }}</span>
                            patient{{ current.vip_count > 1 ? 's' : '' }} VIP
                            <span class="block text-xs text-muted-foreground">{{ current.enabled ? current.rule : 'Désactivé' }}</span>
                            <span class="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground"><BadgePercent class="h-3.5 w-3.5" />{{ current.discount ? `Remise VIP : ${current.discount}` : 'Aucune remise VIP' }}</span>
                            <span v-if="current.updated_at" class="mt-1 block text-xs text-muted-foreground">
                                Modifié le {{ formatDate(current.updated_at) }}<template v-if="current.updated_by"> par {{ current.updated_by }}</template>
                            </span>
                        </p>
                        <p v-else class="mt-2 flex items-start gap-2 text-sm text-muted-foreground">
                            <Users class="mt-0.5 h-4 w-4 shrink-0" />Aucun seuil n’est enregistré : ce site n’a aucun patient VIP.
                        </p>
                    </div>
                </aside>
            </div>
        </Card>
    </div>
</template>
