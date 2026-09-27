import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const index = fs.readFileSync('resources/js/Pages/Deaths/Index.vue', 'utf8');
const certificate = fs.readFileSync('resources/js/Pages/Deaths/CertificatePrint.vue', 'utf8');
const orientation = fs.readFileSync('resources/js/Components/Clinical/ConsultationDecisionPanel.vue', 'utf8');
const medicine = fs.readFileSync('resources/js/Pages/Medicine/Show.vue', 'utf8');
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
 * ADR-106, ADR-203 — la conduite à tenir part avec
 * « Clôturer », et seulement par elle : un seul geste signé, jamais un clic.
 */
test('la conduite à tenir ne part qu’avec la clôture confirmée', () => {
    // Le panneau ne transmet rien lui-même : son seul envoi est « Changer de
    // conduite », qui annule la demande en cours après confirmation.
    assert.doesNotMatch(codeOf(orientation), /Transmettre la demande/);
    assert.doesNotMatch(orientation, /surgical-referrals|hospitalization-requests`|medical-referrals`|\/referrals`|\/discharge`/);
    assert.match(orientation, /\/orientation`/);
    const change = orientation.slice(orientation.indexOf('title="Changer de conduite ?"') - 200);
    assert.match(change, /:dismissible="false"/);

    // La clôture ouvre d'abord la confirmation ; la fenêtre seule envoie.
    const open = medicine.slice(medicine.indexOf('const completeConsultation = '), medicine.indexOf('const submitCompleteConsultation'));
    assert.match(open, /closeConfirmOpen\.value = true/);
    assert.doesNotMatch(open, /\.post\(/);

    const dialog = medicine.slice(medicine.indexOf(':title="closureActionLabels.title"') - 200, medicine.indexOf('closureActionLabels.confirm }}'));
    assert.match(dialog, /:dismissible="false"/);
    assert.match(dialog, /v-for="line in closureSummary"/);
    assert.match(dialog, /\$page\.props\.auth\.user\.name/);
    assert.match(dialog, /@click="submitCompleteConsultation"/);
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
    assert.match(orientation, /<FormField v-if="form\.type === 'SURGERY'" label="Intervention envisagée" required :error="error\('catalog_item_uuid'\)">/);
});

/**
 * ADR-114 — une destination qui a son module ne se remplit plus en
 * consultation : le médecin choisit, le reste part repris du dossier et se
 * complète dans l'espace destinataire.
 */
test('les demandes vers un module se transmettent sans formulaire', () => {
    // Ni motif, ni résumé, ni diagnostic à ressaisir : repris du dossier.
    assert.doesNotMatch(orientation, /clinical_summary|admission_diagnosis|form\.reason/);
    assert.match(orientation, /partent repris du dossier : rien à ressaisir/);

    // Chirurgie : l'intervention reste à choisir.
    assert.match(orientation, /v-model="form\.catalog_item_uuid"/);
    // Transfert : un site de la clinique, un autre établissement, ou plus tard.
    assert.match(orientation, /À préciser plus tard/);
    assert.match(orientation, /Autre établissement…/);
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
 * ADR-113 / ADR-107 (amendements) — l'hospitalisation part repris du dossier,
 * le détail d'un décès s'établit au registre, jamais deux fois.
 */
test('hospitalisation et décès se transmettent sans formulaire', () => {
    const hospital = orientation.slice(orientation.indexOf('DECISION_HINTS'), orientation.indexOf('</script>'));
    assert.doesNotMatch(hospital, /hospitalizationForm/);

    assert.doesNotMatch(orientation, /death_occurred_at|death_causes|death_place/);
    assert.match(orientation, /registre des décès/);
    assert.doesNotMatch(discharge, /id="death_occurred_at"/);
    assert.match(discharge, /registre des décès/);
});

/**
 * ADR-114 — une demande transmise ne se retransmet pas : l'écran la lit, mène
 * à son module, et la changer passe par une annulation tracée.
 */
test('une conduite déjà transmise se lit et se change, jamais ne se retransmet', () => {
    assert.match(orientation, /<div v-if="locked"/);
    assert.match(orientation, /:href="active\.request\.module_url"/);
    assert.match(orientation, /Changer de conduite/);
    assert.doesNotMatch(codeOf(orientation), /Transmettre la demande/);
});
