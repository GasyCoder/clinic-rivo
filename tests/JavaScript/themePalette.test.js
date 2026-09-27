import test from 'node:test';
import assert from 'node:assert/strict';
import { contrastBetween, detectPreset, exportTheme, format, hexToHsl, parseThemeImport, presetValues, previewTokens, tripletToHex } from '../../resources/js/utilities/themePalette.js';

// Relevés sur App\Services\Settings\ThemePalette::previewTokens() : l'aperçu doit dire
// exactement ce que le serveur appliquera.
const SERVER = {
    origin: {
        light: { '--primary': '197 60% 39%', '--primary-foreground': '0 0% 100%', '--accent': '197 60% 94%', '--accent-foreground': '197 60% 28%', '--background': '216 33% 97%', '--foreground': '214 39% 23%', '--card': '216 33% 100%', '--muted': '216 43% 96%', '--muted-foreground': '214 25% 49%', '--border': '216 33% 90%', '--input': '216 33% 88%' },
        dark: { '--primary': '197 60% 56%', '--primary-foreground': '215 40% 10%', '--accent': '197 45% 19%', '--accent-foreground': '197 60% 76%', '--background': '217 39% 9%', '--foreground': '214 33% 96%', '--card': '217 39% 12%', '--muted': '217 27% 18%', '--muted-foreground': '214 25% 65%', '--border': '217 23% 21%', '--input': '217 23% 24%' },
    },
    night: {
        light: { '--primary': '234 56% 60%', '--primary-foreground': '0 0% 100%', '--background': '240 14% 97%', '--foreground': '231 11% 12%', '--card': '240 14% 100%', '--muted': '240 24% 96%', '--muted-foreground': '231 11% 42%', '--border': '240 14% 90%', '--input': '240 14% 88%' },
        dark: { '--primary': '234 51% 59%', '--primary-foreground': '0 0% 100%', '--accent': '234 45% 19%', '--background': '240 6% 6%', '--foreground': '220 6% 90%', '--card': '240 6% 9%', '--muted': '240 4% 15%', '--muted-foreground': '220 6% 60%', '--border': '240 4% 18%', '--input': '240 4% 21%' },
    },
};

const NIGHT = {
    primary_color: '#5e6ad2', light_background: '#f7f7f9', light_foreground: '#1c1d23',
    dark_primary_color: '#606acc', dark_background: '#0f0f11', dark_foreground: '#e3e4e6',
};

for (const [name, values] of [['origin', {}], ['night', NIGHT]]) {
    for (const mode of ['light', 'dark']) {
        test(`l’aperçu ${name} · ${mode} donne les valeurs du serveur`, () => {
            const tokens = previewTokens(values, mode);
            for (const [variable, expected] of Object.entries(SERVER[name][mode])) {
                assert.equal(tokens[variable], expected, variable);
            }
        });
    }
}

const PRESETS = { rivo: {}, night: { light: { primary: '#5e6ad2', background: '#f7f7f9', foreground: '#1c1d23' }, dark: { primary: '#606acc', background: '#0f0f11', foreground: '#e3e4e6' } } };

test('le préréglage se reconnaît aux couleurs ; une retouche le rend « personnalisé »', () => {
    assert.equal(detectPreset({}, PRESETS), 'rivo');
    assert.equal(detectPreset(presetValues('night', PRESETS), PRESETS), 'night');
    assert.equal(detectPreset({ ...presetValues('night', PRESETS), light_background: '#FFFFFF' }, PRESETS), 'custom');
    assert.deepEqual(Object.values(presetValues('rivo', PRESETS)), ['', '', '', '', '', '']);
});

test('un thème exporté se réimporte à l’identique ; un texte faux est refusé', () => {
    const exported = exportTheme(NIGHT, 'Nuit');
    const parsed = parseThemeImport(JSON.stringify(exported));
    assert.equal(parsed.ok, true);
    assert.equal(parsed.values.dark_background, '#0F0F11');
    assert.equal(parseThemeImport('pas du json').ok, false);
    assert.equal(parseThemeImport(JSON.stringify({ light: { primary: 'rouge' }, dark: {} })).ok, false);
});

test('le contraste du texte se mesure comme au serveur', () => {
    assert.ok(contrastBetween('#f5f7fa', '#243852') > 10);
    assert.ok(contrastBetween('#777777', '#888888') < 4.5);
});

test('une couleur déduite (l’accent du mode sombre) se montre avec son vrai code', () => {
    for (const hex of ['#287D9F', '#F5F7FA', '#0E1520', '#5E6AD2']) {
        const { h, s, l } = hexToHsl(hex.toLowerCase());
        // Le triplet est arrondi au pour-cent : l'aller-retour tombe à une unité près par canal.
        const back = tripletToHex(format(h, s, l));
        for (const index of [1, 3, 5]) {
            assert.ok(Math.abs(parseInt(back.slice(index, index + 2), 16) - parseInt(hex.slice(index, index + 2), 16)) <= 3, `${hex} → ${back}`);
        }
    }
    // Sans accent propre en mode sombre, l'aperçu dit celui qui s'affichera : plus clair que l'accent clair.
    const derived = tripletToHex(previewTokens({ primary_color: '#287D9F' }, 'dark')['--primary']);
    assert.match(derived, /^#[0-9A-F]{6}$/);
    assert.ok(hexToHsl(derived.toLowerCase()).l > hexToHsl('#287d9f').l);
    assert.equal(tripletToHex('pas une couleur'), '');
});
