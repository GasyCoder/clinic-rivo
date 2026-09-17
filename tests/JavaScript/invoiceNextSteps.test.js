import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Invoices/Show.vue', 'utf8');
const nextSteps = page.slice(page.indexOf('Et ensuite :'), page.indexOf('Et ensuite :') + 1400);

/**
 * Après la remise du document, le guichet enchaîne : patient suivant, ou
 * retour à la caisse. Sans ces raccourcis, la seule sortie était « Retour
 * au patient ».
 */
test('la suite du travail est proposée sous le choix de document', () => {
    assert.match(nextSteps, /href="\/reception\/patients"[\s\S]*?Nouveau patient/);
    assert.match(nextSteps, /href="\/cash"[\s\S]*?Caisse/);
    assert.match(nextSteps, /\/patients\/\$\{invoice\.patient\.uuid\}`[\s\S]*?Dossier du patient/);
});

/** Chaque raccourci est filtré par la permission de l'écran qu'il ouvre. */
test('aucun raccourci ne mène à un écran interdit', () => {
    assert.match(nextSteps, /v-if="can\('episodes\.create'\)"/);
    assert.match(nextSteps, /v-if="can\('cash\.view'\)"/);
    // Une facture de vente comptoir n'a pas de patient : pas de lien mort.
    assert.match(nextSteps, /v-if="invoice\.patient"/);
    assert.match(page, /const \{ can \} = usePermissions\(\);/);
});

/**
 * Imprimer et quitter la page ne sont pas deux choix du même ordre : les
 * mettre dans la même grille ferait cliquer « Nouveau patient » à la place
 * de « Ticket ».
 */
test('les raccourcis ne rejoignent pas la grille des documents', () => {
    // Jusqu'au commentaire qui introduit la rangée, sinon le texte du
    // commentaire lui-même ferait échouer l'assertion.
    const grid = page.slice(
        page.indexOf('Document à remettre'),
        page.indexOf('<!-- Le document remis'),
    );

    assert.match(grid, /class="grid gap-3 p-4 sm:grid-cols-2"/);
    assert.doesNotMatch(grid, /Nouveau patient/);
    assert.doesNotMatch(grid, /href="\/cash"/);
});
