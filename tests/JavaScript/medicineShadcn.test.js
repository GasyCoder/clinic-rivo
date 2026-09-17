import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const files = [
    ...fs.readdirSync('resources/js/Pages/Medicine').map((f) => path.join('resources/js/Pages/Medicine', f)),
    ...fs.readdirSync('resources/js/Components/Medicine').map((f) => path.join('resources/js/Components/Medicine', f)),
    ...fs.readdirSync('resources/js/Components/Clinical').map((f) => path.join('resources/js/Components/Clinical', f)),
].filter((f) => f.endsWith('.vue'));

const read = (f) => fs.readFileSync(f, 'utf8');
const label = (f) => f.replace('resources/js/', '');

test('le parcours Médecine couvre bien tous ses écrans', () => {
    assert.ok(files.length >= 25, `seulement ${files.length} fichiers trouvés`);
});

/** ADR-099 : shadcn-vue est le design system, DashWind un reliquat. */
test('aucun écran Médecine n’importe plus un composant DashWind', () => {
    for (const file of files) {
        const source = read(file);

        for (const dashwind of ['UI/Icon.vue', 'UI/Button.vue', 'UI/Badge.vue', 'UI/Input.vue', 'UI/IconInput.vue', 'UI/Card.vue']) {
            assert.ok(! source.includes(dashwind), `${label(file)} importe encore ${dashwind}`);
        }

        assert.doesNotMatch(source, /<Icon[ />]/, `${label(file)} rend encore la police d’icônes`);
    }
});

/**
 * Une couleur codée en dur ne suit pas le thème : c'est le passage aux
 * tokens sémantiques qui fait fonctionner le mode sombre sans empiler une
 * variante `dark:` sur chaque élément.
 *
 * Les documents imprimés font exception, et volontairement : leur corps
 * décrit du papier, où `bg-white` et `text-slate-900` sont la vérité, pas
 * un défaut de thème. Seule leur barre d'écran a migré.
 */
test('les écrans Médecine utilisent les tokens sémantiques', () => {
    const printed = ['PrescriptionPrint.vue', 'ImagingReportPrint.vue', 'ClinicalDocumentPrint.vue'];

    for (const file of files.filter((f) => ! printed.some((p) => f.endsWith(p)))) {
        const source = read(file);

        for (const legacy of [/text-slate-[0-9]/, /border-gray-[0-9]/, /bg-gray-[0-9]/, /\bbg-white\b/, /-primary-[0-9]/]) {
            assert.doesNotMatch(source, legacy, `${label(file)} porte encore ${legacy}`);
        }
    }
});
