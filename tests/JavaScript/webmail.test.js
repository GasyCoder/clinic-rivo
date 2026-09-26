import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    applyActionLocally,
    filterBoxes,
    folderActions,
    reloadAfterSending,
    folderUrl,
    formatListDate,
    formatSize,
    forwardSubject,
    frameDocument,
    initialsOf,
    messageUrl,
    parseRecipients,
    quotaPercent,
    quotedReply,
    recipientSuggestions,
    replyRecipients,
    replySubject,
    WEBMAIL_NAVIGATION,
    WEBMAIL_STATIC_PROPS,
    canReturnByHistory,
    followOpening,
    forgetOpening,
    parseWebmailPath,
    rememberOpening,
    toFormData,
} from '../../resources/js/utilities/webmail.js';
import { buildClinicMenu, visibleMenu } from '../../resources/js/utilities/clinicMenu.js';

test('les destinataires se lisent comme le serveur les lit', () => {
    assert.deepEqual(
        parseRecipients('Dr Vola <Vola@Exemple.mg>; labo@exemple.mg,\npas une adresse, vola@exemple.mg'),
        { valid: ['vola@exemple.mg', 'labo@exemple.mg'], invalid: ['pas une adresse'] },
    );
    assert.deepEqual(parseRecipients(''), { valid: [], invalid: [] });
});

test('une date de liste dit l’heure aujourd’hui, « Hier », puis le jour', () => {
    const now = new Date(2026, 8, 25, 16, 0);
    assert.equal(formatListDate(new Date(2026, 8, 25, 9, 5).toISOString(), now), '09:05');
    assert.equal(formatListDate(new Date(2026, 8, 24, 22, 0).toISOString(), now), 'Hier');
    assert.equal(formatListDate(new Date(2026, 2, 3).toISOString(), now), '3 mars');
    assert.equal(formatListDate(new Date(2025, 11, 31).toISOString(), now), '31/12/2025');
    assert.equal(formatListDate('pas une date', now), '');
});

test('tailles, espace utilisé et initiales', () => {
    assert.equal(formatSize(900), '900 o');
    assert.equal(formatSize(1536 * 1024), '1,5 Mo');
    assert.equal(quotaPercent({ used: 256, limit: 1024 }), 25);
    assert.equal(quotaPercent(null), null, 'un espace inconnu n’est pas 0 %');
    assert.equal(initialsOf({ name: 'Soa Rakoto', email: 's@x.mg' }), 'SR');
    assert.equal(initialsOf({ name: '', email: 'labo.central@x.mg' }), 'LC');
    assert.equal(initialsOf({ name: 'Direction — Clinique Saint Georges', email: 'direction@x.mg' }), 'DC');
});

test('« Re: » et « Tr: » ne se répètent pas', () => {
    assert.equal(replySubject('Bilan'), 'Re: Bilan');
    assert.equal(replySubject('RE: Bilan'), 'RE: Bilan');
    assert.equal(forwardSubject('Fwd: Bilan'), 'Fwd: Bilan');
    assert.equal(forwardSubject('Bilan'), 'Tr: Bilan');
});

test('« Répondre à tous » met les autres en copie, jamais soi-même', () => {
    const message = {
        from: { name: 'Dr Vola', email: 'vola@exemple.mg' },
        reply_to: [],
        to: [{ name: '', email: 'soa@cbdc.mg' }, { name: 'Labo', email: 'labo@exemple.mg' }],
        cc: [{ name: '', email: 'vola@exemple.mg' }],
    };

    assert.deepEqual(replyRecipients(message, 'SOA@cbdc.mg'), { to: 'Dr Vola <vola@exemple.mg>', cc: '' });
    assert.deepEqual(replyRecipients(message, 'soa@cbdc.mg', true), { to: 'Dr Vola <vola@exemple.mg>', cc: 'Labo <labo@exemple.mg>' });
    assert.deepEqual(
        replyRecipients({ ...message, reply_to: [{ name: '', email: 'secretariat@exemple.mg' }] }, 'soa@cbdc.mg'),
        { to: 'secretariat@exemple.mg', cc: '' },
        'l’adresse de réponse l’emporte sur l’expéditeur',
    );
});

test('la citation échappe le message d’origine', () => {
    const html = quotedReply({ date: '2026-09-25T10:00:00Z', from: { name: '<b>Pirate</b>', email: 'p@x.mg' }, quote_text: 'Ligne 1\n<script>x()</script>' });

    assert.ok(html.includes('&lt;b&gt;Pirate&lt;/b&gt; a écrit'));
    assert.ok(html.includes('<blockquote><p>Ligne 1</p><p>&lt;script&gt;x()&lt;/script&gt;</p></blockquote>'));
    assert.ok(!html.includes('<script>'));
});

