import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/**
 * ADR-099 — Chirurgie passe à shadcn : plus d'icône DashWind, plus de palette
 * recopiée. Le test garde la frontière, le build ne la voit pas.
 */
const MIGRATED = [
    'resources/js/Pages/Surgery/Index.vue',
];

/** L'écran ne fait pas que changer de primitives : il se lit comme les autres files. */
test('la file du bloc a l’en-tête et les cartes-filtres des autres espaces', () => {
    const index = fs.readFileSync('resources/js/Pages/Surgery/Index.vue', 'utf8');

    assert.match(index, /Components\/Care\/SoinsWorkspaceHeader\.vue/);
    assert.match(index, /Components\/Clinical\/QueueCounters\.vue/);
    // Quatre vues exclusives, comptées par le serveur (ADR-135).
    for (const view of ['to_plan', 'planned', 'in_block', 'done']) {
        assert.ok(index.includes(`'${view}'`), `vue ${view} manquante`);
    }
    assert.match(index, /props\.counts\.to_plan/);
    assert.doesNotMatch(index, /surgicalRequests\.data\.filter/, 'un compte ne se recalcule pas depuis la page');
});

/**
 * ADR-159 — le bloc ne crée pas de demande : elles naissent à la Réception ou
 * en consultation. L'écran ne propose donc aucune création, et dit d'où vient
 * chaque dossier.
 */
test('la file du bloc n’offre aucune création et affiche l’origine', () => {
    const index = fs.readFileSync('resources/js/Pages/Surgery/Index.vue', 'utf8');

    assert.doesNotMatch(index, /surgery\/create/, 'le bloc ne doit plus proposer de nouvelle demande');
    assert.doesNotMatch(index, /Nouvelle demande/);
    assert.match(index, /origin_label/);
    // Une demande antérieure à la trace ne s'invente pas une origine.
    assert.match(index, /Non renseignée/);
    // L'état vide explique les deux chemins réels.
    assert.match(index, /conduite à tenir/);
});

test('l’écran de création du bloc n’existe plus', () => {
    assert.ok(!fs.existsSync('resources/js/Pages/Surgery/Create.vue'));
});

for (const path of MIGRATED) {
    test(`${path} ne porte plus de DashWind`, () => {
        const file = fs.readFileSync(path, 'utf8');

        assert.doesNotMatch(file, /Components\/UI\/Icon\.vue/);
        assert.doesNotMatch(file, /<Icon\b/);
        assert.doesNotMatch(file, /\bnk-|ni ni-/);
        // Les nuances codées en dur cèdent aux tokens sémantiques.
        assert.doesNotMatch(file, /text-slate-400|border-gray-200|bg-primary-100/);
        assert.match(file, /Components\/Shadcn\/Button\.vue/);
        assert.match(file, /lucide-vue-next/);
    });
}

/** Le référentiel du bloc vient de la clinique, jamais d'un nom deviné (ADR-052). */
test('les actes du récapitulatif de la clinique sont au référentiel', () => {
    const data = fs.readFileSync('app/Support/SurgeryReferenceData.php', 'utf8');

    for (const code of [
        'SURG-ABCES', 'SURG-ECTOPIE-TESTICULAIRE', 'SURG-FURONCLES',
        'SURG-HERNIE-INGUINALE', 'SURG-HERNIE-INGUINO-SCROTALE',
        'SURG-INVAGINATION-INTESTINALE', 'SURG-KYSTE-SOUS-CUTANE',
        'SURG-PLAIE-LINEAIRE', 'SURG-TORSION-CORDON',
        'SURG-VOLVULUS-INTESTINAL', 'SURG-CYSTOSTOMIE-DERIVATION',
    ]) {
        assert.ok(data.includes(code), `${code} manque au référentiel Chirurgie`);
    }

    // Les seize éléments d'anesthésie de la clinique, inchangés.
    for (const code of ['ANESTH-ADRENALINE', 'ANESTH-AIGUILLE-PL', 'ANESTH-RACHI', 'ANESTH-OTHER']) {
        assert.ok(data.includes(code), `${code} manque au référentiel Anesthésie`);
    }
});
