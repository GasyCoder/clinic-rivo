<script setup>
import { computed, ref } from 'vue';
import {
    ALargeSmall, ArrowDownUp, Building2, CaseSensitive, CaseUpper, Circle, Droplets, Eye, EyeOff, GraduationCap, Hash,
    Image, LayoutGrid, ListChecks, MapPin, Maximize2, Palette, Printer, Quote, RectangleHorizontal, RectangleVertical,
    Scissors, Shapes, Sparkles, Square, SquareRoundCorner, Stethoscope, Tag, TriangleAlert, Type, UserRound,
    CalendarClock, Briefcase, Layers, TextQuote, Wand2,
} from 'lucide-vue-next';
import ColorField from '@/Components/Settings/ColorField.vue';
import SegmentedField from '@/Components/Settings/SegmentedField.vue';
import SettingsAssetField from '@/Components/Settings/SettingsAssetField.vue';
import SettingsField from '@/Components/Settings/SettingsField.vue';
import SettingsSection from '@/Components/Settings/SettingsSection.vue';
import EmployeeBadge from '@/Components/Administration/EmployeeBadge.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
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
    BADGE_CARD_SCALES, BADGE_CHOICES, BADGE_DEFAULT_DESIGN, BADGE_FONTS, BADGE_ICONS, BADGE_NUMBERS, BADGE_PAPER_LABELS,
    BADGE_SAMPLES, BADGE_TAGLINE_FONTS, BADGE_TEXT_LIMITS, badgeCardMm, badgeDesignFromSettings, badgePalette,
    badgeSheetLayout, formatMm,
} from '@/utilities/employeeBadge';

/**
 * ADR-209 — le badge du personnel d'un site : un seul modèle pour tout le
 * personnel (médecin, infirmier, gardien, RH, stagiaire…). Tout ce qui se règle
 * (amendement du 2026-09-27) est rangé en cinq onglets : couleurs et
 * établissement, textes, éléments affichés, polices et tailles, disposition et
 * impression. Vide, le modèle bleu et jaune de la clinique.
 *
 * L'aperçu est le composant même qui s'imprime : ce qu'on règle ici est ce que
 * le RH imprimera. La mise en page d'impression n'est qu'une proposition : le RH
 * peut la changer pour une impression, sans toucher à ce réglage.
 */
const props = defineProps({
    form: { type: Object, required: true },
    fallbacks: { type: Object, default: () => ({}) },
    assets: { type: Object, default: () => ({}) },
    logoStyles: { type: Array, default: () => [] },
    siteCode: { type: String, required: true },
    siteName: { type: String, default: '' },
    limits: { type: Object, default: () => ({}) },
    readonly: { type: Boolean, default: false },
});
const emit = defineEmits(['saved']);

const defaults = computed(() => ({ ...BADGE_DEFAULT_DESIGN, ...(props.fallbacks.badge ?? {}) }));
const brand = computed(() => String(props.form.app_name ?? '').trim() || props.fallbacks.app_name || defaults.value.brand);

/** L'image que le badge montre : l'emblème déposé, sinon (logo seul) le logo du site, sinon celle de la clinique. */
const emblemUrl = computed(() => {
    if (props.assets?.badge?.data_url) return props.assets.badge.data_url;
    if (props.form.badge_logo_style === 'LOGO') return props.assets?.logo?.data_url || defaults.value.logo_url || defaults.value.emblem_url;

    return defaults.value.emblem_url;
});

const design = computed(() => badgeDesignFromSettings(props.form, {
    defaults: defaults.value,
    brand: brand.value,
    site: props.siteName,
    emblemUrl: emblemUrl.value,
}));
const palette = computed(() => badgePalette(design.value));

const tab = ref('apparence');
const TABS = [
    { value: 'apparence', label: 'Couleurs', icon: Palette },
    { value: 'textes', label: 'Textes', icon: Type },
    { value: 'elements', label: 'Éléments', icon: ListChecks },
    { value: 'polices', label: 'Polices', icon: ALargeSmall },
    { value: 'disposition', label: 'Mise en page', icon: LayoutGrid },
];

const sample = ref('employee');
const person = computed(() => BADGE_SAMPLES[sample.value]);
const landscape = computed(() => props.form.badge_orientation === 'LANDSCAPE');

const field = (name) => ({
    error: props.form.errors?.[name],
    invalid: Boolean(props.form.errors?.[name]),
});
const set = (name, value) => { props.form[name] = value; };

/* --- Couleurs & établissement -------------------------------------- */

