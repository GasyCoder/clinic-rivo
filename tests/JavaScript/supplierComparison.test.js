import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
    NO_FAMILY, SEVERAL, countBy, coverageOf, familyOf, groupByFamily, suppliersOf,
} from '../../resources/js/utilities/supplierComparison.js';

const PAGE = fs.readFileSync('resources/js/Pages/SuperAdmin/PharmacySuppliers/Compare.vue', 'utf8');

/**
 * ADR-181, amendement du 2026-09-24 — ce qui se compare, et par famille.
 *
 * Beaucoup de produits ne sont proposés que par un seul fournisseur. Chaque
 * produit tombe dans une seule case — « chez plusieurs fournisseurs » ou
 * « seulement chez X » —, et la somme des cases fait le total. La famille
 * range la liste au lieu de la dérouler de A à Z.
 */

const row = (name, suppliers, family = null) => ({
    name,
    family,
    quotes: suppliers.map((supplier) => ({ supplier_uuid: supplier })),
});

const ROWS = [
    row('Aiguille rose 18G', ['A', 'B'], 'Seringues'),
    row('Alcool 1l 70°', ['A', 'B', 'A'], 'Antiseptiques'),
    row('Compresse 10x10', ['A'], 'Pansements'),
    row('Gants taille M', ['B']),
    row('Coton hydrophile', ['B'], 'Pansements'),
];

test('un produit proposé par deux fournisseurs se compare ; par un seul, il ne se compare pas', () => {
    assert.equal(coverageOf(ROWS[0]), SEVERAL);
    assert.equal(coverageOf(ROWS[2]), 'ONLY:A');
    assert.equal(coverageOf(ROWS[3]), 'ONLY:B');
});

test('deux références du même fournisseur ne font pas deux fournisseurs', () => {
    assert.deepEqual(suppliersOf(ROWS[1]), ['A', 'B']);
    assert.equal(coverageOf(row('Doublon', ['A', 'A'])), 'ONLY:A');
});

test('chaque produit tombe dans une seule case : la somme des cases fait le total', () => {
    const counts = countBy(ROWS, coverageOf);

    assert.equal(counts.get(SEVERAL), 2);
    assert.equal(counts.get('ONLY:A'), 1);
    assert.equal(counts.get('ONLY:B'), 2);
    assert.equal([...counts.values()].reduce((sum, count) => sum + count, 0), ROWS.length);
});

test('la liste se range par famille, « Sans famille » en dernier, sans rien perdre', () => {
    const groups = groupByFamily(ROWS);

    assert.deepEqual(groups.map((group) => group.label), ['Antiseptiques', 'Pansements', 'Seringues', NO_FAMILY]);
    assert.deepEqual(groups[1].rows.map((item) => item.name), ['Compresse 10x10', 'Coton hydrophile']);
    assert.equal(groups.reduce((sum, group) => sum + group.rows.length, 0), ROWS.length);
    // Une ligne sans famille n'en reçoit pas d'inventée.
    assert.equal(familyOf(ROWS[3]), NO_FAMILY);
});

test('l’écran se sert des règles partagées, et range sa liste par famille', () => {
    assert.ok(PAGE.includes("from '@/utilities/supplierComparison'"), 'la page recopie les règles du comparateur');
    assert.ok(PAGE.includes('groupByFamily(visible.value)'), PAGE);
    assert.ok(PAGE.includes('v-for="group in visibleByFamily"'), 'la liste n’est plus rangée par famille');
    for (const label of ['Chez les deux fournisseurs', 'Chez plusieurs fournisseurs', 'Seulement chez', 'Toutes les familles']) {
        assert.ok(PAGE.includes(label), `« ${label} » ne se dit plus`);
    }
});
