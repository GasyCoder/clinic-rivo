import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const show = fs.readFileSync('resources/js/Pages/Hospitalization/Show.vue', 'utf8');
const form = fs.readFileSync('resources/js/Components/Clinical/ClinicalDischargeForm.vue', 'utf8');
const openConsultations = fs.readFileSync('resources/js/Components/Hospitalization/StayOpenConsultations.vue', 'utf8');
const diagnosisAdd = fs.readFileSync('resources/js/Components/Hospitalization/StayDiagnosisAdd.vue', 'utf8');
const diagnosesCard = fs.readFileSync('resources/js/Components/Hospitalization/StayDiagnosesCard.vue', 'utf8');
const exit = fs.readFileSync('resources/js/Components/Hospitalization/StayExit.vue', 'utf8');
const exitContext = fs.readFileSync('resources/js/Components/Hospitalization/StayExitContext.vue', 'utf8');

/** ADR-147/156 — les diagnostics du séjour restent sa trace clinique. */
test('le séjour garde ses diagnostics, dans leur propre carte', () => {
    assert.match(show, /diagnoses: \{ type: Array, default: \(\) => \[\] \}/);
    assert.match(diagnosesCard, /v-for="diagnosis in diagnoses"/);
    // Sous la carte « Séjour », dans la même colonne : où est le patient, puis ce qu'il a.
    assert.match(show, /<div class="space-y-5">\s*<StayLocationCard[\s\S]*?\/>\s*<StayDiagnosesCard/);
});

