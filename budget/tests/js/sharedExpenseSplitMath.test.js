/**
 * Splitting one transaction between several people (#391).
 *
 * Everything is worked in whole pennies so the parts always add back up to
 * the total, and the penny that will not divide evenly lands somewhere
 * predictable: with you when you are in the split, otherwise with the first
 * people listed.
 */

import { describe, it, expect } from 'vitest';
import { computeSplit, toCents } from '../../src/modules/shared-expenses/splitMath.js';

const people = (...values) => values.map((value, i) => ({ id: i + 1, value }));
const cents = (result) => result.shares.map(s => s.cents);

describe('toCents', () => {
    it('reads money as whole pennies', () => {
        expect(toCents('12.34')).toBe(1234);
        expect(toCents(0.1 + 0.2)).toBe(30);
        expect(toCents('-5')).toBe(-500);
    });

    it('gives NaN for anything that is not a number', () => {
        expect(toCents('')).toBeNaN();
        expect(toCents('abc')).toBeNaN();
        expect(toCents(null)).toBeNaN();
    });
});

describe('equal split', () => {
    it('keeps the spare penny with you', () => {
        const result = computeSplit({ totalCents: 10000, method: 'equal', includeMe: true, people: people(null, null) });

        expect(result.error).toBeNull();
        expect(cents(result)).toEqual([3333, 3333]);
        expect(result.mineCents).toBe(3334);
    });

    it('divides only between the others when you are left out', () => {
        const result = computeSplit({ totalCents: 10000, method: 'equal', includeMe: false, people: people(null, null, null) });

        expect(cents(result)).toEqual([3334, 3333, 3333]);
        expect(result.mineCents).toBe(0);
    });

    it('needs at least one other person', () => {
        const result = computeSplit({ totalCents: 9000, method: 'equal', includeMe: true, people: [] });

        expect(result.error).toEqual({ code: 'nobody' });
    });

    it('refuses a total too small to give everyone a penny', () => {
        const result = computeSplit({ totalCents: 2, method: 'equal', includeMe: true, people: people(null, null) });

        expect(result.error).toEqual({ code: 'too-small' });
    });
});

describe('percentage split', () => {
    it('shares by each person\'s percentage and gives you the rest', () => {
        const result = computeSplit({
            totalCents: 90000, method: 'percent', includeMe: true, myValue: '50', people: people('30', '20'),
        });

        expect(result.error).toBeNull();
        expect(cents(result)).toEqual([27000, 18000]);
        expect(result.mineCents).toBe(45000);
    });

    it('keeps the rounding with you', () => {
        const result = computeSplit({
            totalCents: 10000, method: 'percent', includeMe: true, myValue: '33.34', people: people('33.33', '33.33'),
        });

        expect(cents(result)).toEqual([3333, 3333]);
        expect(result.mineCents).toBe(3334);
    });

    it('accepts thirds typed to two places', () => {
        const result = computeSplit({
            totalCents: 9000, method: 'percent', includeMe: true, myValue: '33.33', people: people('33.33', '33.33'),
        });

        expect(result.error).toBeNull();
        expect(cents(result).concat(result.mineCents).reduce((a, b) => a + b)).toBe(9000);
    });

    it('hands the rounding to the others in order when you are left out', () => {
        const result = computeSplit({
            totalCents: 10000, method: 'percent', includeMe: false, people: people('33.33', '33.33', '33.34'),
        });

        expect(cents(result).reduce((a, b) => a + b)).toBe(10000);
        expect(result.mineCents).toBe(0);
    });

    it('says how far off 100% the percentages are', () => {
        const result = computeSplit({
            totalCents: 9000, method: 'percent', includeMe: true, myValue: '50', people: people('30', '10'),
        });

        expect(result.error).toEqual({ code: 'percent-total', percent: 90 });
    });

    it('ignores your percentage when you are left out', () => {
        const result = computeSplit({
            totalCents: 9000, method: 'percent', includeMe: false, myValue: '50', people: people('60', '40'),
        });

        expect(result.error).toBeNull();
        expect(cents(result)).toEqual([5400, 3600]);
    });

    it('needs a percentage for everyone in the split', () => {
        const result = computeSplit({
            totalCents: 9000, method: 'percent', includeMe: true, myValue: '50', people: people('50', ''),
        });

        expect(result.error).toEqual({ code: 'missing', id: 2 });
    });
});

describe('split by amount', () => {
    it('leaves you whatever the others do not owe', () => {
        const result = computeSplit({ totalCents: 9000, method: 'amount', people: people('25', '40.50') });

        expect(result.error).toBeNull();
        expect(cents(result)).toEqual([2500, 4050]);
        expect(result.mineCents).toBe(2450);
    });

    it('lets the others owe the whole transaction', () => {
        const result = computeSplit({ totalCents: 9000, method: 'amount', people: people('45', '45') });

        expect(result.error).toBeNull();
        expect(result.mineCents).toBe(0);
    });

    it('refuses amounts adding up to more than the transaction', () => {
        const result = computeSplit({ totalCents: 9000, method: 'amount', people: people('50', '41') });

        expect(result.error).toEqual({ code: 'over-total', overCents: 100 });
    });

    it('needs an amount above zero for everyone in the split', () => {
        expect(computeSplit({ totalCents: 9000, method: 'amount', people: people('50', '') }).error)
            .toEqual({ code: 'missing', id: 2 });
        expect(computeSplit({ totalCents: 9000, method: 'amount', people: people('0', '10') }).error)
            .toEqual({ code: 'missing', id: 1 });
    });
});
