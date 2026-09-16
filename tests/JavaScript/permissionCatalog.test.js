import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const catalog = fs.readFileSync('resources/js/Components/Rbac/PermissionCatalog.vue', 'utf8');
const roles = fs.readFileSync('resources/js/Pages/SuperAdmin/Roles/Index.vue', 'utf8');
const scanner = fs.readFileSync('app/Services/Authorization/PermissionUsageScanner.php', 'utf8');

/**
 * La question posée : « pourquoi on ne peut pas créer une permission ? ».
 * On peut — et l'écran dit la seule chose qui compte ensuite : le mot existe,
 * le contrôle n'existe pas encore.
 */
test('le catalogue distingue le mot du contrôle', () => {
    assert.match(catalog, /Vérifiée par l’application/);
    assert.match(catalog, /Pas encore vérifiée/);
    assert.match(catalog, /permission\.used_by_app/);

    // L'état vient du serveur, jamais d'une liste tenue dans l'écran.
    assert.doesNotMatch(catalog, /const USED_PERMISSIONS|usedNames\s*=\s*\[/);
});

/** Créer le mot ne crée pas le contrôle : la fenêtre de création le dit. */
test('la création prévient de ce qu’elle ne fait pas', () => {
    const dialog = catalog.slice(catalog.indexOf('title="Nouvelle permission"'), catalog.indexOf('<!-- Corriger le libellé -->'));

    assert.match(dialog, /Créer le mot ne crée pas le contrôle/);
    assert.match(dialog, /Pas encore vérifiée/);
});

/**
 * Le nom est ce que le code écrit en clair : une route pointerait vers un
 * nom disparu, et le contrôle deviendrait impossible à satisfaire.
 */
test('seul le libellé se corrige', () => {
    const edit = catalog.slice(catalog.indexOf('const editForm'), catalog.indexOf('const removing'));

    assert.match(edit, /useForm\(\{ label: '' \}\)/);
    assert.doesNotMatch(edit, /name:/);
    assert.match(catalog, /Le nom ne change jamais/);
});

/** Un refus se lit avant le clic, pas après l’envoi. */
test('le retrait nomme ce qui le bloque', () => {
    assert.match(catalog, /const blockers = \(permission\) => \{/);
    assert.match(catalog, /l’application la vérifie quelque part/);
    assert.match(catalog, /:disabled="! removable\(permission\)"/);
});

/**
 * Un exemple affiché dans un champ n'est pas un contrôle. Sans cette
 * distinction, le `placeholder` de cet écran suffisait à faire passer une
 * permission pour vérifiée — et à en bloquer le retrait.
 */
test('le balayage ne compte que les vraies vérifications d’interface', () => {
    assert.match(scanner, /private function checkedNamesInUi/);
    assert.match(scanner, /can\|cannot\|hasPermission\|hasPermissionTo/);
    assert.match(catalog, /placeholder="kinesitherapie\.view"/);
});

/**
 * Quatre gestes de portée croissante sur un même écran : une pastille ne dit
 * ni ce qu'on va toucher, ni combien.
 */
test('la navigation annonce la portée de chaque section', () => {
    for (const label of ['Socle des rôles', 'Exceptions par compte', 'Rôles du site', 'Catalogue des droits']) {
        assert.ok(roles.includes(`label: '${label}'`), `section « ${label} » absente`);
    }

    assert.match(roles, /scope: /);
    assert.match(roles, /const scopeParts = computed\(/);
    assert.match(roles, /aria-label="Sections"/);
});

const workspace = fs.readFileSync('resources/js/utilities/permissionWorkspace.js', 'utf8');
const baseline = fs.readFileSync('resources/js/Components/Rbac/RoleBaselineEditor.vue', 'utf8');
const overrides = fs.readFileSync('resources/js/Components/Rbac/UserPermissionOverrides.vue', 'utf8');
const row = fs.readFileSync('resources/js/Components/Rbac/PermissionAccessRow.vue', 'utf8');

/**
 * Une permission sans libellé existait vraiment en base
 * (`consultations.reopen`). Triée telle quelle, elle faisait tomber l'écran
 * entier : `null.localeCompare` interrompt le rendu d'un composant, et Vue
 * ne s'en relève pas — la page devient blanche, pas seulement la ligne.
 */
test('un libellé manquant ne peut plus faire tomber un écran', () => {
    assert.match(workspace, /export const permissionLabel = \(permission\) => \{/);
    assert.match(workspace, /export const comparePermissions = \(left, right\)/);

    for (const [name, source] of [
        ['le catalogue', catalog],
        ['le socle des rôles', baseline],
        ['les exceptions', overrides],
    ]) {
        assert.match(source, /items\.sort\(comparePermissions\)/, `${name} trie sans repli`);
    }

    // Plus aucun tri de permission ne lit `.label` directement.
    for (const source of [catalog, baseline, overrides]) {
        assert.doesNotMatch(source, /items\.sort\(\([^)]*\) => [a-z]+\.label\.localeCompare/);
    }
});

/** Le nom est le repli : c'est ce que le code écrit, et c'est lisible. */
test('les écrans affichent le nom à défaut du libellé', () => {
    for (const source of [catalog, baseline, overrides, row]) {
        assert.match(source, /permissionLabel\(permission\)/);
    }

    assert.doesNotMatch(row, /\{\{ permission\.label \}\}/);
});

import { comparePermissions, permissionLabel, permissionMatchesSearch } from '../../resources/js/utilities/permissionWorkspace.js';

/**
 * Le cas réel : `consultations.reopen` existait sans libellé. Le tri levait
 * une exception au milieu du rendu, et c'est tout l'écran qui restait vide.
 */
test('trier un catalogue où un libellé manque ne lève rien', () => {
    const catalogue = [
        { id: 1, name: 'zeta.view', label: 'Voir Zeta' },
        { id: 2, name: 'consultations.reopen', label: null },
        { id: 3, name: 'alpha.view', label: '' },
        { id: 4, name: 'beta.view' },
    ];

    const sorted = [...catalogue].sort(comparePermissions);

    assert.deepEqual(sorted.map((p) => p.name), [
        'alpha.view', 'beta.view', 'consultations.reopen', 'zeta.view',
    ]);

    // Le nom est le repli : c'est ce que le code écrit, et c'est lisible.
    assert.equal(permissionLabel(catalogue[1]), 'consultations.reopen');
    assert.equal(permissionLabel(catalogue[3]), 'beta.view');
    assert.equal(permissionLabel({ label: '  Voir  ' }), 'Voir');

    // La recherche doit trouver une permission sans libellé, pas la masquer.
    assert.equal(permissionMatchesSearch(catalogue[1], 'reopen', 'Consultations'), true);
});

const boundary = fs.readFileSync('resources/js/Components/UI/ErrorBoundary.vue', 'utf8');

/**
 * Vue ne remonte pas d'un rendu interrompu : l'enfant reste à moitié monté,
 * chaque changement d'onglet échoue ensuite à le démonter, et la zone de
 * contenu reste vide sans un mot — la page paraît figée.
 */
test('une section qui tombe n’emporte plus l’écran', () => {
    assert.match(boundary, /onErrorCaptured\(\(error\) => \{/);
    // Renvoyer false arrête la propagation : sinon l'écran entier gèle.
    assert.match(boundary, /return false;/);
    assert.match(boundary, /n’a pas pu s’afficher/);

    assert.match(roles, /<ErrorBoundary v-if="selectedSite\?\.ok" :key="`\$\{selectedSiteCode\}-\$\{tab\}`"/);
});
