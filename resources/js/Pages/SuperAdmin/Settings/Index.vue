<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Loader2, Save, Server, TriangleAlert } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import AdvancedSettings from '@/Components/Settings/AdvancedSettings.vue';
import AgeBandSettings from '@/Components/Settings/AgeBandSettings.vue';
import CurrencySettings from '@/Components/Settings/CurrencySettings.vue';
import DirectionSettings from '@/Components/Settings/DirectionSettings.vue';
import DiscountSettings from '@/Components/Settings/DiscountSettings.vue';
import IdentitySettings from '@/Components/Settings/IdentitySettings.vue';
import LegalSettings from '@/Components/Settings/LegalSettings.vue';
import MaintenanceSettings from '@/Components/Settings/MaintenanceSettings.vue';
import NumberingSettings from '@/Components/Settings/NumberingSettings.vue';
import ScreenTemplates from '@/Components/Settings/ScreenTemplates.vue';
import SearchVisibilitySettings from '@/Components/Settings/SearchVisibilitySettings.vue';
import SettingsNav from '@/Components/Settings/SettingsNav.vue';
import SettingsSiteSwitcher from '@/Components/Settings/SettingsSiteSwitcher.vue';
import ThemeSettings from '@/Components/Settings/ThemeSettings.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard';
import { cn } from '@/lib/cn';
import { DEFAULT_PRIMARY, isHexColor } from '@/utilities/brandColor';
import { SETTINGS_SECTIONS, settingsSection, settingsUrl } from '@/utilities/settingsSections';

/**
 * Les paramètres de l'application, cible par cible (ADR-184), module par module
 * (ADR-191, amendement du 2026-09-25) : un titre et le choix du site réglé,
 * puis, sur toute la largeur, le module ouvert dans sa carte — ses champs, et au
 * pied de la carte l'état de la saisie et « Enregistrer » (Ctrl+S) — avec le
 * menu des modules à droite.
 *
 * Un site ne se règle que par son API ; un site injoignable le dit au lieu
 * d'afficher des champs vides qui se liraient « rien de réglé ». Le formulaire
 * porte toutes les valeurs du site : un module n'en modifie que les siennes, et
 * l'enregistrement renvoie le reste tel que le site le porte.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    section: { type: String, default: null },
    targets: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({}) },
    currencyLabels: { type: Array, default: () => ['Ar', 'Ariary', 'MGA'] },
    authTemplates: { type: Array, default: () => [] },
    profileTemplates: { type: Array, default: () => [] },
    // ADR-191 — thèmes proposés et bornes de la numérotation.
    themePresets: { type: Object, default: () => ({}) },
    numberingOptions: { type: Object, default: () => ({}) },
});

const page = usePage();
const { can } = usePermissions();
const canUpdate = computed(() => can('settings.update'));

/** Le module ouvert ; sans module, le premier (le serveur y redirige déjà). */
const current = computed(() => settingsSection(props.section) ?? SETTINGS_SECTIONS[0]);
/**
 * ADR-193 — un module sans champ du formulaire commun (la maintenance) agit tout
 * de suite, avec ses propres droits : ni « Enregistrer » en pied, ni l'avis
 * « settings.update ».
 */
const usesCommonForm = computed(() => current.value.fields.length > 0);

/* ------------------------------------------------------------------ */
/* Cible                                                               */
/* ------------------------------------------------------------------ */

const initialCode = new URLSearchParams(String(page.url ?? '').split('?')[1] ?? '').get('site');
const firstReachable = props.targets.find((target) => target.ok)?.site.code ?? props.targets[0]?.site.code ?? '';
const selectedCode = ref(props.targets.some((target) => target.site.code === initialCode) ? initialCode : firstReachable);

const target = computed(() => props.targets.find((item) => item.site.code === selectedCode.value) ?? props.targets[0] ?? null);

const data = computed(() => target.value?.data ?? null);
const isPortal = computed(() => target.value?.kind === 'portal');
const readonly = computed(() => ! canUpdate.value);

const FIELDS = [
    'app_name', 'app_tagline', 'primary_color', 'search_engines_hidden',
    'theme_preset', 'light_background', 'light_foreground', 'dark_primary_color', 'dark_background', 'dark_foreground',
    'ui_font_size', 'ui_density', 'ui_radius', 'ui_motion', 'ui_contrast',
    'patient_number_prefix', 'patient_number_year', 'patient_number_digits', 'patient_number_separator',
    'patient_number_reset', 'episode_number_digits',
    'employee_number_prefix', 'employee_number_separator', 'employee_number_digits',
    'auth_template', 'profile_template',
    'currency_label', 'currency_position', 'currency_decimals',
    'baby_max_age', 'child_max_age',
    'director_name', 'director_title',
    'legal_nif', 'legal_stat', 'legal_address', 'legal_phone', 'legal_email', 'bank_name', 'bank_account',
    'staff_discount_type', 'staff_discount_value',
];

