import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { CODE128_PATTERNS, code128Bars, code128Encodable, code128Modules, code128Values } from '../../resources/js/utilities/code128.js';
import { criticalFlag, criticalRangesCount, criticalRangesForm, criticalRangesPayload } from '../../resources/js/utilities/criticalRanges.js';
import { emptySampleLine, paymentBadge, sampleLinesPayload, sampleLinesTubeCount, sampleTubeOf } from '../../resources/js/utilities/labReception.js';
import { LAB_ROW_ACTIONS, LAB_STATE_LABELS, LAB_VIEWS, itemProgress, rowAction } from '../../resources/js/utilities/labWorkbench.js';

/** ADR-214 — les règles d'écran de la réception au laboratoire. Le serveur reste juge. */

test('Code 128 B : départ, données, clé modulo 103', () => {
    // « AB » : 104 + 33×1 + 34×2 = 205 ≡ 102 (mod 103).
    assert.deepEqual(code128Values('AB'), [104, 33, 34, 102]);
    // Un code de tube réel : la clé reste un symbole du jeu.
    const values = code128Values('A-L26-00042-1');
    assert.equal(values[0], 104);
    assert.ok(values.at(-1) >= 0 && values.at(-1) < 103);
});

test('Code 128 : chaque symbole fait 11 modules, l’arrêt 13, barres et espaces alternent', () => {
    for (const pattern of CODE128_PATTERNS) {
        assert.equal([...pattern].reduce((sum, width) => sum + Number(width), 0), 11, pattern);
    }
    const modules = code128Modules('AB');
    assert.equal(modules.length, 4 * 11 + 13);
    assert.equal(modules[0], 1, 'commence par une barre');
    assert.equal(modules.at(-1), 1, 'finit par une barre');

    const { bars, width } = code128Bars('AB');
    assert.equal(width, modules.length);
    assert.equal(bars.reduce((sum, bar) => sum + bar.width, 0), modules.filter(Boolean).length);
});

test('Code 128 : un caractère non imprimable est refusé, jamais encodé de travers', () => {
    assert.equal(code128Encodable('A-L26-00042-1'), true);
    assert.equal(code128Encodable(''), false);
    assert.equal(code128Encodable('é'), false);
    assert.throws(() => code128Values('\n'));
});

test('bornes critiques : le formulaire garde la virgule, l’envoi ne garde que ce qui est saisi', () => {
    const form = criticalRangesForm({ general: { low: 2.5, high: 6.5 } });
    assert.deepEqual(form.general, { low: '2,5', high: '6,5' });
    assert.deepEqual(form.male, { low: '', high: '' });

    assert.equal(criticalRangesPayload(criticalRangesForm()), null);
    assert.deepEqual(criticalRangesPayload({ ...form, child_female: { low: '', high: ' 180 ' } }), {
        general: { low: '2,5', high: '6,5' },
        child_female: { low: null, high: '180' },
    });
    assert.equal(criticalRangesCount(form), 1);
});

test('bornes critiques : la saisie ne signale que ce qui dépasse, jamais un texte', () => {
    const critical = { low: 2.5, high: 6.5 };
    assert.equal(criticalFlag(critical, '7,1'), 'HIGH');
    assert.equal(criticalFlag(critical, '2.4'), 'LOW');
    assert.equal(criticalFlag(critical, '4'), null);
    assert.equal(criticalFlag(critical, 'hémolysé'), null);
    assert.equal(criticalFlag(null, '99'), null);
    assert.equal(criticalFlag({ low: null, high: 10 }, '-5'), null);
});

test('prélèvements : le tube suit le type, une ligne vide ne part pas, la quantité est bornée', () => {
    const options = {
        sample_types: [{ uuid: 's1', name: 'Sang', tube_uuid: 't1' }, { uuid: 's2', name: 'Urine', tube_uuid: null }],
        tubes: [{ uuid: 't1', code: 'EDTA' }, { uuid: 't2', code: 'SEC' }],
    };
    assert.equal(sampleTubeOf({ sample_type_uuid: 's1' }, options).code, 'EDTA');
    assert.equal(sampleTubeOf({ sample_type_uuid: 's1', tube_type_uuid: 't2' }, options).code, 'SEC');
    assert.equal(sampleTubeOf({ sample_type_uuid: 's2' }, options), null);

    const lines = [{ sample_type_uuid: 's1', tube_type_uuid: '', quantity: 3 }, { sample_type_uuid: '', quantity: 2 }, { sample_type_uuid: 's2', quantity: 40 }];
    assert.deepEqual(sampleLinesPayload(lines), [
        { sample_type_uuid: 's1', tube_type_uuid: null, quantity: 3 },
        { sample_type_uuid: 's2', tube_type_uuid: null, quantity: 10 },
    ]);
    assert.equal(sampleLinesTubeCount(lines), 13);
    assert.equal(emptySampleLine({ sample_types: [options.sample_types[0]] }).sample_type_uuid, 's1');
    assert.equal(emptySampleLine(options).sample_type_uuid, '');
});

