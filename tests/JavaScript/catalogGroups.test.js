import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { catalogUrls, isPortalCatalog } from '../../resources/js/utilities/catalogUrls.js';
import {
    CATEGORY_VIEWS,
    IMAGING_UNCLASSIFIED,
    catalogAttention,
    catalogCategories,
    catalogGroupKey,
    catalogGroups,
    categoryChoices,
    categoryFields,
    categoryForSearch,
    disciplineLabel,
    duplicateNames,
    laboratoryDisciplines,
    matchesAttention,
    matchesSearch,
    normalizeLabel,
    tariffsUrl,
} from '../../resources/js/utilities/catalogGroups.js';

/**
 * Tarifs & mutuelles, module par module : l'Imagerie se sépare en Échographie
 * et ECG sur la famille réglée au catalogue (ADR-106), le Laboratoire se lit
 * par discipline et renvoie à ses analyses (ADR-063). Rien n'est deviné.
 */
const read = (file) => fs.readFileSync(file, 'utf8');
const MODULES = [
    { value: 'MEDICINE', label: 'Médecine' },
    { value: 'LABORATORY', label: 'Laboratoire' },
    { value: 'IMAGING', label: 'Imagerie' },
    { value: 'SURGERY', label: 'Chirurgie' },
];
const item = (overrides) => ({
    uuid: overrides.code,
    module_label: 'Domaine',
    billable: true,
    archived: false,
    staff_coverage_policy: 'ORDINARY_FULL_COVERAGE',
    current_standard_tariff: null,
    analyses_count: 0,
    ...overrides,
});

test('l’Imagerie se sépare sur la famille réglée, jamais sur le code', () => {
    assert.equal(catalogGroupKey(item({ code: 'HOLTER-ECG', module: 'IMAGING', imaging_modality: 'CARDIOLOGY' })), 'IMAGING:CARDIOLOGY');
    assert.equal(catalogGroupKey(item({ code: 'DOPPLER-MI-ART', module: 'IMAGING', imaging_modality: 'ULTRASOUND' })), 'IMAGING:ULTRASOUND');
    // Un code qui « ressemble » à un ECG ne suffit pas : sans famille, il reste non classé.
    assert.equal(catalogGroupKey(item({ code: 'ECG', module: 'IMAGING', imaging_modality: null })), IMAGING_UNCLASSIFIED);
    assert.equal(catalogGroupKey(item({ code: 'NFS', module: 'LABORATORY' })), 'LABORATORY');
});

test('les groupes suivent l’ordre du site, sans groupe vide, avec leur avancement de tarification', () => {
    const groups = catalogGroups([
        item({ code: 'NFS', module: 'LABORATORY', current_standard_tariff: { amount: '8000' } }),
        item({ code: 'GLY', module: 'LABORATORY' }),
        item({ code: 'ECG', module: 'IMAGING', imaging_modality: 'CARDIOLOGY' }),
        item({ code: 'ECHO', module: 'IMAGING', imaging_modality: 'ULTRASOUND', current_standard_tariff: { amount: '50000' } }),
        item({ code: 'X', module: 'IMAGING', imaging_modality: null }),
    ], MODULES);

    assert.deepEqual(groups.map((group) => group.key), ['LABORATORY', 'IMAGING:ULTRASOUND', 'IMAGING:CARDIOLOGY', IMAGING_UNCLASSIFIED]);
    assert.deepEqual(groups.map((group) => group.label), ['Laboratoire', 'Échographie', 'ECG / Cardiologie', 'Imagerie non classée']);
    assert.deepEqual(groups[0], { key: 'LABORATORY', module: 'LABORATORY', label: 'Laboratoire', count: 2, billable: 2, priced: 1, unclassified: false });
    assert.equal(groups[3].unclassified, true);
});

test('deux désignations du même domaine au même nom sont signalées, pas d’un domaine à l’autre ni archivées', () => {
    const duplicates = duplicateNames([
        item({ code: 'LAB-GLYC', module: 'LABORATORY', name: 'Glycémie' }),
        item({ code: 'LEGACY-LAB-16', module: 'LABORATORY', name: ' glycemie ' }),
        item({ code: 'LEGACY-LAB-429', module: 'LABORATORY', name: 'GLYCÉMIE', archived: true }),
        item({ code: 'MED-GLYC', module: 'MEDICINE', name: 'Glycémie' }),
    ]);

    assert.deepEqual([...duplicates.keys()].sort(), ['LAB-GLYC', 'LEGACY-LAB-16']);
    assert.deepEqual(duplicates.get('LAB-GLYC'), [{ uuid: 'LEGACY-LAB-16', code: 'LEGACY-LAB-16' }]);
    assert.equal(normalizeLabel('  À l’état   FRAIS '), "a l'etat frais");
});

