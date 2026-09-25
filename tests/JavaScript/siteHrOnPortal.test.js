import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { HR_SITE_BASE, mapHrPath } from '../../resources/js/utilities/hrPath.js';

/**
 * ADR-187 — les écrans RH du site sont aussi ceux du portail. Ils écrivent
 * leurs adresses telles qu'elles sont sur le site ; `hrUrl` les ramène à la
 * base où l'écran est ouvert.
 */
const HR_DIRS = ['Employees', 'Contracts', 'Attendance', 'Leave', 'Planning', 'Reports', 'Settings', 'Documents', 'StaffBlockCredits'];
const hrPages = () => [
    'resources/js/Pages/Administration/Index.vue',
    ...HR_DIRS.flatMap((dir) => fs.readdirSync(`resources/js/Pages/Administration/${dir}`)
        .filter((file) => file.endsWith('.vue'))
        .map((file) => path.join('resources/js/Pages/Administration', dir, file))),
];

test('a site path is brought to the base where the screen is open', () => {
    assert.equal(mapHrPath('/administration/employees/e-1', '/super-admin/sites/A/rh'), '/super-admin/sites/A/rh/employees/e-1');
    assert.equal(mapHrPath('/administration', '/super-admin/sites/A/rh'), '/super-admin/sites/A/rh');
    assert.equal(mapHrPath('/administration/leave?status=PENDING', '/super-admin/sites/A/rh'), '/super-admin/sites/A/rh/leave?status=PENDING');
    assert.equal(mapHrPath('/administration/employees', HR_SITE_BASE), '/administration/employees', 'sur le site, rien ne change');
    assert.equal(mapHrPath('/administration-generale', '/super-admin/sites/A/rh'), '/administration-generale', 'un préfixe n’est pas une rubrique');
    assert.equal(mapHrPath('/patients/p-1', '/super-admin/sites/A/rh'), '/patients/p-1');
});

