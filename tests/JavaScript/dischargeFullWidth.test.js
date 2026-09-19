import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/**
 * Le formulaire de sortie médicale est une grille à deux colonnes (diagnostic,
 * état, traitement, conseils) : dans une colonne latérale de 22 à 24 rem, il
 * s'écrasait et s'affichait mal. Il vit en bas de page, sur toute la largeur.
 */
for (const [name, path] of [['Hospitalisation', 'resources/js/Pages/Hospitalization/Show.vue'], ['Pédiatrie', 'resources/js/Pages/Pediatrics/Show.vue']]) {
    test(`${name} : la sortie n’est plus dans la colonne latérale`, () => {
        const page = fs.readFileSync(path, 'utf8');
        const aside = page.slice(page.indexOf('<aside'), page.indexOf('</aside>'));
        const after = page.slice(page.indexOf('</aside>'));

        assert.doesNotMatch(aside, /ClinicalDischargeForm|Prononcer la sortie/);
        // En bas, dans sa propre carte, avec le bouton dans l'en-tête de la carte.
        assert.match(after, /<ClinicalDischargeForm/);
        assert.match(after, /Prononcer la sortie/);
        assert.match(after, /flex flex-wrap items-start justify-between/);
    });
}

// Sur 420 px, cinq segments « État du patient » se serraient dans une ligne et
// s'affichaient « Amél… », « Non … », « Aggr… » : ils rétrécissaient au lieu de
// passer à la ligne.
test('un segment passe à la ligne au lieu de rétrécir et de tronquer son libellé', () => {
    const choice = fs.readFileSync('resources/js/Components/Clinical/ClinicalSegmentedChoice.vue', 'utf8');

    assert.match(choice, /shrink-0 grow/);
    assert.doesNotMatch(choice, /min-w-0 flex-1 rounded-md/);
    assert.match(choice, /flex flex-wrap gap-2/);
});
