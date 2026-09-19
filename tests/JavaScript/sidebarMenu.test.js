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
    // Signalé le 2026-09-16 : « Patients » et « Sorties & règlements »
    // paraissaient sélectionnés ensemble. Les deux n'ont aucun préfixe
    // commun — la logique ne les a jamais allumés ensemble, c'était le
    // survol qui ressemblait à la sélection (sidebarActiveStyle.test.js).
    assert.deepEqual(activeTexts('/patients'), ['Patients']);
    // ADR-116 — « Gardiennage » ne pointe plus vers cette adresse : la page
    // n'avait par ailleurs jamais été gardée par la bonne permission
    // (`guarding.view` ouvrait la tuile, `visitors.view` ouvrait la page).
    // « Visiteurs » porte désormais ce lien, gardée par `visitors.view`.
    assert.deepEqual(activeTexts('/reception/visitors'), ['Visiteurs']);
    assert.deepEqual(activeTexts('/guarding'), ['Gardiennage']);
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

/** The modules behind the rows: a group stands for each of its members. */
const moduleKeys = (rows) => rows.flatMap((row) => (row.family ? row.children.map((child) => child.code) : [row.key]));

test('un compte Réception voit ses quatre modules', () => {
    assert.deepEqual(moduleKeys(rowsOf()).sort(),
        ['cash', 'patients', 'reception', 'settlements']);
    // Réception et Sorties & règlements vivent sous /reception : une entrée
    // mère et sa liste déroulante.
    const reception = rowsOf().find((row) => row.key === 'reception-space');
    assert.deepEqual(reception.children.map((child) => child.code), ['reception', 'settlements']);
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
        assert.deepEqual(moduleKeys(rows).sort(),
            ['cash', 'patients', 'reception', 'settlements'],
            `modules perdus pour l’ordre ${JSON.stringify(clinical)}`);
        assert.equal(new Set(rows.map((row) => row.key)).size, rows.length, 'module dupliqué');
    }
});

test('l’ordre enregistré est bien celui affiché', () => {
    // Un ordre écrit avant le regroupement nomme les membres : chacun vaut
    // désormais pour son groupe, à la place que le compte lui avait donnée.
    const rows = rowsOf({ clinical: ['cash', 'reception', 'patients', 'settlements'] });
    assert.deepEqual(rows.map((row) => row.key), ['cash', 'reception-space', 'patients']);

    const current = rowsOf({ clinical: ['patients', 'reception-space', 'cash'] });
    assert.deepEqual(current.map((row) => row.key), ['patients', 'reception-space', 'cash']);
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

/* ------------------------------------------------------------------ */
/* Entrées mères : les menus d'un même module sous une liste déroulante */
/* ------------------------------------------------------------------ */

const MEDICINE_PERMISSIONS = new Set([
    'consultations.view', 'paraclinical_requests.view', 'clinical_protocols.view',
    'laboratory_orders.view', 'patients.view', 'care.update', 'death_records.view',
]);
const canMedicine = (permission) => MEDICINE_PERMISSIONS.has(permission);
const medicineRows = () => visibleMenu(buildClinicMenu({ roleCode: 'MEDICINE', can: canMedicine }), canMedicine)
    .filter((row) => !row.heading && row.key);

test('la Médecine regroupe sa file, ses demandes d’examens et ses protocoles', () => {
    const rows = medicineRows();
    const medicine = rows.find((row) => row.key === 'medicine-space');

    // Le rôle s'ouvre toujours sur la Médecine.
    assert.equal(rows[0].key, 'medicine-space');
    assert.deepEqual(medicine.children.map((child) => child.label),
        ['File de consultation', 'Demandes d’examens', 'Protocoles']);
    // Aucun des trois n'apparaît en plus à la racine.
    for (const key of ['medicine', 'paraclinical-requests', 'clinical-protocols']) {
        assert.equal(rows.some((row) => row.key === key), false, `${key} dupliqué à la racine`);
    }
});

test('une page Médecine n’allume qu’une entrée, et qu’un seul enfant', () => {
    const rows = medicineRows();
    const medicine = rows.find((row) => row.key === 'medicine-space');

    for (const [path, child] of [
        ['/medicine', 'medicine'],
        ['/medicine/orientations/abc/examen', 'medicine'],
        ['/medicine/demandes-examens', 'paraclinical-requests'],
        ['/medicine/protocoles/abc/edit', 'clinical-protocols'],
    ]) {
        assert.deepEqual(activeMenuKeys(rows, path).map((index) => rows[index].key), ['medicine-space'], path);

        const depths = medicine.children.map((entry) => menuMatchDepth(entry, path));
        const deepest = Math.max(...depths);
        const lit = medicine.children.filter((entry, index) => depths[index] === deepest).map((entry) => entry.code);
        assert.deepEqual(lit, [child], `${path} allume ${lit.join(', ')}`);
    }

    // Les autres modules gardent leur propre page.
    assert.deepEqual(activeMenuKeys(rows, '/deces').map((index) => rows[index].key), ['deaths']);
    assert.deepEqual(activeMenuKeys(rows, '/care').map((index) => rows[index].key), ['care']);
});

test('un groupe réduit à un seul membre devient un lien simple', () => {
    const only = new Set(['consultations.view', 'patients.view']);
    const can = (permission) => only.has(permission);
    const rows = visibleMenu(buildClinicMenu({ roleCode: 'MEDICINE', can }), can).filter((row) => !row.heading && row.key);
    const medicine = rows.find((row) => row.key === 'medicine-space');

    assert.equal(medicine.children, undefined, 'une liste déroulante d’une seule entrée');
    assert.equal(medicine.link, '/medicine');
    assert.equal(medicine.text, 'Médecine');
});

test('un groupe ne révèle jamais un membre interdit', () => {
    const can = (permission) => ['consultations.view', 'clinical_protocols.view'].includes(permission);
    const medicine = visibleMenu(buildClinicMenu({ roleCode: 'MEDICINE', can }), can)
        .find((row) => row.key === 'medicine-space');

    assert.deepEqual(medicine.children.map((child) => child.code), ['medicine', 'clinical-protocols']);
});

/**
 * Le bug de la capture du 2026-09-18 : l'application est rendue côté
 * serveur (Inertia SSR). Lire l'ordre personnel pendant le rendu faisait
 * différer la première image du HTML hydraté, et Vue ne répare pas ces
 * écarts : « Soins » ouvrait Patients, les icônes étaient décalées d'une
 * ligne. L'ordre ne se lit qu'une fois le navigateur maître de la page.
 */
test('l’ordre personnel n’est jamais lu pendant le rendu', async () => {
    const { readFileSync } = await import('node:fs');
    const source = readFileSync(new URL('../../resources/js/composables/useSidebarOrder.js', import.meta.url), 'utf8');

    assert.doesNotMatch(source, /watch\(storageKey, load, \{ immediate: true \}\)/);
    assert.match(source, /onMounted\(load\)/);
});
