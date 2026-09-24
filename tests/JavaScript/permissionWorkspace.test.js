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

import fs from 'node:fs';
import {
    PERMISSION_CATEGORIES,
    PERMISSION_MODULES,
    PERMISSION_RESOURCES,
    PERMISSION_RESOURCE_ICONS,
    permissionCategoryIcon,
    permissionCategoryLabel,
    permissionCategoryModule,
    permissionResourceIcon,
    permissionResourceLabel,
} from '../../resources/js/utilities/permissionCategories.js';
import {
    PERMISSION_ACTION_COLUMNS,
    buildPermissionModules,
    matchesSearchTerms,
    permissionAction,
    permissionActionLabel,
    permissionSearchIndex,
} from '../../resources/js/utilities/permissionWorkspace.js';

test('every known category shows a real name, never its code', () => {
    const modules = new Set(PERMISSION_MODULES.map((module) => module.key));

    for (const [key, category] of Object.entries(PERMISSION_CATEGORIES)) {
        assert.notEqual(category.label, key, `${key} is shown as its code`);
        assert.doesNotMatch(category.label, /_/, `${key} label looks like a code`);
        assert.ok(modules.has(category.module), `${key} has an unknown module`);
    }

    assert.equal(permissionCategoryLabel('analysis_catalog'), 'Catalogue des analyses');
    assert.equal(permissionCategoryLabel('cash_registers'), 'Postes de caisse');
});

test('an unlabelled category stays readable and lands in a module', () => {
    assert.equal(permissionCategoryLabel('future_module'), 'Future module');
    assert.equal(permissionCategoryModule('future_module'), 'system');
});

/**
 * ADR-178 — onze catégories de la Pharmacie et de la Médecine s'affichaient
 * « Purchase orders », « Death records » et tombaient dans « Administration ».
 * Le seeder fait foi : chaque catégorie qu'il déclare a un vrai nom et un module.
 */
