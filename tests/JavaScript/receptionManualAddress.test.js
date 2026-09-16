import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const create = fs.readFileSync('resources/js/Pages/Reception/Create.vue', 'utf8');

const addressField = () => {
    const at = create.indexOf('label="Adresse"');
    assert.notEqual(at, -1, 'le champ Adresse est introuvable');

    return create.slice(at, create.indexOf('</FormField>', at));
};

/**
 * Le backend accepte déjà `new_address_label` — il crée l'entrée du
 * référentiel et la rattache au patient. Seul l'écran ne l'exposait pas :
 * une adresse absente de la liste n'avait aucun moyen d'être saisie.
 */
test('une adresse absente de la liste peut être saisie', () => {
    const block = addressField();

    assert.match(block, /addressMode === 'existing'/);
    assert.match(block, /v-model="patientForm.new_address_label"/);
});

/** Le geste vit dans la ligne de libellé, comme le sélecteur Date/Âge. */
test('la bascule occupe le slot du libellé', () => {
    const block = addressField();

    assert.match(block, /<template v-if="capabilities.can_create_address" #action>/);
    assert.match(block, /px-2 py-0\.5 text-\[10px\]/);
});

/**
 * `StoreArrivalRequest` refuse explicitement les deux champs ensemble
 * (« Choisissez une adresse existante ou ajoutez-en une nouvelle, pas les
 * deux »). La bascule vide donc l'autre, et l'envoi ne porte que le champ
 * du mode réellement choisi.
 */
test('les deux champs ne partent jamais ensemble', () => {
    assert.match(create, /if \(mode === 'new'\) patientForm\.address_entry_uuid = '';\s*\n\s*else patientForm\.new_address_label = '';/);
    assert.match(create, /address_entry_uuid: addressMode\.value === 'new' \? null :/);
    assert.match(create, /new_address_label: addressMode\.value === 'new' \?.*: null,/);
});

/** L'erreur du champ manuel doit être lisible, pas avalée. */
test('l’erreur de la saisie manuelle est affichée', () => {
    const block = addressField();

    assert.match(block, /firstError\(arrivalErrors, 'new_address_label'\)/);
});
