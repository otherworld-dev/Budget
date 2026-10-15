/**
 * A shared card's Payment due tile. It looked only at the viewer's own
 * transfers, so the person who hadn't set up the card's payment saw "Set
 * up payment" and could add a second statement transfer into it. It now
 * asks the server for every active transfer into the card.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import AccountsModule from '../../src/modules/accounts/AccountsModule.js';

const card = { id: 7, name: 'Joint card', type: 'credit_card', currency: 'GBP' };

function makeModule() {
    const mod = Object.create(AccountsModule.prototype);
    mod.app = { settings: { default_currency: 'GBP' }, accounts: [card] };
    mod.currentAccount = card;
    return mod;
}

function serve(body) {
    global.fetch = vi.fn(async () => ({ ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => body }));
}

beforeEach(() => {
    document.body.innerHTML = `
        <button id="setup-card-payment-btn"></button>
        <div id="card-payment-info"><span id="account-card-payment"></span></div>
    `;
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.fetch;
    delete global.OC;
});

describe('card Payment due tile', () => {
    it('shows a payment the other person set up instead of offering a second one', async () => {
        serve([{ nextDueDate: '2026-10-25', amount: 0, amountType: 'statement', mine: false }]);

        await makeModule().updateCardPaymentInfo(card);

        expect(global.fetch.mock.calls[0][0]).toBe('/apps/budget/api/accounts/7/payment-transfers');
        expect(document.getElementById('card-payment-info').style.display).toBe('block');
        expect(document.getElementById('setup-card-payment-btn').style.display).toBe('none');
    });

    it('offers to set one up when nobody pays the card', async () => {
        serve([]);

        await makeModule().updateCardPaymentInfo(card);

        expect(document.getElementById('setup-card-payment-btn').style.display).toBe('');
    });
});
