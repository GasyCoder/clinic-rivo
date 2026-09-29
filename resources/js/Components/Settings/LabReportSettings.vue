<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    ALargeSmall, CalendarClock, Columns3, ExternalLink, FileText, FlaskConical, Globe, Hash, Image, Landmark, LayoutTemplate,
    Loader2, MapPin, PenLine, QrCode, Rows3, Send, ShieldCheck, Signature, TriangleAlert, Type, UserRound,
} from 'lucide-vue-next';
import ColorField from '@/Components/Settings/ColorField.vue';
import SegmentedField from '@/Components/Settings/SegmentedField.vue';
import SettingsAssetField from '@/Components/Settings/SettingsAssetField.vue';
import SettingsField from '@/Components/Settings/SettingsField.vue';
import SettingsSection from '@/Components/Settings/SettingsSection.vue';
import Button from '@/Components/Shadcn/Button.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Label from '@/Components/Shadcn/Label.vue';
import RadioGroup from '@/Components/Shadcn/RadioGroup.vue';
import RadioGroupItem from '@/Components/Shadcn/RadioGroupItem.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Switch from '@/Components/Shadcn/Switch.vue';
import Tabs from '@/Components/Shadcn/Tabs.vue';
import TabsContent from '@/Components/Shadcn/TabsContent.vue';
import TabsList from '@/Components/Shadcn/TabsList.vue';
import TabsTrigger from '@/Components/Shadcn/TabsTrigger.vue';
import { cn } from '@/lib/cn';
import {
    LAB_REPORT_CHOICES, LAB_REPORT_NUMBERS, LAB_REPORT_SIGNATORIES, LAB_REPORT_TEMPLATES, LAB_REPORT_TEXT_LIMITS,
    labReportPreviewQuery,
} from '@/utilities/labReportDesign';

/**
 * ADR-223 — l'aspect du compte rendu d'analyses d'un site (le PDF de l'ADR-218) :
 * modèle et couleurs, en-tête, colonnes des résultats, signature et bas de page.
 * Vide, le compte rendu d'origine.
 *
 * L'aperçu est le vrai PDF, rendu par le site lui-même (son en-tête, son logo)
 * sur un patient fictif, avec les réglages en cours de saisie : rien n'est
 * enregistré tant qu'on n'a pas cliqué « Enregistrer ». Les résultats — valeurs,
 * unités, références, notes, validation — ne se règlent jamais ici.
 */
const props = defineProps({
    form: { type: Object, required: true },
    fallbacks: { type: Object, default: () => ({}) },
    assets: { type: Object, default: () => ({}) },
    siteCode: { type: String, required: true },
    siteName: { type: String, default: '' },
    isPortal: { type: Boolean, default: false },
    limits: { type: Object, default: () => ({}) },
    readonly: { type: Boolean, default: false },
});
const emit = defineEmits(['saved']);

const origin = computed(() => props.fallbacks.lab_report ?? {});
const field = (name) => ({ error: props.form.errors?.[name], invalid: Boolean(props.form.errors?.[name]) });
const set = (name, value) => { props.form[name] = value; };

const tab = ref('modele');
const TABS = [
    { value: 'modele', label: 'Modèle & couleurs', icon: LayoutTemplate },
    { value: 'entete', label: 'En-tête', icon: Landmark },
    { value: 'resultats', label: 'Résultats', icon: FlaskConical },
    { value: 'signature', label: 'Signature & bas de page', icon: Signature },
];

/* --- Modèle & couleurs --------------------------------------------- */

const templates = LAB_REPORT_CHOICES.lab_report_template.map((value) => ({ value, ...LAB_REPORT_TEMPLATES[value] }));
const FONT_OPTIONS = [
    { value: 'SANS', label: 'Sans empattements', icon: Type },
    { value: 'SERIF', label: 'Avec empattements', icon: ALargeSmall },
];
const [sizeMin, sizeMax, sizeDefault] = LAB_REPORT_NUMBERS.lab_report_font_size;
const sizeOptions = Array.from({ length: (sizeMax - sizeMin) / 5 + 1 }, (_, index) => {
    const value = sizeMin + index * 5;

    return { value: String(value), label: value === sizeDefault ? `${value} % (d’origine)` : `${value} %` };
});

