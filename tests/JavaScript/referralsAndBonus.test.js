import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { emptyReferral, referralPayload } from '../../resources/js/utilities/referral.js';
import { bonusRowState, monthLabel, selectionState, shiftMonth, toggleShown } from '../../resources/js/utilities/bonus.js';

/**
 * ADR-212 — la recommandation notée à l'accueil d'un nouveau patient, et les
 * bonus du personnel. L'écran ne décide rien : il dit ce qui part au serveur
 * et où en est une ligne.
 */
const read = (file) => fs.readFileSync(file, 'utf8');

test('personne n’a recommandé la clinique : rien ne part', () => {
    assert.equal(referralPayload(emptyReferral()), null);
    assert.equal(referralPayload(null), null);
});

test('une recommandation cochée sans personne choisie ne part pas en silence', () => {
    assert.equal(referralPayload({ ...emptyReferral(), enabled: true }), undefined);
    assert.equal(referralPayload({ ...emptyReferral(), enabled: true, mode: 'other', name: '   ' }), undefined);
});

test('la personne choisie part avec sa source, un nom saisi avec son téléphone', () => {
    assert.deepEqual(
        referralPayload({ ...emptyReferral(), enabled: true, chosen: { source: 'EMPLOYEE', uuid: 'e-1', name: 'RAKOTO Jean' } }),
        { source: 'EMPLOYEE', employee_uuid: 'e-1' },
    );
    assert.deepEqual(
        referralPayload({ ...emptyReferral(), enabled: true, chosen: { source: 'PARTNER', uuid: 'p-1', name: 'Dr RABE' } }),
        { source: 'PARTNER', partner_uuid: 'p-1' },
    );
    assert.deepEqual(
        referralPayload({ ...emptyReferral(), enabled: true, mode: 'other', name: '  Voisine Hery ', phone: '' }),
        { source: 'OTHER', name: 'Voisine Hery', phone: null },
    );
});

test('l’accueil note la recommandation d’un nouveau patient seulement, et refuse une case cochée à vide', () => {
    const reception = read('resources/js/Pages/Reception/Create.vue');

    assert.match(reception, /<ReferralPicker\s+v-if="showIdentityForm && capabilities\.can_record_referral"/);
    assert.match(reception, /\.\.\.\(recommendation \? \{ referral: recommendation \} : \{\}\)/);
    assert.match(reception, /referralPayload\(referral\.value\) === undefined\) \{\s*arrivalErrors\.value = \{ referral:/);
    // Un dossier existant, un membre du personnel : jamais de recommandation.
    assert.match(reception, /return \{ patient_uuid: selectedPatient\.value\.uuid, \.\.\.contact, \.\.\.journey \};/);
    assert.match(reception, /return \{ patient_type: 'STAFF', employee_uuid: employee\.uuid, confirm_duplicate: confirmDuplicate, \.\.\.contact, \.\.\.journey \};/);
});

test('où en est le bonus d’une personne ce mois-ci', () => {
    assert.equal(bonusRowState({ reached: false, award: null }), 'BELOW');
    assert.equal(bonusRowState({ reached: true, award: null }), 'TO_VALIDATE');
    assert.equal(bonusRowState({ reached: true, award: { status: 'VALIDATED' } }), 'VALIDATED');
    assert.equal(bonusRowState({ reached: false, award: { status: 'PAID' } }), 'PAID', 'un bonus versé reste versé même si le compte a bougé');
});

test('les mois se décalent sans jamais sauter d’année à tort', () => {
    assert.equal(shiftMonth('2026-01', -1), '2025-12');
    assert.equal(shiftMonth('2026-12', 1), '2027-01');
    assert.equal(shiftMonth('2026-09', 0), '2026-09');
    assert.equal(monthLabel('2026-09'), 'septembre 2026');
});

test('les écrans bonus passent par hrUrl : le portail les sert aussi (ADR-187)', () => {
    for (const file of ['resources/js/Pages/Administration/Bonus/Index.vue', 'resources/js/Components/Bonus/BonusCategoryDialog.vue']) {
        const source = read(file);
        const bare = source.match(/(?<!hrUrl\()['"`]\/administration\/bonus[^'"`]*['"`]/g) ?? [];

        assert.deepEqual(bare, [], `${file} écrit une adresse des bonus sans hrUrl()`);
    }
});

test('valider un bonus passe par une confirmation, jamais par un clic direct', () => {
    const page = read('resources/js/Pages/Administration/Bonus/Index.vue');

    assert.match(page, /@click="openAward\('validate', category, employee\)"/);
    assert.match(page, /\.post\(hrUrl\('\/administration\/bonus\/awards'\), options\)/);
    assert.match(page, /:disabled="pending\?\.mode === 'cancel' && ! awardForm\.reason\.trim\(\)"/, 'annuler exige un motif');
});

test('« Tout sélectionner » coche la liste affichée, sans toucher ce que la recherche masque', () => {
    assert.equal(selectionState([], []), false, 'une liste vide n’est jamais « toute cochée »');
    assert.equal(selectionState([], ['a', 'b']), false);
    assert.equal(selectionState(['a'], ['a', 'b']), 'indeterminate');
    assert.equal(selectionState(['a', 'b', 'z'], ['a', 'b']), true);

    assert.deepEqual(toggleShown(['z'], ['a', 'b']), ['z', 'a', 'b']);
    assert.deepEqual(toggleShown(['a', 'z'], ['a', 'b']), ['a', 'z', 'b'], 'une liste en partie cochée se coche en entier');
    assert.deepEqual(toggleShown(['a', 'b', 'z'], ['a', 'b']), ['z'], 'une liste toute cochée se décoche, le reste choisi reste');
});

test('la fenêtre des catégories propose « Tout sélectionner » et s’élargit', () => {
    const dialog = read('resources/js/Components/Bonus/BonusCategoryDialog.vue');

    assert.match(dialog, /<Checkbox id="bonus-staff-all" :model-value="allShownState" @update:model-value="toggleAllShown" \/>/);
    assert.match(dialog, /content-class="max-w-5xl"/);
});
