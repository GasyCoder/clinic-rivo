import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * ADR-182 — « aujourd'hui » se calcule à l'heure du poste. `toISOString()` donne
 * la veille entre 0 h et 3 h à Madagascar : interdit dans les écrans Pharmacie et RH.
 */
const roots = ['resources/js/Components/Pharmacy', 'resources/js/Pages/Pharmacy', 'resources/js/Components/Administration', 'resources/js/Pages/Administration'];

const files = (dir) => readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);

    return statSync(path).isDirectory() ? files(path) : path.endsWith('.vue') ? [path] : [];
});

test('Pharmacy and HR screens never take today from toISOString()', () => {
    const offenders = roots.flatMap(files).filter((path) => /new Date\(\)\.toISOString\(\)/.test(readFileSync(path, 'utf8')));

    assert.deepEqual(offenders, []);
});
