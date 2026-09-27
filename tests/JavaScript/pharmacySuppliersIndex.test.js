import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/SuperAdmin/PharmacySuppliers/Index.vue', 'utf8');
const summary = fs.readFileSync('resources/js/Components/UI/ValidationErrorSummary.vue', 'utf8');

/** ADR-099 : tout écran retouché passe à shadcn-vue. */
test('l’écran Fournisseurs n’utilise plus DashWind', () => {
    for (const dashwind of [
        'UI/Icon.vue',
        'UI/Button.vue',
        'UI/Badge.vue',
        'UI/PageHeader.vue',
        'UI/EmptyState.vue',
        'UI/FolderCard.vue',
    ]) {
        assert.ok(! page.includes(dashwind), `${dashwind} ne doit plus être importé`);
    }

    assert.match(page, /from '@\/Components\/Shadcn\/Dialog\.vue'/);
    assert.match(page, /from '@\/Components\/Shadcn\/FormField\.vue'/);
    assert.match(page, /from 'lucide-vue-next'/);
    assert.doesNotMatch(page, /const inputClass|const labelClass/);
});

/**
 * Les deux formulaires vivaient dépliés dans la page et poussaient la liste
 * vers le bas ; le bouton qui les ouvrait devenait « Fermer ».
 */
test('la création et l’import sont des fenêtres, pas des panneaux dépliés', () => {
    assert.match(page, /title="`Nouveau fournisseur · \$\{current\?\.name \?\? ''\}`"/);
    assert.match(page, /title="`Importer des fournisseurs · \$\{current\?\.name \?\? ''\}`"/);

    // Plus de bascule « Ouvrir / Fermer » sur un même bouton.
    assert.doesNotMatch(page, /panel\.value === name \? null : name/);
    assert.doesNotMatch(page, /\? 'Fermer' :/);
});

