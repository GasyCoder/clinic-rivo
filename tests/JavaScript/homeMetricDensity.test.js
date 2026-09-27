import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const home = fs.readFileSync('resources/js/Pages/Home.vue', 'utf8');
const counters = fs.readFileSync('resources/js/Components/Clinical/QueueCounters.vue', 'utf8');
const hrHome = fs.readFileSync('resources/js/Components/Administration/HrHomePanel.vue', 'utf8');
const hrFigures = fs.readFileSync('resources/js/Components/Administration/HrFigures.vue', 'utf8');

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
    assert.match(section, /flex min-w-0 items-center gap-3 rounded-lg border/);
    assert.match(section, /grid h-10 w-10 shrink-0 place-items-center rounded-lg/);
    assert.match(section, /text-2xl font-bold leading-none tabular-nums/);

    // La référence : si les compteurs de file changent, ce test le dira.
    assert.match(counters, /grid h-10 w-10 shrink-0 place-items-center rounded-lg/);
    assert.match(counters, /block text-2xl font-bold leading-none tabular-nums/);
});

/**
 * La carte se lit en largeur : deux côte à côte sur un téléphone
 * tronqueraient le libellé au lieu de gagner de la place.
 */
test('la seconde colonne attend d’avoir la place', () => {
    assert.doesNotMatch(home, /const gridFor/);
    assert.match(home, /grid-cols-\[repeat\(auto-fit,minmax\(220px,1fr\)\)\]/);
});

test('l’en-tête reste neutre et réserve la couleur forte à l’action principale', () => {
    assert.doesNotMatch(home, /bg-gradient-to-|blur-3xl/);
    assert.match(home, /<Card class="overflow-hidden">/);
    assert.match(home, /<Button[\s\S]*?v-if="primaryAction"[\s\S]*?:href="primaryAction\.link"/);
    assert.match(home, /variant="outline"[\s\S]*?v-for="link in quickLinks"|v-for="link in quickLinks"[\s\S]*?variant="outline"/);
});

test('activité et espaces composent une grille utile sans bande vide', () => {
    assert.ok(home.includes('xl:grid-cols-[minmax(0,1fr)_360px]'));
    assert.match(home, /<main class="min-w-0 space-y-5">[\s\S]*?<Card v-if="metrics\.length"[\s\S]*?<ActivityTrendChart/);
    assert.match(home, /<aside class="space-y-5 xl:sticky xl:top-24">[\s\S]*?Vos espaces de travail/);
});

test('le panneau RH est une Card shadcn sans carte imbriquée', () => {
    assert.match(hrHome, /import Card from '@\/Components\/Shadcn\/Card\.vue'/);
    assert.match(hrHome, /<Card class="overflow-hidden"/);
    assert.match(hrHome, /<HrFigures[^>]*embedded/);
    assert.match(hrFigures, /embedded \? 'rounded-none border-0 shadow-none'/);
});
