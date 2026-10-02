import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
    PRICE_COMPARISON_DEFAULTS, gapLabel, orderQuotes, priceTier, resolvePriceComparison, tierStyles,
} from '../../resources/js/utilities/priceComparison.js';
import { AI_BATCH_STATES, nextBatch, runSummary, startRun } from '../../resources/js/utilities/aiMatchingRun.js';

const quotes = [{ price: 36000 }, { price: 30000 }, { price: 33000 }, { price: null }];
const money = (value) => `${value} Ar`;

describe('price comparison (ADR-242)', () => {
    it('ranks the cheapest, the dearest and what lies between', () => {
        assert.equal(priceTier(quotes, quotes[1]).tier, 'best');
        assert.equal(priceTier(quotes, quotes[0]).tier, 'worst');
        assert.equal(priceTier(quotes, quotes[2]).tier, 'middle');
        assert.equal(priceTier(quotes, quotes[3]).tier, null);
    });

    it('colours nothing when there is nothing to compare', () => {
        assert.equal(priceTier([{ price: 10 }], { price: 10 }).tier, null);
        assert.equal(priceTier([{ price: 10 }, { price: 10 }], { price: 10 }).tier, null);
    });

    it('keeps the dearest as middle under the gap threshold', () => {
        const settings = resolvePriceComparison({ min_gap_percent: 50 });
        assert.equal(priceTier(quotes, quotes[0], settings).tier, 'middle');
    });

    it('writes the gap as asked', () => {
        const info = priceTier(quotes, quotes[0]);
        assert.equal(gapLabel(info, PRICE_COMPARISON_DEFAULTS, money), '+20 %');
        assert.equal(gapLabel(info, { ...PRICE_COMPARISON_DEFAULTS, show_gap: 'AMOUNT' }, money), '+6000 Ar');
        assert.equal(gapLabel(info, { ...PRICE_COMPARISON_DEFAULTS, show_gap: 'NONE' }, money), '');
    });

    it('uses the account colours and style', () => {
        const settings = resolvePriceComparison({ best_color: '#112233', style: 'TEXT' });
        assert.deepEqual(tierStyles('best', settings), { card: {}, price: { color: '#112233', fontWeight: 700 } });
        assert.deepEqual(tierStyles('middle', { ...settings, color_middle: false }), { card: {}, price: {} });
    });

    it('sorts the cheapest first, unpriced last', () => {
        assert.deepEqual(orderQuotes(quotes, PRICE_COMPARISON_DEFAULTS).map((quote) => quote.price), [30000, 33000, 36000, null]);
        assert.equal(orderQuotes(quotes, { sort_by_price: false }), quotes);
    });
});

describe('AI matching run (ADR-242)', () => {
    it('follows batches to the end', () => {
        const run = startRun({ run: 'r', batches: [{ index: 0, lines: 10 }, { index: 1, lines: 8 }] });
        assert.equal(nextBatch(run).index, 0);
        run.batches[0].state = AI_BATCH_STATES.DONE;
        run.batches[0].proposed = 3;
        run.batches[1].state = AI_BATCH_STATES.FAILED;
        const summary = runSummary(run);
        assert.deepEqual({ total: summary.total, done: summary.done, failed: summary.failed, proposed: summary.proposed, percent: summary.percent, over: summary.over }, { total: 2, done: 1, failed: 1, proposed: 3, percent: 100, over: true });
        assert.equal(nextBatch(run), null);
    });

    it('stops when asked', () => {
        const run = startRun({ run: 'r', batches: [{ index: 0, lines: 10 }] });
        run.stopped = true;
        assert.equal(nextBatch(run), null);
    });
});
