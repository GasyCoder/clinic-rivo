import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { checkDuplicates, checkLine, parseAmount, worstLevel } from '../../resources/js/utilities/prescriptionChecks.js';

/**
 * ADR-128 — ce que le système relit d'une ligne d'ordonnance, et ce qu'il
 * refuse de prétendre juger.
 */
const ceftriaxone = { uuid: 'm1', name: 'Ceftriaxone 1 g', generic_name: 'Ceftriaxone', form: 'INJECTABLE', form_label: 'Injectable', strength: '1 g', unit: 'flacon' };
const line = (over = {}) => ({
    manual: false, medicine_uuid: 'm1', dosage: '1000 mg', route: 'IV', frequency: '2 fois/jour',
    _duration_amount: '10', _duration_unit: 'jours', quantity: 20, ...over,
});
const patient = (over = {}) => ({ age: 32, weightKg: 70, allergyConflict: null, allergies: [], ...over });
const codes = (alerts) => alerts.map((alert) => alert.code);

test('un produit injectable donné par voie orale est une erreur de voie', () => {
    const alerts = checkLine({ line: line({ route: 'ORAL' }), medicine: ceftriaxone, patient: patient() });

    assert.ok(codes(alerts).includes('route-form'));
    assert.equal(alerts.find((a) => a.code === 'route-form').level, 'danger');
    assert.equal(worstLevel(alerts), 'danger');
});

test('un comprimé prescrit en intraveineuse est une erreur de voie', () => {
    const tablet = { ...ceftriaxone, form: 'TABLET', form_label: 'Comprimé', strength: '500 mg' };
    const alerts = checkLine({ line: line({ route: 'IV' }), medicine: tablet, patient: patient() });

    assert.ok(codes(alerts).includes('route-form'));
});

test('une voie cohérente ne déclenche rien', () => {
    assert.deepEqual(checkLine({ line: line(), medicine: ceftriaxone, patient: patient() }), []);
});

test('un injectable sans voie précisée est signalé, pas refusé', () => {
    const alerts = checkLine({ line: line({ route: null }), medicine: ceftriaxone, patient: patient() });

    assert.equal(alerts.find((a) => a.code === 'route-missing').level, 'warning');
});

test('une fréquence « 2 » ne dit pas par quoi compter', () => {
    const alerts = checkLine({ line: line({ frequency: '2' }), medicine: ceftriaxone, patient: patient() });

    assert.ok(codes(alerts).includes('frequency'));
    assert.match(alerts.find((a) => a.code === 'frequency').message, /2 fois\/jour/);
});

test('la quantité est comparée à ce que le traitement consomme, avec le dosage du produit', () => {
    // 1000 mg / flacon de 1 g = 1 flacon par prise, 2/jour, 10 jours = 20.
    const alerts = checkLine({ line: line({ quantity: 1 }), medicine: ceftriaxone, patient: patient() });
    const quantity = alerts.find((a) => a.code === 'quantity');

    assert.ok(quantity);
    assert.match(quantity.message, /= 20\. Vous en prescrivez 1\./);
    assert.equal(codes(checkLine({ line: line({ quantity: 20 }), medicine: ceftriaxone, patient: patient() })).includes('quantity'), false);
});

test('une dose de 1000 mg sur des comprimés de 500 mg compte deux comprimés par prise', () => {
    const tablet = { ...ceftriaxone, form: 'TABLET', form_label: 'Comprimé', strength: '500 mg', unit: 'comprimé' };
    const alerts = checkLine({ line: line({ route: 'ORAL', frequency: '3 fois/jour', _duration_amount: '5', quantity: 15 }), medicine: tablet, patient: patient() });

    assert.match(alerts.find((a) => a.code === 'quantity').message, /2 comprimé par prise × 3\/jour × 5 jours = 30/);
});

test('un dosage qu’on ne sait pas lire ne fabrique aucun contrôle de quantité', () => {
    const syrup = { ...ceftriaxone, form: 'SYRUP', form_label: 'Sirop', strength: '250 mg/5 ml' };

    assert.equal(parseAmount('250 mg/5 ml'), null);
    assert.equal(codes(checkLine({ line: line({ route: 'ORAL', quantity: 1 }), medicine: syrup, patient: patient() })).includes('quantity'), false);
});

test('un enfant sans poids relevé : le système le dit', () => {
    const alerts = checkLine({ line: line(), medicine: ceftriaxone, patient: patient({ age: 4, weightKg: null }) });

    assert.equal(alerts.find((a) => a.code === 'weight').level, 'warning');
});

