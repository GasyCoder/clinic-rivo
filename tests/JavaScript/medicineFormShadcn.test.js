import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const form = fs.readFileSync('resources/js/Components/Pharmacy/MedicineForm.vue', 'utf8');
const panel = fs.readFileSync('resources/js/Components/Pharmacy/MedicineStatusPanel.vue', 'utf8');
const page = fs.readFileSync('resources/js/Pages/SuperAdmin/Stock/MedicineEdit.vue', 'utf8');

/**
 * L'écran « Modifier un médicament » était déjà en shadcn — mais tout son
 * contenu vient de deux composants partagés, restés en champs habillés à la
 * main. Une page migrée dont le corps ne l'est pas ne l'est pas.
 */
test('la fiche médicament utilise la couche shadcn', () => {
    for (const component of ['FormField', 'Input', 'Select', 'Checkbox', 'Card']) {
        assert.ok(form.includes(`@/Components/Shadcn/${component}.vue`), `${component} n’est pas utilisé`);
    }

    // Plus de classes de champ recopiées dans le composant.
    assert.doesNotMatch(form, /const inputClass|const labelClass/);
    assert.doesNotMatch(form, /<input |<select |<textarea /);
});

/** `bg-white` ne suit pas le thème : en mode sombre la carte reste blanche. */
test('les fonds passent par les tokens', () => {
    for (const [name, source] of [['le formulaire', form], ['le panneau d’état', panel], ['la page', page]]) {
        assert.doesNotMatch(source, /\bbg-white\b/, `${name} porte encore bg-white`);
        assert.doesNotMatch(source, /\btext-red-500\b/, `${name} marque le requis hors token`);
    }
});

/**
 * La fenêtre de désactivation portait son propre voile et sa propre boîte :
 * ni piège de focus, ni fermeture par Échap, ni retour du focus au bouton.
 */
test('la désactivation passe par le Dialog partagé', () => {
    assert.match(panel, /from '@\/Components\/Shadcn\/Dialog\.vue'/);
    assert.doesNotMatch(panel, /fixed inset-0 z-\[1200\]/);
    assert.match(panel, /:dismissible="! form\.processing"/);

    // ADR-098 : un médicament se désactive avec un motif, il ne se supprime jamais.
    assert.match(panel, /! form\.reason\.trim\(\)/);
});

/** Les variantes DashWind du bouton ne survivent pas à la migration. */
test('les boutons utilisent les variantes shadcn', () => {
    for (const [name, source] of [['le formulaire', form], ['le panneau d’état', panel]]) {
        assert.doesNotMatch(source, /variant="white-outline"/, `${name} garde une variante DashWind`);
        assert.doesNotMatch(source, /size="rg"/, `${name} garde une taille DashWind`);
    }
});

/**
 * Le code d'un médicament est ce que désignent les fichiers d'import et les
 * historiques : le champ reste désactivé en modification (ADR-098).
 */
test('le code reste immuable en modification', () => {
    assert.match(form, /<Input v-model="form\.code" name="code" class="uppercase" :disabled="editing"/);
    assert.match(form, /Le code ne change pas/);
});

const stock = fs.readFileSync('resources/js/Pages/SuperAdmin/Stock/Index.vue', 'utf8');

/**
 * L'écran passait le test de palette — il n'utilisait que des tokens — mais
 * tous ses contrôles étaient des balises natives habillées à la main. Une
 * page « migrée » en couleur seulement ne l'est pas.
 */
test('le stock du portail utilise les contrôles shadcn', () => {
    for (const component of ['Badge', 'Button', 'Card', 'Checkbox', 'FormField', 'IconInput', 'Select']) {
        assert.ok(stock.includes(`@/Components/Shadcn/${component}.vue`), `${component} n’est pas utilisé`);
    }

    // Seul le champ fichier reste natif : shadcn n'a pas de primitive pour lui.
    assert.doesNotMatch(stock, /<input(?![^>]*type="file")/);
    assert.doesNotMatch(stock, /<select /);
    assert.match(stock, /<input\s+type="file"/);
    assert.match(stock, /aria-label="Fichier Excel à importer"/);
});

/**
 * Cinq jeux de classes recopiés pour les pastilles d'état s'écartaient du
 * vocabulaire du `Badge` partagé à la première retouche — et de son mode
 * sombre.
 */
test('les états passent par le ton du Badge partagé', () => {
    assert.match(stock, /const statusTone = \(value\) => \(\{/);
    assert.doesNotMatch(stock, /const statusClass/);
    assert.match(stock, /<Badge :tone="statusTone\(medicine\.status\)"/);
    assert.match(stock, /<Badge :tone="statusTone\(lot\.status\)"/);
});

/** Les variantes DashWind ne survivent pas non plus ici. */
test('le stock n’utilise plus les variantes DashWind', () => {
    assert.doesNotMatch(stock, /variant="white-outline"/);
    assert.doesNotMatch(stock, /size="rg"/);
    // Artefact relevé au passage : une classe vide laissée par une substitution.
    assert.doesNotMatch(stock, /bg-muted\/70 \/40/);
});

const families = fs.readFileSync('resources/js/Components/Pharmacy/MedicineFamilies.vue', 'utf8');

/**
 * Les familles sont le seul écran atteignable depuis un bouton de la page
 * Stock. Le laisser en l'état aurait fait passer d'un écran repris à un
 * écran d'avant, en un clic.
 */
test('les familles de médicaments utilisent la couche shadcn', () => {
    for (const component of ['Dialog', 'FormField', 'Input', 'Textarea']) {
        assert.ok(families.includes(`@/Components/Shadcn/${component}.vue`), `${component} n’est pas utilisé`);
    }

    assert.doesNotMatch(families, /<input |<textarea |const inputClass/);
    assert.doesNotMatch(families, /fixed inset-0 z-\[1200\]/);
    assert.doesNotMatch(families, /\bbg-white\b|\btext-red-500\b/);
});

/** ADR-098 : une famille qui classe encore des médicaments actifs ne s’archive pas. */
test('le refus d’archivage se lit avant le clic', () => {
    assert.match(families, /:disabled="category\.active_medicines_count > 0"/);
    assert.match(families, /médicament\(s\) actif\(s\) dans cette famille/);
    assert.match(families, /! archiveForm\.reason\.trim\(\)/);
});
