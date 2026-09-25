<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import {
    Baby,
    Banknote,
    Building2,
    Check,
    Coins,
    FileText,
    Globe,
    Landmark,
    LayoutTemplate,
    Loader2,
    Mail,
    MapPin,
    Palette,
    PenLine,
    Phone,
    Quote,
    RotateCcw,
    Save,
    SearchX,
    Server,
    Settings2,
    TriangleAlert,
    Undo2,
    UserRound,
    Users,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import SettingsAssetField from '@/Components/Settings/SettingsAssetField.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard';
import { monogramOf } from '@/lib/brand';
import { cn } from '@/lib/cn';
import { DEFAULT_PRIMARY, PRIMARY_PRESETS, isHexColor, readability } from '@/utilities/brandColor';
import { formatMoney } from '@/utilities/money';
import { bandRange, describeAgeBands } from '@/utilities/patientAge';

/**
 * Les paramètres de l'application, cible par cible (ADR-184).
 *
 * Chaque site a les siens — nom, devise, logo, icône, couleur, modèles des
 * pages de connexion et de « Mon profil », image de fond, écriture de
 * l'Ariary, tranches d'âge des patients, identité légale, direction,
 * visibilité pour les moteurs de recherche — et le portail les siens. Un site ne se règle que par son API ; un site injoignable le dit au
 * lieu d'afficher des champs vides qui se liraient « rien de réglé ».
 *
 * Le texte s'enregistre d'un geste, avec la barre du bas ; un fichier se dépose
 * et s'enregistre seul, depuis son champ.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    targets: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({}) },
    currencyLabels: { type: Array, default: () => ['Ar', 'Ariary', 'MGA'] },
    authTemplates: { type: Array, default: () => [] },
    profileTemplates: { type: Array, default: () => [] },
});

const page = usePage();
const { can } = usePermissions();
const canUpdate = computed(() => can('settings.update'));

const SECTIONS = [
    { id: 'identite', label: 'Identité', icon: Globe },
    { id: 'couleurs', label: 'Couleurs', icon: Palette },
    { id: 'ecrans', label: 'Écrans & modèles', icon: LayoutTemplate },
    { id: 'monnaie', label: 'Monnaie', icon: Coins },
    { id: 'ages', label: 'Âges des patients', icon: Baby },
    { id: 'legal', label: 'Identité légale', icon: Landmark },
    { id: 'direction', label: 'Direction', icon: PenLine },
    { id: 'visibilite', label: 'Moteurs de recherche', icon: SearchX },
];

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
    'auth_template', 'profile_template',
    'currency_label', 'currency_position', 'currency_decimals',
    'baby_max_age', 'child_max_age',
    'director_name', 'director_title',
    'legal_nif', 'legal_stat', 'legal_address', 'legal_phone', 'legal_email', 'bank_name', 'bank_account',
];

const valuesOf = (payload) => Object.fromEntries(FIELDS.map((field) => {
    const value = payload?.values?.[field];

    if (['currency_decimals', 'baby_max_age', 'child_max_age'].includes(field)) return [field, Number(value ?? 0)];
    // Jamais réglé : la configuration du déploiement décide — masquée par défaut.
    if (field === 'search_engines_hidden') return [field, Boolean(value ?? payload?.fallbacks?.search_engines_hidden ?? true)];
    if (field === 'auth_template') return [field, value || 'COVER'];
    if (field === 'profile_template') return [field, value || 'SIDEBAR'];

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

const selectTarget = (code) => {
    if (code === selectedCode.value) return;
    if (form.isDirty) {
        pendingTarget.value = code;
        return;
    }
    selectedCode.value = code;
    loadTarget();
};

const confirmSwitch = () => {
    selectedCode.value = pendingTarget.value;
    pendingTarget.value = null;
    loadTarget();
};

const dirty = computed(() => form.isDirty);
const leaveGuard = useUnsavedChangesGuard(dirty);

const changedCount = computed(() => FIELDS.filter((field) => String(form[field] ?? '') !== String(saved.value[field] ?? '')).length);

/* ------------------------------------------------------------------ */
/* Aperçus                                                             */
/* ------------------------------------------------------------------ */

const fallbacks = computed(() => data.value?.fallbacks ?? {});
const brandPreview = computed(() => String(form.app_name ?? '').trim() || fallbacks.value.app_name || 'Clinique Saint Georges');
const iconPreview = computed(() => data.value?.assets?.icon?.data_url ?? null);
const taglinePreview = computed(() => String(form.app_tagline ?? '').trim() || fallbacks.value.app_tagline || '');
/** L'image que les modèles Couverture et Partagé montreront : celle du site, sinon celle par défaut. */
const backgroundPreview = computed(() => data.value?.assets?.background?.data_url || fallbacks.value.auth_background_url || null);

const AUTH_TEMPLATE_HINTS = {
    COVER: 'L’image occupe tout l’écran, le formulaire flotte sur une carte.',
    SPLIT: 'Le formulaire sur un panneau, l’image à côté.',
    CENTERED: 'Une carte centrée sur un fond sobre, sans image.',
};
const PROFILE_TEMPLATE_HINTS = {
    SIDEBAR: 'Une carte et un menu à gauche, la section à droite.',
    BANNER: 'Un bandeau au nom de l’utilisateur, puis des onglets.',
};
const authUsesBackground = computed(() => props.authTemplates.find((option) => option.value === form.auth_template)?.uses_background ?? true);

const colorPreview = computed(() => (isHexColor(form.primary_color) ? form.primary_color.toUpperCase() : DEFAULT_PRIMARY));
const colorReadability = computed(() => readability(colorPreview.value));
const colorInput = computed({
    get: () => colorPreview.value,
    set: (value) => { form.primary_color = String(value).toUpperCase(); },
});

const currencyFormat = computed(() => ({
    label: form.currency_label,
    position: form.currency_position,
    decimals: Number(form.currency_decimals) === 2 ? 2 : 0,
}));
const moneyExamples = computed(() => [12500, 4500.5, 150000].map((value) => formatMoney(value, 'MGA', currencyFormat.value)));

const agesValid = computed(() => Number(form.child_max_age) > Number(form.baby_max_age));
const bandsPreview = computed(() => ({ baby_max_age: Number(form.baby_max_age), child_max_age: Number(form.child_max_age) }));
/** La frise va jusqu'à cinq ans après la fin de l'enfance. */
const ageScale = computed(() => Math.max(20, Number(form.child_max_age) + 5));
const segment = (from, to) => `${((to - from) / ageScale.value) * 100}%`;

/** Ce que le site servira : les mêmes lignes que `AppSettings::robotsTxt()`. */
const robotsPreview = computed(() => (form.search_engines_hidden
    ? '# Application privée : aucune page à explorer ni à indexer (ADR-184).\nUser-agent: *\nDisallow: /'
    : 'User-agent: *\nDisallow:'));
const ROBOTS_DIRECTIVES = 'noindex, nofollow, noarchive, nosnippet, noimageindex';
const visibilityMeasures = computed(() => [
    { label: 'robots.txt', detail: form.search_engines_hidden ? 'Refuse toute exploration.' : 'Autorise l’exploration.' },
    { label: 'Balise des pages', detail: form.search_engines_hidden ? '« noindex » dans chaque page.' : 'Aucune consigne.' },
    { label: 'En-tête HTTP', detail: form.search_engines_hidden ? 'Images, documents et API compris.' : 'Aucune consigne.' },
]);

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

const statusTone = (item) => (item.ok ? 'bg-emerald-500' : item.status === 'UNCONFIGURED' ? 'bg-muted-foreground' : 'bg-destructive');

const scrollTo = (id) => document.getElementById(`reglages-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });

const pickLabel = (value) => { form.currency_label = value; };
</script>

<template>
    <Head title="Paramètres" />

    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Super Administration</p>
                <h1 class="mt-0.5 flex items-center gap-2 font-heading text-2xl font-bold tracking-tight text-foreground">
                    <Settings2 class="h-6 w-6 text-primary" aria-hidden="true" />Paramètres de l’application
                </h1>
                <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                    Chaque site a ses propres paramètres : nom, devise, logo, couleurs, modèles d’écran, écriture de l’Ariary, âges des patients, identité légale, direction et moteurs de recherche.
                    Un site se règle par son API ; le portail se règle lui-même.
                </p>
            </div>

            <nav class="flex w-fit max-w-full flex-wrap gap-1 rounded-xl border border-border bg-card p-1 shadow-sm" aria-label="Site à régler">
                <button
                    v-for="item in targets"
                    :key="item.site.code"
                    type="button"
                    :aria-current="selectedCode === item.site.code ? 'page' : undefined"
                    :class="cn(
                        'inline-flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold transition-colors',
                        selectedCode === item.site.code ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                    )"
                    @click="selectTarget(item.site.code)"
                >
                    <component :is="item.kind === 'portal' ? Server : Building2" class="h-4 w-4" aria-hidden="true" />
                    {{ item.site.name }}
                    <span :class="cn('h-1.5 w-1.5 rounded-full', statusTone(item))" :title="item.ok ? 'Joignable' : 'Injoignable'" />
                </button>
            </nav>
        </header>

        <Card v-if="! target?.ok" class="flex min-h-56 flex-col items-center justify-center px-6 py-10 text-center">
            <span class="grid h-11 w-11 place-items-center rounded-lg bg-muted text-muted-foreground"><Server class="h-5 w-5" /></span>
            <h2 class="mt-3 text-sm font-bold text-foreground">Paramètres indisponibles pour {{ target?.site.name }}</h2>
            <p class="mt-1 max-w-lg text-xs leading-5 text-muted-foreground">{{ target?.message || 'Le site ne répond pas actuellement. Les autres sites restent disponibles.' }}</p>
        </Card>

        <div v-else class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_18rem] 2xl:grid-cols-[minmax(0,1fr)_22rem]">
            <!-- Sommaire, à droite du formulaire : placé en premier pour le clavier, affiché en dernier. -->
            <aside class="hidden lg:sticky lg:top-20 lg:order-last lg:block">
                <Card class="p-2">
                    <p class="px-2.5 pb-1.5 pt-1 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">{{ target.site.name }}</p>
                    <nav class="space-y-0.5" aria-label="Sections">
                        <button
                            v-for="section in SECTIONS"
                            :key="section.id"
                            type="button"
                            class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-start text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                            @click="scrollTo(section.id)"
                        >
                            <component :is="section.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />{{ section.label }}
                        </button>
                    </nav>
                    <p v-if="data?.updated_at" class="mt-2 border-t border-border px-2.5 pb-1 pt-2 text-[11px] leading-4 text-muted-foreground">
                        Réglé le {{ new Date(data.updated_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) }}<template v-if="data.updated_by"> par {{ data.updated_by }}</template>
                    </p>
                    <p v-else class="mt-2 border-t border-border px-2.5 pb-1 pt-2 text-[11px] leading-4 text-muted-foreground">Jamais réglé : la configuration du déploiement s’applique.</p>
                </Card>
            </aside>

            <form class="min-w-0 space-y-5" novalidate @submit.prevent="submit">
                <p v-if="readonly" class="flex items-start gap-2 rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                    <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />Lecture seule : modifier les paramètres demande le droit « settings.update ».
                </p>

                <!-- Identité -->
                <Card id="reglages-identite" class="scroll-mt-24 overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-border px-5 py-4">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true"><Globe class="h-5 w-5" /></span>
                        <div>
                            <h2 class="text-base font-bold text-foreground">Identité</h2>
                            <p class="mt-0.5 text-sm text-muted-foreground">Le nom de l’application, sa devise, son logo et son icône sur {{ target.site.name }}.</p>
                        </div>
                    </div>
                    <div class="space-y-6 px-5 py-5">
                        <div class="grid gap-5 md:grid-cols-[minmax(0,1fr)_17rem] md:items-start">
                            <div class="space-y-5">
                                <div>
                                    <FormField label="Nom de l’application" :error="form.errors.app_name">
                                        <IconInput v-model="form.app_name" :icon="Globe" :placeholder="fallbacks.app_name || 'Clinique Saint Georges'" :disabled="readonly" maxlength="80" />
                                    </FormField>
                                    <p class="mt-1.5 text-xs text-muted-foreground">Titre des onglets, barre latérale, page de connexion et documents. Vide : « {{ fallbacks.app_name }} ».</p>
                                </div>
                                <div>
                                    <FormField label="Devise ou slogan" :error="form.errors.app_tagline">
                                        <IconInput v-model="form.app_tagline" :icon="Quote" :placeholder="fallbacks.app_tagline || 'Ex. Ny fahasalamana no loharanon-karena'" :disabled="readonly" maxlength="150" />
                                    </FormField>
                                    <p class="mt-1.5 flex flex-wrap justify-between gap-x-3 text-xs text-muted-foreground">
                                        <span>Affichée sous le nom, sur la page de connexion.<template v-if="fallbacks.app_tagline"> Vide : « {{ fallbacks.app_tagline }} ».</template></span>
                                        <span class="tabular-nums">{{ String(form.app_tagline ?? '').length }} / 150</span>
                                    </p>
                                </div>
                            </div>
                            <div class="space-y-3 rounded-xl border border-border bg-muted/30 p-3" aria-label="Aperçu de la barre latérale et de la page de connexion">
                                <div>
                                    <p class="mb-2 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Aperçu · barre latérale</p>
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <img v-if="iconPreview" :src="iconPreview" alt="" class="h-8 w-8 shrink-0 rounded-lg bg-card object-contain ring-1 ring-border" />
                                        <span v-else class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-[10px] font-bold uppercase text-white" :style="{ backgroundColor: colorPreview }">{{ monogramOf(brandPreview) }}</span>
                                        <span class="flex min-w-0 flex-col">
                                            <span class="truncate text-[11px] font-bold uppercase tracking-[0.06em] text-foreground">{{ brandPreview }}</span>
                                            <span class="mt-1 truncate text-[9px] font-bold uppercase tracking-[0.16em] text-muted-foreground">{{ isPortal ? 'Super Administration' : target.site.name }}</span>
                                        </span>
                                    </div>
                                </div>
                                <div class="border-t border-border pt-3">
                                    <p class="mb-2 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Aperçu · page de connexion</p>
                                    <div class="rounded-lg bg-primary-950 px-3 py-2.5 text-white">
                                        <p class="truncate text-[9px] font-bold uppercase tracking-[0.16em] text-white/70">{{ brandPreview }} · {{ isPortal ? 'Super Administration' : target.site.name }}</p>
                                        <p v-if="taglinePreview" class="mt-1 font-heading text-sm font-semibold leading-snug">{{ taglinePreview }}</p>
                                        <p v-else class="mt-1 text-xs italic text-white/60">Aucune devise affichée.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-6 border-t border-border pt-5 2xl:grid-cols-2">
                            <SettingsAssetField
                                kind="logo"
                                label="Logo"
                                description="Page de connexion, factures, reçus et documents imprimés. Un logo horizontal sur fond clair se lit le mieux."
                                :asset="data.assets?.logo"
                                :site-code="selectedCode"
                                :max-kb="limits.asset_max_kb?.logo ?? 1024"
                                :mimes="limits.asset_mimes?.logo ?? 'png,jpg,jpeg,webp'"
                                :readonly="readonly"
                                shape="wide"
                                @saved="afterSave"
                            />
                            <SettingsAssetField
                                kind="icon"
                                label="Icône (favicon)"
                                description="Onglet du navigateur et pastille de la barre latérale. Une image carrée, lisible en tout petit. Sans icône, la pastille garde les initiales."
                                :asset="data.assets?.icon"
                                :site-code="selectedCode"
                                :max-kb="limits.asset_max_kb?.icon ?? 512"
                                :mimes="limits.asset_mimes?.icon ?? 'png,ico,webp'"
                                :readonly="readonly"
                                shape="square"
                                @saved="afterSave"
                            />
                        </div>
                    </div>
                </Card>

                <!-- Couleurs -->
                <Card id="reglages-couleurs" class="scroll-mt-24 overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-border px-5 py-4">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true"><Palette class="h-5 w-5" /></span>
                        <div>
                            <h2 class="text-base font-bold text-foreground">Couleurs</h2>
                            <p class="mt-0.5 text-sm text-muted-foreground">La couleur principale : boutons, liens, sélection. Elle est déclinée automatiquement pour le thème sombre ; les couleurs d’alerte (rouge, ambre, vert) ne changent pas.</p>
                        </div>
                    </div>
                    <div class="grid gap-6 px-5 py-5 2xl:grid-cols-[minmax(0,1fr)_18rem]">
                        <div class="space-y-4">
                            <div class="flex flex-wrap items-end gap-3">
                                <FormField as="div" label="Couleur principale" :error="form.errors.primary_color">
                                    <div class="flex items-center gap-2">
                                        <label class="relative h-10 w-12 shrink-0 cursor-pointer overflow-hidden rounded-lg border border-input shadow-sm" :style="{ backgroundColor: colorPreview }">
                                            <span class="sr-only">Choisir la couleur</span>
                                            <input v-model="colorInput" type="color" class="absolute inset-0 h-full w-full cursor-pointer opacity-0" :disabled="readonly" />
                                        </label>
                                        <Input v-model="form.primary_color" class="w-32 font-mono uppercase" :placeholder="DEFAULT_PRIMARY" maxlength="7" :disabled="readonly" aria-label="Code de la couleur" />
                                    </div>
                                </FormField>
                                <Button v-if="form.primary_color && ! readonly" type="button" variant="ghost" size="sm" @click="form.primary_color = ''">
                                    <RotateCcw class="h-4 w-4" />Couleur d’origine
                                </Button>
                            </div>

                            <div>
                                <p class="mb-2 text-xs font-semibold text-muted-foreground">Teintes proposées</p>
                                <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Teintes proposées">
                                    <button
                                        v-for="preset in PRIMARY_PRESETS"
                                        :key="preset.value"
                                        type="button"
                                        role="radio"
                                        :aria-checked="colorPreview === preset.value"
                                        :title="preset.label"
                                        :aria-label="preset.label"
                                        :disabled="readonly"
                                        :class="cn('grid h-9 w-9 place-items-center rounded-full ring-offset-2 ring-offset-card transition-transform hover:scale-105 disabled:cursor-not-allowed disabled:hover:scale-100', colorPreview === preset.value ? 'ring-2 ring-foreground' : '')"
                                        :style="{ backgroundColor: preset.value }"
                                        @click="form.primary_color = preset.value"
                                    >
                                        <Check v-if="colorPreview === preset.value" class="h-4 w-4 text-white" :stroke-width="3" />
                                    </button>
                                </div>
                            </div>

                            <p
                                v-if="colorReadability"
                                :class="cn(
                                    'flex items-start gap-2 rounded-lg border px-3 py-2 text-xs leading-5',
                                    colorReadability.level === 'good'
                                        ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200'
                                        : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200',
                                )"
                            >
                                <Check v-if="colorReadability.level === 'good'" class="mt-0.5 h-4 w-4 shrink-0" />
                                <TriangleAlert v-else class="mt-0.5 h-4 w-4 shrink-0" />
                                <span v-if="colorReadability.level === 'good'">Contraste du texte blanc : {{ colorReadability.label }} — lisible sur les boutons.</span>
                                <span v-else>Contraste du texte blanc : {{ colorReadability.label }} — trop clair pour un texte blanc. Le texte des boutons passera en foncé ; une teinte plus sombre reste plus lisible.</span>
                            </p>
                        </div>

                        <div class="rounded-xl border border-border bg-background p-4" aria-label="Aperçu des couleurs">
                            <p class="mb-3 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Aperçu</p>
                            <div class="space-y-3">
                                <span
                                    class="inline-flex h-9 items-center gap-2 rounded-lg px-4 text-sm font-semibold shadow-sm"
                                    :style="{ backgroundColor: colorPreview, color: colorReadability?.level === 'poor' ? '#0F1A2A' : '#FFFFFF' }"
                                ><Save class="h-4 w-4" />Enregistrer</span>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :style="{ backgroundColor: `${colorPreview}1A`, color: colorPreview }">Pastille</span>
                                    <span class="text-sm font-semibold underline underline-offset-4" :style="{ color: colorPreview }">Un lien</span>
                                </div>
                                <div class="flex items-center gap-2 rounded-lg border-2 px-3 py-2 text-sm text-foreground" :style="{ borderColor: colorPreview }">
                                    <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: colorPreview }" />Élément sélectionné
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>

                <!-- Écrans & modèles -->
                <Card id="reglages-ecrans" class="scroll-mt-24 overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-border px-5 py-4">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true"><LayoutTemplate class="h-5 w-5" /></span>
                        <div>
                            <h2 class="text-base font-bold text-foreground">Écrans & modèles</h2>
                            <p class="mt-0.5 text-sm text-muted-foreground">La disposition des pages de connexion et de « Mon profil » sur {{ target.site.name }}. Seule la présentation change : formulaires, droits et règles restent les mêmes.</p>
                        </div>
                    </div>
                    <div class="space-y-6 px-5 py-5">
                        <div>
                            <p class="text-sm font-semibold text-foreground">Pages d’authentification</p>
                            <p class="mt-0.5 text-xs text-muted-foreground">Connexion, mot de passe oublié, réinitialisation et activation de compte suivent le même modèle.</p>
                            <div class="mt-3 grid gap-3 sm:grid-cols-3" role="radiogroup" aria-label="Modèle des pages d’authentification">
                                <button
                                    v-for="option in authTemplates"
                                    :key="option.value"
                                    type="button"
                                    role="radio"
                                    :aria-checked="form.auth_template === option.value"
                                    :disabled="readonly"
                                    :class="cn(
                                        'group flex flex-col overflow-hidden rounded-xl border text-start transition-colors disabled:cursor-not-allowed',
                                        form.auth_template === option.value ? 'border-primary ring-2 ring-primary/20' : 'border-border hover:border-primary/40',
                                    )"
                                    @click="form.auth_template = option.value"
                                >
                                    <!-- Aperçu schématique du modèle, avec l'image et la couleur réglées. -->
                                    <span class="relative block aspect-[16/10] w-full overflow-hidden bg-muted" aria-hidden="true">
                                        <template v-if="option.value === 'COVER'">
                                            <img v-if="backgroundPreview" :src="backgroundPreview" alt="" class="absolute inset-0 h-full w-full object-cover" />
                                            <span class="absolute inset-0 bg-slate-950/35" />
                                            <span class="absolute bottom-[12%] end-[8%] top-[12%] w-[38%] rounded-md bg-card p-1.5 shadow-lg">
                                                <span class="block h-1.5 w-1/2 rounded-full" :style="{ backgroundColor: colorPreview }" />
                                                <span class="mt-1.5 block h-2 rounded bg-muted" /><span class="mt-1 block h-2 rounded bg-muted" />
                                                <span class="mt-1.5 block h-2.5 rounded" :style="{ backgroundColor: colorPreview }" />
                                            </span>
                                        </template>
                                        <template v-else-if="option.value === 'SPLIT'">
                                            <span class="absolute inset-y-0 start-0 w-[45%] bg-card p-2">
                                                <span class="mt-[18%] block h-1.5 w-1/2 rounded-full" :style="{ backgroundColor: colorPreview }" />
                                                <span class="mt-1.5 block h-2 rounded bg-muted" /><span class="mt-1 block h-2 rounded bg-muted" />
                                                <span class="mt-1.5 block h-2.5 rounded" :style="{ backgroundColor: colorPreview }" />
                                            </span>
                                            <span class="absolute inset-y-0 end-0 w-[55%]" :style="{ backgroundColor: colorPreview }">
                                                <img v-if="backgroundPreview" :src="backgroundPreview" alt="" class="h-full w-full object-cover" />
                                            </span>
                                        </template>
                                        <template v-else>
                                            <span class="absolute inset-x-0 top-0 h-[42%]" :style="{ backgroundColor: colorPreview }" />
                                            <span class="absolute inset-x-[27%] bottom-[10%] top-[22%] rounded-md border border-border bg-card p-1.5 shadow">
                                                <span class="block h-1.5 w-1/2 rounded-full" :style="{ backgroundColor: colorPreview }" />
                                                <span class="mt-1.5 block h-2 rounded bg-muted" /><span class="mt-1 block h-2 rounded bg-muted" />
                                                <span class="mt-1.5 block h-2.5 rounded" :style="{ backgroundColor: colorPreview }" />
                                            </span>
                                        </template>
                                    </span>
                                    <span class="flex items-start gap-2 px-3 py-2.5">
                                        <span :class="cn('mt-0.5 grid h-4 w-4 shrink-0 place-items-center rounded-full border', form.auth_template === option.value ? 'border-primary bg-primary text-primary-foreground' : 'border-input')">
                                            <Check v-if="form.auth_template === option.value" class="h-3 w-3" :stroke-width="3" />
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block text-sm font-semibold text-foreground">{{ option.label }}</span>
                                            <span class="block text-xs leading-4 text-muted-foreground">{{ AUTH_TEMPLATE_HINTS[option.value] }}</span>
                                        </span>
                                    </span>
                                </button>
                            </div>
                        </div>

                        <div class="border-t border-border pt-5">
                            <SettingsAssetField
                                kind="background"
                                label="Image de fond"
                                description="Pages de connexion des modèles Couverture et Partagé. Une photo en paysage, d’au moins 1600 px de large."
                                :asset="data.assets?.background"
                                :site-code="selectedCode"
                                :max-kb="limits.asset_max_kb?.background ?? 2048"
                                :mimes="limits.asset_mimes?.background ?? 'jpg,jpeg,png,webp'"
                                :readonly="readonly"
                                shape="cover"
                                :fallback-url="fallbacks.auth_background_url || ''"
                                fallback-label="Image par défaut"
                                @saved="afterSave"
                            />
                            <p v-if="! authUsesBackground" class="mt-3 text-xs text-muted-foreground">Le modèle Centré n’affiche pas d’image : celle-ci servira si vous changez de modèle.</p>
                        </div>

                        <div class="border-t border-border pt-5">
                            <p class="text-sm font-semibold text-foreground">Page « Mon profil »</p>
                            <p class="mt-0.5 text-xs text-muted-foreground">Chaque utilisateur y lit son compte et ses droits, et y change son mot de passe.</p>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3" role="radiogroup" aria-label="Modèle de la page Mon profil">
                                <button
                                    v-for="option in profileTemplates"
                                    :key="option.value"
                                    type="button"
                                    role="radio"
                                    :aria-checked="form.profile_template === option.value"
                                    :disabled="readonly"
                                    :class="cn(
                                        'flex flex-col overflow-hidden rounded-xl border text-start transition-colors disabled:cursor-not-allowed',
                                        form.profile_template === option.value ? 'border-primary ring-2 ring-primary/20' : 'border-border hover:border-primary/40',
                                    )"
                                    @click="form.profile_template = option.value"
                                >
                                    <span class="relative block aspect-[16/10] w-full overflow-hidden bg-muted p-2" aria-hidden="true">
                                        <template v-if="option.value === 'SIDEBAR'">
                                            <span class="absolute inset-y-2 start-2 w-[30%] rounded-md bg-card p-1.5">
                                                <span class="mx-auto block h-4 w-4 rounded-full" :style="{ backgroundColor: colorPreview }" />
                                                <span class="mx-auto mt-1 block h-1.5 w-3/4 rounded bg-muted" />
                                                <span class="mt-2 block h-1.5 rounded" :style="{ backgroundColor: `${colorPreview}33` }" /><span class="mt-1 block h-1.5 rounded bg-muted" /><span class="mt-1 block h-1.5 rounded bg-muted" />
                                            </span>
                                            <span class="absolute inset-y-2 end-2 start-[36%] rounded-md bg-card p-1.5">
                                                <span class="block h-1.5 w-1/3 rounded bg-muted" /><span class="mt-2 block h-6 rounded bg-muted/70" /><span class="mt-1 block h-6 rounded bg-muted/70" />
                                            </span>
                                        </template>
                                        <template v-else>
                                            <span class="absolute inset-x-2 top-2 h-[46%] overflow-hidden rounded-md bg-card">
                                                <span class="block h-[45%]" :style="{ backgroundColor: colorPreview }" />
                                                <span class="absolute start-2 top-[30%] block h-5 w-5 rounded-full border-2 border-card" :style="{ backgroundColor: colorPreview }" />
                                                <span class="absolute bottom-1.5 start-2 flex gap-1"><span class="block h-1.5 w-6 rounded" :style="{ backgroundColor: colorPreview }" /><span class="block h-1.5 w-6 rounded bg-muted" /><span class="block h-1.5 w-6 rounded bg-muted" /></span>
                                            </span>
                                            <span class="absolute inset-x-2 bottom-2 top-[54%] rounded-md bg-card p-1.5">
                                                <span class="block h-1.5 w-1/3 rounded bg-muted" /><span class="mt-1.5 block h-4 rounded bg-muted/70" />
                                            </span>
                                        </template>
                                    </span>
                                    <span class="flex items-start gap-2 px-3 py-2.5">
                                        <span :class="cn('mt-0.5 grid h-4 w-4 shrink-0 place-items-center rounded-full border', form.profile_template === option.value ? 'border-primary bg-primary text-primary-foreground' : 'border-input')">
                                            <Check v-if="form.profile_template === option.value" class="h-3 w-3" :stroke-width="3" />
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block text-sm font-semibold text-foreground">{{ option.label }}</span>
                                            <span class="block text-xs leading-4 text-muted-foreground">{{ PROFILE_TEMPLATE_HINTS[option.value] }}</span>
                                        </span>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                </Card>

                <!-- Monnaie -->
                <Card id="reglages-monnaie" class="scroll-mt-24 overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-border px-5 py-4">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true"><Coins class="h-5 w-5" /></span>
                        <div>
                            <h2 class="text-base font-bold text-foreground">Monnaie</h2>
                            <p class="mt-0.5 text-sm text-muted-foreground">Comment l’Ariary s’écrit sur les écrans et les documents. Seule l’écriture change : aucun montant n’est converti.</p>
                        </div>
                    </div>
                    <div class="grid gap-6 px-5 py-5 2xl:grid-cols-[minmax(0,1fr)_18rem]">
                        <div class="flex flex-wrap content-start gap-x-6 gap-y-4">
                            <FormField as="div" label="Unité" :error="form.errors.currency_label">
                                <div class="inline-flex rounded-lg border border-input bg-card p-1 shadow-sm" role="radiogroup" aria-label="Unité">
                                    <button
                                        v-for="label in currencyLabels"
                                        :key="label"
                                        type="button"
                                        role="radio"
                                        :aria-checked="form.currency_label === label"
                                        :disabled="readonly"
                                        :class="cn('whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-semibold transition-colors', form.currency_label === label ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')"
                                        @click="pickLabel(label)"
                                    >{{ label }}</button>
                                </div>
                            </FormField>
                            <FormField as="div" label="Position" :error="form.errors.currency_position">
                                <div class="inline-flex rounded-lg border border-input bg-card p-1 shadow-sm" role="radiogroup" aria-label="Position de l’unité">
                                    <button
                                        v-for="option in [{ value: 'after', label: 'Après' }, { value: 'before', label: 'Avant' }]"
                                        :key="option.value"
                                        type="button"
                                        role="radio"
                                        :aria-checked="form.currency_position === option.value"
                                        :disabled="readonly"
                                        :class="cn('whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-semibold transition-colors', form.currency_position === option.value ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')"
                                        @click="form.currency_position = option.value"
                                    >{{ option.label }}</button>
                                </div>
                            </FormField>
                            <FormField as="div" label="Décimales" :error="form.errors.currency_decimals">
                                <div class="inline-flex rounded-lg border border-input bg-card p-1 shadow-sm" role="radiogroup" aria-label="Décimales">
                                    <button
                                        v-for="option in [{ value: 0, label: 'Si besoin' }, { value: 2, label: 'Toujours 2' }]"
                                        :key="option.value"
                                        type="button"
                                        role="radio"
                                        :aria-checked="Number(form.currency_decimals) === option.value"
                                        :disabled="readonly"
                                        :class="cn('whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-semibold transition-colors', Number(form.currency_decimals) === option.value ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')"
                                        @click="form.currency_decimals = option.value"
                                    >{{ option.label }}</button>
                                </div>
                            </FormField>
                        </div>
                        <div class="rounded-xl border border-border bg-muted/30 p-4" aria-label="Aperçu des montants">
                            <p class="mb-2 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Aperçu</p>
                            <ul class="space-y-1.5">
                                <li v-for="example in moneyExamples" :key="example" class="flex items-center gap-2 font-mono text-sm tabular-nums text-foreground">
                                    <Banknote class="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />{{ example }}
                                </li>
                            </ul>
                            <p class="mt-2 text-[11px] leading-4 text-muted-foreground">« Si besoin » n’arrondit jamais : un prix de 4 500,50 garde ses centimes.</p>
                        </div>
                    </div>
                </Card>

                <!-- Âges des patients -->
                <Card id="reglages-ages" class="scroll-mt-24 overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-border px-5 py-4">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true"><Baby class="h-5 w-5" /></span>
                        <div>
                            <h2 class="text-base font-bold text-foreground">Âges des patients</h2>
                            <p class="mt-0.5 text-sm text-muted-foreground">
                                Les tranches qui guident le formulaire d’un nouveau patient : un bébé ou un enfant reçoit le profil enfant (civilité « Enfant », contact du parent, sans champs d’adulte), un bébé se déclare avec sa date de naissance exacte.
                            </p>
                        </div>
                    </div>
                    <div class="space-y-5 px-5 py-5">
                        <div class="grid gap-5 sm:grid-cols-3">
                            <FormField label="Bébé jusqu’à" :error="form.errors.baby_max_age">
                                <div class="relative">
                                    <Input v-model.number="form.baby_max_age" type="number" min="0" :max="limits.baby_max_age ?? 5" class="pe-12" :disabled="readonly" />
                                    <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-xs text-muted-foreground">an(s)</span>
                                </div>
                            </FormField>
                            <FormField label="Enfant jusqu’à" :error="form.errors.child_max_age || (! agesValid ? 'Doit dépasser l’âge d’un bébé.' : '')">
                                <div class="relative">
                                    <Input v-model.number="form.child_max_age" type="number" :min="Number(form.baby_max_age) + 1" :max="limits.child_max_age ?? 20" class="pe-12" :disabled="readonly" />
                                    <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-xs text-muted-foreground">ans</span>
                                </div>
                            </FormField>
                            <div class="flex flex-col justify-end">
                                <p class="text-sm font-medium text-foreground">Adulte</p>
                                <p class="mt-1.5 flex h-10 items-center rounded-lg border border-dashed border-border px-3 text-sm text-muted-foreground">à partir de {{ Number(form.child_max_age) + 1 }} ans</p>
                            </div>
                        </div>

                        <div v-if="agesValid" aria-label="Tranches d’âge">
                            <div class="flex h-9 overflow-hidden rounded-lg text-[11px] font-semibold">
                                <span class="flex items-center justify-center gap-1 bg-primary/20 px-1 text-primary" :style="{ width: segment(0, Number(form.baby_max_age) + 1) }"><Baby class="h-3.5 w-3.5 shrink-0" /><span class="truncate">Bébé</span></span>
                                <span class="flex items-center justify-center gap-1 bg-primary/10 px-1 text-primary" :style="{ width: segment(Number(form.baby_max_age) + 1, Number(form.child_max_age) + 1) }"><Users class="h-3.5 w-3.5 shrink-0" /><span class="truncate">Enfant</span></span>
                                <span class="flex flex-1 items-center justify-center gap-1 bg-muted px-1 text-muted-foreground"><UserRound class="h-3.5 w-3.5 shrink-0" /><span class="truncate">Adulte</span></span>
                            </div>
                            <p class="mt-2 text-xs text-muted-foreground">{{ describeAgeBands(bandsPreview) }}</p>
                            <ul class="mt-3 grid gap-2 text-xs text-muted-foreground sm:grid-cols-3">
                                <li class="rounded-lg border border-border px-3 py-2"><strong class="block text-foreground">Bébé · {{ bandRange('BABY', bandsPreview) }}</strong>Date de naissance exacte obligatoire ; civilité « Enfant ».</li>
                                <li class="rounded-lg border border-border px-3 py-2"><strong class="block text-foreground">Enfant · {{ bandRange('CHILD', bandsPreview) }}</strong>Civilité « Enfant » ; téléphone, email, profession et pièce d’identité non demandés.</li>
                                <li class="rounded-lg border border-border px-3 py-2"><strong class="block text-foreground">Adulte · {{ bandRange('ADULT', bandsPreview) }}</strong>Civilité M. ou Mme ; formulaire complet.</li>
                            </ul>
                        </div>
                        <p class="text-[11px] text-muted-foreground">Seuls les nouveaux patients suivent ces tranches : aucun dossier existant n’est modifié.</p>
                    </div>
                </Card>

                <!-- Identité légale -->
                <Card id="reglages-legal" class="scroll-mt-24 overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-border px-5 py-4">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true"><Landmark class="h-5 w-5" /></span>
                        <div>
                            <h2 class="text-base font-bold text-foreground">Identité légale</h2>
                            <p class="mt-0.5 text-sm text-muted-foreground">Imprimée sur les factures, reçus et documents de {{ target.site.name }}. Un champ vide garde la valeur de la configuration du site, indiquée en gris.</p>
                        </div>
                    </div>
                    <div class="grid gap-5 px-5 py-5 md:grid-cols-2">
                        <FormField label="NIF" :error="form.errors.legal_nif">
                            <IconInput v-model="form.legal_nif" :icon="Landmark" :placeholder="fallbacks.legal_nif || 'Numéro d’identification fiscale'" :disabled="readonly" maxlength="40" />
                        </FormField>
                        <FormField label="STAT" :error="form.errors.legal_stat">
                            <IconInput v-model="form.legal_stat" :icon="Landmark" :placeholder="fallbacks.legal_stat || 'Numéro statistique'" :disabled="readonly" maxlength="40" />
                        </FormField>
                        <FormField label="Adresse" :error="form.errors.legal_address" class="md:col-span-2">
                            <IconInput v-model="form.legal_address" :icon="MapPin" :placeholder="fallbacks.legal_address || 'Adresse de l’établissement'" :disabled="readonly" maxlength="255" />
                        </FormField>
                        <FormField label="Téléphone" :error="form.errors.legal_phone">
                            <IconInput v-model="form.legal_phone" :icon="Phone" type="tel" :placeholder="fallbacks.legal_phone || 'Ex. 020 00 000 00'" :disabled="readonly" maxlength="40" />
                        </FormField>
                        <FormField label="Email" :error="form.errors.legal_email">
                            <IconInput v-model="form.legal_email" :icon="Mail" type="email" :placeholder="fallbacks.legal_email || 'contact@exemple.mg'" :disabled="readonly" maxlength="150" />
                        </FormField>
                        <FormField label="Banque" :error="form.errors.bank_name">
                            <IconInput v-model="form.bank_name" :icon="Building2" placeholder="Nom de la banque" :disabled="readonly" maxlength="150" />
                        </FormField>
                        <FormField label="N° de compte bancaire" :error="form.errors.bank_account">
                            <IconInput v-model="form.bank_account" :icon="Banknote" class="font-mono" placeholder="Numéro de compte ou RIB" :disabled="readonly" maxlength="60" />
                        </FormField>
                        <p class="text-[11px] text-muted-foreground md:col-span-2">Le compte bancaire s’imprime sur les factures dès qu’il est renseigné.</p>
                    </div>
                </Card>

                <!-- Direction -->
                <Card id="reglages-direction" class="scroll-mt-24 overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-border px-5 py-4">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true"><PenLine class="h-5 w-5" /></span>
                        <div>
                            <h2 class="text-base font-bold text-foreground">Direction</h2>
                            <p class="mt-0.5 text-sm text-muted-foreground">Le directeur général signe les documents administratifs des RH (attestations, contrats…). La signature est copiée dans chaque document au moment où il est produit.</p>
                        </div>
                    </div>
                    <div class="space-y-6 px-5 py-5">
                        <div class="grid gap-5 md:grid-cols-2">
                            <FormField label="Nom du directeur général" :error="form.errors.director_name">
                                <IconInput v-model="form.director_name" :icon="UserRound" placeholder="Prénom et nom" :disabled="readonly" maxlength="150" />
                            </FormField>
                            <FormField label="Titre" :error="form.errors.director_title">
                                <IconInput v-model="form.director_title" :icon="PenLine" :placeholder="fallbacks.director_title || 'Directeur général'" :disabled="readonly" maxlength="150" />
                            </FormField>
                        </div>
                        <div class="border-t border-border pt-5">
                            <SettingsAssetField
                                kind="signature"
                                label="Signature"
                                description="Une image PNG à fond transparent rend le mieux. Elle n’a aucune adresse publique ; la remplacer ne change aucun document déjà produit."
                                :asset="data.assets?.signature"
                                :site-code="selectedCode"
                                :max-kb="limits.asset_max_kb?.signature ?? 512"
                                :mimes="limits.asset_mimes?.signature ?? 'png,jpg,jpeg,webp'"
                                :readonly="readonly"
                                shape="paper"
                            />
                        </div>
                    </div>
                </Card>

                <!-- Moteurs de recherche -->
                <Card id="reglages-visibilite" class="scroll-mt-24 overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-border px-5 py-4">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true"><SearchX class="h-5 w-5" /></span>
                        <div>
                            <h2 class="text-base font-bold text-foreground">Moteurs de recherche</h2>
                            <p class="mt-0.5 text-sm text-muted-foreground">Ce que Google, Bing et les autres moteurs peuvent voir de {{ target.site.name }}. Les écrans restent de toute façon protégés par la connexion.</p>
                        </div>
                    </div>
                    <div class="grid gap-6 px-5 py-5 2xl:grid-cols-[minmax(0,1fr)_18rem]">
                        <div class="space-y-4">
                            <label
                                for="search-engines-hidden"
                                :class="cn(
                                    'flex items-start gap-3 rounded-xl border p-4 transition-colors',
                                    form.search_engines_hidden ? 'border-primary/40 bg-primary/5' : 'border-border',
                                    readonly ? 'cursor-not-allowed' : 'cursor-pointer hover:bg-accent/40',
                                )"
                            >
                                <Checkbox id="search-engines-hidden" v-model="form.search_engines_hidden" :disabled="readonly" class="mt-0.5 h-5 w-5" />
                                <span class="min-w-0">
                                    <span class="flex flex-wrap items-center gap-2 text-sm font-semibold text-foreground">
                                        Masquer l’application des moteurs de recherche
                                        <Badge :variant="form.search_engines_hidden ? 'success' : 'warning'">{{ form.search_engines_hidden ? 'Masquée' : 'Visible' }}</Badge>
                                    </span>
                                    <span class="mt-1 block text-sm text-muted-foreground">
                                        Cochée, aucune page, image ni document n’est exploré ni indexé : robots.txt refuse tout, et chaque réponse porte la consigne « noindex ».
                                    </span>
                                </span>
                            </label>

                            <p
                                v-if="! form.search_engines_hidden"
                                class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
                            >
                                <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                                La page de connexion de {{ target.site.name }} pourra apparaître dans les résultats de recherche. Rien d’autre n’est visible sans compte.
                            </p>

                            <ul class="grid gap-2 text-xs sm:grid-cols-3">
                                <li v-for="measure in visibilityMeasures" :key="measure.label" class="flex items-start gap-2 rounded-lg border border-border px-3 py-2">
                                    <Check v-if="form.search_engines_hidden" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" aria-hidden="true" />
                                    <span v-else class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-muted-foreground" aria-hidden="true" />
                                    <span><strong class="block text-foreground">{{ measure.label }}</strong><span class="text-muted-foreground">{{ measure.detail }}</span></span>
                                </li>
                            </ul>

                            <p class="text-[11px] leading-4 text-muted-foreground">
                                Une page déjà référencée peut rester visible quelque temps : son retrait se demande dans l’outil du moteur (Google Search Console, Bing Webmaster Tools).
                            </p>
                        </div>

                        <div class="self-start rounded-xl border border-border bg-muted/30 p-4" aria-label="Aperçu de robots.txt">
                            <p class="mb-2 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wide text-muted-foreground"><FileText class="h-3.5 w-3.5" aria-hidden="true" />Aperçu · robots.txt</p>
                            <pre class="whitespace-pre-wrap break-words rounded-lg border border-border bg-card px-3 py-2 font-mono text-[11px] leading-5 text-foreground">{{ robotsPreview }}</pre>
                            <p v-if="form.search_engines_hidden" class="mt-2 text-[11px] leading-4 text-muted-foreground">
                                Et sur chaque réponse : <code class="break-words font-mono text-foreground">X-Robots-Tag: {{ ROBOTS_DIRECTIVES }}</code>
                            </p>
                        </div>
                    </div>
                </Card>

                <!-- Barre d'enregistrement : ce qui a changé, et le geste qui l'envoie. -->
                <footer
                    v-if="! readonly"
                    :class="cn(
                        'z-20 flex flex-col gap-3 rounded-xl border px-4 py-3 shadow-lg backdrop-blur sm:sticky sm:bottom-3 sm:flex-row sm:items-center sm:justify-between',
                        form.isDirty ? 'border-amber-300 bg-amber-50/95 dark:border-amber-900 dark:bg-amber-950/80' : 'border-border bg-card/95',
                    )"
                >
                    <div class="min-w-0 space-y-1.5">
                        <p class="flex items-center gap-2 text-sm" aria-live="polite">
                            <template v-if="form.isDirty">
                                <span class="grid h-6 min-w-6 place-items-center rounded-full bg-amber-500 px-1.5 text-xs font-bold text-white">{{ changedCount }}</span>
                                <span class="font-semibold text-foreground">modification{{ changedCount > 1 ? 's' : '' }} non enregistrée{{ changedCount > 1 ? 's' : '' }}</span>
                                <span class="hidden text-muted-foreground md:inline">· {{ target.site.name }}</span>
                            </template>
                            <template v-else>
                                <Check class="h-4 w-4 text-emerald-600" /><span class="text-muted-foreground">Paramètres à jour</span>
                                <span class="hidden text-muted-foreground md:inline">· {{ target.site.name }}</span>
                            </template>
                        </p>
                        <!-- Un refus qui ne porte sur aucun champ (site injoignable, base non migrée…) : dit ici, jamais tu. -->
                        <p v-if="form.errors.site_code" class="flex items-start gap-2 text-xs leading-5 text-destructive" role="alert">
                            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />{{ form.errors.site_code }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button v-if="form.isDirty" type="button" variant="ghost" :disabled="form.processing" @click="loadTarget"><Undo2 class="h-4 w-4" />Annuler</Button>
                        <Button type="submit" variant="primary" :disabled="! form.isDirty || form.processing || ! agesValid">
                            <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" /><Save v-else class="h-4 w-4" />
                            {{ form.processing ? 'Enregistrement…' : 'Enregistrer les paramètres' }}
                        </Button>
                    </div>
                </footer>
            </form>
        </div>

        <ConfirmModal
            :open="pendingTarget !== null"
            title="Abandonner vos modifications ?"
            :description="`${changedCount} modification${changedCount > 1 ? 's' : ''} de ${target?.site.name} n’${changedCount > 1 ? 'ont' : 'a'} pas été enregistrée${changedCount > 1 ? 's' : ''}.`"
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
