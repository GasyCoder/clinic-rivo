import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const form = fs.readFileSync('resources/js/Components/Clinical/ClinicalDischargeForm.vue', 'utf8');

/**
 * Le garde-fou de soumission du formulaire de sortie, exécuté pour de vrai.
 *
 * Il avait été oublié lors de l'ADR-094 : le libellé disait « facultatif »
 * pendant que le bouton restait grisé sur « Cochez au moins un diagnostic ».
 * Un écran qui n'exige plus une donnée ne doit pas continuer à la réclamer
 * en silence.
 */
const OPEN = 'const canSubmit = computed(() => ';

const canSubmit = (() => {
    const start = form.indexOf(OPEN);
    assert.notEqual(start, -1, 'le garde-fou canSubmit a disparu');

    const bodyStart = start + OPEN.length;
    const expression = form.slice(bodyStart, form.indexOf('\n\n', bodyStart)).trim().replace(/\);$/, '');

    // eslint-disable-next-line no-new-func
    const run = new Function('props', 'selectedDiagnoses', `return (${expression});`);

    return ({ requiresDiagnosis = true, diagnoses = 0, patientCondition = '', disabled = false }) => run(
        { disabled, requiresDiagnosis, form: { processing: false, patient_condition: patientCondition } },
        { value: { size: diagnoses } },
    );
})();

test('passage paraclinique seul : l’état du patient suffit à transmettre', () => {
    assert.equal(canSubmit({ requiresDiagnosis: false, diagnoses: 0, patientCondition: 'Guéri' }), true);
});

test('consultation ordinaire : sans diagnostic coché, la sortie ne part pas', () => {
    assert.equal(canSubmit({ requiresDiagnosis: true, diagnoses: 0, patientCondition: 'Guéri' }), false);
});

test('consultation ordinaire : un diagnostic coché débloque la sortie', () => {
    assert.equal(canSubmit({ requiresDiagnosis: true, diagnoses: 1, patientCondition: 'Guéri' }), true);
});

test('l’état du patient reste exigé dans tous les cas — CDC §33.1', () => {
    assert.equal(canSubmit({ requiresDiagnosis: false, diagnoses: 0, patientCondition: '' }), false);
    assert.equal(canSubmit({ requiresDiagnosis: true, diagnoses: 1, patientCondition: '' }), false);
});

test('un formulaire en lecture seule ne transmet jamais', () => {
    assert.equal(canSubmit({ requiresDiagnosis: false, patientCondition: 'Guéri', disabled: true }), false);
});

test('l’indication affichée suit la même règle que le bouton', () => {
    assert.match(form, /requiresDiagnosis && !selectedDiagnoses\.size \? 'Cochez au moins un diagnostic\.'/);
});
