/**
 * Transfers in another currency. The cards ignored the currency the API
 * already sends, so a 500 EUR transfer read as £500.00, and Monthly Total
 * added euros and dollars to pounds as if they were the same thing. The
 * total now comes from the server, converted to the base currency the way
 * the Bills page's is.
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

import TransfersModule from '../../src/modules/transfers/TransfersModule.js';

const transfer = (overrides = {}) => ({
    id: 1,
    name: 'Savings sweep',
    amount: 500,
    frequency: 'monthly',
    nextDueDate: '2099-06-15',
    isActive: true,
    isTransfer: true,
    accountId: 3,
    destinationAccountId: 4,
    ...overrides,
});

function makeModule(transfers = []) {
    const mod = Object.create(TransfersModule.prototype);
    mod.app = {
        settings: { default_currency: 'GBP' },
        accounts: [
            { id: 3, name: 'Euro current', currency: 'EUR' },
            { id: 4, name: 'Euro savings', currency: 'EUR' },
            { id: 5, name: 'Dollars', currency: 'USD' },
        ],
    };
    mod.transfers = transfers;
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = `
        <div id="transfers-list"></div>
        <div id="empty-transfers"></div>
        <div id="transfers-active-count"></div>
        <div id="transfers-due-count"></div>
        <div id="transfers-monthly-total"></div>
        <div id="transfers-completed-count"></div>
        <div id="detected-transfers-list"></div>
    `;
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    vi.clearAllMocks();
});

describe('transfer currency', () => {
    it('shows each transfer in its own currency', () => {
        const mod = makeModule([
            transfer({ id: 1, amount: 500, currency: 'EUR' }),
            transfer({ id: 2, amount: 100, currency: 'USD', accountId: 5 }),
        ]);
        mod.renderTransfers();

        const amounts = [...document.querySelectorAll('.bill-amount')].map(el => el.textContent);
        expect(amounts[0]).toContain('€500.00');
        expect(amounts[1]).toContain('$100.00');
    });

    it('takes Monthly Total from the server, converted to the base currency', async () => {
        global.fetch = vi.fn(async (url) => ({
            ok: true,
            status: 200,
            headers: { get: () => 'application/json' },
            json: async () => (String(url).includes('/api/bills/summary') ? { monthlyTotal: 502.27, baseCurrency: 'GBP' } : {}),
        }));
        const mod = makeModule([
            transfer({ id: 1, amount: 500, currency: 'EUR' }),
            transfer({ id: 2, amount: 100, currency: 'USD', accountId: 5 }),
        ]);

        await mod.updateSummary();

        expect(global.fetch).toHaveBeenCalledWith(expect.stringContaining('/api/bills/summary?isTransfer=true'), expect.anything());
        expect(document.getElementById('transfers-monthly-total').textContent).toBe('£502.27');
        expect(document.getElementById('transfers-active-count').textContent).toBe('2');
    });

    it('shows detected transfers in their account currency', () => {
        const mod = makeModule();
        mod.renderDetectedTransfers([
            { description: 'To savings', amount: 250, frequency: 'monthly', confidence: 0.9, accountId: 3 },
        ]);

        expect(document.querySelector('.detected-amount').textContent).toBe('€250.00');
    });
});
