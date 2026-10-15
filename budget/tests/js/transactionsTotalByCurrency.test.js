/**
 * The transactions list's Total across currencies.
 *
 * Rows of +€117.50, -£100, -£54.32 and +£2,500 showed "Total: £2,463.18":
 * the euro amount was added as if it were pounds. The total now has one
 * figure per currency.
 */

import { describe, it, expect } from 'vitest';
import { transactionTotalsByCurrency } from '../../src/utils/helpers.js';

const row = (amount, type, accountCurrency, extra = {}) => ({ amount, type, accountCurrency, ...extra });

describe('transactionTotalsByCurrency', () => {
    it('keeps each currency apart, the most common first', () => {
        const totals = transactionTotalsByCurrency([
            row(117.5, 'credit', 'EUR'),
            row(100, 'debit', 'GBP'),
            row(54.32, 'debit', 'GBP'),
            row(2500, 'credit', 'GBP'),
        ], 'GBP');

        expect(totals.map(t => t.currency)).toEqual(['GBP', 'EUR']);
        expect(totals[0].total).toBeCloseTo(2345.68, 2);
        expect(totals[1].total).toBeCloseTo(117.5, 2);
    });

    it('gives one figure when every row is in one currency', () => {
        const totals = transactionTotalsByCurrency([row(10, 'debit', 'GBP'), row(4, 'credit', null)], 'GBP');
        expect(totals).toEqual([{ currency: 'GBP', total: -6 }]);
    });

    it('leaves scheduled rows out and counts a split part for its share', () => {
        const totals = transactionTotalsByCurrency([
            row(700, 'debit', 'GBP', { status: 'scheduled' }),
            row(90, 'debit', 'GBP', { isSplit: true, matchedSplitAmount: 50 }),
        ], 'GBP');
        expect(totals).toEqual([{ currency: 'GBP', total: -50 }]);
    });

    it('is empty for an empty list', () => {
        expect(transactionTotalsByCurrency([], 'GBP')).toEqual([]);
    });
});
