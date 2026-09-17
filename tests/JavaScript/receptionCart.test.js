import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Reception/Create.vue', 'utf8');
const menu = fs.readFileSync('resources/js/utilities/clinicWorkspaces.js', 'utf8');

/**
 * ADR-104 — le panier d'arrivée porte deux rayons, et une seule sélection.
 */
test('les deux rayons partagent un seul panier', () => {
    assert.match(page, /const aisle = ref\('services'\)/);
    assert.match(page, /aisle === 'services'/);
    assert.match(page, /aisle === 'pharmacy'/);

    // Un seul index pour les deux catalogues : le panier ne connaît que
    // l'UUID du catalog_item, jamais de quel rayon vient la ligne.
    assert.match(page, /\[\.\.\.props\.estimateCatalog, \.\.\.props\.pharmacyCatalog\]/);
});

/**
 * Le `kind` doit atteindre le serveur : sans lui, une ligne retombe sur
 * « prestation » et un médicament est refusé comme non routable.
 */
test('chaque ligne du panier porte son rayon jusqu’au serveur', () => {
    assert.match(page, /kind: item\.kind \?\? 'SERVICE'/);
    assert.match(page, /const cartPayload = \(\) => cart\.value\.map\(\(line\) => \(\{\s*\n\s*kind: line\.kind \?\? 'SERVICE'/);
});

/**
 * Deux documents, donc deux sous-totaux : un total unique laisserait croire
 * à un seul règlement (ADR-050).
 */
test('l’estimation sépare les deux sous-totaux', () => {
    assert.match(page, /estimate\.services_total/);
    assert.match(page, /estimate\.medicines_total/);
    assert.match(page, /ticket Pharmacie séparé/);
});

/** Le patient doit savoir avant, pas découvrir après. */
test('un panier de médicaments seuls annonce la bascule à la Caisse', () => {
    assert.match(page, /const pharmacyOnlyCart = computed/);
    assert.match(page, /part directement à la Caisse avec son ticket/);
});

/** La Réception lit le prix et le stock ; elle ne sort jamais un lot. */
test('le rayon Pharmacie reste en lecture', () => {
    assert.doesNotMatch(page, /stock\.(entry|adjust)/);
    assert.match(page, /capabilities\.can_sell_medicines/);
});

/** ADR-104 — la vente comptoir anonyme n'a plus d'entrée nulle part. */
test('plus aucun écran ne propose la vente comptoir', () => {
    assert.doesNotMatch(page, /counter-sales/);
    assert.doesNotMatch(menu, /counter-sale/);
    assert.ok(! fs.existsSync('resources/js/Pages/Pharmacy/CounterSales/Create.vue'));
});

/**
 * Vider le panier en un geste. Rien n'est enregistré à ce stade (ADR-051) :
 * il n'y a donc rien à annuler côté serveur, seulement la sélection à
 * l'écran.
 */
test('le panier se vide en une seule action', () => {
    assert.match(page, /const clearCart = \(\) => \{/);

    // La remise à zéro est complète : une estimation ou un « besoin à
    // préciser » laissé derrière ferait repartir l'écran dans un état
    // qui ne correspond plus au panier.
    const body = page.slice(page.indexOf('const clearCart'), page.indexOf('const stepQuantity'));
    for (const reset of ['cart.value = []', 'estimate.value = null', "estimateError.value = ''", 'designationDeferred.value = false', 'currentStep.value = 1']) {
        assert.ok(body.includes(reset), `clearCart ne remet pas ${reset}`);
    }
});

/** En dessous de deux lignes, la croix de la ligne fait déjà le travail. */
test('« Tout retirer » n’apparaît qu’au-delà d’une ligne', () => {
    const occurrences = page.match(/v-if="cart(Lines)?\.length > 1" type="button"[^>]*@click="clearCart"/g) ?? [];

    // Une fois dans le panier de l'étape Besoin, une fois sur l'estimation.
    assert.equal(occurrences.length, 2);
});

/**
 * ADR-104 — un panier de médicaments seuls n'a aucune couverture à choisir,
 * et l'étape « Prise en charge » refuserait de calculer une couverture sur
 * zéro prestation. L'écran propose donc les deux seules suites qui ont un
 * sens pour ce patient.
 */
test('un passage de médicaments seuls ne traverse pas le choix de couverture', () => {
    // Les cartes Standard/Mutuelle/Personnel/Partenaire et leur bouton de
    // calcul disparaissent : promettre une couverture qui ne s'appliquera
    // pas est pire que ne rien proposer.
    assert.match(page, /<section v-if="pharmacyOnlyCart"[\s\S]*?Ce passage ne contient que des médicaments/);
    assert.match(page, /<div v-else class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">/);
    assert.match(page, /<div v-if="! pharmacyOnlyCart" class="mt-6 flex justify-end/);
});

test('les deux suites proposées sont : ajouter une prestation, ou terminer', () => {
    assert.match(page, /Ajouter une prestation/);
    assert.match(page, /Terminer — envoyer à la Caisse/);
    assert.match(page, /const finishToPharmacy = \(\) => \{/);

    // Le raccourci poste la confirmation réelle : c'est le serveur qui
    // décide de n'ouvrir aucune file, jamais l'écran.
    const body = page.slice(page.indexOf('const finishToPharmacy'), page.indexOf('const confirmCare'));
    assert.match(body, /\/prestations`/);
    assert.match(body, /catalog_lines = cartPayload\(\)/);
});

/**
 * Classer en urgence ouvre les files Soins et Médecine (ADR-056). Le
 * proposer sur un achat de médicaments enverrait deux équipes chercher
 * quelqu'un venu prendre une boîte.
 */
test('l’urgence n’est pas proposée sur un achat de médicaments', () => {
    assert.match(
        page,
        /v-if="capabilities\.can_mark_emergency && episode\.priority !== 'EMERGENCY' && ! pharmacyOnlyCart"/,
    );
});

/**
 * Les deux rayons se distinguent par la couleur : bleu pour les
 * prestations, vert pour la Pharmacie.
 */
test('chaque rayon porte sa teinte, dans les deux thèmes', () => {
    const tabs = page.slice(page.indexOf('const aisleTabs'), page.indexOf('const pharmacyOnlyCart'));

    // Les prestations suivent le token `primary` de l'application plutôt
    // qu'un bleu codé en dur, qui ne suivrait aucun thème (ADR-099).
    assert.match(tabs, /activeClass: 'bg-primary\/10 text-primary/);
    assert.doesNotMatch(tabs, /bg-blue-\d/);

    // Le vert n'a pas de token : il porte donc ses deux variantes.
    assert.match(tabs, /bg-emerald-50 text-emerald-700[^']*dark:bg-emerald-950\/40 dark:text-emerald-300/);
});

/**
 * Deux boutons colorés dont un seul est actif doivent rester distinguables
 * par autre chose que leur teinte.
 */
test('le rayon sélectionné ne se lit pas qu’à la couleur', () => {
    const tabs = page.slice(page.indexOf('const aisleTabs'), page.indexOf('const pharmacyOnlyCart'));

    for (const marker of ['shadow-sm', 'ring-1']) {
        assert.equal(
            (tabs.match(new RegExp(marker, 'g')) ?? []).length,
            2,
            `les deux rayons doivent porter ${marker} à l’état actif`,
        );
    }
    assert.match(page, /:aria-selected="aisle === tab\.value"/);
});
