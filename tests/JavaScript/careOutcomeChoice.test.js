import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/**
 * ADR-166 — à l'étape Terminer, l'infirmier choisit la suite : transmettre au
 * médecin, ou terminer aux Soins — pré-rempli selon le parcours prévu.
 */
const sheet = fs.readFileSync('resources/js/Pages/Care/Show.vue', 'utf8');

test('le choix est pré-rempli selon le parcours prévu, vide pour un besoin inconnu', () => {
    assert.match(sheet, /care_outcome: \(\{ MEDICINE: 'MEDICINE', FINISH: 'FINISH' \}\)\[props\.orientation\.episode\.care_completion_mode\] \?\? ''/);
    assert.match(sheet, /<Badge v-if="plannedOutcome === option\.value" variant="outline">Prévu à l’arrivée<\/Badge>/);
});

test('les deux suites sont proposées en boutons radio accessibles', () => {
    assert.match(sheet, /role="radiogroup" aria-labelledby="care-outcome-title"/);
    assert.match(sheet, /role="radio"\s+:aria-checked="form\.care_outcome === option\.value"/);
    assert.match(sheet, /title: 'Transmettre au médecin'/);
    assert.match(sheet, /title: 'Terminer aux Soins'/);
});

test('aucun choix quand le médecin a demandé les soins ou a déjà le patient', () => {
    assert.match(sheet, /const offersOutcomeChoice = computed\(\(\) => !activeCareOrder\.value\s+&& !props\.medicineAlreadyInvolved/);
    assert.match(sheet, /<fieldset v-if="offersOutcomeChoice"/);
});

test('terminer aux Soins un patient attendu en Médecine exige un motif', () => {
    assert.match(sheet, /const skipsPlannedMedicine = computed\(\(\) => plannedOutcome\.value === 'MEDICINE' && chosenOutcome\.value === 'FINISH'\)/);
    assert.match(sheet, /<FormField\s+v-if="skipsPlannedMedicine"[\s\S]*?required[\s\S]*?id="care_finish_reason"/);
    assert.match(sheet, /if \(skipsPlannedMedicine\.value\) return trimmed\(form\.care_finish_reason\)\.length > 0;/);
});

test('changer seulement la suite termine sans écrire de fiche vide', () => {
    assert.match(sheet, /const OUTCOME_KEYS = \['care_outcome', 'care_finish_reason'\];/);
    assert.match(sheet, /const finishCare = \(\) => \(needsSaving\.value \? submitAndComplete\(\) : completeWithoutSaving\(\)\);/);
    assert.match(sheet, /\.post\(`\/care\/orientations\/\$\{props\.orientation\.uuid\}\/complete`/);
});

test('la suite choisie voyage avec la fiche, et ne recopie jamais un booléen ambigu', () => {
    assert.match(sheet, /if \(outcome\) payload\.care_outcome = outcome;/);
    assert.doesNotMatch(sheet, /payload\.orient_to_medicine/);
});
