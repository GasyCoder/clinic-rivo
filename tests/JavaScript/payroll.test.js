import { test } from 'node:test';
import assert from 'node:assert/strict';
import { bracketsFrom, cleanNumber, inputOf, payrollFormFrom, payrollPayload } from '../../resources/js/utilities/payroll.js';

/** ADR-233 — paramètres de paie : formulaire ↔ envoi, tranches IRSA. */
test('numbers are read the French way and written plainly', () => {
    assert.equal(cleanNumber('350 000'), '350000');
    assert.equal(cleanNumber('1,5'), '1.5');
    assert.equal(cleanNumber(''), null);
    assert.equal(inputOf('350000.00'), '350000');
    assert.equal(inputOf('1.50'), '1.5');
    assert.equal(inputOf(null), '');
});

test('the last bracket always leaves without a ceiling', () => {
    const form = payrollFormFrom({ irsa_brackets: [{ up_to: '350000.00', rate: '0.00' }, { up_to: null, rate: '20.00' }] });
    form.irsa_brackets[1].up_to = '999';
    const payload = payrollPayload(form);
    assert.deepEqual(payload.irsa_brackets, [{ up_to: '350000', rate: '0' }, { up_to: null, rate: '20' }]);
    assert.equal(payload.cnaps_ceiling, null);
});

test('each bracket starts where the previous one ends', () => {
    assert.deepEqual(bracketsFrom([{ up_to: '350 000' }, { up_to: '400000' }, { up_to: '' }]).map((row) => row.from), [0, 350000, 400000]);
});

import { filterPayrollRows, payrollQuery, payrollStatusOf, payrollViewCounts, serviceOptions } from '../../resources/js/utilities/payrollBoard.js';

/** ADR-233 — l'écran « Paie du mois » : vues, recherche et filtres sur ce que le serveur sert. */
const row = (overrides) => ({
    uuid: overrides.name, name: 'X', employee_number: '', job_title: '', department: null, payable: false, payment: null,
    payment_mode: { mode: 'BANK' }, lines: [], total: '0', ...overrides,
});
const rows = [
    row({ name: 'RAKOTO Vola', employee_number: 'EMP-0001', department: 'Laboratoire', payable: true, total: '500000.00', lines: [{ kind: 'DEBT', amount: '-50000.00' }] }),
    row({ name: 'RABE Hery', employee_number: 'EMP-0002', department: 'Médecine', payment: { uuid: 'p' }, payment_mode: { mode: 'CASH' }, total: '700000.00' }),
    row({ name: 'Ándry Sóa', employee_number: 'EMP-0003', department: 'Médecine', payable: true, payment_mode: { mode: null }, total: '300000.00' }),
];

test('a row is paid, to pay, or not payable', () => {
    assert.deepEqual(rows.map(payrollStatusOf), ['to_pay', 'paid', 'to_pay']);
    assert.equal(payrollStatusOf(row({ name: 'Z' })), 'waiting');
});

test('views, search and filters only sort what is served', () => {
    assert.deepEqual(filterPayrollRows(rows, { vue: 'a-payer' }).map((r) => r.name), ['RAKOTO Vola', 'Ándry Sóa']);
    assert.deepEqual(filterPayrollRows(rows, { vue: 'payees' }).map((r) => r.name), ['RABE Hery']);
    assert.deepEqual(filterPayrollRows(rows, { q: 'andry' }).map((r) => r.name), ['Ándry Sóa']);
    assert.deepEqual(filterPayrollRows(rows, { q: '0002 rabe' }).map((r) => r.name), ['RABE Hery']);
    assert.deepEqual(filterPayrollRows(rows, { service: 'Médecine', mode: 'NONE' }).map((r) => r.name), ['Ándry Sóa']);
    assert.deepEqual(filterPayrollRows(rows, { dettes: true }).map((r) => r.name), ['RAKOTO Vola']);
});

test('each tab counts what a click would show, other filters applied', () => {
    assert.deepEqual(payrollViewCounts(rows, { service: 'Médecine', vue: 'payees' }), { toutes: 2, 'a-payer': 1, payees: 1 });
    assert.deepEqual(serviceOptions(rows).map((o) => o.value), ['Laboratoire', 'Médecine']);
});

test('the address keeps only what differs from the default', () => {
    assert.equal(payrollQuery('2026-10', { vue: 'toutes', q: ' ', dettes: false }), 'mois=2026-10');
    assert.equal(payrollQuery('2026-10', { vue: 'a-payer', q: 'rabe', mode: 'CASH', dettes: true }), 'mois=2026-10&vue=a-payer&q=rabe&mode=CASH&dettes=1');
});

test('a payment mode that cannot be paid is flagged, and « À compléter » finds it', async () => {
    const { filterPayrollRows: filter, paymentIncomplete } = await import('../../resources/js/utilities/payrollBoard.js');
    const bankWithout = { name: 'A', payment_mode: { mode: 'BANK', incomplete: true }, lines: [] };
    const cash = { name: 'B', payment_mode: { mode: 'CASH', incomplete: false }, lines: [] };
    const none = { name: 'C', payment_mode: { mode: null }, lines: [] };
    assert.equal(paymentIncomplete(bankWithout), true);
    assert.equal(paymentIncomplete(cash), false);
    assert.equal(paymentIncomplete(none), true);
    assert.deepEqual(filter([bankWithout, cash, none], { mode: 'NONE' }).map((r) => r.name), ['A', 'C']);
});
