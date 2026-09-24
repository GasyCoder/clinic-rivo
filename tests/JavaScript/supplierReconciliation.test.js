import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const COMPARE = fs.readFileSync('resources/js/Pages/SuperAdmin/PharmacySuppliers/Compare.vue', 'utf8');

/**
 * ADR-181 — deux fournisseurs ne nomment pas un produit de la même façon.
 * Le comparateur propose le rapprochement ; il ne le décide jamais.
 */

test('une ligne qui ressemble à un produit de la clinique le dit', () => {
    assert.match(COMPARE, /v-if="medicine\.suggestions\?\.length"/);
    assert.ok(COMPARE.includes('Peut-être déjà au catalogue'), COMPARE);
    assert.ok(COMPARE.includes('C’est le même produit ?'), COMPARE);
});

test('le rapprochement passe toujours par la fenêtre, jamais par un clic direct', () => {
    // Un seul envoi, et il part de la fenêtre.
    const posts = COMPARE.match(/(reconcileForm|form)\.post\(/g) ?? [];
    assert.equal(posts.filter((call) => call.startsWith('reconcileForm')).length, 1);
    assert.ok(COMPARE.includes('const submitReconcile = () => {'), COMPARE);
    assert.ok(COMPARE.includes('@confirm="submitReconcile"'), COMPARE);
});

test('la fenêtre montre les deux libellés et refuse sans motif', () => {
    assert.ok(COMPARE.includes('Chez le fournisseur'), COMPARE);
    assert.ok(COMPARE.includes('Au catalogue de la clinique'), COMPARE);
    assert.ok(COMPARE.includes('un dosage, un volume ou un calibre différent en fait deux produits'), COMPARE);
    assert.ok(COMPARE.includes("reconcileForm.change_reason.trim().length < 3"), COMPARE);
});

/*
 * Rattacher crée le prix d'achat : une ligne sans prix n'a rien à créer, et
 * l'action la refuse. L'écran le dit au lieu de proposer un geste qui échoue.
 */
test('une ligne sans prix n’est pas proposée au rapprochement, et dit pourquoi', () => {
    assert.match(COMPARE, /v-if="linkableQuotes\(medicine\)\.length"/);
    assert.ok(COMPARE.includes('prix non communiqué par ce fournisseur'), COMPARE);
    assert.ok(COMPARE.includes('quote.supplier_catalog_item_uuid && quote.can_link'), COMPARE);
});

test('un filtre montre ce qu’il reste à rapprocher, compté par le serveur', () => {
    assert.ok(COMPARE.includes('toReconcile: { type: Number, default: 0 }'), COMPARE);
    assert.ok(COMPARE.includes('À rapprocher · {{ toReconcile }}'), COMPARE);
    assert.ok(COMPARE.includes('medicine.suggestions?.length'), COMPARE);
});