test('every category and sub-resource declared by the seeder has a French name', () => {
    const seeder = fs.readFileSync('database/seeders/PermissionSeeder.php', 'utf8');
    const names = [...seeder.matchAll(/^\s*'([a-z_]+(?:\.[a-z_]+)+)'\s*=>\s*'/gm)].map((match) => match[1]);

    assert.ok(names.length > 300, 'the seeder catalogue was not read');

    for (const name of names) {
        const category = name.split('.')[0];

        assert.ok(PERMISSION_CATEGORIES[category], `category ${category} (${name}) has no label`);

        const parts = name.split('.');
        if (parts.length === 3) {
            const resource = parts.slice(0, 2).join('.');
            assert.ok(PERMISSION_RESOURCES[resource], `sub-resource ${resource} (${name}) has no label`);
        }
    }

    assert.equal(permissionCategoryModule('purchase_orders'), 'pharmacy');
    assert.equal(permissionCategoryModule('death_records'), 'medicine');
    assert.equal(permissionResourceLabel('surgery.report'), 'Compte rendu opératoire');
    assert.equal(permissionResourceLabel('patients'), 'Dossiers patients');
});

/**
 * ADR-178 — chaque fonctionnalité d'un module porte son icône : Dossier
 * médical, Diagnostics, Ordonnances se reconnaissent avant d'être lus. Le
 * seeder fait foi : aucune catégorie ni sous-ressource qu'il déclare ne se
 * contente de l'icône de repli de son module.
 */
test('every category and sub-resource declared by the seeder has its own icon', () => {
    const seeder = fs.readFileSync('database/seeders/PermissionSeeder.php', 'utf8');
    const names = [...seeder.matchAll(/^\s*'([a-z_]+(?:\.[a-z_]+)+)'\s*=>\s*'/gm)].map((match) => match[1]);

    assert.ok(names.length > 300, 'the seeder catalogue was not read');

    for (const name of names) {
        const parts = name.split('.');

        assert.ok(PERMISSION_CATEGORIES[parts[0]]?.icon, `category ${parts[0]} (${name}) has no icon`);

        if (parts.length === 3) {
            const resource = parts.slice(0, 2).join('.');
            assert.ok(PERMISSION_RESOURCE_ICONS[resource], `sub-resource ${resource} (${name}) has no icon`);
        }
    }

    // Deux fonctionnalités voisines ne se ressemblent pas.
    assert.notEqual(permissionCategoryIcon('medical_record'), permissionCategoryIcon('diagnoses'));
    assert.notEqual(permissionCategoryIcon('diagnoses'), permissionCategoryIcon('prescriptions'));
});

test('an unknown feature still gets an icon: its category, then its module', () => {
    const system = PERMISSION_MODULES.find((module) => module.key === 'system').icon;

    assert.equal(permissionCategoryIcon('future_module'), system);
    assert.equal(permissionResourceIcon('surgery.future_step'), permissionCategoryIcon('surgery'));
    assert.equal(permissionResourceIcon('surgery.report'), PERMISSION_RESOURCE_ICONS['surgery.report']);
    assert.ok(permissionResourceIcon('future_module.thing'));
});

const matrixCatalog = [
    { id: 1, name: 'patients.view', label: 'Voir les patients', module: 'patients' },
    { id: 2, name: 'patients.create', label: 'Créer un patient', module: 'patients' },
    { id: 3, name: 'patients.delete', label: 'Supprimer un patient', module: 'patients' },
    { id: 4, name: 'patients.force_delete', label: 'Supprimer définitivement un patient', module: 'patients' },
    { id: 5, name: 'patients.medical_history.view', label: 'Voir les antécédents', module: 'patients' },
    { id: 6, name: 'catalog.tariffs.archive', label: 'Suspendre un tarif', module: 'catalog' },
    { id: 7, name: 'pharmacy.dispense', label: 'Délivrer les médicaments', module: 'pharmacy' },
    { id: 8, name: 'pharmacy.dispense.print', label: 'Imprimer le ticket', module: 'pharmacy' },
    { id: 9, name: 'address_entries.delete', label: 'Archiver une adresse', module: 'address_entries' },
];

/**
 * « Voir | Créer | Modifier | Supprimer… » : les colonnes sont les mêmes pour
 * toutes les fonctionnalités, et ce qui n'y entre pas garde son libellé.
 */
test('the matrix places each permission in its column, the rest beside it', () => {
    assert.deepEqual(PERMISSION_ACTION_COLUMNS.map((column) => column.label), [
        'Voir', 'Créer', 'Modifier', 'Supprimer', 'Restaurer', 'Valider', 'Exporter',
    ]);

    const modules = buildPermissionModules(matrixCatalog);
    const reception = modules.find((module) => module.key === 'reception');
    const patients = reception.rows.find((row) => row.key === 'patients');

    assert.equal(patients.cells.view.name, 'patients.view');
    assert.equal(patients.cells.create.name, 'patients.create');
    assert.equal(patients.cells.delete.name, 'patients.delete');
    assert.deepEqual(patients.others.map((permission) => permission.name), ['patients.force_delete']);

    // Une sous-ressource est sa propre ligne, sous sa catégorie.
    const history = reception.rows.find((row) => row.key === 'patients.medical_history');
    assert.equal(history.nested, true);
    assert.equal(history.label, 'Antécédents et allergies');
    assert.equal(reception.groups.find((group) => group.category === 'patients').children[0], history);

    // Chaque ligne et chaque groupe portent leur icône (ADR-178).
    assert.equal(patients.icon, permissionCategoryIcon('patients'));
    assert.equal(history.icon, PERMISSION_RESOURCE_ICONS['patients.medical_history']);
    assert.equal(reception.groups.find((group) => group.category === 'patients').icon, permissionCategoryIcon('patients'));

    // Archiver se lit dans la colonne « Supprimer ».
    const tariffs = modules.find((module) => module.key === 'tariffs').rows[0];
    assert.equal(tariffs.cells.delete.name, 'catalog.tariffs.archive');

    // « Délivrer » rejoint la ligne de la délivrance, dont il est l'acte principal.
    const dispense = modules.find((module) => module.key === 'pharmacy').rows.find((row) => row.key === 'pharmacy.dispense');
    assert.deepEqual(dispense.permissions.map((permission) => permission.name).sort(), ['pharmacy.dispense', 'pharmacy.dispense.print']);

    // Rien ne se perd : chaque permission est rangée une fois, et une seule.
    const placed = modules.flatMap((module) => module.permissions.map((permission) => permission.id));
    assert.deepEqual([...placed].sort((a, b) => a - b), matrixCatalog.map((permission) => permission.id));
});

test('modules follow the reading order, never the alphabet', () => {
    const keys = buildPermissionModules(matrixCatalog).map((module) => module.key);

    assert.deepEqual(keys, ['reception', 'pharmacy', 'tariffs']);
});

test('an action is named by its column verb, or by its own label', () => {
    assert.equal(permissionAction(matrixCatalog[4]), 'view');
    assert.equal(permissionActionLabel(matrixCatalog[2]), 'Supprimer');
    assert.equal(permissionActionLabel(matrixCatalog[5]), 'Supprimer');
    assert.equal(permissionActionLabel(matrixCatalog[3]), 'Supprimer définitivement un patient');
});

/**
 * Tous les mots, dans n'importe quel ordre, sans accents — et jamais le nom
 * du module : « supprimer patient » ramenait toutes les suppressions du module
 * « Accueil & patients », adresses comprises.
 */
test('the search needs every word, and ignores the module name', () => {
    assert.equal(matchesSearchTerms('Supprimer définitivement un patient', 'patient supprimer'), true);
    assert.equal(matchesSearchTerms('Supprimer définitivement un patient', 'DEFINITIVEMENT'), true);
    assert.equal(matchesSearchTerms('Supprimer un patient', 'supprimer facture'), false);
    assert.equal(matchesSearchTerms('Nimporte quoi', '   '), true);

    const index = permissionSearchIndex(buildPermissionModules(matrixCatalog));

    assert.equal(matchesSearchTerms(index.get(3), 'supprimer patient'), true);
    assert.equal(matchesSearchTerms(index.get(9), 'supprimer patient'), false);
    assert.equal(matchesSearchTerms(index.get(8), 'pharmacy.dispense.print'), true);
});
