import test from 'node:test';
import assert from 'node:assert/strict';
import { internDossiers } from '../../resources/js/utilities/internships.js';
import { bulkTargets } from '../../resources/js/utilities/employeeActions.js';

test('un stagiaire qui a fait deux stages n’est qu’un dossier', () => {
    const stages = [
        { uuid: 'c1', employee: { uuid: 'e1', name: 'RASOA Vola', employee_number: 'STG-0001', archived: false } },
        { uuid: 'c2', employee: { uuid: 'e1', name: 'RASOA Vola', employee_number: 'STG-0001', archived: false } },
        { uuid: 'c3', employee: { uuid: 'e2', name: 'RAKOTO Faly', employee_number: 'STG-0002', archived: true, deletion_blockers: [] } },
    ];

    const dossiers = internDossiers(stages);
    assert.equal(dossiers.length, 2);
    assert.deepEqual(dossiers.map((dossier) => dossier.uuid), ['e1', 'e2']);

    // Les gestes de la liste des employés s'appliquent tels quels.
    const targets = bulkTargets(dossiers, { badge: true, archive: true, restore: true, forceDelete: true });
    assert.deepEqual(targets.badge.map((row) => row.uuid), ['e1']);
    assert.deepEqual(targets.archive.map((row) => row.uuid), ['e1']);
    assert.deepEqual(targets.restore.map((row) => row.uuid), ['e2']);
    assert.deepEqual(targets.forceDelete.map((row) => row.uuid), ['e2']);
});

test('une ligne sans dossier ne casse rien', () => {
    assert.deepEqual(internDossiers([{ uuid: 'c1' }, null]), []);
});
