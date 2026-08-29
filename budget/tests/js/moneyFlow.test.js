/**
 * Coverage for the Money Flow (Sankey) report's pure data transform (#366).
 *
 * buildMoneyFlows() turns the two `categories/spending` responses (one per
 * transaction direction, netted and signed) into a flow graph: income
 * categories on the left into a central "Cash Flow" node, fanning back out
 * on the right into expense parent categories and -- where they have their
 * own spending -- their subcategories, with the income/expense gap becoming
 * a Surplus or Deficit node. No DOM, no Chart.js -- see ReportsModule.js for
 * fetching and rendering.
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
}));

import { buildMoneyFlows, CENTER_KEY, SURPLUS_KEY, DEFICIT_KEY } from '../../src/modules/reports/moneyFlow.js';

const categories = [
    // Income tree: Salary has a Bonus subcategory; Freelance is standalone.
    { id: 1, name: 'Salary', type: 'income', parentId: null, color: '#111111' },
    { id: 2, name: 'Bonus', type: 'income', parentId: 1, color: '#222222' },
    { id: 3, name: 'Freelance', type: 'income', parentId: null, color: null },
    // Expense tree: Housing has Rent + Utilities subcategories; Food has none.
    { id: 10, name: 'Housing', type: 'expense', parentId: null, color: '#333333' },
    { id: 11, name: 'Rent', type: 'expense', parentId: 10, color: '#444444' },
    { id: 12, name: 'Utilities', type: 'expense', parentId: 10, color: '#555555' },
    { id: 20, name: 'Food', type: 'expense', parentId: null, color: '#666666' },
];

describe('buildMoneyFlows', () => {
    it('rolls income subcategories into their top-level parent and flows into the center node', () => {
        const income = [
            { categoryId: 1, spent: 1000, name: 'Salary', color: '#111111' },
            { categoryId: 2, spent: 200, name: 'Bonus', color: '#222222' },
            { categoryId: 3, spent: 300, name: 'Freelance', color: null },
        ];

        const result = buildMoneyFlows(income, [], categories);

        const salaryFlow = result.flows.find(f => f.from === 'in:1' && f.to === CENTER_KEY);
        expect(salaryFlow).toBeDefined();
        expect(salaryFlow.flow).toBe(1200); // Salary (1000) + Bonus (200) rolled up

        const bonusFlow = result.flows.find(f => f.from === 'in:2');
        expect(bonusFlow).toBeUndefined(); // no separate node for the subcategory

        const freelanceFlow = result.flows.find(f => f.from === 'in:3' && f.to === CENTER_KEY);
        expect(freelanceFlow.flow).toBe(300);

        expect(result.labels['in:1']).toBe('Salary');
        expect(result.totals.income).toBe(1500);
    });

    it('carries each category\'s own color through, falling back to a default when unset', () => {
        const income = [
            { categoryId: 1, spent: 1000, name: 'Salary', color: '#111111' },
            { categoryId: 3, spent: 300, name: 'Freelance', color: null },
        ];

        const result = buildMoneyFlows(income, [], categories);

        expect(result.colors['in:1']).toBe('#111111');
        expect(result.colors['in:3']).toMatch(/^#[0-9a-f]{6}$/i);
        expect(result.colors['in:3']).not.toBe('#111111');
    });

    it('clamps a negative netted spend (refunds > spending) to zero instead of a negative flow', () => {
        const income = [{ categoryId: 1, spent: -50, name: 'Salary', color: '#111111' }];

        const result = buildMoneyFlows(income, [], categories);

        expect(result.flows.find(f => f.from === 'in:1')).toBeUndefined();
        expect(result.totals.income).toBe(0);
    });

    it('fans an expense parent out to subcategories with spending, keeping the parent\'s own remainder', () => {
        const expense = [
            { categoryId: 10, spent: 100, name: 'Housing', color: '#333333' }, // parent's own spending
            { categoryId: 11, spent: 800, name: 'Rent', color: '#444444' },
            { categoryId: 12, spent: 0, name: 'Utilities', color: '#555555' }, // no spending this period
        ];

        const result = buildMoneyFlows([], expense, categories);

        const parentFlow = result.flows.find(f => f.from === CENTER_KEY && f.to === 'out:10');
        expect(parentFlow.flow).toBe(900); // 100 own + 800 Rent

        const rentFlow = result.flows.find(f => f.from === 'out:10' && f.to === 'out:11');
        expect(rentFlow.flow).toBe(800);

        expect(result.flows.find(f => f.to === 'out:12' || f.from === 'out:12')).toBeUndefined();
        expect(result.totals.expenses).toBe(900);
    });

    it('produces a plain center -> parent flow when an expense parent has no spending subcategories', () => {
        const expense = [{ categoryId: 20, spent: 150, name: 'Food', color: '#666666' }];
        const income = [{ categoryId: 1, spent: 150, name: 'Salary', color: '#111111' }]; // balanced: no surplus/deficit noise

        const result = buildMoneyFlows(income, expense, categories);

        expect(result.flows).toContainEqual({ from: CENTER_KEY, to: 'out:20', flow: 150 });
        expect(result.flows.filter(f => f.to === 'out:20' || f.from === 'out:20')).toHaveLength(1);
    });

    it('adds a green Surplus node flowing out of center when income exceeds expenses', () => {
        const income = [{ categoryId: 1, spent: 1000, name: 'Salary', color: '#111111' }];
        const expense = [{ categoryId: 20, spent: 400, name: 'Food', color: '#666666' }];

        const result = buildMoneyFlows(income, expense, categories);

        const surplusFlow = result.flows.find(f => f.from === CENTER_KEY && f.to === SURPLUS_KEY);
        expect(surplusFlow.flow).toBe(600);
        expect(result.totals.surplus).toBe(600);
        expect(result.labels[SURPLUS_KEY]).toBe('Surplus');
        expect(result.colors[SURPLUS_KEY]).toMatch(/^#[0-9a-f]{6}$/i);
        expect(result.flows.find(f => f.from === DEFICIT_KEY || f.to === DEFICIT_KEY)).toBeUndefined();
    });

    it('adds a red Deficit node flowing into center when expenses exceed income', () => {
        const income = [{ categoryId: 1, spent: 400, name: 'Salary', color: '#111111' }];
        const expense = [{ categoryId: 20, spent: 1000, name: 'Food', color: '#666666' }];

        const result = buildMoneyFlows(income, expense, categories);

        const deficitFlow = result.flows.find(f => f.from === DEFICIT_KEY && f.to === CENTER_KEY);
        expect(deficitFlow.flow).toBe(600);
        expect(result.totals.surplus).toBe(-600);
        expect(result.labels[DEFICIT_KEY]).toBe('Deficit');
        expect(result.flows.find(f => f.from === SURPLUS_KEY || f.to === SURPLUS_KEY)).toBeUndefined();
    });

    it('adds neither a surplus nor a deficit node when income exactly equals expenses', () => {
        const income = [{ categoryId: 1, spent: 500, name: 'Salary', color: '#111111' }];
        const expense = [{ categoryId: 20, spent: 500, name: 'Food', color: '#666666' }];

        const result = buildMoneyFlows(income, expense, categories);

        expect(result.flows.find(f => [SURPLUS_KEY, DEFICIT_KEY].includes(f.from) || [SURPLUS_KEY, DEFICIT_KEY].includes(f.to))).toBeUndefined();
        expect(result.totals.surplus).toBe(0);
    });

    it('filters each side by category type, ignoring a refund credit row on an expense category and vice versa', () => {
        const income = [
            { categoryId: 1, spent: 500, name: 'Salary', color: '#111111' },
            { categoryId: 10, spent: 30, name: 'Housing', color: '#333333' }, // refund credit landed on an expense category
        ];
        const expense = [
            { categoryId: 20, spent: 200, name: 'Food', color: '#666666' },
            { categoryId: 1, spent: 10, name: 'Salary', color: '#111111' }, // shouldn't occur, but be defensive
        ];

        const result = buildMoneyFlows(income, expense, categories);

        expect(result.flows.find(f => f.from === 'in:10')).toBeUndefined();
        expect(result.flows.find(f => f.to === 'out:1')).toBeUndefined();
        expect(result.totals.income).toBe(500);
        expect(result.totals.expenses).toBe(200);
    });

    it('returns an empty flow graph and zero totals for no activity', () => {
        const result = buildMoneyFlows([], [], categories);

        expect(result.flows).toEqual([]);
        expect(result.totals).toEqual({ income: 0, expenses: 0, surplus: 0 });
    });
});