test('le Laboratoire se lit par discipline, lue sur l’analyse racine et jamais corrigée en silence', () => {
    const disciplines = laboratoryDisciplines([
        item({ code: 'A', module: 'LABORATORY', analysis_discipline: 'BIOCHIMIE' }),
        item({ code: 'B', module: 'LABORATORY', analysis_discipline: 'biochimie' }),
        item({ code: 'C', module: 'LABORATORY', analysis_discipline: 'BIOCHIME' }),
        item({ code: 'D', module: 'LABORATORY', analysis_discipline: null }),
        item({ code: 'E', module: 'IMAGING', analysis_discipline: 'BIOCHIMIE' }),
    ]);

    // « BIOCHIME » (faute de saisie du catalogue des analyses) reste distinct : c'est à corriger là-bas.
    assert.deepEqual(disciplines.map((discipline) => [discipline.label, discipline.count]), [['Biochimie', 2], ['Biochime', 1], ['Sans discipline', 1]]);
    assert.equal(disciplineLabel('SEROLOGIE (TECHNIQUE ELISA, TECHNIQUE IMMUNOCHROMATOGRAPHIQUE)'), 'Serologie');
});

test('les points d’attention comptent ce qui reste à régler, sans rien régler à la place du Super Admin', () => {
    const items = [
        item({ code: 'A', module: 'LABORATORY', name: 'Glycémie', staff_coverage_policy: 'UNCLASSIFIED', analyses_count: 1 }),
        item({ code: 'B', module: 'LABORATORY', name: 'Glycémie', analyses_count: 0 }),
        item({ code: 'C', module: 'IMAGING', name: 'ECG', imaging_modality: null }),
        item({ code: 'D', module: 'IMAGING', name: 'Holter', imaging_modality: 'CARDIOLOGY', archived: true }),
        item({ code: 'E', module: 'MEDICINE', name: 'Consultation', billable: false, staff_coverage_policy: 'UNCLASSIFIED' }),
    ];
    assert.deepEqual(catalogAttention(items), { staffUnclassified: 1, duplicates: 2, imagingUnclassified: 1, laboratoryWithoutAnalysis: 1 });

    const duplicates = duplicateNames(items);
    assert.deepEqual(items.filter((entry) => matchesAttention(entry, 'LAB_NO_ANALYSIS', duplicates)).map((entry) => entry.code), ['B']);
    assert.deepEqual(items.filter((entry) => matchesAttention(entry, 'STAFF', duplicates)).map((entry) => entry.code), ['A']);
    assert.equal(items.filter((entry) => matchesAttention(entry, '', duplicates)).length, items.length);
});

