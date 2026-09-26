import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const icon = fs.readFileSync('resources/js/Components/Shadcn/RefreshIcon.vue', 'utf8');
const list = fs.readFileSync('resources/js/Components/Webmail/MessageList.vue', 'utf8');

/** Le code seul : les commentaires citent les mêmes termes. */
const code = (source) => source
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/.*$/gm, '$1')
    .replace(/<!--[\s\S]*?-->/g, '');

const vueFiles = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return vueFiles(full);

    return entry.name.endsWith('.vue') ? [full] : [];
});

/** Une réponse en 80 ms ne doit pas se lire comme un clic sans effet. */
test('l’icône s’arrête à la fin d’un tour, jamais au milieu', () => {
    const source = code(icon);
    assert.match(source, /@animationiteration="onTurn"/);
    assert.match(source, /const onTurn = \(\) => \{\s*if \(stopping\) stop\(\);/);
    // Arrêter la relecture ne coupe pas la rotation : elle attend la fin du tour.
    assert.match(source, /stopping = true;/);
    assert.doesNotMatch(source, /if \(!spinning\)[^}]*turning\.value = false/);
});

/** Sans tour qui se termine (animations réduites), la minuterie la libère. */
test('un filet libère l’icône quand aucun tour ne se termine', () => {
    assert.match(code(icon), /fallback = setTimeout\(stop, TURN_MS \+ \d+\)/);
});

test('la durée d’un tour n’est écrite qu’une fois', () => {
    const source = code(icon);
    assert.match(source, /animation: `rivo-refresh-turn \$\{TURN_MS\}ms linear infinite`/);
    assert.doesNotMatch(source, /rivo-refresh-turn_\d/);
    assert.match(fs.readFileSync('resources/css/shadcn.css', 'utf8'), /@keyframes rivo-refresh-turn/);
});

/**
 * Chaque bouton d'actualisation tournait « à sa façon » : l'un sur un état
 * que le rechargement ne posait jamais (il ne bougeait pas), l'autre pas du
 * tout. Tous passent désormais par RefreshIcon.
 */
test('aucune icône d’actualisation ne tourne hors de RefreshIcon', () => {
    const offenders = vueFiles('resources/js')
        .filter((file) => !file.endsWith('RefreshIcon.vue'))
        .filter((file) => /<(RefreshCw|RefreshCcw|RotateCw)\b[^>]*animate-spin/.test(fs.readFileSync(file, 'utf8')));

    assert.deepEqual(offenders, []);
});

test('les deux « Actualiser » de la messagerie tournent pendant la relecture', () => {
    const source = code(list);
    assert.equal((source.match(/<RefreshIcon :spinning="refreshing"/g) ?? []).length, 2);
    assert.match(source, /const refresh = \(\) => \{\s*refreshing\.value = true;/);
    assert.match(source, /onFinish: \(\) => \{ refreshing\.value = false; \}/);
    assert.doesNotMatch(source, /processing && 'animate-spin'/);
});