const COLORS = computed(() => [
    { field: 'badge_primary_color', label: 'Couleur principale', hint: 'Bandes, bandeau du nom, sceau et vagues.', fallback: defaults.value.primary, fallbackLabel: 'de la clinique' },
    { field: 'badge_accent_color', label: 'Couleur d’accent', hint: 'Bandes claires, pastille du service, contours.', fallback: defaults.value.accent, fallbackLabel: 'de la clinique' },
    { field: 'badge_text_color', label: 'Couleur du texte', hint: 'Le nom, le service et la fonction. Vide : un bleu foncé tiré de la principale.', fallback: palette.value.ink, fallbackLabel: 'tirée de la principale' },
    { field: 'badge_background_color', label: 'Fond de la carte', hint: 'Vide : un blanc légèrement teinté de la principale.', fallback: palette.value.paper, fallbackLabel: 'tiré de la principale' },
]);

const STYLE_HINTS = {
    SEAL: 'l’emblème dans un sceau rond, le texte écrit autour.',
    LOGO: 'l’image telle quelle, en largeur, sans sceau.',
    NONE: 'ni sceau ni logo : le coin reste aux bandes.',
};
const styles = computed(() => (props.logoStyles.length ? props.logoStyles : [
    { value: 'SEAL', label: 'Sceau' }, { value: 'LOGO', label: 'Logo seul' }, { value: 'NONE', label: 'Aucun' },
]));

/* --- Textes --------------------------------------------------------- */

const TEXTS = computed(() => [
    { field: 'badge_tagline', label: 'Devise', icon: Quote, placeholder: defaults.value.tagline, hint: `Coupée en deux lignes. Vide : « ${defaults.value.tagline} ».` },
    { field: 'badge_intern_label', label: 'Mention des stagiaires', icon: GraduationCap, placeholder: defaults.value.intern_label, hint: 'Dans la pastille, à la place du service : on ne prend jamais un stagiaire pour un titulaire.' },
    { field: 'badge_number_label', label: 'Avant le numéro', icon: Hash, placeholder: defaults.value.number_label, hint: 'Écrit devant la référence du badge, dans le pied (« N° EMP-0001 »).' },
    { field: 'badge_footer_text', label: 'Mention en pied de badge', icon: TextQuote, placeholder: 'Ex. : Accès réservé au personnel', hint: 'Facultative, ajoutée après le numéro. Resserrée si elle est longue.' },
]);

/* --- Éléments affichés ---------------------------------------------- */

const DISPLAY = [
    { field: 'badge_show_photo', label: 'La photo', hint: 'Sans photo, les initiales de la personne.', icon: Image },
    { field: 'badge_show_tagline', label: 'La devise', hint: 'Sous les bandes, à la main.', icon: Quote },
    { field: 'badge_show_icon', label: 'Le médaillon du métier', hint: 'L’icône de la fonction (ou celle choisie ci-dessous).', icon: Stethoscope },
    { field: 'badge_show_department', label: 'Le service', hint: 'Dans la pastille. Un stagiaire garde toujours sa mention.', icon: Building2 },
    { field: 'badge_show_job', label: 'La fonction', hint: 'Sous la pastille ; pour un stagiaire, sa filière.', icon: Briefcase },
    { field: 'badge_show_number', label: 'Le numéro du badge', hint: 'La référence « Badge » du dossier, sinon le matricule.', icon: Hash },
    { field: 'badge_show_validity', label: 'La fin du stage', hint: '« Valable jusqu’au … » sur le badge d’un stagiaire.', icon: CalendarClock },
    { field: 'badge_show_site', label: 'Le nom du site', hint: 'Sous l’emblème : utile quand le personnel change de site.', icon: MapPin },
    { field: 'badge_show_watermark', label: 'Le filigrane', hint: 'L’emblème, pâle, derrière la photo.', icon: Layers },
    { field: 'badge_show_decorations', label: 'Les décors', hint: 'Points, cercles pâles et fin trait des bandes.', icon: Sparkles },
];

const iconOptions = computed(() => BADGE_CHOICES.badge_icon.map((value) => ({
    value,
    label: value === 'AUTO' ? 'Automatique — selon la fonction' : BADGE_ICONS[value].label,
})));
const iconFor = (value) => (value === 'AUTO' ? Wand2 : BADGE_ICONS[value]?.icon);

/* --- Polices & tailles ----------------------------------------------- */

const fontOptions = Object.entries(BADGE_FONTS).map(([value, font]) => ({ value, label: `${font.label} — ${font.hint}` }));
const taglineFontOptions = Object.entries(BADGE_TAGLINE_FONTS).map(([value, font]) => ({ value, label: `${font.label} — ${font.hint}` }));
const familyOf = (value, list) => list[value]?.family ?? BADGE_FONTS[props.form.badge_font]?.family ?? BADGE_FONTS.SANS.family;

