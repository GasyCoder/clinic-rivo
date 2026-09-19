import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
    followUpShort,
    itemQuantity,
    pillLabel,
    stepIcon,
    stepStyle,
    stepTitle,
} from '../../resources/js/utilities/episodePathway.js';

/**
 * Le parcours d'un passage (ADR-117) : ce que la frise du dossier patient et
 * la liste du « Détail du passage » ont en commun.
 *
 * Le défaut signalé : deux orientations vers le même service se lisaient
 * « Soins », « Soins » — un doublon apparent alors que ce sont deux demandes
 * distinctes du médecin, chacune avec sa suite.
 */
const step = (overrides = {}) => ({
    key: 'k',
    type: 'ORIENTATION',
    module: 'CARE',
    label: 'Soins',
    from_label: 'Médecine',
    qualifier: null,
    sequence: null,
    sequence_total: null,
    state: 'DONE',
    state_label: 'Orienté',
    notes: [],
    follow_up: null,
    items: [],
    ...overrides,
});

test('deux étapes vers le même service ne portent jamais le même mot', () => {
    const first = step({ sequence: 1, sequence_total: 2 });
    const second = step({ sequence: 2, sequence_total: 2 });

    assert.equal(pillLabel(first), 'Soins 1');
    assert.equal(pillLabel(second), 'Soins 2');
    assert.notEqual(pillLabel(first), pillLabel(second));
});

test('un service visité une seule fois garde son nom tout court', () => {
    assert.equal(pillLabel(step()), 'Soins');
    assert.equal(pillLabel(step({ sequence: 1, sequence_total: 1 })), 'Soins');
});

test('la suite décidée par le médecin se lit en deux mots dans la frise', () => {
    assert.equal(followUpShort(step({ follow_up: { code: 'RETURN_TO_MEDICINE', label: '…' } })), 'retour Médecine');
    assert.equal(followUpShort(step({ follow_up: { code: 'DIRECT_EXIT', label: '…' } })), 'sortie directe');
});

test('une étape sans demande de soins derrière elle n’invente aucune suite', () => {
    assert.equal(followUpShort(step()), null);
    assert.equal(followUpShort(step({ follow_up: { code: 'AUTRE', label: '…' } })), null);
});

test('l’infobulle garde le détail que la frise ne peut pas afficher', () => {
    const title = stepTitle(step({
        sequence: 2,
        sequence_total: 2,
        notes: ['Demandé par Dr Rakoto'],
        follow_up: { code: 'DIRECT_EXIT', label: 'Sortie directe après les soins (sans retour en Médecine)' },
    }));

    assert.deepEqual(title.split('\n'), [
        'Soins 2 — Orienté',
        'Médecine → Soins',
        'Demandé par Dr Rakoto',
        'Sortie directe après les soins (sans retour en Médecine)',
    ]);
});

test('« 1 » se tait, « 2 » se dit', () => {
    assert.equal(itemQuantity('1.00'), null);
    assert.equal(itemQuantity('2.00'), '×2');
    assert.equal(itemQuantity('2.50'), '×2.5');
    assert.equal(itemQuantity(null), null);
});

test('chaque état a sa couleur, et un état inconnu se lit comme à venir plutôt que de casser', () => {
    assert.equal(stepStyle({ state: 'DONE' }).badge, 'success');
    assert.equal(stepStyle({ state: 'ACTIVE' }).badge, 'secondary');
    assert.equal(stepStyle({ state: 'WARNING' }).badge, 'warning');
    assert.equal(stepStyle({ state: 'CANCELLED' }).badge, 'destructive');
    assert.equal(stepStyle({ state: 'PENDING' }).badge, 'outline');
    assert.equal(stepStyle({ state: 'JAMAIS_VU' }).badge, 'outline');
});

test('la Réception, la Pharmacie et la Caisse ont leur propre icône, un module inconnu un repli', () => {
    const icons = [
        stepIcon({ type: 'RECEPTION', module: 'RECEPTION' }),
        stepIcon({ type: 'ORIENTATION', module: 'CARE' }),
        stepIcon({ type: 'ORIENTATION', module: 'MEDICINE' }),
        stepIcon({ type: 'PHARMACY', module: 'PHARMACY' }),
        stepIcon({ type: 'PAYMENT', module: null }),
        stepIcon({ type: 'INVOICE', module: null }),
        stepIcon({ type: 'EXIT', module: 'RECEPTION' }),
    ];

    // Sept étapes, sept icônes : aucune ne se confond avec une autre à l'écran.
    assert.equal(new Set(icons).size, icons.length);
    assert.ok(stepIcon({ type: 'ORIENTATION', module: 'SERVICE_INCONNU' }));
});

/**
 * Le dossier du patient et le détail du passage racontaient chacun le parcours
 * à leur façon : la frise le recomposait côté Vue à partir des seules
 * orientations. Les deux lisent désormais le même parcours, servi par Laravel.
 */
test('le dossier patient et le détail du passage affichent le même parcours, sans le recomposer', () => {
    const patientPage = fs.readFileSync('resources/js/Pages/Patients/Show.vue', 'utf8');
    const episodePage = fs.readFileSync('resources/js/Pages/Episodes/Show.vue', 'utf8');

    // `[^>]*` ne convient pas : le `v-if` de la frise contient lui-même un « > ».
    assert.match(patientPage, /<EpisodePathwayTrail\b[\s\S]{0,120}?:steps="episode\.pathway"/);
    assert.match(episodePage, /<EpisodePathwayList\b[\s\S]{0,120}?:steps="episode\.pathway"/);

    // Plus aucune dérivation locale depuis les seules orientations.
    assert.doesNotMatch(patientPage, /const episodeSteps\b/);
    assert.doesNotMatch(episodePage, /orientation\.destination_module_label/);
});

test('la frise n’est affichée que lorsqu’il y a un parcours à montrer au-delà de la Réception', () => {
    const patientPage = fs.readFileSync('resources/js/Pages/Patients/Show.vue', 'utf8');

    assert.match(patientPage, /v-if="episode\.pathway\?\.length > 1"/);
});
