import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/**
 * ADR-125 — les constantes se lisent selon l'âge, et une valeur critique ou
 * improbable se dit par un message toast, une seule fois par repère.
 */
const sheet = fs.readFileSync('resources/js/Pages/Care/Show.vue', 'utf8');

test('la tension, la fréquence et la température se lisent selon l’âge servi par le serveur', () => {
    assert.match(sheet, /reference\.minor/);
    assert.match(sheet, /reference\.hypotension_systolic_below/);
    assert.match(sheet, /reference\.child_very_high/);
    assert.match(sheet, /reference\.age_range/);
    assert.match(sheet, /reference\.marked_high_factor/);
    assert.match(sheet, /reference\.infant/);
    // Aucun seuil d'âge n'est recopié dans l'écran : tout vient de la référence.
    assert.doesNotMatch(sheet, /70 \+ 2 \*|heartRateRange|\[100, 180\]/);
});

test('une valeur critique ou improbable déclenche un toast, posé puis dédoublonné', () => {
    assert.match(sheet, /useToastStore\(\)/);
    assert.match(sheet, /watchForToast\('bp'/);
    assert.match(sheet, /watchForToast\('hr'/);
    assert.match(sheet, /watchForToast\('spo2'/);
    assert.match(sheet, /watchForToast\('temp'/);
    assert.match(sheet, /watchForToast\('implausible'/);
    // Posé : pas à chaque chiffre ; dédoublonné : pas deux fois pour le même repère.
    assert.match(sheet, /setTimeout\(\(\) => \{[\s\S]*?\}, 900\)/);
    assert.match(sheet, /vitalToasted\.get\(key\) === alert\.code/);
});

test('seul un repère critique (danger) fait un toast ; un simple avertissement reste sous son champ', () => {
    assert.match(sheet, /assessment\?\.tone === 'danger'/);
});

test('ADR-126 : tabac et alcool se lisent selon l’âge, « Oui » en rouge et « Non » en vert', () => {
    assert.match(sheet, /const substanceAlert = \(value, name\)/);
    assert.match(sheet, /limits\.substance_unlikely_below/);
    assert.match(sheet, /watchForToast\('smoker'/);
    assert.match(sheet, /watchForToast\('alcohol'/);
    // Les trois groupes Oui/Non passent par la même règle de couleur.
    assert.equal((sheet.match(/yesNoClasses\(form\./g) ?? []).length, 3);
    assert.match(sheet, /bg-red-50 text-red-700/);
    assert.match(sheet, /bg-emerald-50 text-emerald-700/);
});

test('ADR-126 : un âge qu’aucun patient n’atteint se signale, sans rien deviner', () => {
    assert.match(sheet, /const veryOldAlert = computed/);
    assert.match(sheet, /age < limits\.very_old_from/);
    assert.match(sheet, /onMounted\(\(\) => \{\s*if \(veryOldAlert\.value\)/);
});
