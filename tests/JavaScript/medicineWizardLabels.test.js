import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Medicine/Show.vue', 'utf8');
const bar = fs.readFileSync('resources/js/Components/Medicine/ConsultationStepBar.vue', 'utf8');

/** Le code et le texte visible, commentaires retirés : ceux-ci citent les
 *  anciennes étapes pour expliquer pourquoi elles ont disparu. */
const visible = page
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/.*$/gm, '$1');

/**
 * Le parcours affiché doit être celui que le serveur applique :
 * Dossier → Interrogatoire → Examen clinique → Paraclinique → Prescription
 * → Décision & clôture. `Diagnostic` et `Décision médicale` ne sont plus des
 * étapes (ADR-081, ADR-084) ; aucun texte ne doit les annoncer comme telles.
 */

test('aucun texte n’annonce une étape Diagnostic ou Décision médicale', () => {
    assert.doesNotMatch(visible, /passe au diagnostic/i);
    assert.doesNotMatch(visible, /étape [Dd]iagnostic/);
    assert.doesNotMatch(visible, /vers le diagnostic|vers la décision/i);
    assert.doesNotMatch(visible, /Décision médicale/);
});

/**
 * La suite du parcours est nommée par le serveur. Écrire « Prescription »
 * en dur la ferait mentir le jour où cette étape devient sans objet.
 */
test('la suite après « Aucun examen » vient du serveur', () => {
    assert.match(page, /nextStep\.label\.toLocaleLowerCase\('fr'\)/);
});

test('le bouton « continuer » nomme l’étape que le serveur annonce', () => {
    assert.match(bar, /Suivant : \$\{props\.next\.label\.toLocaleLowerCase\('fr'\)\}/);
});

/** Les noms de payload restent : les renommer imposerait une migration front. */
test('les noms techniques du payload sont conservés', () => {
    assert.match(page, /continue_to_diagnosis: true/);
    assert.match(page, /continue_to_decision: true/);
});
