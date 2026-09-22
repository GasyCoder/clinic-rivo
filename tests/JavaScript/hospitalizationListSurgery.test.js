import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/**
 * ADR-160 — la liste des hospitalisés dit qui va au bloc, qui y est, qui en revient.
 */
const list = fs.readFileSync('resources/js/Pages/Hospitalization/Index.vue', 'utf8');
const stay = fs.readFileSync('resources/js/Pages/Hospitalization/Show.vue', 'utf8');
const labels = fs.readFileSync('resources/js/utilities/surgicalRequestStatus.js', 'utf8');

test('la liste et la page du séjour lisent les mêmes libellés du bloc', () => {
    assert.match(list, /import \{ surgeryStatus \} from '@\/utilities\/surgicalRequestStatus';/);
    assert.match(stay, /import \{ surgeryStatus \} from '@\/utilities\/surgicalRequestStatus';/);
    // Plus aucune copie locale des libellés.
    assert.doesNotMatch(stay, /const SURGERY_STATUS = \{/);
    assert.doesNotMatch(list, /const SURGERY_STATUS = \{/);
    assert.match(labels, /IN_PROGRESS: \{ label: 'Au bloc'/);
});

test('chaque ligne porte son passage au bloc, et le lien seulement quand le serveur le donne', () => {
    assert.match(list, /v-if="stay\.surgery"/);
    assert.match(list, /:is="stay\.surgery\.url \? Link : 'div'"/);
    assert.match(list, /<Scissors class="h-3 w-3" \/>\{\{ surgeryBadge\(stay\.surgery\)\.label \}\}/);
    // Une seconde demande ne disparaît pas derrière la première.
    assert.match(list, /v-if="stay\.surgery\.others"/);
});

test('une carte filtre les patients qui vont au bloc, et se referme d’un second clic', () => {
    assert.match(list, /value: 'bloc', label: 'Vers le bloc'/);
    assert.match(list, /visit\(props\.filter === 'bloc' \? \{ q: props\.search \} : \{ q: props\.search, filter: 'bloc' \}\)/);
    // La recherche garde le filtre de la carte.
    assert.match(list, /@submit\.prevent="visit\(withFilter\(\{ q: query \}\)\)"/);
});
