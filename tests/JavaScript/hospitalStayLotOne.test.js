import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/** ADR-161 — ce que le séjour promet à l'écran ; le build ne le voit pas. */
const stay = fs.readFileSync('resources/js/Pages/Hospitalization/Show.vue', 'utf8');
const index = fs.readFileSync('resources/js/Pages/Hospitalization/Index.vue', 'utf8');

test('changer de service ouvre un nouvel emplacement, corriger ne déplace rien', () => {
    assert.match(stay, /\/hospitalisation\/\$\{props\.stay\.uuid\}\/mouvements/);
    assert.match(stay, /Changer de service \/ lit/);
    assert.match(stay, /Corriger l’emplacement actuel/);
    assert.match(stay, /Emplacements précédents/);
});

test('la surveillance ajoute des relevés et affiche les repères du serveur', () => {
    assert.match(stay, /\/hospitalisation\/\$\{props\.stay\.uuid\}\/surveillance/);
    assert.match(stay, /reading\.alerts/);
    // Aucun seuil n'est recopié dans l'écran : le serveur classe chaque relevé.
    assert.doesNotMatch(stay, /temperature_celsius\s*>=?\s*3[89]/);
});

test('un séjour terminé par un transfert dit où le patient est parti', () => {
    assert.match(stay, /stay\.end_reason === 'TRANSFER' && stay\.transfer/);
});

test('la liste signale la réanimation et la surveillance continue', () => {
    assert.match(index, /stay\.care_level !== 'STANDARD'/);
});
