import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
    apgarHints,
    birthWeightHints,
    dilationHints,
    fetalHeartRateHints,
    fundalHeightHints,
    futureDateHints,
    gestationalAgeFromLastPeriod,
    gestationalAgeHints,
    laborTimingHints,
    newbornCountHints,
    parityHints,
    pregnancyFromLastPeriod,
    worstLevel,
} from '../../resources/js/utilities/maternityChecks.js';

/**
 * ADR-137 — ce que le dossier Maternité rappelle sous un champ. Une aide au
 * dépistage : un message, jamais un verrou. Les seuils viennent du serveur ;
 * ici on vérifie qu'ils sont lus, pas qu'ils sont recopiés.
 */
const ref = {
    birth_weight: { min: 100, max: 8000, extremely_low: 1000, very_low: 1500, low: 2500, high: 4000, very_high: 5000 },
    apgar: { max: 10, low_max: 3, moderate_max: 6 },
    gestational_age: { max: 45, extremely_preterm: 28, preterm: 37, post_term: 42 },
    fundal_height: { max: 60, from_weeks: 20, to_weeks: 36, tolerance_cm: 3 },
    fetal_heart_rate: { min: 40, max: 250, low: 110, very_low: 100, high: 160, very_high: 180 },
    dilation: { max: 10 },
    pregnancy: { term_days: 280, implausible_days: 300 },
};
const codes = (hints) => hints.map((hint) => hint.code);
const now = new Date(2026, 8, 20, 12, 0);

test('un champ vide ne dit rien : une absence n’est ni normale ni anormale', () => {
    assert.deepEqual(birthWeightHints('', ref), []);
    assert.deepEqual(apgarHints(null, ref), []);
    assert.deepEqual(gestationalAgeHints(undefined, ref), []);
    assert.deepEqual(fetalHeartRateHints('  ', ref), []);
    assert.deepEqual(fundalHeightHints('', '', ref), []);
});

test('un poids saisi en kilos est reconnu comme une erreur d’unité, avant le refus du serveur', () => {
    // « 10 » pour 10 kg — l'exemple qui a motivé ces repères.
    const kilos = birthWeightHints('10', ref);
    assert.deepEqual(codes(kilos), ['weight-unit']);
    assert.match(kilos[0].message, /grammes/);
    assert.match(kilos[0].message, /3 200 pour 3,2 kg/);

    // « 10000 » : hors de la borne que le serveur refuse.
    const grams = birthWeightHints('10000', ref);
    assert.deepEqual(codes(grams), ['weight-too-high']);
    assert.equal(grams[0].level, 'danger');
});

test('un poids valide se convertit en kilos et se situe', () => {
    assert.deepEqual(birthWeightHints('3200', ref).map((h) => h.message), ['3 200 g = 3,2 kg']);
    assert.deepEqual(codes(birthWeightHints('2400', ref)), ['weight-kg', 'weight-low']);
    assert.deepEqual(codes(birthWeightHints('1400', ref)), ['weight-kg', 'weight-very-low']);
    assert.deepEqual(codes(birthWeightHints('900', ref)), ['weight-kg', 'weight-extremely-low']);
    assert.deepEqual(codes(birthWeightHints('4100', ref)), ['weight-kg', 'weight-high']);
    assert.deepEqual(codes(birthWeightHints('5200', ref)), ['weight-kg', 'weight-very-high']);
    // La virgule décimale est acceptée : « 3,2 » n'est pas ignoré.
    assert.deepEqual(codes(birthWeightHints('3,2', ref)), ['weight-unit']);
});

test('les seuils se lisent dans la référence servie, pas dans ce fichier', () => {
    const strict = { ...ref, birth_weight: { ...ref.birth_weight, low: 3000 } };

    assert.ok(codes(birthWeightHints('2800', strict)).includes('weight-low'));
    assert.ok(! codes(birthWeightHints('2800', ref)).includes('weight-low'));
});

test('l’Apgar distingue rassurant, modérément bas et bas', () => {
    assert.equal(apgarHints('9', ref)[0].level, 'info');
    assert.equal(apgarHints('5', ref)[0].level, 'warning');
    assert.equal(apgarHints('2', ref)[0].level, 'danger');
    assert.equal(apgarHints('0', ref)[0].level, 'danger');
});

test('le terme distingue prématuré, à terme et dépassé', () => {
    assert.deepEqual(codes(gestationalAgeHints('26', ref)), ['ga-extremely-preterm']);
    assert.deepEqual(codes(gestationalAgeHints('34', ref)), ['ga-preterm']);
    assert.deepEqual(codes(gestationalAgeHints('39', ref)), ['ga-term']);
    assert.deepEqual(codes(gestationalAgeHints('42', ref)), ['ga-post-term']);
});

