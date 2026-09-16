import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Maternity/Show.vue', 'utf8');
const textarea = fs.readFileSync('resources/js/Components/Shadcn/Textarea.vue', 'utf8');

/** ADR-099 : tout écran retouché passe à shadcn-vue. */
test('le dossier Maternité n’utilise plus DashWind', () => {
    for (const dashwind of ['Components/UI/Icon.vue', 'Components/UI/Button.vue', 'Components/UI/Input.vue', 'Components/UI/Card.vue']) {
        assert.ok(! page.includes(dashwind), `${dashwind} ne doit plus être importé`);
    }

    assert.match(page, /from '@\/Components\/Shadcn\/FormField\.vue'/);
    assert.match(page, /from '@\/Components\/Shadcn\/Textarea\.vue'/);
    assert.match(page, /from 'lucide-vue-next'/);

    // Les classes de champ recopiées à la main ont disparu avec elles.
    assert.doesNotMatch(page, /const selectClass|const textareaClass/);
});

/**
 * Le vrai défaut n'était pas le style : `UpdateMaternityRecordRequest`
 * déclare ces blocs `prohibited` sans leur permission, et « prohibited »
 * refuse un tableau non vide. L'écran envoyait pourtant les cinq blocs avec
 * leurs valeurs par défaut — le dossier était donc impossible à enregistrer.
 */
test('un bloc que le compte ne peut pas écrire n’est pas envoyé', () => {
    assert.match(page, /if \(! props\.capabilities\.can_prenatal\) delete payload\.prenatal_data;/);
    assert.match(page, /if \(! props\.capabilities\.can_labor\) delete payload\.labor_data;/);
    assert.match(page, /if \(! props\.capabilities\.can_delivery\) delete payload\.delivery_data;/);
    assert.match(page, /delete payload\.newborn_data;\s*\n\s*delete payload\.baby_care_notes;/);
});

/** Le serveur refuse au-delà de cinq : l'écran le dit au lieu de le laisser échouer. */
test('le nombre de nouveau-nés est borné dans l’écran', () => {
    assert.match(page, /const MAX_NEWBORNS = 5;/);
    assert.match(page, /if \(form\.newborn_data\.newborns\.length >= MAX_NEWBORNS\) return;/);
    assert.match(page, /:disabled="form\.newborn_data\.newborns\.length >= MAX_NEWBORNS"/);
});

/**
 * Le dossier s'affichait éditable et seul « Enregistrer » disparaissait :
 * on pouvait saisir un relevé entier avant de découvrir qu'il n'irait
 * nulle part.
 */
test('un dossier non modifiable est désactivé, pas seulement sans bouton', () => {
    assert.match(page, /const readOnly = computed\(\(\) => ! props\.capabilities\.can_edit\)/);
    assert.match(page, /<fieldset class="min-w-0 space-y-5 p-5" :disabled="readOnly">/);
    assert.match(page, /Dossier en lecture seule/);
});

/** Six sections dont on ne voyait que celle ouverte. */
test('chaque onglet dit s’il est déjà renseigné', () => {
    assert.match(page, /const sectionFilled = \{/);
    assert.match(page, /<CircleCheck v-if="section\.filled"/);
});

/**
 * Décider la césarienne crée une demande Chirurgie sur le passage
 * (ADR-067) : ce n'est pas un champ du dossier, et terminer la prise en
 * charge rend le dossier non modifiable. Les deux se confirment.
 */
test('les actes conséquents passent par une confirmation shadcn', () => {
    assert.match(page, /title="Transmettre la césarienne à Chirurgie \?"/);
    assert.match(page, /title="Terminer la prise en charge Maternité \?"/);
    assert.doesNotMatch(page, /window\.confirm|confirm\(/);
});

/** Terminer perdrait une saisie non enregistrée : on l'empêche. */
test('on ne peut pas clore un dossier sur des modifications non enregistrées', () => {
    assert.match(page, /:disabled="completeForm\.processing \|\| form\.isDirty"/);
    assert.match(page, /terminer maintenant les perdrait/);
});

/** Quatrième recopie des mêmes classes : la zone de texte devient une primitive. */
test('la zone de texte est une primitive partagée', () => {
    assert.match(textarea, /resize-y rounded-lg border border-input bg-card/);
    assert.match(textarea, /focus-visible:ring-ring\/25/);
    assert.match(textarea, /disabled:cursor-not-allowed disabled:opacity-50/);
});

/**
 * Les constantes sont relevées une seule fois par les Soins et lues partout
 * ailleurs par la même projection (ADR-054). Maternité était le seul module
 * clinique à ne pas la consommer.
 */
test('le dossier affiche les constantes relevées aux Soins', () => {
    assert.match(page, /import VitalSignsStrip from '@\/Components\/Clinical\/VitalSignsStrip\.vue'/);
    assert.match(page, /<VitalSignsStrip\s+v-if="careRecord"/);
    assert.match(page, /:care-record="careRecord"/);
    assert.match(page, /:allergies="allergies"/);
});

/** Des tirets se liraient « normal » : l'absence de relevé se dit. */
test('un passage sans fiche Soins le dit au lieu d’afficher du vide', () => {
    assert.match(page, /Aucune constante relevée pour ce passage/);
});

/** Corriger une constante se fait sur la fiche qui la porte (ADR-092/093). */
test('la correction renvoie vers la fiche de soins, sans second formulaire', () => {
    assert.match(page, /Ouvrir la fiche de soins complète/);
    assert.doesNotMatch(page, /blood_pressure_systolic:\s/);
});
