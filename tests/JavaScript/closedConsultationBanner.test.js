import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Medicine/Show.vue', 'utf8');

/**
 * Le bandeau d'une consultation clôturée.
 *
 * Il n'existait que dans le pied de l'étape Clôture. « Ouvrir la
 * consultation » depuis « Demandes d'examens » menant à la Paraclinique, le
 * médecin s'y retrouvait devant des champs verrouillés sans une seule
 * commande pour en sortir — et sans qu'on lui dise pourquoi.
 */

test('le bandeau vit au-dessus du parcours, donc sur toutes les étapes', () => {
    const banner = page.indexOf('v-if="consultationIsClosed"');
    assert.notEqual(banner, -1, 'le bandeau a disparu');

    // Avant le stepper : aucune condition d'étape ne peut le masquer.
    assert.ok(banner < page.indexOf('<MedicineWorkflowNav'));
});

test('le bandeau porte l’action de réouverture', () => {
    const banner = page.indexOf('v-if="consultationIsClosed"');
    const block = page.slice(banner, page.indexOf('<MedicineWorkflowNav'));

    assert.match(block, /v-if="capabilities\.can_reopen_consultation"/);
    assert.match(block, /@click="reopenOpen = true"/);
});

/**
 * Quand c'est impossible, on dit pourquoi — et c'est le serveur qui le dit.
 * L'écran ne doit pas deviner entre « le passage est clos » et « vous n'avez
 * pas le droit » : l'une de ces impasses se règle auprès de la Réception.
 */
test('l’obstacle est celui que le serveur nomme, jamais une phrase locale', () => {
    const banner = page.indexOf('v-if="consultationIsClosed"');
    const block = page.slice(banner, page.indexOf('<MedicineWorkflowNav'));

    assert.match(block, /capabilities\.reopen_blocker/);
    assert.doesNotMatch(block, /Réception\s*:/);
});
