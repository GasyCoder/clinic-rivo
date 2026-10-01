import { test } from 'node:test';
import assert from 'node:assert/strict';
import { doctorTotals, entriesPayload, grandTotals, lineProblem, parseAmount } from '../../resources/js/utilities/advantageEntries.js';

/** ADR-227 — la saisie des avantages : montant à la française, totaux en direct, rien d'incomplet ne part. */
test('parseAmount reads French amounts', () => {
    assert.equal(parseAmount('150 000,50'), 150000.5);
    assert.equal(parseAmount('5000'), 5000);
    assert.equal(parseAmount(''), null);
    assert.equal(parseAmount('12a'), null);
});

test('lineProblem names what is missing', () => {
    assert.equal(lineProblem({ amount: '', reason: 'ECHO', period: '2026-09' }), 'Montant obligatoire');
    assert.equal(lineProblem({ amount: '0', reason: 'ECHO', period: '2026-09' }), 'Le montant doit être supérieur à zéro');
    assert.equal(lineProblem({ amount: '1000', reason: ' ', period: '2026-09' }), 'Motif obligatoire');
    assert.equal(lineProblem({ amount: '1000', reason: 'ECHO', period: '' }), 'Mois de paie obligatoire');
    assert.equal(lineProblem({ amount: '1000', reason: 'ECHO', period: '2026-09' }), null);
});

test('totals count filled lines per doctor and overall', () => {
    const rows = {
        a: [{ amount: '1 000', reason: 'ECHO' }, { amount: '', reason: '' }, { amount: '500', reason: 'ECG' }],
        b: [{ amount: '', reason: '' }],
    };
    assert.deepEqual(doctorTotals(rows.a), { count: 2, total: 1500 });
    assert.deepEqual(grandTotals(rows), { doctors: 1, count: 2, total: 1500 });
});

test('entriesPayload ignores blank lines and refuses an incomplete one', () => {
    const period = '2026-09';
    assert.deepEqual(entriesPayload({ a: [{ amount: '1 000,5', reason: ' ECHO ', period }, { amount: '', reason: '', period }] }), [
        { employee_uuid: 'a', amount: '1000.5', reason: 'ECHO', period },
    ]);
    assert.equal(entriesPayload({ a: [{ amount: '1000', reason: '', period }] }), null);
});

test('an article prefills its line with a readable amount', async () => {
    const { amountInput, lineFromArticle, findArticle } = await import('../../resources/js/utilities/advantageEntries.js');
    assert.equal(amountInput('50000.00'), '50 000');
    assert.equal(amountInput('1500.50'), '1 500,50');
    assert.equal(amountInput(null), '');
    assert.deepEqual(lineFromArticle({ label: 'ECHO', amount: '50000.00' }), { reason: 'ECHO', amount: '50 000' });
    assert.deepEqual(lineFromArticle({ label: 'Garde', amount: null }), { reason: 'Garde', amount: '' });
    const articles = [{ label: 'Écho', amount: '50000.00' }, { label: 'AUTO CHIR', amount: '70000.00' }];
    assert.equal(findArticle(articles, 'echo')?.amount, '50000.00');
    assert.equal(findArticle(articles, 'auto chir')?.label, 'AUTO CHIR');
    assert.equal(findArticle(articles, 'CHOL'), null);
});