test('tarifs et analyses se renvoient l’un à l’autre, au site comme au portail', () => {
    assert.equal(catalogUrls({ mode: 'portal' }, 'A').analyses('u-1'), '/super-admin/analyses?site=A&catalog_item=u-1');
    assert.equal(catalogUrls({ mode: 'site' }, 'A').analyses('u-1'), '/administration/analyses?catalog_item=u-1');
    assert.equal(tariffsUrl('A', 'LEGACY-LAB-16'), '/super-admin/workspaces/tariffs?site=A&q=LEGACY-LAB-16');

    const tariffs = read('resources/js/Pages/Catalog/Index.vue');
    assert.match(tariffs, /catalogCategories\(/);
    assert.match(tariffs, /urls\.analyses\(/);
    assert.match(tariffs, /imaging_modality/);
    const analyses = read('resources/js/Pages/Analyses/Index.vue');
    assert.match(analyses, /tariffsUrl\(/);
    assert.match(analyses, /filters\.catalog_item/);
});

test('les adresses de « Désignations & tarifs » suivent le contexte donné par le serveur', () => {
    const portal = catalogUrls({ mode: 'portal' }, 'A');
    const site = catalogUrls({ mode: 'site' }, 'A');

    assert.equal(isPortalCatalog({ mode: 'portal' }), true);
    assert.equal(isPortalCatalog({ mode: 'site' }), false);
    // Un contexte absent vaut le portail : jamais une adresse du site devinée par l'écran.
    assert.equal(isPortalCatalog(undefined), true);

    assert.equal(portal.index({ module: 'LABORATORY' }), '/super-admin/workspaces/tariffs?site=A&module=LABORATORY');
    assert.equal(portal.create('IMAGING:ULTRASOUND'), '/super-admin/workspaces/tariffs/items/create?site=A&module=IMAGING%3AULTRASOUND');
    assert.equal(portal.edit('u-1', 'MUTUAL'), '/super-admin/workspaces/tariffs/items/A/u-1/edit?grille=MUTUAL#tarifs');
    assert.equal(portal.tariff('u-1'), '/super-admin/workspaces/tariffs/items/A/u-1/tariffs');
    assert.equal(portal.careConsumables('u-1'), '/super-admin/workspaces/tariffs/items/A/u-1/care-consumables');
    assert.equal(portal.pendingReview, undefined);

    assert.equal(site.index({ module: 'LABORATORY' }), '/administration/catalog?module=LABORATORY');
    assert.equal(site.index(), '/administration/catalog');
    assert.equal(site.create(null), '/administration/catalog/create');
    assert.equal(site.store, '/administration/catalog');
    assert.equal(site.edit('u-1'), '/administration/catalog/u-1/edit');
    assert.equal(site.tariff('u-1'), '/administration/catalog/u-1/tariff');
    assert.equal(site.tariffArchive('u-1'), '/administration/catalog/u-1/tariff/archive');
    assert.equal(site.careConsumables('u-1'), '/administration/catalog/u-1/care-consumables');
    assert.equal(site.pendingReview(7), '/administration/catalog/pending-medicines/7/review');
});

test('un seul écran de désignations : aucune page ni adresse du portail écrite en dur', () => {
    // L'ancien écran DashWind du site n'existe plus : le site lit la même page que le portail.
    assert.equal(fs.existsSync('resources/js/Pages/Administration/Catalog/Index.vue'), false);
    assert.equal(fs.existsSync('resources/js/Pages/SuperAdmin/Tariffs'), false);

    const list = read('resources/js/Pages/Catalog/Index.vue');
    const page = read('resources/js/Pages/Catalog/ItemForm.vue');
    [list, page].forEach((source) => {
        assert.match(source, /catalogUrls\(props\.context/);
        assert.doesNotMatch(source, /['`]\/super-admin\/workspaces\/tariffs\/items/);
        assert.doesNotMatch(source, /['`]\/administration\/catalog/);
    });
    // Au site : ni choix de site, ni Excel, ni sélection multiple, ni mutuelles.
    assert.match(list, /<SettingsSiteSwitcher v-if="portal && /);
    assert.match(list, /<DropdownMenu v-if="portal && /);
    assert.match(list, /const showOrganizations = portal && /);
    // Les médicaments prescrits hors référentiel restent au site (ADR-037).
    assert.match(list, /const showPending = ! portal && /);
    assert.match(list, /<PendingMedicinesPanel/);
});

test('chaque catégorie se compte à part : en service, archivées, et ce qui reste à tarifer dans chaque grille', () => {
    const categories = catalogCategories([
        item({ code: 'NFS', module: 'LABORATORY', current_standard_tariff: { amount: '8000' }, current_mutual_tariff: { amount: '7000' } }),
        item({ code: 'GLY', module: 'LABORATORY', current_standard_tariff: { amount: '5000' } }),
        item({ code: 'OLD', module: 'LABORATORY', archived: true }),
        item({ code: 'FREE', module: 'LABORATORY', billable: false }),
        item({ code: 'ECHO', module: 'IMAGING', imaging_modality: 'ULTRASOUND' }),
    ], MODULES);

    assert.deepEqual(categories.map((category) => category.key), ['LABORATORY', 'IMAGING:ULTRASOUND']);
    assert.deepEqual(categories[0], {
        key: 'LABORATORY', module: 'LABORATORY', label: 'Laboratoire', unclassified: false,
        active: 3, archived: 1, billable: 2, missingStandard: 0, missingMutual: 1,
    });
    assert.equal(categories[1].missingStandard, 1);
});

test('les onglets d’une catégorie gardent ce qu’ils disent, jamais une archivée parmi les actives', () => {
    const priced = item({ code: 'A', current_standard_tariff: { amount: '1' } });
    const unpriced = item({ code: 'B' });
    const free = item({ code: 'C', billable: false });
    const archived = item({ code: 'D', archived: true });
    const keep = (view) => [priced, unpriced, free, archived].filter(CATEGORY_VIEWS[view]).map((entry) => entry.code);

    assert.deepEqual(keep('ACTIVE'), ['A', 'B', 'C']);
    assert.deepEqual(keep('MISSING_STANDARD'), ['B']);
    assert.deepEqual(keep('MISSING_MUTUAL'), ['A', 'B']);
    assert.deepEqual(keep('ARCHIVED'), ['D']);
});

test('une recherche venue du catalogue des analyses ouvre la catégorie de son code, sans accents ni casse', () => {
    const items = [
        item({ code: 'CONSULT', module: 'MEDICINE', name: 'Consultation' }),
        item({ code: 'LEGACY-LAB-16', module: 'LABORATORY', name: 'Glycémie' }),
        item({ code: 'ECHO-ABD', module: 'IMAGING', name: 'Échographie abdominale', imaging_modality: 'ULTRASOUND' }),
    ];

    assert.equal(categoryForSearch(items, 'legacy-lab-16'), 'LABORATORY');
    assert.equal(categoryForSearch(items, 'echographie'), 'IMAGING:ULTRASOUND');
    assert.equal(categoryForSearch(items, 'inconnu'), null);
    assert.equal(categoryForSearch(items, ''), null);
    assert.equal(matchesSearch(items[1], 'GLYCEMIE'), true);
    assert.equal(matchesSearch(items[1], ''), true);
});

test('Désignations & tarifs : une catégorie à la fois, sans cartes colorées ni bandeau jaune', () => {
    const page = read('resources/js/Pages/Catalog/Index.vue');
    const nav = read('resources/js/Components/Catalog/CatalogCategoryNav.vue');

    assert.match(page, /<CatalogCategoryNav/);
    assert.match(nav, /aria-current/);
    // Plus aucune vue « Tous » qui mélange les catégories, ni les cartes-compteurs colorées.
    assert.doesNotMatch(page, /QueueCounters/);
    assert.doesNotMatch(page, /chooseGroup\(''\)/);
    assert.doesNotMatch(page, /amber-|emerald-/);
    // L'export d'une catégorie part au serveur avec sa clé, famille d'imagerie comprise.
    assert.match(page, /params\.set\('module', category\)/);
});

test('le formulaire range une désignation dans une catégorie : un domaine, ou une famille d’imagerie', () => {
    assert.deepEqual(categoryChoices(MODULES).map((choice) => choice.value), [
        'MEDICINE', 'LABORATORY', 'IMAGING:ULTRASOUND', 'IMAGING:CARDIOLOGY', IMAGING_UNCLASSIFIED, 'SURGERY',
    ]);
    assert.deepEqual(categoryFields('LABORATORY'), { module: 'LABORATORY', imaging_modality: '' });
    assert.deepEqual(categoryFields('IMAGING:CARDIOLOGY'), { module: 'IMAGING', imaging_modality: 'CARDIOLOGY' });
    // « Non classée » n'écrit aucune famille : elle se choisira plus tard, jamais devinée.
    assert.deepEqual(categoryFields(IMAGING_UNCLASSIFIED), { module: 'IMAGING', imaging_modality: '' });
    // Aller-retour : la catégorie écrite est celle que la liste relira.
    ['LABORATORY', 'IMAGING:ULTRASOUND', IMAGING_UNCLASSIFIED].forEach((key) => {
        assert.equal(catalogGroupKey(categoryFields(key)), key);
    });
});

test('une désignation se crée et se modifie sur sa page ; le motif d’un tarif peut être automatique', () => {
    const list = read('resources/js/Pages/Catalog/Index.vue');
    const page = read('resources/js/Pages/Catalog/ItemForm.vue');

    // Plus de fenêtre de désignation ni de tarif dans la liste : des liens vers la page.
    assert.doesNotMatch(list, /itemModalOpen|tariffTarget|Nouvelle désignation'\s*:/);
    assert.match(list, /urls\.value\.create\(/);
    assert.match(list, /urls\.value\.edit\(item\.uuid, grid\)/);
    // La page : case « Motif automatique » cochée par défaut, le texte libre seulement si on la décoche.
    assert.match(page, /tariff_reason_auto: true/);
    assert.match(page, /reason_auto: true/);
    assert.match(page, /v-if="! form\.tariff_reason_auto"/);
    // Le navigateur n'envoie jamais le texte du motif automatique : le serveur l'écrit.
    assert.match(page, /tariff_reason: data\.tariff_reason_auto \? '' : data\.tariff_reason/);
    // Suspendre un tarif ou archiver reste une décision dont le motif s'écrit à la main.
    assert.match(page, /confirmationForm\.reason\.trim\(\)/);
    // Une prop `site` masquerait la prop partagée du même nom : le menu du portail disparaîtrait.
    assert.doesNotMatch(page, /^\s{4}site:\s*\{/m);
    assert.match(page, /targetSite: \{ type: Object, required: true \}/);
});
