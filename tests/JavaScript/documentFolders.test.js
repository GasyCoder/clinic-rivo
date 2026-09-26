import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { familyKey, familyTone, folderDocuments, folderSummary } from '../../resources/js/utilities/documentFamilies.js';

/** ADR-199 — l'écran range un type dans le même dossier que le serveur (DocumentFamily::key). */
test('a document type falls in the same folder as on the server', () => {
    assert.equal(familyKey('Congé'), 'CONGE');
    assert.equal(familyKey('  décision  '), 'DECISION');
    assert.equal(familyKey('note   de service'), 'NOTE DE SERVICE');
    assert.equal(familyKey(''), 'AUTRE');
    assert.equal(familyKey(null), 'AUTRE');
});

test('a known folder has its colour, an unknown one the neutral colour', () => {
    assert.equal(familyTone('CONTRAT'), 'sky');
    assert.equal(familyTone('NOTE DE SERVICE'), 'slate');
});

/** Une tuile fait ~150 px : deux lignes courtes, jamais tronquées. */
test('a folder says what it holds in two short lines', () => {
    assert.equal(folderSummary({ templates: 3 }), '3 canevas');
    assert.equal(folderSummary({ templates: 0 }), 'Aucun canevas');
    assert.equal(folderDocuments({ documents: 0, archived: 0 }), 'Aucun document');
    assert.equal(folderDocuments({ documents: 1, archived: 0 }), '1 document');
    assert.equal(folderDocuments({ documents: 12, archived: 3 }), '12 doc. · 3 arch.');
    for (const line of [folderSummary({ templates: 12 }), folderDocuments({ documents: 128, archived: 14 })]) {
        assert.ok(line.length <= 20, `« ${line} » tient dans une tuile`);
    }
});

test('the portal canevas and the site documents are both shown as folders', () => {
    for (const page of ['resources/js/Pages/SuperAdmin/DocumentTemplates/Index.vue', 'resources/js/Pages/Administration/Documents/Index.vue']) {
        const source = fs.readFileSync(page, 'utf8');
        assert.match(source, /<FolderCard/, `${page} range ses éléments en dossiers`);
        assert.match(source, /familyTone/, `${page} colore ses dossiers comme l'autre écran`);
    }
});

/** Un document ne s'efface jamais : « Supprimer » l'archive avec un motif, « Modifier » en fait une nouvelle version. */
test('a produced document is archived with a reason and modified by a new version', () => {
    const index = fs.readFileSync('resources/js/Pages/Administration/Documents/Index.vue', 'utf8');
    assert.match(index, /create\?from=\$\{document\.uuid\}/);
    assert.match(index, /archiveForm\.delete/);
    assert.match(index, /reason\.trim\(\)\.length < 3/);
    assert.doesNotMatch(index, /forceDelete|force_delete/);
});
