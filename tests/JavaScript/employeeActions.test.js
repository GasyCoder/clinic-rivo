import { test } from 'node:test';
import assert from 'node:assert/strict';
import { bulkTargets, duplicateLabel, forceDeleteState } from '../../resources/js/utilities/employeeActions.js';

/** ADR-236 — ce qu'une sélection d'employés permet, et pourquoi un dossier ne se supprime pas. */

test('un dossier en service ne se supprime pas définitivement', () => {
    assert.equal(forceDeleteState({ archived: false }, true).allowed, false);
});

test('sans le droit, la raison nomme la permission', () => {
    const state = forceDeleteState({ archived: true, deletion_blockers: [] }, false);
    assert.equal(state.allowed, false);
    assert.match(state.reason, /employees\.force_delete/);
});

test('un dossier qui a servi reste archivé, et dit où', () => {
    const state = forceDeleteState({ archived: true, deletion_blockers: ['2 contrats', '1 paie'] }, true);
    assert.equal(state.allowed, false);
    assert.match(state.reason, /2 contrats, 1 paie/);
});

test('un dossier archivé qui n’a servi nulle part se supprime', () => {
    assert.equal(forceDeleteState({ archived: true, deletion_blockers: [] }, true).allowed, true);
});

test('la sélection se répartit par geste', () => {
    const rows = [
        { uuid: 'a', archived: false },
        { uuid: 'b', archived: true, deletion_blockers: [] },
        { uuid: 'c', archived: true, deletion_blockers: ['1 contrat'] },
    ];
    const all = bulkTargets(rows, { archive: true, restore: true, forceDelete: true, badge: true });
    assert.deepEqual(all.archive.map((row) => row.uuid), ['a']);
    assert.deepEqual(all.badge.map((row) => row.uuid), ['a']);
    assert.deepEqual(all.restore.map((row) => row.uuid), ['b', 'c']);
    assert.deepEqual(all.forceDelete.map((row) => row.uuid), ['b']);

    const none = bulkTargets(rows, { archive: false, restore: false, forceDelete: false, badge: false });
    assert.ok(Object.values(none).every((list) => list.length === 0));
});

test('les doublons se nomment par matricule', () => {
    assert.equal(duplicateLabel([{ number: 'EMP-0002', name: 'RAKOTO Vola' }]), 'EMP-0002 (RAKOTO Vola)');
});