/** Corriger une saisie refusée suppose que la fenêtre reste ouverte. */
test('une fenêtre ne se ferme pas pendant l’envoi', () => {
    assert.match(page, /:dismissible="! form\.processing"/);
    assert.match(page, /:dismissible="! importForm\.processing"/);
    assert.match(page, /const closeCreate = \(\) => \{\s*\n\s*if \(form\.processing\) return;/);
});

/**
 * ADR-098 : un fournisseur se présente comme un dossier que l'on ouvre.
 * La vue liste ne remplace pas cette métaphore, elle rend seulement une
 * longue liste comparable.
 */
test('la présentation en dossiers est conservée, avec une vue liste en plus', () => {
    assert.match(page, /const listMode = ref\('grid'\)/);
    assert.match(page, /v-else-if="listMode === 'grid'"/);
    assert.match(page, /aria-label="Vue dossiers"/);
    assert.match(page, /aria-label="Vue liste"/);
    assert.match(page, /Ouvrir le dossier/);
});

/** Une grille d'icônes ne dit pas si la recherche a écarté des dossiers. */
test('le nombre de dossiers affichés est lisible', () => {
    assert.match(page, /\{\{ visible\.length \}\}<template v-if="visible\.length !== suppliers\.length"> sur \{\{ suppliers\.length \}\}<\/template>/);
});

/**
 * Le rappel valait pour la vue archivée entière, mais ne s'affichait qu'en
 * présence de résultats : la seule fois où il manquait était celle où la
 * liste vide surprenait.
 */
test('l’explication des archivés ne dépend plus d’avoir des résultats', () => {
    const at = page.indexOf('Les fournisseurs archivés n’apparaissent');
    assert.notEqual(at, -1, 'explication introuvable');

    const opening = page.slice(page.lastIndexOf('<p', at), at);
    assert.match(opening, /v-if="isArchivedView"/);
    assert.doesNotMatch(opening, /visible\.length/);
});

/** Un écran vide doit proposer la sortie, pas seulement la constater. */
test('la liste vide propose de créer un dossier', () => {
    assert.match(page, /v-if="can\.create && ! isArchivedView && ! suppliers\.length"/);
});

/**
 * Deux glyphes codés en dur, aucun contrat de props : le résumé d'erreurs
 * quitte DashWind sans qu'aucun de ses appelants ne bouge.
 */
test('le résumé d’erreurs partagé passe à lucide sans changer d’API', () => {
    assert.doesNotMatch(summary, /Components\/UI\/Icon\.vue/);
    assert.match(summary, /import \{ ArrowRight, CircleAlert \} from 'lucide-vue-next'/);
    assert.match(summary, /errors: \{ type: Object, default: \(\) => \(\{\}\) \}/);
    assert.match(summary, /emit\('select', key\)/);
});

const DETAIL_PAGES = [
    'CatalogItems', 'Catalogs', 'ImportPreview', 'ImportSuppliers',
    'InvoiceCreate', 'InvoiceEdit', 'InvoiceShow', 'Invoices',
    'OrderCreate', 'OrderEdit', 'OrderShow', 'Orders', 'Products', 'Show',
];
const SHARED = [
    'Pharmacy/SupplierCatalogFiles', 'Pharmacy/CatalogImportPreview',
    'Pharmacy/SupplierInvoiceForm', 'Pharmacy/SupplierInvoiceDetail',
    'Pharmacy/PurchaseOrderForm', 'Pharmacy/PurchaseOrderDetail',
    'Pharmacy/SupplierOfferTables',
    'UI/Breadcrumb', 'UI/EmptyState', 'UI/ExplorerTile', 'UI/ExplorerView',
    'UI/FolderCard', 'UI/FormSection', 'UI/ValidationErrorSummary',
];

const detail = (name) => fs.readFileSync(`resources/js/Pages/SuperAdmin/PharmacySuppliers/${name}.vue`, 'utf8');
const shared = (name) => fs.readFileSync(`resources/js/Components/${name}.vue`, 'utf8');

/** ADR-099 : la police d'icônes DashWind disparaît du dossier Fournisseurs. */
test('aucune page du dossier n’utilise plus la police d’icônes', () => {
    for (const name of DETAIL_PAGES) {
        const source = detail(name);
        assert.ok(! source.includes('UI/Icon.vue'), `${name} importe encore Icon`);
        assert.doesNotMatch(source, /<Icon\b/, `${name} rend encore <Icon>`);
        assert.ok(! source.includes('UI/Button.vue'), `${name} importe encore le Button DashWind`);
        assert.ok(! source.includes('UI/Badge.vue'), `${name} importe encore le Badge DashWind`);
    }

    for (const name of SHARED) {
        const source = shared(name);
        assert.ok(! source.includes('UI/Icon.vue'), `${name} importe encore Icon`);
        assert.doesNotMatch(source, /<Icon\b/, `${name} rend encore <Icon>`);
    }
});

/**
 * Les couleurs codées en dur ne suivent pas le thème : c'est le passage aux
 * tokens sémantiques qui fait fonctionner le mode sombre sans `dark:` partout.
 */
test('le dossier Fournisseurs utilise les tokens sémantiques', () => {
    const palette = /(text|bg|border|divide)-(slate|gray)-(50|100|200|300|400|500|600|700|800|900|1000)\b/;

    for (const name of DETAIL_PAGES) {
        assert.doesNotMatch(detail(name), palette, `${name} porte encore la palette DashWind`);
    }
    for (const name of SHARED) {
        assert.doesNotMatch(shared(name), palette, `${name} porte encore la palette DashWind`);
    }
});

/**
 * `EmptyState`, `ExplorerTile`, `ExplorerView` et `FolderCard` reçoivent leur
 * icône en **chaîne**, depuis une trentaine d'écrans. Changer ce contrat
 * aurait obligé à retoucher — donc à modifier sans les vérifier — des écrans
 * hors du périmètre demandé. La table de correspondance le laisse intact.
 */
test('les composants partagés gardent leur contrat d’icône en chaîne', () => {
    for (const name of ['UI/EmptyState', 'UI/ExplorerTile', 'UI/FolderCard', 'UI/FormSection']) {
        const source = shared(name);
        // La chaîne reste acceptée ; un composant lucide peut l'être en plus.
        assert.match(source, /icon: \{ type: (String|\[String\b)/, `${name} doit garder son icône en chaîne`);
        assert.match(source, /lucideIcon/, `${name} doit résoudre le nom via la table`);
    }

    const map = fs.readFileSync('resources/js/lib/icons.js', 'utf8');
    // Un nom inconnu ne doit pas casser un écran : il retombe sur un neutre.
    assert.match(map, /export const lucideIcon = \(name\) => ICONS\[name\] \?\? Inbox;/);
});

/** Une taille de police ne dimensionne pas un SVG : les repères deviendraient minuscules. */
test('aucune icône n’est dimensionnée par une taille de police', () => {
    for (const name of DETAIL_PAGES) {
        assert.doesNotMatch(detail(name), /<[A-Z][A-Za-z]*[^>]*class="[^"]*text-[3-6]xl/, name);
    }
    for (const name of SHARED) {
        assert.doesNotMatch(shared(name), /<[A-Z][A-Za-z]*[^>]*class="[^"]*text-[3-6]xl/, name);
    }
});

/**
 * Le Badge DashWind prenait `tone` ; celui de shadcn prend `variant`.
 * Échanger l'import sans le prévoir aurait rendu toutes les pastilles
 * d'état de la même couleur, sans la moindre erreur — y compris les
 * `:tone="statusTone(order.status)"` calculés, partagés par le module.
 */
test('le Badge shadcn comprend le vocabulaire de tons partagé', () => {
    const badge = fs.readFileSync('resources/js/Components/Shadcn/Badge.vue', 'utf8');

    assert.match(badge, /tone: \{ type: String, default: '' \}/);
    for (const tone of ['neutral', 'success', 'danger', 'warning', 'info', 'primary']) {
        assert.match(badge, new RegExp(`${tone}: '`), `ton « ${tone} » non traduit`);
    }
    // Un ton inconnu reste lisible plutôt que de tomber sur la pastille pleine.
    assert.match(badge, /TONES\[props\.tone\] \?\? 'outline'/);
});

/** `bg-white` ne suit pas le thème : en mode sombre la carte reste blanche. */
test('les fonds de carte passent par le token', () => {
    for (const name of DETAIL_PAGES) {
        assert.doesNotMatch(detail(name), /\bbg-white\b/, `${name} porte encore bg-white`);
    }
    for (const name of SHARED) {
        assert.doesNotMatch(shared(name), /\bbg-white\b/, `${name} porte encore bg-white`);
    }
});
