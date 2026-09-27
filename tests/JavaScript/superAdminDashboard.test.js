import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const dashboard = fs.readFileSync('resources/js/Pages/SuperAdmin/Dashboard.vue', 'utf8');
const donut = fs.readFileSync('resources/js/Components/Charts/DonutChart.vue', 'utf8');
const bars = fs.readFileSync('resources/js/Components/Charts/BarChart.vue', 'utf8');
const trend = fs.readFileSync('resources/js/Components/Dashboard/ActivityTrendChart.vue', 'utf8');

/**
 * Le tableau de bord affichait « — » partout : aucun endpoint ne comptait
 * quoi que ce soit. Il lit désormais le rapport de chaque site.
 */
test('le tableau de bord porte des graphiques réels', () => {
    assert.match(dashboard, /import ActivityTrendChart from '@\/Components\/Dashboard\/ActivityTrendChart\.vue'/);
    assert.match(dashboard, /import BarChart from '@\/Components\/Charts\/BarChart\.vue'/);
    assert.match(dashboard, /import DonutChart from '@\/Components\/Charts\/DonutChart\.vue'/);

    // Courbe, histogramme, diagramme : les trois lectures demandées.
    assert.match(trend, /viewMode === 'bars'/);
    assert.match(trend, /Histogramme/);
    assert.match(donut, /stroke-dasharray/);
    assert.match(bars, /const max = computed\(/);
});

/**
 * La règle qui porte tout l'écran : une donnée absente n'est pas un zéro.
 * Un site injoignable ou une permission manquante s'écrit « — », jamais
 * « 0 », sinon on décide sur un chiffre faux (ADR-048).
 */
test('une valeur absente s’écrit « — », jamais zéro', () => {
    assert.match(dashboard, /const number = \(value\) => \(value === null \|\| value === undefined\s*\n\s*\? '—'/);
    assert.match(dashboard, /const money = \(value\) => \(value === null \|\| value === undefined\s*\n\s*\? '—'/);

    // Le consolidé ne renvoie 0 que si un site a réellement compté 0.
    assert.match(dashboard, /const consolidated = \(key, pick\) => \(readable\(key\)\.some\(/);
});

/** Les sites hors ligne sont annoncés, pas silencieusement exclus du total. */
test('les sites injoignables sont nommés en tête', () => {
    assert.match(dashboard, /const offline = computed\(\(\) => props\.reports\.filter\(\(report\) => ! report\.ok\)\)/);
    assert.match(dashboard, /ne répondent pas — leurs chiffres ne sont pas dans ces totaux/);

    // Et une section refusée sur un site est signalée là où elle manque.
    assert.match(dashboard, /const blocked = \(key\) =>/);
});

test('l’alerte des API peut être acquittée durablement par compte', () => {
    assert.match(dashboard, /const offlineAcknowledged = ref\(false\)/);
    assert.match(dashboard, /window\.localStorage/);
    assert.doesNotMatch(dashboard, /const window\s*=/);
    assert.match(dashboard, /onMounted\(\(\) => \{\s*offlineAcknowledged\.value = isOfflineAlertAcknowledged/);
    assert.match(dashboard, /rivo:super-admin:offline-alert:\$\{page\.props\.auth\?\.user\?\.uuid/);
    assert.match(dashboard, /v-if="offlineAlertReady && offline\.length && ! offlineAcknowledged"/);
    assert.match(dashboard, /@click="acknowledgeOfflineAlert"/);
    assert.match(dashboard, /J’ai compris/);
});

test('les cartes des sites distinguent API configurée et API réellement disponible', () => {
    assert.match(dashboard, /const reportForSite = \(code\) => props\.reports\.find/);
    assert.match(dashboard, /API disponible/);
    assert.match(dashboard, /API indisponible/);
    assert.match(dashboard, /API à configurer/);
});

/**
 * Les séries des sites sont additionnées date à date. Le serveur borne la
 * fenêtre, donc les index correspondent d'un site à l'autre.
 */
test('la courbe additionne les sites retenus', () => {
    assert.match(dashboard, /existing\.values = existing\.values\.map\(\(value, index\) => value \+ \(serie\.values\[index\] \?\? 0\)\)/);
    assert.match(dashboard, /const trend = computed\(/);
});

/** Un graphique dont les valeurs n’existent que dans le tracé est illisible. */
test('les chiffres vivent dans la légende, pas seulement dans le dessin', () => {
    assert.match(donut, /aria-hidden="true"/);
    assert.match(donut, /<span class="shrink-0 font-semibold tabular-nums text-foreground">\{\{ format\(arc\.value\) \}\}<\/span>/);
    assert.match(bars, /\{\{ format\(bar\.value\) \}\}/);
});

/** La fenêtre d’analyse est un paramètre serveur, pas un filtre local. */
test('changer la fenêtre relit le serveur', () => {
    assert.match(dashboard, /router\.get\('\/', \{ days: Number\(value\) \}/);
    assert.match(dashboard, /const windowOptions = \[/);
});

/**
 * Le graphique a été dessiné pour sept jours. Le portail lui en envoie trente
 * ou quatre-vingt-dix : les dates se sont superposées en une bouillie
 * illisible — « Mar 1Mer 19Jeu 20 ».
 */
test('les dates ne se chevauchent plus sur une longue période', () => {
    assert.match(trend, /const MAX_LABELS = 12;/);
    // Compté depuis la fin : le dernier jour, celui qu'on regarde en
    // premier, est toujours écrit.
    assert.match(trend, /index === dates\.value\.length - 1\s*\n\s*\|\| \(dates\.value\.length - 1 - index\) % labelStride\.value === 0/);
    assert.match(trend, /v-if="isLabelVisible\(index\)"/);

    // Au-delà d'une semaine, le jour de la semaine ne situe plus rien.
    assert.match(trend, /if \(labelStride\.value > 1\) \{[\s\S]*?day: '2-digit', month: '2-digit'/);
});

/** Un graphique ne doit pas prendre la hauteur cumulée de trois cartes. */
test('le graphique principal garde sa propre ligne et les répartitions sont équilibrées', () => {
    assert.match(dashboard, /<section class="min-w-0" aria-label="Évolution de l’activité">/);
    assert.match(dashboard, /<ActivityTrendChart[\s\S]*?compact[\s\S]*?\/>/);
    assert.match(dashboard, /grid items-start gap-4 md:grid-cols-2 xl:grid-cols-3/);
    assert.match(trend, /compact \? 'h-\[300px\]'/);

    // Les trois cartes demandées, une seule fois chacune.
    for (const title of ['Encaissements par mode', 'Patients enregistrés', 'Comptes patients']) {
        assert.equal(dashboard.split(title).length - 1, 1, `« ${title} » ne doit apparaître qu’une fois`);
    }

    // Le montant en ariary tient dans le centre du diagramme.
    assert.match(dashboard, /unit="encaissé" compact-center/);
    assert.match(donut, /compactCenter: \{ type: Boolean, default: false \}/);
});