test('règlement : une information, jamais un verrou — aucune somme, rien quand il n’y a rien à dire (ADR-217)', () => {
    assert.equal(paymentBadge(null), null);
    assert.equal(paymentBadge({ cleared: true, exemption: 'EMERGENCY', exemption_label: 'Urgence' }), null);
    assert.equal(paymentBadge({ cleared: true, exemption: 'HOSPITALIZED', exemption_label: 'Hospitalisé' }).label, 'Hospitalisé');
    assert.equal(paymentBadge({ cleared: true, exemption: null, unbilled_count: 0 }), null);
    assert.equal(paymentBadge({ cleared: true, exemption: null, unbilled_count: 1 }).tone, 'info');
    const due = paymentBadge({ cleared: false, exemption: null, due_count: 2 });
    assert.equal(due.tone, 'warning');
    assert.match(due.hint, /n’empêche pas/);
    assert.doesNotMatch(JSON.stringify(due), /Ar\b|\d{4,}/, 'aucun montant (ADR-014)');
});

test('la file commence par « À traiter », et chaque vue a son libellé (ADR-217)', () => {
    assert.equal(LAB_VIEWS[0].value, 'to_do');
    assert.ok(!LAB_VIEWS.some((view) => view.value === 'to_receive'), 'plus de vue « À réceptionner »');
    for (const view of LAB_VIEWS.filter((entry) => !['all', 'archived'].includes(entry.value))) {
        assert.ok(LAB_STATE_LABELS[view.value], `libellé d’état manquant pour ${view.value}`);
    }
});

test('chaque ligne porte son geste : Traiter, Continuer, Reprendre — Voir sans le droit de commencer', () => {
    assert.equal(rowAction({ action: 'start' }), 'start');
    assert.equal(rowAction({ action: 'start' }, false), 'open');
    assert.equal(rowAction({ action: 'continue' }, false), 'continue');
    assert.equal(rowAction({}), 'open');
    for (const key of ['start', 'continue', 'redo', 'send', 'open']) assert.ok(LAB_ROW_ACTIONS[key]?.label, key);
});

test('l’avancement d’une analyse se lit sur ce qui attend un résultat', () => {
    const item = { nodes: [{ takes_result: true, result: { value: '1' } }, { takes_result: true, result: null }, { takes_result: false, result: null }] };
    assert.deepEqual(itemProgress(item), { done: 1, total: 2, ratio: 0.5 });
    assert.deepEqual(itemProgress({}), { done: 0, total: 0, ratio: 0 });
});

test('les feuilles imprimables du laboratoire n’imposent pas leur format aux autres pages', () => {
    // Une règle `@page` écrite dans un style resterait chargée après la navigation.
    for (const page of ['Labels', 'Worklist', 'SendOutSlip', 'ResultsPrint']) {
        const source = fs.readFileSync(`resources/js/Pages/Laboratory/${page}.vue`, 'utf8');
        const styles = source.split('<style').slice(1).join('');
        assert.doesNotMatch(styles, /@page/, `${page} : @page doit être injectée au montage`);
    }
});

test('la saisie n’attend plus la réception : la demande se prend en charge à la première saisie (ADR-217)', () => {
    const editor = fs.readFileSync('resources/js/Components/Laboratory/LabItemEditor.vue', 'utf8');
    assert.doesNotMatch(editor, /props\.received/);
    assert.doesNotMatch(editor, /Réceptionnez d’abord/);
    const show = fs.readFileSync('resources/js/Pages/Laboratory/Show.vue', 'utf8');
    assert.match(show, /<LabReceptionPanel[\s\S]*v-if="!labRequest\.received/);
    const panel = fs.readFileSync('resources/js/Components/Laboratory/LabReceptionPanel.vue', 'utf8');
    assert.doesNotMatch(panel, /:disabled="!payment\.cleared/, 'le règlement ne grise plus rien');
});
