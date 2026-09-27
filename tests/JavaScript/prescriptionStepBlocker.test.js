import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Medicine/Show.vue', 'utf8');
const bar = fs.readFileSync('resources/js/Components/Medicine/ConsultationStepBar.vue', 'utf8');

/**
 * Ce qui bloque la validation de l'étape Prescription se dit dans un toast,
 * au moment du clic — jamais en consigne imprimée sous le bouton.
 */

test('aucune consigne inline ne remplace le toast', () => {
    const start = page.indexOf(`<template v-if="current_step === 'ordonnance'" #note>`);
    assert.notEqual(start, -1, 'le slot note de l’étape Prescription a disparu');

    const slot = page.slice(start, page.indexOf('</template>', start));

    assert.doesNotMatch(slot, /en préparation/);
    assert.doesNotMatch(slot, /Valider et réserver/);
    assert.doesNotMatch(slot, /retirez-les/);
});

test('la barre prévient par un toast', () => {
    assert.match(page, /@blocked="toast\.warning\(\$event\)"/);
    assert.match(page, /const toast = useToastStore\(\);/);
});

/**
 * Un obstacle local n'éteint plus le bouton : il l'explique. Griser une
 * commande sans rien dire est ce que cette correction supprime.
 */
test('le bouton reste cliquable et émet la raison', () => {
    const submit = bar.slice(bar.indexOf('const submitComplete'));
    const body = submit.slice(0, submit.indexOf('\n};'));

    assert.match(body, /emit\('blocked', props\.localBlocker\)/);
});

test('seul un obstacle du serveur désactive encore le bouton', () => {
    assert.match(bar, /:disabled="Boolean\(state\?\.blocker\) \|\| completeForm\.processing"/);
});

/** Le blocage lui-même reste côté logique. */
test('une ligne en préparation empêche toujours de valider l’étape', () => {
    const blocker = page.slice(page.indexOf('const pendingPrescriptionSelection'));
    const body = blocker.slice(0, blocker.indexOf('});'));

    assert.match(body, /prescriptionForm\.lines\.length/);
    assert.match(body, /careOrderForm\.items\.length/);
});
