import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Medicine/Show.vue', 'utf8');

/**
 * L'étape « Décision & clôture » : trois temps parcourus un par un.
 *
 * Ce que ces tests protègent n'est pas la mise en page, mais deux règles que
 * l'écran doit tenir : avancer n'est jamais refusé, et un diagnostic différé
 * ne laisse pas sa saisie ouverte.
 */

test('les trois sections sont affichées une par une', () => {
    for (const step of [1, 2, 3]) {
        assert.match(page, new RegExp(`v-show="closureSubStep === ${step}"`));
    }
});

/**
 * Les onglets 1 · 2 · 3 sont la seule navigation : ils restent visibles au-dessus
 * des trois sections, donc un second jeu « Précédent / Suivant » en pied
 * refaisait le même travail et suggérait un ordre imposé. Retiré à la demande
 * du propriétaire (2026-09-17).
 */
test('les onglets sont la seule navigation entre les sections', () => {
    const closure = page.slice(page.indexOf('Étapes de la clôture'), page.indexOf('v-if="medical_discharge"'));

    assert.match(closure, /@click="closureSubStep = section\.step"/);
    assert.doesNotMatch(closure, /closureSubStep \+= 1/);
    assert.doesNotMatch(closure, /closureSubStep -= 1/);
});

/**
 * Aucun onglet n'est condamné par l'état du dossier : un diagnostic peut
 * légitimement être différé (ADR-095) ou ne pas être dû (ADR-094). C'est la
 * clôture qui refuse, à l'étape 3, en nommant ce qui manque — jamais un bouton
 * grisé muet.
 */
test('atteindre une section n’est jamais refusé', () => {
    const nav = page.slice(page.indexOf('Étapes de la clôture'));
    const markup = nav.slice(0, nav.indexOf('</nav>'));

    assert.doesNotMatch(markup, /:disabled=/);
});

/** La réponse du médecin doit être suivie d'effet. */
test('« Pas maintenant » replie la saisie du diagnostic', () => {
    assert.match(page, /v-if="diagnosisReady !== false \|\| diagnosisEntryOpen" class="mt-3"/);
});

/**
 * Le serveur refuse à juste titre « Oui » sans diagnostic enregistré (ADR-095),
 * mais son message renvoie à « ci-dessous » — et « Pas maintenant » garde
 * précisément la saisie repliée. La consigne désignait donc un champ
 * invisible. « Oui » ouvre la saisie au lieu d'envoyer une réponse rejetée.
 */
test('« Oui » sans diagnostic ouvre la saisie au lieu d’échouer', () => {
    const decide = page.slice(page.indexOf('const decideDiagnosisTiming'), page.indexOf('const requiresFinalDiagnosis'));

    assert.match(decide, /if \(ready && ! activeDiagnoses\.value\.length\)/);
    assert.match(decide, /diagnosisEntryOpen\.value = true;[\s\S]{0,40}return;/);

    // Et la ligne « diagnostic différé » ne contredit plus la saisie ouverte.
    assert.match(page, /v-if="diagnosisReady === false && ! diagnosisEntryOpen"/);
});

/** Répondre reste possible : le report n’est pas définitif. */
test('la question elle-même reste toujours visible', () => {
    const question = page.indexOf('Le diagnostic peut-il être posé maintenant ?');
    assert.notEqual(question, -1);

    const block = page.slice(page.lastIndexOf('<div', question), question);
    assert.doesNotMatch(block, /v-if|v-show/);
});

/**
 * ADR-081 place correction et retrait « dans la carte Diagnostic », que
 * l'ADR-089 a déplacée ici. Les endpoints existaient, l'écran ne les appelait
 * plus : une faute de frappe restait dans le dossier sans rien pour la
 * rectifier.
 */
test('un diagnostic enregistré peut être corrigé et retiré', () => {
    const list = fs.readFileSync('resources/js/Components/Clinical/ClinicalDiagnosisList.vue', 'utf8');

    // Les deux endpoints existants, jamais un second chemin d'écriture.
    assert.match(list, /\.put\(`\/medicine\/orientations\/\$\{props\.orientationUuid\}\/diagnoses`/);
    assert.match(list, /\.post\(`\/medicine\/orientations\/\$\{props\.orientationUuid\}\/diagnoses\/cancel`/);

    // Réservés à l'auteur (ADR-035) : les drapeaux viennent du serveur.
    assert.match(list, /v-if="diagnosis\.can_edit"/);
    assert.match(list, /v-if="diagnosis\.can_cancel"/);

    // Aucune fenêtre native ne décide d'un retrait, et on ne la ferme pas
    // d'un clic à côté.
    assert.doesNotMatch(list, /window\.confirm|[^.\w]confirm\(/);
    assert.match(list, /:dismissible="false"/);

    // Et l'étape de clôture l'utilise réellement.
    assert.match(page, /<ClinicalDiagnosisList/);
});

/**
 * Le diagnostic se pose à la sous-étape 1, la conduite à tenir à la 2, et on
 * lit les obstacles depuis la 3 : « déjà sur place » ne disait pas où agir.
 */
test('chaque obstacle de la clôture mène à sa sous-étape', () => {
    assert.match(page, /v-else-if="blocker\.closure_section"/);
    assert.match(page, /@click="closureSubStep = blocker\.closure_section"/);
});
