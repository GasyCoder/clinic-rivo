import test from 'node:test';
import assert from 'node:assert/strict';
import { formatMobileNumber, nationalDigits, operatorFromNumber } from '../../resources/js/utilities/mobileMoney.js';

test('the Malagasy prefix proposes the Mobile Money operator', () => {
    assert.equal(operatorFromNumber('034 12 345 67'), 'YAS');
    assert.equal(operatorFromNumber('0381234567'), 'YAS');
    assert.equal(operatorFromNumber('032 12 345 67'), 'ORANGE');
    assert.equal(operatorFromNumber('0371234567'), 'ORANGE');
    assert.equal(operatorFromNumber('+261 33 12 345 67'), 'AIRTEL');
    assert.equal(operatorFromNumber('039 12 345 67'), null, 'no Mobile Money operator is guessed');
    assert.equal(operatorFromNumber(''), null);
});

test('a number is written the usual way, and left alone when it is not a local mobile number', () => {
    assert.equal(nationalDigits('+261 34 12 345 67'), '0341234567');
    assert.equal(formatMobileNumber('0341234567'), '034 12 345 67');
    assert.equal(formatMobileNumber('12 34'), '12 34');
});
