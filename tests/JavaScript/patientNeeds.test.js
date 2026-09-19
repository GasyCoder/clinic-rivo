import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
    NEED_ALL,
    NEED_EVERYTHING,
    NEED_NONE,
    needCombinationLabel,
    needFilterLabel,
    needStateText,
    needTiles,
    needTitle,
    needVariant,
    statusFilterLabel,
    statusTabs,
} from '../../resources/js/utilities/patientNeeds.js';

/**
 * ADR-119 — où chaque patient a encore besoin d'aller.
 *
 * Le serveur calcule les combinaisons et leurs comptes ; ces fonctions ne font
 * que les dire, et les tests fixent les mots que la Réception lira.
 */
const facets = [
    { key: 'ALL', count: 12 },
    { key: 'MEDICINE', count: 2 },
    { key: 'CARE', count: 1 },
    { key: 'PHARMACY', count: 3 },
    { key: 'MEDICINE,CARE', count: 0 },
    { key: 'MEDICINE,PHARMACY', count: 1 },
    { key: 'CARE,PHARMACY', count: 0 },
    { key: 'MEDICINE,CARE,PHARMACY', count: 1 },
    { key: 'NONE', count: 4 },
];

test('une combinaison se lit dans l’ordre du parcours du patient', () => {
    assert.equal(needCombinationLabel(['MEDICINE']), 'Médecine seulement');
    assert.equal(needCombinationLabel(['CARE']), 'Soins seulement');
    assert.equal(needCombinationLabel(['PHARMACY']), 'Pharmacie seulement');
    // Les soins précèdent le médecin, qui précède la pharmacie — quel que soit l'ordre reçu.
    assert.equal(needCombinationLabel(['MEDICINE', 'CARE']), 'Soins + Médecine');
    assert.equal(needCombinationLabel(['PHARMACY', 'MEDICINE']), 'Médecine + Pharmacie');
    assert.equal(needCombinationLabel(['MEDICINE', 'CARE', 'PHARMACY']), 'Soins + Médecine + Pharmacie');
    assert.equal(needCombinationLabel([]), 'Aucun de ces services');
});

test('les cinq cases du haut sont celles que la Réception a demandées, dans son ordre', () => {
    const tiles = needTiles(facets, null);

    assert.deepEqual(tiles.map((tile) => tile.value), ['ALL', 'MEDICINE', 'CARE', 'PHARMACY', NEED_EVERYTHING]);
    assert.deepEqual(tiles.map((tile) => tile.label), [
        'Tous les patients',
        'Médecine seulement',
        'Soins seulement',
        'Pharmacie seulement',
        'Les 3 services',
    ]);
});

test('le compte d’une case vient du serveur, jamais de la page affichée', () => {
    const tiles = needTiles(facets, null);

    assert.deepEqual(tiles.map((tile) => tile.count), [12, 2, 1, 3, 1]);
});

test('une combinaison absente des comptes vaut zéro, pas « indéfini »', () => {
    assert.deepEqual(needTiles([], null).map((tile) => tile.count), [0, 0, 0, 0, 0]);
});

test('sans filtre, « Tous » est la case active ; sinon c’est celle de la combinaison filtrée', () => {
    assert.deepEqual(needTiles(facets, null).filter((tile) => tile.active).map((tile) => tile.value), [NEED_ALL]);
    assert.deepEqual(needTiles(facets, 'CARE').filter((tile) => tile.active).map((tile) => tile.value), ['CARE']);
    assert.deepEqual(needTiles(facets, NEED_EVERYTHING).filter((tile) => tile.active).map((tile) => tile.value), [NEED_EVERYTHING]);
});

test('chaque case dit ce qu’elle exclut : « seulement » n’est pas « au moins »', () => {
    const tiles = needTiles(facets, null);
    const medicine = tiles.find((tile) => tile.value === 'MEDICINE');

    assert.match(medicine.title, /n’attendent que le médecin/);
    assert.match(medicine.title, /ni les soins, ni la pharmacie/);
});

