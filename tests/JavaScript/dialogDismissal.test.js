import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const dialog = fs.readFileSync('resources/js/Components/Shadcn/Dialog.vue', 'utf8');

/**
 * Le verrouillage d'une fenêtre où l'on écrit, exécuté pour de vrai.
 *
 * Une simple recherche de texte dirait que les gestionnaires existent ; ce
 * qui compte est qu'ils annulent bien la fermeture, et seulement quand la
 * fenêtre est déclarée non fermable.
 */
const handler = (attribute) => {
    const marker = `@${attribute}="`;
    const start = dialog.indexOf(marker);
    assert.notEqual(start, -1, `le gestionnaire ${attribute} a disparu`);

    const body = dialog.slice(start + marker.length, dialog.indexOf('"', start + marker.length));

    // eslint-disable-next-line no-new-func
    const run = new Function('dismissible', `return (${body});`);

    return (dismissible) => {
        let prevented = false;
        run(dismissible)({ preventDefault: () => { prevented = true; } });

        return prevented;
    };
};

for (const attribute of ['interact-outside', 'escape-key-down']) {
    test(`« ${attribute} » ne ferme pas une fenêtre déclarée non fermable`, () => {
        assert.equal(handler(attribute)(false), true, 'la fermeture aurait dû être annulée');
    });

    test(`« ${attribute} » ferme normalement les autres fenêtres`, () => {
        assert.equal(handler(attribute)(true), false, 'une fenêtre ordinaire doit rester fermable');
    });
}

/** Les fenêtres existantes ne changent pas de comportement. */
test('une fenêtre est fermable par défaut', () => {
    assert.match(dialog, /dismissible: \{ type: Boolean, default: true \}/);
});
