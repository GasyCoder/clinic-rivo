import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const show = fs.readFileSync('resources/js/Pages/Hospitalization/Show.vue', 'utf8');
// La carte « Séjour » porte l'emplacement, sa correction et la saisie libre.
const location = fs.readFileSync('resources/js/Components/Hospitalization/StayLocationCard.vue', 'utf8');
const index = fs.readFileSync('resources/js/Pages/Hospitalization/Index.vue', 'utf8');
const picker = fs.readFileSync('resources/js/Components/Hospitalization/BedPicker.vue', 'utf8');
const board = fs.readFileSync('resources/js/Components/Hospitalization/BedBoard.vue', 'utf8');
const portal = fs.readFileSync('resources/js/Pages/SuperAdmin/HospitalBeds/Index.vue', 'utf8');
const menu = fs.readFileSync('resources/js/Components/Layout/Menu.vue', 'utf8');
const styles = fs.readFileSync('resources/js/utilities/hospitalBeds.js', 'utf8');

/** ADR-164 — dès le premier lit configuré, on choisit un lit ; sinon la saisie reste libre. */
test('le séjour choisit un lit libre quand le site en a configuré', () => {
    assert.match(show, /bedsConfigured: \{ type: Boolean, default: false \}/);
    assert.match(show, /<BedPicker v-model="bedForm\.hospital_bed_uuid" :services="freeBeds" \/>/);
    // Attribuer / corriger : sans mutation ; changer de lit : un nouvel emplacement.
    assert.match(show, /\.put\(`\/hospitalisation\/\$\{props\.stay\.uuid\}`, done\)/);
    assert.match(show, /\.post\(`\/hospitalisation\/\$\{props\.stay\.uuid\}\/mouvements`, done\)/);
    // La saisie libre n'est proposée que sans lit configuré.
    assert.match(location, /const canMoveFree = computed\(\(\) => props\.capabilities\.can_move && !props\.bedsConfigured && !editingRoom\.value\);/);
    assert.match(location, /v-else-if="canMoveFree"/);
    assert.match(location, /Lit à attribuer/);
    // La carte demande les fenêtres de lit ; la page les ouvre.
    assert.match(show, /<StayLocationCard[\s\S]*?@bed="openBedDialog"[\s\S]*?@move="openMove"/);
});

test('le choix d’un lit est un groupe de boutons radio, filtrable', () => {
    assert.match(picker, /role="radiogroup"/);
    assert.match(picker, /role="radio"/);
    assert.match(picker, /:aria-checked="modelValue === bed\.uuid"/);
    assert.match(picker, /Aucun lit libre sur le site/);
});

/** Le portail voit l'occupation, jamais le nom du patient ; la clinique, si. */
test('le portail n’affiche jamais le nom d’un patient', () => {
    assert.doesNotMatch(portal, /occupant\.patient/);
    assert.match(portal, /occupant\.episode_number/);
    assert.match(board, /bed\.occupant\.patient \?\? bed\.occupant\.episode_number/);
});

test('le portail écrit par l’API du site, et un archivage exige un motif signé', () => {
    assert.match(portal, /`\/super-admin\/hospital-beds\/\$\{selectedCode\.value\}`/);
    assert.match(portal, /:dismissible="false"/);
    assert.match(portal, /reasonForm\.reason\.trim\(\)\.length < 5/);
    // Un lit occupé ne se met pas hors service et ne s'archive pas.
    assert.match(portal, /v-if="canArchive && bedDialog\.bed\.state !== 'OCCUPIED'"/);
    assert.match(portal, /v-if="canUpdate && bedDialog\.bed\.state === 'FREE'"/);
});

test('l’apparence d’un lit est écrite une fois pour les trois écrans', () => {
    for (const source of [portal, board]) {
        assert.match(source, /from '@\/utilities\/hospitalBeds'/);
    }
    assert.match(styles, /FREE:[\s\S]*OCCUPIED:[\s\S]*OUT_OF_SERVICE:/);
});

test('la liste de l’Hospitalisation propose le plan des lits', () => {
    assert.match(index, /<BedBoard v-if="beds && view === 'beds'"/);
    assert.match(index, /beds: \{ type: Object, default: null \}/);
    assert.match(index, /stay\.needs_bed/);
});

test('le portail a son entrée, gardée par hospital_beds.view', () => {
    assert.match(menu, /text: 'Services, chambres & lits', link: '\/super-admin\/hospital-beds', permission: 'hospital_beds\.view'/);
});

/**
 * ADR-164 — sans lit configuré, rien ne disparaît en silence : l'onglet « Plan
 * des lits » reste et dit où les lits se créent, et le séjour explique pourquoi
 * la chambre se note encore à la main.
 */
test('sans lit configuré, l’onglet et le séjour disent où les lits se créent', () => {
    assert.match(index, /bedsConfigured: \{ type: Boolean, default: false \}/);
    assert.doesNotMatch(index, /<div v-if="beds" class="inline-flex[^"]*" role="tablist"/);
    assert.match(index, /<Card v-else-if="view === 'beds'"/);
    assert.match(index, /Aucun lit n’est encore configuré pour ce site/);
    assert.match(location, /<p v-if="!bedsConfigured && isActive && !editingRoom"/);
    assert.match(location, /Les lits de ce site ne sont pas encore configurés/);
});

/**
 * Régression du 2026-09-21 : une note glissée entre la fiche et le formulaire de
 * saisie libre avait récupéré son `v-else`, si bien que le formulaire s'affichait
 * dès que les lits étaient configurés. La saisie libre porte donc sa propre
 * condition, et n'existe jamais une fois les lits configurés.
 */
test('la saisie libre du lieu n’apparaît jamais quand les lits sont configurés', () => {
    assert.match(location, /<form v-if="editingRoom && !bedsConfigured" class="space-y-3" @submit\.prevent="saveRoom">/);
    assert.doesNotMatch(location, /<form v-else[^>]*saveRoom/);
    assert.match(location, /<dl v-if="!editingRoom \|\| bedsConfigured"/);
    assert.match(show, /<Dialog v-if="!bedsConfigured" v-model:open="moveOpen"/);
    // La note vient après le formulaire : elle ne peut plus capter un v-else.
    assert.ok(location.indexOf('@submit.prevent="saveRoom"') < location.indexOf('Les lits de ce site ne sont pas encore configurés'));
});
