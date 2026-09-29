import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/**
 * Paillasse — un seul bouton principal par analyse (« Terminer l'analyse » ; l'envoi
 * au médecin est en haut de la page, pour une, plusieurs ou toutes les analyses),
 * les gestes secondaires dans « Autres actions », chacun avec ce qu'il fait ou
 * pourquoi il est indisponible ; la conclusion générale s'enregistre d'elle-même.
 */
const editor = fs.readFileSync('resources/js/Components/Laboratory/LabItemEditor.vue', 'utf8');
const page = fs.readFileSync('resources/js/Pages/Laboratory/Show.vue', 'utf8');

test('les gestes secondaires d’une analyse sont dans un seul menu, jamais masqués', () => {
    assert.match(editor, /<DropdownMenu :items="moreActions"/);
    for (const key of ["key: 'return'", "key: 'send-out'", "key: 'reset'"]) {
        assert.ok(editor.includes(key), `${key} manque au menu`);
    }
    // Chaque entrée dit pourquoi elle est indisponible, au lieu de disparaître.
    for (const reason of ['returnReason', 'sendOutReason', 'resetReason']) {
        assert.match(editor, new RegExp(`disabled: ${reason}\\.value !== null`));
    }
    // Plus de bouton « Enregistrer » ni de réinitialisation dans l'en-tête : l'enregistrement est automatique.
    assert.doesNotMatch(editor, /> Enregistrer\s*</);
    assert.doesNotMatch(editor, /@click="resetOpen = true"/);
});

test('« Renvoyer à refaire » dit quand il devient possible ; une analyse terminée se rouvre', () => {
    assert.match(editor, /Possible une fois l’analyse envoyée au médecin/);
    assert.match(editor, /laboratory_results\.return/);
    assert.ok(editor.includes("key: 'reopen'"), 'Rouvrir la saisie manque au menu');
});

test('le pied termine l’analyse ; l’envoi est en haut, toutes les terminées par défaut', () => {
    assert.match(editor, /Terminer l’analyse/);
    assert.match(editor, /\/complete`/);
    assert.doesNotMatch(editor, /Envoyer au médecin\s*<\/Button>/);
    assert.match(page, /@completed="selectNextToWork"/);
});

test('la conclusion générale s’enregistre d’elle-même, sans bouton à part', () => {
    assert.doesNotMatch(page, /Enregistrer la conclusion/);
    assert.match(page, /useAutosave\(conclusionForm/);
    // Envoyer au médecin fait d'abord partir la conclusion en cours.
    assert.match(page, /conclusionAutosave\.flush\(/);
});
