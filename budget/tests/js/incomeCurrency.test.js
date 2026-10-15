/**
 * Recurring income paid into an account in another currency. Every row,
 * Monthly Total and the detected-income panel used the default currency's
 * symbol, so a 1000 EUR salary read as £1,000.00 and the total added it to
 * pounds as it was. The rows now carry their account's currency, the total
 * comes converted from the server, and the form's account picker says which
 * currency each account is in, as the bill form's does.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
    showUndoNotification: vi.fn(),
}));

import IncomeModule from '../../src/modules/income/IncomeModule.js';

function makeModule() {
    const mod = Object.create(IncomeModule.prototype);
    mod.app = {
        settings: { default_currency: 'GBP' },
        accounts: [
            { id: 1, name: 'Current', currency: 'GBP' },
            { id: 2, name: 'Euro', currency: 'EUR' },
        ],
        categories: [],
    };
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = `
        <div id="income-list"></div>
        <div id="empty-income"></div>
        <div id="income-expected-count"></div>
        <div id="income-monthly-total"></div>
        <div id="income-received-count"></div>
        <div id="income-active-count"></div>
        <div id="detected-income-list"></div>
        <select id="income-account"></select>
    `;
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    vi.clearAllMocks();
});

describe('income currency', () => {
    it('shows each income in its account currency', () => {
        makeModule().renderRecurringIncome([
            { id: 1, name: 'Salary', amount: 1000, frequency: 'monthly', nextExpectedDate: '2099-06-25', isActive: true, accountId: 2, currency: 'EUR' },
        ]);

        expect(document.querySelector('.income-amount').textContent).toBe('€1,000.00');
    });

    it('shows Monthly Total in the base currency the server converted it to', async () => {
        global.fetch = vi.fn(async () => ({
            ok: true,
            status: 200,
            headers: { get: () => 'application/json' },
            json: async () => ({ monthlyTotal: 1860.53, baseCurrency: 'EUR', expectedThisMonth: 1, receivedThisMonth: 0, activeCount: 2 }),
        }));

        await makeModule().loadIncomeSummary();

        expect(document.getElementById('income-monthly-total').textContent).toBe('€1,860.53');
    });

    it('shows detected income in the currency of the account it arrived in', () => {
        makeModule().renderDetectedIncome([
            { suggestedName: 'Salary', amount: 1000, amountVariance: 5, frequency: 'monthly', occurrences: 3, source: 'ACME', confidence: 0.9, accountId: 2 },
        ]);

        expect(document.querySelector('.detected-bill-amount').textContent).toBe('€1,000.00');
        expect(document.querySelector('.variance-info').textContent).toBe('±€5.00');
    });

    it('names each account currency in the form picker', () => {
        makeModule().populateIncomeModalDropdowns();

        const labels = [...document.querySelectorAll('#income-account option')].map(o => o.textContent);
        expect(labels).toContain('Euro (EUR)');
    });
});
