import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Surgery/Show.vue', 'utf8');
const workflow = fs.readFileSync('resources/js/utilities/surgicalWorkflow.js', 'utf8');
const header = fs.readFileSync('resources/js/Components/Surgery/EnTeteDossierChirurgical.vue', 'utf8');
const accordion = fs.readFileSync('resources/js/Components/Surgery/ClinicalAccordionSection.vue', 'utf8');
const careSummary = fs.readFileSync('resources/js/Components/Surgery/CareSummaryReadOnly.vue', 'utf8');
const entry = fs.readFileSync('resources/js/Components/Surgery/EntreeBloc.vue', 'utf8');
const exit = fs.readFileSync('resources/js/Components/Surgery/SortieBloc.vue', 'utf8');

test('le dossier Chirurgie suit un assistant clinique en cinq étapes', () => {
    // Les étapes vivent dans l'utilitaire de workflow, lu sur le statut réel ;
    // la page les affiche sans les recompter.
    for (const step of ['case', 'preparation', 'intervention', 'block-exit', 'followup']) {
        assert.match(workflow, new RegExp(`id: '${step}'`));
    }

    assert.match(page, /surgerySteps\(props\.surgicalRequest\)/);
    assert.match(page, /Parcours \{\{ workspaceMeta\.label \}\}/);
    assert.match(page, /completedTabs/);
    // La barre du bas porte la prochaine action du workflow, pas un simple « suivant ».
    assert.match(page, /sticky bottom-3/);
    assert.match(page, /runNextAction/);
});

test('les feuilles papier transmises restent présentes sans double saisie des Soins', () => {
    assert.match(entry, /Entrée au bloc/);
    assert.match(entry, /Préparation du patient/);
    assert.match(exit, /Sortie du bloc et surveillance/);
    assert.match(page, /CareSummaryReadOnly/);
    assert.match(careSummary, /lecture seule/i);
});

test('la date de naissance absente laisse place à l’âge déclaré', () => {
    assert.match(header, /patient\.value\.declared_age !== null/);
    assert.match(header, /Date de naissance non renseignée/);
    assert.match(header, /Âge non renseigné/);
});

test('la page retouchée utilise les primitives shadcn et les icônes lucide', () => {
    const sources = [page, header, accordion, entry, exit];

    for (const source of sources) {
        assert.doesNotMatch(source, /Components\/UI\/(Button|Card|CardBody|Icon|Input|Avatar)\.vue/);
    }

    assert.match(page, /Components\/Shadcn\/Button\.vue/);
    assert.match(page, /lucide-vue-next/);
    assert.match(header, /Components\/Shadcn\/Card\.vue/);
});

test('aucun encaissement ne rejoint le dossier clinique Chirurgie', () => {
    assert.doesNotMatch(page, /Encaisser|Créer un paiement|cash\/payments/i);
});
