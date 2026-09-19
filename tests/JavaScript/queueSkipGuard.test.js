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

test('les Soins et la Médecine passent tous deux par le même garde-fou, sans copie locale', () => {
    for (const file of ['resources/js/Pages/Care/Index.vue', 'resources/js/Pages/Medicine/Index.vue']) {
        const source = fs.readFileSync(file, 'utf8');

        assert.match(source, /useQueueSkipGuard\(/, `${file} n’utilise pas le garde-fou partagé`);
        assert.match(source, /<QueueSkipConfirm/, `${file} n’affiche pas la confirmation partagée`);
        assert.doesNotMatch(source, /const pendingAhead/, `${file} garde une copie locale de la règle`);
    }
});

test('aucun bouton « Prendre en charge » des Soins ne poste sans passer par le garde-fou', () => {
    const source = fs.readFileSync('resources/js/Pages/Care/Index.vue', 'utf8');

    assert.doesNotMatch(source, /:href="`\/care\/orientations\/\$\{[^}]+\}\/accept`"/);
    assert.equal((source.match(/skipGuard\.request\(/g) ?? []).length, 3);
});

test('ADR-122 : la file et la fiche Soins proposent « Remettre en file », par un POST vers /release', () => {
    const queue = fs.readFileSync('resources/js/Pages/Care/Index.vue', 'utf8');
    const sheet = fs.readFileSync('resources/js/Pages/Care/Show.vue', 'utf8');

    for (const source of [queue, sheet]) {
        assert.match(source, /Remettre en file/);
        assert.match(source, /\/release`/);
    }
    // Seul le soignant qui a pris le patient le voit dans la file.
    assert.match(queue, /orientation\.accepted_by_id === page\.props\.auth\?\.user\?\.id/);
});

test('ADR-124 : la file Soins n’a que deux onglets, sans les patients déjà accueillis par le médecin', () => {
    const queue = fs.readFileSync('resources/js/Pages/Care/Index.vue', 'utf8');

    assert.match(queue, /role="tablist"/);
    assert.match(queue, /value: 'active', label: 'À prendre aux Soins'/);
    assert.match(queue, /value: 'waiting_doctor', label: 'Orientés · en attente du médecin'/);
    assert.doesNotMatch(queue, /with_doctor|value: 'finished'|filter:oriented/);
});

test('ADR-124 : un patient en attente du médecin garde son n° d’ordre — celui de la file du médecin', () => {
    const queue = fs.readFileSync('resources/js/Pages/Care/Index.vue', 'utf8');

    assert.match(queue, /const queueNumberOf = \(orientation\) => orientation\.queue_number \?\? \(isWaitingDoctor\(orientation\) \? orientation\.doctor\.queue_number : null\)/);
    // Les deux vues (tableau et carte) affichent le n° par la même fonction.
    assert.equal((queue.match(/queueNumberOf\(group\.orientations\[0\]\)\) class=/g) ?? []).length + (queue.match(/queueNumberOf\(group\.orientations\[0\]\)"/g) ?? []).length >= 2, true);
    assert.doesNotMatch(queue, /group\.orientations\[0\]\.queue_number/);
});
