import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
    effectiveInterpretation, entryFilled, entryFromNode, nugentReading, nugentScore,
    rangeFlag, resultText, resultsPayload, suggestedInterpretation,
} from '../../resources/js/utilities/labWorkbench.js';

/** ADR-213 — les règles d'écran de la paillasse. Le serveur reste juge. */

const numeric = { uuid: 'a', entry_mode: 'NUMERIC', interpretable: true, range: { min: 0.7, max: 1.1 } };

test('la position par rapport à la référence se lit avec une virgule', () => {
    assert.equal(rangeFlag(numeric.range, '1,45'), 'HIGH');
    assert.equal(rangeFlag(numeric.range, '0.5'), 'LOW');
    assert.equal(rangeFlag(numeric.range, '0,9'), 'NORMAL');
    assert.equal(rangeFlag(numeric.range, 'élevée'), null);
    assert.equal(rangeFlag(null, '2'), null);
});

test('l’interprétation est proposée, jamais imposée', () => {
    assert.equal(suggestedInterpretation(numeric, { value: '2' }), 'PATHOLOGICAL');
    assert.equal(suggestedInterpretation(numeric, { value: '1' }), 'NORMAL');
    // Choisie par le technicien, elle l'emporte sur la proposition.
    assert.equal(effectiveInterpretation(numeric, { value: '2', interpretation_set: true, interpretation: 'NORMAL' }), 'NORMAL');
    assert.equal(effectiveInterpretation(numeric, { value: '2', interpretation_set: false }), 'PATHOLOGICAL');
});

test('Nugent : un score seulement avec les trois sous-scores', () => {
    assert.equal(nugentScore({ lactobacilli: 4, gardnerella: 3 }), null);
    assert.equal(nugentScore({ lactobacilli: 4, gardnerella: 3, mobiluncus: 1 }), 8);
    assert.equal(nugentReading(2).suggested, 'NORMAL');
    assert.equal(nugentReading(5).suggested, null);
    assert.equal(nugentReading(9).suggested, 'PATHOLOGICAL');
});

test('une interprétation qui suit la proposition n’est pas renvoyée comme un choix', () => {
    const entry = entryFromNode({ ...numeric, result: { value: '2', interpretation: 'PATHOLOGICAL', selections: null } });
    assert.equal(entry.interpretation_set, false);
    assert.equal('interpretation' in resultsPayload([entry])[0], false);

    const chosen = entryFromNode({ ...numeric, result: { value: '2', interpretation: 'NORMAL', selections: null } });
    assert.equal(chosen.interpretation_set, true);
    assert.equal(resultsPayload([chosen])[0].interpretation, 'NORMAL');
});

test('une ligne vide n’est pas comptée comme saisie', () => {
    assert.equal(entryFilled(numeric, { value: '  ' }), false);
    assert.equal(entryFilled({ entry_mode: 'MULTI_CHOICE' }, { selections: ['A'] }), true);
    assert.equal(entryFilled({ entry_mode: 'NUGENT' }, { selections: { lactobacilli: 0 } }), true);
});

test('un résultat se lit comme il s’imprime', () => {
    const options = { culture: [{ value: 'GROWTH', label: 'Présence de germe(s)' }] };
    const culture = {
        entry_mode: 'CULTURE',
        result: { value: 'GROWTH', selections: { bacteria: ['x'] } },
        antibiograms: [{ bacterium: 'Escherichia coli' }],
    };
    assert.equal(resultText(culture, options), 'Présence de germe(s) : Escherichia coli');
    assert.equal(
        resultText({ entry_mode: 'NUGENT', result: { value: '8', selections: { lactobacilli: 4, gardnerella: 3, mobiluncus: 1 } } }),
        'Score 8/10 — Vaginose bactérienne',
    );
    assert.equal(resultText({ entry_mode: 'NEG_POS_VALUE', result: { value: 'Positif', selections: { detail: '1/160' } } }), 'Positif (1/160)');
});

test('la paillasse n’encaisse rien et ne montre aucun montant', () => {
    for (const file of [
        'resources/js/Pages/Laboratory/Index.vue',
        'resources/js/Pages/Laboratory/Show.vue',
        'resources/js/Components/Laboratory/LabItemEditor.vue',
    ]) {
        const source = fs.readFileSync(file, 'utf8');
        assert.doesNotMatch(source, /formatMoney|\/cash|payments\./, `${file} touche à l'argent`);
    }
});
