import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const maternity = fs.readFileSync('resources/js/Pages/Maternity/Show.vue', 'utf8');
const patient = fs.readFileSync('resources/js/Pages/Patients/Show.vue', 'utf8');
const sheet = fs.readFileSync('resources/js/Pages/Medicine/MedicalRecordPrint.vue', 'utf8');
const episode = fs.readFileSync('resources/js/Pages/Episodes/Show.vue', 'utf8');

const component = fs.readFileSync('resources/js/Components/Clinical/NewbornDossiers.vue', 'utf8');
const paper = fs.readFileSync('resources/js/Components/Clinical/PaperSheet.vue', 'utf8');
const reception = fs.readFileSync('resources/js/Pages/Reception/Create.vue', 'utf8');

/**
 * ADR-146 — le bébé vit dans le dossier de sa mère : un seul composant le montre partout, et il ne porte
 * plus aucun geste de création. C'est la Réception qui en fait un patient, en l'accueillant.
 */
test('le composant des nouveau-nés ne crée plus aucun dossier', () => {
    assert.doesNotMatch(component, /Créer le dossier|useForm|create_url_base|UserPlus/);
    assert.doesNotMatch(maternity, /Créer le dossier du nouveau-né|creatingNewbornPatient|newbornPatientForm/);
    for (const page of [maternity, episode]) {
        assert.match(page, /NewbornDossiers/);
    }
});

test('un bébé pas encore patient a pourtant son dossier, et le dit', () => {
    assert.match(component, /baby\.medical_record_url/);
    assert.match(component, /Pas encore patient — dossier ouvert à l’accueil/);
    // Le nom vient du serveur : un bébé non prénommé se dit par sa mère, jamais par un prénom inventé.
    assert.match(component, /\{\{ baby\.name \}\}/);
});

/**
 * Le dossier Maternité terminé est en lecture seule : un `<fieldset disabled>` désactive tous ses boutons.
 * Le composant vit donc hors du fieldset — sans quoi les dossiers des bébés seraient inaccessibles.
 */
test('le composant est placé hors du formulaire verrouillé', () => {
    const at = maternity.indexOf('<NewbornDossiers');
    const fieldset = maternity.indexOf('<fieldset class="min-w-0 space-y-5 p-5"');

    assert.ok(at > -1 && fieldset > -1 && at < fieldset, 'NewbornDossiers doit précéder le fieldset');
});

test('le nom du bébé se saisit dans sa fiche, facultatif, avec celui de sa mère en repère', () => {
    assert.match(maternity, /newborn_data\.newborns\.\$\{index\}\.last_name/);
    assert.match(maternity, /newborn_data\.newborns\.\$\{index\}\.first_name/);
    assert.match(maternity, /:placeholder="patient\.last_name"/);
});

