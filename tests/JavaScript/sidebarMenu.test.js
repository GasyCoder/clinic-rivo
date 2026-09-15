import test from 'node:test';
import assert from 'node:assert/strict';
import { activeMenuKeys, menuMatchDepth, pathCovers } from '../../resources/js/utilities/menuActivation.js';
import { normalizeOrder } from '../../resources/js/composables/useSidebarOrder.js';
import { CLINIC_WORKSPACES } from '../../resources/js/utilities/clinicWorkspaces.js';
import { buildClinicMenu, visibleMenu } from '../../resources/js/utilities/clinicMenu.js';

const workspaces = CLINIC_WORKSPACES.map((workspace) => ({
    ...workspace,
    link: workspace.resolveLink ? workspace.resolveLink(() => true) : workspace.link,
}));
const menu = [{ text: 'Vue d’ensemble', link: '/' }, ...workspaces];
const activeTexts = (path) => activeMenuKeys(menu, path).map((index) => menu[index].text);

test('une seule entrée est active, même quand plusieurs partagent un préfixe', () => {
    // Le défaut signalé : /reception/sorties allumait aussi « Réception ».
    assert.deepEqual(activeTexts('/reception/sorties'), ['Sorties & règlements']);
    assert.deepEqual(activeTexts('/reception/visitors'), ['Gardiennage']);
    assert.deepEqual(activeTexts('/reception'), ['Réception']);
    assert.deepEqual(activeTexts('/reception/patients'), ['Réception']);
});

test('chaque route clinique n’allume qu’un seul module', () => {
    for (const path of [
        '/', '/reception', '/reception/patients', '/reception/sorties',
        '/reception/visitors', '/cash', '/cash/uuid', '/receipts/3',
        '/patients', '/patients/abc', '/medicine', '/care', '/laboratory',
        '/maternity', '/surgery', '/anesthesia', '/pharmacy',
        '/pharmacy/counter-sales/create', '/administration',
        '/administration/users', '/administration/catalog',
        '/administration/analyses', '/logistics', '/trash',
    ]) {
        assert.equal(activeTexts(path).length, 1, `attendu 1 module actif pour ${path}`);
    }
});

test('la correspondance respecte les segments d’URL', () => {
    assert.equal(pathCovers('/reception/patients', '/reception'), true);
    assert.equal(pathCovers('/reception', '/reception'), true);
    // Un préfixe nu attraperait aussi cette URL d'un autre module.
    assert.equal(pathCovers('/reception-visiteurs', '/reception'), false);
});

test('« Vue d’ensemble » ne revendique que la racine', () => {
    assert.equal(menuMatchDepth({ link: '/' }, '/'), 1);
    assert.equal(menuMatchDepth({ link: '/' }, '/patients'), -1);
});

test('une entrée exacte ne revendique pas ses sous-pages', () => {
    const hr = { link: '/administration', exact: true };
    assert.equal(menuMatchDepth(hr, '/administration'), '/administration'.length);
    assert.equal(menuMatchDepth(hr, '/administration/users'), -1);
});

test('la requête est ignorée par la mise en évidence', () => {
    assert.deepEqual(activeTexts('/reception/sorties'.split('?')[0]), ['Sorties & règlements']);
});

/* ------------------------------------------------------------------ */
/* Ordre personnalisé de la barre latérale                             */
/* ------------------------------------------------------------------ */

const recommended = ['reception', 'settlements', 'patients', 'cash'];

test('un ordre enregistré est conservé tel quel', () => {
    assert.deepEqual(
        normalizeOrder(['cash', 'patients', 'reception', 'settlements'], recommended),
        ['cash', 'patients', 'reception', 'settlements'],
    );
});

test('un module nouveau est ajouté, jamais perdu', () => {
    // Préférence enregistrée avant l'arrivée de « settlements ».
    assert.deepEqual(
        normalizeOrder(['cash', 'reception', 'patients'], recommended),
        ['cash', 'reception', 'patients', 'settlements'],
    );
});

test('un module disparu ou interdit est retiré de l’ordre', () => {
    assert.deepEqual(
        normalizeOrder(['cash', 'module-supprime', 'reception'], recommended),
        ['cash', 'reception', 'settlements', 'patients'],
    );
});

test('un ordre corrompu retombe sur l’ordre recommandé', () => {
    assert.deepEqual(normalizeOrder(null, recommended), recommended);
    assert.deepEqual(normalizeOrder('nimporte quoi', recommended), recommended);
    assert.deepEqual(normalizeOrder([1, 2, 3], recommended), recommended);
});

test('un doublon enregistré ne duplique pas une entrée', () => {
    const result = normalizeOrder(['cash', 'cash', 'reception'], recommended);
    assert.deepEqual(result, ['cash', 'reception', 'settlements', 'patients']);
    assert.equal(new Set(result).size, result.length);
});

test('l’ordre contient toujours exactement les modules visibles', () => {
    const result = normalizeOrder(['patients'], recommended);
    assert.deepEqual([...result].sort(), [...recommended].sort());
});

