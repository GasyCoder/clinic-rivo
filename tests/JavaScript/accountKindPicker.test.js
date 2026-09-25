import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/**
 * ADR-188 — un compte « Personnel clinique » commence par la recherche de la
 * personne : le nom et l'email ne se saisissent qu'après, repris de sa fiche.
 */
const read = (file) => fs.readFileSync(file, 'utf8');
const PICKER = read('resources/js/Components/Users/AccountKindPicker.vue');
const PAGES = {
    portail: read('resources/js/Pages/SuperAdmin/Users/Index.vue'),
    site: read('resources/js/Pages/Administration/Users/Index.vue'),
};

test('la fiche se cherche en auto-complétion, au clavier comme à la souris', () => {
    assert.match(PICKER, /import \{ highlight, searchStaff \} from '@\/utilities\/staffSearch'/, 'une seule règle de recherche');
    assert.match(PICKER, /role="combobox"/);
    assert.match(PICKER, /role="listbox"/);
    assert.match(PICKER, /:aria-activedescendant="activeId"/);
    for (const key of ['ArrowDown', 'ArrowUp', 'Enter', 'Escape']) assert.ok(PICKER.includes(`'${key}'`), `la touche ${key} n’est plus gérée`);
    assert.match(PICKER, /event\.key === 'Enter'\) \{[\s\S]*?event\.preventDefault\(\);/, 'Entrée ne doit jamais soumettre le formulaire');
    assert.match(PICKER, /@mousedown\.prevent/, 'cliquer un résultat ne doit pas fermer la liste avant le choix');
});

test('une fois la personne choisie, sa fiche est montrée et peut être changée', () => {
    assert.match(PICKER, /Fiche RH reliée/);
    assert.match(PICKER, /@click="change"/);
});

for (const [where, page] of Object.entries(PAGES)) {
    test(`${where} : le nom et l’email n’apparaissent qu’une fois la personne connue`, () => {
        assert.match(page, /const identityVisible = computed\(\(\) => isEditing\.value\s*\|\| form\.account_kind === 'EXTERNAL'\s*\|\| \(form\.account_kind === 'STAFF' && form\.employee_uuid !== ''\)/);
        assert.match(page, /v-if="identityVisible"/);
    });

    test(`${where} : changer de personne ne garde pas l’email de la précédente`, () => {
        assert.match(page, /form\.email = employee\.email \?\? '';/);
        assert.doesNotMatch(page, /if \(employee\.email && \(form\.email/, 'l’ancienne règle gardait l’email d’une autre fiche');
    });
}
