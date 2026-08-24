/**
 * Regression coverage for the liability balance sign in the account form (#353).
 *
 * A liability stores what is owed as a NEGATIVE number. Before this, the edit
 * form showed that raw signed value under a neutral "Opening Balance" label and
 * posted back whatever was typed, so a user who entered their statement balance
 * without the minus turned a debt into an asset -- a reported GBP 90,904.56 loan
 * added itself to net worth instead of subtracting, a GBP 181k swing, with
 * nothing on screen to say so.
 *
 * The form now collects a MAGNITUDE under an "Amount owed" label plus an
 * explicit in-credit tick, and the server applies the sign. Two properties keep
 * that honest, and both are easy to break by accident:
 *
 *   1. The field is sent ONLY when the user actually changes it. It used to be
 *      resubmitted on every save, so a rename depended on the value surviving a
 *      round trip through the form.
 *   2. The magnitude conversion runs on a real type FLIP, never on a plain
 *      dialog open -- converting twice would flip the sign straight back.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text }));

import AccountsModule from '../../src/modules/accounts/AccountsModule.js';

/** The parts of the account dialog the balance controls actually touch. */
function mountForm() {
    document.body.innerHTML = `
        <form id="account-form">
            <input type="hidden" id="account-id" value="1">
            <select id="account-type">
                <option value="checking">Checking</option>
                <option value="savings">Savings</option>
                <option value="loan">Loan</option>
                <option value="credit_card">Credit Card</option>
                <option value="mortgage">Mortgage</option>
            </select>
            <div class="form-group" id="opening-balance-group">
                <label id="account-opening-balance-label">Opening Balance</label>
                <input type="number" id="account-opening-balance">
                <small id="account-opening-balance-help"></small>
            </div>
            <div class="form-group" id="liability-in-credit-group" style="display: none;">
                <input type="checkbox" id="account-liability-in-credit">
                <small id="liability-in-credit-notice" style="display: none;"></small>
            </div>
            <label id="account-balance-label">Starting Balance</label>
            <input type="number" id="account-balance">
        </form>
    `;
}

/** The real methods, without the constructor that wires the whole accounts view. */
function makeModule() {
    return Object.create(AccountsModule.prototype);
}

/**
 * Put the form into the state loadAccountData() leaves it in for a given stored
 * account, which is the starting point for every case below.
 */
function prefill({ type, openingBalance, balance, liabilityInCredit = null }) {
    const LIABILITIES = ['credit_card', 'loan', 'mortgage', 'line_of_credit'];
    const isLiability = LIABILITIES.includes(type);
    const field = document.getElementById('account-opening-balance');
    const box = document.getElementById('account-liability-in-credit');

    document.getElementById('account-type').value = type;
    field.value = isLiability ? Math.abs(openingBalance) : openingBalance;
    field.dataset.signMode = isLiability ? 'magnitude' : 'signed';
    field.dataset.netChange = String(balance - openingBalance);
    delete field.dataset.dirty;

    box.checked = isLiability && openingBalance > 0;
    box.dataset.storedInCredit = liabilityInCredit === true ? 'true'
        : (liabilityInCredit === false ? 'false' : 'null');
}

