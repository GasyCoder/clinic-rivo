import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { presenceCountsFromEpisodes, presenceState } from '../../resources/js/utilities/episodePresence.js';

/**
 * Le libellé de situation d'un patient, exécuté pour de vrai.
 *
 * « Passage en cours » couvrait aussi le passage dont Médecine avait déjà
 * conclu : un médecin venant de clôturer relisait ici « en cours » et croyait
 * à une contradiction entre les deux écrans.
 *
 * Extraite dans `utilities/episodePresence.js` (2026-09-19) : le répertoire
 * des patients (une ligne par patient) et le dossier d'un patient (son propre
 * en-tête) doivent employer les mêmes mots et couleurs pour le même fait — un
 * dossier ouvert depuis la liste ne doit jamais paraître contredire ce que la
 * ligne venait d'annoncer.
 */
const counts = (overrides = {}) => ({
    activeEmergencyCount: 0,
    openCount: 0,
    settlementCount: 0,
    ...overrides,
});

test('un passage encore en soins reste « Passage en cours »', () => {
    assert.equal(presenceState(counts({ openCount: 1 })).label, 'Passage en cours');
});

test('un passage dont Médecine a conclu attend le règlement', () => {
    const state = presenceState(counts({ openCount: 1, settlementCount: 1 }));

    assert.equal(state.label, 'En attente de règlement');
});

/** Un patient peut avoir deux passages : celui en soins prime. */
test('un passage en soins l’emporte sur un autre à régler', () => {
    const state = presenceState(counts({ openCount: 2, settlementCount: 1 }));

    assert.equal(state.label, 'Passage en cours');
});

test('l’urgence prime sur tout le reste', () => {
    const state = presenceState(counts({
        activeEmergencyCount: 1,
        openCount: 1,
        settlementCount: 1,
    }));

    assert.equal(state.label, 'Urgence');
});

test('aucun passage ouvert reste l’état par défaut', () => {
    assert.equal(presenceState(counts()).label, 'Aucun passage ouvert');
});

/** Les compteurs se dérivent aussi directement des passages d'UN patient (dossier). */
test('les compteurs se recalculent depuis la liste complète des passages', () => {
    const episodes = [
        { status: 'OPEN', administrative_status: 'IN_CARE', priority: 'NORMAL' },
        { status: 'OPEN', administrative_status: 'PENDING_SETTLEMENT', priority: 'NORMAL' },
        { status: 'CLOSED', administrative_status: 'DISCHARGED_PAID', priority: 'NORMAL' },
    ];

    assert.deepEqual(presenceCountsFromEpisodes(episodes), {
        activeEmergencyCount: 0,
        openCount: 2,
        settlementCount: 1,
    });
    assert.equal(presenceState(presenceCountsFromEpisodes(episodes)).label, 'Passage en cours');
});

/**
 * Le répertoire (Patients/Index.vue) et le dossier (Patients/Show.vue)
 * importent la même fonction : deux copies auraient fini par diverger,
 * exactement le défaut signalé le 2026-09-19.
 */
test('le répertoire et le dossier patient partagent la même fonction', () => {
    for (const file of ['resources/js/Pages/Patients/Index.vue', 'resources/js/Pages/Patients/Show.vue']) {
        const source = fs.readFileSync(file, 'utf8');
        assert.match(source, /from '@\/utilities\/episodePresence'/, `${file} n’importe pas la fonction partagée`);
    }

    // Ni l'un ni l'autre ne garde sa propre définition parallèle.
    for (const file of ['resources/js/Pages/Patients/Index.vue', 'resources/js/Pages/Patients/Show.vue']) {
        const source = fs.readFileSync(file, 'utf8');
        assert.doesNotMatch(source, /label: 'Passage en cours'/, `${file} recopie encore le libellé au lieu de l’importer`);
    }
});
