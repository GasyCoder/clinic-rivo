import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { applySiteSettings, currencyFormat, siteBrand } from '../../resources/js/lib/siteSettings.js';
import { formatMoney } from '../../resources/js/utilities/money.js';
import { ageBandFor, bandRange, civilityFor, describeAgeBands, yearsFromBirthDate } from '../../resources/js/utilities/patientAge.js';
import { PRIMARY_PRESETS, readability } from '../../resources/js/utilities/brandColor.js';
import { SETTINGS_GROUPS, SETTINGS_SECTION_IDS, SETTINGS_SECTIONS, sectionsOf, settingsUrl } from '../../resources/js/utilities/settingsSections.js';

const read = (path) => fs.readFileSync(path, 'utf8');
const plain = (value) => value.replace(/[  ]/g, ' ');

/**
 * ADR-184 — l'Ariary s'écrit comme le site l'a réglé ; seule l'écriture change,
 * et les décimales ne cachent jamais un centime.
 */
test('amounts follow the currency writing set for the site', () => {
    applySiteSettings({ brand: 'Clinique A', currency: { label: 'Ar', position: 'after', decimals: 0 } });
    assert.equal(siteBrand(), 'Clinique A');
    assert.equal(plain(formatMoney(12500)), '12 500 Ar');
    assert.equal(plain(formatMoney(4500.5)), '4 500,5 Ar', 'jamais arrondi à l’entier');

    applySiteSettings({ brand: '', currency: { label: 'MGA', position: 'before', decimals: 2 } });
    assert.equal(siteBrand(), 'Clinique Saint Georges', 'un nom vide garde le nom par défaut');
    assert.equal(plain(formatMoney(12500)), 'MGA 12 500,00');

    applySiteSettings({ currency: { label: 'EUR', position: 'x', decimals: 7 } });
    assert.deepEqual(currencyFormat(), { label: 'Ar', position: 'after', decimals: 0 }, 'une valeur inconnue retombe sur l’Ariary');
});

test('no screen writes the currency by hand any more', () => {
    for (const path of [
        'resources/js/utilities/pharmacyStatus.js',
        'resources/js/Pages/Pharmacy/Partials/DispenseQueue.vue',
        'resources/js/Pages/SuperAdmin/Dashboard.vue',
        'resources/js/Components/Pharmacy/PurchaseOrderForm.vue',
        'resources/js/Components/Pharmacy/SupplierInvoiceForm.vue',
        'resources/js/Components/Pharmacy/StockEntryTable.vue',
    ]) {
        const source = read(path);

        assert.doesNotMatch(source, /\} MGA`|">MGA<\/span>|\)\)\} Ar`/, `${path} écrit encore la devise à la main`);
    }
});

/** Bébé 0–1 an, enfant 2–15 ans, adulte dès 16 ans, par défaut — et les bornes du site sinon. */
test('age bands follow the site bounds in completed years', () => {
    const bands = { baby_max_age: 1, child_max_age: 15 };
    const today = new Date(2026, 8, 24);

    assert.equal(yearsFromBirthDate('2024-09-25', today), 1, 'vingt-trois mois : encore un an révolu');
    assert.equal(yearsFromBirthDate('2024-09-24', today), 2);
    assert.equal(yearsFromBirthDate('2027-01-01', today), null, 'une date future n’est pas un âge');
    assert.equal(ageBandFor(0, bands), 'BABY');
    assert.equal(ageBandFor(1, bands), 'BABY');
    assert.equal(ageBandFor(2, bands), 'CHILD');
    assert.equal(ageBandFor(15, bands), 'CHILD');
    assert.equal(ageBandFor(16, bands), 'ADULT');
    assert.equal(ageBandFor('', bands), null);
    assert.equal(ageBandFor(13, { baby_max_age: 2, child_max_age: 12 }), 'ADULT');

    assert.equal(civilityFor('BABY', 'F'), 'GIRL');
    assert.equal(civilityFor('CHILD', 'M'), 'BOY');
    assert.equal(civilityFor('ADULT', 'F'), 'MRS');
    assert.equal(bandRange('CHILD', bands), '2 à 15 ans');
    assert.equal(describeAgeBands(bands), 'Bébé : 0 à 1 an · Enfant : 2 à 15 ans · Adulte : 16 ans et plus');
});

