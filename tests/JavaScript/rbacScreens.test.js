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

/**
 * L'assistant de compte : Entrée à l'étape 1 mène au rôle, jamais à l'envoi
 * d'un compte dont personne n'a choisi le rôle ; aucun rôle n'est présélectionné ;
 * un bouton grisé dit ce qui manque ; une erreur du site sur l'email ramène à
 * l'étape où il se corrige.
 */
test('l’assistant de compte guide sans jamais envoyer trop tôt', () => {
    assert.match(users, /<form class="min-w-0 space-y-5" novalidate @submit\.prevent="onFormSubmit">/);
    assert.match(users, /const onFormSubmit = \(\) => \{\s*if \(step\.value < 2\) \{\s*nextStep\(\);\s*return;\s*\}\s*submitUser\(\);/);
    assert.match(users, /form\.role_id = '';/);
    assert.doesNotMatch(users, /form\.role_id = roles\.value\.find/);
    assert.match(users, /const blocker = computed\(/);
    assert.match(users, /STEP_ONE_FIELDS = \['account_kind', 'employee_uuid', 'name', 'email', 'password', 'password_confirmation'\]/);
    assert.match(users, /role="radiogroup" aria-label="Rôle métier"/);
    assert.match(users, /aria-label="Aperçu du compte"/);
});

/**
 * Un rôle à profils se choisit avec son profil, dans une fenêtre : rien n'est
 * écrit dans le formulaire avant « Valider le profil », et annuler rend
 * exactement le rôle et le profil d'avant.
 */
test('un rôle à profils ouvre la fenêtre du profil, sans rien écrire avant validation', () => {
    assert.match(users, /if \(role\.profiles\?\.length\) \{\s*openProfileDialog\(role\);\s*return;\s*\}/);
    assert.match(users, /:open="pendingRole !== null"/);
    assert.match(users, /@update:open="\(open\) => open \|\| cancelProfile\(\)"/);
    assert.match(users, /const confirmProfile = \(\) => \{\s*if \(! pendingRole\.value \|\| ! draftProfile\.value\) return;\s*form\.role_id = pendingRole\.value\.id;\s*form\.professional_profile_id = draftProfile\.value\.id;/);
    assert.doesNotMatch(users, /<fieldset v-if="selectedRoleProfiles\.length">/);
});
