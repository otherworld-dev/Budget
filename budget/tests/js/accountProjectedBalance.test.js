/**
 * The account page's Projected Balance tile (#163). It compared today's
 * balance with a storedBalance the account list never carries, so it never
 * showed; the server now sends projectedBalance, the balance once the
 * pre-booked bills, transfers and income go through.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import AccountsModule from '../../src/modules/accounts/AccountsModule.js';

const ids = [
    'account-details-title', 'account-type-icon', 'account-institution', 'account-health-indicator', 'account-display-name', 'account-type-label',
    'account-current-balance', 'credit-info', 'account-credit-limit-display', 'account-available-balance',
    'projected-balance-info', 'account-projected-balance', 'account-number', 'routing-number',
    'account-iban', 'sort-code', 'swift-bic', 'account-display-currency', 'account-opened', 'last-reconciled',
];

function makeModule() {
    const mod = Object.create(AccountsModule.prototype);
    mod.app = { settings: { default_currency: 'GBP' }, accounts: [], getPrimaryCurrency: () => 'GBP' };
    mod.updateCardPaymentInfo = vi.fn();
    mod.formatDate = (d) => d;
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = ids.map(id => `<div id="${id}"></div>`).join('');
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Projected Balance tile', () => {
    it('shows the balance once the pre-booked rows go through', () => {
        makeModule().populateAccountOverview({ id: 1, name: 'Current', type: 'checking', currency: 'GBP', balance: 950, projectedBalance: 1060 });

        expect(document.getElementById('projected-balance-info').style.display).toBe('block');
        expect(document.getElementById('account-projected-balance').textContent).toBe('£1,060.00');
    });

    it('stays hidden when nothing is pre-booked', () => {
        makeModule().populateAccountOverview({ id: 1, name: 'Current', type: 'checking', currency: 'GBP', balance: 950, projectedBalance: 950 });

        expect(document.getElementById('projected-balance-info').style.display).toBe('none');
    });
});
