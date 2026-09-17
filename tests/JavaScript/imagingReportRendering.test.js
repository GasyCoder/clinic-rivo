import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const show = fs.readFileSync('resources/js/Pages/Medicine/Show.vue', 'utf8');
const requests = fs.readFileSync('resources/js/Pages/Medicine/Requests.vue', 'utf8');

/**
 * Le compte rendu d'imagerie est du texte enrichi, assaini côté serveur.
 * Interpolé par `{{ }}`, il s'affichait balises comprises :
 * « UTERUS<p>Orientation: Antéversé… ».
 */
test('le compte rendu s’affiche rendu, pas en balises', () => {
    // Le bloc Imagerie seulement : le résultat de laboratoire est du texte
    // brut et s'interpole légitimement dans le bloc voisin.
    const imaging = show.slice(show.indexOf('consultation.imaging_requests?.length'));

    assert.doesNotMatch(imaging, /\{\{ item\.result_value \}\}/);
    assert.match(imaging, /<ClinicalRichTextDisplay\s+v-if="item\.resulted_at"[\s\S]*?:html="item\.result_value"/);

    // Un compte rendu long ne doit pas dérouler la liste des demandes.
    assert.match(imaging, /line-clamp-3[\s\S]*?:html="item\.result_value"/);
});

/**
 * Le même champ recevait du HTML depuis « Demandes d'examens » et du texte
 * brut depuis la consultation : une seule colonne, deux formats.
 */
test('les deux écrans saisissent le compte rendu dans le même éditeur', () => {
    for (const [name, source] of [['Show.vue', show], ['Requests.vue', requests]]) {
        assert.match(
            source,
            /<ClinicalRichTextEditor[\s\S]{0,400}?result_value/,
            `${name} ne saisit pas le compte rendu en texte enrichi`,
        );
    }
});

/**
 * Un éditeur riche vide vaut `<p></p>` : le tester avec `.trim()` activerait
 * le bouton sur un compte rendu vide. Le serveur tranche après
 * assainissement, comme sur l'autre écran.
 */
test('la vacuité du compte rendu est décidée par le serveur', () => {
    assert.doesNotMatch(show, /imagingResultForm\(item\.uuid\)\.result_value\.trim\(\)/);
});

/** Un résultat d'analyse reste du texte brut : rien à rendre en HTML. */
test('le résultat de laboratoire n’est pas traité comme du texte enrichi', () => {
    const lab = fs.readFileSync('resources/js/Pages/Laboratory/Index.vue', 'utf8');

    assert.match(lab, /\{\{ item\.result_value \}\}/);
    assert.doesNotMatch(lab, /ClinicalRichTextEditor/);
});
