import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
    assistantFormValues, defaultModelLabel, formatTokens, keySourceLabel, modelOptionLabel, modelOptionsFor, pageContextFrom, parseSseChunk,
    REVEAL_FRAMES, REVEAL_MIN, refusalMessage, renderAssistantMarkdown, takeReveal,
} from '../../resources/js/utilities/assistant.js';
import {
    BUBBLE_SIZE, EDGE_MARGIN, PREFERENCES_KEY, TOP_RESERVED, WINDOW_GAP, bubblePosition, clampBubble, clampWindow, coversBubble,
    effectiveMode, nudgeBubble, readPreferences, snapBubble, toggledSize, windowRect, windowSize, writePreferences,
} from '../../resources/js/utilities/assistantWidget.js';
import { ASSISTANT_MODULE_ICONS } from '../../resources/js/utilities/assistantModules.js';
import { buildClinicMenu, visibleMenu } from '../../resources/js/utilities/clinicMenu.js';
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
    assert.equal(modelOptionsFor(providers, 'openai')[0].label, 'model-a — Recommandé par le SDK');
    assert.equal(defaultModelLabel({ default_model: 'model-a', models: providers[0].models }), 'Recommandé par le SDK (model-a)');
    assert.equal(defaultModelLabel({ default_model: null, models: [] }), 'Par défaut');
});

/**
 * ADR-222 (amendement ter) — GasyCoder AI propose ses propres types de modèle, chacun
 * avec le moteur qu'il appelle : le Super Administrateur voit les deux.
 */
