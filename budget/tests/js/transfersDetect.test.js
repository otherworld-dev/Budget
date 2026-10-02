/**
 * Find Transfers.
 *
 * Detect Bills and the bill suggestions leave out debits already linked to
 * a transfer's other leg (as a bill, one booked a debit with no deposit), so
 * Find Transfers has to ask for them. When the linked legs show where the
 * money went, that account is picked as the destination to start with.
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

const accounts = [
    { id: 1, name: 'Current', currency: 'GBP' },
    { id: 2, name: 'Savings', currency: 'GBP' },
    { id: 3, name: 'ISA', currency: 'GBP' },
];

const candidate = (overrides = {}) => ({
    patternKey: 'so to savings|300',
    description: 'SO TO SAVINGS',
    suggestedName: 'So To Savings',
    amount: 300,
    frequency: 'monthly',
    confidence: 0.83,
    accountId: 1,
    ...overrides,
});

function makeModule() {
    const mod = Object.create(TransfersModule.prototype);
    mod.app = { settings: {}, accounts };
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = `
        <button id="detect-transfers-btn"></button>
        <div id="detected-transfers-panel" style="display: none"></div>
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

describe('Find Transfers', () => {
    it('asks the detector for linked transfer legs too', async () => {
        const mod = makeModule();
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => [candidate()] }));

        await mod.detectTransfers();

        expect(global.fetch).toHaveBeenCalledWith(
            '/apps/budget/api/bills/detect?months=6&transfers=true',
            expect.anything(),
        );
        expect(document.getElementById('detected-transfers-panel').style.display).toBe('flex');
    });

    it('starts with the destination the linked legs went to', () => {
        const mod = makeModule();

        mod.renderDetectedTransfers([
            candidate({ suggestedDestinationAccountId: 2 }),
            candidate({ patternKey: 'standing order|50', description: 'STANDING ORDER', amount: 50 }),
        ]);

        const selects = document.querySelectorAll('.detected-dest-account');
        expect(selects[0].value).toBe('2');
        expect(selects[1].value).toBe('');
    });
});
