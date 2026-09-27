import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { maternityBundle, maternityShowPage } from './support/maternityPage.js';

// ADR-204 — la page orchestre ; ses composants portent les écrans.
const page = maternityShowPage();
const show = maternityBundle();
const link = fs.readFileSync('resources/js/Components/Maternity/PregnancyLinkSection.vue', 'utf8');
const workflow = fs.readFileSync('resources/js/utilities/maternityWorkflow.js', 'utf8');
const index = fs.readFileSync('resources/js/Pages/Maternity/Index.vue', 'utf8');
const selection = fs.readFileSync('resources/js/Components/Maternity/PregnancySelectionCard.vue', 'utf8');
const summary = fs.readFileSync('resources/js/Components/Maternity/PregnancySummaryCard.vue', 'utf8');
const history = fs.readFileSync('resources/js/Components/Maternity/PregnancyHistory.vue', 'utf8');
const comparison = fs.readFileSync('resources/js/Components/Maternity/PrenatalComparisonCard.vue', 'utf8');
const header = fs.readFileSync('resources/js/Components/Maternity/MaternityEncounterHeader.vue', 'utf8');
const overview = fs.readFileSync('resources/js/Components/Maternity/Steps/PrenatalOverviewStep.vue', 'utf8');
const admission = fs.readFileSync('resources/js/Components/Maternity/Steps/DeliveryAdmissionStep.vue', 'utf8');

test('une consultation sans grossesse exige un choix explicite', () => {
    assert.match(link, /v-if="selectionRequired && ! continueFromHeader"/);
    // Une seule grossesse active : on la continue depuis l'en-tête du parcours, sans seconde carte.
    assert.match(header, /Continuer cette grossesse/);
    assert.match(page, /:continuable="continuesFromHeader"/);
    assert.match(page, /@continue="choosePregnancy"/);
    // ADR-204 — sans ce choix, rien ne s'enregistre de lui-même, et « Suivant » attend.
    assert.match(page, /enabled: \(\) => Boolean\(props\.capabilities\.can_edit && props\.record && pregnancyChosen\.value\)/);
    assert.match(page, /nextBlocked && currentStep === steps\[0\]\?\.key/);
    assert.match(page, /Rien ne s’enregistre avant ce choix/);
    assert.match(selection, /Continuer cette grossesse/);
    assert.match(selection, /Créer une nouvelle grossesse/);
    assert.match(selection, /Ce choix est obligatoire et explicite/);
});

test('le résumé longitudinal vient des props serveur et permet une correction explicite', () => {
    for (const label of ['Grossesse actuelle', 'DDR', 'DPA', 'Terme au passage', 'Consultations', 'Dernière consultation']) {
        assert.match(summary, new RegExp(label));
    }
    // La correction se propose depuis l'en-tête, seule carte de la grossesse du dossier.
    assert.match(header, /canCorrectDating/);
    assert.match(page, /:can-correct-dating="Boolean\(capabilities\.can_correct_dating\)"/);
    assert.match(show, /\/pregnancy\/dating/);
    assert.match(show, /Motif de la correction/);
    assert.match(show, /Enregistrer la correction/);
});

test('historique et grossesses précédentes restent deux ensembles distincts', () => {
    assert.match(history, /Consultations de cette grossesse/);
    assert.match(history, /Uniquement les passages rattachés à cette même grossesse/);
    assert.match(history, /Lecture seule/);
    assert.match(history, /Grossesses précédentes/);
    assert.match(history, /Voir tout l’historique/);
});

test('la comparaison prénatale ne pose aucun diagnostic dans Vue', () => {
    assert.match(comparison, /Comparaison prénatale/);
    assert.match(comparison, /aucun diagnostic automatique/);
    assert.match(comparison, /comparison\.fields/);
    assert.doesNotMatch(comparison, /patholog|diagnostic\s*:/i);
});

test('les nouveaux blocs réutilisent les primitives Shadcn', () => {
    for (const component of [selection, summary, history, comparison]) {
        assert.match(component, /Components\/Shadcn\/Card\.vue/);
        assert.doesNotMatch(component, /Components\/UI\/(Card|Button|Badge)/);
    }
});

test('les données longitudinales sans droit prénatal ne quittent pas le navigateur', () => {
    assert.match(show, /delete payload\.pregnancy_data;/);
    assert.match(show, /delete payload\.prenatal_data;/);
});

