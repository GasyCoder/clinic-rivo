import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { isUndosedForm, quantityBasis, suggestedQuantity } from '../../resources/js/utilities/posology.js';

/**
 * ADR-110 — ce que la posologie permet de déduire, et ce qu'elle ne permet
 * pas. La seconde moitié compte autant : un chiffre inventé sur une
 * ordonnance réserve du stock au nom d'un patient.
 */
test('la quantité suit la fréquence et la durée', () => {
    assert.equal(suggestedQuantity({ frequency: '3 fois/jour', durationAmount: '7', durationUnit: 'jours' }), 21);
    assert.equal(suggestedQuantity({ frequency: 'matin et soir', durationAmount: '5', durationUnit: 'jours' }), 10);
    assert.equal(suggestedQuantity({ frequency: 'toutes les 8 h', durationAmount: '3', durationUnit: 'jours' }), 9);

    // Une semaine vaut sept jours, un mois trente — la convention de
    // délivrance, écrite sous le champ.
    assert.equal(suggestedQuantity({ frequency: '1 fois/jour', durationAmount: '2', durationUnit: 'semaines' }), 14);
    assert.equal(suggestedQuantity({ frequency: '1 fois/jour', durationAmount: '1', durationUnit: 'mois' }), 30);
});

test('« prise unique » vaut les prises d’une seule journée', () => {
    assert.equal(suggestedQuantity({ frequency: '2 fois/jour', durationAmount: '', durationUnit: 'prise unique' }), 2);
});

/** Deviner un nombre dans une phrase libre inventerait une posologie. */
test('une fréquence sans cadence ne se calcule pas', () => {
    for (const frequency of ['si besoin', 'selon douleur', '', null, 'en cas de fièvre']) {
        assert.equal(suggestedQuantity({ frequency, durationAmount: '7', durationUnit: 'jours' }), null);
    }
});

test('une durée absente ou absurde ne se calcule pas', () => {
    for (const durationAmount of ['', '0', '-3', 'sept', null]) {
        assert.equal(suggestedQuantity({ frequency: '3 fois/jour', durationAmount, durationUnit: 'jours' }), null);
    }
});

/** Un total sans son calcul ne se relit pas. */
test('la base du calcul est écrite, jamais un chiffre nu', () => {
    assert.equal(
        quantityBasis({ frequency: '3 fois/jour', durationAmount: '7', durationUnit: 'jours' }),
        '3/jour × 7 jours · une unité par prise',
    );
    // Au-delà du jour, le total en jours est dit : c'est là que la
    // convention se voit.
    assert.match(quantityBasis({ frequency: '1 fois/jour', durationAmount: '1', durationUnit: 'mois' }), /\(30 jours\)/);
    assert.equal(quantityBasis({ frequency: 'si besoin', durationAmount: '7', durationUnit: 'jours' }), null);
});

/**
 * La forme du référentiel décide (ADR-036), jamais un libellé ni un code
 * (ADR-052).
 */
test('seul un consommable ne se dose pas', () => {
    assert.equal(isUndosedForm('PARAPHARMACY_CONSUMABLE'), true);

    for (const form of ['TABLET', 'INJECTABLE', 'LIQUID', 'SYRUP', 'SACHET', 'OINTMENT_CREAM', 'SUPPOSITORY_OVULE', 'OTHER', null]) {
        assert.equal(isUndosedForm(form), false, String(form));
    }
});

/** Les deux règles vivent à un seul endroit : deux copies divergeraient. */
test('l’éditeur de ligne lit le calcul partagé', () => {
    const editor = fs.readFileSync('resources/js/Components/Clinical/PrescriptionLineEditor.vue', 'utf8');

    assert.match(editor, /import \{ isUndosedForm, quantityBasis, suggestedQuantity \} from '@\/utilities\/posology'/);
    // La dose disparaît, elle n'est pas seulement laissée vide.
    assert.match(editor, /<div v-if="dosed">/);
    // Et une quantité corrigée à la main n'est plus jamais réécrite.
    assert.match(editor, /if \(value === null \|\| props\.line\._quantity_touched\)/);
    assert.match(editor, /field: '_quantity_touched', value: true/);
});
