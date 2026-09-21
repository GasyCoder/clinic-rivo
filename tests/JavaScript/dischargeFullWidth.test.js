import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/**
 * Le formulaire de sortie médicale est une grille à deux colonnes (diagnostic,
 * état, traitement, conseils) : dans une colonne latérale de 22 à 24 rem, il
 * s'écrasait et s'affichait mal. Il vit en bas de page, sur toute la largeur.
 */
// ADR-156 — l'Hospitalisation n'a plus de formulaire de sortie : elle se
// prononce dans la visite de service, et termine le séjour.
for (const [name, path] of [['Pédiatrie', 'resources/js/Pages/Pediatrics/Show.vue']]) {
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

/** ADR-162 — la sortie d'un patient hospitalisé se prononce sur la page du séjour, et là seulement. */
test('la page du séjour prononce la sortie dans son onglet, en pleine largeur', () => {
    const show = fs.readFileSync('resources/js/Pages/Hospitalization/Show.vue', 'utf8');
    const exit = fs.readFileSync('resources/js/Components/Hospitalization/StayExit.vue', 'utf8');

    assert.match(show, /activeTab === 'sortie'/);
    assert.match(show, /<StayExit/);
    // Le même formulaire que la consultation, diagnostic final toujours exigé.
    assert.match(exit, /<ClinicalDischargeForm/);
    assert.match(exit, /:requires-diagnosis="true"/);
    assert.match(exit, /\/hospitalisation\/\$\{props\.stayUuid\}\/sortie/);
    // Prononcer la sortie est un acte signé (ADR-106).
    assert.match(exit, /:dismissible="false"/);
});