test('la pastille du filtre actif nomme la combinaison', () => {
    assert.equal(needFilterLabel('MEDICINE'), 'Médecine seulement');
    assert.equal(needFilterLabel('NONE'), 'Aucun de ces services');
    assert.equal(needFilterLabel(NEED_EVERYTHING), 'Les 3 services');
    assert.equal(needFilterLabel('MEDICINE,CARE'), 'Soins + Médecine');
});

test('l’état d’un besoin se dit en mots, sans jamais parler de règlement', () => {
    assert.equal(needStateText({ service: 'MEDICINE', state: 'PENDING' }), 'en attente');
    assert.equal(needStateText({ service: 'MEDICINE', state: 'IN_PROGRESS' }), 'en consultation');
    assert.equal(needStateText({ service: 'CARE', state: 'IN_PROGRESS' }), 'pris en charge');
    assert.equal(needStateText({ service: 'PHARMACY', state: 'PARTIAL' }), 'délivrance partielle');
    // Ni « à régler » ni « prête à délivrer » : ce sont des états financiers (ADR-013, ADR-117).
    assert.equal(needStateText({ service: 'PHARMACY', state: 'PENDING' }), '');
    assert.equal(needStateText({ service: 'PHARMACY', state: 'READY' }), '');
    assert.equal(needStateText({ service: 'PHARMACY', state: 'AWAITING_PAYMENT' }), '');
});

test('une attente est en ambre, une prise en charge en vert, la pharmacie reste neutre', () => {
    assert.equal(needVariant({ service: 'MEDICINE', state: 'PENDING' }), 'warning');
    assert.equal(needVariant({ service: 'CARE', state: 'PENDING' }), 'warning');
    assert.equal(needVariant({ service: 'MEDICINE', state: 'IN_PROGRESS' }), 'success');
    assert.equal(needVariant({ service: 'CARE', state: 'IN_PROGRESS' }), 'success');
    assert.equal(needVariant({ service: 'PHARMACY', state: 'PENDING' }), 'secondary');
    assert.equal(needVariant({ service: 'PHARMACY', state: 'PARTIAL' }), 'secondary');
});

test('l’infobulle dit depuis quand et sur quel passage', () => {
    assert.equal(
        needTitle({ service: 'MEDICINE', state: 'PENDING', episode_number: 'A-26-0009-01' }, 'il y a 12 minutes'),
        'Médecine — en attente · depuis 12 minutes · passage A-26-0009-01',
    );
    assert.equal(
        needTitle({ service: 'CARE', state: 'IN_PROGRESS', episode_number: null }, 'il y a 2 heures'),
        'Soins — pris en charge · depuis 2 heures',
    );
});

test('l’infobulle de la pharmacie parle de médicaments, pas de règlement', () => {
    assert.equal(
        needTitle({ service: 'PHARMACY', state: 'PENDING', episode_number: null }, 'il y a 1 heure'),
        'Pharmacie — médicaments à retirer · depuis 1 heure',
    );
});

test('une heure dans le futur ou absente ne fabrique pas un « depuis » absurde', () => {
    const need = { service: 'MEDICINE', state: 'PENDING', episode_number: null };

    assert.equal(needTitle(need, 'dans 2 minutes'), 'Médecine — en attente');
    assert.equal(needTitle(need, null), 'Médecine — en attente');
    assert.equal(needTitle(need), 'Médecine — en attente');
});

const page = fs.readFileSync('resources/js/Pages/Patients/Index.vue', 'utf8');
const badges = fs.readFileSync('resources/js/Components/Clinical/PatientNeedBadges.vue', 'utf8');

test('la liste des patients affiche les compteurs par le composant partagé des files', () => {
    assert.match(page, /import QueueCounters from '@\/Components\/Clinical\/QueueCounters\.vue'/);
    assert.match(page, /<QueueCounters :tiles="tiles"/);
    assert.match(page, /needTiles\(facets\.value, needFilter\.value\)/);
});