test('no HR screen writes a site path the portal could not follow', () => {
    for (const file of hrPages()) {
        const source = fs.readFileSync(file, 'utf8');
        const bare = source.match(/(?<!hrUrl\()['"`]\/administration[^'"`]*['"`]/g) ?? [];

        assert.deepEqual(bare, [], `${file} écrit une adresse RH sans hrUrl()`);

        if (source.includes("hrUrl(")) {
            assert.match(source, /import \{[^}]*\bhrUrl\b[^}]*\} from '@\/utilities\/hrUrl'/, `${file} utilise hrUrl sans l’importer`);
        }
    }
});

test('printed HR pages name the site whose staff they describe', () => {
    for (const file of hrPages().filter((file) => file.endsWith('Print.vue'))) {
        assert.doesNotMatch(fs.readFileSync(file, 'utf8'), /props\.site\?\.name/, `${file} affiche le nom du portail au lieu de celui du site`);
    }
});

test('the portal shows the HR navigation of the site, from the one list of HR sections', () => {
    const layout = fs.readFileSync('resources/js/Layouts/AppLayout.vue', 'utf8');
    const bar = fs.readFileSync('resources/js/Components/Administration/HrPortalBar.vue', 'utf8');

    assert.match(layout, /<HrPortalBar v-if="page\.props\.hrContext" \/>/);
    const sections = fs.readFileSync('resources/js/utilities/hrSections.js', 'utf8');

    assert.match(bar, /hrSections\(base\.value, can\)/);
    assert.match(sections, /CLINIC_WORKSPACES\.find\(\(workspace\) => workspace\.key === 'hr'\)/, 'mêmes rubriques que le menu RH du site');
    assert.match(sections, /can\(section\.permission\)/, 'chaque rubrique garde son droit');
    assert.match(bar, /print:hidden/);
});

test('the HR figures open the lists of the chosen site on the portal', () => {
    const figures = fs.readFileSync('resources/js/Components/Administration/HrFigures.vue', 'utf8');
    const portal = fs.readFileSync('resources/js/Pages/SuperAdmin/HumanResources/Index.vue', 'utf8');

    assert.match(figures, /mapHrPath\(href, props\.base\)/);
    assert.match(portal, /<HrFigures :summary="summaryOf\(selectedSite\)" linkable :base="hrBase\(selectedSite\.site\.code\)" \/>/);
    assert.match(portal, /:href="figureUrl\(column, site\.site\.code\)"/, 'le comparatif ouvre la liste sur le bon site');
    assert.doesNotMatch(portal, /en lecture seule/);
});

test('the portal HR page keeps the chosen site in the address, without calling the sites again', () => {
    const portal = fs.readFileSync('resources/js/Pages/SuperAdmin/HumanResources/Index.vue', 'utf8');

    assert.match(portal, /router\.replace\(\{/);
    assert.match(portal, /`\$\{PAGE_URL\}\?site=\$\{code\}`/);
    assert.match(portal, /new URLSearchParams\(page\.url\.split\('\?'\)\[1\] \?\? ''\)\.get\('site'\)/, 'lu depuis l’adresse, donc aussi au rendu serveur');
});

test('the HR figures and sections are written once', () => {
    const figures = fs.readFileSync('resources/js/Components/Administration/HrFigures.vue', 'utf8');

    assert.match(figures, /from '@\/utilities\/hrFigures'/);
    assert.doesNotMatch(figures, /Congés à décider/, 'les libellés vivent dans hrFigures.js');
});

/** Demande du propriétaire : des compteurs plus compacts et plus lisibles. */
test('the HR figures are one compact block in two groups, each label whole', async () => {
    const figures = fs.readFileSync('resources/js/Components/Administration/HrFigures.vue', 'utf8');
    const { HR_FIGURES } = await import('../../resources/js/utilities/hrFigures.js');

    assert.match(figures, /label: 'À traiter'/);
    assert.match(figures, /label: 'Effectif du jour'/);
    assert.match(figures, /lg:grid-cols-\[3fr_4fr\]/, 'les deux groupes côte à côte, pas deux rangées inégales');
    assert.match(figures, /:title="item\.label"/, 'la phrase complète reste au survol');
    assert.doesNotMatch(figures, /truncate text-xs/, 'un libellé de tuile ne se tronque pas');

    for (const figure of HR_FIGURES) {
        assert.ok(figure.tile && figure.tile.length <= 24, `${figure.key} : libellé de tuile court et présent`);
    }
});

/** Demande du propriétaire : « Que voulez-vous faire ? » en shadcn, cartes avec bordure et ombre. */
test('the HR home lists its sections as shadcn cards, from the one list of HR sections', () => {
    const home = fs.readFileSync('resources/js/Pages/Administration/Index.vue', 'utf8');

    assert.doesNotMatch(home, /Components\/UI\/(Icon|Button)\.vue|class="[^"]*\bni ni-|slate-800|gray-200/, 'plus de DashWind ni de couleurs codées en dur');
    assert.match(home, /hrSections\(hrContext\(\)\?\.base \?\? HR_SITE_BASE, can\)/, 'mêmes rubriques, adresses et droits que le menu RH');
    // Les thèmes sont partagés avec la barre RH du portail (hrSections.js).
    const sections = fs.readFileSync('resources/js/utilities/hrSections.js', 'utf8');
    assert.match(home, /const GROUPS = HR_SECTION_GROUPS;/);
    for (const group of ['Personnel', 'Temps de travail', 'Pilotage']) {
        assert.match(sections, new RegExp(`label: '${group}'`));
    }
    assert.match(home, /rounded-xl border bg-card p-3\.5 shadow-sm/);
    assert.match(home, /'border-border hover:-translate-y-0\.5 hover:border-primary\/40 hover:shadow-md/);
    assert.match(home, /v-if="area\.count > 0"/, 'une pastille seulement quand une décision attend');
});

test('the employee list offers the Excel template and is written in shadcn', () => {
    const source = fs.readFileSync('resources/js/Pages/Administration/Employees/Index.vue', 'utf8');

    // Le modèle à remplir, à côté de l'import qu'il prépare, et sous le même droit.
    assert.match(source, /hrUrl\('\/administration\/employees\/import-template'\)/, 'le bouton « Modèle Excel » a disparu');
    assert.match(source, /can\('employees\.import'\) && \{ key: 'template'/, 'le modèle doit suivre le droit d’import');

    // Les compteurs sont des filtres : un état « pressé » lisible au clavier.
    assert.match(source, /aria-pressed="statusFilter === card\.value"/);

    // Aucun reliquat DashWind (ADR-099).
    assert.doesNotMatch(source, /Components\/UI\/(Icon|Button|Avatar)\.vue/);
    assert.doesNotMatch(source, /\bni ni-|\bnk-|\b(?:bg|text|border)-(?:gray|slate)-\d/);
});

test('the employee form and its pages are written in shadcn and keep their HR addresses', () => {
    const form = fs.readFileSync('resources/js/Pages/Administration/Employees/EmployeeForm.vue', 'utf8');
    const create = fs.readFileSync('resources/js/Pages/Administration/Employees/Create.vue', 'utf8');
    const edit = fs.readFileSync('resources/js/Pages/Administration/Employees/Edit.vue', 'utf8');

    assert.match(create, /form\.post\(hrUrl\('\/administration\/employees'\)\)/);
    assert.match(edit, /form\.put\(hrUrl\(`\/administration\/employees\/\$\{props\.employee\.uuid\}`\)\)/);

    for (const source of [form, create, edit]) {
        assert.doesNotMatch(source, /Components\/UI\/(Icon|Button|Input|CheckBox|Avatar)\.vue/);
        assert.doesNotMatch(source, /\bni ni-|\bnk-|\b(?:bg|text|border)-(?:gray|slate)-\d|<select\b|<textarea\b/);
    }

    // Un référentiel archivé reste lisible, jamais choisissable.
    assert.match(form, /disabled: !item\.available/);
    assert.match(fs.readFileSync('resources/js/Components/Shadcn/Select.vue', 'utf8'), /:disabled="Boolean\(option\.disabled\)"/);
    // Le résumé des erreurs mène au champ, à son étape.
    assert.match(form, /@select="focusField"/);
});

test('Départements et Fonctions sont deux modules RH, servis aussi au portail (ADR-188)', () => {
    const gateway = fs.readFileSync('app/Services/SuperAdmin/SiteHrGateway.php', 'utf8');
    const menu = fs.readFileSync('resources/js/utilities/clinicWorkspaces.js', 'utf8');
    const page = fs.readFileSync('resources/js/Pages/Administration/HrStructure/Index.vue', 'utf8');

    assert.match(gateway, /'Administration\/HrStructure\/'/, 'le portail accepte l’écran');
    assert.match(menu, /code: 'hr-departments'.*permission: 'hr_settings\.view'/);
    assert.match(menu, /code: 'hr-job-titles'.*permission: 'hr_settings\.view'/);
    assert.doesNotMatch(page, /Components\/UI\/(Icon|Button)\.vue|\bni ni-|\b(?:bg|text|border)-(?:gray|slate)-\d/);
});