/** ADR-146 — « accouchement chez nous ou externe ? », et le bébé choisi chez sa mère. */
test('la Réception demande l\'origine de l\'accouchement et retrouve le bébé chez sa mère', () => {
    const picker = fs.readFileSync('resources/js/Components/Reception/NewbornPicker.vue', 'utf8');

    assert.match(picker, /Né à la clinique/);
    assert.match(picker, /Né ailleurs/);
    assert.match(picker, /\/reception\/newborns\?mother=\$\{patient\.uuid\}/);
    assert.match(picker, /\/reception\/newborns\/\$\{baby\.record_uuid\}\/\$\{baby\.newborn_uuid\}\/patient/);
    // Un bébé déjà patient se sélectionne sans rien créer.
    assert.match(picker, /if \(baby\.patient\) \{\n        emit\('select', baby\.patient\);/);
    // Le sexe n'est demandé que si la fiche ne le porte pas.
    assert.match(picker, /const needsSex = \(baby\) => !baby\.patient && !baby\.sex_code;/);
    // Rien de clinique n'est affiché par l'accueil.
    assert.doesNotMatch(picker, /apgar|birth_weight|care_notes/);

    // L'écran d'arrivée en fait un patient sélectionné, comme n'importe quel patient existant.
    assert.match(reception, /patientMode === 'newborn'/);
    assert.match(reception, /@select="newbornSelected"/);
    assert.match(reception, /@internal="chooseInternalNewborn"/);
    assert.match(reception, /@external="chooseExternalNewborn"/);
});

test('un nouveau-né né ici ne voit aucun formulaire patient, celui né ailleurs reçoit une identité de bébé', () => {
    const picker = fs.readFileSync('resources/js/Components/Reception/NewbornPicker.vue', 'utf8');

    assert.match(reception, /v-if="patientMode === 'create' \|\| isExternalNewborn"/);
    assert.match(reception, /registration_context: 'EXTERNAL_NEWBORN'/);
    assert.match(reception, /Identité du nouveau-né né ailleurs/);
    assert.match(reception, /v-if="!isExternalNewborn" label="Téléphone"/);
    assert.match(reception, /v-if="!isExternalNewborn" label="Email"/);
    assert.match(reception, /v-if="!isExternalNewborn" label="Profession"/);
    assert.match(reception, /v-if="!isExternalNewborn" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"/);
    assert.match(reception, /Parent ou responsable à joindre/);
    assert.match(picker, /emit\('internal'\)/);
    assert.match(picker, /emit\('external'\)/);
});

test('naissance, sexe et domicile du bébé restent sur la même rangée large', () => {
    const birthAt = reception.indexOf(":label=\"isExternalNewborn ? 'Naissance du bébé' : 'Naissance ou âge'\"");
    const rowStart = reception.lastIndexOf('<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">', birthAt);
    const nextRow = reception.indexOf('<div v-if="!isExternalNewborn" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">', birthAt);
    const row = reception.slice(rowStart, nextRow);

    assert.notEqual(rowStart, -1);
    assert.match(row, /label="Sexe"/);
    assert.match(row, /Domicile familial/);
});

test('un bébé qui a son dossier ne peut plus être retiré du dossier Maternité', () => {
    assert.match(maternity, /form\.newborn_data\.newborns\.length > 1 && ! newbornPatient\(newborn\)/);
});

test('le dossier patient dit de qui le bébé est l\'enfant, et sa mère liste ses enfants', () => {
    assert.match(patient, /family: \{ type: Object/);
    assert.match(patient, /Nouveau-né\{\{ family\.mother\.birth_rank/);
    assert.match(patient, /Enfant\{\{ family\.children\.length > 1/);
});

test('le dossier médical d\'un bébé est une feuille de nouveau-né, pas celle d\'un adulte', () => {
    assert.match(sheet, /birth: \{ type: Object, default: null \}/);
    // ADR-146 (amendement) — la naissance d'un enfant est gardée par le droit de SON dossier ; seule la
    // section Maternité de la mère reste gardée par `maternity.view`.
    assert.match(sheet, /restricted\('newborns\.medical_record\.view'\)/);
    assert.match(sheet, /restricted\('maternity\.view'\)/);

    // Les rubriques d'un adulte ne s'appliquent pas : elles n'existent que pour un patient qui n'est pas un bébé.
    for (const adultOnly of ['Situation Maritale', 'Profession', 'Tabac (Oui/Non)', 'TRAITEMENTS ACTUELS']) {
        const at = sheet.indexOf(adultOnly);
        assert.ok(at > sheet.indexOf('v-if="!birth"') - 1, `${adultOnly} doit venir après le bloc adulte`);
    }
    assert.equal((sheet.match(/<table v-if="!birth"/g) ?? []).length, 3);

    // Ce qui décrit sa naissance et sa mère est lu, dans l'ordre d'une feuille de naissance.
    for (const label of ['MÈRE — PERSONNE À JOINDRE', 'NAISSANCE ET ACCOUCHEMENT', 'ÉTAT À LA NAISSANCE', 'Mode d’accouchement',
        'Terme (semaines)', 'Complications', 'Lieu de naissance', 'Poids de naissance (g)', 'Apgar']) {
        assert.ok(sheet.includes(label), `${label} manque à la feuille du nouveau-né`);
    }
    assert.match(sheet, /birth\.mother\.phone/);
    assert.match(sheet, /birth\.mother\.address/);

    // Rien de l'histoire obstétricale de la mère n'est lu ici.
    const babyBlock = sheet.slice(sheet.indexOf('<template v-if="birth">'), sheet.indexOf('<table v-if="!birth" class="ps-table">'));
    assert.doesNotMatch(babyBlock, /pregnancy|gravidity|labor|prenatal|risk_factors|placenta/);
});

test('le dossier médical se lit sans passage et réunit la mère et ses bébés en onglets', () => {
    assert.match(sheet, /episode: \{ type: Object, default: null \}/);
    assert.match(sheet, /:back-href="back\.href"/);
    assert.match(sheet, /<span v-if="episode" class="ps-muted">/);
    assert.match(sheet, /dossiers: \{ type: Array, default: null \}/);
    assert.match(sheet, /Dossiers médicaux de la mère et de ses bébés/);
    // L'onglet courant est marqué, celui d'un bébé sans dossier est inactif et le dit.
    assert.match(sheet, /:aria-current="tab\.current \? 'page' : undefined"/);
    assert.match(sheet, /n\\'est pas encore créé/);
    // Les onglets ne s'impriment jamais : ils passent par le slot écran de la feuille.
    assert.match(paper, /v-if="\$slots\.tabs" class="ps-actions"/);
});

test('le dossier patient ouvre le dossier médical sans passage', () => {
    assert.match(patient, /\/patients\/\$\{patient\.uuid\}\/dossier-medical/);
    assert.match(patient, /child\.medical_record_url/);
});


/** ADR-146 — un bébé accueilli reste dans le répertoire ; sa ligne dit de qui il est l'enfant. */
test('le répertoire nomme la mère d\'un patient nouveau-né', () => {
    const directory = fs.readFileSync('resources/js/Pages/Patients/Index.vue', 'utf8');

    assert.match(directory, /Nouveau-né de \{\{ patient\.newborn_of\.name \}\}/);
    // Un clic mène au dossier de la mère, sans passer par la recherche.
    assert.match(directory, /:href="`\/patients\/\$\{patient\.newborn_of\.uuid\}`"/);
    // Sans le droit d'ouvrir un dossier, la mention reste lisible mais n'est plus un lien.
    assert.match(directory, /v-else-if="patient\.newborn_of"/);
});

/** ADR-146 — le dossier de la mère montre ses bébés, et le répertoire dit lesquelles ont accouché ici. */
test('le dossier de la mère porte une carte de ses nouveau-nés, patients ou non', () => {
    const show = fs.readFileSync('resources/js/Pages/Patients/Show.vue', 'utf8');
    const directory = fs.readFileSync('resources/js/Pages/Patients/Index.vue', 'utf8');

    assert.match(show, /Nouveau-né\{\{ family\.children\.length > 1 \? 's' : '' \}\}/);
    assert.match(show, /v-else>Pas encore patient<\/span>/);
    // Un bébé pas encore patient n'a ni numéro ni dossier patient : ni l'un ni l'autre n'est promis.
    assert.match(show, /v-if="child\.uuid"/);
    assert.match(show, /v-if="child\.medical_record_url"/);
    assert.match(directory, /patient\.newborn_children/);
    assert.match(directory, /bébé\{\{ patient\.newborn_children > 1 \? 's' : '' \}\} né/);
});

/** ADR-116 (amendement) — une feuille imprimée ne contourne aucun droit : chaque section nomme le sien. */
test('la feuille dit quel droit manque, plutôt que de laisser une case vide', () => {
    for (const [prop, permission] of [['diagnosis_visible', 'diagnoses.view'], ['record_visible', 'medical_record.view'], ['stay_visible', 'hospitalization.view']]) {
        assert.match(sheet, new RegExp(`${prop}: \\{ type: Boolean, default: true \\}`));
        assert.ok(sheet.includes(`restricted('${permission}')`), `${permission} doit être nommée sur la feuille`);
    }
    // Une case refusée ne se lit jamais comme « rien à signaler ».
    assert.match(sheet, /diagnosis_visible \? diagnosis : restricted\('diagnoses\.view'\)/);
});

/**
 * ADR-146 (amendement) — le nouveau-né a ses propres droits, et la catégorie doit exister dans
 * « Rôles & permissions » : sans elle, le Super Administrateur ne les trouverait pas pour les accorder.
 */
test('les droits du nouveau-né ont leur catégorie dans l’écran des permissions', () => {
    const categories = fs.readFileSync('resources/js/utilities/permissionCategories.js', 'utf8');

    assert.match(categories, /newborns: \{ label: 'Nouveau-nés/);
});