test('GasyCoder AI models are named types that show their engine', () => {
    const gasycoder = {
        value: 'gasycoder',
        default_model: 'gasycoder-ai',
        models: [
            { value: 'gasycoder-ai', label: 'GasyCoder AI', hint: 'Recommandé', engine: 'moteur-a' },
            { value: 'gasycoder-ai-mini', label: 'GasyCoder AI Mini', hint: 'Le plus rapide et le plus économique', engine: 'moteur-b' },
            { value: 'gasycoder-ai-pro', label: 'GasyCoder AI Pro', hint: 'Le plus capable', engine: 'gasycoder-ai-pro' },
        ],
    };

    assert.deepEqual(modelOptionsFor([gasycoder], 'gasycoder').map((option) => option.label), [
        'GasyCoder AI — recommandé · moteur-a',
        'GasyCoder AI Mini — le plus rapide et le plus économique · moteur-b',
        // Votre propre API sert le type sous son nom : pas de moteur à répéter.
        'GasyCoder AI Pro — le plus capable',
    ]);
    assert.equal(modelOptionLabel({ value: 'x', label: 'Le plus capable' }), 'x — Le plus capable');
    assert.equal(defaultModelLabel(gasycoder), 'Par défaut — GasyCoder AI (moteur-a)');
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
        'resources/js/Components/Assistant/AssistantWidget.vue',
        'resources/js/Components/Assistant/AssistantConversationView.vue',
        'resources/js/Components/Settings/AssistantSettings.vue',
    ].map(read).join('\n');

    assert.ok(! /\b(?:localStorage|sessionStorage)\s*[.[]/.test(sources), 'aucun stockage navigateur');
    assert.ok(! /api_key/.test(read('app/Http/Middleware/HandleInertiaRequests.php')));
    // La clé saisie est effacée de l'écran après l'enregistrement.
    assert.match(read('resources/js/Components/Settings/AssistantSettings.vue'), /onSuccess: \(\) => \{\s*form\.api_key = '';/);
});

/**
 * ADR-222 (amendement bis) — plus d'entrée dans le menu ni de page : l'assistant tient
 * dans une bulle posée sur chaque page, déplaçable, qui ouvre une fenêtre en trois tailles.
 */
test('the assistant is a draggable bubble on every page, never a sidebar entry or a page', () => {
    assert.ok(! fs.existsSync('resources/js/Pages/Assistant/Index.vue'), 'plus de page /assistant');
    assert.doesNotMatch(read('routes/web.php'), /AssistantController::class, 'index'/);
    assert.doesNotMatch(read('resources/js/Components/Layout/Menu.vue'), /Assistant IA|'\/assistant'/);

    const can = () => true;
    assert.ok(! visibleMenu(buildClinicMenu({ roleCode: 'RECEPTION', can }), can).some((item) => item.link === '/assistant'));

    const layout = read('resources/js/Layouts/AppLayout.vue');
    assert.match(layout, /<AssistantWidget \/>/);

    const widget = read('resources/js/Components/Assistant/AssistantWidget.vue');
    // Proposée seulement si l'assistant est prêt, ou au Super Administrateur qui peut le régler.
    assert.match(widget, /const shown = computed\(\(\) => available\.value \|\| setup\.value !== null\);/);
    // Rendue dans le navigateur seulement : sa place vient du poste.
    assert.match(widget, /<Teleport v-if="mounted && shown" to="body">/);
    // Un petit robot, déplaçable (pointeur et clavier), jamais à l'impression.
    assert.match(widget, /<AssistantRobot :talking="chat\.streaming\.value" \/>/);
    assert.match(widget, /@pointerdown="bubbleDrag\.start"/);
    assert.match(widget, /@keydown="onBubbleKeydown"/);
    assert.match(widget, /class="print:hidden" data-assistant-widget/);
    // Un glisser n'ouvre pas la fenêtre.
    assert.match(widget, /if \(bubbleDrag\.wasDragged\(\)\) return;/);
    // Petite / grande fenêtre, plein écran, réduire en bulle.
    assert.match(widget, /data-assistant-size/);
    assert.match(widget, /data-assistant-full/);
    assert.match(widget, /data-assistant-minimize/);
    // Au-dessus de l'en-tête et du menu (1021, 1031), sous les toasts et les fenêtres de confirmation (1400, 1500).
    assert.match(widget, /fixed z-\[1200\]/);
    // Pas prêt : la fenêtre le dit ; supprimer une conversation se confirme.
    assert.match(widget, /<AssistantSetupNotice :setup="setup" \/>/);
    assert.match(read('resources/js/Components/Assistant/AssistantSetupNotice.vue'), /<Button :as="Link" :href="setup\.url"/);
    assert.match(widget, /<ConfirmModal[\s\S]*?title="Supprimer cette conversation \?"/);
});

test('the bubble sticks to the nearest edge, stays on screen and moves with the keyboard', () => {
    const viewport = { width: 1280, height: 800 };
    const bottom = viewport.height - BUBBLE_SIZE - EDGE_MARGIN;

    // À sa place par défaut : en bas à droite.
    assert.deepEqual(bubblePosition({ side: 'right', ratio: 1 }, viewport), { x: 1280 - BUBBLE_SIZE - EDGE_MARGIN, y: bottom });
    // Jamais sous la barre du haut ni hors de l'écran, même tirée trop loin.
    assert.deepEqual(clampBubble({ x: -500, y: -500 }, viewport), { x: EDGE_MARGIN, y: TOP_RESERVED });
    assert.deepEqual(clampBubble({ x: 9999, y: 9999 }, viewport), { x: 1280 - BUBBLE_SIZE - EDGE_MARGIN, y: bottom });
    // Lâchée : le bord le plus proche, à la hauteur où on l'a posée.
    assert.equal(snapBubble({ x: 300, y: 400 }, viewport).side, 'left');
    assert.equal(snapBubble({ x: 900, y: 400 }, viewport).side, 'right');
    const snapped = snapBubble({ x: 300, y: 400 }, viewport);
    assert.equal(bubblePosition(snapped, viewport).y, 400);
    // Au clavier : ↑ ↓ la déplacent, ← → changent de bord, Début / Fin.
    assert.equal(nudgeBubble({ side: 'right', ratio: 1 }, 'ArrowLeft', viewport).side, 'left');
    assert.ok(nudgeBubble({ side: 'right', ratio: 1 }, 'ArrowUp', viewport).ratio < 1);
    assert.equal(nudgeBubble({ side: 'right', ratio: 0.5 }, 'Home', viewport).ratio, 0);
    assert.equal(nudgeBubble({ side: 'right', ratio: 0.5 }, 'Tab', viewport), null, 'Tab garde son rôle');
});

test('the window opens beside the bubble in three sizes, and fills a phone', () => {
    const viewport = { width: 1280, height: 800 };
    const anchor = { side: 'right', ratio: 1 };
    const bubble = bubblePosition(anchor, viewport);

    const compact = windowRect({ mode: 'compact', anchor, viewport });
    assert.equal(compact.beside, true);
    assert.equal(compact.x + compact.width, bubble.x - WINDOW_GAP, 'à gauche d’une bulle posée à droite');
    assert.equal(compact.y + compact.height, bubble.y + BUBBLE_SIZE, 'alignée sur la bulle');
    assert.equal(coversBubble(compact, anchor, viewport), false, 'la bulle reste visible à côté');

    const large = windowRect({ mode: 'large', anchor, viewport });
    assert.ok(large.width > compact.width && large.height > compact.height);
    assert.ok(large.y >= TOP_RESERVED, 'jamais sous la barre du haut');

    const full = windowRect({ mode: 'full', anchor, viewport });
    assert.deepEqual([full.x, full.y, full.width, full.height], [0, 0, 1280, 800]);
    assert.equal(coversBubble(full, anchor, viewport), true);

    // À gauche : la fenêtre s'ouvre vers le centre.
    const left = windowRect({ mode: 'compact', anchor: { side: 'left', ratio: 0.5 }, viewport });
    assert.equal(left.x, EDGE_MARGIN + BUBBLE_SIZE + WINDOW_GAP);

    // Déplacée par son en-tête : elle reste où on l'a posée, entière dans l'écran.
    const moved = windowRect({ mode: 'compact', anchor, moved: { x: 5000, y: -40 }, viewport });
    assert.equal(moved.x + moved.width, 1280 - EDGE_MARGIN);
    assert.equal(moved.y, TOP_RESERVED);
    assert.deepEqual(clampWindow({ x: 0, y: 0 }, windowSize('compact', viewport), viewport), { x: EDGE_MARGIN, y: TOP_RESERVED });

    // Sur un téléphone, toutes les tailles prennent l'écran entier.
    const phone = { width: 390, height: 844 };
    assert.equal(effectiveMode('compact', phone), 'full');
    assert.deepEqual(windowSize('large', phone), { width: 390, height: 844 });
    assert.equal(toggledSize('compact'), 'large');
    assert.equal(toggledSize('large'), 'compact');
});

test('the device keeps only the bubble place and the window size, never a question', () => {
    const store = new Map();
    const storage = { getItem: (key) => store.get(key) ?? null, setItem: (key, value) => store.set(key, value) };

    assert.deepEqual(readPreferences(storage), { anchor: { side: 'right', ratio: 1 }, mode: 'compact', moved: null });

    writePreferences(storage, { anchor: { side: 'left', ratio: 0.25 }, mode: 'large', moved: { x: 120.4, y: 90.6 } });
    assert.deepEqual(JSON.parse(store.get(PREFERENCES_KEY)), { anchor: { side: 'left', ratio: 0.25 }, mode: 'large', moved: { x: 120, y: 91 } });
    assert.deepEqual(readPreferences(storage), { anchor: { side: 'left', ratio: 0.25 }, mode: 'large', moved: { x: 120, y: 91 } });

    // Un stockage refusé ou abîmé ne casse rien : la bulle revient à sa place.
    const refusing = { getItem: () => { throw new Error('SecurityError'); }, setItem: () => { throw new Error('QuotaExceeded'); } };
    assert.equal(readPreferences(refusing).mode, 'compact');
    assert.doesNotThrow(() => writePreferences(refusing, { anchor: { side: 'right', ratio: 1 }, mode: 'full', moved: null }));
    store.set(PREFERENCES_KEY, '{pas du json');
    assert.equal(readPreferences(storage).anchor.side, 'right');
    store.set(PREFERENCES_KEY, JSON.stringify({ anchor: { side: 'haut', ratio: 7 }, mode: 'géant' }));
    assert.deepEqual(readPreferences(storage), { anchor: { side: 'right', ratio: 1 }, mode: 'compact', moved: null });

    // Rien d'autre n'est écrit : ni la question en cours, ni la conversation.
    assert.doesNotMatch(read('resources/js/utilities/assistantWidget.js'), /draft|messages|conversation_id/);
    assert.doesNotMatch(read('resources/js/composables/useAssistantWidget.js'), /localStorage\.setItem|sessionStorage/);
});

test('suggestions come grouped from the server, follow-ups sit under the last answer, a failure can be retried', () => {
    const pageSource = read('resources/js/Components/Assistant/AssistantConversationView.vue');
    const message = read('resources/js/Components/Assistant/AssistantMessage.vue');
    const chat = read('resources/js/composables/useAssistantChat.js');

    assert.match(pageSource, /<AssistantSuggestionGrid[\s\S]*?:groups="chat\.groups\.value"[\s\S]*?@ask="emit\('ask', \$event\)"/);
    assert.match(chat, /groups\.value = json\?\.groups \?\? \[\]/);
    assert.match(chat, /message\.followUps = Array\.isArray\(event\.follow_ups\)/);
    assert.match(message, /<AssistantFollowUps[\s\S]*?v-if="showFollowUps && ! isUser && message\.status === 'done'/);
    assert.match(message, /<Button v-if="canRetry"[^>]*@click="emit\('retry'\)"/);
    assert.match(message, /L’assistant écrit…/);
    assert.match(pageSource, /:can-retry="index === lastIndex"/);
    assert.match(chat, /const retry = \(\) => \{/);

    // Chaque catégorie a l'icône de son module, la même que dans le menu : tous les
    // modules que le serveur connaît, Laboratoire compris (ADR-213 à 220).
    const serverModules = [...read('app/Services/Assistant/AssistantKnowledge.php').matchAll(/^ {8}'([a-z]+)' => \[\n(?: {12}\/\/[^\n]*\n)* {12}'title' =>/gm)].map((match) => match[1]);
    assert.ok(serverModules.includes('laboratory') && serverModules.includes('paraclinical'), serverModules.join(', '));
    for (const key of serverModules) {
        assert.ok(ASSISTANT_MODULE_ICONS[key], key);
    }
});

test('the conversation state is shared across pages, bound to the account, and never shared at server rendering', () => {
    const chat = read('resources/js/composables/useAssistantChat.js');

    assert.match(chat, /if \(typeof window === 'undefined'\) return createAssistantChat\(\);/);
    assert.match(chat, /if \(shared === null \|\| owner !== accountId\) \{/, 'un autre compte repart d’un état vide');
    assert.match(read('resources/js/Components/Assistant/AssistantWidget.vue'), /useAssistantChat\(user\.value\?\.id \?\? null\)/);
    // L'état de la bulle (ouverte, taille, question commencée) suit les mêmes règles.
    const widget = read('resources/js/composables/useAssistantWidget.js');
    assert.match(widget, /if \(typeof window === 'undefined'\) return createWidget\(null\);/);
    assert.match(widget, /if \(shared === null \|\| owner !== accountId\) \{/);
});

test('assistant markdown renders tables, still escaping every cell', () => {
    const html = renderAssistantMarkdown('| Geste | Droit |\n|---|---|\n| **Encaisser** | payments.create |\n| <b>x</b> | y |');

    assert.match(html, /<div class="assistant-table"><table><thead><tr><th>Geste<\/th><th>Droit<\/th><\/tr><\/thead>/);
    assert.match(html, /<td><strong>Encaisser<\/strong><\/td>/);
    assert.ok(html.includes('&lt;b&gt;x&lt;/b&gt;'));
    assert.ok(! html.includes('<b>'));
    // Une ligne avec des barres sans ligne de tirets reste un paragraphe.
    assert.match(renderAssistantMarkdown('a | b'), /^<p>a \| b<\/p>$/);
});

/**
 * ADR-222 (amendement bis) — la réponse coule à l'écran : chaque morceau reçu se
 * dévoile en quelques images, sans jamais couper un caractère en deux.
 */
test('streamed text is revealed in small steps without splitting a character', () => {
    assert.deepEqual(takeReveal(''), ['', '']);
    assert.deepEqual(takeReveal('a'), ['a', '']);

    const long = 'x'.repeat(120);
    const [first, rest] = takeReveal(long);
    assert.equal(first.length, Math.ceil(120 / REVEAL_FRAMES));
    assert.equal(first + rest, long);

    // Un texte court part en au moins REVEAL_MIN caractères : il ne traîne jamais.
    assert.equal(takeReveal('abcd')[0].length, REVEAL_MIN);

    // Un emoji (paire de substitution) n'est jamais coupé en deux.
    const [head, tail] = takeReveal('a😀b');
    assert.equal(head, 'a😀');
    assert.equal(tail, 'b');

    // Tout finit par s'afficher, dans l'ordre.
    let pending = 'Ouvrez **Pharmacie › Médicaments & stock** 😀.';
    let shown = '';
    for (let frame = 0; pending !== '' && frame < 100; frame += 1) {
        const [piece, left] = takeReveal(pending);
        shown += piece;
        pending = left;
    }
    assert.equal(shown, 'Ouvrez **Pharmacie › Médicaments & stock** 😀.');

    const chat = read('resources/js/composables/useAssistantChat.js');
    // « Terminée » (Copier, questions de suivi) seulement une fois tout le texte à l'écran.
    assert.match(chat, /if \(pending === '' && frame === null\) finish\(\);/);
    assert.match(chat, /case 'error':[\s\S]*?flushReveal\(\);/);

    const message = read('resources/js/Components/Assistant/AssistantMessage.vue');
    assert.match(message, /'rivo-assistant-streaming'/);
    assert.match(message, /@keyframes rivo-assistant-caret/);

    // Le serveur ouvre le flux par un commentaire de 2 Ko et coupe la compression.
    const controller = read('app/Http/Controllers/Assistant/AssistantController.php');
    assert.match(controller, /str_repeat\(' ', 2048\)/);
    assert.match(controller, /zlib\.output_compression/);
});

/**
 * ADR-222 (amendement bis) — « GasyCoder AI » : le nom de l'assistant dans sa bulle, et
 * un fournisseur comme les autres (modèle, clé), présélectionné quand rien n'est réglé.
 */
test('the assistant is named GasyCoder AI, a provider like the others and the default one', () => {
    assert.match(read('config/rivo.php'), /'brand' => env\('RIVO_AI_BRAND', 'GasyCoder AI'\)/);
    assert.match(read('config/rivo.php'), /'provider' => env\('RIVO_AI_PROVIDER', 'gasycoder'\)/);
    assert.match(read('app/Http/Middleware/HandleInertiaRequests.php'), /'name' => AssistantConfiguration::brand\(\)/);

    const widget = read('resources/js/Components/Assistant/AssistantWidget.vue');
    assert.match(widget, /const assistantName = computed\(\(\) => page\.props\.assistant\?\.name \|\| 'GasyCoder AI'\);/);
    assert.doesNotMatch(widget, />\s*Assistant IA\s*</, 'le titre est le nom de l’assistant');

    // En tête de la liste, de type ChatGPT : le pilote compatible OpenAI du SDK, à l'adresse
    // de ChatGPT sauf si GASYCODER_AI_URL nomme votre propre API.
    const provider = read('app/Enums/AssistantProvider.php');
    assert.match(provider, /\{\n {4}case GasyCoder = 'gasycoder';\n {4}case OpenAi = 'openai';/);
    assert.match(provider, /self::GasyCoder => 'openai-compatible'/);
    assert.doesNotMatch(provider, /needsUrl/, 'aucune adresse n’est plus exigée');
    assert.match(read('config/ai.php'), /'gasycoder' => \[\s*'driver' => 'openai-compatible',\s*'url' => env\('GASYCODER_AI_URL'\)/);
    assert.match(read('app/Services/Assistant/GasyCoderModels.php'), /CHATGPT_URL = 'https:\/\/api\.openai\.com\/v1'/);

    // Rien de réglé : GasyCoder AI (le fournisseur par défaut du serveur) est présélectionné.
    assert.equal(assistantFormValues({ values: { provider: null }, fallbacks: { provider: 'gasycoder' } }).provider, 'gasycoder');
    assert.equal(assistantFormValues({ values: { provider: 'openai' }, fallbacks: { provider: 'gasycoder' } }).provider, 'openai');

    // Le modèle et la clé se choisissent comme pour les autres : rien n'est verrouillé.
    const settings = read('resources/js/Components/Settings/AssistantSettings.vue');
    assert.doesNotMatch(settings, /managed|rien à saisir|Adresse de l’API manquante/);
    assert.match(settings, /data-assistant-api-url/);
    assert.match(settings, /Type ChatGPT — API de ChatGPT/);
    assert.match(settings, /v-if="! customModel && ! typedModelOnly"/);

    // La page des paramètres ne masque plus la prop partagée `assistant` (sinon, pas de bulle).
    assert.match(read('app/Http/Controllers/SuperAdmin/AppSettingsController.php'), /'assistantSettings' =>/);
    assert.match(read('resources/js/Pages/SuperAdmin/Settings/Index.vue'), /:assistant="assistantSettings"/);
});
