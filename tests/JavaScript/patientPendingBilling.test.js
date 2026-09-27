import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Patients/Show.vue', 'utf8');

/**
 * Les prestations transmises par les services sont dans le panier du
 * passage : elles s'affichent sans ouvrir « Nouvelle facture », qui reste
 * disponible pour ajouter autre chose.
 */
test('les prestations en attente s’affichent directement dans le passage', () => {
    assert.match(page, /v-for="item in group\.pendingItems"/);
    assert.match(page, /@click="invoicePendingItems\(group\)"/);
    assert.match(page, /'Nouvelle facture'/);

    // Un clic porte toutes les lignes du passage, rien d'autre.
    const action = page.slice(page.indexOf('const invoicePendingItems'), page.indexOf('const toggleInvoiceFormFor'));
    assert.match(action, /group\.pendingItems\.map\(\(item\) => item\.uuid\)/);
    assert.match(action, /episode_uuid = group\.episode\.uuid/);
});

test('« Nouvelle facture » reprend les prestations en attente, cochées', () => {
    const toggle = page.slice(page.indexOf('const toggleInvoiceFormFor'), page.indexOf('const catalogByUuid'));
    assert.match(toggle, /item\.status === 'PENDING' && item\.episode\.uuid === episodeUuid/);
});
