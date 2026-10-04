/**
 * The starting balance typed into a new account's form.
 *
 * Changing the currency or the type, or ticking "in credit", reset the
 * typed starting balance to 0.00: those changes refresh an edit-only
 * preview (Current Balance = opening balance + the account's transactions),
 * and on a new account that wrote the hidden opening balance, 0, over the
 * typed amount. A credit card entered as 40 in credit was saved at 0.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text }));

import AccountsModule from '../../src/modules/accounts/AccountsModule.js';

function mountForm(accountId = '') {
    document.body.innerHTML = `
        <form id="account-form">
            <input type="hidden" id="account-id" value="${accountId}">
            <select id="account-type">
                <option value="checking">Checking</option>
                <option value="credit_card">Credit Card</option>
            </select>
            <div class="form-group" id="opening-balance-group" style="display: none;">
                <label id="account-opening-balance-label">Opening Balance</label>
                <input type="number" id="account-opening-balance" value="0" data-net-change="0">
                <small id="account-opening-balance-help"></small>
            </div>
            <div class="form-group" id="liability-in-credit-group" style="display: none;">
                <input type="checkbox" id="account-liability-in-credit" data-stored-in-credit="null">
                <small id="liability-in-credit-notice" style="display: none;"></small>
            </div>
            <label id="account-balance-label">Starting Balance</label>
            <input type="number" id="account-balance" value="0">
        </form>
    `;
}

const balance = () => document.getElementById('account-balance').value;

describe('a new account\'s starting balance', () => {
    let mod;

    beforeEach(() => {
        mountForm();
        mod = Object.create(AccountsModule.prototype);
        mod.renderLiabilityBalanceControl();
    });

    it('stays as typed when the type changes', () => {
        document.getElementById('account-balance').value = '123.45';
        document.getElementById('account-type').value = 'credit_card';

        mod.renderLiabilityBalanceControl();

        expect(balance()).toBe('123.45');
    });

    it('stays as typed when "in credit" is ticked', () => {
        document.getElementById('account-type').value = 'credit_card';
        mod.renderLiabilityBalanceControl();
        document.getElementById('account-balance').value = '40';
        document.getElementById('account-liability-in-credit').checked = true;

        mod.renderLiabilityBalanceControl();

        expect(balance()).toBe('40');
    });
});

describe('an existing account\'s current balance', () => {
    it('still follows the opening balance typed in', () => {
        mountForm('7');
        const field = document.getElementById('account-opening-balance');
        field.value = '100';
        field.dataset.signMode = 'signed';
        field.dataset.netChange = '25';
        const mod = Object.create(AccountsModule.prototype);

        mod.updateOpeningBalancePreview();

        expect(balance()).toBe('125.00');
    });
});
