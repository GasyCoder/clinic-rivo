import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const PAGE = fs.readFileSync('resources/js/Pages/Pharmacy/Stock/Entries/Create.vue', 'utf8');
const TABLE = fs.readFileSync('resources/js/Components/Pharmacy/StockEntryTable.vue', 'utf8');
const STOCK = fs.readFileSync('resources/js/Pages/Pharmacy/Stock/Index.vue', 'utf8');
const MEDICINE = fs.readFileSync('resources/js/Pages/Pharmacy/Stock/Show.vue', 'utf8');

/**
 * ADR-182 — ce qui entre au stock vient d'une livraison réceptionnée, et de
 * rien d'autre. Ce qui est vérifié ici est ce qu'un build ne voit pas : que
 * l'entrée sans commande n'a pas repoussé, ni dans l'écran, ni dans un lien
 * qui y mènerait.
 */

test('l’écran ne déroule plus le catalogue et ne demande plus de provenance', () => {
    for (const gone of ['medicines:', 'catalogRows', 'Arrivé hors commande', 'needsOrigin', 'STOCK_INITIAL', 'D’où vient cette marchandise']) {
        assert.ok(!PAGE.includes(gone), `« ${gone} » est revenu dans l’écran d’entrée`);
    }
});

test('un seul tableau, un seul envoi, fait des seules lignes réceptionnées', () => {
    assert.doesNotMatch(PAGE, /role="tablist"/, 'le sélecteur de mode est revenu');
    assert.equal(PAGE.match(/<StockEntryTable/g)?.length, 1);

    // ADR-189 — l'adresse passe par pharmacyUrl() : c'est toujours un seul envoi.
    const posts = PAGE.match(/\.post\((?:pharmacyUrl\()?'([^']+)'/g) ?? [];
    assert.deepEqual(posts, [".post(pharmacyUrl('/pharmacy/stock/entries/batch'"]);
    assert.ok(PAGE.includes('lines: selected.map('), PAGE);
    assert.ok(!PAGE.includes('entries:'), 'l’écran envoie encore des entrées libres');
});

test('avant toute réception, l’écran est vide et dit comment faire entrer du stock', () => {
    assert.ok(PAGE.includes('Aucune livraison n’attend d’être rangée'), 'l’état vide ne se dit plus');
    assert.ok(PAGE.includes('réceptionnez d’abord la commande'), 'le chemin vers la réception ne se dit plus');
});

test('le bouton dit ce qui manque plutôt que de rester gris sans un mot', () => {
    for (const reason of [
        'Aucune livraison réceptionnée n’attend d’être rangée.',
        'Cochez au moins une ligne à faire entrer en stock.',
    ]) {
        assert.ok(PAGE.includes(reason), `« ${reason} » ne se dit plus`);
    }
});

/*
 * Les lignes sont regroupées par livraison. Une case « tout cocher » par
 * livraison, jamais une pour le tableau entier.
 */
test('le tableau groupe les lignes par livraison et ne connaît plus de ligne « catalogue »', () => {
    assert.ok(TABLE.includes('const groups = computed('), TABLE);
    assert.ok(!TABLE.includes('allSelected'), 'la case « tout cocher » globale est revenue');
    assert.match(TABLE, /Tout cocher — \$\{group\.label\}/);
    assert.ok(!TABLE.includes("'catalog'"), 'le tableau raisonne encore sur des lignes du catalogue');
    assert.ok(!TABLE.includes('editable('), 'une ligne réceptionnée est toujours lisible : plus de mode « non modifiable »');
});

/** ADR-176 — un lot déjà détenu donne sa péremption, sans jamais l'inventer. */
test('les lots connus d’une ligne réceptionnée restent proposés', () => {
    assert.ok(PAGE.includes('known_lots: line.known_lots ?? []'), PAGE);
    assert.ok(TABLE.includes('await nextTick();'), 'le remplissage lit de nouveau le lot précédent');
    // Sans droit de voir la péremption, le lot connu n'en porte pas : le champ ne se bloque pas vide.
    assert.ok(TABLE.includes(':readonly="Boolean(knownLot(row)?.expires_at)"'), TABLE);
});

test('aucun lien ne promet plus une entrée « pour ce produit »', () => {
    for (const [name, source] of [['Médicaments & stock', STOCK], ['fiche du médicament', MEDICINE]]) {
        assert.ok(!source.includes('entries/create?medicine='), `${name} propose encore une entrée par produit`);
    }
    assert.ok(STOCK.includes('awaitingStockCount'), 'le bouton d’entrée ne dit plus ce qui attend d’être rangé');
});

/** ADR-099 — shadcn seulement. */
test('l’écran et son tableau n’ont aucun reste DashWind', () => {
    for (const [name, source] of [['page', PAGE], ['tableau', TABLE]]) {
        for (const legacy of ['UI/Icon.vue', 'UI/Button.vue', 'UI/Badge.vue', 'UI/Input.vue']) {
            assert.ok(!source.includes(legacy), `${name} importe encore ${legacy}`);
        }

        // Une classe DashWind commence le mot ; « shrink-0 » n'en est pas une.
        assert.doesNotMatch(source, /\bnk-/, `${name} porte encore une classe nk-`);
        assert.doesNotMatch(source, /\bni ni-/, `${name} rend encore la police d’icônes`);
        assert.doesNotMatch(source, /<Icon[ />]/, `${name} rend encore la police d’icônes`);
    }
});
