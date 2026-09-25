import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const scheduler = fs.readFileSync('resources/js/Components/Surgery/SurgeonScheduler.vue', 'utf8');
const page = fs.readFileSync('resources/js/Pages/Surgery/Show.vue', 'utf8');
const header = fs.readFileSync('resources/js/Components/Surgery/EnTeteDossierChirurgical.vue', 'utf8');
const employeeForm = fs.readFileSync('resources/js/Pages/Administration/Employees/EmployeeForm.vue', 'utf8');

test('« Moi-même » n’est proposé qu’à un compte au profil Chirurgien, et le met principal', () => {
    assert.match(scheduler, /me\.value\?\.professional_profile\?\.code === 'SURGEON'/);
    assert.match(scheduler, /v-if="iAmSurgeon && mine"/);
    assert.match(scheduler, /toggle\(mine\.id, value, \{ principal: true \}\)/);
    assert.match(scheduler, />Moi-même</);
});

test('la disponibilité vient du serveur, à l’heure choisie, jamais d’un calcul local', () => {
    assert.match(scheduler, /\$\{base\.value\}\/surgeons\?at=\$\{encodeURIComponent\(at\)\}/);
    assert.doesNotMatch(scheduler, /planning_shifts|starts_at\s*<=/);
    // Pas de lecture réseau pendant le rendu serveur (SSR) : seulement au montage.
    assert.match(scheduler, /onMounted\(load\)/);
});

test('le premier choisi est le principal, les suivants partent comme aides', () => {
    assert.match(scheduler, /surgeon_id: selected\.value\[0\]/);
    assert.match(scheduler, /assistant_surgeon_ids: selected\.value\.slice\(1\)/);
    assert.match(scheduler, /Définir principal/);
    assert.match(scheduler, /MAX_ASSISTANTS = 5/);
});

test('un chirurgien indisponible est montré verrouillé avec sa raison, et bloque l’envoi s’il est choisi', () => {
    assert.match(scheduler, /:disabled="\(!surgeon\.selectable \|\| reachedMax\) && !isSelected\(surgeon\.id\)"/);
    assert.match(scheduler, /n’est pas disponible à cette heure/);
    assert.match(scheduler, /Aucun compte n’a encore le profil Chirurgien/);
    assert.match(scheduler, /« Non vérifié »/);
});

test('l’explication « Non vérifié » passe par un toast, une fois par ouverture', () => {
    assert.match(scheduler, /toast\.info\(UNLINKED_MESSAGE, 9000\)/);
    assert.match(scheduler, /if \(!unlinkedNotified && roster\.value\.some\(\(surgeon\) => surgeon\.state === 'UNLINKED'\)\)/);
    // Plus de paragraphe sous la liste : le badge « Non vérifié » reste sur la ligne.
    assert.doesNotMatch(scheduler, /<p v-if="roster\.some\(\(surgeon\) => surgeon\.state === 'UNLINKED'\)"/);
    assert.match(scheduler, />Non vérifié</);
});

test('la page ne choisit plus un chirurgien par le nom de son rôle', () => {
    assert.doesNotMatch(page, /role\?\.code === 'SURGERY'/);
    assert.match(page, /<SurgeonScheduler/);
    assert.match(page, /filter\(\(item\) => item\.value !== 'SURGEON'\)/);
    assert.match(page, /Chirurgiens aides/);
    assert.match(header, /aide\$\{assistants\.value\.length > 1 \? 's' : ''\}/);
});

test('le compte se relie à sa fiche depuis « Utilisateurs », plus depuis le formulaire RH (ADR-183)', () => {
    const picker = fs.readFileSync('resources/js/Components/Users/AccountKindPicker.vue', 'utf8');

    assert.doesNotMatch(employeeForm, /Compte de connexion"|user_uuid/);
    assert.match(picker, /label: 'Personnel clinique'/);
    assert.match(picker, /label: 'Externe'/);
    for (const page of ['resources/js/Pages/SuperAdmin/Users/Index.vue', 'resources/js/Pages/Administration/Users/Index.vue']) {
        assert.match(fs.readFileSync(page, 'utf8'), /<AccountKindPicker/, `${page} propose Personnel clinique / Externe`);
    }
});

test('l’écran de programmation est écrit en shadcn, sans DashWind', () => {
    assert.doesNotMatch(scheduler, /Components\/UI\/Icon|(?<![a-z])nk-|ni ni-|text-slate-|bg-slate-/);
});

test('l’équipe de bloc ne propose que les comptes au profil de la fonction choisie', () => {
    assert.match(page, /user\.professional_profile\?\.code === profile && !taken\.has\(user\.id\)/);
    assert.match(page, /watch\(\(\) => teamForm\.function, \(\) => \{ teamForm\.user_id = ''/);
    // La fonction d'abord, puis la personne ; plus de liste de tous les comptes.
    assert.ok(page.indexOf('id="team_function"') < page.indexOf('id="team_user"'));
    assert.doesNotMatch(page, /const userOptions = /);
    assert.match(page, /Aucun compte disponible au profil/);
});
