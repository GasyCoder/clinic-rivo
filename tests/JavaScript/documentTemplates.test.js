import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
    CUSTOM_FOLDER, MODULE_NAME, buildFolders, expectedContext, filterTemplates, folderChoice, matchesSearch, missingTemplateFields, statusCounts, statusViewKey, templateStatus,
} from '../../resources/js/utilities/documentTemplates.js';

/** ADR-240 — « Modèles de documents » : les règles d'écran de la gestion documentaire du portail. */
const families = [
    { key: 'CONTRAT', label: 'Contrats', context: 'EMPLOYEE_AND_CONTRACT' },
    { key: 'CONGE', label: 'Congés', context: 'EMPLOYEE_AND_LEAVE' },
    { key: 'ATTESTATION', label: 'Attestations', context: 'EMPLOYEE_ONLY' },
];
const templates = [
    { uuid: '1', name: 'CDD', document_type: 'CONTRAT', data_context: 'EMPLOYEE_AND_CONTRACT', active: true, archived: false, generated_documents_count: 4 },
    { uuid: '2', name: 'CDI ancien', document_type: 'CONTRAT', data_context: 'EMPLOYEE_ONLY', active: false, archived: false, generated_documents_count: 1 },
    { uuid: '3', name: 'Congé annuel', document_type: 'Congé', data_context: 'EMPLOYEE_AND_LEAVE', active: true, archived: false, generated_documents_count: 0 },
    { uuid: '4', name: 'Attestation de travail', document_type: 'ATTESTATION', data_context: 'EMPLOYEE_ONLY', active: false, archived: true, generated_documents_count: 2, description: 'Pour la banque' },
    { uuid: '5', name: 'Note interne', document_type: 'Note de service', data_context: 'EMPLOYEE_ONLY', active: true, archived: false, generated_documents_count: 0 },
];

test('the module has one name', () => {
    assert.equal(MODULE_NAME, 'Modèles de documents');
    const menu = fs.readFileSync('resources/js/Components/Layout/Menu.vue', 'utf8');
    assert.match(menu, /text: 'Modèles de documents', link: '\/super-admin\/workspaces\/document-templates'/);
    assert.doesNotMatch(menu, /Canevas de documents/);
});

test('a contract or leave model with the wrong data is flagged, a free one never', () => {
    assert.equal(expectedContext(templates[1]), 'EMPLOYEE_AND_CONTRACT');
    assert.equal(expectedContext(templates[0]), null);
    assert.equal(expectedContext({ document_type: 'congé', data_context: 'EMPLOYEE_ONLY' }), 'EMPLOYEE_AND_LEAVE');
    assert.equal(expectedContext(templates[4]), null);
});

test('status views are exclusive where they should be, and counted per folder', () => {
    assert.deepEqual(statusCounts(templates), { service: 4, actifs: 3, inactifs: 1, 'a-verifier': 1, archives: 1 });
    assert.deepEqual(statusCounts(templates, 'CONTRAT'), { service: 2, actifs: 1, inactifs: 1, 'a-verifier': 1, archives: 0 });
    assert.equal(statusViewKey('n-importe'), 'service');
    assert.equal(templateStatus(templates[3]).label, 'Archivé');
    assert.equal(templateStatus(templates[0]).label, 'Proposé au RH');
});

test('filtering by folder, status and an accent-free search', () => {
    assert.deepEqual(filterTemplates(templates, { folder: 'CONTRAT' }).map((item) => item.uuid), ['1', '2']);
    assert.deepEqual(filterTemplates(templates, { search: 'conge annuel' }).map((item) => item.uuid), ['3']);
    assert.deepEqual(filterTemplates(templates, { view: 'archives', search: 'banque' }).map((item) => item.uuid), ['4']);
    assert.ok(matchesSearch(templates[4], 'NOTE service'));
    assert.ok(! matchesSearch(templates[4], 'contrat'));
});

test('known folders come first, a free type makes its own folder', () => {
    const folders = buildFolders(templates, families);
    assert.deepEqual(folders.map((folder) => folder.key), ['CONTRAT', 'CONGE', 'ATTESTATION', 'NOTE DE SERVICE']);
    assert.equal(folders[0].active, 1);
    assert.equal(folders[0].total, 2);
    assert.equal(folders[0].documents, 5);
    assert.equal(folders[2].total, 0, 'un modèle archivé ne compte pas dans le dossier');
    assert.equal(folders[3].custom, true);
    assert.equal(folders[3].label, 'Note de service');
});

test('the record chooses a known folder or a free type, and says what is missing', () => {
    assert.equal(folderChoice('', families), '');
    assert.equal(folderChoice('Congé', families), 'CONGE');
    assert.equal(folderChoice('Note de service', families), CUSTOM_FOLDER);

    const form = { document_type: '', data_context: 'EMPLOYEE_ONLY', name: ' ' };
    assert.deepEqual(missingTemplateFields(form, { pages: [{ content: '<p></p>' }] }), ['le dossier', 'le nom', 'le texte du modèle']);
    assert.deepEqual(missingTemplateFields({ ...form, document_type: 'CONTRAT', name: 'CDD' }, { pages: [{ content: '<p></p>' }, { content: '<p>Article 1</p>' }] }), []);
    assert.deepEqual(missingTemplateFields({ ...form, document_type: 'CONTRAT', name: 'CDD' }, { pages: [{ content: '<table><tr><td></td></tr></table>' }] }), []);
});

test('the portal pages are shadcn only and ask nothing through browser windows', () => {
    for (const page of ['resources/js/Pages/SuperAdmin/DocumentTemplates/Index.vue', 'resources/js/Pages/SuperAdmin/DocumentTemplates/Editor.vue']) {
        const source = fs.readFileSync(page, 'utf8');
        assert.doesNotMatch(source, /@\/Components\/UI\//, `${page} n'importe plus de composant DashWind`);
        assert.doesNotMatch(source, /\b(window\.)?(confirm|alert)\(/, `${page} n'ouvre aucune fenêtre du navigateur`);
        assert.doesNotMatch(source, /Canevas/, `${page} parle de modèles`);
    }
    const editor = fs.readFileSync('resources/js/Pages/SuperAdmin/DocumentTemplates/Editor.vue', 'utf8');
    for (const step of ['>1</span>Dossier', '>2</span>Données reprises', '>3</span>Identification', '>4</span>Pages']) {
        assert.ok(editor.includes(step), `la fiche suit l'ordre : ${step}`);
    }
});
