import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { deadlineUrgency, handoverGroup, handoverSteps, handoverTone, initialLocalPart, isRecent, itemStateTone, loginLink, proposedSelection, receivedAction, rowProblem, shareMessage } from '../../resources/js/utilities/staffAccess.js';

const source = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const roles = [
    { id: 1, name: 'Réception', profiles: [] },
    { id: 2, name: 'Soins', profiles: [{ id: 7, name: 'Infirmier' }] },
];

test('a row says what is still missing before its access is created', () => {
    const employee = { mailbox: null, suggestion: 'soa.rakoto' };
    assert.equal(rowProblem({ employee, local_part: '', role_id: 1 }, roles), 'Indiquez l’adresse.');
    assert.match(rowProblem({ employee, local_part: 'Soa Rakoto', role_id: 1 }, roles), /lettres sans accent/);
    assert.equal(rowProblem({ employee, local_part: 'soa.rakoto', role_id: '' }, roles), 'Choisissez le rôle.');
    assert.equal(rowProblem({ employee, local_part: 'soa.rakoto', role_id: 2, profile_id: '' }, roles), 'Choisissez le profil métier.');
    assert.equal(rowProblem({ employee, local_part: 'soa.rakoto', role_id: 2, profile_id: 7 }, roles), null);
    assert.equal(rowProblem({ employee, local_part: 'soa.rakoto', role_id: '1' }, roles), null);

    // Une adresse déjà ouverte est reprise : rien à saisir.
    assert.equal(rowProblem({ employee: { mailbox: { status: 'ACTIVE', address: 'soa@x.mg' } }, local_part: '', role_id: 1 }, roles), null);
    assert.match(rowProblem({ employee: { mailbox: { status: 'SUSPENDED', address: 'soa@x.mg' } }, local_part: 'soa', role_id: 1 }, roles), /suspendue/);
});

test('the proposed address is the one already asked for, else the site suggestion', () => {
    assert.equal(initialLocalPart({ mailbox: { status: 'REQUESTED', address: 'hery.rabe@cliniquesaintgeorges.mg' }, suggestion: 'autre' }), 'hery.rabe');
    assert.equal(initialLocalPart({ mailbox: null, suggestion: 'soa.rakoto' }), 'soa.rakoto');
    assert.equal(initialLocalPart({}), '');
});

test('recent employees and handover states are told apart', () => {
    const now = new Date('2026-09-26T12:00:00Z');
    assert.equal(isRecent('2026-09-24T12:00:00Z', now), true);
    assert.equal(isRecent('2026-09-10T12:00:00Z', now), false);
    assert.equal(isRecent(null, now), false);
    assert.equal(handoverTone('DRAFT'), 'warning');
    assert.equal(handoverTone('TO_REOPEN'), 'danger');
    assert.equal(handoverTone('COMPLETE'), 'success');
    assert.equal(handoverTone('???'), 'neutral');
    assert.equal(itemStateTone('ACTIVATED'), 'success');
    assert.equal(itemStateTone('EXPIRED'), 'danger');
});

test('ADR-199 — the role comes preselected from the employee’s job title, when it exists on this site', () => {
    const laborantin = { proposed_access: { role_id: 1, profile_id: null, job_title: 'Laborantin' } };
    assert.deepEqual(proposedSelection(laborantin, roles), { role_id: '1', profile_id: '', fromJobTitle: true });

    const nurse = { proposed_access: { role_id: 2, profile_id: 7, job_title: 'Infirmier généraliste' } };
    assert.deepEqual(proposedSelection(nurse, roles), { role_id: '2', profile_id: '7', fromJobTitle: true });

    // Un profil qui n'est plus dans ce rôle n'est pas repris ; le rôle, si.
    assert.deepEqual(proposedSelection({ proposed_access: { role_id: 2, profile_id: 99 } }, roles), { role_id: '2', profile_id: '', fromJobTitle: true });
    // Un rôle absent de ce site, ou aucune proposition : rien n'est prérempli.
    assert.deepEqual(proposedSelection({ proposed_access: { role_id: 42 } }, roles), { role_id: '', profile_id: '', fromJobTitle: false });
    assert.deepEqual(proposedSelection({ proposed_access: null }, roles), { role_id: '', profile_id: '', fromJobTitle: false });
});

