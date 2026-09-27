/**
 * ADR-191 — le thème d'un site, calculé dans le navigateur pour l'aperçu.
 *
 * Le même calcul que App\Services\Settings\ThemePalette (et ThemeColor) côté
 * serveur, qui reste la seule source de ce qui s'applique réellement : ici, il
 * ne sert qu'à montrer le thème pendant qu'on le règle. Un test compare les deux
 * sur les couleurs d'origine et un préréglage.
 */

export const ORIGIN = Object.freeze({
    light: { primary: '#287d9f', background: '#f5f7fa', foreground: '#243852' },
    dark: { primary: '#4fb3cf', background: '#0e1520', foreground: '#f1f4f8' },
});

export const THEME_FIELDS = Object.freeze({
    light: { primary: 'primary_color', background: 'light_background', foreground: 'light_foreground' },
    dark: { primary: 'dark_primary_color', background: 'dark_background', foreground: 'dark_foreground' },
});

export const COLOR_FIELDS = Object.freeze(Object.values(THEME_FIELDS).flatMap((mode) => Object.values(mode)));

export const MIN_TEXT_CONTRAST = 4.5;

const DARK_TEXT = [215, 40, 10];

const CONTRAST = {
    light: { standard: [7, 9, 0.35], high: [16, 19, 0.22], max: [30, 33, 0.1] },
    dark: { standard: [12, 15, 0.35], high: [22, 26, 0.22], max: [35, 38, 0.1] },
};

export const isHex = (value) => /^#[0-9a-fA-F]{6}$/.test(String(value ?? '').trim());

export const hexToHsl = (hex) => {
    const [r, g, b] = [1, 3, 5].map((index) => parseInt(hex.slice(index, index + 2), 16) / 255);
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const lightness = (max + min) / 2;
    const delta = max - min;

    if (delta === 0) return { h: 0, s: 0, l: lightness * 100 };

    const saturation = delta / (1 - Math.abs(2 * lightness - 1));
    let hue;
    if (max === r) hue = 60 * ((((g - b) / delta) % 6));
    else if (max === g) hue = 60 * ((b - r) / delta + 2);
    else hue = 60 * ((r - g) / delta + 4);

    return { h: hue < 0 ? hue + 360 : hue, s: saturation * 100, l: lightness * 100 };
};

const clamp = (value) => Math.max(0, Math.min(100, value));

/** Le triplet HSL tel que les variables de l'interface l'attendent (`197 60% 39%`). */
export const format = (h, s, l) => `${Math.round(h)} ${Math.round(s)}% ${Math.round(l)}%`;

/** Un triplet `197 60% 39%` en code #RRVVBB — pour montrer une couleur déduite, qui n'a pas de code saisi. */
export const tripletToHex = (triplet) => {
    const [h, s, l] = String(triplet ?? '').replace(/%/g, '').trim().split(/\s+/).map(Number);
    if ([h, s, l].some((value) => Number.isNaN(value))) return '';

    const sat = s / 100;
    const light = l / 100;
    const chroma = (1 - Math.abs(2 * light - 1)) * sat;
    const x = chroma * (1 - Math.abs(((h / 60) % 2) - 1));
    const m = light - chroma / 2;
    const [r, g, b] = h < 60 ? [chroma, x, 0] : h < 120 ? [x, chroma, 0] : h < 180 ? [0, chroma, x] : h < 240 ? [0, x, chroma] : h < 300 ? [x, 0, chroma] : [chroma, 0, x];

    return `#${[r, g, b].map((value) => Math.round((value + m) * 255).toString(16).padStart(2, '0')).join('')}`.toUpperCase();
};

const bounded = (h, s, l) => `${Math.round(((h % 360) + 360) % 360)} ${Math.round(clamp(s))}% ${Math.round(clamp(l))}%`;

