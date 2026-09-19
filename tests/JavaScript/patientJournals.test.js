import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (file) => fs.readFileSync(file, 'utf8');

const patientPage = read('resources/js/Pages/Patients/Show.vue');
const combinedPage = read('resources/js/Pages/Medicine/PatientTreatmentJournals.vue');
const singleSheet = read('resources/js/Pages/Medicine/TreatmentJournalSheet.vue');
const carePage = read('resources/js/Pages/Care/Index.vue');
const table = read('resources/js/Components/Clinical/TreatmentJournalTable.vue');
const paperSheet = read('resources/js/Components/Clinical/PaperSheet.vue');

/**
 * ADR-118 — tous les journaux de traitement d'un patient, en un seul document.
 *
 * Le dossier du patient ouvre ce document ; il ne reconstruit rien lui-même.
 */
test('le dossier patient propose le document réunissant tous les journaux', () => {
    assert.match(patientPage, /journaux-de-traitement/);
    assert.match(patientPage, /const journalsUrl = computed/);
    assert.match(patientPage, /<NotebookText/);
    assert.match(patientPage, /Journaux de traitement/);
});

test('le bouton n’apparaît que pour qui peut lire les journaux, et s’il y a un passage à réunir', () => {
    assert.match(
        patientPage,
        /const canOpenJournals = computed\(\(\) => can\('treatment_journal\.view'\) && props\.patient\.episodes\.length > 0\)/,
    );
    assert.match(patientPage, /v-if="canOpenJournals"/);
});

test('chaque passage garde son propre lien vers son journal', () => {
    assert.match(patientPage, /const passageJournalUrl = \(episode\) => `\/passages\/\$\{episode\.uuid\}\/journal`/);
    assert.match(patientPage, /v-if="can\('treatment_journal\.view'\)"[^>]*:href="passageJournalUrl\(episode\)"/);
});

test('le document réuni imprime un passage par page et cache la consigne à l’impression', () => {
    assert.match(combinedPage, /\.pj-doc > \.ps-page \+ \.ps-page/);
    assert.match(combinedPage, /break-before: page/);
    assert.match(combinedPage, /\.pj-hint\s*\{\s*display: none !important/);
});

test('le téléchargement passe par l’impression du navigateur, sous le nom du fichier', () => {
    assert.match(combinedPage, /import \{ pdfFileName, printAsPdf \} from '@\/utilities\/pdfDownload'/);
    assert.match(combinedPage, /pdfFileName\('Journaux de traitement', props\.patient\.patient_number, props\.patient\.name\)/);
    assert.match(combinedPage, /printAsPdf\(fileName\.value\)/);
    // Aucun PDF n'est généré côté serveur (ADR-070) : pas de bibliothèque à déployer.
    assert.doesNotMatch(combinedPage, /jspdf|html2pdf|pdfmake/i);
});

test('un passage sans ligne est listé mais n’occupe pas une page blanche', () => {
    assert.match(combinedPage, /props\.passages\.filter\(\(passage\) => passage\.rows_count > 0\)/);
    assert.match(combinedPage, /v-for="passage in sheets"/);
    assert.match(combinedPage, /Aucune ligne/);
});

test('les feuilles des passages n’affichent pas les boutons d’action de la couverture', () => {
    assert.match(combinedPage, /:show-actions="false"/);
    assert.match(paperSheet, /showActions: \{ type: Boolean, default: true \}/);
    assert.match(paperSheet, /v-if="showActions"/);
});

/** Deux écrans ne dessinent jamais la même feuille de deux façons. */
test('la feuille d’un passage et le document réuni partagent la même grille', () => {
    assert.match(singleSheet, /<TreatmentJournalTable\b/);
    assert.match(combinedPage, /<TreatmentJournalTable\b/);
    assert.match(table, /VISA PERSONNEL MÉDICAL/);

    // La grille n'est plus recopiée dans la feuille du passage.
    assert.doesNotMatch(singleSheet, /VISA PERSONNEL MÉDICAL/);
});

test('le document réuni est en lecture seule : aucune saisie n’y est proposée', () => {
    assert.doesNotMatch(combinedPage, /useForm|<textarea|<Textarea|router\.post/);
});

/** La file Soins renvoie vers le même document depuis la fenêtre du patient. */
test('la fenêtre du patient, aux Soins, ouvre les journaux de ce patient', () => {
    assert.match(carePage, /can\('treatment_journal\.view'\)/);
    assert.match(carePage, /`\/patients\/\$\{openGroup\.patient\.uuid\}\/journaux-de-traitement`/);
});

/**
 * Une demande de soins n'est pas un passage : deux demandes du même passage
 * s'affichaient comme deux passages au numéro identique.
 */
test('la file Soins compte les passages, pas les demandes', () => {
    assert.match(carePage, /const passagesOf = \(group\)/);
    assert.match(carePage, /const groupSummary = \(group\)/);
    assert.match(carePage, /orientation\.care_request/);
});

/** « 2. Injection IM » d'une demande retirée ne doit pas se lire comme une seconde injection. */
test('un acte retiré par le médecin est signalé dans la ligne de la file', () => {
    assert.match(carePage, /item\.state === 'CANCELLED' \? '\(retiré\)' : null/);
});

/**
 * « 1. » et « 2. » de la ligne de la file sont les mêmes rangs que « Demande 1 »
 * et « Demande 2 » de la fenêtre : dans l'ordre où le médecin les a faites.
 */
test('la ligne de la file et la fenêtre numérotent les demandes dans le même ordre', () => {
    assert.match(carePage, /const requestsInOrder = \(group\)/);
    assert.match(carePage, /requestsInOrder\(group\)\.slice\(0, 2\)/);
    assert.doesNotMatch(carePage, /group\.orientations\.slice\(0, 2\)/);
});

