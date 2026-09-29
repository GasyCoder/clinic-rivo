import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { headerCheckState, rowMenuItems, rowRefusal, selectionActions, toggleSelection } from '../../resources/js/utilities/labSelection.js';

const rows = [
    { uuid: 'a', archived: false, archivable: true, trashable: false },
    { uuid: 'b', archived: false, archivable: false, trashable: true },
    { uuid: 'c', archived: true, archivable: false, trashable: false },
];

test('each gesture counts what it will take and what the server will refuse', () => {
    const actions = selectionActions(rows, ['a', 'b', 'c']);

    assert.deepEqual(actions.archive, ['a']);
    assert.deepEqual(actions.unarchive, ['c']);
    assert.deepEqual(actions.trash, ['b']);
    assert.deepEqual(actions.refused, { archive: 1, trash: 2 });
});

test('the select-all box says all, some or none, and the selection never exceeds the server cap', () => {
    assert.equal(headerCheckState(rows, []), false);
    assert.equal(headerCheckState(rows, ['a']), 'indeterminate');
    assert.equal(headerCheckState(rows, ['a', 'b', 'c']), true);
    assert.deepEqual(toggleSelection(['a'], 'a'), []);
    assert.deepEqual(toggleSelection(['a'], 'b', 1), ['a'], 'au plafond, rien de plus n’est coché');
});

test('a refused gesture says why before the click, and the menu only offers what the account may do', () => {
    assert.match(rowRefusal(rows[1], 'archive'), /envoyées au médecin/);
    assert.match(rowRefusal(rows[0], 'trash'), /ne part pas à la corbeille/);
    assert.equal(rowRefusal(rows[0], 'archive'), null);

    assert.deepEqual(rowMenuItems(rows[0], { archive: false, trash: false }), []);
    const menu = rowMenuItems(rows[0], { archive: true, trash: true });
    assert.deepEqual(menu.map((item) => item.key), ['archive', 'trash']);
    assert.equal(menu[1].disabled, true);
    assert.equal(rowMenuItems(rows[2], { archive: true })[0].key, 'unarchive');
});

test('the laboratory queue and the worklist offer the multiple selection', () => {
    const index = fs.readFileSync('resources/js/Pages/Laboratory/Index.vue', 'utf8');
    const worklist = fs.readFileSync('resources/js/Pages/Laboratory/Worklist.vue', 'utf8');

    assert.match(index, /labUrl\('\/laboratory\/requests\/bulk'\)/);
    assert.match(index, /LabTrashDialog/);
    assert.match(worklist, /labUrl\('\/laboratory\/requests\/bulk'\)/);
    assert.match(worklist, /print:hidden/);
});
