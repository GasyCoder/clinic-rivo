import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const menu = fs.readFileSync('resources/js/Components/Layout/Menu.vue', 'utf8');

/**
 * Savoir sur quelle page on est.
 *
 * La sélection ne se distinguait que par sa teinte. En thème sombre, le
 * survol (`accent`, clarté 19 %) sortait plus clair que l'état actif
 * (`primary/10`, ≈ 16 % sur ce fond) : deux taches sombres impossibles à
 * départager, et c'est la mauvaise qui l'emportait.
 *
 * La logique, elle, n'a jamais allumé deux entrées — `sidebarMenu.test.js`
 * le vérifie déjà. Ce fichier ne protège que la lisibilité.
 */

/** Le repère positionnel : indépendant de toute nuance et de tout thème. */
test('l’entrée active porte un rail, pas seulement une couleur', () => {
    assert.match(menu, /v-if="isActive\(item\)"\s*\n\s*class="absolute inset-y-1 start-0 w-1 rounded-e-full bg-primary"/);
});

test('le survol ne peut plus rivaliser avec la sélection', () => {
    assert.match(menu, /isActive\(item\) \? 'bg-primary\/15 text-primary ring-1 ring-inset ring-primary\/25' : ''/);
    assert.match(menu, /!isActive\(item\) \? 'hover:bg-accent\/60' : ''/);

    // Le survol ne s'applique jamais à l'entrée déjà active : sans cette
    // garde, passer la souris dessus la repeindrait d'une autre teinte.
    assert.doesNotMatch(menu, /'hover:bg-accent'(?!\/)/);
});

/** Les groupes (Ressources humaines, Pharmacie) suivent la même règle. */
test('un groupe actif se distingue comme une entrée simple', () => {
    assert.match(menu, /isAdminPortal && isActive\(item\) \? 'bg-primary\/15 ring-1 ring-inset ring-primary\/25' : ''/);
});

/** Le point reste, mais il n'est plus le seul indice. */
test('le point de sélection subsiste', () => {
    assert.match(menu, /v-if="isActive\(item\)" class="[^"]*rounded-full bg-primary/);
});
