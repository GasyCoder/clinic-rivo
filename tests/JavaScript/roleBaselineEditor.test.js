import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { diffPermissionSelection, permissionActionGroup } from '../../resources/js/utilities/permissionWorkspace.js';

const editor = fs.readFileSync('resources/js/Components/Rbac/RoleBaselineEditor.vue', 'utf8');
const page = fs.readFileSync('resources/js/Pages/SuperAdmin/Users/Index.vue', 'utf8');
const row = fs.readFileSync('resources/js/Components/Rbac/PermissionAccessRow.vue', 'utf8');

const catalog = [
    { id: 1, name: 'patients.view', label: 'Consulter', module: 'patients' },
    { id: 2, name: 'patients.create', label: 'Créer', module: 'patients' },
    { id: 3, name: 'patients.delete', label: 'Supprimer', module: 'patients' },
];

/**
 * Un socle de rôle s'applique à tous les comptes du service : on doit
 * pouvoir relire l'écart avant de l'envoyer, pas seulement le compter.
 */
test('l’écart nomme ce qui est accordé et ce qui est retiré', () => {
    const diff = diffPermissionSelection(catalog, new Set([1, 3]), new Set([1, 2]));

    assert.deepEqual(diff.added.map((permission) => permission.name), ['patients.create']);
    assert.deepEqual(diff.removed.map((permission) => permission.name), ['patients.delete']);
    assert.equal(diff.total, 2);
});

test('un socle inchangé ne produit aucun écart', () => {
    const diff = diffPermissionSelection(catalog, new Set([1, 2]), new Set([2, 1]));

    assert.equal(diff.total, 0);
    assert.deepEqual(diff.added, []);
    assert.deepEqual(diff.removed, []);
});

/** Consulter et supprimer ne portent pas le même risque : ils ne se lisent pas ensemble. */
test('les permissions se regroupent par nature d’action', () => {
    assert.equal(permissionActionGroup(catalog[0]), 'Consulter et exporter');
    assert.equal(permissionActionGroup(catalog[1]), 'Créer et importer');
    assert.equal(permissionActionGroup(catalog[2]), 'Suppression et restauration');
    assert.equal(permissionActionGroup({ name: 'users.manage' }), 'Gérer et mettre à jour');
});

/**
 * L'écran précédent paginait les catégories six par six. On ne pouvait ni
 * retrouver un droit dont on connaissait le nom, ni relire page 1 depuis la
 * page 3 avant d'enregistrer.
 */
test('les catégories ne sont plus paginées', () => {
    assert.doesNotMatch(editor, /MODULES_PER_PAGE|roleCategoryPage|TotalPages/);
    assert.doesNotMatch(page, /MODULES_PER_PAGE|roleCategoryPage|rolePaginatedModuleEntries/);
});

/** Chercher en restant dans une seule catégorie reviendrait à ne rien chercher. */
test('la recherche et les filtres traversent tout le catalogue', () => {
    assert.match(editor, /const scopedToCategory = computed\(\(\) => search\.value\.trim\(\) === '' && filter\.value === 'all'\)/);
    assert.match(editor, /categories\.value\.filter\(\(category\) => category\.visible > 0\)/);
});

/** Un compteur seul ne dit pas lesquelles : l'écart est consultable avant l'envoi. */
test('les modifications sont relisibles avant enregistrement', () => {
    assert.match(editor, /Voir les modifications/);
    assert.match(editor, /title="Modifications du socle"/);
    assert.match(editor, /Accordées \(\{\{ diff\.added\.length \}\}\)/);
    assert.match(editor, /Retirées \(\{\{ diff\.removed\.length \}\}\)/);
});