test('la file montre grossesse, DPA, terme, dernière consultation et finalité', () => {
    assert.match(index, /pregnancyContexts\[row\.uuid\]\.reference/);
    assert.match(index, /estimated_due_date/);
    assert.match(index, /gestational_age_label/);
    assert.match(index, /last_consultation_at/);
    // Le parcours se lit sur sa propre pastille, sous le nom de la patiente (ADR-204).
    assert.match(index, /<template #patient-details="\{ row \}">/);
    assert.match(index, /encounters\[row\.uuid\]\.type_label/);
    assert.doesNotMatch(index, /visit_label/);
});

/**
 * Empilés, historique, comparaison et grossesses précédentes repoussaient le
 * dossier du jour sous la ligne de flottaison : ils partagent une carte à onglets.
 */
test('le suivi de la grossesse tient dans une carte à onglets', () => {
    assert.match(history, /Components\/Shadcn\/Tabs\.vue/);
    for (const value of ['visits', 'comparison', 'previous']) {
        assert.match(history, new RegExp(`<TabsContent[^>]*value="${value}"`));
    }
    assert.match(history, /<PrenatalComparisonCard :comparison="comparison" bare \/>/);
    // ADR-204 — le suivi complet s'ouvre dans un panneau latéral, sans quitter l'étape en cours.
    const sheet = fs.readFileSync('resources/js/Components/Maternity/PregnancyHistorySheet.vue', 'utf8');
    assert.match(sheet, /<PregnancyHistory/);
    assert.match(page, /<PregnancyHistorySheet/);
    assert.doesNotMatch(page, /<PrenatalComparisonCard/);
});

test('les écarts de la comparaison viennent du serveur, jamais d’un calcul Vue', () => {
    assert.match(comparison, /field\.delta_label/);
    assert.match(comparison, /comparison\.current_visit\.label/);
    assert.match(comparison, /comparison\.interval_label/);
    assert.doesNotMatch(comparison, /field\.current\s*-\s*field\.previous|toFixed/);
    // « 64,5 kg » comme les écarts servis (« +1,5 kg »), jamais « 64.5 kg ».
    assert.match(comparison, /Intl\.NumberFormat\('fr-FR'/);
});

test('le résumé de la grossesse reste compact sur un téléphone', () => {
    const summary = fs.readFileSync('resources/js/Components/Maternity/PregnancySummaryCard.vue', 'utf8');
    assert.match(summary, /<dl class="grid grid-cols-2 [^"]*sm:grid-cols-3">/);
});

test('le terme d’une consultation passée est son instantané', () => {
    assert.match(summary, /gestational_age_source === 'snapshot'/);
    // Une grossesse livrée n'est plus « actuelle ».
    assert.match(summary, /pregnancy\.status === 'ONGOING' \? 'Grossesse actuelle' : 'Grossesse'/);
});

test('un onglet n’est « renseigné » que par une saisie du passage', () => {
    // DDR, G/P et terme viennent de la grossesse : préremplis, ils ne comptent pas.
    // Seul le rattachement fait dire que la vue d'ensemble est renseignée.
    assert.match(workflow, /return Boolean\(context\.pregnancyLinked \|\| form\?\.pregnancy_choice\)/);
    assert.doesNotMatch(workflow, /gestational_age_weeks/);
});

/**
 * L'en-tête du parcours (ADR-204) et l'étape 1 montraient chacun une carte
 * « Grossesse actuelle » avec les mêmes repères. L'en-tête est désormais la
 * seule : ce que la seconde carte portait seule y a été repris.
 */
test('la grossesse ne s’affiche qu’une fois dans le dossier', () => {
    assert.doesNotMatch(link, /PregnancySummaryCard/);
    assert.doesNotMatch(overview, /Voir l’historique complet|Rendez-vous programmés|open-history/);
    assert.doesNotMatch(admission, /risk_factors/);
    // Ce que seule la carte de l'étape 1 portait, l'en-tête le porte.
    for (const piece of [/Grossesse active trouvée/, /Corriger la datation/, /pregnancy\?\.risk_factors/, /Terme au passage/, /pregnancy\.status_label/]) {
        assert.match(header, piece);
    }
    // La carte de résumé ne sert plus qu'au panneau d'historique, en lecture.
    assert.doesNotMatch(summary, /Continuer cette grossesse|correct-dating/);
});

test('une seule grossesse active se continue depuis l’en-tête', async () => {
    const { continuesSinglePregnancy } = await import('../../resources/js/utilities/maternityWorkflow.js');
    const active = [{ uuid: 'g1' }];
    assert.equal(continuesSinglePregnancy(true, active, { uuid: 'g1' }), true);
    assert.equal(continuesSinglePregnancy(false, active, { uuid: 'g1' }), false);
    assert.equal(continuesSinglePregnancy(true, [{ uuid: 'g1' }, { uuid: 'g2' }], { uuid: 'g1' }), false);
    assert.equal(continuesSinglePregnancy(true, [], null), false);
});
