import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
    DELIVERY_STEPS,
    PRENATAL_STEPS,
    hasValue,
    neighbours,
    stepFilled,
    stepFromHash,
    stepsFor,
} from '../../resources/js/utilities/maternityWorkflow.js';
import { maternityShowPage } from './support/maternityPage.js';

const page = maternityShowPage();
const read = (file) => fs.readFileSync(file, 'utf8');

/** ADR-204 — deux parcours, chacun ses six étapes, dans l'ordre demandé. */
test('chaque parcours a ses propres étapes', () => {
    assert.deepEqual(PRENATAL_STEPS.map((step) => step.label), ['Vue d’ensemble', 'Interrogatoire', 'Examen', 'Paraclinique', 'Ordonnance', 'Synthèse', 'Rendez-vous']);
    assert.deepEqual(DELIVERY_STEPS.map((step) => step.label), ['Admission', 'Travail', 'Surveillance', 'Accouchement', 'Nouveau-né', 'Ordonnance', 'Transmission']);
    assert.equal(stepsFor('PRENATAL'), PRENATAL_STEPS);
    assert.equal(stepsFor('DELIVERY'), DELIVERY_STEPS);
    assert.deepEqual(stepsFor(null), []);
});

test('Précédent et Suivant s’arrêtent aux extrémités', () => {
    assert.equal(neighbours(PRENATAL_STEPS, 'overview').previous, null);
    assert.equal(neighbours(PRENATAL_STEPS, 'overview').next.key, 'interview');
    assert.equal(neighbours(DELIVERY_STEPS, 'transmission').next, null);
    assert.equal(neighbours(DELIVERY_STEPS, 'transmission').previous.key, 'prescription');
});

test('l’adresse ouvre une étape, une adresse inconnue ouvre la première', () => {
    assert.equal(stepFromHash(PRENATAL_STEPS, '#examen'), 'examination');
    assert.equal(stepFromHash(DELIVERY_STEPS, 'nouveau-ne'), 'newborn');
    assert.equal(stepFromHash(PRENATAL_STEPS, '#inconnue'), 'overview');
});

test('une étape n’est « renseignée » que par ce qui a réellement été saisi', () => {
    const empty = { prenatal_data: {}, labor_data: { membranes_status: 'UNKNOWN' }, delivery_data: {}, newborn_data: { newborns: [{ sex: '' }] } };

    assert.equal(hasValue(0), true);
    assert.equal(hasValue(''), false);
    assert.equal(stepFilled('labor', empty), false, 'UNKNOWN est la valeur par défaut, pas une saisie');
    assert.equal(stepFilled('newborn', empty), false);
    assert.equal(stepFilled('interview', { prenatal_data: { reported_since_last: ['SYMPTOMS'] } }), true);
    assert.equal(stepFilled('paraclinical', empty, { labCount: 1 }), true);
    assert.equal(stepFilled('appointment', { prenatal_data: { next_appointment: { enabled: true, scheduled_at: '' } } }), false);
    assert.equal(stepFilled('overview', { pregnancy_choice: '' }, { pregnancyLinked: true }), true);
});

/**
 * Enregistrer n'est pas terminer : l'enregistrement automatique écrit le
 * dossier par la route du bouton d'avant ; seule la finalisation le clôt.
 */
