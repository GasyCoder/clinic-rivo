import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(path, 'utf8');
const DASHWIND = /Components\/UI\/(Icon|Input|InputWrap|FormGroup|FormLabel|FormError|Button|CheckBox)\.vue|class="[^"]*\bni ni-|\bnk-(main|wrap)\b/;

/**
 * ADR-184 — les pages d'authentification suivent le modèle réglé pour le site :
 * une seule enveloppe, trois dispositions inspirées de DashWind, écrites en
 * shadcn-vue (ADR-099).
 */
test('every auth page goes through the shared shell, shadcn only', () => {
    for (const path of ['resources/js/Pages/Auth/Login.vue', 'resources/js/Pages/Auth/ForgotPassword.vue', 'resources/js/Pages/Auth/ResetPassword.vue']) {
        const page = read(path);

        assert.match(page, /import AuthShell from '@\/Components\/Auth\/AuthShell\.vue'/, `${path} n’utilise pas l’enveloppe commune`);
        assert.match(page, /<AuthShell/);
        assert.doesNotMatch(page, DASHWIND, `${path} garde du DashWind`);
    }

    assert.doesNotMatch(read('resources/js/Layouts/GuestLayout.vue'), DASHWIND);
    assert.ok(! fs.existsSync('resources/js/Components/Auth/IdentityPanel.vue'), 'l’ancien panneau est remplacé par l’enveloppe');
});

test('the shell offers the three templates and falls back on the cover', () => {
    const shell = read('resources/js/Components/Auth/AuthShell.vue');

    for (const template of ['COVER', 'SPLIT', 'CENTERED']) {
        assert.match(shell, new RegExp(`data-auth-template="${template}"`));
    }
    assert.match(shell, /TEMPLATES\.includes\(site\.value\.authTemplate\) \? site\.value\.authTemplate : 'COVER'/);
    assert.match(shell, /site\.value\.authCoverUrl/, 'l’image de fond vient du site');
});

/** Exigence du propriétaire : le logo de la page de connexion est toujours au centre. */
test('the login logo is always centered', () => {
    const mark = read('resources/js/Components/Auth/BrandMark.vue');

    assert.match(mark, /class="mb-8 flex min-h-16 items-center justify-center" data-auth-logo/);
    assert.match(mark, /class="mx-auto h-auto w-full max-w-\[270px\] object-contain object-center"/);
    assert.match(mark, /<div v-else class="flex items-center justify-center gap-3">/, 'les initiales, à défaut de logo, sont centrées aussi');
    assert.doesNotMatch(mark, /object-left/);

    // Chaque modèle passe par ce seul composant : aucun ne peut réécrire le logo à gauche.
    const shell = read('resources/js/Components/Auth/AuthShell.vue');
    assert.equal(shell.match(/<BrandMark \/>/g)?.length, 3);
    assert.doesNotMatch(shell, /logo_url/);
});

test('the password field is labelled before its show/hide button', () => {
    const field = read('resources/js/Components/Shadcn/PasswordInput.vue');

    assert.ok(field.indexOf('<Input') < field.indexOf('<button'), 'dans un <label>, le premier élément étiquetable reçoit le libellé');
    assert.match(field, /:aria-pressed="visible"/);
});

test('the profile page follows the site template, shadcn only', () => {
    const profile = read('resources/js/Pages/Profile/Show.vue');

    assert.match(profile, /data-profile-template="SIDEBAR"/);
    assert.match(profile, /data-profile-template="BANNER"/);
    assert.match(profile, /page\.props\.site\?\.profileTemplate === 'BANNER'/);
    for (const section of ['ProfileIdentity', 'ProfilePermissions', 'ProfileSecurity']) {
        assert.match(profile, new RegExp(`<${section}`));
    }
    assert.match(read('resources/js/Components/Profile/ProfileSecurity.vue'), /form\.put\('\/profil\/mot-de-passe'/);

    for (const path of ['resources/js/Pages/Profile/Show.vue', 'resources/js/Components/Profile/ProfileIdentity.vue', 'resources/js/Components/Profile/ProfilePermissions.vue', 'resources/js/Components/Profile/ProfileSecurity.vue']) {
        assert.doesNotMatch(read(path), DASHWIND, `${path} garde du DashWind`);
    }
});

test('the settings page chooses the templates and the background', () => {
    const page = read('resources/js/Pages/SuperAdmin/Settings/Index.vue');

    assert.match(page, /id="reglages-ecrans"/);
    assert.match(page, /@click="form\.auth_template = option\.value"/);
    assert.match(page, /@click="form\.profile_template = option\.value"/);
    assert.match(page, /kind="background"/);
});
