import test from 'node:test';
import assert from 'node:assert/strict';
import { endingLabel, formatDays, formatMinutes, formatPeriod, initials } from '../../resources/js/utilities/hr.js';

test('les durées de congé se lisent en jours, jamais en décimales brutes', () => {
    assert.equal(formatDays('8.00'), '8 jours');
    assert.equal(formatDays('1.00'), '1 jour');
    assert.equal(formatDays('0.50'), '0,5 jour');
    assert.equal(formatDays('22.5'), '22,5 jours');
    assert.equal(formatDays(null), '—');
    assert.equal(formatDays(''), '—');
});

test('une période se lit en toutes lettres, sans « au sans date de fin »', () => {
    assert.equal(formatPeriod('2024-01-15', '2026-10-15'), 'Du 15/01/2024 au 15/10/2026');
    assert.equal(formatPeriod('2019-02-01', null), 'Depuis le 01/02/2019');
    assert.equal(formatPeriod(null, null), '—');
});

test('l’échéance d’un contrat est dite en jours', () => {
    assert.equal(endingLabel(0), 'Finit aujourd’hui');
    assert.equal(endingLabel(1), 'Finit demain');
    assert.equal(endingLabel(21), 'Finit dans 21 jours');
    assert.equal(endingLabel(null), null);
});

test('une durée de présence se lit en heures et minutes', () => {
    assert.equal(formatMinutes(480), '8 h');
    assert.equal(formatMinutes(465), '7 h 45');
    assert.equal(formatMinutes(25), '25 min');
    assert.equal(formatMinutes(null), '—');
});

test('les initiales d’une personne servent d’avatar', () => {
    assert.equal(initials('RAKOTOBE Hanitra'), 'RH');
    assert.equal(initials('RABE'), 'R');
    assert.equal(initials(''), '?');
});