test('un poids inconnu du contexte n’est jamais annoncé « non relevé »', () => {
    const alerts = checkLine({ line: line(), medicine: ceftriaxone, patient: patient({ age: 4, weightKg: undefined }) });

    assert.equal(codes(alerts).includes('weight'), false);
    assert.equal(codes(alerts).includes('dose-per-kg'), false);
});

test('un enfant pesé : la dose par kilo est calculée, et le système avoue ne pas connaître de maximum', () => {
    const alerts = checkLine({ line: line(), medicine: ceftriaxone, patient: patient({ age: 4, weightKg: 16 }) });
    const perKg = alerts.find((a) => a.code === 'dose-per-kg');

    assert.ok(perKg);
    assert.equal(perKg.level, 'info');
    assert.match(perKg.message, /62,5 mg\/kg par prise · 125 mg\/kg\/jour/);
    // Il ne prétend pas avoir jugé : jamais un « dose trop élevée » inventé.
    assert.match(perKg.message, /Aucune dose maximale n’est enregistrée/);
    assert.equal(perKg.level === 'danger', false);
});

test('un adulte n’a ni alerte de poids ni dose par kilo', () => {
    const alerts = checkLine({ line: line(), medicine: ceftriaxone, patient: patient() });

    assert.equal(codes(alerts).includes('dose-per-kg'), false);
    assert.equal(codes(alerts).includes('weight'), false);
});

test('une allergie du catalogue, rapprochée par le serveur, est une alerte rouge', () => {
    const alerts = checkLine({ line: line(), medicine: ceftriaxone, patient: patient({ allergyConflict: 'Ceftriaxone' }) });

    assert.equal(alerts[0].code, 'allergy');
    assert.equal(alerts[0].level, 'danger');
});

test('une ligne manuelle est comparée aux allergies sur son nom, par mots entiers', () => {
    const manual = { manual: true, medication_name: 'Pénicilline V', dosage: '500 mg', route: 'ORAL', frequency: '3 fois/jour', _duration_amount: '7', quantity: 21 };

    assert.ok(codes(checkLine({ line: manual, medicine: null, patient: patient({ allergies: ['penicilline'] }) })).includes('allergy'));
    assert.equal(codes(checkLine({ line: { ...manual, medication_name: 'Paracétamol' }, medicine: null, patient: patient({ allergies: ['penicilline'] }) })).includes('allergy'), false);
});

test('un produit qui ne se dose pas n’est pas soumis aux contrôles de dose', () => {
    const gauze = { uuid: 'm2', name: 'Compresses', generic_name: 'Compresses', form: 'PARAPHARMACY_CONSUMABLE', form_label: 'Parapharmacie', strength: null, unit: 'paquet' };

    assert.deepEqual(checkLine({ line: { manual: false, medicine_uuid: 'm2', frequency: '', quantity: 1 }, medicine: gauze, patient: patient({ age: 4, weightKg: null }) }), []);
});

test('le même principe actif deux fois est signalé sur la seconde ligne', () => {
    const lines = [line({ medicine_uuid: 'm1' }), line({ medicine_uuid: 'm3' })];
    const medicines = { m1: ceftriaxone, m3: { ...ceftriaxone, uuid: 'm3', name: 'Rocéphine' } };
    const result = checkDuplicates({ lines, medicineFor: (l) => medicines[l.medicine_uuid] });

    assert.equal(result[0], undefined);
    assert.equal(result[1][0].code, 'duplicate');
});

test('les alertes se disent par toast, sans bloc sous la ligne, et ne bloquent jamais la validation', () => {
    const show = fs.readFileSync('resources/js/Pages/Medicine/Show.vue', 'utf8');

    // Plus de liste d'alertes sous chaque ligne : elle occupait la page.
    assert.equal(show.includes('<PrescriptionAlerts :alerts="prescriptionAlerts[index]'), false);
    // Le rouge et l'ambre parlent par toast ; l'information reste dans la fenêtre de signature.
    assert.match(show, /alert\.level !== 'info'/);
    assert.match(show, /toast\.error : toast\.warning/);
    assert.match(show, /<PrescriptionAlerts :alerts="prescriptionAlerts\[prescriptionForm\.lines\.indexOf\(line\)\]/);
    // Les alertes ne font pas partie de la garde qui grise « Valider ».
    const guard = show.slice(show.indexOf('const prescriptionStockIsValid'), show.indexOf('const pendingPrescriptionSelection'));

    assert.equal(guard.includes('prescriptionAlerts'), false);
});
