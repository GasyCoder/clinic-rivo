import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const show = fs.readFileSync('resources/js/Pages/Hospitalization/Show.vue', 'utf8');
const form = fs.readFileSync('resources/js/Components/Clinical/ClinicalDischargeForm.vue', 'utf8');

/** ADR-147 — la sortie d'hospitalisation reprend ce qui est déjà conclu. */
test('la sortie d’hospitalisation reçoit les diagnostics du dossier', () => {
    assert.match(show, /:diagnoses="diagnoses"/);
    assert.match(show, /diagnoses: \{ type: Array, default: \(\) => \[\] \}/);
});

/** Le formulaire est partagé : il ne renvoie pas à l'écran de l'autre module. */
test('l’invite du diagnostic vient du parent, jamais codée dans le formulaire', () => {
    assert.match(form, /diagnosisHint: \{ type: String/);
    assert.match(form, /\{\{ diagnosisHint \}\}/);
    assert.match(show, /diagnosis-hint="Aucun diagnostic posé pour ce passage/);
});

/** Poser un diagnostic reste gardé par son droit ; le serveur revérifie. */
test('ajouter un diagnostic exige can_add_diagnosis', () => {
    assert.match(show, /v-if="capabilities\.can_add_diagnosis" #diagnosis-actions/);
    assert.match(show, /\/hospitalisation\/\$\{props\.stay\.uuid\}\/diagnostics/);
    // Aucun montant, aucune écriture dans la consultation depuis cet écran.
    assert.doesNotMatch(show, /\/medicine\/orientations\/.*\/diagnoses/);
});

/** ADR-148 — la visite de service mène à l'assistant Médecine, jamais à un doublon. */
test('la page du séjour ouvre et liste les visites de service', () => {
    assert.match(show, /\/hospitalisation\/\$\{props\.stay\.uuid\}\/visites/);
    assert.match(show, /capabilities\.can_view_visits \|\| capabilities\.can_open_visit/);
    assert.match(show, /Reprendre la visite en cours/);
    // Les liens mènent au parcours existant, servis par le serveur.
    assert.match(show, /:href="visit\.url"/);
});

/** ADR-148 — le bloc de sortie est regroupé par sujet, non étalé. */
test('la sortie est présentée en trois sections encadrées', () => {
    for (const legend of ['1 · Décision', '2 · Conclusion médicale', '3 · Consignes remises au patient']) {
        assert.ok(form.includes(legend), `${legend} manque au formulaire de sortie`);
    }
    // « Contrôle » rejoint les consignes au lieu d'occuper sa propre rangée.
    assert.match(form, /lg:grid-cols-2 xl:grid-cols-3/);
    assert.doesNotMatch(form, /<!-- Contrôle -->\n\s*<div v-if="!isDeceased">/);
});

/** ADR-149 — un patient dans un lit se signale, et sa conduite à tenir le sait. */
test('la consultation dit que le patient est hospitalisé', () => {
    const card = fs.readFileSync('resources/js/Components/Clinical/ClinicalOrientationCard.vue', 'utf8');
    const header = fs.readFileSync('resources/js/Components/Clinical/ClinicalCondensedHeader.vue', 'utf8');
    const medicine = fs.readFileSync('resources/js/Pages/Medicine/Show.vue', 'utf8');

    assert.match(card, /hospitalStay: \{ type: Object, default: null \}/);
    assert.match(card, /Patient hospitalisé/);
    // La sortie ne se prononce pas d'ici : l'écran dit où, au lieu de laisser essayer.
    assert.match(card, /la page du séjour/);
    assert.match(header, /Hospitalisé<span v-if="hospitalStay\.room_bed"/);
    assert.match(medicine, /:hospital-stay="hospital_stay"/);
});

/** ADR-150 — un socle à zéro ne veut pas dire « personne n'y a accès ». */
test('l’éditeur de socle signale les comptes qui portent des exceptions', () => {
    const editor = fs.readFileSync('resources/js/Components/Rbac/RoleBaselineEditor.vue', 'utf8');

    assert.match(editor, /selectedRole\?\.users_with_exceptions_count/);
    assert.match(editor, /qui l’emportent sur ce socle/);
    assert.match(editor, /Exceptions par compte/);
});

/** ADR-151 — un droit se cherche sous le nom que l'écran lui donne. */
test('les droits qui agissent dans plusieurs modules le disent', () => {
    const categories = fs.readFileSync('resources/js/utilities/permissionCategories.js', 'utf8');
    const seeder = fs.readFileSync('database/seeders/PermissionSeeder.php', 'utf8');

    // « Prononcer la sortie » d'un séjour est gouverné par medical_discharge.create :
    // chercher « hospitalisation » doit le trouver, catégorie comme libellé.
    assert.match(categories, /medical_discharge: \{ label: 'Sortie médicale \(consultation, hospitalisation, pédiatrie\)'/);
    assert.match(seeder, /'medical_discharge\.create' => 'Prononcer une sortie médicale \(consultation, sortie d’hospitalisation, pédiatrie\)'/);
    assert.match(seeder, /'consultations\.create' => 'Ouvrir une consultation[^']*hospitalisation/);
    assert.match(seeder, /'diagnoses\.create' => 'Poser un diagnostic \(consultation, et sortie d’hospitalisation\)'/);
});

/** ADR-153 — cocher un droit au socle n'ouvre rien à un compte qui le refuse. */
test('l’éditeur de socle marque les droits refusés individuellement', () => {
    const editor = fs.readFileSync('resources/js/Components/Rbac/RoleBaselineEditor.vue', 'utf8');
    const page = fs.readFileSync('resources/js/Pages/SuperAdmin/Roles/Index.vue', 'utf8');

    assert.match(editor, /const overridesByPermission = computed/);
    assert.match(editor, /Refusé à \{\{ overridesByPermission\.get\(permission\.id\)\.deny \}\} compte/);
    // Le repère ne compte que les comptes du rôle réglé.
    assert.match(editor, /user\.role\?\.code !== selectedRoleCode\.value/);
    // Et la page lui passe bien les comptes.
    assert.match(page, /:users="users"\n\s*:permission-catalog/);
});