test('le dossier s’enregistre de lui-même, sans jamais se finaliser', () => {
    assert.match(page, /const autosave = useAutosave\(form, send, \{/);
    assert.match(page, /\.put\(`\/maternity\/orientations\/\$\{props\.orientation\.uuid\}\/record`, \{ \.\.\.options, except: STATIC_PROPS \}\)/);
    assert.doesNotMatch(page, /send[^\n]*\/complete/);
    // L'état réel : enregistré, en cours, modifié, échec — et « Réessayer ».
    assert.match(page, /<ClinicalSaveStatus[\s\S]*?retryable[\s\S]*?@retry="autosave\.retry\(\)"/);
    const status = read('resources/js/Components/Clinical/ClinicalSaveStatus.vue');
    assert.match(status, /retryable: \{ type: Boolean, default: false \}/);
    assert.match(status, /Réessayer/);
});

test('Suivant enregistre d’abord, un refus garde l’étape', () => {
    assert.match(page, /autosave\.flush\(\s*\(\) => \{ advancing\.value = false; goTo\(around\.value\.next\.key\); \},\s*\(\) => \{ advancing\.value = false; \},\s*\)/);
    // La dernière étape porte la finalisation, nommée selon le parcours.
    assert.match(page, /\{\{ encounter\.completion_label \}\}/);
    const type = read('app/Enums/MaternityEncounterType.php');
    assert.match(type, /Terminer la consultation/);
    assert.match(type, /Clôturer l’accouchement/);
});

test('le parcours se choisit, il n’est jamais deviné', () => {
    assert.match(page, /<MaternityEncounterChooser/);
    assert.match(page, /`\/maternity\/orientations\/\$\{props\.orientation\.uuid\}\/parcours`, \{ encounter_type: type \}/);
    // Changer de parcours enregistre d'abord ce qui est tapé, et n'efface rien.
    assert.match(page, /if \(props\.record\) autosave\.flush\(proceed\)/);
    assert.match(page, /Rien n’est effacé : seules les étapes proposées changent/);
});

/** Un résultat en attente n'empêche ni « Suivant », ni de terminer. */
test('les examens réutilisent le Laboratoire et l’Imagerie, sans rien bloquer', () => {
    const panel = read('resources/js/Components/Maternity/MaternityParaclinicalPanel.vue');
    assert.match(panel, /import StayExams from '@\/Components\/Hospitalization\/StayExams\.vue'/);
    assert.match(panel, /baseUrl: `\/maternity\/orientations\/\$\{props\.orientationUuid\}`/);
    for (const value of ['lab', 'imaging', 'results']) {
        assert.match(panel, new RegExp(`<TabsTrigger value="${value}"`));
    }
    const dialog = read('resources/js/Components/Maternity/MaternityCompletionDialog.vue');
    assert.match(dialog, /cela n’empêche pas de terminer/);
    assert.doesNotMatch(dialog, /:disabled="[^"]*pendingExamCount/);
});

/** Les rappels du suivi sont indicatifs : « À valider par la clinique », jamais un verrou. */
test('les rappels du suivi prénatal restent des suggestions', () => {
    const advice = read('resources/js/Components/Maternity/PrenatalRecommendations.vue');
    assert.match(advice, /À valider par la clinique/);
    assert.match(advice, /\$emit\('request', suggestion\)|emit\('request'/);
});

/** Les rappels sont repliés par défaut ; la punaise les garde ouverts, lue après l'hydratation (SSR). */
test('les rappels du suivi prénatal se replient et s’épinglent', () => {
    const advice = read('resources/js/Components/Maternity/PrenatalRecommendations.vue');
    assert.match(advice, /const open = ref\(false\)/);
    assert.match(advice, /v-show="open"/);
    assert.match(advice, /:aria-pressed="pinned"/);
    assert.match(advice, /onMounted\(\(\) => \{\s*try \{ pinned\.value = localStorage\.getItem\(PIN_KEY\)/);
});

/** Le rendez-vous est facultatif et n'ouvre aucun passage. */
test('le prochain rendez-vous est facultatif', () => {
    const step = read('resources/js/Components/Maternity/Steps/PrenatalAppointmentStep.vue');
    assert.match(step, /Programmer un rendez-vous/);
    assert.match(step, /il n’ouvre aucun passage/);
    assert.match(step, /<Switch/);
});

/** Le Jour J garde tout le suivi prénatal à portée, sans quitter l'accouchement. */
test('l’historique s’ouvre dans un panneau pendant le parcours', () => {
    const sheet = read('resources/js/Components/Maternity/PregnancyHistorySheet.vue');
    assert.match(sheet, /import Sheet from '@\/Components\/Shadcn\/Sheet\.vue'/);
    assert.match(page, /@open-history="historyOpen = true"/);
});

/** Aucune date d'accouchement inventée : l'heure consignée clôt la grossesse. */
test('la clôture d’un accouchement lit l’heure consignée', () => {
    const dialog = read('resources/js/Components/Maternity/MaternityCompletionDialog.vue');
    assert.match(dialog, /à l’heure consignée/);
    assert.match(dialog, /la grossesse restera en cours/);
    assert.match(page, /:delivered-at="form\.delivery_data\.occurred_at"/);
});
