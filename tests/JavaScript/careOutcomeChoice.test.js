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
    assert.match(sheet, /<Badge variant="outline">Prévu à l’arrivée<\/Badge>/);
});

test('la suite prévue se lit seulement ; la suite non prévue se clique et demande un motif', () => {
    // Carte prévue : un simple div, jamais un bouton.
    assert.match(sheet, /<div\s+v-if="option\.value === plannedOutcome"\s+:aria-current=/);
    // Carte non prévue : un interrupteur qui ouvre le motif ; un second clic revient au prévu.
    assert.match(sheet, /:aria-pressed="deviatesFromPlan"[\s\S]*?@click="toggleOffPlan"/);
    assert.match(sheet, /<Badge variant="outline">Changer · motif<\/Badge>/);
    assert.match(sheet, /@click="chooseOutcome\(plannedOutcome\)">Garder la suite prévue<\/button>/);
});

test('les deux suites sont proposées en boutons radio accessibles', () => {
    // Besoin inconnu : rien de prévu, les deux suites restent des radios.
    assert.match(sheet, /role="radiogroup" aria-labelledby="care-outcome-title"/);
    assert.match(sheet, /role="radio"\s+:aria-checked="form\.care_outcome === option\.value"/);
    assert.match(sheet, /title: 'Transmettre au médecin'/);
    assert.match(sheet, /title: 'Terminer aux Soins'/);
});

test('aucun choix quand le médecin a demandé les soins ou a déjà le patient', () => {
    assert.match(sheet, /const offersOutcomeChoice = computed\(\(\) => !activeCareOrder\.value\s+&& !props\.medicineAlreadyInvolved/);
    assert.match(sheet, /<fieldset v-if="offersOutcomeChoice"/);
});

test('toute suite non prévue exige un motif, dans les deux sens', () => {
    assert.match(sheet, /const deviatesFromPlan = computed\(\(\) => Boolean\(plannedOutcome\.value && chosenOutcome\.value && chosenOutcome\.value !== plannedOutcome\.value\)\)/);
    assert.match(sheet, /<FormField\s+v-if="deviatesFromPlan"[\s\S]*?required[\s\S]*?id="care_outcome_reason"/);
    assert.match(sheet, /if \(deviatesFromPlan\.value\) return trimmed\(form\.care_outcome_reason\)\.length > 0;/);
});

test('changer seulement la suite termine sans écrire de fiche vide', () => {
    assert.match(sheet, /const OUTCOME_KEYS = \['care_outcome', 'care_outcome_reason'\];/);
    assert.match(sheet, /const finishCare = \(\) => \(needsSaving\.value \? submitAndComplete\(\) : completeWithoutSaving\(\)\);/);
    assert.match(sheet, /\.post\(`\/care\/orientations\/\$\{props\.orientation\.uuid\}\/complete`/);
});

test('la suite choisie voyage avec la fiche, et ne recopie jamais un booléen ambigu', () => {
    assert.match(sheet, /if \(outcome\) payload\.care_outcome = outcome;/);
    assert.doesNotMatch(sheet, /payload\.orient_to_medicine/);
});

/** ADR-167 — la suite verrouillée se montre ; une seule fenêtre pour la reprendre. */
test('un collègue voit la suite verrouillée et peut reprendre la prise en charge', () => {
    // La suite n'est montrée que si elle se choisit (pas d'ordre de soins, Médecine pas encore là).
    assert.match(sheet, /<section v-else-if="capabilities\.handled_by_other && !activeCareOrder && !medicineAlreadyInvolved"/);
    // Une suite verrouillée se clique : elle ouvre la fenêtre et reste retenue.
    assert.match(sheet, /:disabled="!capabilities\.can_take_over"[\s\S]*?@click="openTakeOver\(option\.value\)"/);
    assert.match(sheet, /if \(outcome\) chooseOutcome\(outcome\);\s+if \(changesPlan\) form\.care_outcome_reason = reason;/);
    // La suite prévue se voit d'emblée, même verrouillée : bordure bleue, et ne se clique pas.
    assert.match(sheet, /aria-current="true"\s+class="flex items-center gap-3 rounded-lg border border-primary bg-primary\/5/);
    // Garder la suite prévue et reprendre : le pied de l'étape ouvre la même fenêtre.
    assert.match(sheet, /<Button v-if="capabilities\.can_take_over" type="button" size="rg" variant="white-outline" @click="openTakeOver\(\)">/);
    assert.equal(sheet.match(/@click="openTakeOver\(\)"/g).length, 1);
    assert.equal(sheet.match(/<Dialog\s+v-model:open="takeOverOpen"/g).length, 1);
    assert.match(sheet, /takeOverForm\.post\(`\/care\/orientations\/\$\{props\.orientation\.uuid\}\/take-over`/);
    // On écrit un motif dans cette fenêtre : pas de fermeture sur un clic à côté.
    assert.match(sheet, /'Reprendre la prise en charge'"[\s\S]*?:dismissible="false"/);
});

test('une seule fenêtre : un motif, puis la case « Reprendre la prise en charge » au bas', () => {
    assert.match(sheet, /:title="takeOverChangesPlan \? 'Changer la suite prévue' : 'Reprendre la prise en charge'"/);
    // Le motif précède la case, dans le même formulaire.
    assert.match(sheet, /id="take_over_reason"[\s\S]*?<Checkbox id="take_over_confirm" v-model="takeOverConfirmed"/);
    // La case n'est jamais cochée d'avance, et la confirmation l'exige avec le motif.
    assert.match(sheet, /takeOverOutcome\.value = outcome;\s+takeOverConfirmed\.value = false;/);
    assert.match(sheet, /const takeOverReady = computed\(\(\) => takeOverConfirmed\.value && trimmed\(takeOverForm\.reason\)\.length > 0\);/);
    assert.match(sheet, /:disabled="takeOverForm\.processing \|\| !takeOverReady"/);
    assert.match(sheet, /if \(!takeOverReady\.value\) return;/);
});

test('plus aucun bandeau d’avertissement pour la reprise', () => {
    assert.doesNotMatch(sheet, /Cette décision revient à/);
    assert.doesNotMatch(sheet, /<div v-if="capabilities\.handled_by_other"/);
    // Sans le droit de reprendre, le droit manquant est nommé en texte simple.
    assert.match(sheet, /<p v-if="!capabilities\.can_take_over" class="mt-2 text-xs text-muted-foreground">/);
});
