import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Medicine/Requests.vue', 'utf8');

/** L'écran « Demandes d'examens » : un vrai tableau, et des actions honnêtes. */

test('les demandes sont présentées en tableau, avec une colonne d’actions', () => {
    assert.match(page, /<table class="w-full min-w-\[64rem\] text-sm">/);

    for (const column of ['Patient', 'Examen', 'Demandée', 'Statut', 'Actions']) {
        assert.match(page, new RegExp(`<th scope="col"[^>]*>${column}</th>`));
    }
});

/**
 * Le serveur décide qui peut écrire un résultat ou retirer une demande.
 * L'écran ne recalcule jamais la règle : il lit le drapeau.
 */
test('les actions sont gardées par les drapeaux du serveur, jamais recalculées', () => {
    assert.match(page, /request\.items\.filter\(\(exam\) => exam\.can_record\)/);
    assert.match(page, /v-if="request\.can_withdraw"/);

    // Aucune reconstruction locale de ces règles.
    assert.doesNotMatch(page, /resulted_at === null &&/);
    assert.doesNotMatch(page, /status !== 'CANCELLED' &&/);
});

/** ADR-010 — une demande n'est jamais supprimée. */
test('le retrait dit qu’il ne supprime pas', () => {
    assert.match(page, /paraclinical-requests\/cancel/);
    assert.match(page, /La demande n’est pas supprimée/);
    assert.doesNotMatch(page, /router\.delete|method: 'delete'/);
});

test('la recherche et les filtres restent servis par le serveur', () => {
    assert.match(page, /const selectFilter = \(key\) => visit\(/);
    assert.match(page, /counts\[filter\.key\] \?\? 0/);
});

/**
 * Trois vues, chacune répondant à une question — plus un onglet par statut
 * dérivé, qui obligeait le médecin à savoir lequel regarder.
 */
test('les demandes se lisent en trois vues, « Active » par défaut', () => {
    for (const key of ['active', 'recent', 'archived']) {
        assert.match(page, new RegExp(`key: '${key}'`));
    }

    assert.doesNotMatch(page, /key: '(all|pending|in_progress|resulted|cancelled)'/);
    assert.match(page, /props\.filters\.filter \?\? 'active'/);
});

/**
 * Rien ne « valide » un résultat dans RIVO : ni `validated_at`, ni permission
 * de validation. Nommer l'onglet « Validé » afficherait un contrôle que
 * personne n'a fait — le même refus que pour « a pris connaissance ».
 */
test('l’onglet dit « rendu », jamais « validé »', () => {
    const filters = page.slice(page.indexOf('const FILTERS = ['));
    const block = filters.slice(0, filters.indexOf('];'));

    assert.match(block, /Rendues? récemment/);
    assert.doesNotMatch(block, /Validé/);
});

/** Après une saisie, la ligne a quitté « Active » : on la suit. */
test('après la saisie, on suit la ligne vers « Rendu récemment »', () => {
    const success = page.slice(page.indexOf('onSuccess: () => {'));
    const body = success.slice(0, success.indexOf('},'));

    assert.match(body, /selectFilter\('recent'\)/);
    assert.match(body, /activeFilter\.value === 'active'/);
});

/**
 * La feuille de saisie doit laisser écrire.
 *
 * Elle était en `lg` avec ses deux champs empilés : plus haute que large,
 * avec une zone de rédaction étroite. Une feuille de compte rendu se lit et
 * s'écrit en paysage.
 */
test('la feuille de compte rendu est un rectangle, pas une colonne', () => {
    const dialog = page.slice(page.indexOf(':open="reporting !== null"'));
    const head = dialog.slice(0, dialog.indexOf('</Dialog>'));

    assert.match(head, /size="wide"/);
    assert.match(head, /grid gap-4 lg:grid-cols-3/);
    assert.match(head, /lg:col-span-2/);
});

/**
 * La zone d'écriture suit l'écran. Une hauteur figée est trop courte sur un
 * grand moniteur et déborde sur un portable.
 */
test('la zone d’écriture s’adapte à la taille de l’écran', () => {
    assert.match(page, /min-height-class="min-h-\[48vh\]"/);
    assert.doesNotMatch(page, /min-height-class="min-h-\d+"/);
});

/** Une fenêtre ne doit jamais dépasser l'écran : le corps défile. */
test('la feuille ne dépasse jamais la hauteur de l’écran', () => {
    assert.match(page, /body-class="max-h-\[78vh\] overflow-y-auto"/);
});

/**
 * Un compte rendu représente plusieurs minutes d'écriture. Un clic à côté ou
 * une touche Échap ne doit pas le jeter : seuls « Annuler » et la croix
 * ferment, deux gestes explicites.
 */
test('la feuille de saisie ne se ferme pas par un clic à côté', () => {
    const dialog = page.slice(page.indexOf(':open="reporting !== null"'));
    const head = dialog.slice(0, dialog.indexOf('</Dialog>'));

    assert.match(head, /:dismissible="false"/);
});

/**
 * L'impression n'existe que pour l'imagerie : un résultat d'analyse
 * appartient au Laboratoire, et son UUID sur cette route ne désignerait
 * rien. L'URL est donc construite par le serveur, jamais devinée ici.
 */
test('le bouton Imprimer suit l’URL fournie par le serveur', () => {
    assert.match(page, /request\.items\.filter\(\(exam\) => exam\.print_url\)/);
    assert.match(page, /:href="item\.print_url"/);
    assert.doesNotMatch(page, /`\/medicine\/imaging-requests\/\$\{item\.uuid\}\/compte-rendu`/);
});

/**
 * Un libellé qui promet une chose doit faire cette chose.
 *
 * « Voir le résultat » renvoyait vers l'étape Paraclinique de la
 * consultation : le médecin atterrissait dans l'assistant d'un dossier
 * souvent déjà clôturé, à chercher ce qu'il venait de demander à voir.
 */
test('« Voir le résultat » montre le résultat, il ne navigue pas ailleurs', () => {
    // Ancré sur le gestionnaire, jamais sur le libellé : les commentaires de
    // ce fichier citent la même phrase, et chercher le texte trouverait l'un
    // d'eux avant le bouton.
    const handler = page.indexOf('@click="openResult(request)"');
    assert.notEqual(handler, -1, 'le bouton de lecture a disparu');

    const button = page.slice(page.lastIndexOf('<Button', handler), page.indexOf('</Button>', handler));

    assert.match(button, /Voir le résultat/);
    assert.doesNotMatch(button, /:href=/);
});

/** Ouvrir la consultation reste possible, mais comme une action distincte. */
test('la consultation garde son propre bouton', () => {
    assert.match(page, /v-else-if="request\.consultation_url"/);
    assert.match(page, /:href="viewing\.consultation_url"/);
});

/** Le contenu est déjà dans la page : aucune requête supplémentaire. */
test('la lecture n’interroge pas le serveur', () => {
    const dialog = page.slice(page.indexOf(':open="viewing !== null"'));
    const body = dialog.slice(0, dialog.indexOf('</Dialog>'));

    assert.match(body, /v-html="item\.report"/);
    assert.doesNotMatch(body, /router\.(get|post)|useForm/);
});