test('les comptes ne sont jamais recalculés depuis la page affichée', () => {
    // La liste est paginée : un compte tiré de `patients.data` mentirait dès la deuxième page.
    assert.doesNotMatch(page, /patients\.data\.(filter|reduce)\([^)]*needs/);
    assert.match(page, /props\.needs\?\.facets/);
});

test('le besoin choisi voyage dans l’adresse et repart avec les autres filtres', () => {
    assert.match(page, /need: need \|\| undefined/);
    assert.match(page, /const needFilter = computed\(\(\) => props\.filters\?\.need \?\? null\)/);
});

test('cliquer une case déjà active la referme, et « Tous » efface le filtre', () => {
    assert.match(page, /key === NEED_ALL \|\| key === needFilter\.value \? null : key/);
});

test('la recherche se lance d’elle-même après une courte pause', () => {
    assert.match(page, /setTimeout\(\(\) => \{[\s\S]*?\}, 350\)/);
    assert.match(page, /onBeforeUnmount\(\(\) => clearTimeout\(searchTimer\)\)/);
});

test('le besoin se lit dans la colonne « Situation actuelle », qui remplace « Catégorie » : « Standard » ne disait rien', () => {
    assert.match(page, />Situation actuelle</);
    assert.doesNotMatch(page, />Catégorie</);
    assert.match(page, /<PatientNeedBadges v-if="patient\.needs\?\.length"/);
    // Seule une catégorie qui change la prise en charge s'affiche encore sur la ligne.
    assert.match(page, /const hasSpecialType = \(patient\) =>/);
});

test('les pastilles de besoin s’écrivent avec les mots partagés, sans copie locale', () => {
    assert.match(badges, /from '@\/utilities\/patientNeeds'/);
    // Aucun libellé d'état écrit ici : « en attente » se dit au même endroit partout.
    assert.doesNotMatch(badges, /['"`](en consultation|pris en charge|en attente|délivrance partielle)/);
});

test('l’urgence reste visible au premier coup d’œil', () => {
    assert.match(page, /v-if="emergencyCount > 0"/);
    assert.match(page, /@click="focusEmergencies"/);
});

test('le défilement du tableau ne déborde pas sur la page entière', () => {
    // `sr-only` est en position absolue : sans conteneur `relative`, il échappe au
    // défilement du tableau et élargit la page au lieu du seul tableau.
    assert.match(page, /class="relative overflow-x-auto"/);
});

test('ADR-120 : les onglets reprennent les états de la ligne et portent les comptes du serveur', () => {
    const tabs = statusTabs({ status: { all: 13, open: 4, settlement: 2, none: 7 } }, 'settlement');

    assert.deepEqual(tabs.map((tab) => [tab.label, tab.count, tab.active]), [
        ['Tous', 13, false],
        ['Besoin en cours', 4, false],
        ['En attente de règlement', 2, true],
        ['Aucun passage ouvert', 7, false],
    ]);
});

test('ADR-120 : sans filtre « Tous » est actif, un compte absent vaut zéro, et « Tous » n’a pas de pastille', () => {
    assert.deepEqual(statusTabs(undefined, null).filter((tab) => tab.active).map((tab) => tab.key), ['all']);
    assert.equal(statusTabs(undefined, null)[1].count, 0);
    assert.equal(statusFilterLabel('none'), 'Aucun passage ouvert');
    assert.equal(statusFilterLabel(null), null);
    assert.equal(statusFilterLabel('zzz'), null);
});

test('ADR-120 : la page renvoie l’onglet au serveur et n’a plus ni pastilles de combinaisons ni ancienneté', () => {
    assert.match(page, /status: status \|\| undefined/);
    assert.match(page, /role="tablist"/);
    assert.doesNotMatch(page, /Autres situations|Ancienneté|selectSegment/);
});