test('ADR-199 — the grant dialog is a narrow form that says nothing is wrong before anyone acted', () => {
    const dialog = source('resources/js/Components/StaffAccess/StaffAccessGrantDialog.vue');
    // Assez large pour poser les champs côte à côte, sans prendre tout l'écran.
    assert.match(dialog, /size="xl"/);
    assert.doesNotMatch(dialog, /size="wide"/);
    assert.match(dialog, /class="grid gap-x-4 gap-y-3 p-4 md:grid-cols-2"/);
    // Envoyer au RH est inerte tant que personne sur le site ne peut recevoir.
    assert.match(dialog, /:disabled="sending\[group\.uuid\] \|\| receiversOf\(group\.site\) === 0"/);
    // ADR-199 — personne au site ne peut recevoir : le Super Admin désigne qui remettra, sans quitter la fenêtre.
    assert.match(dialog, /<StaffAccessReceiverPicker[\s\S]*?v-if="!sent\[group\.uuid\] && receiversOf\(group\.site\) === 0"/);
    assert.match(source('resources/js/Components/StaffAccess/StaffAccessHandoverCard.vue'), /noReceiver = computed\(\(\) => isDraft\.value && \(props\.handover\.receivers \?\? 0\) === 0\)/);
    assert.match(source('resources/js/Components/StaffAccess/StaffAccessHandoverCard.vue'), /<StaffAccessReceiverPicker/);
    const picker = source('resources/js/Components/StaffAccess/StaffAccessReceiverPicker.vue');
    assert.match(picker, /\/super-admin\/staff-access\/\$\{props\.site\}\/receivers/);
    assert.doesNotMatch(picker, /password/i);
    // Libellés au-dessus des champs, rôle prérempli par la fonction.
    assert.match(dialog, /label="Adresse professionnelle"/);
    assert.match(dialog, /label="Rôle"/);
    // Ni « Rôle dans RIVO » ni « Compte RIVO » : juste Rôle et Compte.
    assert.doesNotMatch(dialog, /RIVO/);
    assert.match(dialog, /proposedSelection\(employee, rolesOf\(employee\.site_code\)\)/);
    assert.match(dialog, /Proposé par sa fonction/);
    assert.match(dialog, /selon sa fonction/);
    // Un manque ne se dit qu'après un geste ou un refus.
    assert.match(dialog, /const visibleProblem = \(row\) => \(\(row\.touched \|\| row\.status === 'failed'\) \? problemOf\(row\) : null\)/);
    assert.doesNotMatch(dialog, /Aucun profil pour ce rôle/);
});

test('ADR-199 — accounts and staff access are one module: shared tabs, one menu entry', () => {
    const tabs = source('resources/js/Components/SuperAdmin/UserAccessTabs.vue');
    assert.match(tabs, /href: '\/super-admin\/workspaces\/users'[^}]*permission: 'users\.view'/);
    assert.match(tabs, /href: '\/super-admin\/staff-access'[^}]*permission: 'staff_access\.view'/);
    // Un onglet sans droit est verrouillé, jamais masqué (ADR-158).
    assert.match(tabs, /aria-disabled="true"/);

    assert.match(source('resources/js/Pages/SuperAdmin/Users/Index.vue'), /<UserAccessTabs current="accounts"/);
    assert.match(source('resources/js/Pages/SuperAdmin/StaffAccess/Index.vue'), /<UserAccessTabs current="staff-access"/);

    const menu = source('resources/js/Components/Layout/Menu.vue');
    assert.doesNotMatch(menu, /text: 'Accès du personnel'/);
    assert.match(menu, /text: 'Utilisateurs'[^}]*activeLinks: \['\/super-admin\/workspaces\/users', '\/super-admin\/staff-access'\]/);
});

test('ADR-199 — the account wizard takes the role from the chosen employee’s job title, never over a manual choice', () => {
    const users = source('resources/js/Pages/SuperAdmin/Users/Index.vue');
    assert.match(users, /const proposal = proposedSelection\(employee, roles\.value\)/);
    assert.match(users, /form\.role_id === '' \|\| String\(form\.role_id\) === String\(prefilled\.value\.role_id\)/);
});