/* ------------------------------------------------------------------ */
/* Construction du menu : aucun module ne peut disparaître             */
/* ------------------------------------------------------------------ */

// Le compte de la capture : Réception / Caisse, 4 modules.
const RECEPTION_PERMISSIONS = new Set([
    'reception.view', 'episodes.settlement.view', 'patients.view', 'cash.view',
]);
const canReception = (permission) => RECEPTION_PERMISSIONS.has(permission);

const rowsOf = (stored = {}) => visibleMenu(
    buildClinicMenu({ roleCode: 'RECEPTION', can: canReception, stored }),
    canReception,
).filter((row) => !row.heading && row.key);

test('un compte Réception voit ses quatre modules', () => {
    assert.deepEqual(rowsOf().map((row) => row.key).sort(),
        ['cash', 'patients', 'reception', 'settlements']);
});

test('réordonner ne fait jamais disparaître un module', () => {
    // Le bug signalé : après un glissement, deux modules sur quatre
    // n'étaient plus affichés.
    const orders = [
        ['cash', 'reception'],                                   // ordre partiel
        ['cash', 'reception', 'settlements', 'patients'],         // ordre complet
        ['patients'],                                             // un seul déplacé
        ['settlements', 'settlements', 'cash'],                   // doublon
        ['module-disparu', 'cash'],                               // clé obsolète
        [],                                                       // vide
    ];

    for (const clinical of orders) {
        const rows = rowsOf({ clinical });
        assert.deepEqual(rows.map((row) => row.key).sort(),
            ['cash', 'patients', 'reception', 'settlements'],
            `modules perdus pour l’ordre ${JSON.stringify(clinical)}`);
        assert.equal(new Set(rows.map((row) => row.key)).size, rows.length, 'module dupliqué');
    }
});

test('l’ordre enregistré est bien celui affiché', () => {
    const rows = rowsOf({ clinical: ['cash', 'reception', 'patients', 'settlements'] });
    assert.deepEqual(rows.map((row) => row.key), ['cash', 'reception', 'patients', 'settlements']);
});

test('chaque module garde sa propre icône et son propre lien après réordonnancement', () => {
    // Le symptôme de la capture : des lignes portant l'icône d'une autre.
    const reference = new Map(rowsOf().map((row) => [row.key, row]));
    const shuffled = rowsOf({ clinical: ['patients', 'cash', 'settlements', 'reception'] });

    for (const row of shuffled) {
        assert.equal(row.icon, reference.get(row.key).icon, `icône incorrecte pour ${row.key}`);
        assert.equal(row.link, reference.get(row.key).link, `lien incorrect pour ${row.key}`);
        assert.equal(row.text, reference.get(row.key).text);
    }
});

test('un ordre enregistré ne peut pas révéler un module interdit', () => {
    const rows = rowsOf({ clinical: ['surgery', 'pharmacy', 'cash'] });

    assert.equal(rows.some((row) => row.key === 'surgery'), false);
    assert.equal(rows.some((row) => row.key === 'pharmacy'), false);
});

test('une clé de ligne est unique et stable', () => {
    const rowKey = (item) => (item.heading ? `heading:${item.heading}` : `row:${item.key ?? item.link ?? item.text}`);
    const menu = visibleMenu(buildClinicMenu({ roleCode: 'RECEPTION', can: canReception }), canReception);
    const keys = menu.map(rowKey);

    assert.equal(new Set(keys).size, keys.length, 'deux lignes partagent la même clé');
});

test('chaque ligne du menu porte un composant d’icône, jamais un nom', () => {
    // « Vue d’ensemble » était construite avec `icon: 'growth'`, une chaîne
    // héritée du jeu DashWind : <component :is> ne la résout pas et la ligne
    // s’affichait sans aucune icône.
    const rows = visibleMenu(buildClinicMenu({ roleCode: 'RECEPTION', can: canReception }), canReception)
        .filter((row) => !row.heading);

    assert.ok(rows.length > 0);

    for (const row of rows) {
        assert.equal(
            typeof row.icon === 'string',
            false,
            `${row.text} porte une icône en chaîne (${row.icon}) au lieu d’un composant`,
        );
        assert.ok(row.icon, `${row.text} n’a aucune icône`);
    }
});

test('deux modules ne partagent jamais la même icône', async () => {
    // Médecine, Laboratoire et Catalogue analyses portaient toutes trois
    // `Activity` : dans une colonne étroite, deux lignes au même repère se
    // confondent, et l'icône cesse d'aider à viser la bonne.
    const lucide = await import('lucide-vue-next');
    const nameOf = (component) => Object.keys(lucide).find((key) => lucide[key] === component) ?? '?';
    const byIcon = new Map();

    for (const workspace of CLINIC_WORKSPACES) {
        const icon = nameOf(workspace.icon);
        byIcon.set(icon, [...(byIcon.get(icon) ?? []), workspace.text]);
    }

    const shared = [...byIcon].filter(([, modules]) => modules.length > 1);

    assert.deepEqual(shared, [], shared.map(([icon, m]) => `${icon} : ${m.join(' / ')}`).join(' | '));
});
