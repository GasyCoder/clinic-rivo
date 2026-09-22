import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { diffPermissionSelection, permissionActionGroup } from '../../resources/js/utilities/permissionWorkspace.js';

const editor = fs.readFileSync('resources/js/Components/Rbac/RoleBaselineEditor.vue', 'utf8');
// L'atelier des permissions a quitté le formulaire utilisateur : il vit
// désormais dans « Rôles & permissions » (ADR-100). `page` désigne donc
// l'écran qui le porte, pas celui qui crée les comptes.
const page = fs.readFileSync('resources/js/Components/Rbac/UserPermissionOverrides.vue', 'utf8');
const rolesPage = fs.readFileSync('resources/js/Pages/SuperAdmin/Roles/Index.vue', 'utf8');
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
    assert.match(page, /title="`Quitter \$\{selectedUser\?\.name \?\? ''\} \?`"/);
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
    ]) {
        assert.ok(page.includes(`title="${title}"`), `${title} doit être un Dialog`);
    }

    const users = fs.readFileSync('resources/js/Pages/SuperAdmin/Users/Index.vue', 'utf8');
    assert.ok(users.includes('title="Quitter sans enregistrer ?"'));
});

/**
 * Trois états, jamais deux : « Suivre le rôle » n'est pas une absence de
 * décision, c'est le socle du rôle qui s'applique (ADR-064).
 */
test('la ligne de permission conserve ses trois états', () => {
    assert.match(row, /value: '',\s*label: 'Suivre le rôle'/);
    assert.match(row, /value: 'allow',\s*label: 'Toujours autoriser'/);
    assert.match(row, /value: 'deny',\s*label: 'Toujours interdire'/);
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
    assert.match(nav, /sticky top-0 z-10 flex w-full items-center gap-2 border-b border-t border-border bg-muted px-3 py-2/);
});

/** Une ligne par catégorie, séparée de la suivante, et un compteur en colonne. */
test('les catégories se lisent comme une liste', () => {
    assert.match(nav, /border-b border-border\/50/);
    assert.match(nav, /<Badge[\s\S]*?tabular-nums/);
});

/** Même repère d'état actif que le menu latéral de l'application. */
test('la catégorie ouverte porte un rail, pas seulement une couleur', () => {
    assert.match(nav, /absolute inset-y-1 start-0 w-1 rounded-e-full bg-primary/);
    assert.match(nav, /aria-current="page"|:aria-current=/);
});

