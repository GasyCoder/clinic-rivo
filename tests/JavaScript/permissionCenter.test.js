import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { diffPermissionSelection } from '../../resources/js/utilities/permissionWorkspace.js';
import { roleDescription, roleInitials } from '../../resources/js/utilities/roleDescriptions.js';
import { nextModuleState } from '../../resources/js/composables/useExclusiveModules.js';

/**
 * ADR-178 — « Rôles & permissions » devient un centre de gestion : les rôles à
 * gauche, le rôle choisi à droite, ses droits en modules et en grille. Ces
 * tests verrouillent ce que la refonte ne devait pas perdre — et ce qu'elle
 * devait supprimer.
 */
const read = (path) => fs.readFileSync(path, 'utf8');

const page = read('resources/js/Pages/SuperAdmin/Roles/Index.vue');
const workspace = read('resources/js/Components/Rbac/RoleWorkspace.vue');
const overview = read('resources/js/Components/Rbac/RoleOverviewCard.vue');
const directory = read('resources/js/Components/Rbac/RoleDirectory.vue');
const matrix = read('resources/js/Components/Rbac/PermissionMatrix.vue');
const toggle = read('resources/js/Components/Rbac/PermissionToggle.vue');
const effectCell = read('resources/js/Components/Rbac/PermissionEffectCell.vue');
const moduleCard = read('resources/js/Components/Rbac/PermissionModuleCard.vue');
const saveBar = read('resources/js/Components/Rbac/PermissionSaveBar.vue');
const toolbar = read('resources/js/Components/Rbac/PermissionToolbar.vue');
const accounts = read('resources/js/Components/Rbac/AccountWorkspace.vue');
const accountDirectory = read('resources/js/Components/Rbac/AccountDirectory.vue');
const createPanel = read('resources/js/Components/Rbac/RoleCreatePanel.vue');
const guard = read('resources/js/composables/useUnsavedChangesGuard.js');
const checkbox = read('resources/js/Components/Shadcn/Checkbox.vue');
const dropdown = read('resources/js/Components/Shadcn/DropdownMenu.vue');

const rbacSources = { workspace, overview, directory, matrix, toggle, effectCell, moduleCard, saveBar, toolbar, accounts, accountDirectory, createPanel };

/* ------------------------------------------------------------------ */
/* L'organisation de l'écran                                           */
/* ------------------------------------------------------------------ */

/**
 * L'ancien écran : quatre grandes cartes d'onglets, une fenêtre pour changer
 * de rôle, un rail de quatre-vingt-six catégories et une seule catégorie
 * visible à la fois. Aucun de ces composants ne doit revenir.
 */
test('les anciens ateliers ont disparu', () => {
    for (const removed of ['RoleBaselineEditor', 'UserPermissionOverrides', 'PermissionCategoryNav', 'PermissionAccessRow']) {
        assert.equal(fs.existsSync(`resources/js/Components/Rbac/${removed}.vue`), false, `${removed}.vue existe encore`);
        assert.doesNotMatch(page, new RegExp(removed), `la page importe encore ${removed}`);
    }

    // Plus de fenêtre pour changer de rôle ou de compte : ils sont dans la liste.
    assert.doesNotMatch(page, /title="Choisir un rôle"|title="Choisir un compte"/);
    assert.doesNotMatch(workspace, /title="Choisir un rôle"|const switching = ref/);
});

test('la page réunit liste des rôles, espace de travail, exceptions et catalogue', () => {
    for (const component of ['RoleDirectory', 'RoleWorkspace', 'RoleCreatePanel', 'AccountDirectory', 'AccountWorkspace', 'PermissionCatalog']) {
        assert.match(page, new RegExp(`import ${component} from '@/Components/Rbac/${component}\\.vue'`), `${component} non importé`);
    }

    for (const label of ['Rôles', 'Exceptions par compte', 'Catalogue des droits']) {
        assert.ok(page.includes(`label: '${label}'`), `section « ${label} » absente`);
    }

    assert.match(page, /aria-label="Sections"/);
});

