import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Patients/Index.vue', 'utf8');
const portal = fs.readFileSync('resources/js/Pages/SuperAdmin/PatientVip/Index.vue', 'utf8');
const menu = fs.readFileSync('resources/js/Components/Layout/Menu.vue', 'utf8');

/**
 * ADR-133 — patients normaux / VIP, tri A → Z, initiale du nom, export Excel.
 * Le statut et les comptes viennent du serveur : l'écran n'en recalcule aucun.
 */
test('les catégories normal / VIP sont des boutons dont le compte vient du serveur', () => {
    assert.match(page, /props\.segments\?\.category\?\.vip \?\? 0/);
    assert.match(page, /props\.segments\?\.category\?\.normal \?\? 0/);
    assert.match(page, /label: 'Patients VIP'/);
    assert.match(page, /label: 'Patients normaux'/);
    assert.match(page, /submitFilters\(\{ segment: key \}\)/);

    // Jamais calculé depuis la page affichée : il mentirait dès la page 2.
    assert.doesNotMatch(page, /data\.filter\(\(patient\) => patient\.is_vip\)/);
});

test('un patient VIP porte son badge en liste comme en grille', () => {
    assert.equal((page.match(/v-if="patient\.is_vip"/g) ?? []).length, 2);
});

test('sans seuil réglé, l’écran le dit au lieu de laisser croire qu’aucun patient n’est VIP par hasard', () => {
    assert.match(page, /Aucun seuil VIP n’est réglé pour ce site/);
    assert.match(page, /<template v-if="vip\?\.configured">VIP : \{\{ vip\.rule \}\}\.<\/template>/);
});

test('le tri A → Z / Z → A et l’initiale voyagent dans l’adresse', () => {
    assert.match(page, /\{ value: 'name_asc', label: 'Nom A → Z' \}/);
    assert.match(page, /\{ value: 'name_desc', label: 'Nom Z → A' \}/);
    assert.match(page, /const LETTERS = Array\.from\(\{ length: 26 \}/);
    assert.match(page, /sort: sort && sort !== 'recent' \? sort : undefined/);
    assert.match(page, /letter: letter \|\| undefined/);

    // Une lettre déjà choisie se referme.
    assert.match(page, /letter === letterFilter\.value \? null : letter/);
});

test('l’export reprend tous les filtres de la page et exige son droit', () => {
    assert.match(page, /v-if="can\('patients\.export'\)" as="a" :href="exportUrl"/);
    assert.match(page, /`\/patients\/export\$\{queryString \? `\?\$\{queryString\}` : ''\}`/);

    // Une seule source de paramètres : l'écran et l'export ne peuvent pas diverger.
    const exportBlock = page.slice(page.indexOf('const exportUrl'), page.indexOf('const submitSearch'));
    assert.match(exportBlock, /directoryParams\(\)/);
    assert.match(page, /router\.get\('\/patients', directoryParams\(overrides\)/);
});

test('« Tout effacer » lève la catégorie et l’initiale', () => {
    assert.match(page, /submitFilters\(\{ need: null, status: null, segment: 'all', letter: null \}\)/);
});

/** Portail : un patient est VIP quand il remplit les DEUX conditions, réglées par site. */
test('le portail règle trois seuils par site et montre ce qu’ils donnent avant d’enregistrer', () => {
    assert.match(portal, /label="Passages au moins"/);
    assert.match(portal, /label="Argent encaissé \(Ar\)"/);
    assert.match(portal, /label="Sur les derniers \(mois\)"/);
    assert.match(portal, /fetch\('\/super-admin\/patient-vip\/preview'/);
    assert.match(portal, /\.put\('\/super-admin\/patient-vip'/);

    // Une réponse tardive d'une frappe précédente ne remplace pas la plus récente.
    assert.match(portal, /if \(seq !== previewSeq\)/);
});

test('un site sans réglage n’a aucun VIP, et le portail ne fait pas semblant du contraire', () => {
    assert.match(portal, /Non réglé — aucun patient VIP/);
    assert.match(portal, /Aucun seuil n’est enregistré : ce site n’a aucun patient VIP/);
});

test('l’entrée « Patients VIP » n’apparaît qu’avec sa permission', () => {
    assert.match(menu, /text: 'Patients VIP', link: '\/super-admin\/patient-vip', permission: 'patient_vip\.view'/);
    assert.match(portal, /const canUpdate = computed\(\(\) => can\('patient_vip\.update'\)\)/);
});
