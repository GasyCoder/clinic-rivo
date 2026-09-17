import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Medicine/Show.vue', 'utf8');

/**
 * Transmettre une demande d'examen est un acte signé : l'ordre part au
 * service, et le patient est facturé (ADR-105). Aucun de ces deux effets ne
 * doit pouvoir se déclencher sur un clic.
 */
test('aucun chemin n’envoie une demande sans confirmation', () => {
    // Les boutons n'ont plus de `form=` : ils ouvrent la fenêtre.
    assert.doesNotMatch(page, /form="lab-request-form"/);
    assert.doesNotMatch(page, /form="imaging-request-form"/);

    // La touche Entrée dans un champ passe par le même chemin.
    assert.match(page, /@submit\.prevent="openRequestConfirmation\('lab'\)"/);
    assert.match(page, /@submit\.prevent="openRequestConfirmation\('imaging'\)"/);

    // Un seul appelant pour chaque envoi réel : la confirmation.
    const confirm = page.slice(page.indexOf('const confirmRequest'), page.indexOf('const submitLabRequest'));
    assert.match(confirm, /submitImagingRequest\(\)/);
    assert.match(confirm, /submitLabRequest\(\)/);
});

/** Une fenêtre qu'on ferme d'un clic à côté n'est pas une signature. */
test('la fenêtre ne se ferme pas par accident', () => {
    const dialog = page.slice(page.indexOf('Confirmer la demande d’imagerie') - 400, page.indexOf('Je confirme et transmets'));

    assert.match(dialog, /:dismissible="false"/);
    // Pendant l'envoi, ni « Revenir » ni « Confirmer » ne repartent.
    assert.match(dialog, /:disabled="labRequestForm\.processing \|\| imagingRequestForm\.processing"/);
});

/**
 * Confirmer « 2 examens » sans les voir ne serait pas une signature
 * consciente : la fenêtre les nomme, et engage le médecin par son nom.
 *
 * Les conséquences (départ au service, facturation) ne sont plus énoncées —
 * retrait demandé par le propriétaire le 2026-09-17. Elles restent vraies ;
 * elles ne sont simplement plus répétées à chaque envoi.
 */
test('la fenêtre nomme ce qui est signé, et par qui', () => {
    const dialog = page.slice(page.indexOf('Confirmer la demande d’imagerie') - 400, page.indexOf('Je confirme et transmets'));

    assert.match(dialog, /v-for="item in confirmedRequestItems"/);
    assert.match(dialog, /\{\{ item\.name \}\}/);
    assert.match(dialog, /\$page\.props\.auth\.user\.name/);

    // Allégée à la demande du propriétaire.
    assert.doesNotMatch(dialog, /La demande part immédiatement/);
    assert.doesNotMatch(dialog, /porté au compte du patient/);
});

/**
 * L'étape Prescription ne rappelle plus l'orientation : elle se décide à
 * « Décision & clôture » (ADR-089), et le bouton de ce bandeau menait à
 * l'Examen clinique, où la carte n'existe plus — une impasse.
 */
test('la Prescription ne porte plus de rappel d’orientation', () => {
    assert.doesNotMatch(page, /Orientation actuelle/);
    assert.doesNotMatch(page, /Définir la suite de la prise en charge/);
});

/**
 * ADR-106 — ECG et Échographie sont deux familles distinctes, réglées au
 * catalogue et jamais devinées sur un code.
 */
test('les deux familles d’imagerie ont chacune leur onglet', () => {
    const tabs = page.slice(page.indexOf('const paraclinicalTabs'), page.indexOf('const imagingModalityOf'));

    assert.match(tabs, /value: 'CARDIOLOGY'[\s\S]*?label: 'ECG'/);
    assert.match(tabs, /value: 'ULTRASOUND'[\s\S]*?label: 'Échographie'/);

    // Un examen dont personne n'a réglé la famille ne disparaît pas : il a
    // son propre groupe, et seulement s'il en existe un.
    assert.match(tabs, /imagingByModality\('UNCLASSIFIED'\)\.length \|\| requestedIn\('UNCLASSIFIED'\)/);
});

