import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const users = fs.readFileSync('resources/js/Pages/SuperAdmin/Users/Index.vue', 'utf8');
const roles = fs.readFileSync('resources/js/Pages/SuperAdmin/Roles/Index.vue', 'utf8');
const overrides = fs.readFileSync('resources/js/Components/Rbac/UserPermissionOverrides.vue', 'utf8');
const baselines = fs.readFileSync('resources/js/Components/Rbac/RoleBaselineEditor.vue', 'utf8');
const menu = fs.readFileSync('resources/js/Components/Layout/Menu.vue', 'utf8');

/**
 * Créer un compte touche une personne ; modifier un socle touche tous ceux
 * qui exercent le métier. Les deux vivaient sur un écran unique.
 */
test('les comptes et les rôles sont deux écrans', () => {
    assert.match(menu, /text: 'Utilisateurs', link: '\/super-admin\/workspaces\/users', permission: 'users\.view'/);
    assert.match(menu, /text: 'Rôles & permissions', link: '\/super-admin\/workspaces\/roles', permission: 'roles\.view'/);

    // L'écran des comptes ne porte plus ni socle, ni atelier de permissions.
    assert.doesNotMatch(users, /RoleBaselineEditor|PermissionAccessRow|PermissionCategoryNav/);
    assert.doesNotMatch(users, /view === 'roles'/);

    assert.match(roles, /import RoleBaselineEditor from '@\/Components\/Rbac\/RoleBaselineEditor\.vue'/);
    assert.match(roles, /import UserPermissionOverrides from '@\/Components\/Rbac\/UserPermissionOverrides\.vue'/);
});

/**
 * Les commandes de comptes ont suivi l'écran : laissées sous l'URL des
 * rôles, elles auraient fait dépendre la création d'un compte d'une route
 * nommée « roles », que la moindre relecture aurait rendue fausse.
 */
test('l’écran des comptes poste sous son propre chemin', () => {
    assert.doesNotMatch(users, /\/super-admin\/workspaces\/roles\/\$\{/);
    assert.match(users, /form\.post\('\/super-admin\/workspaces\/users'/);
    assert.match(users, /\/super-admin\/workspaces\/users\/\$\{selectedSiteCode\.value\}\/\$\{editingUser\.value\.uuid\}/);
});

/**
 * Le formulaire n'envoie plus `permission_overrides` : omise, la clé laisse
 * les exceptions intactes côté serveur. Envoyée vide, elle les effacerait
 * toutes à chaque modification d'un nom ou d'un e-mail.
 */
test('modifier un compte ne touche pas à ses exceptions', () => {
    const form = users.slice(users.indexOf('const form = useForm('), users.indexOf('const deactivationForm'));

    assert.doesNotMatch(form, /permission_overrides/);
    assert.doesNotMatch(form, /sync_profile_permissions/);
    assert.doesNotMatch(users, /serializeOverrides/);
});

/** Deux étapes, plus trois : le formulaire ne conduit plus aux permissions. */
test('l’assistant de compte s’arrête au rôle', () => {
    assert.match(users, /const steps = \[\s*\n\s*\{ n: 1, label: 'Informations'[\s\S]*?\{ n: 2, label: 'Rôle'[^\n]*\n\];/);
    assert.doesNotMatch(users, /step === 3/);
    assert.match(users, /<ol class="grid grid-cols-2">/);
});

/**
 * L'ordre de résolution ne change pas d'un écran à l'autre : c'est la seule
 * chose que l'utilisateur doit pouvoir tenir pour acquise.
 */
test('l’écran des exceptions garde la priorité du DENY', () => {
    assert.match(overrides, /if \(effect === 'deny'\) return false;\s*\n\s*if \(effect === 'allow'\) return true;/);
    assert.match(overrides, /return roleGrants\(permission\);/);
});

/** Ouvrir un droit sensible mérite un deuxième regard ; le fermer, non. */
test('autoriser une permission sensible demande confirmation', () => {
    assert.match(overrides, /if \(effect === 'allow' && isSensitivePermission\(permission\)\)/);
    assert.match(overrides, /title="Autoriser cette permission sensible \?"/);
    assert.match(overrides, /title="Confirmer l’action groupée \?"/);
});

/** Un rôle porté par des comptes ne s’archive pas : le bouton le dit avant le clic. */
test('le référentiel des rôles protège les rôles portés', () => {
    assert.match(roles, /:disabled="role\.users_count > 0"/);
    assert.match(roles, /role\.users_count > 0 \? `\$\{role\.users_count\} compte\(s\) portent encore ce rôle`/);
    assert.match(roles, /role\.protected/);
});

/** Le code d’un rôle est son identité : il ne se renomme jamais. */
test('renommer ne touche que le libellé', () => {
    const rename = roles.slice(roles.indexOf('const renameForm'), roles.indexOf('const archiving'));

    assert.match(rename, /useForm\(\{ name: '' \}\)/);
    assert.doesNotMatch(rename, /code:/);
});

test('les réinitialisations distinguent le socle du rôle des exceptions du compte', () => {
    assert.match(baselines, /Réinitialiser le rôle/);
    assert.match(baselines, /selectedRole\?\.has_default_baseline/);
    assert.match(baselines, /Leurs autorisations et interdictions individuelles resteront inchangées/);

    assert.match(overrides, /Réinitialiser le compte/);
    // Visible même sans exception (ADR-158) : désactivé et expliqué, jamais masqué.
    assert.doesNotMatch(overrides, /v-if="canAssign && resetStats\.total"/);
    assert.match(overrides, /:disabled="processing \|\| resetStats\.total === 0"/);
    assert.match(overrides, /héritera uniquement du socle de son rôle/);
    assert.match(overrides, /ses recommandations ne seront pas réappliquées automatiquement/);
});

/** Les trois états d'une exception disent ce qu'ils produisent, pas seulement ce qu'ils écrivent. */
test('les trois états d’une permission sont nommés par leur effet', () => {
    const row = fs.readFileSync('resources/js/Components/Rbac/PermissionAccessRow.vue', 'utf8');

    assert.match(row, /label: 'Suivre le rôle'/);
    assert.match(row, /label: 'Toujours autoriser'/);
    assert.match(row, /label: 'Toujours interdire'/);
    assert.match(row, /Socle du rôle :/);
    assert.match(row, /Résultat pour ce compte :/);
});
