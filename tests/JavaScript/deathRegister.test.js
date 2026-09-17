import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const index = fs.readFileSync('resources/js/Pages/Deaths/Index.vue', 'utf8');
const certificate = fs.readFileSync('resources/js/Pages/Deaths/CertificatePrint.vue', 'utf8');
const orientation = fs.readFileSync('resources/js/Components/Clinical/ClinicalOrientationCard.vue', 'utf8');
const discharge = fs.readFileSync('resources/js/Components/Clinical/ClinicalDischargeForm.vue', 'utf8');

/**
 * Ce que le fichier *fait*, sans ce qu'il *dit*. Ces écrans expliquent en
 * clair ce qu'ils refusent de faire — « n'encaisse rien », « ni déclarant ni
 * officier » — et une recherche brute y trouvait donc les mots qu'elle
 * cherchait à exclure, dans la phrase qui promet le contraire.
 */
const codeOf = (source) => source
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '');

/**
 * ADR-107 — le registre écoute, il ne décide pas. Le décès est prononcé par
 * la sortie médicale (ADR-035) ; cet écran liste et fait signer.
 */
test('le registre ne prononce aucun décès et n’encaisse rien', () => {
    // Aucun chemin d'écriture vers la sortie médicale.
    assert.doesNotMatch(index, /\/discharge/);
    // ADR-012/013 : ce n'est pas un écran de caisse.
    assert.doesNotMatch(codeOf(index), /payments\.|\/cash/);

    // Le seul envoi est l'acte lui-même.
    assert.match(index, /\.post\(`\/deces\/\$\{recording\.value\.uuid\}\/acte`/);
});

/** Signer un document médico-légal n'est pas un clic qu'on fait en passant. */
test('l’acte se signe dans une fenêtre qu’on ne ferme pas par accident', () => {
    const dialog = index.slice(index.indexOf('Acte de constatation de décès') - 200);

    assert.match(dialog, /:dismissible="false"/);
    assert.match(dialog, /\$page\.props\.auth\.user\.name/);
});

/**
 * L'ADR-107 n'invente pas l'état civil : le numéro d'acte, le déclarant et
 * l'officier ne figurent nulle part au CDC, donc nulle part ici.
 */
test('aucune donnée d’état civil n’est inventée', () => {
    for (const source of [index, certificate]) {
        assert.doesNotMatch(codeOf(source), /declarant|déclarant|officier|numero_acte/i);
    }
});

/** Le droit gouverne le bouton, et la route le revérifie côté serveur. */
test('le bouton de signature suit le droit envoyé par le serveur', () => {
    assert.match(index, /v-if="episode\.can_record"/);
});

/**
 * ADR-106 — transmettre engage le service destinataire et débloque la
 * clôture (ADR-084). Un clic ne doit pas suffire.
 */
test('aucune demande n’est transmise sans confirmation', () => {
    for (const kind of ['SURGERY', 'HOSPITALIZATION', 'REFERRAL', 'SERVICE']) {
        assert.match(orientation, new RegExp(`@submit\\.prevent="openTransmitConfirmation\\('${kind}'\\)"`));
    }

    // Un seul appelant pour chaque envoi réel : la confirmation.
    const confirm = orientation.slice(orientation.indexOf('const TRANSMIT_SUBMITS'), orientation.indexOf('const transmitProcessing'));
    for (const submit of ['submitSurgery', 'submitHospitalization', 'submitReferral', 'submitService']) {
        assert.match(confirm, new RegExp(`${submit}\\(\\)`));
    }

    // Elle relit ce qui part, et n'est pas fermable au clic extérieur.
    const dialog = orientation.slice(orientation.indexOf('title="Confirmer la demande"') - 200);
    assert.match(dialog, /:dismissible="false"/);
    assert.match(dialog, /v-for="line in transmitLines"/);
    assert.match(dialog, /\$page\.props\.auth\.user\.name/);
});

/**
 * ADR-099 — les contrôles habillés à la main cèdent la place aux primitives
 * partagées. Les chaînes de classes recopiées avaient déjà divergé.
 */
test('les formulaires cliniques n’habillent plus de contrôle natif', () => {
    for (const source of [orientation, discharge]) {
        assert.doesNotMatch(source, /<select\b/);
        assert.doesNotMatch(source, /<textarea\b/);
        assert.doesNotMatch(source, /textareaClass|selectClass/);
    }

    assert.match(orientation, /import Select from '@\/Components\/Shadcn\/Select\.vue'/);
    assert.match(orientation, /import Textarea from '@\/Components\/Shadcn\/Textarea\.vue'/);
    assert.match(discharge, /import Select from '@\/Components\/Shadcn\/Select\.vue'/);
});

/**
 * Les libellés de ces formulaires étaient écrits à la main, en `text-[11px]
 * font-bold`, c'est-à-dire ni la taille ni la graisse du reste de
 * l'application. `FormField` porte le libellé, l'astérisque, la précision et
 * l'erreur — une seule définition pour les quatre demandes (ADR-099).
 */
test('les demandes de conduite à tenir utilisent la primitive de champ', () => {
    assert.match(orientation, /import FormField from '@\/Components\/Shadcn\/FormField\.vue'/);
    assert.match(discharge, /import FormField from '@\/Components\/Shadcn\/FormField\.vue'/);

    // Plus aucun libellé habillé à la main.
    assert.doesNotMatch(orientation, /mb-1 block text-\[11px\] font-bold/);

    // Et l'erreur remonte au champ plutôt que de flotter sous lui.
    assert.match(orientation, /<FormField label="Motif d’hospitalisation" required :error="hospitalizationForm\.errors\.reason">/);
});

/**
 * Un compte rendu d'imagerie fait une dizaine de lignes. Sur quatre rangées,
 * il fallait faire défiler un champ pour relire ce qui part au service.
 */
test('le résumé clinique a la place de son contenu', () => {
    for (const id of ['orientation_hosp_summary', 'orientation_referral_summary']) {
        const field = orientation.slice(orientation.indexOf(id), orientation.indexOf(id) + 220);
        assert.match(field, /:rows="10"/);
    }
});

/**
 * ADR-107 — une sortie pour décès ne propose ni « Guéri », ni traitement de
 * sortie, ni conseils, ni rendez-vous de contrôle. Ce ne sont pas des cases
 * à laisser vides : ce sont des instructions sans destinataire.
 */
test('une sortie pour décès ne propose rien qui s’adresse à un vivant', () => {
    assert.match(discharge, /const isDeceased = computed\(\(\) => props\.form\.type === 'DECEASED'\)/);

    // Les quatre blocs disparaissent, ils ne sont pas seulement vidés.
    assert.match(discharge, /<div v-if="!isDeceased">\n\s*<ClinicalSegmentedChoice/);
    assert.match(discharge, /<div v-if="!isDeceased" class="grid gap-5 lg:grid-cols-2">/);
    assert.match(discharge, /<div v-if="!isDeceased">\n\s*<p :class="labelClass">Contrôle<\/p>/);

    // L'état n'est pas absent : il est affiché comme un fait acquis.
    assert.match(discharge, /Décédé — porté au dossier par le type de sortie\./);

    // Et la garde de complétude cesse de réclamer un état qu'on ne demande plus.
    assert.match(discharge, /\(isDeceased\.value \|\| Boolean\(props\.form\.patient_condition\)\)/);
});

/** Prononcer une sortie change le statut médical du passage (ADR-035). */
test('la sortie médicale passe par une confirmation', () => {
    assert.match(discharge, /@submit\.prevent="canSubmit && openConfirmation\(\)"/);

    // Un seul appelant pour l'envoi réel.
    const confirm = discharge.slice(discharge.indexOf('const confirmDischarge'), discharge.indexOf('const confirmationLines'));
    assert.match(confirm, /emit\('submit'\)/);

    const dialog = discharge.slice(discharge.indexOf(':open="confirming"'));
    assert.match(dialog, /:dismissible="false"/);
    assert.match(dialog, /v-for="line in confirmationLines"/);
    assert.match(dialog, /\$page\.props\.auth\.user\.name/);
    // Un décès se confirme dans ses propres termes.
    assert.match(dialog, /Je confirme le décès/);
});
