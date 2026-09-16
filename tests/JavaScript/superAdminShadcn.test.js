import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);

    return entry.isDirectory() ? walk(full) : (full.endsWith('.vue') ? [full] : []);
});

const PAGES = walk('resources/js/Pages/SuperAdmin');
const read = (file) => fs.readFileSync(file, 'utf8');
const label = (file) => file.replace('resources/js/Pages/SuperAdmin/', '');

/** Le portail compte une trentaine d'écrans : la garde porte sur tous. */
test('le portail Super Admin couvre bien toutes ses pages', () => {
    assert.ok(PAGES.length >= 35, `seulement ${PAGES.length} pages trouvées`);
});

/** ADR-099 : shadcn-vue est le design system, DashWind un reliquat. */
test('aucune page du portail n’importe plus un composant DashWind', () => {
    for (const file of PAGES) {
        const source = read(file);

        for (const dashwind of ['UI/Icon.vue', 'UI/Button.vue', 'UI/Badge.vue', 'UI/Input.vue']) {
            assert.ok(! source.includes(dashwind), `${label(file)} importe encore ${dashwind}`);
        }
    }
});

/** La police d'icônes `ni ni-*` ne doit plus être rendue nulle part. */
test('aucune page du portail ne rend plus la police d’icônes', () => {
    for (const file of PAGES) {
        assert.doesNotMatch(read(file), /<Icon[ />]/, `${label(file)} rend encore <Icon>`);
    }
});

/**
 * Une couleur codée en dur ne suit pas le thème : c'est le passage aux
 * tokens sémantiques qui fait fonctionner le mode sombre sans empiler des
 * variantes `dark:` sur chaque élément.
 */
test('le portail utilise les tokens sémantiques', () => {
    // `slate-950` reste légitime : c'est le voile des fenêtres modales.
    const palette = /(text|bg|border|divide|ring)-(slate|gray)-(50|100|200|300|400|500|600|700|800|900|1000)\b/;

    for (const file of PAGES) {
        assert.doesNotMatch(read(file), palette, `${label(file)} porte encore la palette DashWind`);
    }
});

/**
 * Les substitutions de palette ont produit deux fois des classes mortes —
 * une fraction doublée (`bg-primary-50/30` → `bg-primary/10/30`) et un
 * `gray-1000` coupé en `gray-100` + `0`. Ni l'une ni l'autre ne fait
 * échouer le build : elles ne se voient qu'à l'écran.
 */
test('aucune classe n’a été cassée par une substitution', () => {
    const broken = /(muted|primary|card|border|foreground)\d|\/(5|10|15|20|25|30|40|50|60)\/\d/;

    for (const file of PAGES) {
        assert.doesNotMatch(read(file), broken, `${label(file)} porte une classe cassée`);
    }
});

/**
 * Plusieurs icônes sont choisies par le serveur ou par une table locale et
 * arrivent donc en **chaîne**. Rendues telles quelles, Vue chercherait une
 * balise HTML de ce nom et n'afficherait rien.
 */
test('une icône dynamique passe toujours par la table de correspondance', () => {
    for (const file of PAGES) {
        const source = read(file);
        // Une icône peut légitimement être un composant lucide affecté
        // directement (`icon: User`) : seul un **nom** rendu tel quel est
        // un défaut, et il ne se voit qu'à l'écran.
        const hasStringIcons = /\bicon: '[a-z0-9-]+'/.test(source);
        const rendersRaw = /:is="[a-z][A-Za-z_]*\.(icon|category_icon)"/.test(source);

        assert.ok(
            ! (hasStringIcons && rendersRaw),
            `${label(file)} rend un nom d’icône comme composant`,
        );
    }

    const map = fs.readFileSync('resources/js/lib/icons.js', 'utf8');
    // Les noms que le serveur envoie réellement (Corbeille, Sites, RH…).
    for (const name of ['activity', 'bag', 'card-view', 'grid-alt', 'growth', 'history',
        'list-index', 'reports', 'setting-alt', 'shield-check', 'trash', 'user-list', 'wallet']) {
        assert.match(map, new RegExp(`'?${name}'?:`), `nom « ${name} » absent de la table`);
    }
});

/** Une taille de police ne dimensionne pas un SVG. */
test('aucune icône n’est dimensionnée par une taille de police', () => {
    for (const file of PAGES) {
        assert.doesNotMatch(
            read(file),
            /<component\s+class="[^"]*text-[3-6]?xl/,
            `${label(file)} dimensionne une icône avec une taille de police`,
        );
    }
});

/**
 * `PageHeader`, `IconInput` et `Card` sont partagés avec les écrans
 * cliniques : ils quittent DashWind sans changer de contrat, pour ne pas
 * imposer de retoucher — donc de modifier sans les vérifier — des écrans
 * hors du périmètre demandé.
 */
test('les composants partagés migrent sans changer d’API', () => {
    const header = fs.readFileSync('resources/js/Components/UI/PageHeader.vue', 'utf8');
    const iconInput = fs.readFileSync('resources/js/Components/UI/IconInput.vue', 'utf8');
    const card = fs.readFileSync('resources/js/Components/UI/Card.vue', 'utf8');

    for (const source of [header, iconInput, card]) {
        assert.ok(! source.includes('UI/Icon.vue'));
    }

    assert.match(header, /icon: \{ type: String, default: 'users' \}/);
    assert.match(header, /lucideIcon/);
    assert.match(iconInput, /icon: \{ type: String, required: true \}/);
    assert.match(iconInput, /lucideIcon/);
    assert.match(card, /bg-card border-border/);
});
