import test from 'node:test';
import assert from 'node:assert/strict';
import { composeSupplierOrderMail, openSupplierOrderMail } from '../../resources/js/utilities/supplierOrderMail.js';

/**
 * ADR-179 · 6 — le brouillon d'e-mail qui porte une commande à son fournisseur.
 *
 * Rien n'est envoyé par RIVO : `mailto:` ouvre la messagerie de la personne,
 * qui relit et envoie depuis sa propre adresse. Ce qui est vérifié ici est donc
 * ce que le serveur ne peut pas rattraper : le texte réellement composé, et le
 * fait qu'aucune messagerie ne s'ouvre sans destinataire.
 */

const commande = {
    order_number: 'ABC-000012',
    ordered_on: '22/09/2026',
    expected_delivery_on: '30/09/2026',
    total: '4 500,50 Ar',
    lines: [
        { quantity: 20, unit: 'boîte', name: 'Paracétamol 500 mg', code: 'PARA-500' },
        { quantity_ordered: 5, name: 'Gants & compresses' },
    ],
};
const contexte = { clinic: 'Clinique Saint Georges — Ambondromamy', author: 'BEZARA Florent' };

test('l’objet porte la référence de la commande et le site qui écrit', () => {
    const { subject } = composeSupplierOrderMail(commande, contexte);

    assert.equal(subject, 'Commande ABC-000012 — Clinique Saint Georges — Ambondromamy');
});

test('sans numéro ni site, l’objet reste lisible plutôt que vide', () => {
    assert.equal(composeSupplierOrderMail({}).subject, 'Commande');
    assert.equal(composeSupplierOrderMail({ order_number: 'ABC-000012' }).subject, 'Commande ABC-000012');
});

/*
 * Le numéro est un identifiant : le fournisseur le cite en retour et la
 * réception le retrouve. Une phrase mise en minuscule d'un bloc écrivait
 * « notre commande abc-000012 ».
 */
test('la phrase met « commande » en minuscule, jamais le numéro', () => {
    const { body } = composeSupplierOrderMail(commande, contexte);

    assert.ok(body.includes('Veuillez trouver ci-dessous notre commande ABC-000012.'), body);
    assert.ok(!body.includes('abc-000012'), 'le numéro a été mis en minuscule');
});

test('chaque produit occupe sa ligne, avec sa quantité, son unité et son code', () => {
    const { body } = composeSupplierOrderMail(commande, contexte);

    assert.ok(body.includes('  • 20 boîte — Paracétamol 500 mg (PARA-500)'), body);
    // La page d'une commande envoie `quantity_ordered`, le formulaire `quantity`.
    assert.ok(body.includes('  • 5 — Gants & compresses'), body);
});

test('une valeur absente ne laisse ni ligne vide ni « null » dans le texte', () => {
    const { body } = composeSupplierOrderMail(
        { order_number: 'ABC-000012', ordered_on: '22/09/2026', expected_delivery_on: null, total: null, notes: null, lines: [] },
        { clinic: 'Clinique Saint Georges', author: null },
    );

    assert.doesNotMatch(body, /null|undefined/);
    assert.ok(!body.includes('Livraison souhaitée'), body);
    assert.ok(!body.includes('Montant total'), body);
    assert.ok(!body.includes('Remarque'), body);
});

test('la signature reprend qui écrit et depuis quelle clinique', () => {
    const { body } = composeSupplierOrderMail(commande, contexte);

    assert.ok(body.endsWith('Cordialement,\nBEZARA Florent\nClinique Saint Georges — Ambondromamy'), body);
});

/** Une messagerie factice : on lit l'adresse ouverte, sans rien lancer. */
const fausseMessagerie = () => {
    const fenetre = { location: { href: 'https://rivo.test/pharmacy/purchases' } };
    globalThis.window = fenetre;

    return fenetre;
};

test('sans adresse au dossier du fournisseur, aucune messagerie ne s’ouvre', () => {
    const fenetre = fausseMessagerie();

    try {
        assert.equal(openSupplierOrderMail(null, commande, contexte), false);
        assert.equal(openSupplierOrderMail('', commande, contexte), false);
        assert.equal(fenetre.location.href, 'https://rivo.test/pharmacy/purchases');
    } finally {
        delete globalThis.window;
    }
});

test('le brouillon ouvert porte l’objet et le corps, un « & » compris', () => {
    const fenetre = fausseMessagerie();

    try {
        assert.equal(openSupplierOrderMail('contact@arbiochem.mg', commande, contexte), true);

        const ouvert = fenetre.location.href;
        assert.ok(ouvert.startsWith('mailto:contact%40arbiochem.mg?'), ouvert);

        const parametres = new URLSearchParams(ouvert.slice(ouvert.indexOf('?') + 1));
        const { subject, body } = composeSupplierOrderMail(commande, contexte);
        assert.equal(parametres.get('subject'), subject);
        // Sans encodage, « Gants & compresses » couperait le corps en deux.
        assert.equal(parametres.get('body'), body);
        assert.ok(parametres.get('body').includes('Gants & compresses'));
    } finally {
        delete globalThis.window;
    }
});
