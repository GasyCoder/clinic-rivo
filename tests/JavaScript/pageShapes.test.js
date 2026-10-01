import test from 'node:test';
import assert from 'node:assert/strict';
import {
    compactShape,
    MAX_SHAPE_HEIGHT,
    MAX_STORED_SHAPES,
    pageShapeKey,
    readShapes,
    rememberShape,
    shapeFor,
    SHAPES_STORAGE_KEY,
    writeShapes,
} from '../../resources/js/utilities/pageShapes.js';

const bone = (y, extra = {}) => ({ x: 2, y, w: 40, h: 16, r: 6, ...extra });
const snapshot = (width = 1200, bones = [bone(0), bone(30), bone(60)]) => ({ width, height: 400, bones });

/** Deux dossiers différents ont la même forme : l'identifiant ne compte pas. */
test('a shape belongs to the address pattern, not to one record', () => {
    assert.equal(pageShapeKey('/patients/9f3c2a10-1b2c-4d5e-8f90-123456789abc'), '/patients/:id');
    assert.equal(pageShapeKey('/laboratory/requests/42?view=to_send#x'), '/laboratory/requests/:id');
    assert.equal(pageShapeKey('/passages/A-26-0009-01/dossier-medical'), '/passages/:id/dossier-medical');
    assert.equal(pageShapeKey('/super-admin/sites/A/rh'), '/super-admin/sites/A/rh', 'un code de site sans chiffre reste lui-même');
    assert.equal(pageShapeKey('/patients?status=open'), '/patients');
    assert.equal(pageShapeKey(''), '/');
});

/** On ne garde que ce qui se dessine, et jamais une page vide. */
test('a snapshot is reduced to drawable blocks, cards kept apart', () => {
    const shape = compactShape(snapshot(1200, [
        bone(0, { c: true, h: 200, w: 100 }),
        bone(10), bone(40), bone(70),
        bone(90, { h: 1 }),               // texte réservé aux lecteurs d'écran
        bone(MAX_SHAPE_HEIGHT + 10),      // bien plus bas que l'écran
    ]), 1);

    assert.equal(shape.width, 1200);
    assert.equal(shape.bones.length, 4);
    assert.deepEqual(shape.bones[0], [2, 0, 100, 200, 6, true], 'la carte garde sa marque');
    assert.deepEqual(shape.bones[1], [2, 10, 40, 16, 6]);
    assert.equal(compactShape(snapshot(1200, [bone(0), bone(20)])), null, 'trop peu de contenu : pas une forme');
    assert.equal(compactShape({ width: 0, height: 10, bones: [] }), null);
    assert.ok(compactShape({ ...snapshot(), height: 9000 }).height <= MAX_SHAPE_HEIGHT);
});

/** Une forme prise sur ordinateur ne sert pas sur téléphone. */
test('a shape is reused only at a comparable width', () => {
    let store = rememberShape({}, '/patients', compactShape(snapshot(1200), 1));
    store = rememberShape(store, '/patients', compactShape(snapshot(380), 2));

    assert.equal(shapeFor(store, '/patients', 1180).width, 1200);
    assert.equal(shapeFor(store, '/patients', 390).width, 380);
    assert.equal(shapeFor(store, '/patients', 800), null, 'aucune largeur comparable : forme générique');
    assert.equal(shapeFor(store, '/caisse', 1200), null);

    store = rememberShape(store, '/patients', compactShape(snapshot(1210), 3));
    assert.equal(store['/patients'].length, 2, 'la nouvelle photo remplace l\'ancienne à la même largeur');
    assert.equal(shapeFor(store, '/patients', 1200).at, 3);
});

test('the store stays bounded, oldest screens leave first', () => {
    let store = {};
    for (let index = 0; index < MAX_STORED_SHAPES + 5; index += 1) {
        store = rememberShape(store, `/ecran-${index}`, compactShape(snapshot(), index));
    }

    assert.equal(Object.keys(store).length, MAX_STORED_SHAPES);
    assert.ok(! store['/ecran-0']);
    assert.ok(store[`/ecran-${MAX_STORED_SHAPES + 4}`]);
});

/** Stockage refusé ou abîmé : jamais une erreur, seulement pas de forme. */
test('storage failures never break the page', () => {
    const memory = new Map();
    const storage = { getItem: (k) => memory.get(k) ?? null, setItem: (k, v) => memory.set(k, v) };

    writeShapes(storage, { '/patients': [] });
    assert.deepEqual(readShapes(storage), { '/patients': [] });

    memory.set(SHAPES_STORAGE_KEY, '{abîmé');
    assert.deepEqual(readShapes(storage), {});

    const refused = { getItem: () => { throw new Error('refusé'); }, setItem: () => { throw new Error('plein'); } };
    assert.deepEqual(readShapes(refused), {});
    assert.doesNotThrow(() => writeShapes(refused, {}));
    assert.deepEqual(readShapes(null), {});
});
