import test from 'node:test';
import assert from 'node:assert/strict';
import {
    clampOffset, cropQuality, displaySize, initialOffset, normalizeRotation, rotatedSize, sourceSquare, zoomAround, zoomAtPoint,
} from '../../resources/js/utilities/photoCrop.js';

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

test('zoomer sous le pointeur garde le point visé sous le pointeur', () => {
    // Sur un carré 600 × 600 dans un cadre de 300, le coin haut-gauche visé reste en place.
    const { zoom, offset } = zoomAtPoint({ x: 0, y: 0 }, 600, 600, 300, 1, 2, { x: 0, y: 0 });
    assert.equal(zoom, 2);
    assert.deepEqual(offset, { x: 0, y: 0 });
    // Visé au centre, c'est exactement zoomAround.
    assert.deepEqual(
        zoomAtPoint({ x: 0, y: 0 }, 600, 600, 300, 1, 2, { x: 150, y: 150 }),
        zoomAround({ x: 0, y: 0 }, 600, 600, 300, 1, 2),
    );
});

test('un quart de tour échange largeur et hauteur, et les angles se ramènent à 0–270', () => {
    assert.deepEqual(rotatedSize(900, 1200, 0), { width: 900, height: 1200 });
    assert.deepEqual(rotatedSize(900, 1200, 90), { width: 1200, height: 900 });
    assert.deepEqual(rotatedSize(900, 1200, 180), { width: 900, height: 1200 });
    assert.deepEqual(rotatedSize(900, 1200, -90), { width: 1200, height: 900 });
    assert.equal(normalizeRotation(-90), 270);
    assert.equal(normalizeRotation(450), 90);
    assert.equal(normalizeRotation(360), 0);
});

test('la qualité dit ce que vaudra la photo envoyée, sans jamais l’agrandir', () => {
    assert.deepEqual(cropQuality(1800), { pixels: 600, level: 'excellent' });
    assert.deepEqual(cropQuality(420.4), { pixels: 420, level: 'good' });
    assert.deepEqual(cropQuality(200), { pixels: 200, level: 'low' });
});