const [sizeMin, sizeMax, sizeDefault] = BADGE_NUMBERS.badge_name_size;
const sizeOptions = Array.from({ length: (sizeMax - sizeMin) / 10 + 1 }, (_, index) => {
    const value = sizeMin + index * 10;

    return { value: String(value), label: value === sizeDefault ? `${value} % (de la clinique)` : `${value} %` };
});
const SIZES = [
    { field: 'badge_name_size', label: 'Taille du nom', hint: 'Le prénom et le nom. Un nom long reste sur sa ligne.' },
    { field: 'badge_text_size', label: 'Taille des textes', hint: 'Service, fonction, site et pied de badge.' },
    { field: 'badge_tagline_size', label: 'Taille de la devise', hint: 'Bornée par la place entre les bandes.' },
];

const CASE_OPTIONS = [
    { value: 'UPPER', label: 'MAJUSCULES', icon: CaseUpper },
    { value: 'AS_IS', label: 'Tel qu’écrit', icon: CaseSensitive },
];
const ORDER_OPTIONS = [
    { value: 'FIRST_LAST', label: 'Prénom, puis nom', icon: UserRound },
    { value: 'LAST_FIRST', label: 'Nom, puis prénom', icon: ArrowDownUp },
];

/* --- Mise en page & impression -------------------------------------- */

const ORIENTATIONS = [
    { value: 'PORTRAIT', label: 'Portrait', hint: 'Vertical, porté au cou ou à la pince.', icon: RectangleVertical },
    { value: 'LANDSCAPE', label: 'Paysage', hint: 'Horizontal : photo à gauche, nom à droite.', icon: RectangleHorizontal },
];
const cardSizeOptions = computed(() => Object.keys(BADGE_CARD_SCALES).map((value) => {
    const names = { STANDARD: 'Standard (carte bancaire)', LARGE: 'Grand', XLARGE: 'Très grand' };

    return { value, label: `${names[value]} — ${formatMm(badgeCardMm(value, props.form.badge_orientation))}` };
}));
const PHOTO_OPTIONS = [
    { value: 'CIRCLE', label: 'Ronde', icon: Circle },
    { value: 'ROUNDED', label: 'Carrée arrondie', icon: Square },
];
const CORNER_OPTIONS = [
    { value: 'ROUNDED', label: 'Arrondis', icon: SquareRoundCorner },
    { value: 'SQUARE', label: 'Droits', icon: Square },
];
const paperOptions = computed(() => BADGE_CHOICES.badge_paper.map((value) => ({
    value,
    label: value === 'CARD' ? 'Carte seule — une carte par page (imprimante à badges)' : BADGE_PAPER_LABELS[value],
})));
const PAGE_ORIENTATIONS = [
    { value: 'PORTRAIT', label: 'Portrait', icon: RectangleVertical },
    { value: 'LANDSCAPE', label: 'Paysage', icon: RectangleHorizontal },
];
const isCardPaper = computed(() => props.form.badge_paper === 'CARD');
const numberInput = (name, value) => { props.form[name] = value === '' ? '' : Number(value); };

const sheet = computed(() => badgeSheetLayout({
    paper: props.form.badge_paper,
    paperOrientation: props.form.badge_paper_orientation,
    margin: Number(props.form.badge_page_margin) || 0,
    gap: Number(props.form.badge_gap) || 0,
    cardSize: props.form.badge_card_size,
    orientation: props.form.badge_orientation,
}));
/** Les cartes de la planche schématique, centrées en largeur sous la marge du haut. */
const sheetCards = computed(() => {
    const s = sheet.value;
    if (s.mode !== 'sheet' || ! s.fits) return [];
    const usedWidth = s.columns * s.card.width + (s.columns - 1) * s.gap;
    const left = (s.page.width - usedWidth) / 2;

    return Array.from({ length: s.perPage }, (_, index) => ({
        x: left + (index % s.columns) * (s.card.width + s.gap),
        y: s.margin + Math.floor(index / s.columns) * (s.card.height + s.gap),
    }));
});

const OPTION_CLASS = 'cursor-pointer [&:has([data-state=checked])>div]:border-primary [&:has(:focus-visible)>div]:ring-2 [&:has(:focus-visible)>div]:ring-ring/40 [&:has([data-disabled])]:cursor-not-allowed [&:has([data-disabled])]:opacity-60';
</script>

