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
    const run = new Function('props', 'selectedDiagnoses', 'isDeceased', `return (${expression});`);

    return ({ requiresDiagnosis = true, diagnoses = 0, patientCondition = '', disabled = false, type = 'NORMAL' }) => run(
        { disabled, requiresDiagnosis, form: { processing: false, type, patient_condition: patientCondition } },
        { value: { size: diagnoses } },
        // Le composant le dérive du type ; le harnais fait de même plutôt
        // que de le poser à la main, sinon le test pourrait rester vert sur
        // une règle que l'écran n'applique plus.
        { value: type === 'DECEASED' },
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

test('l’état du patient reste exigé pour une sortie ordinaire — CDC §33.1', () => {
    assert.equal(canSubmit({ requiresDiagnosis: false, diagnoses: 0, patientCondition: '' }), false);
    assert.equal(canSubmit({ requiresDiagnosis: true, diagnoses: 1, patientCondition: '' }), false);
});

/**
 * ADR-107 — pour un décès, l'état du patient n'est pas demandé : le type de
 * sortie *est* la réponse, et le serveur la pose. Continuer à l'exiger
 * ici laisserait le bouton grisé sur une case qui n'existe plus à l'écran
 * — exactement le défaut qu'avait corrigé l'ADR-094.
 */
test('un décès ne réclame pas un état du patient que l’écran ne demande plus', () => {
    assert.equal(canSubmit({ type: 'DECEASED', requiresDiagnosis: false, patientCondition: '' }), true);
});

/** Le diagnostic, lui, garde sa règle : un décès ne l'exempte de rien. */
test('un décès ne dispense pas du diagnostic quand il est dû', () => {
    assert.equal(canSubmit({ type: 'DECEASED', requiresDiagnosis: true, diagnoses: 0, patientCondition: '' }), false);
    assert.equal(canSubmit({ type: 'DECEASED', requiresDiagnosis: true, diagnoses: 1, patientCondition: '' }), true);
});

test('un formulaire en lecture seule ne transmet jamais', () => {
    assert.equal(canSubmit({ requiresDiagnosis: false, patientCondition: 'Guéri', disabled: true }), false);
});

test('l’indication affichée suit la même règle que le bouton', () => {
    assert.match(form, /requiresDiagnosis && !selectedDiagnoses\.size \? 'Cochez au moins un diagnostic\.'/);
});
