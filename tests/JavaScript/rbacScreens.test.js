import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const users = fs.readFileSync('resources/js/Pages/SuperAdmin/Users/Index.vue', 'utf8');
const roles = fs.readFileSync('resources/js/Pages/SuperAdmin/Roles/Index.vue', 'utf8');
const menu = fs.readFileSync('resources/js/Components/Layout/Menu.vue', 'utf8');

/**
 * Créer un compte touche une personne ; modifier un socle touche tous ceux
 * qui exercent le métier. Les deux vivaient sur un écran unique.
 */
test('les comptes et les rôles sont deux écrans', () => {
    assert.match(menu, /text: 'Utilisateurs', link: '\/super-admin\/workspaces\/users', permission: 'users\.view'/);
    assert.match(menu, /text: 'Rôles & permissions', link: '\/super-admin\/workspaces\/roles', permission: 'roles\.view'/);

    // L'écran des comptes ne porte plus ni socle, ni grille de permissions.
    assert.doesNotMatch(users, /RoleWorkspace|PermissionMatrix|AccountWorkspace/);
    assert.doesNotMatch(users, /view === 'roles'/);

    // Le socle et les exceptions vivent sur l'écran des rôles (ADR-100, ADR-178).
    assert.match(roles, /import RoleWorkspace from '@\/Components\/Rbac\/RoleWorkspace\.vue'/);
    assert.match(roles, /import AccountWorkspace from '@\/Components\/Rbac\/AccountWorkspace\.vue'/);
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
 * L'écran des rôles demande les droits d'écriture un par un : aucun geste
 * n'est proposé à qui ne pourrait pas le faire aboutir (ADR-007, ADR-152).
 */
test('l’écran des rôles se règle sur les droits de l’administrateur connecté', () => {
    for (const permission of [
        'roles.create', 'roles.update', 'roles.archive', 'roles.restore',
        'users.manage', 'permissions.assign',
        'permissions.create', 'permissions.update', 'permissions.delete',
    ]) {
        assert.ok(roles.includes(`can('${permission}')`), `${permission} n'est plus vérifiée`);
    }
});