/** Le formulaire est partagé : il ne renvoie pas à l'écran de l'autre module. */
test('l’invite du diagnostic vient du parent, jamais codée dans le formulaire', () => {
    assert.match(form, /diagnosisHint: \{ type: String/);
    assert.match(form, /\{\{ diagnosisHint \}\}/);
});

/** Poser un diagnostic reste gardé par son droit ; le serveur revérifie. */
test('ajouter un diagnostic exige can_add_diagnosis', () => {
    assert.match(show, /:can-add="capabilities\.can_add_diagnosis"/);
    assert.match(diagnosesCard, /<footer v-if="canAdd"[\s\S]*?<StayDiagnosisAdd/);
    assert.match(diagnosisAdd, /\/hospitalisation\/\$\{props\.stayUuid\}\/diagnostics/);
    // Aucune écriture dans la consultation depuis le séjour : elle est le plus
    // souvent close (ADR-076), le diagnostic va sur le séjour (ADR-147).
    for (const source of [show, diagnosesCard, diagnosisAdd, exit]) {
        assert.doesNotMatch(source, /\/medicine\/orientations\/.*\/diagnoses/);
    }
});

/** Le contrôle d'ajout est écrit une fois : la Vue d'ensemble et la Sortie l'emploient. */
test('le champ d’ajout d’un diagnostic n’existe qu’à un endroit', () => {
    assert.doesNotMatch(show, /diagnostic-catalog\/search/);
    assert.doesNotMatch(exit, /diagnostic-catalog\/search/);
    assert.match(diagnosisAdd, /diagnostic-catalog\/search/);
});

/**
 * La sortie garde ce qui est déjà consigné (coché d'office) et permet d'en
 * ajouter un autre sans quitter l'étape : il arrive coché, lui aussi.
 */
test('la sortie ajoute un diagnostic sans renvoyer vers un autre onglet', () => {
    assert.match(exit, /<template v-if="canAddDiagnosis" #diagnosis-actions>/);
    assert.match(exit, /<StayDiagnosisAdd/);
    assert.match(exit, /Ajouter un autre diagnostic/);
    assert.doesNotMatch(exit, /Vue d’ensemble › Diagnostics/);
    // Le formulaire partagé offre la place ; il ne sait rien du séjour.
    assert.match(form, /<slot name="diagnosis-actions" \/>/);
    assert.match(show, /:can-add-diagnosis="capabilities\.can_add_diagnosis"/);
});

/**
 * Le formulaire garde toute sa largeur (ADR-132) ; les repères du séjour se
 * lisent à côté. Une section non servie se tait : jamais un zéro (ADR-102).
 */
test('la sortie place les repères du séjour dans une colonne latérale', () => {
    assert.match(show, /xl:grid-cols-\[minmax\(0,1fr\)_20rem\]/);
    assert.match(show, /<StayExitContext/);
    assert.match(show, /:readings="capabilities\.can_view_vitals \? vitalReadings : null"/);
    assert.match(show, /:active-prescriptions="prescriptions === null \? null : activePrescriptions\.length"/);
    assert.match(exitContext, /<Card v-if="readings !== null"/);
    assert.match(exitContext, /props\.activePrescriptions === null \? null :/);
    // Seul ce qui reste à relire mène quelque part.
    assert.match(exitContext, /:is="item\.count > 0 \? 'button' : 'div'"/);
});

/**
 * ADR-163 — le séjour n'a plus de carte « Visites de service » : plus aucune ne
 * s'ouvre, et une visite close ou annulée se relit sur la page du passage.
 * Seule une visite restée ouverte paraît ici, parmi les consultations à conclure.
 */
test('la page du séjour ne porte plus de carte « Visites de service »', () => {
    assert.doesNotMatch(show, /StayServiceVisits|Visites de service/);
    assert.doesNotMatch(show, /\bvisits\b/);
    assert.doesNotMatch(show, /can_open_visit|Reprendre la visite en cours/);
    assert.ok(!fs.existsSync('resources/js/Components/Hospitalization/StayServiceVisits.vue'));
});

/** ADR-163 — une visite restée ouverte s'annule là où elle est signalée. */
test('une visite restée ouverte s’annule, sans jamais être effacée', () => {
    const routes = [...openConsultations.matchAll(/\/hospitalisation\/\$\{props\.stayUuid\}\/visites\/[^`]*/g)].map((match) => match[0]);
    assert.deepEqual(routes, ['/hospitalisation/${props.stayUuid}/visites/${cancelling.value.uuid}/annuler']);
    // Le bouton suit le serveur, et l'écran dit pourquoi l'annulation est refusée.
    assert.match(openConsultations, /v-if="consultation\.can_cancel"/);
    assert.match(openConsultations, /consultation\.cancel_blockers/);
    // Une annulation se signe : la fenêtre ne se ferme pas au clic extérieur.
    assert.match(openConsultations, /:dismissible="false"/);
});

/** ADR-163 — le transfert au bloc s'annule tant que le bloc ne l'a pas programmé. */
test('le transfert au bloc s’annule depuis le séjour, selon le serveur', () => {
    assert.match(show, /v-if="surgery\.can_cancel"/);
    assert.match(show, /\/bloc\/\$\{cancellingSurgery\.value\.uuid\}\/annuler/);
    // Plus de lien englobant la ligne : un bouton ne vit pas dans un lien.
    assert.doesNotMatch(show, /:is="surgery\.url \? Link : 'div'"/);
});

/** ADR-163 — les consultations restées ouvertes sont nommées, et se clôturent d'un clic. */
test('le séjour nomme les consultations restées ouvertes', () => {
    assert.match(show, /<StayOpenConsultations/);
    assert.match(openConsultations, /v-if="consultation\.can_close"/);
    assert.match(openConsultations, /\/consultations\/\$\{consultation\.uuid\}\/cloturer/);
    assert.match(openConsultations, /consultation\.closure_blockers/);
});

/** ADR-148 — le bloc de sortie est regroupé par sujet, non étalé. */
test('la sortie est présentée en trois sections encadrées', () => {
    for (const legend of ['1 · Décision', '2 · Conclusion médicale', '3 · Consignes remises au patient']) {
        assert.ok(form.includes(legend), `${legend} manque au formulaire de sortie`);
    }
    // « Contrôle » rejoint les consignes au lieu d'occuper sa propre rangée :
    // trois colonnes, dès 1280 px — ou dès 1536 px quand le formulaire partage
    // la ligne avec la colonne de repères du séjour (`narrow`).
    assert.match(form, /'grid gap-5 lg:grid-cols-2', narrow \? '2xl:grid-cols-3' : 'xl:grid-cols-3'/);
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
    // ADR-178 : le rappel vit dans l'en-tête du rôle, au-dessus de la grille.
    const overview = fs.readFileSync('resources/js/Components/Rbac/RoleOverviewCard.vue', 'utf8');

    assert.match(overview, /role\.users_with_exceptions_count/);
    assert.match(overview, /qui l’emportent sur ce socle/);
    assert.match(overview, /Exceptions par compte/);
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
    // ADR-178 : le compte se fait dans l'espace du rôle, le repère sur la case.
    const workspace = fs.readFileSync('resources/js/Components/Rbac/RoleWorkspace.vue', 'utf8');
    const toggle = fs.readFileSync('resources/js/Components/Rbac/PermissionToggle.vue', 'utf8');
    const page = fs.readFileSync('resources/js/Pages/SuperAdmin/Roles/Index.vue', 'utf8');

    assert.match(workspace, /const overridesByPermission = computed/);
    assert.match(toggle, /Refusé à \{\{ denyCount \}\} compte/);
    // Le repère ne compte que les comptes du rôle réglé.
    assert.match(workspace, /user\.role\?\.code !== props\.role\.code/);
    // Et la page lui passe bien les comptes.
    assert.match(page, /<RoleWorkspace[\s\S]*?:users="users"/);
});

/**
 * Le transfert propose les autres sites de la clinique ; un établissement
 * extérieur se saisit par « Autre établissement ». Laissé vide, il se précise
 * ensuite dans le module Transferts (ADR-114).
 */
test('le transfert propose les autres sites, et une saisie libre pour un autre établissement', () => {
    assert.match(exit, /transferDestinations: \{ type: Array/);
    assert.match(exit, /props\.transferDestinations\.map\(\(site\) => \(\{ value: site\.destination/);
    assert.match(exit, /\{ value: OTHER_FACILITY, label: 'Autre établissement…' \}/);
    assert.match(exit, /\{ value: '', label: 'À préciser plus tard' \}/);
    assert.match(exit, /<FormField v-if="facilityChoice === OTHER_FACILITY"/);
    // La liste vient du serveur : aucun nom de site n'est écrit dans l'écran.
    assert.doesNotMatch(exit, /Mampikony|Ambondromamy|Boriziny/);
    assert.match(show, /:transfer-destinations="orderOptions\.transfer_destinations \?\? \[\]"/);
});
