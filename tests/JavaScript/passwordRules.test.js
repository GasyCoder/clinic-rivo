import test from 'node:test';
import assert from 'node:assert/strict';
import { passwordChecks, passwordMeetsRules } from '../../resources/js/utilities/passwordRules.js';

const state = (password) => Object.fromEntries(passwordChecks(password).map((check) => [check.key, check.ok]));

test('ADR-202 — the rules are those of the server: 12 characters, both cases, a digit, a symbol', () => {
    assert.deepEqual(state(''), { length: false, case: false, number: false, symbol: false });
    assert.deepEqual(state('abcdefghijkl'), { length: true, case: false, number: false, symbol: false });
    assert.deepEqual(state('Abcdefghijk1'), { length: true, case: true, number: true, symbol: false });
    assert.equal(passwordMeetsRules('Mon-Choix-2026!'), true);
    // Les lettres accentuées comptent, comme pour Laravel.
    assert.equal(passwordMeetsRules('Éléphant-2026'), true);
    assert.equal(passwordMeetsRules('Court-1!'), false);
    // Un espace est un symbole pour Laravel (\p{Z}).
    assert.equal(state('a b')['symbol'], true);
});
