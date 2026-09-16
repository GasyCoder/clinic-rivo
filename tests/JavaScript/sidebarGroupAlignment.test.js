import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const menu = fs.readFileSync('resources/js/Components/Layout/Menu.vue', 'utf8');

/**
 * Une ligne du menu tombe sur la même colonne que ses voisines.
 *
 * « Pharmacie » et « Ressources humaines » sont des groupes : ils sont
 * rendus par un `<summary>`, pas par un `<Link>`. Les deux gabarits avaient
 * divergé — `ps-6` d'un côté, `px-2.5` de l'autre, et une boîte d'icône sans
 * la marge `me-1` — si bien que la ligne d'un groupe démarrait 14 px plus à
 * droite que les autres et son libellé 10 px plus loin encore. Rien ne le
 * signalait : deux chaînes de classes, écrites à deux endroits.
 *
 * Ces tests ne fixent pas une valeur esthétique, ils imposent que les deux
 * gabarits partagent exactement la même géométrie.
 */

/** Le padding de la ligne, identique pour un groupe et pour une entrée simple. */
test('un groupe reprend le padding d’une entrée simple', () => {
    const boxes = menu.match(/isAdminPortal \? 'rounded-md px-3 py-2\.5[^']*' : 'rounded-lg px-2\.5 py-2'/g) ?? [];

    // Le `<summary>` d'un groupe et le `<Link>` d'une entrée simple.
    assert.equal(boxes.length, 2);
});

/** La boîte d'icône, donc la colonne où commence le libellé. */
test('l’icône d’un groupe occupe la même boîte, marge comprise', () => {
    assert.match(
        menu,
        /flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground group-\[\.active\]\/item:text-primary', isAdminPortal \? '' : 'me-1'/,
    );
});

/** Le libellé d'un groupe a le même corps que celui d'une entrée simple. */
test('le libellé d’un groupe est au même corps', () => {
    const summary = menu.slice(menu.indexOf('<summary'), menu.indexOf('</summary>'));

    assert.match(summary, /isAdminPortal \? '' : 'text-\[13px\]'/);
});

/**
 * Le trait des sous-entrées tombe au centre de l'icône du parent :
 * 10 px de padding + la moitié d'une boîte de 32 px.
 */
test('les sous-entrées sont guidées sous l’icône du parent', () => {
    assert.match(menu, /'ms-\[26px\] border-s border-border ps-2 pe-1'/);
    assert.doesNotMatch(menu, /ps-\[60px\]/);
});
