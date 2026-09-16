import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const header = fs.readFileSync('resources/js/Components/Layout/Header.vue', 'utf8');
const search = fs.readFileSync('resources/js/Components/Layout/HeaderSearch.vue', 'utf8');
const bell = fs.readFileSync('resources/js/Components/Layout/HeaderAttention.vue', 'utf8');

/** Le code seul : les commentaires de ces fichiers citent les mêmes termes. */
const code = (source) => source
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/.*$/gm, '$1')
    .replace(/<!--[\s\S]*?-->/g, '');

test('l’en-tête porte la recherche et les points d’attention', () => {
    assert.match(header, /<HeaderSearch \/>/);
    assert.match(header, /<HeaderAttention \/>/);
});

/** La marque est déjà dans le bandeau latéral ; la répéter volait la place. */
test('la marque n’est plus répétée sur grand écran', () => {
    const duplicate = /hidden xl:flex flex-col justify-center[\s\S]*?site\.brand/;
    assert.doesNotMatch(header, duplicate);
});

/**
 * Le panneau n'est pas une boîte de réception : rien n'enregistre qu'un
 * compte a lu quoi que ce soit, donc aucun « non lu » n'est affiché.
 */
test('le panneau ne prétend aucune lecture', () => {
    assert.doesNotMatch(code(bell), /non lue?s?|unread/i);
    assert.match(bell, /Points d’attention/);
});

/** Les compteurs viennent du serveur, jamais d'un calcul local. */
test('les compteurs sont ceux que le serveur renvoie', () => {
    assert.match(code(bell), /payload\.total \?\? 0/);
    assert.match(code(bell), /payload\.items \?\? \[\]/);
    assert.doesNotMatch(code(bell), /\.filter\(|\.reduce\(/);
});

/**
 * Une panne réseau ne doit pas se lire comme « rien n'attend » : sans cet
 * état, un échec et une file vide affichaient le même écran.
 */
test('un échec de chargement est distingué d’une file vide', () => {
    assert.match(code(bell), /failed\.value = true/);
    assert.match(bell, /Impossible de relever les points d’attention/);
    assert.match(bell, /Réessayer/);
});

/** L'illustration d'une source est nommée par le serveur, jamais devinée. */
test('l’icône d’une ligne vient du serveur', () => {
    assert.match(code(bell), /ICONS\[item\.icon\] \?\? Bell/);
});

/** Deux commandes sans rapport ne doivent pas se lire comme un seul bloc. */
test('la cloche est séparée du menu du compte', () => {
    assert.match(header, /gap-2 sm:gap-3/);
    assert.match(header, /h-6 w-px shrink-0 bg-border/);
});

/** Rechargé à chaque ouverture : un compteur figé décrirait un état périmé. */
test('le panneau se recharge à chaque ouverture', () => {
    const toggle = code(bell).slice(code(bell).indexOf('const toggle'));
    assert.match(toggle.slice(0, toggle.indexOf('};')), /if \(value\) load\(\)/);
});

/**
 * Une réponse lente à « ra » ne doit pas écraser les résultats de « rako ».
 */
test('la recherche annule la requête précédente', () => {
    assert.match(code(search), /controller\?\.abort\(\)/);
    assert.match(code(search), /signal: controller\.signal/);
    assert.match(code(search), /error\.name !== 'AbortError'/);
});

test('la liste courte n’est jamais le seul chemin vers un résultat', () => {
    const submit = code(search).slice(code(search).indexOf('const submit'));
    assert.match(submit.slice(0, submit.indexOf('\n};')), /\/patients\?q=/);
});

test('le raccourci clavier est annoncé, pas caché', () => {
    assert.match(code(search), /event\.ctrlKey \|\| event\.metaKey/);
    assert.match(search, /Ctrl K/);
});

/** Un écouteur global retiré au démontage, sinon il survit à la page. */
test('les écouteurs et requêtes sont nettoyés au démontage', () => {
    const teardown = code(search).slice(code(search).indexOf('onBeforeUnmount('));
    assert.match(teardown, /removeEventListener\('keydown', onShortcut\)/);
    assert.match(teardown, /clearTimeout\(timer\)/);
    assert.match(teardown, /controller\?\.abort\(\)/);
});