const accentShown = computed(() => props.form.lab_report_accent_color || origin.value.accent || '#1D4ED8');
const COLORS = computed(() => [
    { field: 'lab_report_accent_color', label: 'Couleur principale', hint: 'Le nom de l’établissement, les titres de section, les filets et le bandeau. Vide : la couleur principale du site.', fallback: origin.value.accent, fallbackLabel: 'du site' },
    { field: 'lab_report_text_color', label: 'Couleur du texte', hint: 'Le texte des résultats et du patient.', fallback: origin.value.text, fallbackLabel: 'd’origine' },
    { field: 'lab_report_section_background', label: 'Fond des titres de section', hint: 'Vide : pas de fond, un filet sous le titre. Le texte passe en blanc sur un fond foncé.', fallback: '#FFFFFF', fallbackLabel: 'sans fond' },
    { field: 'lab_report_patient_background', label: 'Fond du bloc patient', hint: 'Vide : pas de fond, un filet gris dessous.', fallback: '#FFFFFF', fallbackLabel: 'sans fond' },
    { field: 'lab_report_abnormal_color', label: 'Valeurs hors norme', hint: 'Les valeurs pathologiques, déjà en gras avec ↑ / ↓. Une valeur critique reste rouge.', fallback: props.form.lab_report_text_color || origin.value.text, fallbackLabel: 'du texte' },
]);

/* --- En-tête ---------------------------------------------------------- */

const HEADER_TEXTS = computed(() => [
    { field: 'lab_report_heading', label: 'Nom en tête', icon: Landmark, placeholder: origin.value.heading, hint: `Vide : « ${origin.value.heading ?? ''} ».` },
    { field: 'lab_report_subheading', label: 'Sous-titre', icon: FlaskConical, placeholder: origin.value.subheading, hint: `Sous le nom. Vide : « ${origin.value.subheading ?? ''} ».` },
    { field: 'lab_report_title', label: 'Titre du document', icon: FileText, placeholder: 'Ex. : Compte rendu d’analyses médicales', hint: 'Centré, en capitales, sous l’en-tête. Vide : aucun titre.' },
]);
const HEADER_SWITCHES = [
    { field: 'lab_report_show_logo', label: 'Le logo', hint: 'Celui du compte rendu, sinon celui du site (Identité).', icon: Image },
    { field: 'lab_report_show_contacts', label: 'Les coordonnées', hint: 'Adresse, téléphone et email (Identité légale).', icon: MapPin },
    { field: 'lab_report_show_legal', label: 'NIF et STAT', hint: 'Sous l’en-tête (Identité légale).', icon: Hash },
    { field: 'lab_report_show_qr', label: 'Le QR code', hint: 'À droite du patient : le numéro de laboratoire, rien d’autre. Le scanner rouvre la demande au laboratoire.', icon: QrCode },
];
const logoFallback = computed(() => props.assets?.logo?.data_url || props.fallbacks.badge?.logo_url || '');

/* --- Résultats -------------------------------------------------------- */

const RESULT_SWITCHES = [
    { field: 'lab_report_show_anteriority', label: 'La colonne Antériorité', hint: 'La dernière valeur de la même ligne chez ce patient, avec sa date.', icon: Columns3 },
    { field: 'lab_report_zebra', label: 'Les lignes alternées', hint: 'Une ligne sur deux sur fond gris clair : plus facile à suivre du doigt.', icon: Rows3 },
];

/* --- Signature & bas de page ----------------------------------------- */

const signatories = LAB_REPORT_CHOICES.lab_report_signatory.map((value) => ({ value, ...LAB_REPORT_SIGNATORIES[value] }));
const showsLab = computed(() => ['LAB', 'BOTH', 'AUTO'].includes(props.form.lab_report_signatory));
const showsPhysician = computed(() => ['PHYSICIAN', 'BOTH', 'AUTO'].includes(props.form.lab_report_signatory));
const CLOSING_SWITCHES = [
    { field: 'lab_report_show_sent', label: '« Résultats envoyés au médecin par … »', hint: 'Qui a envoyé les résultats, et quand (ADR-216).', icon: Send },
    { field: 'lab_report_show_approval', label: '« Résultats validés par … »', hint: 'Le médecin qui a validé, et ce qui attend sa validation.', icon: ShieldCheck },
    { field: 'lab_report_show_generated', label: '« Édité le … »', hint: 'La date à laquelle le PDF a été produit.', icon: CalendarClock },
    { field: 'lab_report_show_closing_identity', label: 'Le rappel du patient', hint: 'Nom, dossier et n° de laboratoire, au-dessus de la signature.', icon: UserRound },
];
const FOOTER_SWITCHES = [
    { field: 'lab_report_show_footer_patient', label: 'Le patient dans le pied', hint: 'Son nom et son dossier, sur chaque page : une page détachée se rattache à son patient.', icon: UserRound },
    { field: 'lab_report_show_page_numbers', label: 'Les numéros de page', hint: '« Page 1 / 2 », en bas à droite.', icon: Hash },
];
const footerPreview = computed(() => [
    String(props.form.lab_report_footer_text ?? '').trim() || origin.value.footer_text,
    props.form.lab_report_show_footer_patient ? 'Mme EXEMPLE Patiente · Dossier n° EX-26-0001' : null,
    String(props.form.lab_report_website ?? '').trim() || null,
].filter(Boolean).join(' · '));

