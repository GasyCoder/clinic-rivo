import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/** Amendement ADR-216 du 2026-09-29 — seule une analyse terminée part au médecin. */
const sending = fs.readFileSync('resources/js/utilities/labSending.js', 'utf8');

test('seule une analyse terminée peut partir', () => {
    assert.match(sending, /if \(item\.status === 'COMPLETED'\) \{\s*return \{ sendable: true/);
    assert.match(sending, /return \{ sendable: false, correction, note: `\$\{saved\} résultat.*pas encore terminée/);
});

test('la fenêtre coche toutes les terminées par défaut et permet d’en décocher', () => {
    const dialog = fs.readFileSync('resources/js/Components/Laboratory/LabSendDialog.vue', 'utf8');
    assert.match(dialog, /toggleAll/);
    assert.match(dialog, /Par défaut, toutes les analyses terminées partent ensemble/);
});
