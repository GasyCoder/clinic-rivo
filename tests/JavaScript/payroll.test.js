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