test('la hauteur utérine se compare au terme, dans la fenêtre où la règle vaut', () => {
    assert.deepEqual(fundalHeightHints('30', '30', ref), []);
    assert.deepEqual(fundalHeightHints('32', '30', ref), [], 'dans la tolérance');
    assert.deepEqual(codes(fundalHeightHints('38', '30', ref)), ['fundal-gap']);
    assert.match(fundalHeightHints('22', '30', ref)[0].message, /inférieure/);
    // Avant 20 SA ou après 36 SA, la règle de McDonald ne vaut pas : aucun message.
    assert.deepEqual(fundalHeightHints('40', '12', ref), []);
    assert.deepEqual(fundalHeightHints('20', '39', ref), []);
    // Sans terme, on ne compare rien.
    assert.deepEqual(fundalHeightHints('30', '', ref), []);
});

test('le rythme cardiaque fœtal se situe dans 110-160', () => {
    assert.deepEqual(codes(fetalHeartRateHints('140', ref)), ['fhr-ok']);
    assert.deepEqual(codes(fetalHeartRateHints('105', ref)), ['fhr-low']);
    assert.deepEqual(codes(fetalHeartRateHints('90', ref)), ['fhr-very-low']);
    assert.deepEqual(codes(fetalHeartRateHints('170', ref)), ['fhr-high']);
    assert.deepEqual(codes(fetalHeartRateHints('190', ref)), ['fhr-very-high']);
});

test('la parité ne peut pas dépasser la gestité', () => {
    assert.deepEqual(codes(parityHints('2', '3')), ['parity-over-gravidity']);
    assert.deepEqual(parityHints('3', '2'), []);
    assert.deepEqual(parityHints('', '2'), []);
});

test('les dernières règles proposent le terme estimé (Naegele), jamais par-dessus une saisie', () => {
    const computed = pregnancyFromLastPeriod('2026-01-01', '', ref, now);

    assert.equal(computed.dueDate, '2026-10-08');
    assert.equal(computed.weeks, 37);
    assert.equal(computed.hints[0].action.value, '2026-10-08');

    // Un terme déjà saisi n'est pas remplacé : le message reste, sans bouton.
    const kept = pregnancyFromLastPeriod('2026-01-01', '2026-10-15', ref, now);
    assert.equal(kept.hints[0].action, undefined);

    assert.equal(pregnancyFromLastPeriod('', '', ref, now), null);
});

test('des dernières règles dans le futur ou très anciennes sont signalées', () => {
    assert.deepEqual(codes(pregnancyFromLastPeriod('2026-12-01', '', ref, now).hints), ['lmp-future']);
    assert.deepEqual(codes(pregnancyFromLastPeriod('2025-01-01', '', ref, now).hints), ['lmp-too-old']);
});

test('l’âge de la grossesse calculé n’est proposé que dans un champ vide', () => {
    const proposed = gestationalAgeFromLastPeriod('2026-04-05', '', ref, now);
    assert.equal(proposed[0].action.value, 24);

    assert.equal(gestationalAgeFromLastPeriod('2026-04-05', '30', ref, now)[0].action, undefined);
    assert.deepEqual(gestationalAgeFromLastPeriod('', '', ref, now), []);
});

test('les dates futures et incohérentes sont signalées', () => {
    assert.deepEqual(codes(futureDateHints('2026-12-01T10:00', 'Accouchement', now)), ['future-Accouchement']);
    assert.deepEqual(futureDateHints('2026-09-01T10:00', 'Accouchement', now), []);
    assert.deepEqual(codes(laborTimingHints('2026-09-20T10:00', '2026-09-19T10:00')), ['labor-after-delivery']);
    assert.deepEqual(laborTimingHints('2026-09-19T10:00', '2026-09-20T10:00'), []);
});

test('la dilatation complète et le nombre de nouveau-nés attendus s’annoncent', () => {
    assert.deepEqual(codes(dilationHints('10', ref)), ['dilation-complete']);
    assert.deepEqual(dilationHints('6', ref), []);
    assert.deepEqual(codes(newbornCountHints(1, 2)), ['newborn-count']);
    assert.deepEqual(newbornCountHints(2, 2), []);
    assert.deepEqual(newbornCountHints(1, null), []);
});

test('le plus grave des messages donne le repère visuel', () => {
    assert.equal(worstLevel([{ level: 'info' }, { level: 'danger' }, { level: 'warning' }]), 'danger');
    assert.equal(worstLevel([]), null);
});

/** Aucune aide ne doit empêcher d'enregistrer : rien ici ne touche à la soumission. */
test('le dossier affiche les repères sans bloquer l’enregistrement', () => {
    const page = fs.readFileSync('resources/js/Pages/Maternity/Show.vue', 'utf8');

    assert.match(page, /maternityReference: \{ type: Object, required: true \}/);
    assert.match(page, /<ClinicalFieldHints/);
    assert.doesNotMatch(page, /hints[^\n]*:disabled|disabled[^\n]*Hints/);

    // Les bornes des champs viennent de la référence, pas d'un chiffre recopié.
    assert.match(page, /:min="reference\.birth_weight\.min" :max="reference\.birth_weight\.max"/);
    assert.match(page, /:max="reference\.apgar\.max"/);
    assert.doesNotMatch(page, /min="100" max="8000"/);
});