const valuesOf = (payload) => Object.fromEntries(FIELDS.map((field) => {
    const value = payload?.values?.[field];

    if (['currency_decimals', 'baby_max_age', 'child_max_age'].includes(field)) return [field, Number(value ?? 0)];
    // Jamais réglé : la configuration du déploiement décide — masquée par défaut.
    if (field === 'search_engines_hidden') return [field, Boolean(value ?? payload?.fallbacks?.search_engines_hidden ?? true)];
    if (field === 'auth_template') return [field, value || 'COVER'];
    if (field === 'profile_template') return [field, value || 'SIDEBAR'];
    if (field === 'theme_preset') return [field, value || 'rivo'];
    // ADR-192 — une remise se lit « 10 », jamais « 10.00 » : la même valeur ne compte pas deux fois comme modifiée.
    if (field === 'staff_discount_value') return [field, value === null || value === undefined || value === '' ? '' : String(Number(value))];
    // Les couleurs s'éditent en majuscules : la même valeur ne compte pas deux fois comme modifiée.
    if (['primary_color', 'light_background', 'light_foreground', 'dark_primary_color', 'dark_background', 'dark_foreground'].includes(field)) return [field, value ? String(value).toUpperCase() : ''];

    return [field, value ?? ''];
}));

const form = useForm(valuesOf(data.value));
/** Ce que le site porte réellement : la base de comparaison des modifications. */
const saved = ref(valuesOf(data.value));

/** Les valeurs du site ont changé (enregistrement, autre cible) : le formulaire repart d'elles. */
const loadTarget = () => {
    saved.value = valuesOf(data.value);
    form.defaults({ ...saved.value });
    form.reset();
    form.clearErrors();
};

watch(data, () => {
    if (! form.isDirty) loadTarget();
});

const pendingTarget = ref(null);

/** L'adresse suit le site choisi, sans recharger : tous les sites sont déjà là. */
const switchTo = (code) => {
    selectedCode.value = code;
    loadTarget();
    router.replace({ url: settingsUrl(current.value.id, code), preserveState: true, preserveScroll: true });
};

const selectTarget = (code) => {
    if (code === selectedCode.value) return;
    if (form.isDirty) {
        pendingTarget.value = code;

        return;
    }
    switchTo(code);
};

const confirmSwitch = () => {
    const code = pendingTarget.value;
    pendingTarget.value = null;
    switchTo(code);
};

const dirty = computed(() => form.isDirty);
const leaveGuard = useUnsavedChangesGuard(dirty);

const changedCount = computed(() => FIELDS.filter((field) => String(form[field] ?? '') !== String(saved.value[field] ?? '')).length);

/* ------------------------------------------------------------------ */
/* Aperçus partagés                                                    */
/* ------------------------------------------------------------------ */

const fallbacks = computed(() => data.value?.fallbacks ?? {});
const siteStatus = computed(() => {
    if (target.value?.status === 'UNCONFIGURED') return 'Non configuré : son API n’est pas encore reliée au portail.';
    if (! target.value?.ok) return 'Injoignable : ses paramètres ne peuvent pas être lus.';
    if (! data.value?.updated_at) return 'Jamais réglé : la configuration du déploiement s’applique.';

    const when = new Date(data.value.updated_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });

    return `Réglé le ${when}${data.value.updated_by ? ` par ${data.value.updated_by}` : ''}`;
});
const colorPreview = computed(() => (isHexColor(form.primary_color) ? form.primary_color.toUpperCase() : DEFAULT_PRIMARY));
const agesValid = computed(() => Number(form.child_max_age) > Number(form.baby_max_age));

/* ------------------------------------------------------------------ */
/* Enregistrer                                                         */
/* ------------------------------------------------------------------ */

const submit = () => {
    if (! form.isDirty || ! agesValid.value) return;

    form
        .transform((values) => ({
            ...values,
            site_code: selectedCode.value,
            primary_color: isHexColor(values.primary_color) ? values.primary_color.toUpperCase() : null,
        }))
        .put('/super-admin/settings', {
            preserveScroll: true,
            onSuccess: () => {
                loadTarget();
                afterSave();
            },
        });
};

/**
 * Le portail applique ses couleurs et son icône dans l'en-tête de la page,
 * rendu par le serveur : on recharge pour les voir. Un site les applique à sa
 * prochaine page.
 */
