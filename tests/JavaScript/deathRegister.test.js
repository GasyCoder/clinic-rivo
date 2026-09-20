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
    assert.match(orientation, /<FormField label="Intervention envisagée" required :error="surgeryForm\.errors\.catalog_item_uuid">/);
});

/**
 * ADR-114 — une destination qui a son module ne se remplit plus en
 * consultation : le médecin coche et transmet, le reste part repris du
 * dossier et se complète dans l'espace destinataire.
 */
test('les demandes vers un module se transmettent sans formulaire', () => {
    // Transfert : ni établissement ni résumé à saisir ici.
    assert.doesNotMatch(orientation, /orientation_referral_facility|orientation_referral_summary/);
    assert.match(orientation, /espace <strong class="font-semibold text-foreground">Transferts<\/strong>/);

    // Maternité / Pédiatrie : un clic, le motif repris du dossier.
    assert.doesNotMatch(orientation, /orientation_service_motif|orientation_service_observations/);

    // Chirurgie : l'intervention reste à choisir, le diagnostic est repris.
    assert.doesNotMatch(orientation, /orientation_surgery_diagnostic|orientation_surgery_notes/);
    assert.match(orientation, /id="orientation_surgery_item"/);
});

/**
 * Le résumé transmis se complète dans le module Transferts, en texte riche,
 * et garde la place de son contenu. La lettre imprime le HTML assaini, jamais
 * les balises en clair.
 */
test('la demande de transfert se rédige en texte riche', () => {
    const transfer = fs.readFileSync(new URL('../../resources/js/Pages/Transfers/Show.vue', import.meta.url), 'utf8');
    const letter = fs.readFileSync(new URL('../../resources/js/Pages/Medicine/MedicalReferralPrint.vue', import.meta.url), 'utf8');

    assert.match(transfer, /<ClinicalRichTextEditor/);
    assert.match(transfer, /\{ key: 'clinical_summary', label: 'Résumé clinique et examens', max: 5000, height: 'min-h-56' \}/);
    assert.doesNotMatch(transfer, /<Textarea v-model="requestForm\./);

    assert.match(letter, /<ClinicalRichTextDisplay class="rx-block-value" :html="block\.html" \/>/);
    assert.doesNotMatch(letter, /\{\{ block\.value \}\}/);
});

/**
 * ADR-107 — une sortie pour décès ne propose ni « Guéri », ni traitement de
 * sortie, ni conseils, ni rendez-vous de contrôle. Ce ne sont pas des cases
 * à laisser vides : ce sont des instructions sans destinataire.
 */
test('une sortie pour décès ne propose rien qui s’adresse à un vivant', () => {
    assert.match(discharge, /const isDeceased = computed\(\(\) => props\.form\.type === 'DECEASED'\)/);

    // Les blocs disparaissent, ils ne sont pas seulement vidés. La règle porte
    // sur la garde `!isDeceased`, jamais sur la mise en page qui l'entoure
    // (ADR-148 a regroupé la sortie en sections encadrées).
    assert.match(discharge, /<div v-if="!isDeceased">\n\s*<ClinicalSegmentedChoice/);
    // Traitement, conseils et contrôle : une seule section, une seule garde.
    assert.match(discharge, /<section v-if="!isDeceased" :class="sectionClass">/);
    const consignes = discharge.slice(discharge.indexOf('<section v-if="!isDeceased"'));
    for (const label of ['Traitement de sortie', 'Conseils et surveillance', 'Contrôle']) {
        assert.ok(consignes.includes(label), `${label} doit vivre sous la garde !isDeceased`);
    }

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

/**
 * ADR-113 / ADR-107 (amendements) — une destination qui a son module se
 * transmet en un clic : le détail se complète dans l'espace Hospitalisation
 * ou dans le registre des décès, jamais deux fois.
 */
test('hospitalisation et décès se transmettent sans formulaire', () => {
    const hospital = orientation.slice(orientation.indexOf("active.type === 'HOSPITALIZATION'"), orientation.indexOf("active.type === 'REFERRAL'"));
    assert.doesNotMatch(hospital, /hospitalizationForm\.reason/);
    assert.match(hospital, /Transmettre la demande/);

    assert.doesNotMatch(discharge, /id="death_occurred_at"/);
    assert.doesNotMatch(discharge, /id="death_causes"/);
    assert.match(discharge, /registre des décès/);
});

/**
 * ADR-114 — une demande transmise vers un module ne se retransmet pas : le
 * second clic créait un doublon. L'écran conduit au module à la place.
 */
test('une demande transmise ne propose plus « Transmettre la demande »', () => {
    assert.match(orientation, /const MODULE_TYPES = \['SURGERY', 'HOSPITALIZATION', 'REFERRAL', 'MATERNITY', 'PEDIATRICS'\]/);
    assert.match(orientation, /<div v-if="submittedToModule"/);
    // Tous les formulaires qui transmettent suivent ce bloc dans la même chaîne.
    assert.match(orientation, /<form v-else-if="active\.type === 'SURGERY'"/);
    assert.match(orientation, /:href="active\.request\.module_url"/);
    assert.doesNotMatch(orientation, /corriger en la retransmettant/);
});
