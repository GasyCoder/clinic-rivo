import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const queue = fs.readFileSync('resources/js/Pages/Medicine/Index.vue', 'utf8');
const users = fs.readFileSync('resources/js/Pages/Administration/Users/Index.vue', 'utf8');

/** ADR-099 : tout écran retouché passe à shadcn-vue. */
test('la file Médecine et les Utilisateurs n’utilisent plus DashWind', () => {
    for (const [name, source] of [['Médecine', queue], ['Utilisateurs', users]]) {
        for (const dashwind of ['UI/Icon.vue', 'UI/Button.vue', 'UI/Badge.vue', 'UI/Input.vue', 'UI/Avatar.vue', 'UI/Card.vue']) {
            assert.ok(! source.includes(dashwind), `${name} importe encore ${dashwind}`);
        }
        assert.doesNotMatch(source, /<Icon[ />]/, `${name} rend encore <Icon>`);
        assert.match(source, /from 'lucide-vue-next'/);
    }
});

/** Une couleur codée en dur ne suit pas le thème. */
test('les deux écrans utilisent les tokens sémantiques', () => {
    const palette = /(text|bg|border|divide|ring)-(slate|gray)-(50|100|200|300|400|500|600|700|800|900|1000)\b/;

    assert.doesNotMatch(queue, palette);
    assert.doesNotMatch(users, palette);
});

const counters = fs.readFileSync('resources/js/Components/Clinical/QueueCounters.vue', 'utf8');

/**
 * Les compteurs étaient des pastilles dans une barre d'onglets, lisibles
 * seulement en les cherchant — alors que « combien attendent » et « y a-t-il
 * une urgence » sont les deux questions qu'on se pose devant l'écran.
 */
test('les compteurs de file sont des cartes', () => {
    assert.match(counters, /rounded-xl border bg-card p-4 text-start shadow-sm/);
    assert.match(counters, /text-2xl font-bold leading-none tabular-nums/);
    assert.match(counters, /const TONES = \{/);
});

/**
 * La carte **est** le filtre : un seul contrôle au lieu de deux qui
 * disaient la même chose, et l'état reste annoncé aux lecteurs d'écran.
 */
test('la carte filtre la file et annonce son état', () => {
    assert.match(counters, /:aria-pressed="clickable\(tile\) \? Boolean\(tile\.active\) : undefined"/);
    assert.match(counters, /\$emit\('select', tile\.value\)/);

    // Une carte non filtrante ne doit pas se présenter comme cliquable.
    assert.match(counters, /const clickable = \(tile\) => tile\.filterable !== false/);
    assert.match(counters, /clickable\(tile\) \? 'button' : 'div'/);
});

/** L'ancienne barre d'onglets a disparu de la file Médecine. */
test('la file Médecine utilise le composant partagé', () => {
    assert.match(queue, /import QueueCounters from '@\/Components\/Clinical\/QueueCounters\.vue'/);
    assert.match(queue, /<QueueCounters[\s\S]{0,300}?active: filter === tab\.value/);
    assert.doesNotMatch(queue, /min-w-5 rounded px-1\.5 py-0\.5 text-center text-\[10px\] font-bold/);
});

/**
 * Le compteur vient du serveur, qui compte toute la file ; celui de la
 * page affichée mentirait dès la deuxième page.
 */
test('les compteurs restent ceux du serveur', () => {
    assert.match(queue, /counts\[tab\.value\]/);
    assert.doesNotMatch(queue, /counts\[tab\.value\] = /);
});

/** Un filtre actif doit pouvoir se retirer sans chercher le bon onglet. */
test('un filtre actif se retire d’un clic', () => {
    assert.match(queue, /tout afficher/);
    assert.match(queue, /@click="selectFilter\('all'\)"/);
});

const QUEUES = {
    'Médecine': 'resources/js/Pages/Medicine/Index.vue',
    'Soins': 'resources/js/Pages/Care/Index.vue',
    'Laboratoire': 'resources/js/Pages/Laboratory/Index.vue',
    'Demandes d’examens': 'resources/js/Pages/Medicine/Requests.vue',
    'Sorties & règlements': 'resources/js/Pages/Reception/Settlements/Index.vue',
    'Maternité': 'resources/js/Pages/Maternity/Index.vue',
};

/** Une file ne doit pas changer d'allure selon le service où l'on se trouve. */
test('toutes les files partagent le même composant de compteurs', () => {
    for (const [name, file] of Object.entries(QUEUES)) {
        const source = fs.readFileSync(file, 'utf8');

        assert.match(source, /import QueueCounters from '@\/Components\/Clinical\/QueueCounters\.vue'/, `${name} n’utilise pas le composant`);
        assert.match(source, /<QueueCounters/, `${name} ne rend pas les cartes`);
    }
});

/**
 * Le compte vient du serveur. Recalculé depuis la page affichée, il
 * mentirait dès la deuxième — et c'est précisément quand la file est
 * longue qu'on le regarde.
 */
test('aucune file ne recompte ses cartes depuis la page affichée', () => {
    for (const [name, file] of Object.entries(QUEUES)) {
        const source = fs.readFileSync(file, 'utf8');
        const tiles = source.slice(source.indexOf('counterTiles'), source.indexOf('</script>'));

        // Un compteur dérivé de `.data` serait faux au-delà de la première page.
        assert.doesNotMatch(tiles, /count:[^,\n]*\.data\b/, `${name} recompte depuis la page`);
    }
});

/** Un chiffre financier ne s'affiche pas sans le droit de voir les comptes. */
test('le reste à payer suit la permission, pas seulement l’affichage', () => {
    const settlements = fs.readFileSync('resources/js/Pages/Reception/Settlements/Index.vue', 'utf8');
    const controller = fs.readFileSync('app/Http/Controllers/Reception/EpisodeSettlementController.php', 'utf8');

    assert.match(controller, /'outstanding' => \$canViewAccounts/);
    assert.match(settlements, /props\.counts\.outstanding !== null/);
});