/** Enregistrer ne doit pas exiger de remonter en haut de page. */
test('la barre d’enregistrement reste visible', () => {
    assert.match(editor, /class="sticky bottom-0/);
    assert.match(editor, /:disabled="processing \|\| ! dirty"/);
});

/** Changer de rôle emporterait le brouillon sans le dire. */
test('changer de rôle avec un brouillon demande confirmation', () => {
    assert.match(editor, /if \(dirty\.value\) \{ pendingRoleCode\.value = code; return; \}/);
    assert.match(editor, /title="Changer de rôle sans enregistrer \?"/);
    assert.match(page, /title="Quitter le socle sans enregistrer \?"/);
});

/**
 * Le brouillon part du socle réellement enregistré, au montage comme au
 * retour d'un enregistrement : sinon l'écart lirait « tout retiré ».
 */
test('le brouillon est amorcé sur le socle enregistré', () => {
    assert.match(editor, /watch\(\(\) => props\.roles, \(\) => \{[\s\S]*?loadRole\(code\);[\s\S]*?\}, \{ immediate: true \}\)/);
    assert.match(editor, /draftIds\.value = new Set\(baselineIds\.value\)/);
});

/** Compter un filtre ne doit jamais changer le filtre actif pendant le rendu. */
test('les compteurs de filtre ne modifient aucun état', () => {
    assert.match(editor, /const filterCounts = computed\(/);
    assert.doesNotMatch(editor, /filter\.value = value;/);
});

/** ADR-099 : tout écran retouché passe à shadcn-vue. */
test('l’écran n’utilise plus DashWind', () => {
    for (const source of [page, editor, row]) {
        assert.doesNotMatch(source, /Components\/UI\/Icon\.vue/);
        assert.doesNotMatch(source, /Components\/UI\/Button\.vue/);
        assert.doesNotMatch(source, /Components\/UI\/Input\.vue/);
        assert.doesNotMatch(source, /Components\/UI\/Avatar\.vue/);
    }

    assert.match(page, /from '@\/Components\/Shadcn\/Dialog\.vue'/);
    assert.match(page, /from 'lucide-vue-next'/);
});

/** Les fenêtres de confirmation passent par la primitive commune. */
test('les confirmations utilisent le Dialog partagé', () => {
    assert.doesNotMatch(page, /fixed inset-0 z-\[12\d0\]/);

    for (const title of [
        'Autoriser cette permission sensible ?',
        'Confirmer l’action groupée ?',
        'Quitter sans enregistrer ?',
    ]) {
        assert.ok(page.includes(`title="${title}"`), `${title} doit être un Dialog`);
    }
});

/**
 * Trois états, jamais deux : « Selon le rôle » n'est pas une absence de
 * décision, c'est le socle du rôle qui s'applique (ADR-064).
 */
test('la ligne de permission conserve ses trois états', () => {
    assert.match(row, /\{ value: '', label: 'Selon le rôle' \}/);
    assert.match(row, /\{ value: 'allow', label: 'Autoriser' \}/);
    assert.match(row, /\{ value: 'deny', label: 'Interdire' \}/);
    assert.match(row, /Inclus dans le rôle/);
});

/**
 * Un enregistrement refusé réaffiche la page avec le même socle. Recharger
 * là viderait la saisie au moment précis où elle vient d'échouer.
 */
test('un enregistrement refusé ne jette pas le brouillon', () => {
    assert.match(editor, /const baselineSignature = \(code\) => \{/);
    assert.match(editor, /if \(exists && baselineSignature\(code\) === loadedSignature\.value\) return;/);
});

const nav = fs.readFileSync('resources/js/Components/Rbac/PermissionCategoryNav.vue', 'utf8');

/** La navigation ne doit pas changer d'allure selon ce que l'on règle. */
test('les deux écrans partagent le même rail de catégories', () => {
    assert.match(page, /import PermissionCategoryNav from '@\/Components\/Rbac\/PermissionCategoryNav\.vue'/);
    assert.match(editor, /import PermissionCategoryNav from '@\/Components\/Rbac\/PermissionCategoryNav\.vue'/);

    // Plus aucune liste de catégories réécrite à la main dans les écrans.
    assert.doesNotMatch(page, /v-for="category in group\.categories"/);
    assert.doesNotMatch(editor, /v-for="category in group\.categories"/);
});

/**
 * Sept domaines et une quarantaine de catégories : sans bande grise qui
 * tient le domaine en tête pendant le défilement, la liste se lit ligne à
 * ligne sans jamais dire où l'on en est.
 */
test('le domaine reste en tête pendant le défilement', () => {
    assert.match(nav, /class="sticky top-0 z-10 border-b border-t border-border bg-muted px-3 py-1\.5 text-\[10px\] font-bold uppercase tracking-wider text-muted-foreground first:border-t-0"/);
});

/** Une ligne par catégorie, séparée de la suivante, et un compteur en colonne. */
test('les catégories se lisent comme une liste', () => {
    assert.match(nav, /border-b border-border\/50/);
    assert.match(nav, /rounded px-1\.5 py-0\.5 text-\[10px\] font-semibold tabular-nums/);
});

/** Même repère d'état actif que le menu latéral de l'application. */
test('la catégorie ouverte porte un rail, pas seulement une couleur', () => {
    assert.match(nav, /absolute inset-y-1 start-0 w-0\.5 rounded-e-full bg-primary/);
    assert.match(nav, /aria-current="page"|:aria-current=/);
});

/** Un compteur figé pendant la frappe ne dirait pas où chercher. */
test('la recherche filtre le rail et recompte', () => {
    assert.match(page, /count: permissionSearch\.value \? category\.visible : category\.total/);
    assert.match(page, /hidden: Boolean\(permissionSearch\.value\) && category\.visible === 0/);
    assert.match(nav, /\.filter\(\(category\) => ! category\.hidden\)/);
});

/** Le socle montre « accordées / total » : le compteur est aussi l'état. */
test('le rail du socle affiche l’état de chaque catégorie', () => {
    assert.match(editor, /count: `\$\{category\.granted\}\/\$\{category\.total\}`/);
});

/** Sinon le clic n'aurait rien changé à l'écran, la vue suivant le filtre. */
test('ouvrir une catégorie quitte la recherche en cours', () => {
    assert.match(editor, /const openCategory = \(key\) => \{\s*search\.value = '';\s*filter\.value = 'all';\s*selectedCategory\.value = key;/);
});