/** Un compteur figé pendant la frappe ne dirait pas où chercher. */
test('la recherche filtre le rail et recompte', () => {
    // Les deux ateliers cadrent la vue de la même façon : tant qu'une
    // recherche ou un filtre est actif, la catégorie ouverte ne borne plus
    // rien — c'est précisément quand on ne sait pas où vit un droit qu'on
    // le tape.
    for (const source of [page, editor]) {
        assert.match(source, /const scopedToCategory = computed\(\(\) => search\.value\.trim\(\) === '' && filter\.value === 'all'\)/);
    }
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

/**
 * Onze rôles empilés au-dessus de quarante catégories : plus de 900 px à
 * parcourir avant d'atteindre le travail réel, pour un choix qu'on ne fait
 * qu'une fois — et sans aucune recherche.
 */
test('le rôle et le compte se choisissent dans une fenêtre cherchable', () => {
    const overrides = fs.readFileSync('resources/js/Components/Rbac/UserPermissionOverrides.vue', 'utf8');

    for (const [name, source, title] of [
        ['le socle', editor, 'Choisir un rôle'],
        ['les exceptions', overrides, 'Choisir un compte'],
    ]) {
        assert.match(source, new RegExp(`title="${title}"`), `${name} : pas de fenêtre de choix`);
        assert.match(source, /const switching = ref\(false\)/, `${name} : pas d’état de fenêtre`);
        assert.match(source, /aria-label="Rechercher un (rôle|compte)"/, `${name} : pas de recherche`);
        // La colonne suit le défilement : les catégories restent atteignables.
        assert.match(source, /sticky top-4 flex max-h-\[calc\(100vh-9rem\)\] min-h-\[26rem\] flex-col gap-4 pe-1/, `${name} : rail non collant`);
    }

    // Plus de liste complète empilée dans la colonne.
    assert.doesNotMatch(editor, /<p class="border-b border-border px-4 py-2\.5[^"]*">Rôles du site<\/p>/);
});

/** Quarante catégories sans filtre, c'était une recherche à l'œil. */
test('le rail des catégories se filtre', () => {
    assert.match(nav, /placeholder="Filtrer les catégories…"/);
    assert.match(nav, /aria-label="Filtrer les catégories"/);
    // Le code d'une catégorie compte autant que son libellé : on connaît
    // parfois l'un sans l'autre.
    assert.match(nav, /normalizePermissionText\(`\$\{category\.label\} \$\{category\.key\}`\)/);
    assert.match(nav, /Effacer le filtre/);
});

/**
 * La largeur utile du rail dépend du travail en cours : parcourir des
 * catégories n'a pas les mêmes besoins que relire des libellés longs à
 * droite. Une grille figée imposait le même arbitrage à tout le monde.
 */
test('les deux panneaux se redimensionnent à la barre', () => {
    const overrides = fs.readFileSync('resources/js/Components/Rbac/UserPermissionOverrides.vue', 'utf8');
    const split = fs.readFileSync('resources/js/Components/UI/ResizableSplit.vue', 'utf8');

    for (const [name, source, key] of [
        ['le socle', editor, 'rivo:super-admin:baseline-split'],
        ['les exceptions', overrides, 'rivo:super-admin:overrides-split'],
    ]) {
        assert.match(source, /<ResizableSplit/, `${name} : pas de panneaux redimensionnables`);
        assert.ok(source.includes(`storage-key="${key}"`), `${name} : le choix ne serait pas conservé`);
        assert.match(source, /<template #start>/, `${name} : panneau de gauche absent`);
        assert.match(source, /<template #end>/, `${name} : panneau de droite absent`);

        // Une grille figée ne laissait aucun arbitrage au poste de travail.
        assert.doesNotMatch(source, /xl:grid-cols-\[19rem_minmax\(0,1fr\)\]/, `${name} : grille figée restante`);
    }

    // La barre reste utilisable sans souris, et se remet d'un double-clic.
    assert.match(split, /role="separator"/);
    assert.match(split, /aria-orientation="vertical"/);
    assert.match(split, /tabindex="0"/);
    assert.match(split, /event\.key === 'ArrowLeft'/);

    // Elle suit le thème de l'application (ADR-099), plus un bleu codé en dur.
    assert.match(split, /background-color: hsl\(var\(--primary\)\)/);
    assert.doesNotMatch(split, /rgb\(59 130 246\)/);
});

/**
 * Le rail était une petite fenêtre fixe (22 rem) où l'on faisait défiler
 * quatre lignes à la fois, masquée en bas par la barre d'enregistrement. Il
 * prend désormais la hauteur que l'écran lui laisse.
 */
test('le rail des catégories suit la hauteur de l’écran', () => {
    assert.doesNotMatch(nav, /max-h-\[22rem\]|max-h-\[26rem\]/);
    assert.match(nav, /min-h-\[12rem\] flex-1 overflow-y-auto/);

    const overrides = fs.readFileSync('resources/js/Components/Rbac/UserPermissionOverrides.vue', 'utf8');

    for (const source of [editor, overrides]) {
        assert.match(source, /<div class="flex min-h-0 flex-1 flex-col">/);
        assert.match(source, /class="min-h-0 flex-1"/);
        assert.match(source, /<Card class="shrink-0 overflow-hidden">/);
    }
});

/** Sept domaines : les replier permet de survoler au lieu de lire quarante lignes. */
test('les domaines du rail se replient, et une recherche les déplie', () => {
    assert.match(nav, /:aria-expanded="isOpen\(group\)"/);
    assert.match(nav, /const isOpen = \(group\) => filtering\.value \|\| ! collapsed\.value\[group\.key\]/);
    assert.match(nav, /Tout replier/);
    assert.match(nav, /Tout déplier/);
});

/** Un changement venu d'ailleurs ne doit pas sélectionner une ligne hors de vue. */
test('la catégorie ouverte déplie son domaine et reste visible', () => {
    assert.match(nav, /scrollIntoView\(\{ block: 'nearest' \}\)/);
    assert.match(nav, /collapsed\.value = \{ \.\.\.collapsed\.value, \[owner\.key\]: false \}/);
});

/** Le rail n'est pas réservé à la souris. */
test('les flèches parcourent les catégories', () => {
    assert.match(nav, /\['ArrowDown', 'ArrowUp', 'Home', 'End'\]/);
    assert.match(nav, /data-category/);
    assert.match(nav, /@keydown="onKeydown"/);
});

/** ADR-099 : ni champ natif habillé à la main, ni police d'icônes. */
test('le rail est écrit avec les primitives shadcn', () => {
    for (const primitive of ['Badge', 'Button', 'IconInput']) {
        assert.match(nav, new RegExp(`import ${primitive} from '@/Components/Shadcn/${primitive}\\.vue'`));
    }

    assert.doesNotMatch(nav, /class="[^"]*\b(ni ni-|nk-)/);
});