/** Luminance relative WCAG d'une couleur HSL (même formule que le serveur). */
const luminance = (h, s, l) => {
    const sat = s / 100;
    const lig = l / 100;
    const c = (1 - Math.abs(2 * lig - 1)) * sat;
    const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
    const m = lig - c / 2;
    const [r, g, b] = h < 60 ? [c, x, 0] : h < 120 ? [x, c, 0] : h < 180 ? [0, c, x] : h < 240 ? [0, x, c] : h < 300 ? [x, 0, c] : [c, 0, x];
    const channel = (value) => {
        const v = value + m;

        return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
};

const ratio = (a, b) => (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);

/** Le contraste WCAG entre deux couleurs hexadécimales. */
export const contrastBetween = (hexA, hexB) => {
    const a = hexToHsl(hexA);
    const b = hexToHsl(hexB);

    return ratio(luminance(a.h, a.s, a.l), luminance(b.h, b.s, b.l));
};

const readableTextOn = (h, s, l) => {
    const background = luminance(h, s, l);
    const white = ratio(background, 1);
    const dark = ratio(background, luminance(...DARK_TEXT));

    return white >= 4.5 || white >= dark ? '0 0% 100%' : format(...DARK_TEXT);
};

const darkLightness = (l) => Math.max(55, Math.min(72, l + 17));

/** Les couleurs réellement utilisées pour un mode : réglées, sinon d'origine. */
export const effectiveColors = (values, mode) => {
    const fields = THEME_FIELDS[mode];
    const pick = (key) => (isHex(values?.[fields[key]]) ? values[fields[key]].trim().toLowerCase() : null);

    return {
        primary: pick('primary'),
        background: pick('background') ?? ORIGIN[mode].background,
        foreground: pick('foreground') ?? ORIGIN[mode].foreground,
        lightPrimary: isHex(values?.primary_color) ? values.primary_color.trim().toLowerCase() : ORIGIN.light.primary,
    };
};

const primaryTokens = (colors, mode) => {
    if (mode === 'light' || colors.primary) {
        const { h, s, l } = hexToHsl(colors.primary ?? colors.lightPrimary);
        const accent = mode === 'light'
            ? [format(h, Math.min(s, 70), 94), format(h, Math.min(s, 60), 28)]
            : [format(h, Math.min(s, 45), 19), format(h, Math.min(s, 65), 76)];

        return {
            '--primary': format(h, s, l),
            '--ring': format(h, s, l),
            '--primary-foreground': readableTextOn(h, s, l),
            '--accent': accent[0],
            '--accent-foreground': accent[1],
        };
    }

    const { h, s, l } = hexToHsl(colors.lightPrimary);
    const lightness = darkLightness(l);

    return {
        '--primary': format(h, s, lightness),
        '--ring': format(h, s, lightness),
        '--primary-foreground': readableTextOn(h, s, lightness),
        '--accent': format(h, Math.min(s, 45), 19),
        '--accent-foreground': format(h, Math.min(s, 65), 76),
    };
};

const surfaceTokens = (backgroundHex, foregroundHex, level = 'standard') => {
    const bg = hexToHsl(backgroundHex);
    const fg = hexToHsl(foregroundHex);
    const lightBackground = bg.l >= 50;
    const step = lightBackground ? -1 : 1;
    const [border, input, share] = CONTRAST[lightBackground ? 'light' : 'dark'][level];
    const edgeSaturation = lightBackground ? bg.s : bg.s * 0.6;
    const card = bounded(bg.h, bg.s, lightBackground ? Math.min(100, bg.l + 3) : bg.l + 3);
    const muted = bounded(bg.h, lightBackground ? Math.min(bg.s + 10, 50) : bg.s * 0.7, lightBackground ? bg.l - 1 : bg.l + 9);
    const text = format(fg.h, fg.s, fg.l);

    return {
        '--background': format(bg.h, bg.s, bg.l),
        '--foreground': text,
        '--card': card,
        '--card-foreground': text,
        '--popover': card,
        '--popover-foreground': text,
        '--secondary': muted,
        '--secondary-foreground': text,
        '--muted': muted,
        '--muted-foreground': bounded(fg.h, Math.min(fg.s, 25), fg.l + (bg.l - fg.l) * share),
        '--border': bounded(bg.h, edgeSaturation, bg.l + step * border),
        '--input': bounded(bg.h, edgeSaturation, bg.l + step * input),
    };
};

/** Toutes les variables d'un mode, pour un aperçu posé en style sur un bloc. */
export const previewTokens = (values, mode, level = 'standard') => {
    const colors = effectiveColors(values, mode);

    return { ...primaryTokens(colors, mode), ...surfaceTokens(colors.background, colors.foreground, level) };
};

/** Le préréglage qui correspond exactement aux couleurs, sinon « custom ». RIVO = toutes vides. */
export const detectPreset = (values, presets) => {
    const colors = COLOR_FIELDS.map((field) => String(values?.[field] ?? '').trim().toLowerCase());

    if (colors.every((color) => color === '')) return 'rivo';

    const match = Object.entries(presets ?? {}).find(([key, preset]) => key !== 'rivo'
        && ['light', 'dark'].every((mode) => Object.entries(THEME_FIELDS[mode])
            .every(([role, field]) => String(values?.[field] ?? '').trim().toLowerCase() === String(preset[mode]?.[role] ?? '').toLowerCase())));

    return match ? match[0] : 'custom';
};

/** Les six couleurs d'un préréglage, prêtes pour le formulaire (RIVO : vides = d'origine). */
export const presetValues = (key, presets) => {
    const preset = presets?.[key];

    return Object.fromEntries(['light', 'dark'].flatMap((mode) => Object.entries(THEME_FIELDS[mode])
        .map(([role, field]) => [field, key === 'rivo' || ! preset ? '' : String(preset[mode][role]).toUpperCase()])));
};

/** Un thème exporté : les couleurs réellement appliquées, lisibles et réimportables. */
export const exportTheme = (values, name) => ({
    rivo_theme: 1,
    name,
    light: effectiveThemeColors(values, 'light'),
    dark: effectiveThemeColors(values, 'dark'),
});

const effectiveThemeColors = (values, mode) => {
    const colors = effectiveColors(values, mode);

    return {
        primary: (colors.primary ?? (mode === 'light' ? colors.lightPrimary : null)) ?? null,
        background: colors.background,
        foreground: colors.foreground,
    };
};

/**
 * Relit un thème collé ou déposé. Refuse ce qui n'en est pas un, plutôt que de
 * remplir le formulaire de valeurs fausses.
 */
export const parseThemeImport = (text) => {
    let data;

    try {
        data = JSON.parse(String(text ?? ''));
    } catch {
        return { ok: false, error: 'Ce n’est pas un thème : le texte n’est pas du JSON valide.' };
    }

    if (! data || typeof data !== 'object' || ! data.light || ! data.dark) {
        return { ok: false, error: 'Ce n’est pas un thème RIVO : il manque le mode clair ou le mode sombre.' };
    }

    const values = {};

    for (const mode of ['light', 'dark']) {
        for (const [role, field] of Object.entries(THEME_FIELDS[mode])) {
            const color = data[mode]?.[role];

            if (color === null || color === undefined || color === '') {
                values[field] = '';
            } else if (isHex(color)) {
                values[field] = String(color).trim().toUpperCase();
            } else {
                return { ok: false, error: `Couleur invalide (${mode === 'light' ? 'clair' : 'sombre'} · ${role}) : « ${color} ». Format attendu : #RRVVBB.` };
            }
        }
    }

    return { ok: true, values, name: typeof data.name === 'string' ? data.name : null };
};
