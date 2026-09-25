import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { PHARMACY_SITE_BASE, mapPharmacyPath } from '../../resources/js/utilities/pharmacyPath.js';

/**
 * ADR-189 — les écrans de la Pharmacie du site sont aussi ceux du portail. Ils
 * écrivent leurs adresses telles qu'elles sont sur le site ; `pharmacyUrl` les
 * ramène à la base où l'écran est ouvert. Les actes physiques restent au site.
 */
const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);

    return entry.isDirectory() ? walk(full) : (entry.name.endsWith('.vue') ? [full] : []);
});

// Le panneau de l'accueil vit sur la Vue d'ensemble du site, jamais sur le portail.
const SITE_ONLY_FILES = ['PharmacyHomePanel.vue'];
const pharmacyScreens = () => [...walk('resources/js/Pages/Pharmacy'), ...walk('resources/js/Components/Pharmacy')]
    .filter((file) => ! SITE_ONLY_FILES.includes(path.basename(file)));

const read = (file) => fs.readFileSync(file, 'utf8');

test('a site path is brought to the base where the pharmacy screen is open', () => {
    const base = '/super-admin/sites/A/pharmacie';

    assert.equal(mapPharmacyPath('/pharmacy/stock/m-1', base), `${base}/stock/m-1`);
    assert.equal(mapPharmacyPath('/pharmacy', base), base);
    assert.equal(mapPharmacyPath('/pharmacy/purchase-orders?status=TO_RECEIVE', base), `${base}/purchase-orders?status=TO_RECEIVE`);
    assert.equal(mapPharmacyPath('/pharmacy/stock', PHARMACY_SITE_BASE), '/pharmacy/stock', 'sur le site, rien ne change');
    assert.equal(mapPharmacyPath('/pharmacy-historique', base), '/pharmacy-historique', 'un préfixe n’est pas une rubrique');
    assert.equal(mapPharmacyPath('/patients/p-1', base), '/patients/p-1');
});

test('no pharmacy screen writes a site path the portal could not follow', () => {
    for (const file of pharmacyScreens()) {
        const source = read(file);
        const bare = source.match(/(?<!pharmacyUrl\()['"`]\/pharmacy(?=[/?#'"`])[^'"`]*['"`]/g) ?? [];

        assert.deepEqual(bare, [], `${file} écrit une adresse de la Pharmacie sans pharmacyUrl()`);

        if (source.includes('pharmacyUrl(')) {
            assert.match(source, /import \{[^}]*\bpharmacyUrl\b[^}]*\} from '@\/utilities\/pharmacyUrl'/, `${file} utilise pharmacyUrl sans l’importer`);
        }
    }
});

test('the physical acts are shown locked on the portal, never hidden', () => {
    const locks = {
        'resources/js/Pages/Pharmacy/Partials/DispenseQueue.vue': ['Préparer le ticket', 'Délivrer', 'Imprimer le ticket'],
        'resources/js/Pages/Pharmacy/Partials/CareConsumableQueue.vue': ['Servir et sortir le stock'],
        'resources/js/Pages/Pharmacy/Stock/Index.vue': ['Inventaire', 'Corriger'],
        'resources/js/Pages/Pharmacy/Stock/Show.vue': ['Corriger'],
        'resources/js/Pages/Pharmacy/GoodsReceipts/Show.vue': ['Entrer en stock'],
        'resources/js/Pages/Pharmacy/GoodsReceipts/Index.vue': ['Entrer en stock'],
        'resources/js/Pages/Pharmacy/PurchaseOrders/Index.vue': ['Réceptionner'],
        'resources/js/Components/Pharmacy/PurchaseOrderDetail.vue': ['Réceptionner', 'Le fournisseur le livre finalement'],
    };

    for (const [file, labels] of Object.entries(locks)) {
        const source = read(file);

        assert.match(source, /import SiteOnlyAction from '@\/Components\/Pharmacy\/SiteOnlyAction\.vue'/, `${file} n’importe pas SiteOnlyAction`);
        for (const label of labels) {
            assert.match(source, new RegExp(`<SiteOnlyAction [^>]*label="${label}"`), `${file} : « ${label} » n’est pas verrouillé sur le portail`);
        }
    }

    const lock = read('resources/js/Components/Pharmacy/SiteOnlyAction.vue');

    assert.match(lock, /<slot v-if="! onPortal" \/>/, 'sur le site, le bouton est rendu tel quel');
    assert.match(lock, /disabled/);
    assert.match(lock, /SITE_ONLY_REASON/, 'le verrou dit pourquoi');
});

test('the site refuses the physical acts to the portal, whatever the screen says', () => {
    const routes = read('routes/pharmacy.php');

    for (const name of ['stock.entries.create', 'stock.entries.batch', 'stock.inventory', 'stock.inventory.store', 'stock.adjustments.create', 'stock.adjustments.store', 'dispenses.invoice.store', 'dispenses.ticket.show', 'dispenses.deliveries.store', 'care-consumables.serve', 'purchase-orders.receive', 'purchase-orders.receipts.store', 'purchase-orders.lines.shortage', 'purchase-orders.lines.shortage.revert']) {
        assert.match(routes, new RegExp(`->name\\('${name.replaceAll('.', '\\.')}'\\)[^;]*rivo\\.site-only`), `${name} doit rester au site`);
    }
});

test('the portal shows the pharmacy navigation of the site, from the one list of pharmacy sections', () => {
    const layout = read('resources/js/Layouts/AppLayout.vue');
    const bar = read('resources/js/Components/Pharmacy/PharmacyPortalBar.vue');
    const sections = read('resources/js/utilities/pharmacySections.js');

    assert.match(layout, /<PharmacyPortalBar v-if="page\.props\.pharmacyContext" \/>/);
    assert.match(bar, /pharmacySections\(base\.value, can\)/);
    assert.match(sections, /CLINIC_WORKSPACES\.find\(\(workspace\) => workspace\.key === 'pharmacy'\)/, 'mêmes rubriques que le menu Pharmacie du site');
    assert.match(sections, /section\.anyPermission\.some/, 'une rubrique à plusieurs droits en garde un');
    assert.match(bar, /print:hidden/);
});
