import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/**
 * ADR-123 — « Terminer » : ce que le soignant enregistre à gauche, ce qu'il
 * transmet à droite, séparés par une barre que l'on glisse.
 */
const sheet = fs.readFileSync('resources/js/Pages/Care/Show.vue', 'utf8');
const split = fs.readFileSync('resources/js/Components/UI/ResizableSplit.vue', 'utf8');

test('l’étape « Terminer » utilise le séparateur redimensionnable partagé', () => {
    assert.match(sheet, /import ResizableSplit from '@\/Components\/UI\/ResizableSplit\.vue'/);
    assert.match(sheet, /<ResizableSplit[\s\S]*?storage-key="rivo:care:finish-split"/);
    assert.match(sheet, /<template #start>/);
    assert.match(sheet, /<template #end>/);
});

test('sans transmission ni point de vigilance, un seul panneau : ni barre, ni panneau vide', () => {
    assert.match(sheet, /:single="!hasSideColumn"/);
    assert.match(split, /single: \{ type: Boolean, default: false \}/);
    assert.match(split, /v-if="!single"\s+class="rs-handle"/);
});

test('la transmission est en texte riche, pas en zone de texte brute', () => {
    assert.match(sheet, /<ClinicalRichTextEditor[\s\S]*?id="transmission_reason"/);
    assert.doesNotMatch(sheet, /<textarea id="(diagnostic_note|transmission_reason)"/);
});

test('les points de vigilance sont épinglés en bas du panneau de gauche, repliés en pastilles', () => {
    const start = sheet.indexOf('<template #start>');
    const end = sheet.indexOf('<template #end>');
    const warnings = sheet.indexOf('id="care-warnings-title"');

    // Ni dans la colonne de transmission, ni éparpillés : dans le panneau de gauche, en bas.
    assert.ok(warnings > start && warnings < end, 'les points de vigilance ne sont pas dans le panneau de gauche');
    assert.match(sheet, /class="mt-auto space-y-2 border-t border-border pt-3"/);
    // Tous restent visibles : seul leur texte se replie, le détail est à un clic.
    assert.match(sheet, /warningTitle\(warning\)/);
    assert.match(sheet, /Voir le détail/);
});

test('sans transmission, le récapitulatif garde toute la largeur même avec des points de vigilance', () => {
    assert.match(sheet, /const hasSideColumn = computed\(\(\) => Boolean\(props\.orientation\.episode\.care_transmission_expected\)\)/);
});