/** Un rôle se choisit d'un clic dans la liste, jamais dans une fenêtre. */
test('la liste des rôles se cherche, se parcourt au clavier et dit ce que porte chaque rôle', () => {
    assert.match(directory, /aria-label="Rechercher un rôle"/);
    assert.match(directory, /\['ArrowDown', 'ArrowUp', 'Home', 'End'\]/);
    assert.match(directory, /data-role/);
    assert.match(directory, /permissionCount\(role\)/);
    assert.match(directory, /role\.users_count/);
    // Le rôle système reste visible, verrouillé ; les archivés restent restaurables.
    assert.match(directory, /Rôle système/);
    assert.match(directory, /Archivés/);
    // Le rôle dont le socle a un brouillon porte une pastille.
    assert.match(directory, /role\.code === dirtyCode/);
});

/** Sous 1024 px la liste passe dans un panneau : la grille garde la largeur de la tablette. */
test('la liste des rôles reste accessible sur tablette', () => {
    assert.match(page, /<aside class="hidden lg:sticky lg:top-20 lg:block/);
    assert.match(page, /<Popover[\s\S]*?:open="directoryOpen"/);
    assert.match(page, /lg:hidden/);
});

/** Chaque rôle ouvre un espace neuf : un brouillon n'appartient qu'à son rôle. */
test('l’espace de travail est recréé pour chaque rôle, et reçoit les comptes du site', () => {
    assert.match(page, /:key="`\$\{selectedSiteCode\}:\$\{activeRole\.code\}`"/);
    assert.match(page, /<RoleWorkspace[\s\S]*?:users="users"/);
    assert.match(page, /<ErrorBoundary v-else :key="`\$\{selectedSiteCode\}-\$\{view\}`"/);
});

/* ------------------------------------------------------------------ */
/* Ne jamais perdre un brouillon                                       */
/* ------------------------------------------------------------------ */

/** Changer de rôle, de compte, de section ou de site ne jette jamais un brouillon en silence. */
test('changer d’objet avec un brouillon demande confirmation', () => {
    assert.match(page, /const guarded = \(target, run\) => \{/);
    assert.match(page, /if \(! dirty\.value\) \{\s*run\(\);/);
    assert.match(page, /pendingSwitch\.value = \{ target, run \};/);
    assert.match(page, /title="Abandonner vos modifications \?"/);

    for (const handler of ['selectSite', 'selectView', 'selectRole', 'selectUser', 'startCreate']) {
        const body = page.slice(page.indexOf(`const ${handler} = `), page.indexOf('};', page.indexOf(`const ${handler} = `)));
        assert.match(body, /guarded\(/, `${handler} contourne la garde`);
    }
});

/** Quitter la page ou fermer l'onglet ne jette pas non plus le brouillon. */
test('quitter la page avec un brouillon demande confirmation', () => {
    assert.match(page, /const leaveGuard = useUnsavedChangesGuard\(dirty\)/);
    assert.match(page, /title="Quitter sans enregistrer \?"/);
    assert.match(guard, /window\.addEventListener\('beforeunload', onBeforeUnload\)/);
    // Seules les visites GET sont retenues : un enregistrement vide le brouillon, il ne le menace pas.
    assert.match(guard, /String\(visit\.method\)\.toLowerCase\(\) !== 'get'/);
    assert.match(guard, /event\.preventDefault\(\)/);
});

/**
 * Le brouillon part du socle réellement enregistré, et ne repart que si le
 * site a changé d'avis : un enregistrement refusé réaffiche la page avec le
 * même socle, et recharger là viderait la saisie au moment où elle échoue.
 */
test('un enregistrement refusé ne jette pas le brouillon', () => {
    assert.match(workspace, /const draftIds = ref\(new Set\(baselineIds\.value\)\)/);
    assert.match(workspace, /watch\(\(\) => props\.role\.permissions, \(\) => \{/);
    assert.match(workspace, /if \(signature === loadedSignature\.value\) return;/);

    assert.match(accounts, /watch\(\(\) => props\.user\.permission_overrides, \(\) => \{/);
    assert.match(accounts, /if \(current === loadedSignature\.value\) return;/);
});

/* ------------------------------------------------------------------ */
/* L'adresse suit ce qu'on regarde                                     */
/* ------------------------------------------------------------------ */

/**
 * Une actualisation rouvre le même rôle. L'état initial est lu dans la page
 * Inertia — identique au rendu serveur et dans le navigateur, donc sans écart
 * d'hydratation — et l'adresse suit par une visite côté client, sans requête.
 */
test('l’adresse garde le site, la section, le rôle et le compte', () => {
    assert.match(page, /new URLSearchParams\(String\(page\.url \?\? ''\)\.split\('\?'\)\[1\] \?\? ''\)/);
    assert.match(page, /router\.replace\(\{ url, preserveState: true, preserveScroll: true \}\)/);
    assert.match(page, /onMounted\(\(\) => \{\s*mounted\.value = true;/);
    assert.match(page, /const VIEWS = \{ roles: 'roles', comptes: 'accounts', catalogue: 'catalog' \}/);
});

/* ------------------------------------------------------------------ */
/* La grille                                                           */
/* ------------------------------------------------------------------ */

/** Les colonnes sont les mêmes pour tous les modules : on lit « Supprimer » de haut en bas. */
test('la grille range chaque module en colonnes d’actions', () => {
    assert.match(matrix, /const columns = PERMISSION_ACTION_COLUMNS;/);
    assert.match(matrix, /grid-template-columns: minmax\(0, 1fr\) repeat\(7, var\(--pm-col\)\);/);
    // Aucun écart entre colonnes : un écart propre aux lignes décalait chaque case de sa légende.
    assert.match(matrix, /column-gap: 0;/);
    // Sur écran étroit, des pastilles qui portent leur verbe — mesuré sur la carte, pas la fenêtre.
    assert.match(matrix, /container: pm \/ inline-size;/);
    assert.match(matrix, /@container pm \(min-width: 38rem\)/);
    assert.match(matrix, /permissionActionLabel\(permission\)/);
});

/** Une case qui résume une ligne dit « une partie seulement ». */
test('la case d’une fonctionnalité a trois états', () => {
    assert.match(matrix, /return active === shown\.length \? true : 'indeterminate';/);
    assert.match(checkbox, /modelValue: \{ type: \[Boolean, String\], default: false \}/);
    assert.match(checkbox, /<Minus v-if="modelValue === 'indeterminate'"/);
    assert.match(checkbox, /emit\('update:modelValue', value === true\)/);
});

/** Les actions groupées ne portent que sur ce qui est affiché. */
test('les actions groupées s’appliquent aux permissions affichées', () => {
    assert.match(matrix, /emit\('set-many', \{ permissions: row\.permissions\.filter\(visible\), value \}\)/);
    assert.match(matrix, /\.filter\(\(permission\) => permission && visible\(permission\)\)/);
    assert.match(workspace, /@click="setMany\(moduleStats\[module\.key\]\.shown, true\)"/);
    assert.match(workspace, /@click="setMany\(moduleStats\[module\.key\]\.shown, false\)"/);
    assert.match(workspace, /Tout sélectionner/);
    assert.match(workspace, /Tout désélectionner/);
});

/** Répétées sur quatorze modules repliés, elles chargeaient l'écran. */
test('les actions groupées n’apparaissent que sur le module ouvert', () => {
    assert.match(moduleCard, /<slot v-if="expanded" name="actions" \/>/);
    assert.match(moduleCard, /:aria-expanded="expanded"/);
    assert.match(moduleCard, /:aria-controls="bodyId"/);
});

/**
 * ADR-153 — un DENY nominatif l'emporte sur ce socle : la case le dit
 * elle-même, et le repère ne compte que les comptes du rôle réglé.
 */
test('une case refusée individuellement le dit', () => {
    assert.match(workspace, /const overridesByPermission = computed/);
    assert.match(workspace, /if \(user\.role\?\.code !== props\.role\.code\) continue;/);
    assert.match(workspace, /denyCount: overridesByPermission\.value\.get\(permission\.id\)\?\.deny \?\? 0/);
    assert.match(toggle, /Refusé à \{\{ denyCount \}\} compte/);
    assert.match(toggle, /le cocher ici ne l’ouvrira pas pour/);
});

/** Accordée, modifiée, sensible : la case se lit sans survol, et au clavier. */
test('une case de la grille est une vraie case à cocher', () => {
    assert.match(toggle, /role="checkbox"/);
    assert.match(toggle, /:aria-checked="granted"/);
    assert.match(toggle, /ring-2 ring-amber-400/);
    assert.match(toggle, /<TriangleAlert\s+v-if="sensitive"/);
});

/* ------------------------------------------------------------------ */
/* Chercher, filtrer                                                   */
/* ------------------------------------------------------------------ */

/** Chercher en restant dans un seul module reviendrait à ne rien chercher. */
test('la recherche traverse tout le catalogue et ouvre les modules qui répondent', () => {
    assert.match(workspace, /const visibleIds = computed\(\(\) => new Set\(props\.catalog/);
    assert.match(workspace, /const isExpanded = \(module\) => \(filtering\.value\s*\? ! collapsedWhileFiltering\.value\.has\(module\.key\)/);
    assert.match(toolbar, /aria-label="Rechercher une permission"/);
    assert.match(toolbar, /Tout afficher/);
});

/** Compter un filtre ne doit jamais changer le filtre actif pendant le rendu. */
test('les compteurs de filtre ne modifient aucun état', () => {
    assert.match(workspace, /const filterOptions = computed\(/);
    assert.doesNotMatch(workspace, /props\.filter = |filter\.value = /);
    assert.match(workspace, /\{ value: 'changed', label: 'Modifiées'/);
});

/* ------------------------------------------------------------------ */
/* Enregistrer                                                         */
/* ------------------------------------------------------------------ */

/** Enregistrer ne doit pas exiger de remonter en haut de page. */
test('la barre d’enregistrement colle au bas de l’écran dès qu’il y a un brouillon', () => {
    assert.match(saveBar, /sticky bottom-3/);
    assert.match(saveBar, /:disabled="processing \|\| ! dirty \|\| ! canSave"/);
    assert.match(saveBar, /Modifications enregistrées/);
    assert.match(saveBar, /non enregistrée/);
    assert.match(workspace, /save-label="Enregistrer les modifications"/);
});

/** Un compteur seul ne dit pas lesquelles : l'écart est consultable avant l'envoi. */
test('les modifications sont relisibles avant enregistrement', () => {
    assert.match(workspace, /title="Modifications du socle"/);
    assert.match(workspace, /title: 'Accordées', permissions: diff\.value\.added/);
    assert.match(workspace, /title: 'Retirées', permissions: diff\.value\.removed/);
    assert.match(workspace, /@review="reviewing = true"/);
});

/**
 * La seule confirmation d'un enregistrement ordinaire : accorder des
 * permissions sensibles à tout un métier. Retirer ne peut que restreindre.
 */
test('accorder une permission sensible à un rôle demande confirmation', () => {
    assert.match(workspace, /const sensitiveAdditions = computed\(\(\) => diff\.value\.added\.filter\(isSensitivePermission\)\)/);
    assert.match(workspace, /if \(sensitiveAdditions\.value\.length\) \{\s*confirmingSensitive\.value = true;/);
    assert.match(workspace, /confirm-label="Enregistrer et accorder"/);
    assert.match(workspace, /baselineForm\.put\(`\/super-admin\/workspaces\/roles\/\$\{props\.siteCode\}\/permissions\/\$\{props\.role\.code\}`/);
});

/** Même principe pour un compte : ouvrir un droit sensible, jamais le fermer. */
test('autoriser une permission sensible à un compte demande confirmation', () => {
    assert.match(accounts, /effectOf\(permission\) === 'allow' && isSensitivePermission\(permission\)/);
    assert.match(accounts, /if \(sensitiveOpenings\.value\.length\) \{\s*confirmingSensitive\.value = true;/);
    assert.match(accounts, /Autoriser cette permission sensible \?/);
});

test('l’écart nomme ce qui est accordé et ce qui est retiré', () => {
    const catalog = [
        { id: 1, name: 'patients.view', label: 'Consulter', module: 'patients' },
        { id: 2, name: 'patients.create', label: 'Créer', module: 'patients' },
        { id: 3, name: 'patients.delete', label: 'Supprimer', module: 'patients' },
    ];
    const diff = diffPermissionSelection(catalog, new Set([1, 3]), new Set([1, 2]));

    assert.deepEqual(diff.added.map((permission) => permission.name), ['patients.create']);
    assert.deepEqual(diff.removed.map((permission) => permission.name), ['patients.delete']);
    assert.equal(diffPermissionSelection(catalog, new Set([1, 2]), new Set([2, 1])).total, 0);
});

/* ------------------------------------------------------------------ */
/* Le rôle lui-même                                                    */
/* ------------------------------------------------------------------ */

/** Le code d'un rôle est son identité : renommer, sur place, ne touche que le libellé. */
test('renommer un rôle se fait sur place et ne touche que le libellé', () => {
    const rename = overview.slice(overview.indexOf('const renameForm'), overview.indexOf('const menuItems'));

    assert.match(rename, /useForm\(\{ name: '' \}\)/);
    assert.doesNotMatch(rename, /code:/);
    assert.match(rename, /renameForm\.put\(`\/super-admin\/workspaces\/roles\/\$\{props\.siteCode\}\/\$\{props\.role\.code\}`/);
    assert.match(overview, /@keydown\.esc\.prevent="cancelRename"/);
    assert.doesNotMatch(page, /title="`Renommer/);
});

/** Un rôle porté par des comptes ne s’archive pas : le menu le dit avant le clic. */
test('le référentiel des rôles protège les rôles portés', () => {
    assert.match(overview, /disabled: props\.role\.users_count > 0/);
    assert.match(overview, /encore ce rôle : réaffectez-les d’abord/);
    assert.match(workspace, /:disabled="archiveForm\.reason\.trim\(\)\.length < 5"/);
    assert.match(workspace, /archiveForm\.delete\(\s*`\/super-admin\/workspaces\/roles\/\$\{props\.siteCode\}\/\$\{props\.role\.code\}`/);
    // Un rôle archivé se lit et se restaure, il ne se règle pas.
    assert.match(workspace, /const readonly = computed\(\(\) => props\.role\.archived \|\| ! props\.abilities\.edit\)/);
    assert.match(overview, /Restaurer le rôle/);
});

/** Le rôle système ne se règle jamais depuis un site (ADR-025, ADR-027) : on le dit. */
test('le rôle système est expliqué, jamais réglé', () => {
    assert.match(workspace, /<template v-if="role\.protected">/);
    assert.match(workspace, /Il ne se règle ni ne s’attribue ici/);
});

test('les réinitialisations distinguent le socle du rôle des exceptions du compte', () => {
    assert.match(overview, /props\.abilities\.reset && props\.role\.has_default_baseline/);
    assert.match(workspace, /title="Réinitialiser le socle du rôle \?"/);
    assert.match(workspace, /Leurs autorisations et interdictions individuelles resteront inchangées/);

    assert.match(accounts, /Réinitialiser le compte/);
    // Visible même sans exception (ADR-158) : désactivé et expliqué, jamais masqué.
    assert.match(accounts, /resetStats\.total === 0/);
    assert.match(accounts, /héritera uniquement du socle de son rôle/);
    assert.match(accounts, /ses recommandations ne seront pas réappliquées automatiquement/);
});

/** Créer un rôle sans fenêtre : il s'ouvre ensuite dans la grille. */
test('un rôle se crée sur place et s’ouvre dans la grille', () => {
    assert.match(createPanel, /\.post\('\/super-admin\/workspaces\/roles'/);
    assert.match(createPanel, /onSuccess: \(\) => emit\('created', normalizedCode\.value\)/);
    assert.match(createPanel, /\/\^\[A-Z\]\[A-Z0-9_\]\{1,39\}\$\//);
    assert.match(page, /const onRoleCreated = \(code\) => \{\s*creating\.value = false;\s*selectedRoleCode\.value = code;/);
});

test('un rôle livré se décrit, un rôle créé dit ce que couvre son socle', () => {
    assert.match(roleDescription('RECEPTION'), /Accueil des patients/);
    assert.equal(roleDescription('KINESITHERAPEUTE'), '');
    assert.equal(roleInitials('Administration / RH'), 'AR');
    assert.equal(roleInitials('Médecine'), 'ME');
    assert.match(overview, /Rôle créé depuis le portail/);
});

/** ADR-150 — un socle à zéro ne veut pas dire « personne n'y a accès ». */
test('l’en-tête du rôle signale les comptes qui portent des exceptions', () => {
    assert.match(overview, /role\.users_with_exceptions_count/);
    assert.match(overview, /qui l’emportent sur ce socle/);
    assert.match(overview, /Exceptions par compte/);
});

/* ------------------------------------------------------------------ */
/* Les exceptions d'un compte                                          */
/* ------------------------------------------------------------------ */

/** L'ordre de résolution ne change pas d'un écran à l'autre. */
test('l’écran des exceptions garde la priorité du DENY', () => {
    assert.match(accounts, /if \(effect === 'deny'\) return false;\s*\n\s*if \(effect === 'allow'\) return true;/);
    assert.match(accounts, /return roleGrants\(permission\);/);
    assert.match(effectCell, /if \(props\.effect === 'deny'\) return false;\s*\n\s*if \(props\.effect === 'allow'\) return true;/);
});

/** Trois états, jamais deux : « Suivre le rôle » est le socle qui s'applique. */
test('les trois états d’une exception sont nommés par leur effet', () => {
    assert.match(effectCell, /label: 'Suivre le rôle'/);
    assert.match(effectCell, /label: 'Toujours autoriser'/);
    assert.match(effectCell, /label: 'Toujours interdire'/);
    assert.match(effectCell, /Socle du rôle :/);
    assert.match(effectCell, /<DropdownMenuRadioGroup v-model="model">/);
});

test('les exceptions s’enregistrent sans toucher au socle', () => {
    assert.match(accounts, /overridesForm\.put\(`\/super-admin\/workspaces\/roles\/\$\{props\.siteCode\}\/accounts\/\$\{props\.user\.uuid\}\/permissions`/);
    assert.match(accounts, /permission_overrides: \[\]/);
    assert.match(accounts, /mode="overrides"/);
    assert.match(accountDirectory, /aria-label="Rechercher un compte"/);
    assert.match(accountDirectory, /Avec exceptions seulement/);
});

/* ------------------------------------------------------------------ */
/* Design system                                                       */
/* ------------------------------------------------------------------ */

/** ADR-099 : shadcn-vue seulement, ni DashWind ni police d'icônes. */
test('le centre des droits n’utilise que shadcn-vue', () => {
    for (const [name, source] of Object.entries({ ...rbacSources, page })) {
        assert.doesNotMatch(source, /Components\/UI\/(Icon|Button|Input|Avatar)\.vue/, `${name} importe DashWind`);
        assert.doesNotMatch(source, /class="[^"]*\b(ni ni-|nk-)/, `${name} utilise une classe DashWind`);
    }

    assert.match(dropdown, /from 'reka-ui'/);
    assert.match(dropdown, /DropdownMenuItem/);
});

/**
 * ADR-178 — chaque fonctionnalité d'un module se reconnaît à son icône, dans la
 * grille comme dans le catalogue ; une sous-fonctionnalité est décalée, avec
 * une icône plus petite, au lieu d'une flèche.
 */
test('every feature row of the matrix and every catalog section shows its icon', () => {
    const matrix = read('resources/js/Components/Rbac/PermissionMatrix.vue');
    const catalog = read('resources/js/Components/Rbac/PermissionCatalog.vue');

    assert.match(matrix, /<component :is="row\.icon"/);
    assert.match(matrix, /<component :is="group\.icon"/);
    assert.doesNotMatch(matrix, /CornerDownRight/);
    assert.match(matrix, /--pm-indent/);
    assert.match(catalog, /icon: permissionCategoryIcon\(key\)/);
    assert.match(catalog, /<component :is="section\.icon"/);
});

/**
 * Un menu ancré (réglage d'une exception, « Tout le module », Popover) s'ouvre
 * directement à sa place. L'animation des fenêtres centrées translate de -50 % :
 * appliquée à un menu, elle le faisait glisser vers la gauche puis sauter.
 */
test('anchored menus open in place: no centred-dialog animation, no movement', () => {
    const css = read('resources/css/shadcn.css');
    const keyframes = css.match(/@keyframes rivo-popover-in \{[\s\S]*?\n\}/)?.[0] ?? '';

    assert.ok(keyframes, 'rivo-popover-in keyframes are missing');
    assert.doesNotMatch(keyframes, /transform|translate/);

    for (const path of [
        'resources/js/Components/Shadcn/Popover.vue',
        'resources/js/Components/Shadcn/DropdownMenu.vue',
        'resources/js/Components/Rbac/PermissionEffectCell.vue',
    ]) {
        const source = read(path);

        assert.doesNotMatch(source, /rivo-dialog-in/, `${path} uses the centred-dialog animation`);
        assert.match(source, /animate-\[rivo-popover-in_/, `${path} lost its in-place animation`);
    }
});

/**
 * ADR-178 — un seul module ouvert à la fois : en ouvrir un referme les autres,
 * y compris pendant une recherche où plusieurs se sont ouverts d'eux-mêmes.
 */
test('opening a module closes the others, with or without a search', () => {
    assert.deepEqual(nextModuleState({ key: 'surgery', opening: true, filtering: false, shownKeys: [], collapsed: new Set() }), { expanded: ['surgery'] });
    assert.deepEqual(nextModuleState({ key: 'surgery', opening: false, filtering: false, shownKeys: [], collapsed: new Set() }), { expanded: [] });

    const shownKeys = ['medicine', 'surgery', 'pharmacy'];
    const opened = nextModuleState({ key: 'surgery', opening: true, filtering: true, shownKeys, collapsed: new Set(shownKeys) });
    assert.deepEqual([...opened.collapsed].sort(), ['medicine', 'pharmacy']);

    const closed = nextModuleState({ key: 'surgery', opening: false, filtering: true, shownKeys, collapsed: new Set(['medicine']) });
    assert.deepEqual([...closed.collapsed].sort(), ['medicine', 'surgery']);
});

test('every module list uses the exclusive accordion and keeps the clicked header in place', () => {
    for (const path of [
        'resources/js/Components/Rbac/RoleWorkspace.vue',
        'resources/js/Components/Rbac/AccountWorkspace.vue',
        'resources/js/Components/Rbac/PermissionCatalog.vue',
    ]) {
        const source = read(path);

        assert.match(source, /keepHeaderInPlace\(module\.key/, `${path} does not keep the header in place`);
        assert.match(source, /nextModuleState\(/, `${path} does not close the other modules`);
        assert.doesNotMatch(source, /Tout déplier|toggleAll/, `${path} still offers « Tout déplier »`);
    }

    const toolbar = read('resources/js/Components/Rbac/PermissionToolbar.vue');
    assert.match(toolbar, /v-if="anyExpanded"/);
    assert.doesNotMatch(toolbar, /Tout déplier/);
});
