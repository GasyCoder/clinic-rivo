import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { boardTiles, emptyState } from '../../resources/js/utilities/activePassages.js';

const queue = fs.readFileSync('resources/js/Pages/Maternity/Index.vue', 'utf8');
const board = fs.readFileSync('resources/js/Components/Clinical/ActivePassageBoard.vue', 'utf8');

/**
 * Les commentaires décrivent souvent ce qui a été retiré : les garder ferait
 * échouer une assertion sur le motif qu'elle interdit, précisément parce
 * qu'il est expliqué.
 */
const markup = (source) => source.replace(/<!--[\s\S]*?-->/g, '');

/** ADR-099 : la file Maternité tient sur la couche partagée. */
test('la file Maternité utilise les primitives shadcn', () => {
    assert.ok(queue.includes('@/Components/Shadcn/Badge.vue'), 'Badge n’est pas utilisé');
    for (const component of ['Badge', 'Button', 'Card', 'IconInput']) {
        assert.ok(board.includes(`@/Components/Shadcn/${component}.vue`), `${component} n’est pas utilisé par le tableau`);
    }

    // Le Card DashWind partagé n’est plus la carte de cet écran.
    assert.doesNotMatch(queue, /@\/Components\/UI\/Card\.vue/);
    assert.doesNotMatch(board, /@\/Components\/UI\//);
    // Pastilles écrites à la main.
    assert.doesNotMatch(queue, /rounded bg-red-600 px-2/);
});

/**
 * `<Link as="button">` enveloppant un `<Button>` produisait un bouton dans
 * un bouton. ADR-177 : la prise en charge est un POST vers l'adresse que le
 * serveur donne — l'écran n'en fabrique aucune.
 */
test('la prise en charge est un POST, pas un bouton dans un bouton', () => {
    for (const source of [queue, board]) {
        assert.doesNotMatch(markup(source), /<Link[^>]*as="button"[\s\S]{0,120}<Button/);
    }
    assert.match(board, /router\.post\(url, \{\}/);
    assert.match(board, /postTakeCharge\(row, row\.actions\?\.take_charge_url\)/);
    // Un double clic n’ouvre pas deux prises en charge.
    assert.match(board, /if \(taking\.value \|\| !url\) return;/);
});

test('aucune classe cassée par une substitution', () => {
    for (const source of [queue, board]) {
        assert.doesNotMatch(source, /(muted|primary|card|border|foreground)\d/);
    }
});

/** Le compte vient du serveur : recalculé sur la page, il mentirait dès la deuxième. */
test('les compteurs restent ceux du serveur', () => {
    assert.match(queue, /:counts="counts"/);
    const tiles = boardTiles('MATERNITY', { waiting: 4, suggested: 1, in_progress: 1, completed: 3, emergency: 0 }, 'in_progress');

    assert.deepEqual(tiles.map((tile) => tile.count), [4, 1, 3, 0]);
    assert.deepEqual(tiles.filter((tile) => tile.active).map((tile) => tile.value), ['in_progress']);
    // « Suggérés pour moi » filtre le bloc « En attente », qui reste allumé.
    assert.deepEqual(boardTiles('MATERNITY', {}, 'suggested').filter((tile) => tile.active).map((tile) => tile.value), ['waiting']);
});

/** Une page vide dit quoi faire, pas seulement qu’elle est vide — et chaque vue a son propre message. */
test('la vue vide oriente, vue par vue', () => {
    const titles = ['waiting', 'suggested', 'in_progress', 'completed', 'emergency']
        .map((view) => emptyState('MATERNITY', view).title);

    assert.equal(new Set(titles).size, 5);
    assert.equal(emptyState('MATERNITY', 'suggested').title, 'Aucun passage suggéré pour la Maternité');
    // « Aucune suggestion » est un état normal : le message le dit.
    assert.match(emptyState('MATERNITY', 'suggested').hint, /C’est un état normal/);
    assert.match(board, /const empty = computed\(\(\) => emptyState\(props\.module, props\.view\)\)/);
});

test('chaque ligne dit ce qui suit la Maternité, sans le deviner', () => {
    // Les deux suites viennent de vraies orientations / demandes servies par le serveur.
    assert.match(queue, /followUps\[row\.uuid\]\.medicine/);
    assert.match(queue, /followUps\[row\.uuid\]\.cesarean/);
    assert.match(queue, /followUps\[row\.uuid\]\.medicine\.label/);
    assert.match(queue, /followUps\[row\.uuid\]\.cesarean\.label/);
});

test('l’en-tête est celui, partagé, du module Soins', () => {
    assert.match(queue, /@\/Components\/Care\/SoinsWorkspaceHeader\.vue/);
    assert.match(queue, /<SoinsWorkspaceHeader/);
    assert.doesNotMatch(markup(queue), /<header class="flex flex-col gap-4 lg:flex-row/);
});

/** ADR-177 — la Maternité lit le même tableau des passages que les Soins et la Médecine. */
test('la Maternité lit le tableau partagé des passages', () => {
    assert.match(queue, /<ActivePassageBoard module="MATERNITY" base-url="\/maternity"/);
    assert.doesNotMatch(queue, /orientations\.data|props\.filter/);
});
