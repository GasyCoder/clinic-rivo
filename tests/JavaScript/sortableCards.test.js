import test from 'node:test';
import assert from 'node:assert/strict';
import { mergeVisibleCardOrder, moveCard, normalizeCardOrder } from '../../resources/js/utilities/sortableCards.js';

test('un ordre mémorisé est réparé et une nouvelle carte reste visible', () => {
    assert.deepEqual(
        normalizeCardOrder(['b', 'old', 'b', 'a'], ['a', 'b', 'c']),
        ['b', 'a', 'c'],
    );
});

test('déplacer une carte ne modifie pas le tableau reçu', () => {
    const original = ['a', 'b', 'c'];

    assert.deepEqual(moveCard(original, 'c', 0), ['c', 'a', 'b']);
    assert.deepEqual(original, ['a', 'b', 'c']);
    assert.deepEqual(moveCard(original, 'a', -1), original);
});

test('réordonner une recherche conserve les cartes masquées par le filtre', () => {
    assert.deepEqual(
        mergeVisibleCardOrder(['a', 'hidden', 'b', 'c'], ['c', 'a', 'b']),
        ['c', 'hidden', 'a', 'b'],
    );
});
