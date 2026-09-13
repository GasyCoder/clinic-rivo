import test from 'node:test';
import assert from 'node:assert/strict';
import {
    isPermissionEffectivelyGranted,
    isSensitivePermission,
    permissionMatchesFilter,
    permissionMatchesSearch,
    summarizePermissionWorkspace,
} from '../../resources/js/utilities/permissionWorkspace.js';

const catalog = [
    { id: 1, name: 'patients.view', label: 'Voir les patients', module: 'patients' },
    { id: 2, name: 'patients.update', label: 'Modifier un patient', module: 'patients' },
    { id: 3, name: 'patients.force_delete', label: 'Supprimer définitivement', module: 'patients' },
    { id: 4, name: 'users.assign', label: 'Attribuer les utilisateurs', module: 'users' },
];

test('une interdiction utilisateur reste prioritaire sur le rôle', () => {
    const role = new Set(['patients.view', 'patients.update']);
    assert.equal(isPermissionEffectivelyGranted(catalog[0], { 1: 'deny' }, role), false);
});

test('une autorisation utilisateur complète le rôle', () => {
    assert.equal(isPermissionEffectivelyGranted(catalog[1], { 2: 'allow' }, new Set()), true);
});

test('la recherche couvre le libellé, le code et la catégorie', () => {
    assert.equal(permissionMatchesSearch(catalog[1], 'modifier', 'Patients'), true);
    assert.equal(permissionMatchesSearch(catalog[1], 'patients.update', 'Patients'), true);
    assert.equal(permissionMatchesSearch(catalog[1], 'PATIENTS', 'Patients'), true);
    assert.equal(permissionMatchesSearch(catalog[1], 'pharmacie', 'Patients'), false);
});

test('les filtres distinguent héritage, exception et effet final', () => {
    const effects = { 1: '', 2: 'allow', 3: 'deny' };
    const role = new Set(['patients.view']);
    assert.equal(permissionMatchesFilter(catalog[0], 'inherited', effects, role), true);
    assert.equal(permissionMatchesFilter(catalog[1], 'exceptions', effects, role), true);
    assert.equal(permissionMatchesFilter(catalog[1], 'allowed', effects, role), true);
    assert.equal(permissionMatchesFilter(catalog[2], 'denied', effects, role), true);
});

test('les opérations critiques sont marquées sensibles', () => {
    assert.equal(isSensitivePermission(catalog[2]), true);
    assert.equal(isSensitivePermission(catalog[3]), true);
    assert.equal(isSensitivePermission(catalog[0]), false);
});

test('le résumé sépare héritage, permissions effectives et décisions manuelles', () => {
    const summary = summarizePermissionWorkspace(
        catalog,
        { 1: '', 2: 'allow', 3: 'deny', 4: 'allow' },
        { 2: { source: 'MANUAL' }, 3: { source: 'MANUAL' }, 4: { source: 'PROFILE' } },
        new Set(['patients.view']),
    );

    assert.deepEqual(summary, {
        total: 4,
        effectiveAllowed: 3,
        effectiveDenied: 1,
        exceptions: 3,
        inherited: 1,
        manualAllowed: 1,
        manualDenied: 1,
    });
});

import {
    PERMISSION_CATEGORIES,
    PERMISSION_DOMAINS,
    permissionCategoryDomain,
    permissionCategoryLabel,
} from '../../resources/js/utilities/permissionCategories.js';

test('every known category shows a real name, never its code', () => {
    const domains = new Set(PERMISSION_DOMAINS.map((domain) => domain.key));

    for (const [key, category] of Object.entries(PERMISSION_CATEGORIES)) {
        assert.notEqual(category.label, key, `${key} is shown as its code`);
        assert.doesNotMatch(category.label, /_/, `${key} label looks like a code`);
        assert.ok(domains.has(category.domain), `${key} has an unknown domain`);
    }

    assert.equal(permissionCategoryLabel('analysis_catalog'), 'Catalogue des analyses');
    assert.equal(permissionCategoryLabel('cash_registers'), 'Postes de caisse');
});

test('an unlabelled category stays readable and lands in a domain', () => {
    assert.equal(permissionCategoryLabel('future_module'), 'Future module');
    assert.equal(permissionCategoryDomain('future_module'), 'admin');
});
