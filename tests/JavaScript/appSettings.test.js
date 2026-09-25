import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { applySiteSettings, currencyFormat, siteBrand } from '../../resources/js/lib/siteSettings.js';
import { formatMoney } from '../../resources/js/utilities/money.js';
import { ageBandFor, bandRange, civilityFor, describeAgeBands, yearsFromBirthDate } from '../../resources/js/utilities/patientAge.js';
import { PRIMARY_PRESETS, readability } from '../../resources/js/utilities/brandColor.js';

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

test('the settings page sets each site through its own sections, shadcn only', () => {
    const page = read('resources/js/Pages/SuperAdmin/Settings/Index.vue');
    const asset = read('resources/js/Components/Settings/SettingsAssetField.vue');
    const menu = read('resources/js/Components/Layout/Menu.vue');

    assert.match(menu, /text: 'Paramètres', link: '\/super-admin\/settings', permission: 'settings\.view'/);
    for (const section of ['identite', 'couleurs', 'ecrans', 'monnaie', 'ages', 'legal', 'direction', 'visibilite']) {
        assert.match(page, new RegExp(`id="reglages-${section}"`));
    }
    assert.match(page, /\.put\('\/super-admin\/settings'/);
    assert.match(page, /can\('settings\.update'\)/);
    assert.match(page, /title="Abandonner vos modifications \?"/);
    assert.match(page, /<Checkbox id="search-engines-hidden" v-model="form\.search_engines_hidden"/, 'une case à cocher, pas un interrupteur caché');
    assert.match(page, /v-model="form\.app_tagline"/);
    assert.match(page, /lg:grid-cols-\[minmax\(0,1fr\)_18rem\] 2xl:grid-cols-\[minmax\(0,1fr\)_22rem\]/, 'le sommaire des sections est à droite, élargi');
    assert.match(page, /<aside class="[^"]*lg:order-last/);
    assert.match(page, /v-if="form\.errors\.site_code"[^>]*role="alert"/, 'un refus sans champ se lit dans la barre d’enregistrement');
    assert.match(asset, /router\.post\(`\/super-admin\/settings\/assets\/\$\{props\.kind\}`/);
    assert.match(asset, /forceFormData: true/);
    assert.match(asset, /title="`Retirer : \$\{label\.toLowerCase\(\)\} \?`"|:title="`Retirer : \$\{label\.toLowerCase\(\)\} \?`"/);

    for (const source of [page, asset]) {
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
