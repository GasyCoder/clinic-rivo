import test from 'node:test';
import assert from 'node:assert/strict';
import { clampOffset, displaySize, initialOffset, sourceSquare, zoomAround } from '../../resources/js/utilities/photoCrop.js';

test('une photo en portrait couvre le cadre et démarre un peu haute', () => {
    const size = displaySize(600, 900, 300, 1);
    assert.deepEqual(size, { width: 300, height: 450 });
    const offset = initialOffset(600, 900, 300);
    assert.equal(offset.x, 0);
    assert.equal(offset.y, -45);
});

test('l’image ne laisse jamais de bande vide dans le cadre', () => {
    assert.deepEqual(clampOffset({ x: 40, y: -999 }, 600, 900, 300, 1), { x: 0, y: -150 });
});

test('zoomer garde le centre du cadre et reste dans les bornes', () => {
    // Au zoom 2, le centre du cadre (150, 150) reste le centre de l'image.
    const { zoom, offset } = zoomAround({ x: 0, y: 0 }, 600, 600, 300, 1, 2);
    assert.equal(zoom, 2);
    assert.deepEqual(offset, { x: -150, y: -150 });
    assert.equal(zoomAround({ x: 0, y: 0 }, 600, 600, 300, 1, 9).zoom, 3);
});

test('le carré découpé correspond exactement à ce que montre le cadre', () => {
    // Zoom 1 sur un carré : toute l'image.
    assert.deepEqual(sourceSquare({ x: 0, y: 0 }, 600, 600, 300, 1), { x: 0, y: 0, side: 600 });
    // Zoom 2, décalé : un quart de l'image, au bon endroit.
    assert.deepEqual(sourceSquare({ x: -150, y: -300 }, 600, 600, 300, 2), { x: 150, y: 300, side: 300 });
});