test('la recherche ne propose jamais un examen de l’autre famille', () => {
    const filter = page.slice(page.indexOf('const filteredImagingCatalog'), page.indexOf('const filteredImagingCatalog') + 700);

    assert.match(filter, /\(item\.modality \?\? 'UNCLASSIFIED'\) === paracliniqueTab\.value/);
});

/** La famille vient du serveur, jamais d'une lecture du code ou du libellé. */
test('aucune famille n’est déduite d’un code', () => {
    assert.doesNotMatch(page, /startsWith\(['"]ECG/);
    assert.doesNotMatch(page, /startsWith\(['"]ECHO/);
    assert.match(page, /item\.modality/);
});

/**
 * Valider une ordonnance réserve les lots en FEFO (ADR-036) : c'est un acte
 * signé, au même titre qu'une demande d'examen.
 */
test('aucune ordonnance n’est validée sans confirmation', () => {
    assert.doesNotMatch(page, /form="medicine-prescription-form"/);
    assert.match(page, /@submit\.prevent="openPrescriptionConfirmation"/);
    assert.match(page, /@click="openPrescriptionConfirmation"/);

    // Un seul appelant pour la validation réelle.
    const confirm = page.slice(page.indexOf('const confirmPrescription'), page.indexOf('const prescriptionLinePosology'));
    assert.match(confirm, /addPrescription\(\)/);
});

test('la fenêtre d’ordonnance relit les doses et engage le prescripteur', () => {
    const dialog = page.slice(page.indexOf('Confirmer l’ordonnance') - 300, page.indexOf('Je confirme et valide'));

    assert.match(dialog, /:dismissible="false"/);
    // Chaque ligne, avec sa posologie composée — relire « 500 mg · orale ·
    // matin et soir » est le seul intérêt d'une confirmation ici.
    assert.match(dialog, /v-for="line in prescriptionForm\.lines"/);
    assert.match(dialog, /prescriptionLinePosology\(line\)/);
    assert.match(dialog, /\$page\.props\.auth\.user\.name/);

    // Une ligne manuelle ne réserve rien (ADR-037) : le dire évite de
    // croire le stock engagé.
    assert.match(dialog, /Hors référentiel/);
});

/**
 * La posologie se compose à un seul endroit : deux formules divergeraient,
 * et la fenêtre annoncerait autre chose que ce que la Pharmacie lira.
 */
test('la posologie de la fenêtre suit la même composition que l’éditeur', () => {
    const editor = fs.readFileSync('resources/js/Components/Clinical/PrescriptionLineEditor.vue', 'utf8');
    const fields = /dosage[\s\S]{0,200}?short_label[\s\S]{0,120}?frequency[\s\S]{0,80}?duration/;

    assert.match(editor, fields);
    assert.match(page.slice(page.indexOf('const prescriptionLinePosology')), fields);
});

/**
 * ADR-105 — un résultat encore attendu n'a jamais empêché de clôturer. En
 * ambre, juste au-dessus de l'étape de clôture, le bandeau se lisait
 * pourtant comme un verrou. Il est neutre et dit ce qu'il est.
 */
test('les résultats attendus ne se présentent pas comme un blocage', () => {
    const start = page.indexOf('v-if="awaitingResults.length"');
    const banner = page.slice(start, page.indexOf('Suivre les demandes', start));

    assert.doesNotMatch(banner, /amber/);
    assert.match(banner, /bg-muted\/40/);
    assert.match(banner, /n’empêche pas de clôturer/);

    // Et le blocage réel, lui, n'est jamais un résultat manquant.
    const blockers = page.slice(page.indexOf('const closureBlockers'), page.indexOf('const closureBlockers') + 900);
    assert.doesNotMatch(blockers, /awaitingResults/);
});
