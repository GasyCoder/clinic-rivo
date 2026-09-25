import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { locate, moveCard, neighbourColumn, normalizeLayout, sameLayout } from '../../resources/js/utilities/boardLayout.js';

const DEFAULTS = { people: ['employees', 'contracts', 'documents'], time: ['attendance', 'leave', 'planning'], steering: ['reports', 'credit', 'settings'] };

/** Une disposition enregistrée se répare, elle ne se croit pas. */
test('a stored layout is repaired, never trusted', () => {
    assert.deepEqual(normalizeLayout(null, DEFAULTS), DEFAULTS, 'rien d’enregistré : l’ordre d’origine');
    assert.deepEqual(normalizeLayout('abîmé', DEFAULTS), DEFAULTS);

    const repaired = normalizeLayout({
        people: ['leave', 'ghost', 'employees', 'leave', 42],
        time: ['reports'],
        steering: ['settings'],
    }, DEFAULTS);

    assert.deepEqual(repaired.people, ['leave', 'employees', 'contracts', 'documents'], 'inconnue oubliée, doublon gardé une fois, oubliées rendues à leur colonne');
    assert.deepEqual(repaired.time, ['reports', 'attendance', 'planning']);
    assert.deepEqual(repaired.steering, ['settings', 'credit']);
    assert.equal(Object.values(repaired).flat().length, 9, 'aucune carte ne disparaît');
});

test('a card moves within its column or into another, never in place', () => {
    const down = moveCard(DEFAULTS, 'employees', 'people', 1);
    assert.deepEqual(down.people, ['contracts', 'employees', 'documents']);
    assert.deepEqual(DEFAULTS.people, ['employees', 'contracts', 'documents'], 'l’ancienne disposition reste intacte');

    const across = moveCard(DEFAULTS, 'leave', 'people', 0);
    assert.deepEqual(across.people, ['leave', 'employees', 'contracts', 'documents']);
    assert.deepEqual(across.time, ['attendance', 'planning']);

    assert.deepEqual(moveCard(DEFAULTS, 'leave', 'steering', 99).steering, ['reports', 'credit', 'settings', 'leave'], 'un rang trop grand vaut la fin');
    assert.equal(moveCard(DEFAULTS, 'inconnue', 'people', 0), DEFAULTS);
    assert.deepEqual(locate(across, 'leave'), { column: 'people', index: 0 });
});

test('columns have neighbours, not beyond the edges', () => {
    assert.equal(neighbourColumn(DEFAULTS, 'people', -1), null);
    assert.equal(neighbourColumn(DEFAULTS, 'people', 1), 'time');
    assert.equal(neighbourColumn(DEFAULTS, 'steering', 1), null);
    assert.ok(sameLayout(normalizeLayout(null, DEFAULTS), DEFAULTS));
});

/** Demande du propriétaire : les rubriques de l'accueil RH se rangent comme on veut. */
test('the HR home cards can be arranged, safely', () => {
    const home = fs.readFileSync('resources/js/Pages/Administration/Index.vue', 'utf8');

    assert.match(home, /const STORAGE_KEY = 'rivo:hr:home-areas'/);
    assert.match(home, /onMounted\(\(\) => \{\s*try \{\s*layout\.value = normalizeLayout\(JSON\.parse\(window\.localStorage\.getItem\(STORAGE_KEY\)\)/, 'lue après l’affichage, jamais pendant le rendu serveur');
    assert.match(home, /:is="customizing \? 'div' : Link"/, 'en mode arrangement, une carte n’est plus un lien : pas de navigation sur un geste mal placé');
    assert.match(home, /:draggable="customizing \? 'true' : undefined"/);
    assert.match(home, /Monter \$\{area\.label\}/, 'flèches pour le doigt et le clavier');
    assert.match(home, /@click="reset"/);
});