test('ADR-202 — a hand-over reads as a timeline: created, sent, then first logins', () => {
    const states = (handover) => handoverSteps(handover).map((step) => `${step.key}:${step.state}`);
    const counts = (activated, waiting, expired) => ({ total: activated + waiting + expired, activated, waiting, expired });

    assert.deepEqual(states({ status: 'DRAFT', created_at: 't1', counts: counts(0, 2, 0) }), ['created:done', 'sent:current', 'activated:pending']);
    assert.deepEqual(states({ status: 'WAITING', created_at: 't1', sent_at: 't2', counts: counts(1, 1, 0) }), ['created:done', 'sent:done', 'activated:current']);
    assert.deepEqual(states({ status: 'TO_REOPEN', created_at: 't1', sent_at: 't2', counts: counts(1, 0, 1) }), ['created:done', 'sent:done', 'activated:failed']);
    assert.deepEqual(states({ status: 'COMPLETE', created_at: 't1', sent_at: 't2', completed_at: 't3', counts: counts(2, 0, 0) }), ['created:done', 'sent:done', 'activated:done']);

    const last = (handover) => handoverSteps(handover).at(-1);
    assert.equal(last({ status: 'WAITING', sent_at: 't2', counts: counts(1, 2, 0) }).label, 'Connectés 1/3');
    assert.equal(last({ status: 'WAITING', sent_at: 't2', counts: counts(1, 2, 0) }).detail, '2 en attente');
    assert.equal(last({ status: 'TO_REOPEN', sent_at: 't2', counts: counts(0, 0, 2) }).detail, '2 délais dépassés');
    assert.equal(last({ status: 'COMPLETE', sent_at: 't2', completed_at: 't3', counts: counts(2, 0, 0) }).label, 'Tous connectés');

    assert.equal(handoverGroup('DRAFT'), 'todo');
    assert.equal(handoverGroup('TO_REOPEN'), 'reopen');
    assert.equal(handoverGroup('WAITING'), 'waiting');
    assert.equal(handoverGroup('COMPLETE'), 'done');

    // Aucun mot de passe n'existe : ni la carte du portail, ni la fenêtre de création n'en parlent comme d'une valeur.
    for (const file of ['resources/js/Components/StaffAccess/StaffAccessHandoverCard.vue', 'resources/js/Components/StaffAccess/StaffAccessGrantDialog.vue']) {
        assert.doesNotMatch(source(file), /\.password\b|secret\b|reveal/);
    }
});

test('ADR-199 — the portal creates only external accounts; an employee goes through staff access', () => {
    const users = source('resources/js/Pages/SuperAdmin/Users/Index.vue');
    assert.match(users, /form\.account_kind = 'EXTERNAL';/);
    assert.match(users, /<AccountKindPicker\s+v-else/);
    assert.match(users, /\/super-admin\/staff-access\?site=/);
    assert.match(users, /Compte externe/);
});

test('the invitation steps live in the “!” button of the account step, never as a block across the page', () => {
    const users = source('resources/js/Pages/SuperAdmin/Users/Index.vue');
    assert.doesNotMatch(users, /aria-labelledby="invitation-title"/);
    assert.match(users, /<NoticesButton v-if="! isEditing"[^>]*:notices="invitationNotices"/);
    assert.match(users, /key: 'email', icon: Mail, title: '2 · Email envoyé'/);
});

test('ADR-202 — the nearest first-login deadline gets louder as it nears', () => {
    const now = new Date('2026-09-26T22:00:00+03:00');
    const at = (iso) => deadlineUrgency(iso, now);

    assert.deepEqual([at('2026-10-10T22:00:00+03:00').tone, at('2026-10-10T22:00:00+03:00').label], ['neutral', 'Délai dans 14 jours']);
    assert.deepEqual([at('2026-09-29T22:00:00+03:00').tone, at('2026-09-29T22:00:00+03:00').label], ['warning', 'Délai dans 3 jours']);
    assert.deepEqual([at('2026-09-27T10:00:00+03:00').tone, at('2026-09-27T10:00:00+03:00').label], ['danger', 'Délai dans 12 h']);
    assert.equal(at('2026-09-26T21:00:00+03:00').label, 'Délai dépassé', 'jamais une durée négative');
    assert.equal(deadlineUrgency(null, now), null);
});

