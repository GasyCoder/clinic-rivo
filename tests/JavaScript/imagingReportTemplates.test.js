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
    assert.match(page, /report_templates: \{ type: Array, default: \(\) => \[\] \}/);
    assert.match(page, /:templates="report_templates"/);
    assert.match(dialog, /props\.templates\.filter\(\(template\) => !template\.custom && template\.validated\)\.map\(entry\)/);

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

    // L'insertion part d'un choix explicite dans la liste, et de rien d'autre.
    assert.match(dialog, /@update:model-value="onSheetSelected"/);
    assert.match(dialog, /chooseTemplate\(template\);/);
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

/**
 * ADR-108 — la feuille est en deux colonnes suivies de cases pleine largeur :
 * chaque case a son éditeur, comme sur le papier. Le trait `<hr>` n'est plus
 * qu'un détail de stockage, jamais quelque chose que le médecin voit ou peut
 * effacer.
 */
test('chaque case de la feuille a son éditeur, et le trait n’est qu’un détail de stockage', () => {
    assert.match(dialog, /const COLUMN_BREAK = '<hr>'/);
    assert.match(dialog, /\.split\(\/<hr\\s\*\\\/\?>\/i\)/);
    assert.match(dialog, /Colonne de gauche/);
    assert.match(dialog, /Colonne de droite/);
    assert.match(dialog, /Pleine largeur/);

    // Le compte rendu envoyé reste une seule chaîne : le serveur n'apprend rien.
    assert.match(dialog, /form\.result_value = next\.join\(COLUMN_BREAK\)/);

    // Une seule barre d'outils pour toutes les cases.
    assert.match(dialog, /<ClinicalRichTextEditor[\s\S]{0,120}?bare/);
    assert.match(dialog, /const format = \(command\)/);
});

test('repasser en texte libre garde le texte : seules les colonnes disparaissent', () => {
    assert.match(dialog, /if \(key === ''\) \{[\s\S]{0,160}form\.result_value = regions\.value\.join\(''\);/);
});

test('le plafond affiché est celui du serveur', () => {
    const request = fs.readFileSync('app/Http/Requests/RecordImagingResultRequest.php', 'utf8');

    assert.match(dialog, /const REPORT_LIMIT = 10000;/);
    assert.match(request, /'result_value' => \['required', 'string', 'max:10000'\]/);
});

/**
 * ADR-108 — un médecin ajoute sa propre feuille depuis la fenêtre : le bouton
 * « + » n'apparaît qu'avec le droit, ne s'active que sur un texte écrit, et
 * prévient que le contenu devient le modèle tel quel.
 */
test('le bouton « + » enregistre le contenu actuel comme nouvelle feuille', () => {
    assert.match(dialog, /templateRights: \{ type: Object, default: \(\) => \(\{\}\) \}/);
    assert.match(dialog, /v-if="templateRights\.create"[\s\S]{0,400}?:disabled="!hasContent"[\s\S]{0,60}?@click="openSave"/);
    assert.match(dialog, /\.post\('\/medicine\/imaging-report-templates'/);
    // Le texte envoyé est celui de la fenêtre, jamais une saisie séparée.
    assert.match(dialog, /body_html: form\.result_value/);
    // Avertissement : rien n'efface pour le médecin ce qui est propre au patient.
    assert.match(dialog, /propre à ce patient/);
    assert.match(dialog, /:dismissible="false"[\s\S]{0,80}close-label="Annuler"/);
});

test('une feuille ajoutée se retire, une feuille de la clinique jamais', () => {
    assert.match(dialog, /props\.templateRights\?\.archive && currentTemplate\.value\?\.custom/);
    assert.match(dialog, /router\.delete\(`\/medicine\/imaging-report-templates\/\$\{template\.uuid\}`/);
});

test('les deux écrans transmettent les droits sur les feuilles', () => {
    const show = fs.readFileSync('resources/js/Pages/Medicine/Show.vue', 'utf8');

    assert.match(page, /:template-rights="report_template_rights"/);
    assert.match(show, /:template-rights="options\.imaging_report_template_rights \?\? \{\}"/);
});

/**
 * Enregistrer une feuille recharge la page, ce qui recrée l'objet `item` sans
 * changer d'examen. Un `watch` sur un tableau neuf était « changé » à chaque
 * fois et remettait le compte rendu à zéro — vu en navigateur.
 */
test('la remise à zéro du formulaire ne dépend que de l’examen et du mode', () => {
    assert.match(dialog, /watch\(\(\) => `\$\{props\.item\?\.uuid \?\? ''\}\|\$\{props\.mode\}`, \(\) => \{/);
    assert.doesNotMatch(dialog, /watch\(\(\) => \[props\.item\?\.uuid/);
});

/**
 * ADR-108 — l'aperçu montre le compte rendu tel qu'il s'imprimera, composé par
 * le serveur (même document que l'impression) et sans rien écrire.
 */
test('« Aperçu » ouvre le document composé par le serveur, sans rien enregistrer', () => {
    assert.match(dialog, /\/result\/preview`/);
    assert.match(dialog, /<Eye class="h-4 w-4" \/>\{\{ previewLoading \? 'Composition…' : 'Aperçu' \}\}/);
    assert.match(dialog, /:disabled="!hasContent \|\| previewLoading" @click="openPreview"/);

    // Le même composant que l'impression : ce qu'on relit est ce qui sort.
    assert.match(dialog, /<ImagingReportDocument :document="previewDocument" \/>/);

    // Depuis l'aperçu, on peut enregistrer ou revenir : jamais rester coincé.
    const preview = dialog.slice(dialog.indexOf('title="Aperçu du compte rendu"'));
    assert.match(preview, /Retour à la saisie/);
    assert.match(preview, /@click="submit"/);
});

/**
 * ADR-108 — la feuille utilisée suit le compte rendu (titre du bandeau), les
 * propositions du système se distinguent des feuilles papier, et une feuille
 * ajoutée se modifie.
 */
test('la feuille choisie est envoyée au serveur, qui en lit le titre', () => {
    assert.match(dialog, /const sheetSent = ref\(null\)/);
    assert.match(dialog, /sheetSent\.value === null \? data : \{ \.\.\.data, sheet_key: sheetSent\.value \}/);
    assert.match(dialog, /sheetSent\.value = 'FREE'/);
    // Le navigateur n'envoie jamais un titre.
    assert.doesNotMatch(dialog, /sheet_title/);
});

test('une proposition du système se distingue d’une feuille de la clinique', () => {
    assert.match(dialog, /label: 'Propositions à valider'/);
    assert.match(dialog, /Proposition du système<\/strong>, faute de modèle papier/);
    assert.match(dialog, /const isProposal = computed/);
});

test('une feuille ajoutée se modifie, une feuille de la clinique jamais', () => {
    assert.match(dialog, /props\.templateRights\?\.update && currentTemplate\.value\?\.custom/);
    assert.match(dialog, /\.put\(`\/medicine\/imaging-report-templates\/\$\{template\.uuid\}`/);
    // Sans la case cochée, seul le nom change : le contenu de la fenêtre n'est pas envoyé.
    assert.match(dialog, /\.\.\.\(replace_body \? \{ body_html: form\.result_value \} : \{\}\)/);
    assert.match(dialog, /const editOpen = ref\(false\)/);
});

test('l’état de l’édition est déclaré avant le watch immédiat', () => {
    assert.ok(dialog.indexOf('const editOpen = ref(false)') < dialog.indexOf('{ immediate: true }'));
    assert.ok(dialog.indexOf('const sheetSent = ref(null)') < dialog.indexOf('{ immediate: true }'));
});