const OPTION_CLASS = 'cursor-pointer [&:has([data-state=checked])>div]:border-primary [&:has(:focus-visible)>div]:ring-2 [&:has(:focus-visible)>div]:ring-ring/40 [&:has([data-disabled])]:cursor-not-allowed [&:has([data-disabled])]:opacity-60';

/* --- Aperçu : le vrai PDF, rendu par le site -------------------------- */

const previewUrl = ref('');
const previewError = ref('');
const previewLoading = ref(false);
let controller = null;
let timer = null;

const query = computed(() => labReportPreviewQuery(props.form));

const clearUrl = () => {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = '';
};

const loadPreview = async () => {
    if (props.isPortal || typeof window === 'undefined') return;
    controller?.abort();
    controller = new AbortController();
    previewLoading.value = true;
    previewError.value = '';

    const params = new URLSearchParams({ site_code: props.siteCode, ...query.value });

    try {
        const response = await fetch(`/super-admin/settings/lab-report-preview?${params.toString()}`, {
            // JSON d'abord : un refus (validation, site injoignable) revient en JSON, le PDF reste le PDF.
            headers: { Accept: 'application/json, application/pdf', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller.signal,
        });

        if (! response.ok || ! String(response.headers.get('Content-Type')).startsWith('application/pdf')) {
            const body = await response.json().catch(() => ({}));
            const first = body?.errors ? Object.values(body.errors).flat()[0] : null;
            previewError.value = first || body?.message || 'L’aperçu n’a pas pu être rendu.';

            return;
        }

        const blob = await response.blob();
        clearUrl();
        previewUrl.value = URL.createObjectURL(blob);
    } catch (error) {
        if (error?.name !== 'AbortError') previewError.value = 'Le portail ne joint pas le site : l’aperçu n’a pas pu être rendu.';
    } finally {
        if (! controller?.signal.aborted) previewLoading.value = false;
    }
};

const schedule = () => {
    clearTimeout(timer);
    timer = setTimeout(loadPreview, 700);
};

// Chaque réglage change l'aperçu ; un logo enregistré aussi.
watch(() => JSON.stringify(query.value), schedule);
watch(() => [props.siteCode, props.assets?.lab_logo?.data_url, props.assets?.logo?.data_url].join('|'), schedule);

onMounted(loadPreview);
onBeforeUnmount(() => {
    clearTimeout(timer);
    controller?.abort();
    clearUrl();
});

const openPreview = () => {
    if (previewUrl.value) window.open(previewUrl.value, '_blank', 'noopener');
};
</script>

<template>
    <SettingsSection id="compte-rendu" title="Compte rendu d’analyses" :description="`Le PDF des résultats de ${siteName} : son modèle, ses couleurs, son en-tête et sa signature. Vide, le compte rendu d’origine. Les résultats eux-mêmes ne se règlent jamais ici.`">
        <div v-if="isPortal" class="flex items-start gap-3 rounded-lg border border-border bg-muted/40 px-4 py-4 text-sm text-muted-foreground">
            <FlaskConical class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
            <p>Le portail n’imprime aucun compte rendu : choisissez un site (en haut) pour régler le sien.</p>
        </div>

        <div v-else class="grid gap-8 cq-6xl:grid-cols-[minmax(0,1fr)_25rem]">
            <Tabs v-model="tab" class="min-w-0">
                <TabsList class="flex h-auto w-full flex-wrap justify-start gap-1" aria-label="Réglages du compte rendu">
                    <TabsTrigger v-for="item in TABS" :key="item.value" :value="item.value">
                        <component :is="item.icon" class="h-4 w-4" aria-hidden="true" />{{ item.label }}
                    </TabsTrigger>
                </TabsList>

                <!-- 1 · Modèle & couleurs -->
                <TabsContent value="modele" class="space-y-8">
                    <SettingsField label="Modèle" :description="LAB_REPORT_TEMPLATES[form.lab_report_template]?.hint" :error="field('lab_report_template').error">
                        <RadioGroup :model-value="form.lab_report_template" :disabled="readonly" class="grid max-w-xl grid-cols-3 gap-4 pt-1" aria-label="Modèle du compte rendu" @update:model-value="(value) => set('lab_report_template', value)">
                            <Label v-for="option in templates" :key="option.value" :class="OPTION_CLASS">
                                <RadioGroupItem :value="option.value" class="sr-only" />
                                <div class="rounded-md border-2 border-border bg-card p-1 transition-colors hover:border-primary/40">
                                    <!-- Un schéma de la page : l'en-tête, les lignes de résultats. -->
                                    <span class="block h-24 w-full space-y-1.5 overflow-hidden rounded-sm bg-white p-2" aria-hidden="true">
                                        <span v-if="option.value === 'BANNER'" class="flex h-5 items-center justify-between rounded-[2px] px-1.5" :style="{ backgroundColor: accentShown }">
                                            <span class="h-3 w-6 rounded-[1px] bg-white" /><span class="h-1.5 w-10 rounded bg-white/80" />
                                        </span>
                                        <span v-else-if="option.value === 'MINIMAL'" class="flex flex-col items-center gap-1">
                                            <span class="h-2.5 w-5 rounded-[1px] bg-slate-300" /><span class="h-1.5 w-12 rounded bg-slate-700" /><span class="block h-px w-full bg-slate-300" />
                                        </span>
                                        <span v-else class="block">
                                            <span class="flex items-center justify-between"><span class="h-3 w-6 rounded-[1px] bg-slate-300" /><span class="h-1.5 w-10 rounded" :style="{ backgroundColor: accentShown }" /></span>
                                            <span class="mt-1 block h-px w-full" :style="{ backgroundColor: accentShown }" />
                                        </span>
                                        <span class="block h-1 w-1/3 rounded" :style="{ backgroundColor: option.value === 'MINIMAL' ? '#334155' : accentShown }" />
                                        <span v-for="line in 3" :key="line" class="flex gap-1"><span class="h-1 w-1/2 rounded bg-slate-200" /><span class="h-1 w-1/4 rounded bg-slate-300" /></span>
                                    </span>
                                </div>
                                <span class="block w-full p-2 text-center text-sm font-normal">{{ option.label }}</span>
                            </Label>
                        </RadioGroup>
                    </SettingsField>

                    <div class="grid gap-6 cq-4xl:grid-cols-2">
                        <SettingsField label="Police" description="Les polices du PDF : lisibles à l’écran comme à l’impression." :error="field('lab_report_font').error">
                            <SegmentedField :model-value="form.lab_report_font" :options="FONT_OPTIONS" :disabled="readonly" aria-label="Police du compte rendu" @update:model-value="(value) => set('lab_report_font', value || 'SANS')" />
                        </SettingsField>
                        <SettingsField label="Taille du texte" for="reglage-lab_report_font_size" description="Tout le texte ensemble. Un compte rendu qui ne tient plus sur une page se resserre de lui-même." :error="field('lab_report_font_size').error">
                            <Select id="reglage-lab_report_font_size" :model-value="String(form.lab_report_font_size)" :options="sizeOptions" class="w-full max-w-xs" :disabled="readonly" @update:model-value="(value) => set('lab_report_font_size', Number(value || sizeDefault))" />
                        </SettingsField>
                    </div>

                    <div class="grid gap-6 cq-4xl:grid-cols-2">
                        <SettingsField v-for="color in COLORS" :key="color.field" :label="color.label" :for="`reglage-${color.field}`" :description="color.hint" :error="field(color.field).error">
                            <ColorField
                                :id="`reglage-${color.field}`"
                                :model-value="form[color.field]"
                                :fallback="color.fallback"
                                :fallback-label="color.fallbackLabel"
                                :label="`${color.label} du compte rendu`"
                                :disabled="readonly"
                                :invalid="field(color.field).invalid"
                                @update:model-value="(value) => set(color.field, value)"
                            />
                        </SettingsField>
                    </div>
                </TabsContent>

                <!-- 2 · En-tête -->
                <TabsContent value="entete" class="space-y-8">
                    <div class="grid gap-6 cq-4xl:grid-cols-2">
                        <SettingsField
                            v-for="text in HEADER_TEXTS"
                            :key="text.field"
                            :label="text.label"
                            :for="`reglage-${text.field}`"
                            :description="`${text.hint} ${LAB_REPORT_TEXT_LIMITS[text.field]} caractères au plus.`"
                            :error="field(text.field).error"
                        >
                            <IconInput :id="`reglage-${text.field}`" :model-value="form[text.field]" :icon="text.icon" :placeholder="text.placeholder" :maxlength="LAB_REPORT_TEXT_LIMITS[text.field]" :disabled="readonly" @update:model-value="(value) => set(text.field, value)" />
                        </SettingsField>
                    </div>

                    <SettingsField label="Ce que l’en-tête affiche">
                        <div class="grid divide-y divide-border rounded-lg border border-border cq-4xl:grid-cols-2 cq-4xl:divide-y-0">
                            <label
                                v-for="(item, index) in HEADER_SWITCHES"
                                :key="item.field"
                                :for="`reglage-${item.field}`"
                                :class="cn('flex items-center gap-3 px-4 py-3 cq-4xl:border-border', index >= 2 && 'cq-4xl:border-t', readonly ? 'cursor-not-allowed' : 'cursor-pointer')"
                            >
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-primary/10 text-primary"><component :is="item.icon" class="h-4 w-4" aria-hidden="true" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-foreground">{{ item.label }}</span>
                                    <span class="block text-[0.8rem] text-muted-foreground">{{ item.hint }}</span>
                                </span>
                                <Switch :id="`reglage-${item.field}`" :model-value="form[item.field]" :disabled="readonly" @update:model-value="(value) => set(item.field, value)" />
                            </label>
                        </div>
                    </SettingsField>

                    <SettingsField
                        v-if="form.lab_report_show_logo"
                        label="Logo du compte rendu"
                        description="Facultatif : un logo propre au laboratoire. Vide, le logo du site (Identité). Une image en largeur, sur fond clair. L’aperçu le montre une fois enregistré."
                    >
                        <SettingsAssetField
                            kind="lab_logo"
                            label="Logo du compte rendu"
                            :asset="assets?.lab_logo"
                            :site-code="siteCode"
                            :max-kb="limits.asset_max_kb?.lab_logo ?? 1024"
                            :mimes="limits.asset_mimes?.lab_logo ?? 'png,jpg,jpeg,webp'"
                            :readonly="readonly"
                            shape="wide"
                            :fallback-url="logoFallback"
                            fallback-label="Logo du site"
                            compact
                            hide-label
                            @saved="emit('saved')"
                        />
                    </SettingsField>
                </TabsContent>

                <!-- 3 · Résultats -->
                <TabsContent value="resultats" class="space-y-6">
                    <SettingsField label="Le tableau des résultats" description="Les colonnes Résultat et Val. réf. s’affichent toujours.">
                        <div class="grid divide-y divide-border rounded-lg border border-border">
                            <label
                                v-for="item in RESULT_SWITCHES"
                                :key="item.field"
                                :for="`reglage-${item.field}`"
                                :class="cn('flex items-center gap-3 px-4 py-3', readonly ? 'cursor-not-allowed' : 'cursor-pointer')"
                            >
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-primary/10 text-primary"><component :is="item.icon" class="h-4 w-4" aria-hidden="true" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-foreground">{{ item.label }}</span>
                                    <span class="block text-[0.8rem] text-muted-foreground">{{ item.hint }}</span>
                                </span>
                                <Switch :id="`reglage-${item.field}`" :model-value="form[item.field]" :disabled="readonly" @update:model-value="(value) => set(item.field, value)" />
                            </label>
                        </div>
                    </SettingsField>
                    <p class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-[0.8rem] leading-5 text-muted-foreground">
                        Les valeurs, unités, références, notes, antériorités et la validation viennent du dossier de chaque patient : elles ne se règlent jamais ici. Une valeur critique reste rouge, quelle que soit la couleur choisie.
                    </p>
                </TabsContent>

                <!-- 4 · Signature & bas de page -->
                <TabsContent value="signature" class="space-y-8">
                    <SettingsField label="Qui signe" :description="LAB_REPORT_SIGNATORIES[form.lab_report_signatory]?.hint" :error="field('lab_report_signatory').error">
                        <SegmentedField
                            :model-value="form.lab_report_signatory"
                            :options="signatories.map((option) => ({ value: option.value, label: option.label, hint: option.hint }))"
                            :disabled="readonly"
                            aria-label="Qui signe le compte rendu"
                            @update:model-value="(value) => set('lab_report_signatory', value || 'LAB')"
                        />
                    </SettingsField>

                    <div class="grid gap-6 cq-4xl:grid-cols-2">
                        <SettingsField v-if="showsLab" label="Intitulé du laboratoire" for="reglage-lab_report_lab_signatory" :description="`Au-dessus de la case de signature. Vide : « ${origin.lab_signatory ?? ''} ».`" :error="field('lab_report_lab_signatory').error">
                            <IconInput id="reglage-lab_report_lab_signatory" :model-value="form.lab_report_lab_signatory" :icon="PenLine" :placeholder="origin.lab_signatory" :maxlength="LAB_REPORT_TEXT_LIMITS.lab_report_lab_signatory" :disabled="readonly" @update:model-value="(value) => set('lab_report_lab_signatory', value)" />
                        </SettingsField>
                        <SettingsField v-if="showsPhysician" label="Intitulé du médecin" for="reglage-lab_report_physician_signatory" :description="`Suivi du nom du médecin. Vide : « ${origin.physician_signatory ?? ''} ».`" :error="field('lab_report_physician_signatory').error">
                            <IconInput id="reglage-lab_report_physician_signatory" :model-value="form.lab_report_physician_signatory" :icon="PenLine" :placeholder="origin.physician_signatory" :maxlength="LAB_REPORT_TEXT_LIMITS.lab_report_physician_signatory" :disabled="readonly" @update:model-value="(value) => set('lab_report_physician_signatory', value)" />
                        </SettingsField>
                    </div>

                    <SettingsField label="Les lignes au-dessus de la signature" description="Chacune se masque sans rien changer à ce qui est enregistré au dossier.">
                        <div class="grid divide-y divide-border rounded-lg border border-border cq-4xl:grid-cols-2 cq-4xl:divide-y-0">
                            <label
                                v-for="(item, index) in CLOSING_SWITCHES"
                                :key="item.field"
                                :for="`reglage-${item.field}`"
                                :class="cn('flex items-center gap-3 px-4 py-3 cq-4xl:border-border', index >= 2 && 'cq-4xl:border-t', readonly ? 'cursor-not-allowed' : 'cursor-pointer')"
                            >
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-primary/10 text-primary"><component :is="item.icon" class="h-4 w-4" aria-hidden="true" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-foreground">{{ item.label }}</span>
                                    <span class="block text-[0.8rem] text-muted-foreground">{{ item.hint }}</span>
                                </span>
                                <Switch :id="`reglage-${item.field}`" :model-value="form[item.field]" :disabled="readonly" @update:model-value="(value) => set(item.field, value)" />
                            </label>
                        </div>
                    </SettingsField>

                    <SettingsField label="Le bas de page" description="Répété en bas de chaque page.">
                        <div class="space-y-4 rounded-lg border border-border p-4">
                            <label for="reglage-lab_report_show_footer" :class="cn('flex items-center gap-3', readonly ? 'cursor-not-allowed' : 'cursor-pointer')">
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-primary/10 text-primary"><FileText class="h-4 w-4" aria-hidden="true" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-foreground">Afficher le bas de page</span>
                                    <span class="block text-[0.8rem] text-muted-foreground">L’établissement, le patient et le site web, centrés.</span>
                                </span>
                                <Switch id="reglage-lab_report_show_footer" :model-value="form.lab_report_show_footer" :disabled="readonly" @update:model-value="(value) => set('lab_report_show_footer', value)" />
                            </label>

                            <template v-if="form.lab_report_show_footer">
                                <div class="grid gap-6 cq-4xl:grid-cols-2">
                                    <SettingsField label="Texte du bas de page" for="reglage-lab_report_footer_text" :description="`Vide : « ${origin.footer_text ?? ''} ». ${LAB_REPORT_TEXT_LIMITS.lab_report_footer_text} caractères au plus.`" :error="field('lab_report_footer_text').error">
                                        <IconInput id="reglage-lab_report_footer_text" :model-value="form.lab_report_footer_text" :icon="Landmark" :placeholder="origin.footer_text" :maxlength="LAB_REPORT_TEXT_LIMITS.lab_report_footer_text" :disabled="readonly" @update:model-value="(value) => set('lab_report_footer_text', value)" />
                                    </SettingsField>
                                    <SettingsField label="Site web" for="reglage-lab_report_website" :description="`Facultatif, à la fin du bas de page. ${LAB_REPORT_TEXT_LIMITS.lab_report_website} caractères au plus.`" :error="field('lab_report_website').error">
                                        <IconInput id="reglage-lab_report_website" :model-value="form.lab_report_website" :icon="Globe" placeholder="Ex. : www.clinique-saint-georges.mg" :maxlength="LAB_REPORT_TEXT_LIMITS.lab_report_website" :disabled="readonly" @update:model-value="(value) => set('lab_report_website', value)" />
                                    </SettingsField>
                                </div>
                                <p class="rounded-md bg-muted/50 px-3 py-2 text-center text-[0.8rem] text-muted-foreground">
                                    <span class="sr-only">Aperçu du bas de page : </span>{{ footerPreview }}
                                </p>
                            </template>

                            <div class="grid gap-3 border-t border-border pt-3">
                                <label
                                    v-for="item in FOOTER_SWITCHES"
                                    :key="item.field"
                                    :for="`reglage-${item.field}`"
                                    :class="cn('flex items-center gap-3', readonly || (item.field === 'lab_report_show_footer_patient' && ! form.lab_report_show_footer) ? 'cursor-not-allowed opacity-60' : 'cursor-pointer')"
                                >
                                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-primary/10 text-primary"><component :is="item.icon" class="h-4 w-4" aria-hidden="true" /></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-medium text-foreground">{{ item.label }}</span>
                                        <span class="block text-[0.8rem] text-muted-foreground">{{ item.hint }}</span>
                                    </span>
                                    <Switch
                                        :id="`reglage-${item.field}`"
                                        :model-value="form[item.field]"
                                        :disabled="readonly || (item.field === 'lab_report_show_footer_patient' && ! form.lab_report_show_footer)"
                                        @update:model-value="(value) => set(item.field, value)"
                                    />
                                </label>
                            </div>
                        </div>
                    </SettingsField>
                </TabsContent>
            </Tabs>

            <!-- L'aperçu : le vrai PDF, rendu par le site sur un patient fictif. -->
            <aside class="min-w-0 space-y-3 cq-6xl:sticky cq-6xl:top-24 cq-6xl:self-start" aria-label="Aperçu du compte rendu">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-sm font-medium text-foreground">Aperçu</p>
                    <div class="flex items-center gap-2">
                        <span v-if="previewLoading" class="inline-flex items-center gap-1.5 text-xs text-muted-foreground" aria-live="polite"><Loader2 class="h-3.5 w-3.5 animate-spin" aria-hidden="true" />Mise à jour…</span>
                        <Button type="button" size="sm" variant="outline" :disabled="! previewUrl" @click="openPreview"><ExternalLink class="h-4 w-4" aria-hidden="true" />Ouvrir</Button>
                    </div>
                </div>
                <div class="relative overflow-hidden rounded-lg border border-border bg-muted/40" style="aspect-ratio: 210 / 297">
                    <iframe
                        v-if="previewUrl"
                        :src="`${previewUrl}#toolbar=0&navpanes=0&view=FitH`"
                        title="Aperçu du compte rendu d’analyses"
                        class="h-full w-full bg-white"
                    />
                    <div v-else-if="! previewError" class="absolute inset-0 grid place-items-center text-sm text-muted-foreground">
                        <span class="inline-flex items-center gap-2"><Loader2 class="h-4 w-4 animate-spin" aria-hidden="true" />Rendu de l’aperçu…</span>
                    </div>
                    <div v-if="previewError" class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-card/95 px-6 text-center text-sm text-destructive" role="alert">
                        <TriangleAlert class="h-5 w-5" aria-hidden="true" />{{ previewError }}
                        <Button type="button" size="sm" variant="outline" @click="loadPreview">Réessayer</Button>
                    </div>
                </div>
                <p class="text-[0.8rem] leading-5 text-muted-foreground">
                    Un patient et des résultats fictifs, avec l’en-tête réel de {{ siteName }}. Rien n’est enregistré tant que vous n’avez pas cliqué « Enregistrer ».
                </p>
            </aside>
        </div>
    </SettingsSection>
</template>
