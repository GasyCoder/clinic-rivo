import { test } from 'node:test';
import assert from 'node:assert/strict';
import { seniority, seniorityLabel } from '../../resources/js/utilities/seniority.js';

/** ADR-197 — l'ancienneté se calcule depuis la date d'entrée, comme au serveur. */
test('seniority counts whole years and months since the hire date', () => {
    assert.deepEqual(seniority('2023-05-10', '2026-09-26'), { years: 3, months: 4, label: '3 ans 4 mois', future: false });
    assert.equal(seniority('2025-09-26', '2026-09-26').label, '1 an');
    assert.equal(seniority('2026-04-01', '2026-09-26').label, '5 mois');
    assert.equal(seniority('2026-09-10', '2026-09-26').label, 'Moins d’un mois');
    // Un mois n'est acquis qu'au jour anniversaire.
    assert.equal(seniority('2026-08-27', '2026-09-26').label, 'Moins d’un mois');
    assert.equal(seniority('2026-08-26', '2026-09-26').label, '1 mois');
});

test('seniority says nothing it cannot know', () => {
    assert.equal(seniority(''), null);
    assert.equal(seniority(null), null);
    assert.deepEqual(seniority('2027-01-01', '2026-09-26'), { years: 0, months: 0, label: 'Entrée à venir', future: true });
    assert.equal(seniorityLabel(2, 0), '2 ans');
});
