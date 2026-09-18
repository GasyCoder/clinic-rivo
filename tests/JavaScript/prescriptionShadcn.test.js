import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

/**
 * ADR-099 — l'écran Ordonnance est écrit avec la couche shadcn.
 *
 * Ces tests gardent deux choses que ni le build ni un test de rendu
 * n'attrapent : un contrôle natif habillé à la main qui revient, et un
 * composant utilisé sans être importé.
 */
const read = (file) => fs.readFileSync(path.join('resources/js', file), 'utf8');

const EDITOR = 'Components/Clinical/PrescriptionLineEditor.vue';
const SCREEN = 'Pages/Medicine/Show.vue';

/** La partie du fichier qui décrit l'écran, pas celle qui l'assemble. */
const templateOf = (source) => source.slice(source.indexOf('<template>'));

test('l’éditeur de ligne n’habille plus de contrôle natif à la main', () => {
    const template = templateOf(read(EDITOR));

    // Les trois listes (unité de dose, voie, unité de durée) portaient
    // chacune leur copie de la même chaîne de classes — elles avaient déjà
    // divergé des champs voisins de la même rangée.
    assert.doesNotMatch(template, /<select/, 'un <select> natif est revenu dans l’éditeur de ligne');
    assert.doesNotMatch(template, /<textarea/, 'un <textarea> natif est revenu dans l’éditeur de ligne');

    // Un libellé écrit à la main, c'est une typographie de plus à tenir
    // à jour : `FormField` porte le texte, l'astérisque, la précision et
    // l'erreur.
    assert.doesNotMatch(template, /<label/, 'un libellé écrit à la main est revenu dans l’éditeur de ligne');

    for (const primitive of ['FormField', 'Select', 'Input']) {
        assert.match(template, new RegExp(`<${primitive}[\\s\\n]`), `${primitive} a disparu de l’éditeur de ligne`);
    }
});

/**
 * ADR-083 — la voie reste facultative, et « Non précisée » est une entrée
 * de la liste : un champ vide sans intitulé se lirait comme un oubli.
 */
test('« Non précisée » reste un choix, jamais une absence', () => {
    const source = read(EDITOR);

    assert.match(source, /value: '', label: 'Non précisée'/);
});

/**
 * ADR-110 — la quantité déduite est une suggestion. Elle ne revient jamais
 * d'elle-même sur une valeur corrigée, mais reste atteignable : sinon
 * l'aide ne sert qu'une fois.
 */
test('la quantité déduite se reprend sans la recalculer de tête', () => {
    const source = read(EDITOR);

    assert.match(source, /canRestoreSuggestion/);
    assert.match(source, /Utiliser \{\{ suggestion \}\}/);

    // Et la reprise passe par le même chemin que la saisie : elle marque la
    // ligne comme touchée, donc le calcul ne la réécrira plus ensuite.
    assert.match(source, /@click="setQuantity\(suggestion\)"/);
});

/**
 * Le catalogue distinguait mal « épuisé » de « déjà retenu » : les deux
 * rendaient la ligne inerte, et le médecin relisait « épuisé » sur un
 * produit qu'il venait lui-même d'ajouter. Trois états, jamais deux —
 * la règle que ce dossier tient partout (ADR-077, ADR-079).
 */
test('le catalogue dit pourquoi une ligne est inerte', () => {
    const template = templateOf(read(SCREEN));

    assert.match(template, /Dans l’ordonnance/);
    assert.match(template, /Épuisé/);
});

/**
 * Le build ne résout pas les composants : un `<FormField>` utilisé sans
 * import ne casse qu'à l'écran, dans un avertissement de console. Ce test
 * l'attrape — il a attrapé exactement ce cas le 2026-09-18.
 */
test('aucun composant clinique n’est utilisé sans être importé', () => {
    const BUILTINS = new Set(['Transition', 'TransitionGroup', 'Teleport', 'KeepAlive', 'Suspense', 'Component']);
    const files = [
        SCREEN,
        ...fs.readdirSync('resources/js/Components/Clinical')
            .filter((name) => name.endsWith('.vue'))
            .map((name) => `Components/Clinical/${name}`),
    ];

    const missing = [];

    for (const file of files) {
        const source = read(file);
        const cut = source.indexOf('<template>');
        const script = cut >= 0 ? source.slice(0, cut) : source;
        const used = new Set([...source.slice(cut).matchAll(/<([A-Z][A-Za-z0-9]*)[\s/>]/g)].map((m) => m[1]));

        for (const name of used) {
            if (!BUILTINS.has(name) && !new RegExp(`\\b${name}\\b`).test(script)) {
                missing.push(`${file} → <${name}>`);
            }
        }
    }

    assert.deepEqual(missing, [], `composant utilisé sans import :\n${missing.join('\n')}`);
});