describe('account form liability sign (#353)', () => {
    let mod;

    beforeEach(() => {
        mountForm();
        mod = makeModule();
    });

    it('shows a correctly-stored debt as a positive amount owed', () => {
        prefill({ type: 'loan', openingBalance: -5000, balance: -4800 });
        mod.renderLiabilityBalanceControl();

        expect(document.getElementById('account-opening-balance').value).toBe('5000');
        expect(document.getElementById('account-liability-in-credit').checked).toBe(false);
        expect(document.getElementById('account-opening-balance-label').textContent).toBe('Amount owed');
    });

    it('leaves the prefilled value alone on a plain dialog open', () => {
        // renderLiabilityBalanceControl runs on EVERY open, not only on a type
        // change. Converting here as well would flip 5000 back to -5000.
        prefill({ type: 'loan', openingBalance: -5000, balance: -4800 });
        mod.renderLiabilityBalanceControl();
        mod.renderLiabilityBalanceControl();

        expect(document.getElementById('account-opening-balance').value).toBe('5000');
        expect(document.getElementById('account-opening-balance').dataset.dirty).toBeUndefined();
    });

    it('previews the resulting current balance as a debt before saving', () => {
        prefill({ type: 'loan', openingBalance: -5000, balance: -4800 });
        document.getElementById('account-opening-balance').value = '90904.56';
        mod.renderLiabilityBalanceControl();

        // net change is +200, so the user sees -90704.56 -- visibly a debt.
        expect(document.getElementById('account-balance').value).toBe('-90704.56');
    });

    it('signs a typed amount owed as negative', () => {
        prefill({ type: 'loan', openingBalance: -5000, balance: -5000 });
        document.getElementById('account-opening-balance').value = '90904.56';

        expect(mod.openingBalanceSignedValue()).toBe(-90904.56);
    });

    it('keeps a ticked in-credit amount positive', () => {
        prefill({ type: 'credit_card', openingBalance: 500, balance: 430, liabilityInCredit: true });
        expect(document.getElementById('account-liability-in-credit').checked).toBe(true);
        expect(mod.openingBalanceSignedValue()).toBe(500);
    });

    it('leaves an asset balance signed, so an overdraft survives', () => {
        prefill({ type: 'checking', openingBalance: -200, balance: -200 });
        mod.renderLiabilityBalanceControl();

        expect(document.getElementById('account-opening-balance').value).toBe('-200');
        expect(mod.openingBalanceSignedValue()).toBe(-200);
        expect(document.getElementById('liability-in-credit-group').style.display).toBe('none');
    });

    it('converts and marks dirty when the type flips from asset to liability', () => {
        prefill({ type: 'savings', openingBalance: 1000, balance: 1200 });
        document.getElementById('account-type').value = 'mortgage';
        mod.renderLiabilityBalanceControl();

        const field = document.getElementById('account-opening-balance');
        expect(field.value).toBe('1000');
        expect(field.dataset.signMode).toBe('magnitude');
        // Dirty even though the user never typed: the flip must carry an
        // explicit value or the server has to guess the sign.
        expect(field.dataset.dirty).toBe('true');
        // net change +200 against a now-negative 1000.
        expect(document.getElementById('account-balance').value).toBe('-800.00');
    });

    it('converts back when the type flips from liability to asset', () => {
        prefill({ type: 'mortgage', openingBalance: -1000, balance: -800 });
        mod.renderLiabilityBalanceControl();
        document.getElementById('account-type').value = 'savings';
        mod.renderLiabilityBalanceControl();

        const field = document.getElementById('account-opening-balance');
        expect(field.value).toBe('-1000');
        expect(field.dataset.signMode).toBe('signed');
        expect(mod.openingBalanceSignedValue()).toBe(-1000);
    });

    it('warns only when a positive debt was never declared as a credit', () => {
        prefill({ type: 'credit_card', openingBalance: 90904.56, balance: 90904.56, liabilityInCredit: null });
        mod.renderLiabilityBalanceControl();

        const notice = document.getElementById('liability-in-credit-notice');
        expect(notice.style.display).not.toBe('none');
        expect(notice.textContent).toContain('in credit');
    });

    it('stays quiet for a confirmed genuine overpayment', () => {
        prefill({ type: 'credit_card', openingBalance: 50, balance: 50, liabilityInCredit: true });
        mod.renderLiabilityBalanceControl();

        expect(document.getElementById('liability-in-credit-notice').style.display).toBe('none');
    });

    it('never carries a ticked checkbox from one account to the next', () => {
        // The modal is a shared singleton and the edit path never calls
        // resetAccountForm, so state left in the DOM persists between opens.
        prefill({ type: 'credit_card', openingBalance: 500, balance: 500 });
        mod.renderLiabilityBalanceControl();
        expect(document.getElementById('account-liability-in-credit').checked).toBe(true);

        prefill({ type: 'loan', openingBalance: -5000, balance: -5000 });
        mod.renderLiabilityBalanceControl();
        expect(document.getElementById('account-liability-in-credit').checked).toBe(false);
    });
});
