import test from 'node:test';
import assert from 'node:assert/strict';
import {
    addDays, durationMinutes, endOnSameOrNextDay, formatDuration, groupByDay, monthGrid,
    rosterRows, shiftPeriod, shiftTime, shortName, startOfWeek, weekDays,
} from '../../resources/js/utilities/planningCalendar.js';

const shift = (uuid, name, startsAt, endsAt, department = 'Médecine') => ({
    uuid, starts_at: startsAt, ends_at: endsAt, department,
    employee: { uuid: `e-${name}`, name, department },
});

test('la semaine va du lundi au dimanche, même autour d’un changement de mois', () => {
    assert.equal(startOfWeek('2026-09-25'), '2026-09-21');
    assert.equal(startOfWeek('2026-09-21'), '2026-09-21');
    assert.equal(startOfWeek('2026-09-27'), '2026-09-21');
    assert.deepEqual(weekDays('2026-10-01'), ['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04']);
    assert.equal(addDays('2026-12-31', 1), '2027-01-01');
});

test('la grille d’un mois est faite de semaines complètes, jours hors mois signalés', () => {
    const grid = monthGrid('2026-09-15');
    assert.equal(grid[0][0].date, '2026-08-31');
    assert.equal(grid[0][0].inMonth, false);
    assert.equal(grid.at(-1).at(-1).date, '2026-10-04');
    assert.ok(grid.every((week) => week.length === 7));
    assert.equal(grid.flat().filter((day) => day.inMonth).length, 30);
    // Un mois qui commence un lundi ne gagne pas une semaine vide devant.
    assert.equal(monthGrid('2026-06-10')[0][0].date, '2026-06-01');
});

test('les heures se lisent à l’heure de la clinique, pas du navigateur', () => {
    assert.equal(shiftTime('2026-09-25T19:00:00+03:00'), '19:00');
    assert.equal(durationMinutes(shift('a', 'X', '2026-09-25T19:00:00+03:00', '2026-09-26T07:00:00+03:00')), 720);
    assert.equal(formatDuration(720), '12 h');
    assert.equal(formatDuration(510), '8 h 30');
});

test('jour, nuit ou 24 h se lisent sur les heures', () => {
    assert.equal(shiftPeriod(shift('a', 'X', '2026-09-25T07:00:00+03:00', '2026-09-25T15:00:00+03:00')), 'DAY');
    assert.equal(shiftPeriod(shift('b', 'X', '2026-09-25T19:00:00+03:00', '2026-09-26T07:00:00+03:00')), 'NIGHT');
    assert.equal(shiftPeriod(shift('c', 'X', '2026-09-25T07:00:00+03:00', '2026-09-26T07:00:00+03:00')), 'LONG');
    // Finir à minuit pile n'est pas une nuit.
    assert.equal(shiftPeriod(shift('d', 'X', '2026-09-25T14:00:00+03:00', '2026-09-26T00:00:00+03:00')), 'DAY');
});

test('le tableau de garde a une ligne par personne, rangée par département puis nom', () => {
    const days = weekDays('2026-09-25');
    const rows = rosterRows([
        shift('1', 'RAZAFY Mamy', '2026-09-24T07:00:00+03:00', '2026-09-24T15:00:00+03:00', 'Pharmacie'),
        shift('2', 'ANDRIAMASY Tojo', '2026-09-25T19:00:00+03:00', '2026-09-26T07:00:00+03:00'),
        shift('3', 'ANDRIAMASY Tojo', '2026-09-22T07:00:00+03:00', '2026-09-22T15:00:00+03:00'),
        shift('4', 'HORS SEMAINE', '2026-10-05T07:00:00+03:00', '2026-10-05T15:00:00+03:00'),
    ], days);

    assert.deepEqual(rows.map((row) => row.employee.name), ['ANDRIAMASY Tojo', 'RAZAFY Mamy']);
    assert.equal(rows[0].total, 2);
    assert.equal(rows[0].cells['2026-09-25'][0].uuid, '2');
    assert.equal(rows[0].cells['2026-09-22'][0].uuid, '3');
    assert.equal(groupByDay(rows[0].cells['2026-09-22']).size, 1);
});

test('une fin qui n’est pas après le début tombe le lendemain', () => {
    assert.equal(endOnSameOrNextDay('2026-09-25', '07:00', '15:00'), '2026-09-25T15:00');
    assert.equal(endOnSameOrNextDay('2026-09-25', '19:00', '07:00'), '2026-09-26T07:00');
    assert.equal(endOnSameOrNextDay('2026-09-25', '07:00', '07:00'), '2026-09-26T07:00');
    assert.equal(endOnSameOrNextDay('2026-09-25', '', '07:00'), null);
    assert.equal(shortName('RAKOTOBE Hanitra'), 'Rakotobe H.');
});
