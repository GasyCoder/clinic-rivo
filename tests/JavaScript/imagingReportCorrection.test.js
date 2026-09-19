import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/**
 * ADR-130 — corriger un compte rendu déjà enregistré, sans jamais perdre la
 * version remplacée.
 */
const dialog = fs.readFileSync('resources/js/Components/Clinical/ImagingReportDialog.vue', 'utf8');
const requests = fs.readFileSync('resources/js/Pages/Medicine/Requests.vue', 'utf8');

test('la même fenêtre saisit et corrige : PUT en correction, POST à la première saisie', () => {
    assert.match(dialog, /form\.put\(url, options\)/);
    assert.match(dialog, /form\.post\(url, options\)/);
    assert.match(dialog, /mode: \{ type: String, default: 'record' \}/);
});

test('en correction, la fenêtre part du compte rendu enregistré, pas d’une feuille vide', () => {
    assert.match(dialog, /result_value: props\.item\.report_raw/);
    assert.match(dialog, /result_notes: props\.item\.notes_raw/);
});

test('rien à enregistrer tant que rien n’a changé', () => {
    assert.match(dialog, /:disabled="form\.processing \|\| unchanged"/);
});

test('le motif de la correction est facultatif et l’ancienne version annoncée conservée', () => {
    assert.match(dialog, /Motif de la correction/);
    assert.match(dialog, /L’ancienne version n’est pas perdue/);
});

test('« Demandes d’examens » propose Modifier par examen et lit les versions remplacées', () => {
    assert.match(requests, /v-if="item\.can_correct"/);
    assert.match(requests, /openReport\(request, item, 'correct'\)/);
    assert.match(requests, /item\.revisions\.length/);
    // La fenêtre de lecture se relit dans les props : après une correction, elle montre la nouvelle version.
    assert.match(requests, /const viewing = computed\(\(\) => props\.requests\.find/);
});

test('« Corrigé » se voit dans la liste, avec qui et quand au survol', () => {
    assert.match(requests, /v-if="item\.corrected_at"/);
    assert.match(requests, /Corrigé le \$\{formatDateTime\(item\.corrected_at\)\}/);
});

test('une première saisie renvoie vers « Rendues récemment » ; une correction reste où elle est', () => {
    assert.match(requests, /const wasFirstEntry = reporting\.value\?\.mode === 'record'/);
});

// Le `watch` immédiat lit `pendingTemplate` : déclaré après lui, il plantait la
// page entière (« Cannot access before initialization ») — vu en navigateur, pas
// par les tests de contenu.
test('pendingTemplate est déclaré avant le watch immédiat qui le lit', () => {
    assert.ok(
        dialog.indexOf('const pendingTemplate = ref(null)') < dialog.indexOf('{ immediate: true }'),
        'un watch immédiat s\'exécute dès setup : ce qu\'il lit doit déjà exister',
    );
});

// Le `watch` immédiat remet aussi l'aperçu à zéro : ce qu'il lit doit exister
// avant lui, sinon la page entière plante (« Cannot access before
// initialization ») — vu en navigateur, pas par les tests de contenu.
test('l’état de l’aperçu est déclaré avant le watch immédiat qui le remet à zéro', () => {
    const watcher = dialog.indexOf('{ immediate: true }');

    for (const state of ['const previewOpen = ref(false)', 'const previewError = ref(\'\')', 'const sheetKey = ref(null)', 'const notesOpen = ref(false)', 'const defaultKey = ref(null)']) {
        assert.ok(dialog.indexOf(state) !== -1 && dialog.indexOf(state) < watcher, `${state} doit précéder le watch immédiat`);
    }
});
