import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Medicine/Requests.vue', 'utf8');
// La feuille de saisie vit dans un composant partagé avec la consultation :
// ses règles de forme se vérifient là où elle est écrite.
const reportDialog = fs.readFileSync('resources/js/Components/Clinical/ImagingReportDialog.vue', 'utf8');

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
    // Chaque examen porte ses actions à sa ligne (ADR-131) : deux examens
    // d'une même demande n'ont plus deux boutons identiques.
    assert.match(page, /v-if="item\.can_record"/);
    assert.match(page, /v-if="request\.can_withdraw"/);
    assert.match(page, /v-if="request\.can_archive"/);
    assert.match(page, /v-if="request\.can_unarchive"/);

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
    const success = page.slice(page.indexOf('const reportSaved = () => {'));
    const body = success.slice(0, success.indexOf('};'));

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
    const dialog = reportDialog.slice(reportDialog.indexOf(':open="item !== null"'));
    const head = dialog.slice(0, dialog.indexOf('</Dialog>'));

    assert.match(head, /size="wide"/);
    // Les deux colonnes de la feuille côte à côte, comme sur le papier ; la
    // saisie libre prend toute la largeur au lieu de la partager avec un
    // second champ (ADR-108).
    assert.match(head, /grid divide-border lg:grid-cols-2 lg:divide-x/);
    assert.doesNotMatch(head, /lg:grid-cols-3/);
});

/**
 * La zone d'écriture suit l'écran. Une hauteur figée est trop courte sur un
 * grand moniteur et déborde sur un portable.
 */
test('la zone d’écriture s’adapte à la taille de l’écran', () => {
    assert.match(reportDialog, /min-height-class="min-h-\[48vh\]"/);
    assert.doesNotMatch(reportDialog, /min-height-class="min-h-\d+"/);
});

/** Une fenêtre ne doit jamais dépasser l'écran : le corps défile. */
test('la feuille ne dépasse jamais la hauteur de l’écran', () => {
    assert.match(reportDialog, /body-class="max-h-\[78vh\] overflow-y-auto"/);
});

/**
 * Un compte rendu représente plusieurs minutes d'écriture. Un clic à côté ou
 * une touche Échap ne doit pas le jeter : seuls « Annuler » et la croix
 * ferment, deux gestes explicites.
 */
test('la feuille de saisie ne se ferme pas par un clic à côté', () => {
    const dialog = reportDialog.slice(reportDialog.indexOf(':open="item !== null"'));
    const head = dialog.slice(0, dialog.indexOf('</Dialog>'));

    assert.match(head, /:dismissible="false"/);
});

/**
 * L'impression n'existe que pour l'imagerie : un résultat d'analyse
 * appartient au Laboratoire, et son UUID sur cette route ne désignerait
 * rien. L'URL est donc construite par le serveur, jamais devinée ici.
 */
test('le bouton Imprimer suit l’URL fournie par le serveur', () => {
    assert.match(page, /v-if="item\.print_url"/);
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
    // ADR-131 — avec son icône : un bouton sans icône se lisait comme un intrus.
    assert.match(page, /v-if="request\.consultation_url"/);
    assert.match(page, /<Stethoscope class="h-4 w-4" \/>/);
    assert.match(page, /:href="viewing\.consultation_url"/);
});

/** Le contenu est déjà dans la page : aucune requête supplémentaire. */
test('la lecture n’interroge pas le serveur', () => {
    const dialog = page.slice(page.indexOf(':open="viewing !== null"'));
    const body = dialog.slice(0, dialog.indexOf('</Dialog>'));

    assert.match(body, /v-html="item\.report"/);
    assert.doesNotMatch(body, /router\.(get|post)|useForm/);
});

/**
 * ADR-131 — les boutons d'une ligne restent courts : « Saisir » garde son
 * libellé, tout le reste passe en icônes nommées au survol.
 */
test('les actions secondaires sont des icônes nommées, pas des libellés longs', () => {
    for (const label of ['Ouvrir la consultation', 'Archiver cette demande', 'Sortir des archives']) {
        assert.match(page, new RegExp(`aria-label="${label}"`));
    }

    // Dans le tableau, le libellé long d'origine ne sert plus de texte de bouton
    // (la fenêtre de lecture, elle, a la place de garder le sien).
    const table = page.slice(page.indexOf('<tbody'), page.indexOf('</tbody>'));

    assert.doesNotMatch(table, />\s*Ouvrir la consultation\s*<\/Button>/);
    assert.doesNotMatch(table, />\s*Saisir le résultat\s*<\/Button>/);
});

test('un filtre par famille d’examen — ECG, échographie, analyses — se combine avec la vue', () => {
    for (const key of ['ALL', 'ECG', 'ULTRASOUND', 'LAB', 'UNCLASSIFIED']) {
        assert.match(page, new RegExp(`key: '${key}'`));
    }

    assert.match(page, /role="tablist"/);
    assert.match(page, /const selectType = /);
    // Vue et famille se combinent : changer l'une garde l'autre.
    assert.match(page, /filter: activeFilter\.value, type: activeType\.value/);
    // Le compte vient du serveur, jamais recalculé depuis la page.
    assert.match(page, /props\.type_counts\[key\] \?\? 0/);
});

test('« Non classés » n’apparaît que s’il existe un examen sans famille', () => {
    assert.match(page, /tab\.key !== 'UNCLASSIFIED'/);
    assert.match(page, /\(props\.type_counts\.UNCLASSIFIED \?\? 0\) > 0/);
});

test('archiver et sortir des archives passent par le serveur, sans suppression', () => {
    assert.match(page, /\/medicine\/paraclinical-requests\/\$\{request\.kind\}\/\$\{request\.uuid\}\/\$\{restore \? 'unarchive' : 'archive'\}/);
    assert.doesNotMatch(page, /router\.delete/);
});

/**
 * Le serveur envoie `type_counts` et `report_templates` en snake_case : Vue ne
 * fait correspondre qu'un nom identique (ou en kebab-case). Un prop déclaré
 * `typeCounts` ne recevait jamais rien — les onglets affichaient 0 et les
 * feuilles de la clinique n'apparaissaient pas dans la fenêtre de saisie.
 */
test('les props servis en snake_case sont déclarés tels quels', () => {
    assert.match(page, /type_counts: \{ type: Object/);
    assert.match(page, /report_templates: \{ type: Array/);
    assert.doesNotMatch(page, /typeCounts|reportTemplates/);
});
