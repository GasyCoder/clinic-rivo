import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { analysisInitials, designationWeight, hasEntries, labTaskState, notesFromNodes, notesPayload } from '../../resources/js/utilities/labWorkbench.js';

/** ADR-218 — la note de chaque ligne : une note vidée part pour être effacée, une ligne jamais annotée ne part pas. */
test('la note d’une ligne part seulement quand elle dit quelque chose ou qu’elle est effacée', () => {
    const served = notesFromNodes([{ uuid: 'a', note: 'Microcytose' }, { uuid: 'b', note: null }, { uuid: 'c' }]);
    assert.deepEqual(served, { a: 'Microcytose', b: '', c: '' });

    assert.deepEqual(notesPayload({ a: '', b: '  À contrôler  ', c: '' }, served), [
        { analysis_uuid: 'a', note: '' },
        { analysis_uuid: 'b', note: 'À contrôler' },
    ]);
});

test('le compte rendu est le PDF du serveur, montré, téléchargé et imprimé depuis la page', () => {
    const page = fs.readFileSync('resources/js/Pages/Laboratory/ResultsPrint.vue', 'utf8');
    assert.match(page, /resultats\.pdf/);
    assert.match(page, /\/resultats-analyses\/\$\{props\.labRequest\.uuid\}\/pdf/);
    assert.match(page, /telecharger=1/);

    const editor = fs.readFileSync('resources/js/Components/Laboratory/LabItemEditor.vue', 'utf8');
    assert.match(editor, /notesPayload\(data\.notes/);
    // ADR-219 — plus de conclusion par analyse : la conclusion générale suffit.
    assert.doesNotMatch(editor, /Conclusion de l’analyse/);
    assert.doesNotMatch(editor, /form\.conclusion/);
});

/** ADR-219 — le nom d'une analyse suit le catalogue : gras seulement si `is_bold`. */
test('un nom d’analyse n’est en gras que si le catalogue le dit', () => {
    assert.equal(designationWeight({ is_bold: true }), 'font-bold');
    assert.equal(designationWeight({ is_bold: false }), 'font-normal');
    assert.equal(designationWeight({}), 'font-normal');

    const editor = fs.readFileSync('resources/js/Components/Laboratory/LabItemEditor.vue', 'utf8');
    assert.doesNotMatch(editor, /node\.is_bold \? 'font-bold' : 'font-(medium|semibold)'/, 'un nom non gras ne doit pas l’être à moitié');
});

test('la tâche à traiter dit son état en mot et en couleur, et l’analyse ses initiales', () => {
    assert.equal(labTaskState('IN_PROGRESS').label, 'En cours');
    assert.equal(labTaskState('PENDING').label, 'À faire');
    assert.equal(labTaskState('INCONNU').label, 'À faire');
    assert.equal(analysisInitials('Hémostase'), 'Hé');
    assert.equal(analysisInitials('Bilan lipidique'), 'BL');
    assert.equal(analysisInitials(''), '?');
});

test('réinitialiser la saisie : confirmée, et seulement s’il y a quelque chose à effacer', () => {
    assert.equal(hasEntries({ nodes: [{ result: null, note: null }] }), false);
    assert.equal(hasEntries({ nodes: [{ result: { value: '1' } }] }), true);
    assert.equal(hasEntries({ nodes: [{ note: 'À contrôler' }] }), true);

    const editor = fs.readFileSync('resources/js/Components/Laboratory/LabItemEditor.vue', 'utf8');
    assert.match(editor, /\/reset`/);
    assert.match(editor, /item\.resettable/);
    assert.match(editor, /Tout effacer/);
});

test('la conclusion partielle se valide, s’annule et se supprime', () => {
    const note = fs.readFileSync('resources/js/Components/Laboratory/LabLineNote.vue', 'utf8');
    for (const label of ['Ajouter une conclusion', 'Annuler', 'Valider', 'Modifier', 'Supprimer']) {
        assert.ok(note.includes(label), `« ${label} » manque`);
    }
    assert.match(note, /emit\('save', ''\)/, 'supprimer envoie une note vide, que le serveur efface');
});

/** ADR-219 — un grand champ numérique qui dit, pendant la saisie, où la valeur se place. */
test('la valeur numérique se lit par rapport à la norme pendant la saisie', async () => {
    const { numericHint, entryFromNode } = await import('../../resources/js/utilities/labWorkbench.js');
    const range = { min: 4, max: 5.2 };
    assert.equal(numericHint(range, ''), null);
    assert.equal(numericHint(range, '4,6').key, 'normal');
    assert.equal(numericHint(range, '6').key, 'high');
    assert.equal(numericHint(range, '3,1').key, 'low');
    assert.equal(numericHint(range, 'abc').key, 'invalid');
    assert.equal(numericHint(range, '9', true).key, 'critical');
    assert.equal(numericHint(null, '4'), null, 'sans norme lisible, rien n’est affirmé');

    assert.equal(entryFromNode({ uuid: 'a', entry_mode: 'NUMERIC', result: { value: '4.6' } }).value, '4,6');
    assert.equal(entryFromNode({ uuid: 'a', entry_mode: 'TEXT', result: { value: '4.6' } }).value, '4.6');

    const field = fs.readFileSync('resources/js/Components/Laboratory/LabResultField.vue', 'utf8');
    assert.doesNotMatch(field, /'w-32 /, 'le champ numérique ne doit plus être étroit');
    assert.match(field, /h-12 w-full/);
});

/** ADR-219 — « Demandes d'examens » dessine le même bouton que la file du laboratoire. */
test('le bouton d’une demande d’analyses est le même dans la file et dans « Demandes d’examens »', async () => {
    const requests = fs.readFileSync('resources/js/Pages/Medicine/Requests.vue', 'utf8');
    const queue = fs.readFileSync('resources/js/Pages/Laboratory/Index.vue', 'utf8');
    for (const page of [requests, queue]) assert.match(page, /labRowButton/);
    assert.match(requests, /request\.bench_action/);
    assert.match(requests, /\/start`/);
});
