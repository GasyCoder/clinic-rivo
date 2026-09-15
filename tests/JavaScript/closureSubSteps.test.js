import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Medicine/Show.vue', 'utf8');

/**
 * L'étape « Décision & clôture » : trois temps parcourus un par un.
 *
 * Ce que ces tests protègent n'est pas la mise en page, mais deux règles que
 * l'écran doit tenir : avancer n'est jamais refusé, et un diagnostic différé
 * ne laisse pas sa saisie ouverte.
 */

test('les trois sections sont affichées une par une', () => {
    for (const step of [1, 2, 3]) {
        assert.match(page, new RegExp(`v-show="closureSubStep === ${step}"`));
    }
});

/**
 * Le bouton ne porte aucune condition : un diagnostic peut légitimement être
 * différé (ADR-095) ou ne pas être dû (ADR-094). C'est la clôture qui refuse,
 * à l'étape 3, en nommant ce qui manque — jamais un bouton grisé muet.
 */
test('avancer n’est jamais bloqué par l’état du dossier', () => {
    const next = page.slice(page.indexOf('v-if="closureSubStep < 3"'));
    const markup = next.slice(0, next.indexOf('</Button>'));

    assert.doesNotMatch(markup, /:disabled=/);
    assert.match(markup, /@click="closureSubStep \+= 1"/);
});

test('on peut revenir en arrière, et sauter directement à une section', () => {
    assert.match(page, /@click="closureSubStep -= 1"/);
    assert.match(page, /@click="closureSubStep = section\.step"/);
});

/** La réponse du médecin doit être suivie d'effet. */
test('« Pas maintenant » replie la saisie du diagnostic', () => {
    assert.match(page, /v-if="diagnosisReady !== false" class="mt-3"/);
});

/** Répondre reste possible : le report n’est pas définitif. */
test('la question elle-même reste toujours visible', () => {
    const question = page.indexOf('Le diagnostic peut-il être posé maintenant ?');
    assert.notEqual(question, -1);

    const block = page.slice(page.lastIndexOf('<div', question), question);
    assert.doesNotMatch(block, /v-if|v-show/);
});
