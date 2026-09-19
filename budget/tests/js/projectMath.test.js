/**
 * How project budgets read on screen (#391), and the two helpers they share
 * with the rest of the app.
 */

import { describe, it, expect } from 'vitest';
import { toCents } from '../../src/utils/money.js';
import { expenseProgressStatus } from '../../src/utils/budgetProgress.js';
import { groupProjects, progressFor, unallocated, subcategoriesOf, ownExpenseTree } from '../../src/modules/projects/projectMath.js';

const TREE = [
    { id: 1, name: 'Renovation', type: 'expense', children: [
        { id: 4, name: 'Bathroom', type: 'expense', children: [] },
        { id: 2, name: 'Kitchen', type: 'expense', children: [
            { id: 3, name: 'Appliances', type: 'expense', children: [] },
        ] },
        { id: 8, name: 'Skip hire', type: 'expense', excludedFromReports: true, children: [
            { id: 9, name: 'Skip permit', type: 'expense', children: [] },
        ] },
    ] },
    { id: 6, name: 'Salary', type: 'income', children: [] },
    { id: 11, name: 'Their category', type: 'expense', _shared: true, children: [] },
];

describe('toCents', () => {
    it('reads money as whole pennies and anything else as NaN', () => {
        expect(toCents('12.34')).toBe(1234);
        expect(toCents(0.1 + 0.2)).toBe(30);
        expect(toCents('')).toBeNaN();
        expect(toCents(null)).toBeNaN();
    });
});

describe('expenseProgressStatus', () => {
    it('uses the Budget page bands', () => {
        expect(expenseProgressStatus(59.9)).toBe('good');
        expect(expenseProgressStatus(60)).toBe('warning');
        expect(expenseProgressStatus(80)).toBe('danger');
        expect(expenseProgressStatus(100)).toBe('over');
    });
});

describe('groupProjects', () => {
    it('puts running and upcoming projects first by start date, finished ones apart', () => {
        const { open, finished } = groupProjects([
            { name: 'Wedding', status: 'finished', startDate: '2025-01-01' },
            { name: 'Garden', status: 'upcoming', startDate: '2026-10-01' },
            { name: 'Renovation', status: 'active', startDate: '2026-03-01' },
            { name: 'Holiday', status: 'finished', startDate: '2025-06-01' },
        ]);
        expect(open.map(p => p.name)).toEqual(['Renovation', 'Garden']);
        expect(finished.map(p => p.name)).toEqual(['Holiday', 'Wedding']);
    });
});

describe('progressFor', () => {
    it('clamps the bar but keeps the real percentage for the label', () => {
        expect(progressFor(650, 900)).toEqual({ width: (650 / 900) * 100, status: 'warning', percent: 72 });
        expect(progressFor(1000, 900)).toEqual({ width: 100, status: 'over', percent: 111 });
        expect(progressFor(-50, 900)).toEqual({ width: 0, status: 'good', percent: -6 });
        expect(progressFor(10, 0)).toEqual({ width: 0, status: 'good', percent: 0 });
    });
});

describe('unallocated', () => {
    it('takes the amounts off the total in pennies, ignoring blanks', () => {
        expect(unallocated('900', ['400', '', '150.50'])).toEqual({ cents: 34950, over: false, valid: true });
    });

    it('says when the amounts go over the total', () => {
        expect(unallocated('900', ['600', '300.01'])).toEqual({ cents: -1, over: true, valid: true });
    });

    it('cannot work it out without a total', () => {
        expect(unallocated('', ['100'])).toEqual({ cents: -10000, over: true, valid: false });
    });
});

describe('subcategoriesOf', () => {
    it('lists the branch in tree order, leaving out excluded categories and what is under them', () => {
        expect(subcategoriesOf(TREE, 1)).toEqual([
            { id: 4, name: 'Bathroom', depth: 1 },
            { id: 2, name: 'Kitchen', depth: 1 },
            { id: 3, name: 'Appliances', depth: 2 },
        ]);
    });

    it('is empty for an unknown category or one with no subcategories', () => {
        expect(subcategoriesOf(TREE, 99)).toEqual([]);
        expect(subcategoriesOf(TREE, 6)).toEqual([]);
    });
});

describe('ownExpenseTree', () => {
    it('keeps only your own expense categories', () => {
        const ids = [];
        const walk = nodes => nodes.forEach(n => { ids.push(n.id); walk(n.children || []); });
        walk(ownExpenseTree(TREE));
        expect(ids).toEqual([1, 4, 2, 3, 8, 9]);
    });
});
