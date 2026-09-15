import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('resources/js/Pages/Patients/Index.vue', 'utf8');

/**
 * Le libellé de situation du répertoire patients, exécuté pour de vrai.
 *
 * « Passage en cours » couvrait aussi le passage dont Médecine avait déjà
 * conclu : un médecin venant de clôturer relisait ici « en cours » et croyait
 * à une contradiction entre les deux écrans.
 */
const presenceState = (() => {
    const OPEN = 'const presenceState = (patient) => {';
    const start = page.indexOf(OPEN);
    assert.notEqual(start, -1, 'presenceState a disparu');

    const body = page.slice(start + OPEN.length, page.indexOf('\n};', start));

    // eslint-disable-next-line no-new-func
    return new Function('patient', body);
})();

const patient = (overrides = {}) => ({
    active_emergency_episodes_count: 0,
    open_episodes_count: 0,
    settlement_episodes_count: 0,
    ...overrides,
});

test('un passage encore en soins reste « Passage en cours »', () => {
    assert.equal(presenceState(patient({ open_episodes_count: 1 })).label, 'Passage en cours');
});

test('un passage dont Médecine a conclu attend le règlement', () => {
    const state = presenceState(patient({ open_episodes_count: 1, settlement_episodes_count: 1 }));

    assert.equal(state.label, 'En attente de règlement');
});

/** Un patient peut avoir deux passages : celui en soins prime. */
test('un passage en soins l’emporte sur un autre à régler', () => {
    const state = presenceState(patient({ open_episodes_count: 2, settlement_episodes_count: 1 }));

    assert.equal(state.label, 'Passage en cours');
});

test('l’urgence prime sur tout le reste', () => {
    const state = presenceState(patient({
        active_emergency_episodes_count: 1,
        open_episodes_count: 1,
        settlement_episodes_count: 1,
    }));

    assert.equal(state.label, 'Urgence');
});

test('aucun passage ouvert reste l’état par défaut', () => {
    assert.equal(presenceState(patient()).label, 'Aucun passage ouvert');
});
