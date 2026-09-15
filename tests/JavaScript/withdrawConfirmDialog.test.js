import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Medicine/Show.vue', 'utf8');

/**
 * Le retrait des demandes paracliniques se confirme dans l'application.
 *
 * Il passait par `window.confirm()` : une fenêtre du navigateur, qui ne peut
 * nommer ni les examens concernés ni ce qui leur arrive, et qui ignore le
 * thème de l'application.
 */

/** Le code seul : les commentaires de ce fichier citent la fonction retirée. */
const code = page
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/.*$/gm, '$1');

test('plus aucune fenêtre native ne décide du retrait', () => {
    assert.doesNotMatch(code, /window\.confirm\(/);
    assert.doesNotMatch(code, /window\.alert\(/);
});

test('la confirmation nomme chaque demande concernée', () => {
    const dialog = page.slice(page.indexOf('v-model:open="withdrawConfirmOpen"'));
    const block = dialog.slice(0, dialog.indexOf('</ShadcnDialog>'));

    assert.match(block, /v-for="request in paraclinicalRequests"/);
    assert.match(block, /request\.summary/);
});

/**
 * L'écran n'est jamais la protection : le serveur exige `withdraw_confirmed`
 * et refuse de lui-même une demande portant déjà un résultat.
 */
test('le drapeau de confirmation n’est posé que par l’action explicite', () => {
    const handler = page.slice(page.indexOf('const confirmWithdrawAndDecide'));
    const body = handler.slice(0, handler.indexOf('};'));

    assert.match(body, /withdraw_confirmed = true/);

    // Le chemin ordinaire ne doit jamais le laisser armé d'un tour sur l'autre.
    const decide = page.slice(page.indexOf('const decideComplementaryExams'));
    assert.match(decide.slice(0, decide.indexOf('};')), /withdraw_confirmed = false/);
});

test('répondre « Oui » n’ouvre aucune confirmation', () => {
    const decide = page.slice(page.indexOf('const decideComplementaryExams'));
    const body = decide.slice(0, decide.indexOf('};'));

    assert.match(body, /value === false && paraclinicalRequests\.value\.length > 0/);
});
