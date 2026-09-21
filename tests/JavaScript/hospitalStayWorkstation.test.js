import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/**
 * ADR-162 — le séjour est le poste de travail du patient hospitalisé : note du
 * jour, ordonnances, examens, soins et sortie s'y font, sans rouvrir de
 * consultation.
 */
const show = fs.readFileSync('resources/js/Pages/Hospitalization/Show.vue', 'utf8');
const components = Object.fromEntries(['StayNotes', 'StayPrescriptions', 'StayExams', 'StayCareOrders', 'StayExit']
    .map((name) => [name, fs.readFileSync(`resources/js/Components/Hospitalization/${name}.vue`, 'utf8')]));

test('la page du séjour se lit en onglets, un par geste', () => {
    for (const key of ['apercu', 'notes', 'ordonnances', 'examens', 'soins', 'surveillance', 'regime', 'bloc', 'sortie']) {
        assert.match(show, new RegExp(`key: '${key}'`), `onglet ${key} manquant`);
    }
    for (const name of Object.keys(components)) {
        assert.match(show, new RegExp(`<${name}\\b`), `${name} n'est pas monté`);
    }
    // SSR : l'onglet de l'adresse ne se lit qu'une fois la page reprise par le navigateur.
    assert.match(show, /onMounted\(\(\) => \{\s*const key = window\.location\.hash/);
    assert.doesNotMatch(show.slice(0, show.indexOf('onMounted')), /localStorage/);
});

test('chaque geste part vers le séjour, jamais vers une consultation', () => {
    const routes = {
        StayNotes: '/notes', StayPrescriptions: '/ordonnances', StayCareOrders: '/soins', StayExit: '/sortie',
    };
    for (const [name, segment] of Object.entries(routes)) {
        assert.ok(components[name].includes(`/hospitalisation/\${props.stayUuid}${segment}`), `${name} → ${segment}`);
        assert.doesNotMatch(components[name], /\/medicine\/orientations\//, `${name} ne passe pas par une consultation`);
    }
    assert.match(components.StayExams, /'analyses' : 'imagerie'/);
});

test('ordonnance, examens, soins, transfert et sortie se signent (ADR-106)', () => {
    for (const name of ['StayPrescriptions', 'StayExams', 'StayCareOrders', 'StayExit']) {
        assert.match(components[name], /:dismissible="false"/, `${name} sans confirmation signée`);
        assert.match(components[name], /Sous la responsabilité de/, `${name} ne nomme pas le signataire`);
    }
});

test('une section refusée se nomme, elle n’est jamais servie vide', () => {
    assert.match(components.StayNotes, /notes === null/);
    assert.match(components.StayNotes, /hospital_notes\.view/);
    assert.match(components.StayCareOrders, /care_orders\.view/);
});

test('les composants du séjour sont écrits en shadcn-vue (ADR-099)', () => {
    for (const [name, source] of Object.entries({ Show: show, ...components })) {
        assert.doesNotMatch(source, /Components\/UI\/Icon\.vue|class="[^"]*\bnk-|\bni ni-|@headlessui/, `${name} emploie encore DashWind`);
    }
});