test('adresses de dossier et de message', () => {
    assert.equal(folderUrl('reception'), '/messagerie/dossier/reception');
    assert.equal(folderUrl('reception', { q: 'écho', filter: 'non-lus', page: 2 }), '/messagerie/dossier/reception?q=%C3%A9cho&filtre=non-lus&page=2');
    assert.equal(messageUrl('d-SU5CT1guRmFjdHVyZXM', 12, { images: true }), '/messagerie/dossier/d-SU5CT1guRmFjdHVyZXM/12?images=1');
});

test('supprimer définitivement ne se propose que depuis la corbeille ou les indésirables', () => {
    assert.ok(!folderActions('inbox').includes('delete'));
    assert.ok(folderActions('inbox').includes('trash'));
    assert.ok(folderActions('trash').includes('delete'));
    assert.ok(folderActions('spam').includes('delete'));
    assert.ok(folderActions('archive').includes('inbox'));
    assert.ok(!folderActions('inbox').includes('inbox'));
});

test('le cadre de lecture interdit tout script et, par défaut, les images distantes', () => {
    const document = frameDocument('<p>Bonjour</p>');
    assert.match(document, /default-src 'none'/);
    assert.match(document, /img-src data:;/);
    assert.doesNotMatch(document, /script-src/);
    assert.match(frameDocument('', { allowRemote: true }), /img-src data: https: http:/);

    const frame = readFileSync(new URL('../../resources/js/Components/Webmail/EmailBodyFrame.vue', import.meta.url), 'utf8');
    const sandbox = frame.match(/sandbox="([^"]*)"/);
    assert.ok(sandbox, 'le corps est affiché dans un cadre isolé');
    assert.doesNotMatch(sandbox[1], /allow-scripts/, 'jamais de script dans le corps d’un message');
});

test('« Messagerie » n’apparaît que pour le titulaire d’une adresse active', () => {
    const can = () => false;
    const texts = (webmail) => visibleMenu(buildClinicMenu({ roleCode: 'RECEPTION', can, webmail }), can)
        .filter((row) => !row.heading).map((row) => row.text);

    assert.ok(texts(true).includes('Messagerie'));
    assert.ok(!texts(false).includes('Messagerie'));
});

test('the boxes one may open are found by name, address, job or site, accents and case ignored', () => {
    const boxes = [
        { owner: 'Vola Rabe', address: 'vola.rabe@cbdc.mg', job: 'Sage-femme', site_name: 'Ambondromamy', site_code: 'A' },
        { owner: 'Hery Andria', address: 'hery@cbdc.mg', job: 'Médecin', site_name: 'Mampikony', site_code: 'M' },
    ];

    assert.deepEqual(filterBoxes(boxes, '').map((box) => box.owner), ['Vola Rabe', 'Hery Andria']);
    assert.deepEqual(filterBoxes(boxes, 'medecin').map((box) => box.owner), ['Hery Andria']);
    assert.deepEqual(filterBoxes(boxes, 'AMBONDROMAMY sage').map((box) => box.owner), ['Vola Rabe']);
    assert.deepEqual(filterBoxes(boxes, 'vola hery'), [], 'every word must match');
});

test('any address can be typed: « Écrire à … » comes first, colleagues follow', () => {
    const contacts = [
        { name: 'Vola Rabe', email: 'vola.rabe@cbdc.mg', job: 'Sage-femme' },
        { name: 'Hery Andria', email: 'hery@cbdc.mg', job: 'Médecin' },
    ];

    // Un nom : les collègues seulement, rien à « écrire à ».
    assert.deepEqual(recipientSuggestions(contacts, [], 'vola').map((option) => option.email), ['vola.rabe@cbdc.mg']);
    // Une adresse d'ailleurs : proposée telle quelle, en tête.
    const outside = recipientSuggestions(contacts, [], 'fournisseur@gmail.com');
    assert.equal(outside.length, 1);
    assert.equal(outside[0].typed, true);
    assert.equal(outside[0].email, 'fournisseur@gmail.com');
    // Une adresse incomplète n'est pas proposée ; celle d'un collègue ne se double pas.
    assert.deepEqual(recipientSuggestions(contacts, [], 'fournisseur@gm'), []);
    assert.deepEqual(recipientSuggestions(contacts, [], 'hery@cbdc.mg').map((option) => option.typed ?? false), [false]);
    // Déjà ajoutée : plus rien à proposer.
    assert.deepEqual(recipientSuggestions(contacts, [{ email: 'fournisseur@gmail.com' }], 'fournisseur@gmail.com'), []);
    assert.deepEqual(recipientSuggestions(contacts, [], '   '), []);
});

