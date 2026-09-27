import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Reception/Settlements/Index.vue', 'utf8');

/**
 * ADR-090 (amendement du 2026-09-20) — la dette validée et l'évasion engagent la
 * clinique sur un montant : elles restent affichées mais verrouillées, et ne se
 * déverrouillent que par un droit que le Super Administrateur accorde.
 */
test('les deux sorties à risque restent visibles mais verrouillées sans leur droit', () => {
    assert.match(page, /type\.value === 'DEBT_VALIDATED' && !props\.capabilities\.can_authorize_debt/);
    assert.match(page, /type\.value === 'ESCAPED' && !props\.capabilities\.can_record_escape/);
    assert.match(page, /debts\.record_escape/);
    assert.match(page, /:disabled="!!unavailableReason\(type\)"/);
});

/** Le motif n'a rien à faire avant qu'un type soit choisi. */
test('le motif et le commentaire n’apparaissent qu’après le choix d’un type de sortie', () => {
    assert.match(page, /<div v-if="form\.exit_type">\s*<FormLabel for="exit_reason">/);
    assert.match(page, /<div v-if="form\.exit_type && form\.exit_type !== 'PAID_CASH'">/);
});

/**
 * Sur un compte soldé, une dette ou une évasion n'ont rien à laisser : les
 * proposer verrouillées ne servait qu'à faire lire trois lignes pour une seule
 * décision possible.
 */
test('un compte soldé ne propose que « payé comptant »', () => {
    assert.match(page, /const visibleExitTypes = computed\(/);
    assert.match(page, /isSettled\.value && !hasUnbilled\.value && type\.value !== 'PAID_CASH'/);
    assert.match(page, /v-for="type in visibleExitTypes"/);
    assert.doesNotMatch(page, /v-for="type in exitTypes"/);
});

/**
 * Sélection multiple : l'écran coche des UUID, le serveur rejuge chaque passage.
 * Les boutons annoncent seulement combien de passages cochés sont concernés.
 */
test('la sélection multiple ne décide rien côté écran', () => {
    // Seule la sortie « payé comptant » se prononce en lot : les comptes
    // réellement soldés, jamais une dette ni une évasion.
    assert.match(page, /const exitRows = computed\(/);
    assert.match(page, /reallySettled\(episode\.account\)/);
    assert.match(page, /'\/reception\/sorties\/sortie-groupee'/);
    assert.match(page, /'\/reception\/sorties\/facturation-groupee'/);
    // Le corps envoyé ne porte que des UUID : ni type de sortie, ni montant.
    assert.match(page, /\{ episode_uuids: bulkTargets\.value\.map\(\(episode\) => episode\.uuid\) \}/);
    assert.doesNotMatch(page, /exit_type: 'ESCAPED'[\s\S]{0,80}episode_uuids/);
});

test('une sélection ne survit ni au changement de liste ni aux passages disparus', () => {
    assert.match(page, /watch\(\(\) => \[props\.tab, props\.episodes\?\.meta\?\.current_page, props\.filters\?\.q\], \(\) => \{ selected\.value = \[\]; \}\);/);
    assert.match(page, /selected\.value = selected\.value\.filter\(\(uuid\) => present\.has\(uuid\)\);/);
});

test('les actions groupées sont confirmées, non fermables au clic extérieur, et rapportées', () => {
    assert.match(page, /:dismissible="false"/);
    assert.match(page, /bulkReport = computed\(\(\) => page\.props\.flash\?\.bulk_report/);
    assert.match(page, /role="toolbar" aria-label="Actions sur la sélection"/);
    assert.match(page, /aria-label="Sélectionner tous les passages de la page"/);
});

test('les fiches se regroupent sur un seul document, une par page', () => {
    const slips = fs.readFileSync('resources/js/Pages/Reception/Settlements/ExitSlipsPrint.vue', 'utf8');

    assert.match(slips, /:show-actions="index === 0"/);
    assert.match(slips, /break-before: page;/);
    // Une seule mise en page de fiche, partagée avec l'impression d'une fiche.
    for (const file of ['resources/js/Pages/Reception/Settlements/ExitSlipPrint.vue', 'resources/js/Pages/Reception/Settlements/ExitSlipsPrint.vue']) {
        assert.match(fs.readFileSync(file, 'utf8'), /import ExitSlipBody from '@\/Components\/Clinical\/ExitSlipBody\.vue'/);
    }
});