const afterSave = () => {
    if (isPortal.value) window.location.reload();
};

/**
 * Ctrl+S (⌘+S sur Mac) enregistre le module, comme dans tout éditeur : jamais la
 * page du navigateur. Sans modification, sans droit ou sur un site injoignable,
 * rien ne part. Le raccourci affiché suit le poste, lu après le rendu serveur.
 */
const isMac = ref(false);
const onKeydown = (event) => {
    if (! (event.ctrlKey || event.metaKey) || event.altKey || event.key?.toLowerCase() !== 's') return;
    event.preventDefault();
    if (! readonly.value && target.value?.ok) submit();
};
onMounted(() => {
    isMac.value = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');
    window.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

</script>

<template>
    <Head :title="`${current.label} · Paramètres`" />

    <div class="w-full space-y-6 pb-16">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div class="min-w-0 flex-1 space-y-1">
                <h2 class="text-2xl font-bold tracking-tight text-foreground">Paramètres</h2>
                <p class="text-muted-foreground">Les réglages de chaque site et du portail. Un site se règle par son API ; le portail se règle lui-même.</p>
            </div>
            <div class="flex min-w-0 flex-col gap-1.5 xl:shrink-0 xl:items-end">
                <SettingsSiteSwitcher :targets="targets" :model-value="selectedCode" @update:model-value="selectTarget" />
                <p class="flex items-start gap-1.5 text-[0.8rem] leading-5 text-muted-foreground" aria-live="polite">
                    <span :class="cn('mt-2 h-1.5 w-1.5 shrink-0 rounded-full', target?.ok ? 'bg-emerald-500' : target?.status === 'UNCONFIGURED' ? 'bg-muted-foreground' : 'bg-destructive')" aria-hidden="true" />
                    <span><span class="font-medium text-foreground">{{ target?.site.name }}</span> · {{ siteStatus }}</span>
                </p>
            </div>
        </div>

        <!-- Le module ouvert dans sa carte, le menu des modules à sa droite (au-dessus sur un écran étroit). -->
        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_16rem]">
            <aside class="min-w-0 lg:sticky lg:top-24 lg:order-2">
                <SettingsNav :current="current.id" :site-code="selectedCode" />
            </aside>

            <div class="cq min-w-0 rounded-xl border border-border bg-card text-card-foreground shadow-sm lg:order-1">
                <div v-if="! target?.ok" class="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center">
                    <Server class="h-8 w-8 text-muted-foreground" aria-hidden="true" />
                    <h3 class="mt-4 text-lg font-medium text-foreground">Paramètres indisponibles pour {{ target?.site.name }}</h3>
                    <p class="mt-1 max-w-md text-sm text-muted-foreground">{{ target?.message || 'Le site ne répond pas actuellement. Les autres sites restent disponibles.' }}</p>
                </div>

                <form v-else novalidate @submit.prevent="submit">
                    <div class="space-y-8 p-5 sm:p-8">
                        <p v-if="readonly && usesCommonForm" class="flex items-start gap-2 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />Lecture seule : modifier les paramètres demande le droit « settings.update ».
                        </p>

                        <IdentitySettings
                            v-if="current.id === 'identite'"
                            :form="form"
                            :fallbacks="fallbacks"
                            :assets="data.assets"
                            :site-name="target.site.name"
                            :site-code="selectedCode"
                            :is-portal="isPortal"
                            :limits="limits"
                            :color-preview="colorPreview"
                            :readonly="readonly"
                            @saved="afterSave"
                        />
                        <ThemeSettings v-else-if="current.id === 'theme'" :form="form" :presets="themePresets" :readonly="readonly" :site-name="target.site.name" />
                        <AdvancedSettings v-else-if="current.id === 'avance'" :form="form" :readonly="readonly" />
                        <ScreenTemplates
                            v-else-if="current.id === 'ecrans'"
                            :form="form"
                            :auth-templates="authTemplates"
                            :profile-templates="profileTemplates"
                            :color-preview="colorPreview"
                            :assets="data.assets"
                            :site-code="selectedCode"
                            :site-name="target.site.name"
                            :limits="limits"
                            :fallback-background="fallbacks.auth_background_url || ''"
                            :readonly="readonly"
                            @saved="afterSave"
                        />
                        <NumberingSettings
                            v-else-if="current.id === 'numerotation'"
                            :form="form"
                            :saved="saved"
                            :numbering="data.numbering ?? {}"
                            :fallbacks="fallbacks"
                            :options="numberingOptions"
                            :readonly="readonly"
                        />
                        <AgeBandSettings v-else-if="current.id === 'ages'" :form="form" :limits="limits" :readonly="readonly" />
                        <CurrencySettings v-else-if="current.id === 'monnaie'" :form="form" :currency-labels="currencyLabels" :readonly="readonly" />
                        <DiscountSettings
                            v-else-if="current.id === 'remises'"
                            :form="form"
                            :discounts="data.discounts ?? {}"
                            :site-code="selectedCode"
                            :site-name="target.site.name"
                            :is-portal="isPortal"
                            :readonly="readonly"
                        />
                        <LegalSettings v-else-if="current.id === 'legal'" :form="form" :fallbacks="fallbacks" :site-name="target.site.name" :readonly="readonly" />
                        <DirectionSettings v-else-if="current.id === 'direction'" :form="form" :fallbacks="fallbacks" :assets="data.assets" :site-code="selectedCode" :limits="limits" :readonly="readonly" />
                        <SearchVisibilitySettings v-else-if="current.id === 'visibilite'" :form="form" :site-name="target.site.name" :readonly="readonly" />
                        <MaintenanceSettings
                            v-else-if="current.id === 'maintenance'"
                            :maintenance="data.maintenance ?? {}"
                            :site-code="selectedCode"
                            :site-name="target.site.name"
                            :is-portal="isPortal"
                        />

                        <!-- Un refus qui ne porte sur aucun champ (site injoignable, base non migrée…) : dit ici, jamais tu. -->
                        <p v-if="form.errors.site_code" class="flex items-start gap-2 rounded-lg border border-destructive/40 px-4 py-3 text-sm text-destructive" role="alert">
                            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />{{ form.errors.site_code }}
                        </p>
                    </div>

                    <!-- Le pied de la carte : l'état de la saisie et l'enregistrement, toujours à portée même sur un long module. -->
                    <div v-if="! readonly && usesCommonForm" class="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-3 rounded-b-xl border-t border-border bg-card/95 px-5 py-3 backdrop-blur sm:px-8 sm:py-4">
                        <p class="flex items-center gap-2 text-sm text-muted-foreground" aria-live="polite">
                            <span :class="cn('h-2 w-2 shrink-0 rounded-full', form.isDirty ? 'bg-amber-500' : 'bg-emerald-500')" aria-hidden="true" />
                            <template v-if="form.isDirty">{{ changedCount }} modification{{ changedCount > 1 ? 's' : '' }} non enregistrée{{ changedCount > 1 ? 's' : '' }}</template>
                            <template v-else>Tout est enregistré.</template>
                        </p>
                        <div class="ml-auto flex flex-wrap items-center gap-2">
                            <span class="hidden items-center gap-1 text-xs text-muted-foreground sm:inline-flex" aria-hidden="true">
                                <kbd class="rounded border border-border bg-muted px-1.5 py-0.5 font-mono text-[0.7rem] text-foreground">{{ isMac ? '⌘' : 'Ctrl' }}</kbd>
                                <kbd class="rounded border border-border bg-muted px-1.5 py-0.5 font-mono text-[0.7rem] text-foreground">S</kbd>
                            </span>
                            <Button v-if="form.isDirty" type="button" variant="outline" :disabled="form.processing" @click="loadTarget">Annuler</Button>
                            <Button type="submit" :disabled="! form.isDirty || form.processing || ! agesValid" :aria-keyshortcuts="isMac ? 'Meta+S' : 'Control+S'">
                                <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" />
                                <Save v-else class="h-4 w-4" aria-hidden="true" />
                                {{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
                            </Button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <ConfirmModal
            :open="pendingTarget !== null"
            title="Abandonner vos modifications ?"
            :description="`${changedCount} modification${changedCount > 1 ? 's' : ''} (${target?.site.name}) n’${changedCount > 1 ? 'ont' : 'a'} pas été enregistrée${changedCount > 1 ? 's' : ''}.`"
            confirm-label="Abandonner et changer de site"
            tone="warning"
            @update:open="pendingTarget = $event ? pendingTarget : null"
            @confirm="confirmSwitch"
        />
        <ConfirmModal
            :open="leaveGuard.pendingVisit.value !== null"
            title="Quitter sans enregistrer ?"
            :description="`${changedCount} modification${changedCount > 1 ? 's' : ''} ne ser${changedCount > 1 ? 'ont' : 'a'} pas enregistrée${changedCount > 1 ? 's' : ''}.`"
            confirm-label="Quitter"
            tone="warning"
            @update:open="(open) => open || leaveGuard.stay()"
            @confirm="leaveGuard.leave"
        />
    </div>
</template>