<template>
    <SettingsSection id="badges" title="Badge du personnel" :description="`Le badge imprimé par les RH de ${siteName} : le même modèle pour tout le personnel et les stagiaires. Vide, le modèle bleu et jaune de la clinique.`">
        <div class="grid gap-8 cq-2xl:grid-cols-[minmax(0,1fr)_17rem]">
            <Tabs v-model="tab" class="min-w-0">
                <TabsList class="flex h-auto w-full flex-wrap justify-start gap-1" aria-label="Réglages du badge">
                    <TabsTrigger v-for="item in TABS" :key="item.value" :value="item.value">
                        <component :is="item.icon" class="h-4 w-4" aria-hidden="true" />{{ item.label }}
                    </TabsTrigger>
                </TabsList>

                <!-- 1 · Couleurs & établissement -->
                <TabsContent value="apparence" class="space-y-8">
                    <div class="grid gap-6 cq-4xl:grid-cols-2">
                        <SettingsField v-for="color in COLORS" :key="color.field" :label="color.label" :for="`reglage-${color.field}`" :description="color.hint" :error="field(color.field).error">
                            <ColorField
                                :id="`reglage-${color.field}`"
                                :model-value="form[color.field]"
                                :fallback="color.fallback"
                                :fallback-label="color.fallbackLabel"
                                :label="`${color.label} du badge`"
                                :disabled="readonly"
                                :invalid="field(color.field).invalid"
                                @update:model-value="(value) => set(color.field, value)"
                            />
                        </SettingsField>
                    </div>

                    <SettingsField label="L’établissement sur le badge" :description="`${styles.find((option) => option.value === form.badge_logo_style)?.label ?? ''} — ${STYLE_HINTS[form.badge_logo_style] ?? ''}`" :error="field('badge_logo_style').error">
                        <RadioGroup :model-value="form.badge_logo_style" :disabled="readonly" class="grid max-w-md grid-cols-3 gap-4 pt-1" aria-label="L’établissement sur le badge" @update:model-value="(value) => set('badge_logo_style', value)">
                            <Label v-for="option in styles" :key="option.value" :class="OPTION_CLASS">
                                <RadioGroupItem :value="option.value" class="sr-only" />
                                <div class="rounded-md border-2 border-border bg-card p-1 transition-colors hover:border-primary/40">
                                    <span class="relative grid h-16 w-full place-items-center overflow-hidden rounded-sm bg-muted/60" aria-hidden="true">
                                        <span v-if="option.value === 'SEAL'" class="grid h-12 w-12 place-items-center rounded-full border-2" :style="{ backgroundColor: design.primary, borderColor: design.accent }">
                                            <span class="h-6 w-6 rounded-full bg-card" />
                                        </span>
                                        <span v-else-if="option.value === 'LOGO'" class="grid h-8 w-20 place-items-center rounded-sm bg-card shadow-sm">
                                            <span class="h-2 w-12 rounded" :style="{ backgroundColor: design.primary }" />
                                        </span>
                                        <EyeOff v-else class="h-6 w-6 text-muted-foreground" />
                                    </span>
                                </div>
                                <span class="block w-full p-2 text-center text-sm font-normal">{{ option.label }}</span>
                            </Label>
                        </RadioGroup>
                    </SettingsField>

                    <SettingsField
                        v-if="form.badge_logo_style !== 'NONE' || form.badge_show_watermark"
                        label="Emblème du badge"
                        :description="form.badge_logo_style === 'LOGO'
                            ? 'Vide : le logo du site (Identité), sinon celui de la clinique. Une image en largeur, sur fond clair.'
                            : 'Vide : l’emblème de la clinique. Une image carrée, au fond transparent, qui tient dans un rond. Sert aussi au filigrane.'"
                    >
                        <SettingsAssetField
                            kind="badge"
                            label="Emblème du badge"
                            :asset="assets?.badge"
                            :site-code="siteCode"
                            :max-kb="limits.asset_max_kb?.badge ?? 512"
                            :mimes="limits.asset_mimes?.badge ?? 'png,jpg,jpeg,webp'"
                            :readonly="readonly"
                            :shape="form.badge_logo_style === 'LOGO' ? 'wide' : 'square'"
                            :fallback-url="form.badge_logo_style === 'LOGO' ? (defaults.logo_url || defaults.emblem_url) : defaults.emblem_url"
                            fallback-label="De la clinique"
                            compact
                            hide-label
                            @saved="emit('saved')"
                        />
                    </SettingsField>

                    <div v-if="form.badge_logo_style === 'SEAL'" class="space-y-2">
                        <div class="grid gap-6 cq-4xl:grid-cols-2">
                            <SettingsField label="Texte du sceau · en haut" for="reglage-badge_seal_top" :error="field('badge_seal_top').error">
                                <IconInput id="reglage-badge_seal_top" :model-value="form.badge_seal_top" :icon="Tag" :placeholder="design.seal.top || 'CLINIQUE'" :maxlength="BADGE_TEXT_LIMITS.badge_seal_top" :disabled="readonly" @update:model-value="(value) => set('badge_seal_top', value)" />
                            </SettingsField>
                            <SettingsField label="Texte du sceau · en bas" for="reglage-badge_seal_bottom" :error="field('badge_seal_bottom').error">
                                <IconInput id="reglage-badge_seal_bottom" :model-value="form.badge_seal_bottom" :icon="Tag" :placeholder="design.seal.bottom || 'SAINT GEORGES'" :maxlength="BADGE_TEXT_LIMITS.badge_seal_bottom" :disabled="readonly" @update:model-value="(value) => set('badge_seal_bottom', value)" />
                            </SettingsField>
                        </div>
                        <p class="text-[0.8rem] leading-5 text-muted-foreground">Écrit en capitales autour de l’emblème. Laissez les deux vides : le nom de l’établissement, coupé après le premier mot.</p>
                    </div>
                </TabsContent>

                <!-- 2 · Textes -->
                <TabsContent value="textes" class="space-y-6">
                    <SettingsField v-for="text in TEXTS" :key="text.field" :label="text.label" :for="`reglage-${text.field}`" :description="`${text.hint} ${BADGE_TEXT_LIMITS[text.field]} caractères au plus.`" :error="field(text.field).error">
                        <IconInput
                            :id="`reglage-${text.field}`"
                            :model-value="form[text.field]"
                            :icon="text.icon"
                            :placeholder="text.placeholder"
                            :maxlength="BADGE_TEXT_LIMITS[text.field]"
                            :disabled="readonly"
                            @update:model-value="(value) => set(text.field, value)"
                        />
                    </SettingsField>
                    <p class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-[0.8rem] leading-5 text-muted-foreground">
                        Le nom, la photo, le service, la fonction et le numéro se lisent dans le dossier de chaque personne : ils ne se saisissent jamais ici.
                    </p>
                </TabsContent>

                <!-- 3 · Éléments affichés -->
                <TabsContent value="elements" class="space-y-6">
                    <SettingsField label="Ce que le badge affiche" description="Le nom s’affiche toujours. Un stagiaire garde sa mention, même sans le service.">
                        <div class="grid divide-y divide-border rounded-lg border border-border cq-4xl:grid-cols-2 cq-4xl:divide-y-0">
                            <label
                                v-for="(item, index) in DISPLAY"
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

                    <SettingsField label="Icône du médaillon" for="reglage-badge_icon" description="Automatique : stéthoscope pour un médecin, bouclier pour un gardien, fiole au laboratoire… Sinon, la même icône pour tout le monde." :error="field('badge_icon').error">
                        <Select id="reglage-badge_icon" :model-value="form.badge_icon" :options="iconOptions" class="w-full max-w-md" :disabled="readonly || ! form.badge_show_icon" @update:model-value="(value) => set('badge_icon', value || 'AUTO')">
                            <template #leading="{ option }">
                                <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full text-white" :style="{ backgroundColor: design.primary }" aria-hidden="true"><component :is="iconFor(option.value)" class="h-3.5 w-3.5" /></span>
                            </template>
                        </Select>
                    </SettingsField>
                </TabsContent>

                <!-- 4 · Polices & tailles -->
                <TabsContent value="polices" class="space-y-8">
                    <div class="grid gap-6 cq-4xl:grid-cols-2">
                        <SettingsField label="Police du badge" for="reglage-badge_font" description="Le nom, le service, la fonction et le pied." :error="field('badge_font').error">
                            <Select id="reglage-badge_font" :model-value="form.badge_font" :options="fontOptions" class="w-full" :disabled="readonly" @update:model-value="(value) => set('badge_font', value || 'SANS')">
                                <template #leading="{ option }"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-md border border-border bg-muted/50 text-sm font-bold" :style="{ fontFamily: familyOf(option.value, BADGE_FONTS) }" aria-hidden="true">Aa</span></template>
                            </Select>
                        </SettingsField>
                        <SettingsField label="Police de la devise" for="reglage-badge_tagline_font" :error="field('badge_tagline_font').error">
                            <Select id="reglage-badge_tagline_font" :model-value="form.badge_tagline_font" :options="taglineFontOptions" class="w-full" :disabled="readonly || ! form.badge_show_tagline" @update:model-value="(value) => set('badge_tagline_font', value || 'SCRIPT')">
                                <template #leading="{ option }"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-md border border-border bg-muted/50 text-sm font-bold" :style="{ fontFamily: familyOf(option.value, BADGE_TAGLINE_FONTS), fontStyle: BADGE_TAGLINE_FONTS[option.value]?.italic ? 'italic' : 'normal' }" aria-hidden="true">Aa</span></template>
                            </Select>
                        </SettingsField>
                    </div>

                    <div class="grid gap-6 cq-4xl:grid-cols-3">
                        <SettingsField v-for="size in SIZES" :key="size.field" :label="size.label" :for="`reglage-${size.field}`" :description="size.hint" :error="field(size.field).error">
                            <Select :id="`reglage-${size.field}`" :model-value="String(form[size.field])" :options="sizeOptions" class="w-full" :disabled="readonly" @update:model-value="(value) => set(size.field, Number(value || sizeDefault))">
                                <template #leading="{ option }"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-md border border-border bg-muted/50 font-bold leading-none" :style="{ fontSize: `${Math.round(Number(option.value) / 100 * 12)}px` }" aria-hidden="true">A</span></template>
                            </Select>
                        </SettingsField>
                    </div>

                    <div class="grid gap-6 cq-4xl:grid-cols-3">
                        <SettingsField label="Nom de famille" description="En capitales, ou tel qu’écrit au dossier." :error="field('badge_name_case').error">
                            <SegmentedField :model-value="form.badge_name_case" :options="CASE_OPTIONS" :disabled="readonly" aria-label="Nom de famille" @update:model-value="(value) => set('badge_name_case', value)" />
                        </SettingsField>
                        <SettingsField label="Ordre du nom" description="Le premier va sur le bandeau coloré." :error="field('badge_name_order').error">
                            <SegmentedField :model-value="form.badge_name_order" :options="ORDER_OPTIONS" :disabled="readonly" aria-label="Ordre du nom" @update:model-value="(value) => set('badge_name_order', value)" />
                        </SettingsField>
                        <SettingsField label="Service et fonction" description="En capitales, ou tels qu’écrits au référentiel." :error="field('badge_text_case').error">
                            <SegmentedField :model-value="form.badge_text_case" :options="CASE_OPTIONS" :disabled="readonly" aria-label="Service et fonction" @update:model-value="(value) => set('badge_text_case', value)" />
                        </SettingsField>
                    </div>
                </TabsContent>

                <!-- 5 · Mise en page & impression -->
                <TabsContent value="disposition" class="space-y-8">
                    <SettingsField label="Orientation du badge" :description="ORIENTATIONS.find((option) => option.value === form.badge_orientation)?.hint" :error="field('badge_orientation').error">
                        <RadioGroup :model-value="form.badge_orientation" :disabled="readonly" class="grid max-w-sm grid-cols-2 gap-4 pt-1" aria-label="Orientation du badge" @update:model-value="(value) => set('badge_orientation', value)">
                            <Label v-for="option in ORIENTATIONS" :key="option.value" :class="OPTION_CLASS">
                                <RadioGroupItem :value="option.value" class="sr-only" />
                                <div class="rounded-md border-2 border-border bg-card p-1 transition-colors hover:border-primary/40">
                                    <span class="grid h-20 w-full place-items-center rounded-sm bg-muted/60" aria-hidden="true">
                                        <span :class="cn('relative overflow-hidden rounded-[3px] border border-border bg-card shadow-sm', option.value === 'PORTRAIT' ? 'h-16 w-10' : 'h-10 w-16')">
                                            <span class="absolute left-0 top-0 h-3 w-4 rounded-br-full" :style="{ backgroundColor: design.primary }" />
                                            <span :class="cn('absolute rounded-full', option.value === 'PORTRAIT' ? 'left-1/2 top-4 h-5 w-5 -translate-x-1/2' : 'left-1.5 top-2.5 h-5 w-5')" :style="{ backgroundColor: design.accent }" />
                                            <span class="absolute bottom-0 left-0 right-0 h-1.5" :style="{ backgroundColor: design.primary }" />
                                        </span>
                                    </span>
                                </div>
                                <span class="flex w-full items-center justify-center gap-1.5 p-2 text-sm font-normal"><component :is="option.icon" class="h-4 w-4" aria-hidden="true" />{{ option.label }}</span>
                            </Label>
                        </RadioGroup>
                    </SettingsField>

                    <div class="grid gap-6 cq-4xl:grid-cols-3">
                        <SettingsField label="Taille de la carte" for="reglage-badge_card_size" description="Le rapport de la carte bancaire est toujours gardé." :error="field('badge_card_size').error">
                            <Select id="reglage-badge_card_size" :model-value="form.badge_card_size" :options="cardSizeOptions" :icon="Maximize2" class="w-full" :disabled="readonly" @update:model-value="(value) => set('badge_card_size', value || 'STANDARD')" />
                        </SettingsField>
                        <SettingsField label="Photo" :error="field('badge_photo_shape').error">
                            <SegmentedField :model-value="form.badge_photo_shape" :options="PHOTO_OPTIONS" :disabled="readonly" aria-label="Forme de la photo" @update:model-value="(value) => set('badge_photo_shape', value)" />
                        </SettingsField>
                        <SettingsField label="Coins de la carte" description="Droits : pour une découpe au massicot." :error="field('badge_corners').error">
                            <SegmentedField :model-value="form.badge_corners" :options="CORNER_OPTIONS" :disabled="readonly" aria-label="Coins de la carte" @update:model-value="(value) => set('badge_corners', value)" />
                        </SettingsField>
                    </div>

                    <div class="space-y-6 rounded-lg border border-border p-4">
                        <div class="flex items-start gap-3">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-primary/10 text-primary"><Printer class="h-4 w-4" aria-hidden="true" /></span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-foreground">Impression</p>
                                <p class="text-[0.8rem] leading-5 text-muted-foreground">Ce que la page d’impression propose d’abord. Le RH peut le changer pour une impression.</p>
                            </div>
                        </div>

                        <div class="grid gap-6 cq-4xl:grid-cols-2">
                            <SettingsField label="Papier" for="reglage-badge_paper" :error="field('badge_paper').error">
                                <Select id="reglage-badge_paper" :model-value="form.badge_paper" :options="paperOptions" :icon="Shapes" class="w-full" :disabled="readonly" @update:model-value="(value) => set('badge_paper', value || 'A4')" />
                            </SettingsField>
                            <SettingsField label="Orientation de la page" :error="field('badge_paper_orientation').error">
                                <SegmentedField :model-value="form.badge_paper_orientation" :options="PAGE_ORIENTATIONS" :disabled="readonly || isCardPaper" aria-label="Orientation de la page" @update:model-value="(value) => set('badge_paper_orientation', value)" />
                            </SettingsField>
                            <SettingsField label="Marges de la page" for="reglage-badge_page_margin" :description="`De ${BADGE_NUMBERS.badge_page_margin[0]} à ${BADGE_NUMBERS.badge_page_margin[1]} mm, autour de la planche.`" :error="field('badge_page_margin').error">
                                <div class="flex items-center gap-2">
                                    <Input id="reglage-badge_page_margin" type="number" inputmode="numeric" :min="BADGE_NUMBERS.badge_page_margin[0]" :max="BADGE_NUMBERS.badge_page_margin[1]" step="1" :model-value="form.badge_page_margin" class="w-24" :disabled="readonly || isCardPaper" :aria-invalid="field('badge_page_margin').invalid || undefined" @update:model-value="(value) => numberInput('badge_page_margin', value)" />
                                    <span class="text-sm text-muted-foreground">mm</span>
                                </div>
                            </SettingsField>
                            <SettingsField label="Espace entre les cartes" for="reglage-badge_gap" :description="`De ${BADGE_NUMBERS.badge_gap[0]} à ${BADGE_NUMBERS.badge_gap[1]} mm, pour glisser la lame.`" :error="field('badge_gap').error">
                                <div class="flex items-center gap-2">
                                    <Input id="reglage-badge_gap" type="number" inputmode="numeric" :min="BADGE_NUMBERS.badge_gap[0]" :max="BADGE_NUMBERS.badge_gap[1]" step="1" :model-value="form.badge_gap" class="w-24" :disabled="readonly || isCardPaper" :aria-invalid="field('badge_gap').invalid || undefined" @update:model-value="(value) => numberInput('badge_gap', value)" />
                                    <span class="text-sm text-muted-foreground">mm</span>
                                </div>
                            </SettingsField>
                        </div>

                        <label for="reglage-badge_cut_marks" :class="cn('flex items-center gap-3 rounded-lg border border-border px-4 py-3', readonly ? 'cursor-not-allowed' : 'cursor-pointer')">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-primary/10 text-primary"><Scissors class="h-4 w-4" aria-hidden="true" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-foreground">Traits de coupe</span>
                                <span class="block text-[0.8rem] text-muted-foreground">Un pointillé autour de chaque carte, pour découper droit.</span>
                            </span>
                            <Switch id="reglage-badge_cut_marks" :model-value="form.badge_cut_marks" :disabled="readonly" @update:model-value="(value) => set('badge_cut_marks', value)" />
                        </label>

                        <!-- La planche, en schéma : ce que donnera une page. -->
                        <div class="flex flex-wrap items-center gap-4">
                            <svg
                                :viewBox="`0 0 ${sheet.page.width} ${sheet.page.height}`"
                                :class="cn('shrink-0 rounded-sm border border-border bg-white shadow-sm', sheet.page.width > sheet.page.height ? 'h-24 w-auto' : 'h-32 w-auto')"
                                role="img"
                                :aria-label="`Schéma de la page : ${sheet.perPage} carte${sheet.perPage > 1 ? 's' : ''}`"
                            >
                                <rect v-if="sheet.mode === 'sheet'" :x="sheet.margin" :y="sheet.margin" :width="Math.max(0, sheet.page.width - 2 * sheet.margin)" :height="Math.max(0, sheet.page.height - 2 * sheet.margin)" fill="none" stroke="#CBD5E1" stroke-width="0.6" stroke-dasharray="2 2" />
                                <rect
                                    v-for="(card, index) in (sheet.mode === 'card' ? [{ x: 0, y: 0 }] : sheetCards)"
                                    :key="index"
                                    :x="card.x"
                                    :y="card.y"
                                    :width="sheet.card.width"
                                    :height="sheet.card.height"
                                    :rx="form.badge_corners === 'SQUARE' ? 0 : 3"
                                    :fill="design.primary"
                                    fill-opacity="0.18"
                                    :stroke="design.primary"
                                    stroke-width="0.8"
                                />
                            </svg>
                            <div class="min-w-0 space-y-1 text-sm">
                                <p v-if="sheet.mode === 'card'" class="font-medium text-foreground">Une carte par page · {{ formatMm(sheet.card) }}</p>
                                <p v-else-if="sheet.fits" class="font-medium text-foreground">{{ sheet.perPage }} badge{{ sheet.perPage > 1 ? 's' : '' }} par page · {{ sheet.columns }} × {{ sheet.rows }}</p>
                                <p v-else class="flex items-start gap-1.5 font-medium text-destructive" role="alert"><TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />La carte ne tient pas sur la page : réduisez les marges ou la taille, ou changez de papier.</p>
                                <p class="text-[0.8rem] text-muted-foreground">Carte {{ formatMm(sheet.card) }}<template v-if="sheet.mode === 'sheet'"> sur {{ BADGE_PAPER_LABELS[form.badge_paper] }} {{ form.badge_paper_orientation === 'LANDSCAPE' ? 'paysage' : 'portrait' }} ({{ formatMm(sheet.page) }})</template>. À imprimer à 100 %.</p>
                            </div>
                        </div>
                    </div>
                </TabsContent>
            </Tabs>

            <!-- L'aperçu : le badge tel qu'il s'imprimera. -->
            <aside class="min-w-0 cq-2xl:sticky cq-2xl:top-24 cq-2xl:self-start" aria-label="Aperçu du badge">
                <p class="flex items-center gap-1.5 text-sm font-medium text-foreground"><Eye class="h-4 w-4 text-muted-foreground" aria-hidden="true" />Aperçu</p>
                <div class="mt-2 inline-flex rounded-lg border border-border bg-muted/40 p-1" role="radiogroup" aria-label="Personne de l’aperçu">
                    <button
                        v-for="option in [{ value: 'employee', label: 'Employé', icon: UserRound }, { value: 'intern', label: 'Stagiaire', icon: GraduationCap }]"
                        :key="option.value"
                        type="button"
                        role="radio"
                        :aria-checked="sample === option.value"
                        :class="cn('inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', sample === option.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')"
                        @click="sample = option.value"
                    ><component :is="option.icon" class="h-3.5 w-3.5" aria-hidden="true" />{{ option.label }}</button>
                </div>
                <div :class="cn('mx-auto mt-3 w-full overflow-hidden shadow-[0_12px_30px_-14px_rgb(15_23_42/0.45)]', landscape ? 'max-w-[17rem]' : 'max-w-[15rem]', form.badge_corners === 'SQUARE' ? 'rounded-none' : 'rounded-xl')">
                    <EmployeeBadge :person="person" :design="design" />
                </div>
                <p class="mt-3 flex items-center gap-1.5 text-[0.8rem] text-muted-foreground"><Droplets class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Carte {{ formatMm(sheet.card) }}</p>
                <p class="mt-1 text-[0.8rem] leading-5 text-muted-foreground">Personne fictive. Le badge réel prend la photo, le nom, le service et la fonction du dossier ; s’imprime depuis la liste des employés, des stages ou la fiche.</p>
            </aside>
        </div>
    </SettingsSection>
</template>
