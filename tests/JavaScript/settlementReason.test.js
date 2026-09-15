import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Reception/Settlements/Index.vue', 'utf8');

/**
 * Le motif de sortie administrative.
 *
 * Il devait être tapé pour chaque sortie, y compris le cas courant d'un
 * compte soldé où les chiffres disent tout. Il est désormais composé par le
 * serveur, après le verrou — ce que l'écran montre n'est qu'un aperçu.
 */

test('le motif n’est plus présenté comme obligatoire', () => {
    const label = page.slice(page.indexOf('<FormLabel for="exit_reason">'));
    const block = label.slice(0, label.indexOf('</FormLabel>'));

    assert.match(block, /généré automatiquement/);
    assert.doesNotMatch(block, /text-red-500/);
});

/**
 * L'aperçu est un placeholder, jamais une valeur soumise : figer ici des
 * montants les laisserait contredire le compte entre l'affichage et le clic
 * — le même piège que les tarifs envoyés par le navigateur (ADR-028).
 */
test('l’aperçu ne remplit jamais le champ envoyé', () => {
    assert.match(page, /:placeholder="generatedReasonHint"/);
    assert.doesNotMatch(page, /form\.reason = generatedReasonHint/);
    assert.doesNotMatch(page, /form\.reason =\s*`/);
});

test('l’aperçu se tait quand le compte est inconnu', () => {
    const hint = page.slice(page.indexOf('const generatedReasonHint'));
    const body = hint.slice(0, hint.indexOf('});'));

    assert.match(body, /if \(!amounts\)/);
});
