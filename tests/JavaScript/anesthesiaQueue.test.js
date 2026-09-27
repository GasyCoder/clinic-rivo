import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Anesthesia/Index.vue', 'utf8');
const markup = page.replace(/<!--[\s\S]*?-->/g, '');

/** ADR-099 : la file Anesthésie tient sur la couche partagée, sans reliquat DashWind. */
test('la file Anesthésie utilise les primitives shadcn', () => {
    for (const component of ['Badge', 'Button', 'Card', 'IconInput']) {
        assert.ok(page.includes(`@/Components/Shadcn/${component}.vue`), `${component} n’est pas utilisé`);
    }

    assert.doesNotMatch(page, /variant="white-outline"|size="rg"|@\/Components\/UI\/Card\.vue|\bnk-|ni ni-/);
});

/**
 * ADR-135 : quatre étapes exclusives, servies par le serveur. Les cartes sont
 * le filtre ; leur compte ne suit ni la page ni la recherche.
 */
test('quatre étapes en cartes-filtres, comptées par le serveur', () => {
    for (const value of ['assess', 'cleared', 'intra', 'done']) {
        assert.match(page, new RegExp(`value: '${value}'`), `l’étape ${value} manque`);
        assert.match(page, new RegExp(`count: props\\.counts\\.${value}`));
    }

    assert.match(page, /<QueueCounters :tiles="counterTiles"/);
    assert.doesNotMatch(page, /count:[^,\n]*\.data\b/);
});

test('chaque ligne porte son étape, et la recherche garde l’étape choisie', () => {
    assert.match(page, /request\.stage_label/);
    assert.match(page, /STAGE_TONES\[request\.stage\]/);
    // L'étape par défaut n'encombre pas l'adresse ; les autres y restent.
    assert.match(page, /stage: stage === 'assess' \? undefined : stage/);
    assert.match(page, /visit\(props\.stage, value\)/);
});

test('chaque étape a sa vue vide, et une recherche sans résultat le dit', () => {
    assert.match(page, /Aucun dossier à évaluer/);
    assert.match(page, /Aucun dossier transmis à Chirurgie/);
    assert.match(page, /Aucune intervention au bloc/);
    assert.match(page, /Aucun dossier terminé/);
    assert.match(page, /Aucun dossier ne correspond à la recherche/);
});

test('l’en-tête est celui, partagé, du module Soins', () => {
    assert.match(page, /<SoinsWorkspaceHeader/);
    assert.match(page, /<SoinsTabs current="anesthesia"/);
    assert.doesNotMatch(markup, /<header /);
});

test('les trois espaces du module partagent le même en-tête', () => {
    for (const path of ['Care/Index', 'Maternity/Index', 'Anesthesia/Index']) {
        const source = fs.readFileSync(`resources/js/Pages/${path}.vue`, 'utf8');
        assert.match(source, /<SoinsWorkspaceHeader/, `${path} n’utilise pas l’en-tête partagé`);
    }
});
