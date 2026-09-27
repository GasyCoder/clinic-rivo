<script setup>
import { computed, useId } from 'vue';
import '@fontsource/dancing-script/700.css';
import {
    BADGE_DEFAULT_DESIGN, BADGE_FONTS, BADGE_GEOMETRY, BADGE_TAGLINE_FONTS, badgeFooter, badgeIcon, badgeNameLines,
    badgeNameRows, badgePalette, badgeRole, crossPath, fitFontSize, fitLine, sealArc, splitTagline,
} from '@/utilities/employeeBadge';

/**
 * ADR-209 — le badge du personnel, dessiné en SVG au format carte (54 × 85,6 mm,
 * en portrait ou en paysage) : net à l'écran comme à l'impression, sans image de
 * fond à charger.
 *
 * Un seul modèle pour tout le personnel — médecin, infirmier, gardien, RH… —
 * et pour un stagiaire, qui porte « Stagiaire » et la fin de son stage. Tout ce
 * qui se règle (Paramètres › Badge du personnel) arrive dans `design` ; tout ce
 * qui est montré de la personne est lu dans le dossier : rien ne se saisit ici.
 * Les deux orientations partagent ce même dessin : seule change la place de
 * chaque élément (`BADGE_GEOMETRY`).
 */
const props = defineProps({
    /** Ce que `EmployeeBadges` sert pour une personne. */
    person: { type: Object, required: true },
    /** Ce que `BadgeDesign` sert pour le site ; vide, le modèle de la clinique. */
    design: { type: Object, default: () => ({}) },
});

