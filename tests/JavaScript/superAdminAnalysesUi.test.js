import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { buildAnalysisTree, flattenAnalysisTree } from '../../resources/js/utilities/analysisHierarchy.js';

const source = fs.readFileSync('resources/js/Pages/Analyses/Index.vue', 'utf8');
const formSource = fs.readFileSync('resources/js/Components/Analyses/AnalysisForm.vue', 'utf8');
const editSource = fs.readFileSync('resources/js/Pages/Analyses/Edit.vue', 'utf8');

test('the super admin analysis catalogue uses the shared shadcn workspace', () => {
    for (const component of ['Badge', 'Button', 'Card', 'Dialog', 'DropdownMenu', 'FormField', 'IconInput', 'Input', 'Select', 'Tabs']) {
        assert.match(source, new RegExp(`Components/Shadcn/${component}\\.vue`), `${component} doit venir de la couche Shadcn`);
    }

    assert.match(source, /<SettingsSiteSwitcher/);
    assert.doesNotMatch(source, /Components\/UI\/(?:Button|Card|Icon|IconInput|Input)\.vue/);
    assert.doesNotMatch(source, /\bni ni-|\bnk-|\b(?:bg|text|border)-(?:gray|slate)-\d/);
});

test('the catalogue remains useful on desktop and mobile', () => {
    assert.match(source, /class="space-y-2\.5 p-3 lg:hidden"/);
    assert.match(source, /class="hidden overflow-x-auto lg:block"/);
    assert.match(source, /<caption class="sr-only">Catalogue des analyses/);
    assert.match(source, /reference_child_male/);
    assert.match(source, /reference_child_female/);
    assert.match(source, /aria-label="Rechercher dans le catalogue"/);
    assert.match(source, /aria-label="Filtrer par prestation"/);
});

test('sub-analyses stay wrapped in a collapsible main analysis', () => {
    assert.match(source, /const analysisTree = computed\(\(\) => buildAnalysisTree\(analyses\.value\)\)/);
    assert.match(source, /const expandedGroups = ref\(new Set\(\)\)/);
    assert.match(source, /:aria-expanded="isExpanded\(root\.uuid\)"/);
    assert.match(source, /descendantsOf\(root\)/);
    assert.match(source, /Tout réduire/);
    assert.match(source, /Tout développer/);
    assert.match(source, /visibleRootAnalyses/, 'la pagination porte sur les analyses principales, jamais au milieu de leurs enfants');
});

test('list and grid modes are shadcn, responsive and remembered after hydration', () => {
    assert.match(source, /const viewMode = ref\('list'\)/, 'la liste est le rendu SSR stable par défaut');
    assert.match(source, /onMounted\(\(\) => \{/);
    assert.match(source, /localStorage\.getItem\(viewStorageKey\)/);
    assert.match(source, /localStorage\.setItem\(viewStorageKey, viewMode\.value\)/);
    assert.match(source, /<Tabs :model-value="viewMode" @update:model-value="setViewMode">/);
    assert.match(source, /<TabsTrigger value="list"><List/);
    assert.match(source, /<TabsTrigger value="grid"><LayoutGrid/);
    assert.match(source, /viewMode === 'grid'/);
    assert.match(source, /sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4/);
    assert.match(source, /viewMode === 'list'/);
});

test('an existing analysis saves itself instead of a « Mettre à jour » button', () => {
    assert.match(editSource, /useAutosave\(/);
    assert.doesNotMatch(editSource, /submit-label=/);
    assert.doesNotMatch(formSource, /submitForm|Mettre à jour/);
});

test('the hierarchy builder keeps children and grandchildren inside their main analysis', () => {
    const service = (uuid) => ({ uuid });
    const row = (uuid, designation, displayOrder, catalog, parent = null) => ({
        uuid,
        designation,
        display_order: displayOrder,
        catalog_item: service(catalog),
        parent: parent ? { uuid: parent } : null,
    });
    const tree = buildAnalysisTree([
        row('child-b', 'Plaquettes', 20, 'nfs', 'root'),
        row('grandchild', 'Volume plaquettaire', 1, 'nfs', 'child-b'),
        row('other', 'Glycémie', 1, 'gly'),
        row('root', 'Numération formule sanguine', 1, 'nfs'),
        row('child-a', 'Hémoglobine', 10, 'nfs', 'root'),
    ]);

    assert.deepEqual(tree.map((item) => item.uuid), ['root', 'other']);
    assert.deepEqual(tree[0].children.map((item) => item.uuid), ['child-a', 'child-b']);
    assert.deepEqual(tree[0].children[1].children.map((item) => item.uuid), ['grandchild']);
    assert.deepEqual(flattenAnalysisTree(tree[0].children).map((item) => item.uuid), ['child-a', 'child-b', 'grandchild']);
    assert.equal(tree[0].children[1].children[0].tree_depth, 2);
});

test('remote site, permissions, tariffs and immutable API boundaries are preserved', () => {
    assert.match(source, /:targets="sites"/);
    assert.match(source, /can\('analysis_catalog\.create'\)/);
    assert.match(source, /can\('analysis_catalog\.export'\)/);
    assert.match(source, /can\('analysis_catalog\.import'\)/);
    assert.match(source, /analysis_catalog\.deactivate/);
    assert.match(source, /analysis_catalog\.activate/);
    assert.match(source, /tariffsUrl\(selectedSiteCode\.value, code\)/);
    assert.match(source, /ses données et mutations passent par les API des/);
});

test('Excel actions are grouped and import stays transactional in a dialog', () => {
    assert.match(source, /label="Fichiers Excel"/);
    assert.match(source, /title="Importer le catalogue des analyses"/);
    assert.match(source, /Une ligne invalide annule tout l’import/);
    assert.match(source, /forceFormData: true/);
    assert.match(source, /urls\.value\.template/);
});

test('one screen for site and portal: the context picks the addresses, never a second page', async () => {
    const { analysisCatalogUrls, isPortalContext } = await import('../../resources/js/utilities/analysisCatalogUrls.js');
    const portal = analysisCatalogUrls({ mode: 'portal' }, 'A');
    const site = analysisCatalogUrls({ mode: 'site' }, 'A');

    assert.equal(isPortalContext(undefined), true);
    assert.equal(portal.edit('u1'), '/super-admin/analyses/A/u1/edit');
    assert.equal(portal.toggle('u1', true), '/super-admin/analyses/A/u1/deactivate');
    assert.equal(portal.export('ALL'), '/super-admin/analyses/export?site_code=A&status=ALL');
    assert.equal(site.edit('u1'), '/administration/analyses/u1/edit');
    assert.equal(site.update('u1'), '/administration/analyses/u1');
    assert.equal(site.toggle('u1', false), '/administration/analyses/u1/activate');
    assert.equal(site.create, '/administration/analyses/create');
    assert.equal(site.export('ACTIVE'), '/administration/analyses/export?status=ACTIVE');

    assert.equal(fs.existsSync('resources/js/Pages/Administration/Analyses'), false, 'plus de seconde page du catalogue sur le site');
    assert.equal(fs.existsSync('resources/js/Pages/SuperAdmin/Analyses'), false, 'plus de seconde page du catalogue au portail');
    assert.match(source, /v-if="portal && sites\.length"/, 'le choix du site n’existe qu’au portail');
    assert.doesNotMatch(source, /['"`]\/super-admin\/analyses/, 'aucune adresse du portail écrite en dur dans l’écran partagé');
});