test('the arrival form reads the site bands and adapts the child profile', () => {
    const form = read('resources/js/Pages/Reception/Create.vue');

    assert.match(form, /page\.props\.site\?\.ageBands \?\? DEFAULT_AGE_BANDS/);
    assert.match(form, /\|\| isMinorBand\(patientBand\.value\)/, 'un enfant par l’âge a le profil enfant');
    assert.match(form, /const babyNeedsBirthDate = computed/);
    assert.match(form, /watch\(\[patientBand, \(\) => patientForm\.sex\]/, 'la civilité suit la tranche et le sexe');
    assert.match(form, /patientForm\.age !== '' && patientForm\.age !== null \? Number\(patientForm\.age\) : null/, '0 an reste un âge');
});

test('the preset colours are all readable with white text', () => {
    for (const preset of PRIMARY_PRESETS) {
        assert.equal(readability(preset.value).level, 'good', `${preset.label} n’est pas lisible en blanc`);
    }

    assert.equal(readability('#FDE047').level, 'poor');
    assert.equal(readability('bleu'), null);
});

test('the settings read module by module: the open module in a card, the modules on its right with their icons, stacked fields', () => {
    const page = read('resources/js/Pages/SuperAdmin/Settings/Index.vue');
    const nav = read('resources/js/Components/Settings/SettingsNav.vue');
    const section = read('resources/js/Components/Settings/SettingsSection.vue');
    const field = read('resources/js/Components/Settings/SettingsField.vue');
    const asset = read('resources/js/Components/Settings/SettingsAssetField.vue');
    const menu = read('resources/js/Components/Layout/Menu.vue');
    const controller = read('app/Http/Controllers/SuperAdmin/AppSettingsController.php');
    const routes = read('routes/web.php');

    assert.match(menu, /text: 'Paramètres', link: '\/super-admin\/settings', permission: 'settings\.view'/);

    // Une seule liste de modules : l'écran et le serveur (qui refuse tout autre module) disent la même.
    const serverSections = [...controller.match(/public const SECTIONS = \[([^\]]+)\]/)[1].matchAll(/'([a-z]+)'/g)].map((match) => match[1]);
    assert.deepEqual(serverSections, [...SETTINGS_SECTION_IDS]);
    assert.match(routes, /Route::get\('\/settings\/\{section\}'[\s\S]*?->whereIn\('section', SuperAdminAppSettingsController::SECTIONS\)/);
    assert.match(controller, /redirect\(\)->route\('super-admin\.settings\.section', \['section' => self::SECTIONS\[0\]/, '« Paramètres » ouvre directement le premier module');

    // Chaque module a son composant, en champs shadcn empilés ; la page l'affiche pour son module.
    const COMPONENTS = {
        identite: 'IdentitySettings', theme: 'ThemeSettings', avance: 'AdvancedSettings', ecrans: 'ScreenTemplates',
        numerotation: 'NumberingSettings', ages: 'AgeBandSettings', monnaie: 'CurrencySettings', remises: 'DiscountSettings', legal: 'LegalSettings',
        direction: 'DirectionSettings', visibilite: 'SearchVisibilitySettings',
    };
    assert.deepEqual(Object.keys(COMPONENTS), [...SETTINGS_SECTION_IDS]);
    assert.match(section, /:id="`reglages-\$\{id\}`"/);
    assert.match(section, /<h3 [^>]*class="text-lg font-medium text-foreground"/, 'le titre du module, comme dans l’exemple shadcn');
    assert.match(section, /<Separator \/>/, 'un filet sous le titre du module');
    assert.match(field, /<Label v-if="\$props\.for" :for="\$props\.for">\{\{ label \}\}<\/Label>/, 'le libellé au-dessus du champ, relié à lui');
    assert.match(field, /class="min-w-0 space-y-2"/, 'libellé, champ puis aide, empilés');
    for (const [id, name] of Object.entries(COMPONENTS)) {
        const component = read(`resources/js/Components/Settings/${name}.vue`);
        assert.match(component, new RegExp(`<SettingsSection id="${id}"`), `${name} porte le module « ${id} »`);
        assert.match(component, /<SettingsField\b/, `${name} se lit en champs de formulaire`);
        assert.doesNotMatch(component, /OptionPills|Components\/UI\//, `${name} n’emploie que les primitives shadcn`);
        assert.match(page, new RegExp(`<${name}[\\s\\S]*?current\\.id === '${id}'`), `la page affiche ${name} pour « ${id} »`);
    }
    for (const group of SETTINGS_GROUPS) {
        assert.ok(sectionsOf(group.id).length > 0, `le groupe ${group.id} a au moins un module`);
    }
    assert.ok(SETTINGS_SECTIONS.every((item) => SETTINGS_GROUPS.some((group) => group.id === item.group)), 'chaque module appartient à un groupe');
    assert.equal(settingsUrl('theme', 'A'), '/super-admin/settings/theme?site=A');
    assert.equal(settingsUrl(null, ''), '/super-admin/settings');

    // L'en-tête : le titre, et le site réglé à sa droite ; un filet ; puis le menu des modules et le module ouvert.
    assert.match(page, /<h2 class="text-2xl font-bold tracking-tight text-foreground">Paramètres<\/h2>/);
    const switcher = read('resources/js/Components/Settings/SettingsSiteSwitcher.vue');
    assert.match(page, /<SettingsSiteSwitcher :targets="targets" :model-value="selectedCode" @update:model-value="selectTarget" \/>/, 'le site se choisit en un clic, et la page décide (confirmation si des modifications sont en cours)');
    assert.match(switcher, /<RadioGroup\b[\s\S]*?aria-label="Site réglé"/, 'un groupe radio shadcn : flèches du clavier, un seul choix');
    assert.match(switcher, /<RadioGroupItem :value="target\.site\.code" class="sr-only" \/>/);
    assert.match(switcher, /'non configuré'/);
    assert.match(switcher, /'injoignable'/, 'l’état de chaque site se lit avant de le choisir');
    assert.match(page, /<SettingsNav :current="current\.id" :site-code="selectedCode" \/>/);
    assert.match(page, /<div class="w-full space-y-6 pb-16">/, 'toute la largeur de l’écran');
    assert.doesNotMatch(page, /max-w-6xl|lg:max-w-2xl/);
    assert.match(page, /lg:grid-cols-\[minmax\(0,1fr\)_16rem\]/, 'le module ouvert, puis le menu à sa droite');
    assert.match(page, /class="cq min-w-0 rounded-xl border/, 'la carte est un conteneur : ses champs s’élargissent selon SA largeur');
    assert.match(read('tailwind.config.js'), /addVariant\(`cq-\$\{name\}`, `@container \(min-width: \$\{width\}\) \{ \.cq & \}`\)/, 'plus spécifique que sm:, écrit après dans la feuille');
    for (const name of ['NumberingSettings', 'AdvancedSettings', 'LegalSettings']) {
        assert.match(read(`resources/js/Components/Settings/${name}.vue`), /sm:grid-cols-2 cq-4xl:grid-cols-3/, `${name} passe à trois colonnes sur une carte large`);
    }
    assert.match(page, /event\.key\?\.toLowerCase\(\) !== 's'/, 'Ctrl+S enregistre le module');
    // Chaque thème de la liste porte sa vignette clair / sombre, reprise dans le champ fermé.
    const select = read('resources/js/Components/Shadcn/Select.vue');
    const theme = read('resources/js/Components/Settings/ThemeSettings.vue');
    assert.equal(select.match(/<slot name="leading" :option="(?:item|option)" \/>\s*<SelectItemText>/g)?.length, 2, 'le repère reste hors du texte de l’option, que le clavier lit');
    assert.match(select, /<slot v-if="selectedOption" name="leading" :option="selectedOption" \/>/, 'et il est repris dans le champ fermé');
    assert.match(theme, /<template #leading="\{ option \}"><ThemeSwatch :colors="swatchOf\(option\.value\)" \/><\/template>/);
    assert.match(read('resources/js/Components/Settings/ThemeSwatch.vue'), /aria-hidden="true"/, 'décorative : le nom du thème est écrit à côté');
    // Chaque option de l'affichage avancé montre ce qu'elle change.
    const advanced = read('resources/js/Components/Settings/AdvancedSettings.vue');
    const optionIcon = read('resources/js/Components/Settings/AppearanceOptionIcon.vue');
    assert.match(advanced, /<template #leading="\{ option \}"><AppearanceOptionIcon :field="item\.field" :value="option\.value" \/><\/template>/);
    for (const field of ['ui_font_size', 'ui_radius', 'ui_contrast', 'ui_density', 'ui_motion']) {
        assert.match(optionIcon, new RegExp(field), `${field} a son repère`);
    }
    assert.match(read('resources/js/Components/Settings/OptionTile.vue'), /aria-hidden="true"/, 'décoratif : le nom de l’option est écrit à côté');
    assert.match(optionIcon, /<OptionTile>/);
    // Numérotation et monnaie : chaque liste montre, dans sa vignette, la part du numéro ou du montant qu'elle change.
    const numbering = read('resources/js/Components/Settings/NumberingSettings.vue');
    for (const id of ['patient-year', 'patient-separator', 'patient-digits', 'patient-reset', 'episode-digits', 'employee-separator', 'employee-digits']) {
        assert.match(numbering, new RegExp(`<Select id="reglage-${id}"[^\\n]*>\\s*<template #leading="\\{ option \\}"><NumberingOptionIcon `), `${id} a ses repères`);
    }
    assert.equal(read('resources/js/Components/Settings/CurrencySettings.vue').match(/<template #leading/g)?.length, 3);
    // Champs texte : une icône en tête, via la primitive shadcn.
    for (const [name, count] of [['IdentitySettings', 2], ['LegalSettings', 7], ['DirectionSettings', 2], ['AgeBandSettings', 3], ['NumberingSettings', 2]]) {
        assert.equal(read(`resources/js/Components/Settings/${name}.vue`).match(/<IconInput id="reglage-[^"]+"[^>]*:icon="/g)?.length, count, `${name} : ${count} champs avec leur icône`);
    }
    assert.match(page, /if \(! readonly\.value && target\.value\?\.ok\) submit\(\);/, 'jamais sans droit ni sur un site injoignable');
    assert.match(page, /<aside class="[^"]*lg:order-2">\s*<SettingsNav/, 'le menu à droite sur un grand écran, au-dessus du module sur un écran étroit');
    assert.match(page, /class="cq min-w-0 rounded-xl border border-border bg-card[^"]*lg:order-1"/, 'le module dans une carte bordée');
    assert.match(page, /class="sticky bottom-0[^"]*border-t border-border/, 'le pied de la carte garde « Enregistrer » à portée');
    assert.match(page, /<Button type="submit"[^>]*>/, 'l’enregistrement au bas du module, comme dans un formulaire shadcn');
    assert.match(nav, /:aria-current="section\.id === current \? 'page' : undefined"/);
    assert.match(nav, /active \? 'bg-primary\/10 font-medium text-primary hover:bg-primary\/10' : 'text-muted-foreground hover:bg-muted hover:text-foreground'/, 'le module ouvert se distingue');
    assert.match(nav, /v-for="group in SETTINGS_GROUPS"/, 'les modules rangés par groupe');
    assert.match(nav, /<component\s+:is="section\.icon"/, 'chaque module avec son icône');
    assert.match(section, /<component :is="entry\.icon"/, 'le titre du module reprend l’icône du menu');
    assert.match(nav, /:href="settingsUrl\(section\.id, siteCode\)"/, 'chaque module est une adresse, pour le site réglé');

    assert.match(page, /\.put\('\/super-admin\/settings'/);
    assert.match(page, /can\('settings\.update'\)/);
    assert.match(page, /title="Abandonner vos modifications \?"/);
    assert.match(page, /title="Quitter sans enregistrer \?"/, 'changer de module avec des modifications demande confirmation');
    assert.match(page, /router\.replace\(\{ url: settingsUrl\(current\.value\.id, code\)/, 'l’adresse suit le site choisi, sans recharger');
    assert.match(page, /v-if="form\.errors\.site_code"[^>]*role="alert"/, 'un refus sans champ se lit au-dessus du bouton Enregistrer');
    assert.match(read('resources/js/Components/Settings/SearchVisibilitySettings.vue'), /<Switch id="search-engines-hidden" v-model="form\.search_engines_hidden"/, 'un interrupteur shadcn, avec son libellé');
    assert.match(read('resources/js/Components/Settings/SearchVisibilitySettings.vue'), /<Label for="search-engines-hidden"/);
    assert.match(read('resources/js/Components/Settings/IdentitySettings.vue'), /v-model="form\.app_tagline"/);
    assert.match(asset, /router\.post\(`\/super-admin\/settings\/assets\/\$\{props\.kind\}`/);
    assert.match(asset, /forceFormData: true/);
    assert.match(asset, /title="`Retirer : \$\{label\.toLowerCase\(\)\} \?`"|:title="`Retirer : \$\{label\.toLowerCase\(\)\} \?`"/);

    for (const source of [page, nav, section, field, asset, ...Object.values(COMPONENTS).map((name) => read(`resources/js/Components/Settings/${name}.vue`))]) {
        assert.doesNotMatch(source, /Components\/UI\/Icon\.vue|class="ni |\bnk-[a-z]/);
    }
});

test('the login pages read the site tagline instead of a motto written by hand', () => {
    const shell = read('resources/js/Components/Auth/AuthShell.vue');

    assert.doesNotMatch(shell, /Ny fahasalamana no loharanon-karena/);
    assert.match(shell, /site\.value\.tagline/);
    assert.match(shell, /v-if="tagline"/);
});

test('the head applies the site icon and colour, the sidebar shows the icon', () => {
    const blade = read('resources/views/app.blade.php');
    const lockup = read('resources/js/Components/Layout/BrandLockup.vue');
    const app = read('resources/js/app.js');

    assert.match(blade, /\$appSettings->brand\(\)/);
    assert.match(blade, /<link rel="icon" href="\{\{ \$appSettings->iconUrl\(\) \}\}">/);
    assert.match(blade, /<style id="rivo-theme">/);
    assert.match(blade, /@if \(\$appSettings->hiddenFromSearchEngines\(\)\)\s*<meta name="robots"/);
    assert.match(lockup, /v-if="iconUrl"/);
    assert.match(app, /title: \(title\) => \(title \? `\$\{title\} - \$\{siteBrand\(\)\}` : siteBrand\(\)\)/);
    assert.match(app, /applySiteSettings\(page\?\.props\?\.site\)/);
});
