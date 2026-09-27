import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Medicine/MedicalRecordPrint.vue', 'utf8');

/** ADR-143 : la Maternité est une section du même dossier médical, jamais un second dossier. */
test('la section Maternité rejoint la feuille du dossier médical', () => {
    assert.match(page, /maternity: \{ type: Object, default: null \}/);
    assert.match(page, /<template v-if="maternity">/);
    // Pas de droit : la section se nomme restreinte, elle ne se lit jamais comme « rien consigné ».
    assert.match(page, /maternity\.restricted/);
    assert.match(page, /restricted\('maternity\.view'\)/);
});

test('chaque bébé a son bloc, numéroté quand ils sont plusieurs', () => {
    assert.match(page, /v-for="newborn in maternity\.newborns"/);
    assert.match(page, /NOUVEAU-NÉ \$\{newborn\.rank\}/);
    // L'ancienne note commune n'apparaît que si elle existe.
    assert.match(page, /v-if="maternity\.baby_care_notes_legacy"/);
});

test('un bloc Maternité ne se coupe pas entre deux pages et garde les retours à la ligne', () => {
    assert.match(page, /\.mrp-block \{\s*break-inside: avoid;/);
    assert.match(page, /white-space: pre-line/);
});

test('aucun libellé de code n\'est recopié dans l\'écran : le serveur les sert', () => {
    // Les libellés (Voie basse, Rompues, Féminin…) viennent de MaternitySheetSection.
    assert.doesNotMatch(page, /VAGINAL|RUPTURED|UNDETERMINED/);
});
