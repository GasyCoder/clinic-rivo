import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { jsonRequest } from '../../resources/js/utilities/jsonRequest.js';

const COMPARE = fs.readFileSync('resources/js/Pages/SuperAdmin/PharmacySuppliers/Compare.vue', 'utf8');
const PROMPT = fs.readFileSync('resources/js/Components/Pharmacy/CatalogPromptGenerator.vue', 'utf8');
const CATALOGS = fs.readFileSync('resources/js/Pages/SuperAdmin/PharmacySuppliers/Catalogs.vue', 'utf8');

/**
 * ADR-241 — le même produit sous deux noms, et le prompt d'un catalogue.
 */

test('the comparator lets a human say « same » or « two products », and separate a merged line', () => {
    assert.match(COMPARE, /C’est le même produit/);
    assert.match(COMPARE, /Ce n’est pas le même/);
    assert.match(COMPARE, /status: 'SAME', other_item_uuids: \[peer\.item_uuid\]/);
    assert.match(COMPARE, /Séparer/);
    // Une proposition de l'IA se reconnaît, et attend une décision.
    assert.match(COMPARE, /peer\.source === 'AI'/);
});

test('the AI button and the dictionary only appear for those who may decide', () => {
    assert.match(COMPARE, /v-if="canManageEquivalences && aiAvailable"/);
    assert.match(COMPARE, /ProductMatchingSettings/);
    // Sans IA prête, le bouton reste visible, grisé, et dit quoi régler (ADR-158).
    assert.match(COMPARE, /v-else-if="canManageEquivalences"[\s\S]*?aiUnavailableReason[\s\S]*?à configurer/);
});

test('« Copier » copies the generated prompt and nothing else', () => {
    assert.match(PROMPT, /copyText\(prompt\.value\)/);
    assert.match(PROMPT, /Générer le prompt/);
    assert.match(CATALOGS, /<CatalogPromptGenerator/);
});

test('jsonRequest never throws: an unreachable portal is said, not raised', async () => {
    const original = globalThis.fetch;
    globalThis.fetch = async () => { throw new Error('offline'); };

    try {
        const result = await jsonRequest('/x');
        assert.equal(result.ok, false);
        assert.match(result.message, /injoignable/);
    } finally {
        globalThis.fetch = original;
    }
});

test('jsonRequest surfaces the first validation error', async () => {
    const original = globalThis.fetch;
    globalThis.fetch = async () => ({ ok: false, status: 422, json: async () => ({ errors: { term: ['Abréviation invalide.'] } }) });

    try {
        const result = await jsonRequest('/x', { method: 'POST', body: { term: '1' } });
        assert.equal(result.status, 422);
        assert.equal(result.message, 'Abréviation invalide.');
        assert.deepEqual(result.errors, { term: ['Abréviation invalide.'] });
    } finally {
        globalThis.fetch = original;
    }
});
