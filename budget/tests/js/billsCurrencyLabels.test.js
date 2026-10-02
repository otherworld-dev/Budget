/**
 * Amounts the Bills page shows that carry only an account: the detected
 * bills panel, the suggestions card and the split template's
 * remaining/over label. They all used the default currency's symbol, so a
 * euro account's bills read in pounds while the form beside them said EUR.
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

import BillsModule from '../../src/modules/bills/BillsModule.js';

function makeModule() {
    const mod = Object.create(BillsModule.prototype);
    mod.app = {
        settings: { default_currency: 'GBP' },
        accounts: [{ id: 2, name: 'Euro', currency: 'EUR' }],
        categories: [],
    };
    return mod;
}

beforeEach(() => {
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    vi.clearAllMocks();
});

describe('bill amounts in their account currency', () => {
    it('shows detected bills in their account currency', () => {
        document.body.innerHTML = '<div id="detected-bills-list"></div>';
        makeModule().renderDetectedBills([
            { description: 'Gym', amount: 12.99, frequency: 'monthly', confidence: 0.9, accountId: 2 },
        ]);

        expect(document.querySelector('.detected-amount').textContent).toBe('€12.99');
    });

    it('shows suggestions in their account currency', async () => {
        document.body.innerHTML = '<div id="bill-suggestions-card"></div><div id="bill-suggestions-list"></div><span id="bill-suggestions-count"></span>';
        global.fetch = vi.fn(async () => ({
            ok: true,
            status: 200,
            headers: { get: () => 'application/json' },
            json: async () => ({ total: 1, suggestions: [{ description: 'Gym', amount: 12.99, frequency: 'monthly', confidence: 0.9, accountId: 2 }] }),
        }));

        await makeModule().loadBillSuggestions();

        expect(document.querySelector('.bill-suggestion-meta').textContent).toContain('€12.99');
    });

    it('says what is left of a split in the chosen account currency', () => {
        document.body.innerHTML = `
            <select id="bill-account"><option value="2" selected>Euro</option></select>
            <input id="bill-amount" value="50">
            <div class="bill-split-row"><input class="bill-split-amount" value="30"></div>
            <span id="bill-split-remaining"></span>
        `;

        makeModule().updateBillSplitRemaining();

        expect(document.getElementById('bill-split-remaining').textContent).toBe('€20.00 remaining');
    });
});
