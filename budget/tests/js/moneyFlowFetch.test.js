/**
 * generateMoneyFlowReport must fetch the category list itself (#366).
 *
 * The transform filters each spending row by its category's type, so it
 * needs the category record for every row. The app-level category list is
 * loaded at init and can be stale — a category created after page load (or
 * by another client) has spending rows but no record, and its flows were
 * silently dropped: seeded income categories vanished from the diagram
 * while the raw endpoint returned them fine. The report fetches
 * /api/categories fresh alongside the two spending calls instead of
 * trusting app state.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import ReportsModule from '../../src/modules/reports/ReportsModule.js';

const CATEGORIES = [
    { id: 371, name: 'Salary', type: 'income', parentId: null, color: '#2e7d32' },
    { id: 374, name: 'Rent', type: 'expense', parentId: null, color: '#e53935' },
];

function makeModule() {
    const mod = Object.create(ReportsModule.prototype);
    mod.app = { categories: [] }; // deliberately stale/empty app state
    mod.excludeShared = false;
    mod.renderMoneyFlowChart = vi.fn();
    return mod;
}

function jsonResponse(body) {
    return { ok: true, json: async () => body };
}

beforeEach(() => {
    document.body.innerHTML = '<div id="report-moneyflow" style="display:none"></div>';
    global.OC = { generateUrl: (p) => p, requestToken: 'tok' };
    global.fetch = vi.fn((url) => {
        if (url.includes('transactionType=credit')) {
            return Promise.resolve(jsonResponse([{ categoryId: 371, spent: 5650, name: 'Salary', color: '#2e7d32', count: 2 }]));
        }
        if (url.includes('transactionType=debit')) {
            return Promise.resolve(jsonResponse([{ categoryId: 374, spent: 2800, name: 'Rent', color: '#e53935', count: 2 }]));
        }
        return Promise.resolve(jsonResponse(CATEGORIES));
    });
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('generateMoneyFlowReport', () => {
    it('fetches the category list fresh instead of trusting stale app state', async () => {
        const mod = makeModule();

        await mod.generateMoneyFlowReport(new URLSearchParams({ startDate: '2026-06-01', endDate: '2026-08-29' }));

        const urls = global.fetch.mock.calls.map(c => String(c[0]));
        expect(urls.some(u => u.includes('/apps/budget/api/categories') && !u.includes('/spending'))).toBe(true);

        // With the fresh list, both rows resolve to their categories and the
        // flows reach the renderer — nothing silently dropped.
        const result = mod.renderMoneyFlowChart.mock.calls[0][0];
        expect(result.totals.income).toBe(5650);
        expect(result.totals.expenses).toBe(2800);
    });
});
