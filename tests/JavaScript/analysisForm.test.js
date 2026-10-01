import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
    adoptChildUuids, analysisFormFrom, analysisPayload, emptyChild, matchesCatalogItem, missingFields, missingSentence,
    newAnalysisForm, stepOfField, stepsFor, usesPredefinedValues, isNumericResult,
} from '../../resources/js/utilities/analysisForm.js';

const read = (path) => fs.readFileSync(path, 'utf8');

/** Les sous-analyses n'existent que pour un groupe : une étape vide n'est jamais proposée. */
test('the steps follow the level of the analysis', () => {
    assert.deepEqual(stepsFor('NORMAL').map((step) => step.key), ['identite', 'resultat', 'normes', 'recap']);
    assert.deepEqual(stepsFor('PARENT').map((step) => step.key), ['identite', 'resultat', 'normes', 'sous-analyses', 'recap']);
    assert.deepEqual(stepsFor('CHILD').map((step) => step.number), [1, 2, 3, 4]);
    assert.equal(stepOfField('children.0.code'), 'sous-analyses');
    assert.equal(stepOfField('critical_ranges.general.low'), 'normes');
    assert.equal(stepOfField('predefined_values.1'), 'resultat');
});

/** Rien ne part tant qu'un champ obligatoire manque ; l'écran dit lequel. */
test('missing fields are named, in screen order, and block saving', () => {
    const form = newAnalysisForm();
    assert.equal(form.catalog_item_uuid, '', 'aucune prestation choisie d’office');
    assert.deepEqual(missingFields(form).map((item) => item.field), ['catalog_item_uuid', 'designation', 'code']);
    assert.equal(missingSentence(missingFields(form)), 'la prestation Laboratoire, la désignation et le code');

    Object.assign(form, { catalog_item_uuid: 'svc', designation: 'Hémoglobine', code: 'HB', level: 'CHILD' });
    assert.deepEqual(missingFields(form).map((item) => item.field), ['parent_uuid'], 'une sous-analyse exige son groupe');

    Object.assign(form, { level: 'PARENT', children: [{ ...emptyChild(), code: 'A', designation: 'A' }, emptyChild()] });
    assert.deepEqual(missingFields(form).map((item) => item.step), ['sous-analyses'], 'une ligne vide retient l’enregistrement');
    form.children.pop();
    assert.deepEqual(missingFields(form), []);
});

test('the payload splits values, drops a parent the level cannot have, numbers the children', () => {
    const payload = analysisPayload({
        ...newAnalysisForm(), level: 'NORMAL', parent_uuid: 'p', predefined_values_text: ' Positif | Négatif |Positif| ',
        children: [emptyChild()],
    });
    assert.equal(payload.parent_uuid, null);
    assert.deepEqual(payload.predefined_values, ['Positif', 'Négatif']);
    assert.deepEqual(payload.children, [], 'seul un groupe envoie des sous-analyses');

    const group = analysisPayload({ ...newAnalysisForm(), level: 'PARENT', parent_uuid: '', children: [{ ...emptyChild(), code: 'A' }, { ...emptyChild(), code: 'B' }] });
    assert.equal(group.parent_uuid, null, 'un groupe principal n’a pas de parent');
    assert.deepEqual(group.children.map((child) => child.display_order), [1, 2]);
});

test('unit, values and critical ranges follow the result type', () => {
    assert.equal(isNumericResult({ result_type: 'NUMERIC' }), true);
    assert.equal(isNumericResult({ result_type: 'TEXT', entry_mode: 'NUMERIC' }), true);
    assert.equal(usesPredefinedValues({ result_type: 'CHOICE' }), true);
    assert.equal(usesPredefinedValues({ result_type: 'TEXT', entry_mode: 'NEG_POS_CHOICE' }), true);
    assert.equal(usesPredefinedValues({ result_type: 'NUMERIC', entry_mode: null }), false);
});

/** Recharger une sous-analyse désactivée la réactiverait au prochain enregistrement. */
test('the record form keeps only active sub-analyses', () => {
    const form = analysisFormFrom({
        level: 'PARENT', code: 'ION', designation: 'Ionogramme', result_type: 'TEXT', catalog_item: { uuid: 'svc' },
        children: [
            { uuid: 'na', code: 'ION-NA', level: 'CHILD', result_type: 'NUMERIC', is_active: true },
            { uuid: 'k', code: 'ION-K', level: 'CHILD', result_type: 'NUMERIC', is_active: false },
        ],
    });
    assert.deepEqual(form.children.map((child) => child.uuid), ['na']);
});

/** Sans l'UUID reçu, l'enregistrement suivant recréerait la sous-analyse. */
test('new sub-analyses adopt the uuid the server gave them', () => {
    const children = [
        { ...emptyChild(), uuid: 'a', code: 'A' },
        { ...emptyChild(), code: 'b' },
        { ...emptyChild(), code: 'C-renamed', level: 'PARENT', children: [{ ...emptyChild(), code: 'C1' }] },
    ];
    const changed = adoptChildUuids(children, [
        { uuid: 'a', code: 'A' },
        { uuid: 'uuid-b', code: 'B' },
        { uuid: 'uuid-c', code: 'C', level: 'PARENT', children: [{ uuid: 'uuid-c1', code: 'C1' }] },
        { uuid: 'old', code: 'OLD', is_active: false },
    ]);

    assert.equal(changed, true);
    assert.deepEqual(children.map((child) => child.uuid), ['a', 'uuid-b', 'uuid-c'], 'par le code, sinon par la place');
    assert.equal(children[2].children[0].uuid, 'uuid-c1');
    assert.equal(adoptChildUuids(children, [{ uuid: 'a', code: 'A' }]), false, 'rien n’est touché quand tout a son UUID');
});

test('a laboratory service is found by name or code, accents ignored', () => {
    const item = { code: 'LAB-NFS', name: 'Numération formule sanguine' };
    assert.ok(matchesCatalogItem(item, 'formule numeration'));
    assert.ok(matchesCatalogItem(item, 'lab-nfs'));
    assert.ok(! matchesCatalogItem(item, 'glycémie'));
});

test('the record autosaves instead of a save button, on shadcn components', () => {
    const edit = read('resources/js/Pages/Analyses/Edit.vue');
    const create = read('resources/js/Pages/Analyses/Create.vue');
    const form = read('resources/js/Components/Analyses/AnalysisForm.vue');
    const children = read('resources/js/Components/Analyses/SubAnalysesEditor.vue');

    assert.match(edit, /useAutosave\(form, send, \{ enabled: \(\) => ready\.value/);
    assert.match(edit, /_autosave: true/);
    assert.match(edit, /only: \['analysis'\]/, 'seule la fiche revient : ni les listes, ni le reste du site');
    assert.match(edit, /adoptChildUuids\(form\.children/);
    assert.match(edit, /<ClinicalSaveStatus/);
    assert.match(edit, /useUnsavedChangesGuard\(pending\)/);
    assert.doesNotMatch(edit, /Mettre à jour/);
    assert.match(create, /after: 'edit'/);
    assert.match(create, /Créer et continuer/);
    assert.match(create, /<EmployeeStepBar :steps="steps" current="identite" locked/);

    for (const source of [form, children]) {
        assert.doesNotMatch(source, /<select|<input v-model[^>]*type="checkbox"|\bni ni-|\bbg-(?:gray|slate)-\d/, 'plus de contrôle natif ni de palette DashWind');
    }
    assert.match(form, /<SearchSelect/);
    assert.match(form, /<RadioGroup :model-value="form\.level"/);
    assert.match(children, /<ConfirmModal/);
});
