import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const home = fs.readFileSync('resources/js/Pages/Home.vue', 'utf8');
const counters = fs.readFileSync('resources/js/Components/Clinical/QueueCounters.vue', 'utf8');

const section = home.slice(home.indexOf('aria-labelledby="today-title"'), home.indexOf('<ActivityTrendChart'));

/**
 * Les cartes « Activité aujourd'hui » occupaient une hauteur fixe de 160 px
 * chacune pour n'afficher qu'un nombre : trois groupes remplissaient l'écran
 * avant même d'arriver aux graphiques.
 */
test('une carte d’indicateur ne réserve plus une hauteur fixe', () => {
    assert.doesNotMatch(section, /min-h-40/);
    assert.doesNotMatch(section, /text-3xl/);
    assert.doesNotMatch(section, /line-clamp-2/);
});

/**
 * La Vue d'ensemble et les six files cliniques montrent la même chose — un
 * chiffre, son libellé, une précision — et doivent donc se lire pareil.
 */
test('la carte reprend la densité des compteurs de file', () => {
    assert.match(section, /flex h-full items-start gap-3 p-3/);
    assert.match(section, /grid h-10 w-10 shrink-0 place-items-center rounded-lg/);
    assert.match(section, /block text-2xl font-bold leading-none tabular-nums/);

    // La référence : si les compteurs de file changent, ce test le dira.
    assert.match(counters, /grid h-10 w-10 shrink-0 place-items-center rounded-lg/);
    assert.match(counters, /block text-2xl font-bold leading-none tabular-nums/);
});

/**
 * La carte se lit en largeur : deux côte à côte sur un téléphone
 * tronqueraient le libellé au lieu de gagner de la place.
 */
test('la seconde colonne attend d’avoir la place', () => {
    assert.doesNotMatch(home, /\d: 'grid-cols-2\b/);
    assert.match(home, /2: 'grid-cols-1 sm:grid-cols-2'/);
});
