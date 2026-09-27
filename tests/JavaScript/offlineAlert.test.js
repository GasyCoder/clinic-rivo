import test from 'node:test';
import assert from 'node:assert/strict';
import {
    isOfflineAlertAcknowledged,
    rememberOfflineAlert,
} from '../../resources/js/utilities/offlineAlert.js';

const memoryStorage = () => {
    const values = new Map();

    return {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: (key) => values.delete(key),
    };
};

test('l’acquittement survit aux actualisations et aux changements de sites en panne', () => {
    const storage = memoryStorage();
    const key = 'rivo:super-admin:offline-alert:user-a';
    rememberOfflineAlert(storage, key);

    assert.equal(storage.getItem(key), 'acknowledged');
    assert.equal(isOfflineAlertAcknowledged(storage, key), true);
    assert.equal(isOfflineAlertAcknowledged(storage, 'rivo:super-admin:offline-alert:user-b'), false);
});

test('un ancien acquittement lié aux sites reste valable après la migration', () => {
    const storage = memoryStorage();
    const key = 'rivo:super-admin:offline-alert:user-a';
    storage.setItem(key, 'B|M');

    assert.equal(isOfflineAlertAcknowledged(storage, key), true);
});

test('le stockage désactivé ne casse ni l’alerte ni l’acquittement courant', () => {
    const unavailable = {
        getItem: () => { throw new Error('Storage blocked'); },
        setItem: () => { throw new Error('Storage blocked'); },
        removeItem: () => { throw new Error('Storage blocked'); },
    };

    assert.equal(isOfflineAlertAcknowledged(unavailable, 'key'), false);
    assert.doesNotThrow(() => rememberOfflineAlert(unavailable, 'key'));
});
