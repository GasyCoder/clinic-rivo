import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { stayDays, bloodPressure } from '../../resources/js/utilities/hospitalStay.js';

const index = fs.readFileSync('resources/js/Pages/Hospitalization/Index.vue', 'utf8');
const dietOne = fs.readFileSync('resources/js/Pages/Hospitalization/DietSheetPrint.vue', 'utf8');
const dietMany = fs.readFileSync('resources/js/Pages/Hospitalization/DietSheetsPrint.vue', 'utf8');
const dietBody = fs.readFileSync('resources/js/Components/Hospitalization/DietSheetBody.vue', 'utf8');
const records = fs.readFileSync('resources/js/Pages/Hospitalization/MedicalRecordsPrint.vue', 'utf8');
const record = fs.readFileSync('resources/js/Pages/Medicine/MedicalRecordPrint.vue', 'utf8');
const wardRound = fs.readFileSync('resources/js/Pages/Hospitalization/WardRoundPrint.vue', 'utf8');

/** ADR-165 — les patients cochés, et seulement des actions en lecture. */
test('la liste coche des patients et propose quatre actions, toutes en lecture', () => {
    assert.match(index, /import Checkbox from '@\/Components\/Shadcn\/Checkbox\.vue'/);
    assert.match(index, /aria-label="Sélectionner tous les patients de la page"/);
    assert.match(index, /role="toolbar"/);
    for (const path of ['tour-de-salle', 'regimes', 'dossiers-medicaux', 'export']) {
        assert.ok(index.includes(`bulkHref('${path}')`), `action ${path} absente`);
    }
    // Aucune décision médicale en lot : ni sortie, ni transfert, ni bloc, ni lit.
    assert.doesNotMatch(index, /bulkHref\('(sortie|transfert|bloc|mouvements)'\)/);
});

test('une action n’est proposée qu’avec son droit ; le serveur revérifie', () => {
    assert.match(index, /v-if="capabilities\.can_print_medical_records"\s+:as="Link"\s+:href="bulkHref\('dossiers-medicaux'\)"/);
    assert.match(index, /v-if="capabilities\.can_export"\s+as="a"\s+:href="bulkHref\('export'\)"/);
});

test('une sélection ne survit ni à un changement de page, ni à une recherche, ni à un filtre', () => {
    assert.match(index, /watch\(\(\) => \[props\.stays\?\.current_page, props\.search, props\.filter\], \(\) => \{ selected\.value = \[\]; \}\)/);
    assert.match(index, /const overLimit = computed\(\(\) => selectedRows\.value\.length > props\.bulkLimit\)/);
});

test('la liste porte des icônes shadcn / lucide, jamais la police DashWind', () => {
    assert.match(index, /from 'lucide-vue-next'/);
    assert.doesNotMatch(index, /(?<![a-z-])(ni ni-|nk-)/);
    assert.match(index, /<Avatar/);
});

/** Un seul corps de fiche de régime : deux copies finiraient par diverger. */
test('la fiche de régime seule et les fiches réunies partagent le même corps', () => {
    assert.match(dietOne, /<DietSheetBody :stay="stay" \/>/);
    assert.match(dietMany, /<DietSheetBody v-for="sheet in sheets" :key="sheet\.uuid" :stay="sheet" \/>/);
    assert.match(dietMany, /\.ds-many > \.ds-sheet \+ \.ds-sheet \{\s+break-before: page;/);
    assert.match(dietBody, /FICHE DE RÉGIME/);
});

test('les dossiers réunis sont la feuille du passage elle-même, une par page', () => {
    assert.match(record, /showActions: \{ type: Boolean, default: true \}/);
    assert.match(record, /:show-actions="showActions"/);
    assert.match(records, /<MedicalRecordPrint[\s\S]*:show-actions="index === 0"/);
    assert.match(records, /\.mrs-doc > \.ps-page \+ \.ps-page/);
});

test('le tour de salle passe seul en paysage, et tait le relevé sans droit', () => {
    // Une page nommée est ignorée dans les conteneurs flex du layout : la règle
    // est injectée au montage, retirée en quittant la page, jamais au rendu serveur.
    assert.match(wardRound, /onMounted\(\(\) => \{[\s\S]*'@page \{ size: A4 landscape; margin: 10mm; \}'/);
    assert.match(wardRound, /onBeforeUnmount\(\(\) => document\.getElementById\(PAGE_STYLE_ID\)\?\.remove\(\)\)/);
    assert.doesNotMatch(wardRound, /@page wardround/);
    assert.match(wardRound, /<th v-if="vitals_visible"/);
    assert.match(wardRound, /Aucun relevé pendant le séjour/);
});

test('la durée de séjour se compte de la même façon partout', () => {
    const now = new Date('2026-09-21T12:00:00Z');
    assert.equal(stayDays({ admitted_at: '2026-09-21T08:00:00Z' }, now), 'Moins d’un jour');
    assert.equal(stayDays({ admitted_at: '2026-09-18T08:00:00Z' }, now), '3 jours');
    assert.equal(stayDays({ admitted_at: '2026-09-18T08:00:00Z', discharged_at: '2026-09-19T09:00:00Z' }, now), '1 jour');
    assert.equal(bloodPressure({ blood_pressure_systolic: 120, blood_pressure_diastolic: 80 }), '120/80');
    assert.equal(bloodPressure({ blood_pressure_systolic: 120 }), '—');
    assert.match(index, /import \{ stayDays \} from '@\/utilities\/hospitalStay'/);
});