test('ADR-202 — the HR button says what the hand-over waits for', () => {
    assert.equal(receivedAction('WAITING').label, 'Prévenir les employés');
    assert.equal(receivedAction('WAITING').primary, true);
    assert.equal(receivedAction('TO_REOPEN').label, 'Rouvrir');
    assert.equal(receivedAction('COMPLETE').label, 'Voir');
    assert.equal(receivedAction('COMPLETE').primary, false);
    assert.equal(receivedAction('INCONNU').label, 'Voir');
});

test('ADR-202 — the message the HR shares says where to sign in, with the address already typed, and no secret', () => {
    assert.equal(loginLink('https://a.test/login', 'vola.rabe@cliniquesaintgeorges.mg'), 'https://a.test/login?email=vola.rabe%40cliniquesaintgeorges.mg');
    assert.equal(loginLink('https://a.test/login', null), 'https://a.test/login');

    const item = { employee_name: 'Vola RABE', login_email: 'vola.rabe@cliniquesaintgeorges.mg', mailbox_address: 'vola.rabe@cliniquesaintgeorges.mg' };
    const message = shareMessage(item, { loginUrl: 'https://a.test/login', brand: 'RIVO', site: 'Ambondromamy', deadline: '2026-10-10T10:00:00+03:00' });
    assert.match(message, /^Bonjour Vola,/);
    assert.match(message, /Votre compte RIVO — Ambondromamy est créé\./);
    assert.match(message, /avant le 10\/10\/2026 sur : https:\/\/a\.test\/login\?email=vola\.rabe%40cliniquesaintgeorges\.mg/);
    assert.match(message, /vous choisirez vous-même votre mot de passe/);
    assert.match(message, /messagerie professionnelle vola\.rabe@cliniquesaintgeorges\.mg/);
    assert.doesNotMatch(shareMessage({ ...item, mailbox_address: null }, { loginUrl: 'https://a.test/login' }), /messagerie/);
});

test('ADR-202 — the HR hand-over page shares a link, prints a sheet with a QR code and reopens a late first login', () => {
    const show = source('resources/js/Pages/Administration/StaffAccess/Show.vue');
    assert.match(show, /import QRCode from 'qrcode'/);
    assert.match(show, /QRCode\.toDataURL\(loginLink\(props\.loginUrl, item\.login_email\)/);
    assert.match(show, /shareMessage\(item,/);
    assert.match(show, /\/items\/\$\{reopenTarget\.value\.uuid\}\/reopen/);
    assert.doesNotMatch(show, /reveal|secret|delivered/i);
});

test('the HR list reads each hand-over from one card, in shadcn, without DashWind', () => {
    const page = source('resources/js/Pages/Administration/StaffAccess/Index.vue');
    const card = source('resources/js/Components/StaffAccess/StaffAccessReceivedCard.vue');

    assert.match(page, /<StaffAccessReceivedCard\b/);
    assert.match(page, /<QueueCounters\b/);
    assert.match(page, /hrUrl\('\/administration\/staff-access'\)/, 'servie aussi au portail (ADR-187)');
    for (const file of [page, card]) {
        assert.doesNotMatch(file, /Components\/UI\/Icon\.vue|\bnk-|\bni ni-/);
    }
    assert.match(card, /hrUrl\(`\/administration\/staff-access\/\$\{props\.handover\.uuid\}`\)/);
    assert.doesNotMatch(card, /secret/, 'aucun mot de passe dans la liste');
});

test('ADR-202 — the login page asks for the address first, then the password or the first-login choice', () => {
    const login = source('resources/js/Pages/Auth/Login.vue');
    assert.match(login, /fetch\('\/login\/identifier'/);
    assert.match(login, /activation\.post\('\/login\/premiere-connexion'/);
    assert.match(login, /Continuer/);
    assert.match(login, /Nouveau mot de passe/);
    assert.match(login, /Confirmer votre mot de passe/);
    // Une vérification qui ne répond pas ne bloque jamais : le mot de passe est demandé.
    assert.match(login, /goTo\('password'\);\n\};/);
    // L'adresse peut arriver dans le lien du RH.
    assert.match(login, /new URLSearchParams\(window\.location\.search\)\.get\('email'\)/);
});