test('an action changes the screen at once, and only what it changes', () => {
    const props = {
        list: { total: 3, items: [
            { folder: 'reception', uid: 3, seen: false, flagged: false, keywords: [] },
            { folder: 'reception', uid: 2, seen: true, flagged: false, keywords: ['rivoa1'] },
            { folder: 'reception', uid: 1, seen: false, flagged: true, keywords: [] },
        ] },
        message: null,
        folders: [{ key: 'reception', unseen: 2, total: 3 }, { key: 'archives', unseen: 0, total: 0 }],
        labels: [{ uuid: 'l-1', keyword: 'rivoa1' }, { uuid: 'l-2', keyword: 'rivob2' }],
    };

    // Lu : le message change, et le compteur des non-lus baisse d'autant.
    const read = applyActionLocally(props, { action: 'read', items: [{ folder: 'reception', uid: 3 }, { folder: 'reception', uid: 2 }] });
    assert.deepEqual(read.list.items.map((item) => item.seen), [true, true, false]);
    assert.equal(read.folders[0].unseen, 1, 'un seul changeait vraiment');
    assert.equal(read.folders[1], props.folders[1], 'les autres dossiers restent les mêmes objets');
    assert.equal(props.list.items[0].seen, false, 'les accessoires d’origine ne sont pas modifiés');

    // Étoile et libellé : aucun compteur ne bouge.
    const star = applyActionLocally(props, { action: 'star', items: [{ folder: 'reception', uid: 2 }] });
    assert.equal(star.list.items[1].flagged, true);
    assert.equal(star.folders, undefined);
    const label = applyActionLocally(props, { action: 'label', items: [{ folder: 'reception', uid: 3 }], label: 'l-2' });
    assert.deepEqual(label.list.items[0].keywords, ['rivob2']);
    const unlabel = applyActionLocally(props, { action: 'unlabel', items: [{ folder: 'reception', uid: 2 }], label: 'l-1' });
    assert.deepEqual(unlabel.list.items[1].keywords, []);

    // Archiver : les messages quittent la liste, et le dossier compte ce qu'il perd.
    const archived = applyActionLocally(props, { action: 'archive', items: [{ folder: 'reception', uid: 3 }, { folder: 'reception', uid: 1 }] });
    assert.deepEqual(archived.list.items.map((item) => item.uid), [2]);
    assert.equal(archived.list.total, 1);
    assert.deepEqual([archived.folders[0].unseen, archived.folders[0].total], [0, 1]);

    // Un message ouvert change aussi, sans liste.
    const opened = applyActionLocally({ ...props, list: null, message: { folder: 'reception', uid: 1, seen: true, flagged: true } }, { action: 'unstar', items: [{ folder: 'reception', uid: 1 }] });
    assert.equal(opened.message.flagged, false);
    assert.equal(opened.list, undefined);
});

test('after sending, only the notice is reloaded — except in Sent or Drafts', () => {
    assert.deepEqual(reloadAfterSending('reception'), ['flash', 'errors']);
    assert.deepEqual(reloadAfterSending('reception', true), ['folders', 'flash', 'errors']);
    assert.deepEqual(reloadAfterSending('envoyes'), ['list', 'folders', 'flash', 'errors']);
    assert.deepEqual(reloadAfterSending('brouillons', true), ['list', 'folders', 'flash', 'errors']);
});

test('a webmail address names its folder and, when there is one, its message', () => {
    assert.deepEqual(parseWebmailPath('/messagerie/dossier/reception'), { folder: 'reception', uid: null });
    assert.deepEqual(parseWebmailPath('/messagerie/dossier/envoyes/42'), { folder: 'envoyes', uid: 42 });
    assert.deepEqual(parseWebmailPath('/messagerie/dossier/Mes%20dossiers'), { folder: 'Mes dossiers', uid: null });
    assert.equal(parseWebmailPath('/messagerie/connexion'), null);
    assert.equal(parseWebmailPath('/patients/12'), null);
});

test('navigating inside the webmail keeps the page and never asks again for what does not change', () => {
    assert.equal(WEBMAIL_NAVIGATION.preserveState, true);
    for (const prop of ['labels', 'templates', 'contacts', 'quota', 'mailbox']) {
        assert.ok(WEBMAIL_NAVIGATION.except.includes(prop), prop);
    }
    // La liste, le message, les compteurs : toujours relus.
    for (const prop of ['list', 'message', 'folders', 'current', 'filters', 'error']) {
        assert.ok(!WEBMAIL_STATIC_PROPS.includes(prop), prop);
    }
    assert.ok(Object.isFrozen(WEBMAIL_NAVIGATION));
});

