import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { medicineFamily, medicineMatches, medicineSubtitle } from '../../resources/js/utilities/medicine.js';

const amoxicillin = {
    name: 'Amoxicilline 500 mg',
    generic_name: 'Amoxicilline',
    code: 'AMOX-500',
    barcode: '3401234567890',
    form_label: 'Comprimé',
    strength: '500 mg',
    category: { name: 'Antibiotiques' },
};

test('un médicament se décrit dans un seul ordre : forme, dosage, DCI, code', () => {
    assert.equal(medicineSubtitle(amoxicillin), 'Comprimé · 500 mg · Amoxicilline · AMOX-500');
});

test('ce qui manque disparaît, sans séparateur orphelin', () => {
    assert.equal(medicineSubtitle({ form_label: 'Bidon', code: 'ALCO-003' }), 'Bidon · ALCO-003');
    assert.equal(medicineSubtitle({}), '');
    assert.equal(medicineSubtitle(null), '');
});

test('une famille absente vaut null, jamais une chaîne vide affichable', () => {
    assert.equal(medicineFamily(amoxicillin), 'Antibiotiques');
    assert.equal(medicineFamily({ category: null }), null);
    assert.equal(medicineFamily({ category: { name: '' } }), null);
});

test('la recherche trouve par la famille, comme le filtre', () => {
    assert.equal(medicineMatches(amoxicillin, 'antibio'), true);
    assert.equal(medicineMatches(amoxicillin, 'AMOX'), true);
    assert.equal(medicineMatches(amoxicillin, '340123'), true);
    assert.equal(medicineMatches(amoxicillin, 'paracétamol'), false);
    assert.equal(medicineMatches(amoxicillin, '   '), true, 'une recherche vide ne filtre rien');
});

test('les écrans Pharmacie décrivent un médicament par le même helper', () => {
    const screens = [
        'resources/js/Pages/Pharmacy/Stock/Index.vue',
        'resources/js/Pages/Pharmacy/Stock/Show.vue',
        'resources/js/Pages/Pharmacy/Partials/ExternalCounterSaleWorkspace.vue',
    ];

    for (const screen of screens) {
        const source = readFileSync(new URL(`../../${screen}`, import.meta.url), 'utf8');

        assert.match(source, /medicineSubtitle/, `${screen} doit utiliser medicineSubtitle`);
        // Recomposer « forme · dosage » à la main est exactement ce que ce
        // helper remplace : trois écrans le faisaient différemment.
        assert.doesNotMatch(
            source,
            /medicine\.form_label\s*}}<span v-if="medicine\.strength"/,
            `${screen} ne doit plus recomposer le sous-titre à la main`,
        );
    }
});
