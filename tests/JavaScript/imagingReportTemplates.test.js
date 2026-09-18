import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Medicine/Requests.vue', 'utf8');
// La saisie est partagée entre « Demandes d'examens » et la consultation :
// ses règles se vérifient dans le composant qui les porte.
const dialog = fs.readFileSync('resources/js/Components/Clinical/ImagingReportDialog.vue', 'utf8');

/**
 * ADR-108 — les feuilles de la clinique arrivent du serveur, jamais d'une
 * copie dans l'écran : le compte rendu imprimé et la feuille saisie doivent
 * venir de la même source.
 */
test('les feuilles viennent du serveur', () => {
    assert.match(page, /reportTemplates: \{ type: Array, default: \(\) => \[\] \}/);
    assert.match(page, /:templates="reportTemplates"/);
    assert.match(dialog, /v-for="template in templates"/);

    // Aucune feuille codée dans un écran.
    for (const source of [page, dialog]) {
        assert.doesNotMatch(source, /ÉCHOGRAPHIE ABDOMINO|SAC OVULAIRE|Clarté nucale/);
    }
});

/**
 * ADR-052 — rien n'est déduit du nom ni du code d'un examen. « Échographie
 * pelvienne » et « échographie abdomino-pelvienne » se ressemblent assez pour
 * qu'une correspondance automatique insère la mauvaise feuille.
 */
test('aucune feuille n’est choisie à la place du médecin', () => {
    const logic = dialog.slice(dialog.indexOf('const pendingTemplate'), dialog.indexOf('</script>'));

    // Pas de correspondance sur le libellé de l'examen.
    assert.doesNotMatch(logic, /item\.exam/);
    assert.doesNotMatch(logic, /\.includes\(|startsWith\(|match\(/);

    // L'insertion part d'un clic, et de rien d'autre.
    assert.match(dialog, /@click="chooseTemplate\(template\)"/);
});

/** Une feuille remplace tout : sur un champ déjà écrit, on demande avant. */
test('insérer une feuille n’écrase jamais une saisie en silence', () => {
    const choose = dialog.slice(dialog.indexOf('const chooseTemplate'), dialog.indexOf('const applyTemplate'));

    assert.match(choose, /if \(hasContent\.value\)/);
    assert.match(choose, /pendingTemplate\.value = template;[\s\S]{0,40}return;/);

    const confirmation = dialog.slice(dialog.indexOf('title="Remplacer le compte rendu ?"') - 200);
    assert.match(confirmation, /:dismissible="false"/);
    assert.match(confirmation, /Conserver ma saisie/);
});

/** Un champ ne portant que des balises vides n'est pas « déjà écrit ». */
test('un compte rendu vide n’ouvre pas la confirmation', () => {
    // On ne découpe pas sur le premier « ; » : il y en a un dans la regex
    // `/&nbsp;|\u00a0/`, et la coupure tombait au milieu du littéral.
    const start = dialog.indexOf('const hasContent');
    const end = dialog.indexOf(".trim() !== ''", start) + ".trim() !== ''".length;
    const expression = dialog.slice(dialog.indexOf('=>', start) + 2, end).trim();

    // eslint-disable-next-line no-new-func
    const run = new Function('form', `return (${expression});`);

    assert.equal(run({ result_value: '' }), false);
    assert.equal(run({ result_value: '<p></p><p>&nbsp;</p>' }), false);
    assert.equal(run({ result_value: '<p>Foie normal</p>' }), true);
});

/**
 * ADR-109 — le besoin de l'arrivée est déjà connu. Faire chercher le même
 * examen au catalogue, c'est ressaisir ce que le dossier porte déjà, et
 * c'était aussi le chemin qui le facturait une seconde fois.
 */
test('le besoin de l’arrivée entre dans la demande sans être recherché', () => {
    const show = fs.readFileSync('resources/js/Pages/Medicine/Show.vue', 'utf8');

    // La liste vient du serveur : l'écran ne déduit rien d'un libellé.
    assert.match(show, /props\.consultation\?\.planned_paraclinical \?\? \[\]/);
    assert.match(show, /plannedLab = computed\(\(\) => plannedParaclinical\.value\.filter\(\(line\) => line\.module === 'LABORATORY'\)\)/);
    assert.match(show, /plannedImaging = computed\(\(\) => plannedParaclinical\.value\.filter\(\(line\) => line\.module === 'IMAGING'\)\)/);

    // Préselection une seule fois par examen : une ligne retirée à la main
    // ne revient jamais.
    const watcher = show.slice(show.indexOf('const preselected = new Set()'), show.indexOf('const removeImagingItem'));
    assert.match(watcher, /if \(preselected\.has\(line\.catalog_item_uuid\)\) continue;/);
    assert.match(watcher, /addLabItem\(/);
    assert.match(watcher, /addImagingItem\(/);
    assert.match(watcher, /\{ immediate: true \}/);

    // Et l'écran dit en une ligne pourquoi la ligne est là, et qu'elle ne
    // refacture rien — court à la demande du propriétaire (2026-09-18).
    assert.match(show, /demandé à l’arrivée<template v-if="planned\w+\.some\(\(line\) => line\.already_billed\)">, déjà facturé<\/template>/);
});
