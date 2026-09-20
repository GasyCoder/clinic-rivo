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

/**
 * ADR-135 : terminer dit ce qui vient ensuite. « Terminer » reste le geste par
 * défaut ; orienter vers Médecine est un choix explicite, avec un message
 * facultatif — jamais une orientation déduite.
 */
test('terminer offre deux issues explicites, la première par défaut', () => {
    assert.match(page, /const outcome = ref\('end'\)/);
    assert.match(page, /Terminer et orienter vers Médecine/);
    assert.match(page, /:clearable="false"/);
    // La note n'est envoyée que si la patiente est orientée.
    assert.match(page, /orient_to_medicine: toMedicine\.value/);
    assert.match(page, /medicine_note: toMedicine\.value \?/);
    assert.match(page, /Message pour le médecin/);
});

/**
 * ADR-136 : le dossier s'adapte à l'acte demandé à la Réception. Les sections
 * mises en avant sont une aide — jamais un verrou — et viennent du serveur.
 */
test('les sections mises en avant viennent du serveur et ne verrouillent rien', () => {
    assert.match(page, /actProfile: \{ type: Object/);
    assert.match(page, /suggested: props\.actProfile\.sections\.includes\(section\.key\)/);
    // On ouvre sur la première section attendue et non renseignée, sinon comme avant.
    assert.match(page, /section\.suggested && ! section\.filled/);
    assert.match(page, /\?\? 'context'/);
    // Une section ne se masque jamais faute d'être mise en avant : `visible` ne lit que les droits.
    assert.doesNotMatch(page, /visible:[^\n]*suggested/);
});

test('un accouchement gémellaire ouvre deux fiches vides, jamais remplies', () => {
    assert.match(page, /props\.actProfile\.expected_newborns \?\? 1/);
    assert.match(page, /const blankNewborn = \(\) => \(\{ first_name: '', last_name: '', sex: '', birth_weight_g: '', condition: '', apgar: '', care_notes: '' \}\)/);
    assert.match(page, /MAX_NEWBORNS/);
});

test('les actes demandés à la Réception s’enregistrent en un clic', () => {
    assert.match(page, /Demandé à la Réception/);
    assert.match(page, /const recordPlanned = \(act\)/);
    // Un acte qui exige une précision passe par le formulaire, pas par le clic direct.
    assert.match(page, /catalogByUuid\.value\[act\.uuid\]\?\.requires_note/);
    // Le geste rapide n'a pas de chemin d'écriture à lui : c'est l'endpoint existant.
    assert.match(page, /router\.post\(\s*`\/maternity\/orientations\/\$\{props\.orientation\.uuid\}\/procedures`/);
    assert.match(page, /! act\.done && canRecordProcedures/);
});

test('les actes se choisissent dans un panier, avec quantité et précision par ligne', () => {
    assert.match(page, /const basketForm = useForm\(\{ lines: \[\], consumables: \[\], consumable_notes: '' \}\)/);
    assert.match(page, /aria-label="Panier d’actes"/);
    // Un clic ajoute l'acte, un second le retire ; il n'entre qu'une fois.
    assert.match(page, /role="checkbox"/);
    assert.match(page, /const toggleInBasket = \(uuid\)/);
    assert.match(page, /if \(! catalogByUuid\.value\[uuid\] \|\| inBasket\(uuid\)\) return;/);
    // Quantité réglable ligne par ligne, jamais sous 1 par les boutons.
    assert.match(page, /const changeQuantity = \(line, delta\)/);
    assert.match(page, /Math\.max\(1, /);
    // Les actes demandés à la Réception s'y ajoutent d'un clic.
    assert.match(page, /const addPlannedToBasket/);
});

test('le panier s’enregistre d’un geste, et « Autres » exige sa précision', () => {
    assert.match(page, /\/procedures\/batch/);
    assert.match(page, /const missingNote = \(line\)/);
    assert.match(page, /catalogByUuid\.value\[line\.catalog_item_uuid\]|lineItem\(line\)\?\.requires_note/);
    assert.match(page, /const basketReady = computed/);
    assert.match(page, /:disabled="! basketReady \|\| basketForm\.processing"/);
    assert.match(page, /Précisez l’acte \(obligatoire\)/);
    // Après un succès, `reset()` reviendrait au contenu pris pour référence : on vide directement.
    assert.match(page, /onSuccess: clearBasket/);
    assert.match(page, /const clearBasket = \(\) => \{ basketForm\.lines = \[\]; basketForm\.consumables = \[\]; basketForm\.consumable_notes = ''; \};/);
    // Une erreur de ligne s'affiche sur sa ligne.
    assert.match(page, /basketForm\.errors\[`procedures\.\$\{index\}\.notes`\]/);
});

/**
 * ADR-136 : la saisie survit à une actualisation, côté serveur et par compte —
 * comme la fiche de soins (ADR-073).
 */
test('la saisie en cours est conservée par le composable partagé', () => {
    assert.match(page, /import \{ useFormDraft \} from '@\/composables\/useFormDraft'/);
    assert.match(page, /endpoint: `\/maternity\/orientations\/\$\{props\.orientation\.uuid\}\/draft`/);
    assert.match(page, /forms: \{ record: form, basket: basketForm, cesarean: cesareanForm \}/);
    assert.match(page, /enabled: Boolean\(props\.capabilities\.can_edit\)/);
    // Un vrai enregistrement rend le brouillon caduc.
    assert.match(page, /onSuccess: \(\) => draft\.markSaved\(\)/);
    // Effacer suspend d'abord l'enregistrement automatique : sinon il recréerait ce qu'on efface.
    assert.match(page, /draft\.suspend\(\);\s*router\.delete/);
    assert.match(page, /Effacer le brouillon/);
});

/**
 * ADR-139 : les soins du bébé se notent par nouveau-né — avec des jumeaux,
 * l'un peut être sous photothérapie et pas l'autre. Ceux de la mère restent
 * uniques : il n'y a qu'une mère.
 */
test('les soins bébé se notent par nouveau-né, ceux de la mère restent uniques', () => {
    assert.match(page, /care_notes: ''/);
    assert.match(page, /v-model="newborn\.care_notes"/);
    assert.match(page, /Soins — nouveau-né \$\{index \+ 1\}/);
    assert.match(page, /v-model="form\.maternal_care_notes"/);
    // Un nouveau-né enregistré avant ce champ le reçoit vide.
    assert.match(page, /\.\.\.blankNewborn\(\), \.\.\.newborn/);
});

test('une ancienne note « Soins bébé » commune n’est ni effacée ni proposée à la saisie', () => {
    assert.match(page, /const legacyBabyCare = Boolean\(String\(props\.record\?\.baby_care_notes/);
    assert.match(page, /<FormField v-if="legacyBabyCare"/);
    assert.doesNotMatch(page, /v-model="form\.baby_care_notes"[^\n]*\n[^\n]*\n[^\n]*Soins bébé"/);
});

/**
 * ADR-140 : un acte enregistré se corrige (crayon) ou se retire (corbeille) ;
 * celui d'un médecin reste intact pour le personnel. Les gestes permis viennent
 * du serveur — l'écran ne les déduit pas.
 */
test('un acte enregistré se corrige ou se retire, selon ce que le serveur permet', () => {
    assert.match(page, /v-if="procedure\.can_modify"/);
    assert.match(page, /:aria-label="`Modifier \$\{procedure\.procedure_name\}`"/);
    assert.match(page, /:aria-label="`Retirer \$\{procedure\.procedure_name\}`"/);
    assert.match(page, /<Pencil class=/);
    assert.match(page, /<Trash2 class=/);
    // Corriger la quantité et la précision, jamais l'acte : changer d'acte, c'est retirer et ajouter.
    assert.match(page, /const saveEdit = \(procedure\) => editForm\.put\(/);
    assert.match(page, /\/procedures\/\$\{procedure\.uuid\}/);
    assert.doesNotMatch(page, /editForm\.catalog_item_uuid/);
    // « Autres » garde sa description à la correction comme à l'enregistrement.
    assert.match(page, /editNeedsNote\(procedure\) && ! String\(editForm\.notes/);
});

test('retirer passe par une confirmation, pas par une fenêtre native', () => {
    assert.match(page, /title="Retirer cet acte \?"/);
    assert.match(page, /removeForm\.delete\(/);
    assert.doesNotMatch(page, /window\.confirm|confirm\(/);
});

test('l’acte d’un médecin reste visible et verrouillé, jamais masqué', () => {
    assert.match(page, /v-else-if="procedure\.locked_by_physician"/);
    assert.match(page, /Enregistré par un médecin : non modifiable depuis la Maternité/);
    // Le verrou ne se décide pas ici : aucun nom de rôle dans l'écran.
    assert.doesNotMatch(page, /MEDICINE/);
});

/**
 * ADR-141 / ADR-142 : un acte est facturé et le matériel utilisé part à la
 * Pharmacie, dans le même geste que les actes — jamais un second formulaire dont
 * le contenu se perdrait si on valide sans l'avoir envoyé.
 */
test('le matériel utilisé part dans le même panier et le même envoi que les actes', () => {
    assert.match(page, /aria-label="Panier d’actes"/);
    assert.match(page, /basketForm\.consumables/);
    // Chaque partie n'est envoyée que si elle existe : le matériel peut partir seul.
    assert.match(page, /\.\.\.\(data\.lines\.length \? \{/);
    assert.match(page, /\.\.\.\(data\.consumables\.length \? \{/);
    assert.match(page, /consumable_notes: String\(data\.consumable_notes/);
    assert.match(page, /const basketHasContent = computed/);
    // Sans acte, le bouton le dit : il transmet le matériel.
    assert.match(page, /'Transmettre le matériel'/);
});

test('le matériel habituel de l’acte est une suggestion, jamais une règle', () => {
    assert.match(page, /function suggestConsumablesFor\(actUuid\)/);
    assert.match(page, /default_consumables/);
    // Un compte qui ne peut pas déclarer du matériel ne reçoit aucune suggestion.
    assert.match(page, /if \(! canRequestConsumables\.value\) return;/);
    // Retirer un acte ne retire que ce qu'il avait apporté et que personne n'a corrigé.
    assert.match(page, /line\.touched \|\| line\.suggested_by\.length > 0/);
    assert.match(page, /line\.touched = true/);
    // Un produit hors du catalogue déclarable ne part jamais.
    assert.match(page, /if \(! consumableByUuid\.value\[suggestion\.medicine_uuid\]\) continue;/);
});

test('un dépassement de stock se dit avant l’envoi, sans jamais afficher un prix', () => {
    assert.match(page, /const consumableShort = \(line\)/);
    assert.match(page, /Au-delà du stock connu/);
    assert.match(page, /procedure\.billing\.label/);
    // Aucun montant côté poste de soins (ADR-036).
    assert.doesNotMatch(page, /billing\.amount|unit_price|total_amount|formatMoney/);
});

test('les demandes de matériel transmises se lisent et s’annulent avec un motif', () => {
    assert.match(page, /Matériel transmis à la Pharmacie/);
    assert.match(page, /capabilities\.can_cancel_consumables && request\.can_be_cancelled/);
    assert.match(page, /\/consumables\/\$\{cancellingRequest\.value\.uuid\}\/cancel/);
    assert.match(page, /! cancelForm\.reason\.trim\(\)/);
});

test('la file Pharmacie dit d’où vient chaque demande', () => {
    const queue = fs.readFileSync('resources/js/Pages/Pharmacy/Partials/CareConsumableQueue.vue', 'utf8');

    assert.match(queue, /request\.source_label/);
    assert.match(queue, /Maternité/);
});