// Plusieurs badges sur une même planche : chacun ses identifiants de dégradés et de découpes.
const uid = `badge-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;
const sid = (name) => `${uid}-${name}`;

const d = computed(() => ({
    ...BADGE_DEFAULT_DESIGN,
    ...Object.fromEntries(Object.entries(props.design ?? {}).filter(([, value]) => value !== null && value !== undefined)),
}));
const g = computed(() => BADGE_GEOMETRY[d.value.orientation] ?? BADGE_GEOMETRY.PORTRAIT);
const colors = computed(() => badgePalette(d.value));
const radius = computed(() => (d.value.corners === 'SQUARE' ? 0 : g.value.radius));
const upperText = computed(() => d.value.text_case !== 'AS_IS');
const upper = (text) => String(text ?? '').toLocaleUpperCase('fr-FR');
/** Un texte tout en capitales est plus large qu'un texte courant. */
const ratioOf = (text, caps, mixed) => (text && text === upper(text) ? caps : mixed);

const font = computed(() => BADGE_FONTS[d.value.font]?.family ?? BADGE_FONTS.SANS.family);
const taglineStyle = computed(() => {
    const choice = BADGE_TAGLINE_FONTS[d.value.tagline_font] ?? BADGE_TAGLINE_FONTS.SCRIPT;

    return { family: choice.family ?? font.value, italic: choice.italic, script: d.value.tagline_font !== 'SANS' && d.value.tagline_font !== 'SERIF' };
});

/* --- La personne -------------------------------------------------- */

const names = computed(() => badgeNameRows(props.person, d.value));
const role = computed(() => badgeRole(props.person, d.value));
const icon = computed(() => badgeIcon(props.person, d.value));
const footer = computed(() => badgeFooter(props.person, d.value));
const photoUrl = computed(() => (d.value.show_photo ? props.person?.photo_url : null));
const initials = computed(() => {
    const { first, last } = badgeNameLines(props.person, { name_case: 'UPPER' });

    return `${first?.[0] ?? ''}${last?.[0] ?? ''}`.toLocaleUpperCase('fr-FR') || '?';
});

/* --- La devise ---------------------------------------------------- */

const taglineLines = computed(() => (d.value.show_tagline ? splitTagline(d.value.tagline) : []));
const taglineSize = computed(() => {
    const T = g.value.tagline;
    const ratio = taglineStyle.value.script ? 0.46 : 0.55;

    return Math.min(...taglineLines.value.map((line) => fitLine(line, { max: T.max, min: T.min, width: T.width, ratio, percent: d.value.tagline_size, cap: 46 }).size));
});
const taglineRotate = computed(() => `rotate(${g.value.tagline.rotate} ${g.value.tagline.cx} ${g.value.tagline.y + 12})`);
/** Le trait de pinceau sous la dernière ligne de la devise. */
const underline = computed(() => {
    const T = g.value.tagline;
    const y = T.y + (taglineLines.value.length - 1) * taglineSize.value * 1.05 + 18;
    const at = (share) => Math.round(T.cx + share * T.width);

    return `M${at(-0.39)} ${y + 8} C${at(-0.12)} ${y - 2} ${at(0.21)} ${y - 4} ${at(0.47)} ${y - 12}`;
});

/* --- Le nom, le service et la fonction ---------------------------- */

const bandFit = computed(() => {
    const B = g.value.name.band;

    return fitLine(names.value.band, { max: B.max, min: B.min, width: B.fit, ratio: ratioOf(names.value.band, 0.7, 0.58), percent: d.value.name_size, cap: B.height * 0.78 });
});
const mainFit = computed(() => {
    const M = g.value.name.main;

    return fitLine(names.value.main, { max: M.max, min: M.min, width: M.fit, ratio: ratioOf(names.value.main, 0.7, 0.58), percent: d.value.name_size, cap: M.height * 0.78 });
});

const pillText = computed(() => (upperText.value ? upper(role.value.pill) : role.value.pill));
const pillFit = computed(() => {
    const P = g.value.pill;

    return fitLine(pillText.value, { max: P.max, min: P.min, width: P.fit, ratio: ratioOf(pillText.value, 0.7, 0.58), percent: d.value.text_size, cap: P.height * 0.7 });
});
const pillWidth = computed(() => {
    const P = g.value.pill;
    const text = pillFit.value.length ?? [...pillText.value].length * pillFit.value.size * ratioOf(pillText.value, 0.7, 0.58);

    return Math.min(P.maxWidth, Math.max(P.minWidth, text + 64));
});

const lineText = computed(() => (upperText.value ? upper(role.value.line) : role.value.line));
const lineFit = computed(() => {
    const J = g.value.job;

    return fitLine(lineText.value, { max: J.max, min: J.min, width: J.fit, ratio: upperText.value ? 0.82 : 0.62, percent: d.value.text_size, cap: 34 });
});

const footerFit = computed(() => fitLine(footer.value, { max: g.value.wave.size, min: 11, width: g.value.wave.fit, ratio: 0.66, percent: d.value.text_size, cap: 24 }));

/* --- L'établissement ---------------------------------------------- */

const sealKind = computed(() => (['LOGO', 'NONE'].includes(d.value.logo_style) ? d.value.logo_style : 'SEAL'));
const seal = computed(() => d.value.seal ?? BADGE_DEFAULT_DESIGN.seal);
const S = computed(() => {
    const { cx, cy, scale: k } = g.value.seal;

    return {
        cx,
        cy,
        k,
        topSize: fitFontSize(seal.value.top, { max: 24 * k, min: 11 * k, width: 170 * k, ratio: 0.72 }),
        bottomSize: fitFontSize(seal.value.bottom, { max: 22 * k, min: 10 * k, width: 230 * k, ratio: 0.72 }),
        topArc: sealArc(cx, cy, 70 * k, true),
        bottomArc: sealArc(cx, cy, 84 * k, false),
    };
});
const siteText = computed(() => (d.value.show_site && d.value.site ? upper(d.value.site) : ''));
const siteFit = computed(() => fitLine(siteText.value, { max: 18, min: 10, width: g.value.site.width, ratio: 0.68, percent: d.value.text_size, cap: 22 }));

/* --- La photo et le médaillon ------------------------------------- */

const P = computed(() => {
    const { cx, cy, r } = g.value.photo;
    const f = r / 140;

    return { cx, cy, r, f, rounded: d.value.photo_shape === 'ROUNDED' };
});
/** Les cadres carrés arrondis autour d'une photo « arrondie » : les deux anneaux, puis le blanc. */
const frames = computed(() => Object.fromEntries(Object.entries({ outer: 1.16, inner: 1.09, white: 1.045 }).map(([key, grow]) => {
    const half = P.value.r * grow;

    return [key, { x: P.value.cx - half, y: P.value.cy - half, size: half * 2, rx: P.value.r * 0.3 + (half - P.value.r) }];
})));
const medallion = computed(() => {
    const { cx, cy, r } = g.value.medallion;
    const size = r * 0.964;

    return { cx, cy, r, inner: r * 0.875, stroke: 5 * (r / 56), size, x: cx - size / 2, y: cy - size / 2 };
});

const ariaLabel = computed(() => [
    `Badge de ${props.person?.name || `${names.value.band} ${names.value.main}`.trim()}`,
    role.value.pill, role.value.line, footer.value,
].filter(Boolean).join(', '));
</script>

<template>
    <svg
        class="employee-badge block h-auto w-full select-none"
        :viewBox="`0 0 ${g.width} ${g.height}`"
        :data-orientation="d.orientation"
        xmlns="http://www.w3.org/2000/svg"
        role="img"
        :aria-label="ariaLabel"
        :font-family="font"
    >
        <defs>
            <linearGradient :id="sid('paper')" x1="0" y1="0" x2="0.4" y2="1">
                <stop offset="0" :stop-color="colors.paper" />
                <stop offset="0.55" :stop-color="colors.paperMiddle" />
                <stop offset="1" :stop-color="colors.paperEnd" />
            </linearGradient>
            <clipPath :id="sid('card')"><rect :width="g.width" :height="g.height" :rx="radius" /></clipPath>
            <clipPath :id="sid('photo')">
                <rect v-if="P.rounded" :x="P.cx - P.r" :y="P.cy - P.r" :width="P.r * 2" :height="P.r * 2" :rx="P.r * 0.3" />
                <circle v-else :cx="P.cx" :cy="P.cy" :r="P.r" />
            </clipPath>
            <clipPath :id="sid('emblem')"><circle :cx="S.cx" :cy="S.cy" :r="55 * S.k" /></clipPath>
            <filter :id="sid('shadow')" x="-10%" y="-20%" width="120%" height="160%">
                <feDropShadow dx="0" dy="4" stdDeviation="5" :flood-color="colors.ink" flood-opacity="0.18" />
            </filter>
            <path :id="sid('seal-top')" :d="S.topArc" />
            <path :id="sid('seal-bottom')" :d="S.bottomArc" />
        </defs>

        <g :clip-path="`url(#${sid('card')})`">
            <rect :width="g.width" :height="g.height" :fill="`url(#${sid('paper')})`" />

            <!-- Filigrane : l'emblème, pâle, derrière la photo. -->
            <image v-if="d.show_watermark" :href="d.emblem_url" :x="g.watermark.x" :y="g.watermark.y" :width="g.watermark.size" :height="g.watermark.size" opacity="0.07" preserveAspectRatio="xMidYMid meet" />
            <template v-if="d.show_decorations">
                <circle :cx="g.watermark.cx" :cy="g.watermark.cy" r="150" fill="none" :stroke="colors.primary" stroke-width="22" opacity="0.05" />
                <circle :cx="g.watermark.cx" :cy="g.watermark.cy" r="100" fill="none" :stroke="colors.primary" stroke-width="16" opacity="0.05" />
            </template>

            <!-- Les bandes du coin supérieur gauche. -->
            <path :d="g.bands.primary" :fill="colors.primary" />
            <path :d="g.bands.accent" :fill="colors.accent" />
            <template v-if="d.show_decorations">
                <path :d="g.bands.line" fill="none" :stroke="colors.primary" stroke-width="5" opacity="0.55" />
                <path :d="g.bands.pale" :fill="colors.primary" opacity="0.1" />

                <!-- Points décoratifs. -->
                <g :fill="colors.primary" opacity="0.18">
                    <template v-for="row in 4" :key="`r${row}`">
                        <circle v-for="col in 4" :key="`c${row}-${col}`" :cx="g.dots.x + col * 16" :cy="g.dots.y + row * 16" r="3.5" />
                    </template>
                </g>
            </template>

            <!-- La devise. -->
            <g v-if="taglineLines.length" :transform="taglineRotate">
                <text
                    v-for="(line, index) in taglineLines"
                    :key="index"
                    :x="g.tagline.cx"
                    :y="g.tagline.y + index * taglineSize * 1.05"
                    text-anchor="middle"
                    :font-family="taglineStyle.family"
                    :font-style="taglineStyle.italic ? 'italic' : undefined"
                    font-weight="700"
                    :font-size="taglineSize"
                    :fill="colors.primary"
                >{{ line }}</text>
                <path :d="underline" fill="none" :stroke="colors.accent" stroke-width="6" stroke-linecap="round" />
            </g>

            <!-- L'établissement : le sceau, le logo tel quel, ou rien. -->
            <g v-if="sealKind === 'SEAL'">
                <circle :cx="S.cx" :cy="S.cy" :r="99 * S.k" fill="#FFFFFF" />
                <circle :cx="S.cx" :cy="S.cy" :r="94 * S.k" :fill="colors.primary" :stroke="colors.accent" :stroke-width="5 * S.k" />
                <circle :cx="S.cx" :cy="S.cy" :r="58 * S.k" fill="#FFFFFF" :stroke="colors.accent" :stroke-width="4 * S.k" />
                <image :href="d.emblem_url" :x="S.cx - 55 * S.k" :y="S.cy - 55 * S.k" :width="110 * S.k" :height="110 * S.k" preserveAspectRatio="xMidYMid meet" :clip-path="`url(#${sid('emblem')})`" />
                <text :font-size="S.topSize" font-weight="900" :fill="colors.accent" letter-spacing="1.5">
                    <textPath :href="`#${sid('seal-top')}`" startOffset="50%" text-anchor="middle">{{ seal.top }}</textPath>
                </text>
                <text :font-size="S.bottomSize" font-weight="900" :fill="colors.accent" letter-spacing="1">
                    <textPath :href="`#${sid('seal-bottom')}`" startOffset="50%" text-anchor="middle">{{ seal.bottom }}</textPath>
                </text>
                <template v-if="seal.bottom">
                    <g v-for="side in [-1, 1]" :key="side">
                        <circle :cx="S.cx + side * 76 * S.k" :cy="S.cy" :r="10 * S.k" fill="#FFFFFF" :stroke="colors.accent" :stroke-width="2 * S.k" />
                        <path
                            :d="`M${S.cx + side * 76 * S.k - 5 * S.k} ${S.cy} H${S.cx + side * 76 * S.k + 5 * S.k} M${S.cx + side * 76 * S.k} ${S.cy - 5 * S.k} V${S.cy + 5 * S.k}`"
                            :stroke="colors.primary"
                            :stroke-width="3 * S.k"
                            stroke-linecap="round"
                        />
                    </g>
                </template>
            </g>
            <g v-else-if="sealKind === 'LOGO'">
                <rect :x="g.logo.x" :y="g.logo.y" :width="g.logo.width" :height="g.logo.height" rx="18" fill="#FFFFFF" :stroke="colors.light" stroke-width="2" />
                <image :href="d.emblem_url" :x="g.logo.x + 14" :y="g.logo.y + 12" :width="g.logo.width - 28" :height="g.logo.height - 24" preserveAspectRatio="xMidYMid meet" />
            </g>
            <text
                v-if="siteText"
                :x="g.site.x"
                :y="g.site.y"
                text-anchor="middle"
                :font-size="siteFit.size"
                font-weight="800"
                :fill="colors.primary"
                letter-spacing="1.5"
                :textLength="siteFit.length ?? undefined"
                :lengthAdjust="siteFit.length ? 'spacingAndGlyphs' : undefined"
            >{{ siteText }}</text>

            <!-- La photo, dans ses anneaux (ou son cadre arrondi). -->
            <template v-if="P.rounded">
                <rect :x="frames.outer.x" :y="frames.outer.y" :width="frames.outer.size" :height="frames.outer.size" :rx="frames.outer.rx" fill="none" :stroke="colors.primary" :stroke-width="12 * P.f" pathLength="100" stroke-dasharray="62 38" stroke-dashoffset="20" stroke-linecap="round" />
                <rect :x="frames.inner.x" :y="frames.inner.y" :width="frames.inner.size" :height="frames.inner.size" :rx="frames.inner.rx" fill="none" :stroke="colors.accent" :stroke-width="7 * P.f" pathLength="100" stroke-dasharray="56 44" stroke-dashoffset="-30" stroke-linecap="round" />
                <rect :x="frames.white.x" :y="frames.white.y" :width="frames.white.size" :height="frames.white.size" :rx="frames.white.rx" fill="#FFFFFF" />
                <rect :x="P.cx - P.r" :y="P.cy - P.r" :width="P.r * 2" :height="P.r * 2" :rx="P.r * 0.3" :fill="colors.light" />
            </template>
            <template v-else>
                <circle :cx="P.cx" :cy="P.cy" :r="P.r * 1.2143" fill="none" :stroke="colors.primary" :stroke-width="12 * P.f" pathLength="100" stroke-dasharray="62 38" :transform="`rotate(118 ${P.cx} ${P.cy})`" stroke-linecap="round" />
                <circle :cx="P.cx" :cy="P.cy" :r="P.r * 1.1214" fill="none" :stroke="colors.accent" :stroke-width="7 * P.f" pathLength="100" stroke-dasharray="56 44" :transform="`rotate(170 ${P.cx} ${P.cy})`" stroke-linecap="round" />
                <circle :cx="P.cx" :cy="P.cy" :r="P.r * 1.0571" fill="#FFFFFF" />
                <circle :cx="P.cx" :cy="P.cy" :r="P.r" :fill="colors.light" />
            </template>
            <image
                v-if="photoUrl"
                :href="photoUrl"
                :x="P.cx - P.r"
                :y="P.cy - P.r"
                :width="P.r * 2"
                :height="P.r * 2"
                preserveAspectRatio="xMidYMid slice"
                :clip-path="`url(#${sid('photo')})`"
            />
            <text v-else :x="P.cx" :y="P.cy" text-anchor="middle" dominant-baseline="central" :font-size="P.r * 0.786" font-weight="900" :fill="colors.primary" opacity="0.55">{{ initials }}</text>

            <!-- Le métier, en médaillon. -->
            <g v-if="d.show_icon">
                <circle :cx="medallion.cx" :cy="medallion.cy" :r="medallion.r" fill="#FFFFFF" />
                <circle :cx="medallion.cx" :cy="medallion.cy" :r="medallion.inner" :fill="colors.primary" :stroke="colors.accent" :stroke-width="medallion.stroke" />
                <component :is="icon" :x="medallion.x" :y="medallion.y" :size="medallion.size" color="#FFFFFF" :stroke-width="1.8" aria-hidden="true" />
            </g>

            <!-- Le nom : le bandeau coloré, puis la grande ligne blanche. -->
            <path :d="g.name.tabs[0]" :fill="colors.accent" />
            <path :d="g.name.tabs[1]" :fill="colors.accent" />
            <template v-if="names.band">
                <rect :x="g.name.band.x" :y="g.name.band.y" :width="g.name.band.width" :height="g.name.band.height" rx="18" :fill="colors.primary" />
                <text
                    :x="g.name.cx"
                    :y="g.name.band.textY"
                    text-anchor="middle"
                    dominant-baseline="central"
                    :font-size="bandFit.size"
                    font-weight="800"
                    fill="#FFFFFF"
                    :textLength="bandFit.length ?? undefined"
                    :lengthAdjust="bandFit.length ? 'spacingAndGlyphs' : undefined"
                >{{ names.band }}</text>
            </template>
            <rect :x="g.name.main.x" :y="g.name.main.y" :width="g.name.main.width" :height="g.name.main.height" rx="16" fill="#FFFFFF" :filter="`url(#${sid('shadow')})`" />
            <text
                :x="g.name.cx"
                :y="g.name.main.textY"
                text-anchor="middle"
                dominant-baseline="central"
                :font-size="mainFit.size"
                font-weight="900"
                :fill="colors.ink"
                letter-spacing="0.5"
                :textLength="mainFit.length ?? undefined"
                :lengthAdjust="mainFit.length ? 'spacingAndGlyphs' : undefined"
            >{{ names.main }}</text>

            <!-- Le service (ou « Stagiaire »), en pastille. -->
            <g v-if="pillText">
                <line :x1="g.pill.lineFrom" :y1="g.pill.cy" :x2="g.pill.cx - pillWidth / 2 - 14" :y2="g.pill.cy" :stroke="colors.primary" stroke-width="3" stroke-linecap="round" />
                <line :x1="g.pill.cx + pillWidth / 2 + 14" :y1="g.pill.cy" :x2="g.pill.lineTo" :y2="g.pill.cy" :stroke="colors.primary" stroke-width="3" stroke-linecap="round" />
                <rect :x="g.pill.cx - pillWidth / 2" :y="g.pill.cy - g.pill.height / 2" :width="pillWidth" :height="g.pill.height" :rx="g.pill.height / 2" :fill="colors.accent" />
                <text
                    :x="g.pill.cx"
                    :y="g.pill.cy + 1"
                    text-anchor="middle"
                    dominant-baseline="central"
                    :font-size="pillFit.size"
                    font-weight="900"
                    :fill="colors.ink"
                    letter-spacing="1"
                    :textLength="pillFit.length ?? undefined"
                    :lengthAdjust="pillFit.length ? 'spacingAndGlyphs' : undefined"
                >{{ pillText }}</text>
            </g>

            <!-- La fonction (ou la filière d'un stagiaire). -->
            <g v-if="lineText">
                <line :x1="g.job.lines[0][0]" :y1="g.job.ornamentY" :x2="g.job.lines[0][1]" :y2="g.job.ornamentY" :stroke="colors.primary" stroke-width="2.5" stroke-linecap="round" />
                <line :x1="g.job.lines[1][0]" :y1="g.job.ornamentY" :x2="g.job.lines[1][1]" :y2="g.job.ornamentY" :stroke="colors.primary" stroke-width="2.5" stroke-linecap="round" />
                <path :d="crossPath(g.job.cx, g.job.ornamentY)" :fill="colors.primary" />
                <text
                    :x="g.job.cx"
                    :y="g.job.y"
                    text-anchor="middle"
                    dominant-baseline="central"
                    :font-size="lineFit.size"
                    font-weight="700"
                    :fill="colors.ink"
                    :letter-spacing="upperText ? 4 : 1"
                    :textLength="lineFit.length ?? undefined"
                    :lengthAdjust="lineFit.length ? 'spacingAndGlyphs' : undefined"
                >{{ lineText }}</text>
            </g>

            <!-- Les vagues du bas, et le pied du badge. -->
            <path :d="g.wave.fill" :fill="colors.primary" />
            <path :d="g.wave.line" fill="none" :stroke="colors.accent" stroke-width="7" />
            <text
                v-if="footer"
                :x="g.wave.cx"
                :y="g.wave.y"
                text-anchor="middle"
                dominant-baseline="central"
                :font-size="footerFit.size"
                font-weight="700"
                fill="#FFFFFF"
                letter-spacing="1.5"
                :textLength="footerFit.length ?? undefined"
                :lengthAdjust="footerFit.length ? 'spacingAndGlyphs' : undefined"
            >{{ footer }}</text>
        </g>
    </svg>
</template>
