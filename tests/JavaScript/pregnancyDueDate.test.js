import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { dueDateCountdown, DUE_DATE_TONES } from '../../resources/js/utilities/pregnancyDueDate.js';

const today = new Date(2026, 8, 27, 1, 30); // 27/09/2026 01:30, heure du poste

test('la DPA dit dans combien de temps elle tombe', () => {
    assert.deepEqual(dueDateCountdown('2027-04-21', 'ONGOING', today), { days: 206, tone: 'ahead', label: 'Dans 29 sem. 3 j' });
    assert.equal(dueDateCountdown('2026-10-11', 'ONGOING', today).label, 'Dans 2 sem.');
    assert.deepEqual(dueDateCountdown('2026-10-05', 'ONGOING', today), { days: 8, tone: 'soon', label: 'Dans 8 j' });
    assert.deepEqual(dueDateCountdown('2026-09-27', 'ONGOING', today), { days: 0, tone: 'due', label: 'Aujourd’hui' });
    assert.deepEqual(dueDateCountdown('2026-09-20', 'ONGOING', today), { days: -7, tone: 'past', label: 'Dépassée de 7 j' });
});

/** Entre minuit et 3 h, une date lue en UTC reculerait d'un jour à Madagascar. */
test('la date est lue en heure locale, jamais en UTC', () => {
    assert.equal(dueDateCountdown('2026-09-28', 'ONGOING', new Date(2026, 8, 27, 0, 5)).days, 1);
});

test('pas de délai sans DPA, ni pour une grossesse qui n’est plus en cours', () => {
    assert.equal(dueDateCountdown(null, 'ONGOING', today), null);
    assert.equal(dueDateCountdown('2027-04-21', 'DELIVERED', today), null);
    assert.equal(dueDateCountdown('2027-04-21', 'ENDED', today), null);
    for (const tone of ['ahead', 'soon', 'due', 'past']) assert.ok(DUE_DATE_TONES[tone]);
});

test('la DPA est mise en valeur dans l’en-tête du parcours et la carte de grossesse', () => {
    for (const file of ['MaternityEncounterHeader', 'PregnancySummaryCard']) {
        const source = fs.readFileSync(`resources/js/Components/Maternity/${file}.vue`, 'utf8');
        assert.match(source, /bg-rose-50/, `${file} : case DPA teintée`);
        assert.match(source, /text-base font-bold tabular-nums text-rose-700/, `${file} : date en gras`);
        assert.match(source, /DUE_DATE_TONES\[dueDate\.tone\]/, `${file} : délai coloré`);
        assert.match(source, /date prévue d’accouchement/, `${file} : nom complet pour les lecteurs d’écran`);
    }
});
