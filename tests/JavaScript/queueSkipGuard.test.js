import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { useQueueSkipGuard } from '../../resources/js/composables/useQueueSkipGuard.js';

/**
 * Prendre le n° 2 avant le n° 1 : on demande, on n'interdit pas — Médecine et
 * Soins partagent le même garde-fou.
 */
const row = (uuid, patient, status = 'PENDING', priority = 'NORMAL') => ({
    uuid,
    status,
    episode: { priority, episode_number: `E-${uuid}`, patient: { uuid: patient } },
});

const setup = (rows, currentPage = 1) => {
    const accepted = [];
    const guard = useQueueSkipGuard({ rows: () => rows, currentPage: () => currentPage, accept: (o) => accepted.push(o.uuid) });

    return { guard, accepted };
};

test('le premier de la file se prend sans aucune question', () => {
    const rows = [row('a', 'p1'), row('b', 'p2')];
    const { guard, accepted } = setup(rows);

    guard.request(rows[0]);

    assert.deepEqual(accepted, ['a']);
    assert.equal(guard.pending.value, null);
});

test('prendre le n° 2 demande confirmation, et ne prend rien tant qu’on n’a pas confirmé', () => {
    const rows = [row('a', 'p1'), row('b', 'p2')];
    const { guard, accepted } = setup(rows);

    guard.request(rows[1]);

    assert.deepEqual(accepted, []);
    assert.deepEqual(guard.pending.value.ahead.map((r) => r.uuid), ['a']);

    guard.confirm();

    assert.deepEqual(accepted, ['b']);
    assert.equal(guard.pending.value, null);
});

test('annuler ne prend personne', () => {
    const rows = [row('a', 'p1'), row('b', 'p2')];
    const { guard, accepted } = setup(rows);

    guard.request(rows[1]);
    guard.cancel();

    assert.deepEqual(accepted, []);
    assert.equal(guard.pending.value, null);
});

test('seuls les patients encore en attente comptent devant : un patient déjà pris en charge ne bloque personne', () => {
    const rows = [row('a', 'p1', 'IN_PROGRESS'), row('b', 'p2')];
    const { guard, accepted } = setup(rows);

    guard.request(rows[1]);

    assert.deepEqual(accepted, ['b']);
});

test('un autre passage du même patient n’est pas « un autre patient avant »', () => {
    const rows = [row('a', 'p1'), row('b', 'p1')];
    const { guard, accepted } = setup(rows);

    guard.request(rows[1]);

    assert.deepEqual(accepted, ['b']);
});

test('une page précédente contient forcément des patients non affichés : on le dit', () => {
    const rows = [row('a', 'p1')];
    const { guard, accepted } = setup(rows, 2);

    guard.request(rows[0]);

    assert.deepEqual(accepted, []);
    assert.equal(guard.pending.value.earlierPages, true);
    assert.deepEqual(guard.pending.value.ahead, []);
});

test('passer devant une urgence se voit : elle est relevée à part', () => {
    const rows = [row('a', 'p1', 'PENDING', 'EMERGENCY'), row('b', 'p2'), row('c', 'p3')];
    const { guard } = setup(rows);

    guard.request(rows[2]);

    assert.deepEqual(guard.skippedEmergencies.value.map((r) => r.uuid), ['a']);
});

const BOARD = 'resources/js/Components/Clinical/ActivePassageBoard.vue';

/** ADR-177 — Soins, Médecine et Maternité lisent le même tableau, qui porte le garde-fou une seule fois. */
test('les Soins, la Médecine et la Maternité passent tous par le même garde-fou, sans copie locale', () => {
    const board = fs.readFileSync(BOARD, 'utf8');

    assert.match(board, /useQueueSkipGuard\(/, 'le tableau n’utilise pas le garde-fou partagé');
    assert.match(board, /<QueueSkipConfirm/, 'le tableau n’affiche pas la confirmation partagée');
    assert.doesNotMatch(board, /const pendingAhead/, 'le tableau garde une copie locale de la règle');

    for (const file of ['resources/js/Pages/Care/Index.vue', 'resources/js/Pages/Medicine/Index.vue', 'resources/js/Pages/Maternity/Index.vue']) {
        const source = fs.readFileSync(file, 'utf8');

        assert.match(source, /<ActivePassageBoard/, `${file} ne lit pas le tableau partagé`);
        assert.doesNotMatch(source, /const pendingAhead|useQueueSkipGuard/, `${file} garde une copie locale de la règle`);
    }
});

test('aucun bouton « Prendre en charge » ne poste sans passer par le garde-fou', () => {
    const board = fs.readFileSync(BOARD, 'utf8');

    // Le geste vient du serveur (`take_charge_url`) et passe toujours par le garde-fou :
    // directement, ou après la fenêtre du parcours (« Consulter quand même »).
    assert.equal((board.match(/skipGuard\.request\(/g) ?? []).length, 2);
    assert.equal((board.match(/postTakeCharge\(row, row\.actions\?\.take_charge_url\)/g) ?? []).length, 1);
    assert.match(board, /accept: takeCharge/);
    assert.doesNotMatch(board, /@click="takeCharge\(/);
    assert.doesNotMatch(board, /\/accept`/);
});

test('ADR-122, ADR-127 : « Remettre en file » est proposé seulement quand le serveur le permet', () => {
    const board = fs.readFileSync(BOARD, 'utf8');
    const sheet = fs.readFileSync('resources/js/Pages/Care/Show.vue', 'utf8');

    assert.match(board, /Remettre en file/);
    // Seul le soignant qui a pris le patient reçoit l'adresse : l'écran ne recalcule pas la règle.
    assert.match(board, /v-if="row\.actions\.release_url"/);
    assert.match(board, /router\.post\(row\.actions\.release_url/);
    assert.match(sheet, /Remettre en file/);
    assert.match(sheet, /\/release`/);
});

/**
 * ADR-177 — trois blocs qui ne se mélangent pas (en attente, en cours, terminés),
 * « En attente » en premier, et « Urgences » en filtre ; « Suggérés pour moi »
 * est un filtre du bloc « En attente ».
 */
test('le tableau des passages a ses blocs, « En attente » en premier', () => {
    const utility = fs.readFileSync('resources/js/utilities/activePassages.js', 'utf8');
    const board = fs.readFileSync(BOARD, 'utf8');
    const values = [...utility.matchAll(/\{ value: '([a-z_]+)', label: '/g)].map((match) => match[1]);

    assert.deepEqual(values, ['waiting', 'in_progress', 'completed', 'emergency']);
    assert.match(utility, /label: 'En attente'/);
    assert.match(utility, /label: 'En cours chez moi'/);
    assert.match(utility, /label: 'Terminés chez moi'/);
    assert.doesNotMatch(utility, /value: 'all'|value: 'requested'|Tous les passages/);
    assert.match(board, /Suggérés pour moi · \{\{ counts\.suggested/);
});

/** Un n° d'ordre n'existe que pour une vraie orientation qui attend : une suggestion n'est pas une place. */
test('le n° d’ordre est celui du serveur, jamais recalculé', () => {
    const board = fs.readFileSync(BOARD, 'utf8');

    assert.match(board, /v-if="row\.module\.queue_number"/);
    assert.doesNotMatch(board, /queueNumberOf|index \+ 1/);
    // Le n° 1 est le prochain patient, et le tableau le dit.
    assert.match(board, /row\.module\.queue_number === 1/);
    assert.match(board, />Prochain</);
});

