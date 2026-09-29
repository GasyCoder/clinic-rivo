import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
    assistantFormValues, formatTokens, keySourceLabel, modelOptionsFor, pageContextFrom, parseSseChunk, refusalMessage, renderAssistantMarkdown,
} from '../../resources/js/utilities/assistant.js';
import { SETTINGS_SECTION_IDS, settingsSection } from '../../resources/js/utilities/settingsSections.js';

const read = (path) => fs.readFileSync(path, 'utf8');

/**
 * ADR-222 — une réponse de l'assistant ne peut injecter ni script ni lien : tout est
 * échappé avant que le Markdown réduit n'ajoute ses propres balises.
 */
test('assistant markdown escapes everything before adding its own tags', () => {
    const html = renderAssistantMarkdown('<script>alert(1)</script>\n<img src=x onerror=alert(1)>\n[lien](javascript:alert(1))');

    assert.ok(! html.includes('<script'));
    assert.ok(! html.includes('<img'));
    assert.ok(! html.includes('<a '));
    assert.ok(html.includes('&lt;script&gt;'));
});

test('assistant markdown renders steps, bold, code and headings', () => {
    const html = renderAssistantMarkdown('### Délivrer\n1. Ouvrez **Pharmacie**\n2. Cliquez `Délivrer`\n\n- une puce\n\nUn *mot* important.');

    assert.match(html, /<h4>Délivrer<\/h4>/);
    assert.match(html, /<ol><li>Ouvrez <strong>Pharmacie<\/strong><\/li><li>Cliquez <code>Délivrer<\/code><\/li><\/ol>/);
    assert.match(html, /<ul><li>une puce<\/li><\/ul>/);
    assert.match(html, /<em>mot<\/em>/);
});

test('server-sent events are parsed across chunk boundaries', () => {
    const first = parseSseChunk('data: {"type":"delta","text":"Bon"}\n\ndata: {"type":"del');
    assert.deepEqual(first.events, [{ type: 'delta', text: 'Bon' }]);

    const second = parseSseChunk(`${first.rest}ta","text":"jour"}\n\ndata: [DONE]\n\ndata: pas du json\n\n`);
    assert.deepEqual(second.events, [{ type: 'delta', text: 'jour' }]);
    assert.equal(second.rest, '');
});

test('the page context never carries query strings or unsafe names', () => {
    assert.deepEqual(pageContextFrom('/patients/abc?q=RAKOTO', 'Patients/Show', '#passages'), {
        path: '/patients/abc',
        component: 'Patients/Show',
        section: 'passages',
    });
    assert.deepEqual(pageContextFrom('/cash', '<script>', '#a b'), { path: '/cash', component: null, section: null });
});

test('refusals speak French and keep the server message first', () => {
    assert.equal(refusalMessage(503, { message: 'L’assistant est désactivé.' }), 'L’assistant est désactivé.');
    assert.match(refusalMessage(419, null), /session/);
    assert.match(refusalMessage(403, null), /ai_assistant\.use/);
});

test('settings values never contain a key and empty numbers stay empty', () => {
    const values = assistantFormValues({ values: { enabled: 1, provider: 'openai', model: null, max_output_tokens: 800, temperature: null } });

    assert.equal(values.enabled, true);
    assert.equal(values.provider, 'openai');
    assert.equal(values.model, '');
    assert.equal(values.max_output_tokens, '800');
    assert.equal(values.temperature, '');
    assert.ok(! ('api_key' in values));
});

test('model options come from the SDK list plus a hand-typed model', () => {
    const providers = [{ value: 'openai', models: [{ value: 'model-a', label: 'Recommandé par le SDK' }] }];

    assert.deepEqual(modelOptionsFor(providers, 'openai', 'model-z').map((option) => option.value), ['model-a', 'model-z']);
    assert.deepEqual(modelOptionsFor(providers, 'openai', 'model-a').map((option) => option.value), ['model-a']);
    assert.deepEqual(modelOptionsFor(providers, 'anthropic'), []);
});

test('key status and token counts read at a glance', () => {
    assert.equal(keySourceLabel({ present: false }), 'Aucune clé');
    assert.match(keySourceLabel({ present: true, source: 'environment' }), /\.env/);
    assert.match(keySourceLabel({ present: true, source: 'database' }), /chiffrée/);
    assert.equal(formatTokens(12345), '12 345');
    assert.equal(formatTokens(2_400_000), '2,4 M');
});

test('the assistant settings module exists on the server and the screen', () => {
    assert.ok(SETTINGS_SECTION_IDS.includes('assistant'));
    assert.equal(settingsSection('assistant').fields.length, 0, 'aucun champ du formulaire commun');
    assert.match(read('app/Http/Controllers/SuperAdmin/AppSettingsController.php'), /'maintenance', 'assistant'\]/);
});

test('no key ever reaches the browser storage or the shared props', () => {
    const sources = [
        'resources/js/composables/useAssistantChat.js',
        'resources/js/Components/Assistant/AssistantLauncher.vue',
        'resources/js/Components/Settings/AssistantSettings.vue',
    ].map(read).join('\n');

    assert.ok(! /\b(?:localStorage|sessionStorage)\s*[.[]/.test(sources), 'aucun stockage navigateur');
    assert.ok(! /api_key/.test(read('app/Http/Middleware/HandleInertiaRequests.php')));
    // La clé saisie est effacée de l'écran après l'enregistrement.
    assert.match(read('resources/js/Components/Settings/AssistantSettings.vue'), /onSuccess: \(\) => \{\s*form\.api_key = '';/);
});

test('the assistant launcher lives in the persistent layout and hides when unavailable', () => {
    const launcher = read('resources/js/Components/Assistant/AssistantLauncher.vue');

    assert.match(read('resources/js/Layouts/AppLayout.vue'), /<AssistantLauncher \/>/);
    assert.match(launcher, /page\.props\.assistant\?\.available === true/);
    // Avant tout réglage, seul celui qui peut le régler voit le bouton (le serveur envoie `setup`),
    // et le panneau le mène aux réglages au lieu d'une discussion qui échouerait.
    assert.match(launcher, /const shown = computed\(\(\) => available\.value \|\| setup\.value !== null\)/);
    assert.match(launcher, /<template v-if="shown">/);
    assert.match(launcher, /<div v-if="setup"[^>]*data-assistant-setup>[\s\S]*?<Button :as="Link" :href="setup\.url"[\s\S]*?<\/div>\s*<template v-else>/);
    assert.match(launcher, /if \(! value \|\| ! available\.value\) return;/, 'aucune question proposée tant que l’assistant n’est pas prêt');
});
