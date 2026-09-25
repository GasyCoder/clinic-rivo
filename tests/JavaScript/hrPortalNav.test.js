import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { CLINIC_WORKSPACES } from '../../resources/js/utilities/clinicWorkspaces.js';
import { HR_HOME_CODE, HR_SECTION_GROUPS, groupHrSections, hrSections } from '../../resources/js/utilities/hrSections.js';

const hrCodes = CLINIC_WORKSPACES.find((workspace) => workspace.key === 'hr').children.map((section) => section.code);

test('chaque rubrique RH, hors accueil, appartient à un thème et un seul', () => {
    const grouped = HR_SECTION_GROUPS.flatMap((group) => group.codes);

    assert.equal(new Set(grouped).size, grouped.length, 'une rubrique dans deux thèmes');
    assert.deepEqual(
        hrCodes.filter((code) => code !== HR_HOME_CODE).sort(),
        [...grouped].sort(),
        'les thèmes et le menu RH du site doivent lister les mêmes rubriques',
    );
    assert.ok(hrCodes.includes(HR_HOME_CODE));
});

test('un thème ne garde que les rubriques permises ; vide, il disparaît', () => {
    const allowed = new Set(['employees.view', 'leave.view']);
    const groups = groupHrSections(hrSections('/super-admin/sites/A/rh', (permission) => allowed.has(permission)));

    assert.deepEqual(groups.map((group) => group.key), ['people', 'time']);
    assert.deepEqual(groups[0].sections.map((section) => section.code), ['hr-employees']);
    assert.deepEqual(groups[1].sections.map((section) => section.code), ['hr-leave']);
    assert.equal(groups[0].sections[0].href, '/super-admin/sites/A/rh/employees');
});

test('une rubrique sans thème rejoint le dernier au lieu de disparaître', () => {
    const groups = groupHrSections([
        { code: HR_HOME_CODE, label: 'Accueil RH', prefixes: [] },
        { code: 'hr-reports', label: 'Rapports', prefixes: [] },
        { code: 'hr-nouvelle', label: 'Nouvelle', prefixes: [] },
    ]);

    assert.deepEqual(groups.at(-1).sections.map((section) => section.code), ['hr-reports', 'hr-nouvelle']);
    assert.ok(! groups.some((group) => group.sections.some((section) => section.code === HR_HOME_CODE)));
});

test('la barre RH du portail ne défile plus : thèmes puis rubriques, qui passent à la ligne', () => {
    const bar = fs.readFileSync('resources/js/Components/Administration/HrPortalBar.vue', 'utf8');
    const home = fs.readFileSync('resources/js/Pages/Administration/Index.vue', 'utf8');

    assert.doesNotMatch(bar, /overflow-x-auto/);
    assert.match(bar, /groupHrSections\(sections\.value\)/);
    assert.match(bar, /aria-current="isActive\(section\) \? 'page' : undefined"/);
    assert.match(home, /const GROUPS = HR_SECTION_GROUPS;/, 'l’accueil RH et la barre partagent les mêmes thèmes');
});