test('the back link uses the browser history only toward the list the message was opened from', () => {
    forgetOpening();
    assert.equal(canReturnByHistory('/messagerie/dossier/reception/7', 'reception'), false, 'arrivé directement : une vraie visite');

    rememberOpening('/messagerie/dossier/reception?page=2', '/messagerie/dossier/reception/7');
    assert.equal(canReturnByHistory('/messagerie/dossier/reception/7', 'reception'), true);
    assert.equal(canReturnByHistory('/messagerie/dossier/reception/7', 'archives'), false, 'un autre dossier');
    assert.equal(canReturnByHistory('/messagerie/dossier/reception/8', 'reception'), false, 'un autre message');

    // Les flèches remplacent le message : le retour mène toujours à la même liste.
    followOpening('/messagerie/dossier/reception/6');
    assert.equal(canReturnByHistory('/messagerie/dossier/reception/6', 'reception'), true);

    // Un autre dossier visité : l'historique n'est plus fiable, on l'oublie.
    forgetOpening();
    assert.equal(canReturnByHistory('/messagerie/dossier/reception/6', 'reception'), false);
});

test('a background send serialises the form as Inertia would, skipping empty fields', () => {
    const file = new Blob(['%PDF-1.4'], { type: 'application/pdf' });
    const form = toFormData({ to: 'a@exemple.mg', cc: '', bcc: null, subject: 'X', reply: { folder: 'reception', uid: 4 }, attachments: [file, file] });
    const entries = [...form.entries()].map(([key, value]) => [key, value instanceof Blob ? 'fichier' : value]);

    assert.deepEqual(entries, [
        ['to', 'a@exemple.mg'],
        ['subject', 'X'],
        ['reply[folder]', 'reception'],
        ['reply[uid]', '4'],
        ['attachments[0]', 'fichier'],
        ['attachments[1]', 'fichier'],
    ]);
});

test('the connect page is full width and the pending states say what is loading', () => {
    const connect = readFileSync(new URL('../../resources/js/Pages/Webmail/Connect.vue', import.meta.url), 'utf8');
    assert.ok(!connect.includes('mx-auto'), 'plus de colonne étroite centrée');
    assert.match(connect, /lg:grid-cols-\[minmax\(0,1fr\)_24rem\]/);

    for (const name of ['PendingMessage', 'PendingList']) {
        const source = readFileSync(new URL(`../../resources/js/Components/Webmail/${name}.vue`, import.meta.url), 'utf8');
        assert.match(source, /data-webmail-pending/);
        assert.match(source, /role="status"/);
    }

    // Tous les avis — audit, sites non listés, informations — tiennent dans le bouton « ! ».
    assert.match(connect, /<NoticesButton :notices="notices"/);
    for (const banner of ['Boîtes non listées pour', 'bg-amber-50 px-3 py-2.5', 'space-y-2.5 border-t']) {
        assert.ok(!connect.includes(banner), banner);
    }
    const emails = readFileSync(new URL('../../resources/js/Components/ProfessionalEmails/ProfessionalEmailsWorkspace.vue', import.meta.url), 'utf8');
    assert.match(emails, /<NoticesButton :notices="notices"/);

    // Le lien d'Inertia écrase un @click : le retour passe par onBefore.
    const view = readFileSync(new URL('../../resources/js/Components/Webmail/MessageView.vue', import.meta.url), 'utf8');
    assert.match(view, /:on-before="goBack"/);
});

test('the filter counters follow an action at once', () => {
    const props = {
        list: {
            items: [
                { folder: 'reception', uid: 3, seen: false, flagged: false },
                { folder: 'reception', uid: 2, seen: true, flagged: true },
                { folder: 'reception', uid: 1, seen: false, flagged: false },
            ],
            total: 3,
            counts: { all: 10, unseen: 4, flagged: 2 },
        },
        folders: [{ key: 'reception', unseen: 4, total: 10 }],
        labels: [],
    };

    const read = applyActionLocally(props, { action: 'read', items: [{ folder: 'reception', uid: 3 }] });
    assert.deepEqual(read.list.counts, { all: 10, unseen: 3, flagged: 2 });

    const starred = applyActionLocally(props, { action: 'star', items: [{ folder: 'reception', uid: 1 }] });
    assert.deepEqual(starred.list.counts, { all: 10, unseen: 4, flagged: 3 });

    // Archiver un non-lu et un favori : chacun retire ce qu'il comptait.
    const archived = applyActionLocally(props, { action: 'archive', items: [{ folder: 'reception', uid: 1 }, { folder: 'reception', uid: 2 }] });
    assert.deepEqual(archived.list.counts, { all: 8, unseen: 3, flagged: 1 });

    // Une liste sans compteurs (les favoris réunis) n'en invente pas.
    const favourites = applyActionLocally({ ...props, list: { ...props.list, counts: null } }, { action: 'read', items: [{ folder: 'reception', uid: 3 }] });
    assert.equal(favourites.list.counts, null);
});
