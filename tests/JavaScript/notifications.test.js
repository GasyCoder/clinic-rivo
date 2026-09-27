import test from 'node:test';
import assert from 'node:assert/strict';
import { groupNotificationsByDay, notificationTime, unreadLabel } from '../../resources/js/utilities/notifications.js';

const now = new Date(2026, 8, 26, 15, 0, 0);
const at = (days, hours = 10) => new Date(2026, 8, 26 - days, hours, 30).toISOString();

test('notifications are grouped by day, newest first, empty groups hidden', () => {
    const items = [
        { id: 'a', created_at: at(0) },
        { id: 'b', created_at: at(1) },
        { id: 'c', created_at: at(3) },
        { id: 'd', created_at: at(30) },
    ];

    assert.deepEqual(groupNotificationsByDay(items, now).map((group) => [group.label, group.items.map((item) => item.id)]), [
        ['Aujourd’hui', ['a']],
        ['Hier', ['b']],
        ['Cette semaine', ['c']],
        ['Plus anciennes', ['d']],
    ]);
    assert.deepEqual(groupNotificationsByDay([{ id: 'a', created_at: at(0) }], now).map((group) => group.key), ['today']);
    assert.deepEqual(groupNotificationsByDay([], now), []);
});

test('the time of a notification reads naturally', () => {
    assert.equal(notificationTime(new Date(now.getTime() - 20 * 1000).toISOString(), now), 'à l’instant');
    assert.equal(notificationTime(new Date(now.getTime() - 12 * 60 * 1000).toISOString(), now), 'il y a 12 min');
    assert.equal(notificationTime(at(0), now), '10:30');
    assert.equal(notificationTime(at(1), now), 'hier, 10:30');
    assert.equal(notificationTime(at(5), now), '21/09 · 10:30');
    assert.equal(notificationTime(null, now), '');
});

test('the unread count is said in words', () => {
    assert.equal(unreadLabel(0), 'Aucune non lue');
    assert.equal(unreadLabel(1), '1 non lue');
    assert.equal(unreadLabel(4), '4 non lues');
});

test('ADR-199 — a notification whose task is done says so, on the page and in the bell', async () => {
    const { readFileSync } = await import('node:fs');
    for (const path of ['resources/js/Pages/Notifications/Index.vue', 'resources/js/Components/Layout/HeaderNotifications.vue']) {
        const source = readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
        assert.match(source, /item\.resolution\?\.state === 'done' \? CheckCircle2/);
        assert.match(source, /\{\{ item\.resolution\.label \}\}/);
    }
});
