import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Medicine/Show.vue', 'utf8');
const bar = fs.readFileSync('resources/js/Components/Clinical/ClinicalCondensedHeader.vue', 'utf8');

/**
 * L'invariant qui règle les trois bugs observés — page qui vibre, défilement
 * qui « dépasse », en-tête non épinglé : montrer ou masquer le bandeau
 * réduit ne doit rien retirer à la hauteur du document.
 */
test('le bandeau réduit vit dans une surcouche de hauteur nulle', () => {
    assert.match(page, /sticky top-16 z-40 hidden h-0 lg:block/);
});

test('l’en-tête complet n’est plus collant : il défile et le bandeau prend le relais', () => {
    assert.match(page, /<div ref="fullHeader" class="space-y-3">/);
    // Une seule chose s'épingle sur cette page : la surcouche.
    assert.equal(page.split('sticky top-16').length - 1, 1);
});

test('la sentinelle précède tout bloc épinglé', () => {
    assert.ok(page.indexOf('ref="headerSentinel"') < page.indexOf('sticky top-16'));
});

test('aucun garde-fou de hauteur ne subsiste : il n’a plus d’objet', () => {
    assert.doesNotMatch(page, /hasRoomToCollapse|canCollapseStickyHeader|headerReserveStyle/);
    assert.equal(fs.existsSync('resources/js/utilities/stickyHeader.js'), false);
});

test('le repli se fait par IntersectionObserver, pas par un écouteur scroll', () => {
    assert.match(page, /new IntersectionObserver\(/);
    assert.doesNotMatch(page, /addEventListener\(\s*['"]scroll['"]/);
});

test('observateurs et écouteur média sont retirés au démontage', () => {
    const teardown = page.slice(page.indexOf('onBeforeUnmount('), page.indexOf('onBeforeUnmount(') + 500);
    assert.match(teardown, /sentinelObserver\?\.disconnect\(\)/);
    assert.match(teardown, /stackObserver\?\.disconnect\(\)/);
    assert.match(teardown, /barObserver\?\.disconnect\(\)/);
    assert.match(teardown, /removeEventListener\?\.\('change', stickyQueryHandler\)/);
});

/** « Déplier » ne pouvait rien déplier : l'en-tête complet est hors écran. */
test('le contrôle du bandeau remonte au lieu de prétendre déplier', () => {
    assert.match(page, /const scrollToHeader = \(\) => \{[\s\S]{0,200}window\.scrollTo/);
    assert.match(bar, /Haut de page/);
    assert.doesNotMatch(bar, /Déplier/);
});

test('le bandeau réduit ne reclassifie aucune constante', () => {
    assert.doesNotMatch(bar, /blood_pressure_assessment|spo2_assessment|temperature_assessment/);
    assert.match(bar, /alerts:\s*\{\s*type:\s*Array/);
});

/** « Modifications non enregistrées » s'affichait deux fois dans la barre basse. */
test('la barre d’action n’annonce l’état de sauvegarde qu’une fois', () => {
    const stepBar = fs.readFileSync('resources/js/Components/Medicine/ConsultationStepBar.vue', 'utf8');
    // Le libellé appartient à ClinicalSaveStatus seul : on vérifie qu'aucun
    // élément de ce composant ne le rend à son tour (les commentaires,
    // eux, ont le droit de le nommer).
    const template = stepBar.slice(stepBar.indexOf('<template>'));
    const rendered = template.replace(/<!--[\s\S]*?-->/g, '');

    assert.doesNotMatch(rendered, /Modifications non enregistrées/);
    assert.match(stepBar, /<ClinicalSaveStatus/);
});

/**
 * Le même examen ne doit jamais s'afficher deux fois : une fois comme
 * demande transmise, une fois comme sélection en préparation.
 */
const medicine = fs.readFileSync('resources/js/Pages/Medicine/Show.vue', 'utf8');

test('une sélection déjà transmise est purgée du brouillon, jamais la demande', () => {
    assert.match(medicine, /const dropAlreadyRequestedSelection = \(\) => \{/);
    // Elle filtre la sélection…
    assert.match(medicine, /labRequestForm\.items = labRequestForm\.items\s*\n?\s*\.filter/);
    assert.match(medicine, /imagingRequestForm\.items = imagingRequestForm\.items\s*\n?\s*\.filter/);
    // …et n'écrit jamais dans les demandes transmises.
    assert.doesNotMatch(medicine, /consultation\.(lab|imaging)_requests\s*=/);
});

test('la purge rejoue à l’ouverture et après chaque envoi', () => {
    assert.match(medicine, /^dropAlreadyRequestedSelection\(\);$/m);
    assert.match(medicine, /watch\(\s*\n?\s*\(\) => \[props\.consultation\?\.lab_requests[\s\S]{0,120}dropAlreadyRequestedSelection/);
});

test('le sélecteur compare des UUID de prestation, pas des libellés', () => {
    assert.match(medicine, /alreadyRequestedLabUuids\.value\.has\(line\.catalog_item_uuid\)/);
    assert.match(medicine, /alreadyRequestedImagingUuids\.value\.has\(line\.catalog_item_uuid\)/);
});

/** « Passer cette étape » ne demandait pas de décision supplémentaire. */
const stepBar = fs.readFileSync('resources/js/Components/Medicine/ConsultationStepBar.vue', 'utf8');

test('passer une étape agit directement, sans confirmation', () => {
    // On vérifie le rendu, pas le texte du fichier : un commentaire a le
    // droit de nommer le bouton qu'on vient de retirer.
    const rendered = stepBar
        .slice(stepBar.indexOf('<template>'))
        .replace(/<!--[\s\S]*?-->/g, '');

    assert.doesNotMatch(stepBar, /askSkip/);
    assert.doesNotMatch(rendered, /Confirmer|Motif \(facultatif\)/);
    assert.match(rendered, /@click="submitSkip"/);
});

test('le refus du serveur est affiché sous la clé qu’il utilise', () => {
    // ResolveConsultationStepAction refuse sous `step` ; l'afficher sous
    // `consultation` seulement laissait le clic sans explication.
    assert.match(stepBar, /completeForm\.errors\.step/);
    assert.match(stepBar, /skipForm\.errors\.step/);
});
