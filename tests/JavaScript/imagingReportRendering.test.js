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
    assert.match(imaging, /<template v-if="item\.resulted_at">\s*<ClinicalRichTextDisplay[\s\S]*?:html="item\.result_value"/);

    // Un compte rendu long ne doit pas dérouler la liste des demandes.
    assert.match(imaging, /line-clamp-3[\s\S]*?:html="item\.result_value"/);
});

/**
 * Une seule saisie du compte rendu, où qu'on l'ouvre.
 *
 * La consultation portait un second éditeur, réduit et sans les feuilles de
 * la clinique (ADR-108) : deux outils pour la même colonne. Les deux écrans
 * ouvrent désormais le même composant, qui écrit en texte enrichi.
 */
test('les deux écrans saisissent le compte rendu avec le même composant', () => {
    const dialog = fs.readFileSync('resources/js/Components/Clinical/ImagingReportDialog.vue', 'utf8');

    assert.match(dialog, /<ClinicalRichTextEditor[\s\S]{0,200}?v-model="form\.result_value"/);

    for (const [name, source] of [['Show.vue', show], ['Requests.vue', requests]]) {
        assert.match(source, /<ImagingReportDialog/, `${name} n'ouvre pas la saisie partagée`);
        assert.doesNotMatch(source, /imaging-requests\/\$\{[^}]+\}\/result/, `${name} poste encore le compte rendu lui-même`);
    }
});

/**
 * Un compte rendu enregistré se lit, il ne se ressaisit pas. Le formulaire
 * était rattaché par `v-else-if` à la ligne des notes : sans notes, un éditeur
 * vide s'affichait sous un compte rendu déjà enregistré — et l'enregistrer
 * aurait échoué, le serveur refusant un second compte rendu.
 */
test('un compte rendu enregistré n’offre plus de saisie', () => {
    const imaging = show.slice(show.indexOf('consultation.imaging_requests?.length'));
    const item = imaging.slice(imaging.indexOf('<li v-for="item in request.items"'), imaging.indexOf('</li>'));

    // La saisie ne s'offre que sur un examen sans compte rendu.
    assert.match(item, /<span v-if="item\.resulted_at"[\s\S]*?<Button\s+v-else-if="capabilities\.can_record_imaging_result"/);
    assert.doesNotMatch(item, /<form/);
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

/**
 * ADR-108 — le compte rendu reprend la feuille papier de la clinique, et un
 * seul composant la dessine : l'impression et « Voir le résultat » ne peuvent
 * pas diverger.
 */
test('le compte rendu reprend la feuille « Résultats d’échographie »', () => {
    const documentView = fs.readFileSync('resources/js/Components/Medicine/ImagingReportDocument.vue', 'utf8');
    const print = fs.readFileSync('resources/js/Pages/Medicine/ImagingReportPrint.vue', 'utf8');

    for (const part of ['N° DE DOSSIER', 'Nom et Prénom', 'Date de Naissance', 'Sexe (M/F)', 'Adresse', 'N.B. :', 'Fait le', 'Le médecin responsable']) {
        assert.ok(documentView.includes(part), `la feuille a perdu « ${part} »`);
    }

    // Deux colonnes, comme le papier ; et le bleu s'imprime.
    assert.match(documentView, /column-count: 2/);
    assert.match(documentView, /print-color-adjust: exact/);

    for (const [name, source] of [['ImagingReportPrint.vue', print], ['Requests.vue', requests]]) {
        assert.match(source, /<ImagingReportDocument/, `${name} ne dessine pas la feuille partagée`);
    }
});
