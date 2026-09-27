import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const FORMS = ['EntreeBloc', 'SortieBloc', 'ConsultationPreAnesthesique', 'ExamenParaclinique', 'ConduiteAnesthesique'];

/**
 * Les formulaires du bloc et de l'anesthésie s'enregistrent tout seuls :
 * plus de bouton « Enregistrer et continuer », seulement « Suivant », qui
 * enregistre ce qui reste avant d'ouvrir la section suivante.
 */
for (const name of FORMS) {
    test(`${name} s'enregistre automatiquement et avance par « Suivant »`, () => {
        const source = read(`resources/js/Components/Surgery/${name}.vue`);
        assert.match(source, /useAutosave\(/);
        assert.match(source, />Suivant</);
        assert.doesNotMatch(source, /Enregistrer et (continuer|passer)/);
        assert.doesNotMatch(source, /type="submit"[^>]*>[^<]*<Icon name="save"/);
    });
}

test('un enregistrement automatique ne déclenche aucun toast', () => {
    const toasts = read('resources/js/Components/UI/ToastContainer.vue');
    assert.equal((toasts.match(/if \(isAutosaveVisit\(\)\) return;/g) ?? []).length, 2);
});

test('l’enregistrement automatique garde l’état de la page et ne ment pas', () => {
    const composable = read('resources/js/composables/useAutosave.js');
    assert.match(composable, /preserveState: true/);
    // « enregistré » seulement après la réponse du serveur
    assert.match(composable, /onSuccess: \(page\) => \{[\s\S]*savedAt\.value = /);
    // la référence est ce qui a été envoyé, pas ce qui a été tapé depuis
    assert.match(composable, /form\.defaults\(sentData\)/);
});
