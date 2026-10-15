/**
 * The account Edit dialog while its account loads.
 *
 * The dialog opened at once and filled itself when the account arrived,
 * without clearing what was there: for a moment it showed the previous
 * account's values, and anything typed or ticked in that moment (a new
 * name, "closed") was overwritten when the load landed, while Save still
 * said "Account saved successfully". It now opens cleared and can't be
 * used until this account's values are in; a slower load for an account
 * opened earlier never fills it, and a failed load closes it rather than
 * leaving an empty form whose Save would create a new account.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

import AccountsModule from '../../src/modules/accounts/AccountsModule.js';

function mountDialog() {
    document.body.innerHTML = `
        <div id="account-modal" style="display: none;" aria-hidden="true">
            <h3 id="account-modal-title"></h3>
            <form id="account-form">
                <input type="hidden" id="account-id">
                <div class="modal-scroll">
                <input type="text" id="account-name">
                <select id="account-type"><option value="checking">Checking</option><option value="savings">Savings</option></select>
                <select id="account-currency"><option value="GBP">GBP</option><option value="EUR">EUR</option></select>
                <input type="text" id="form-institution">
                <label id="account-balance-label"></label><small id="account-balance-help"></small>
                <input type="number" id="account-balance" value="10">
                <div id="opening-balance-group"><label id="account-opening-balance-label"></label>
                    <input type="number" id="account-opening-balance"><small id="account-opening-balance-help"></small></div>
                <div id="liability-in-credit-group"><input type="checkbox" id="account-liability-in-credit">
                    <small id="liability-in-credit-notice"></small></div>
                <input type="text" id="account-holder-name">
                <input type="text" id="account-opening-date">
                <input type="number" id="account-interest-rate">
                <input type="number" id="account-credit-limit">
                <input type="number" id="account-overdraft-limit">
                <input type="number" id="account-minimum-payment">
                <input type="number" id="account-statement-day">
                <input type="checkbox" id="account-interest-enabled">
                <select id="account-compounding-frequency"><option value="daily">daily</option></select>
                <input type="checkbox" id="account-excluded-from-reports">
                <div id="account-closed-group"><input type="checkbox" id="account-closed"><small id="account-closed-hint"></small></div>
                </div>
                <div class="modal-buttons">
                    <button type="submit">Save</button>
                    <button type="button" class="cancel-btn">Cancel</button>
                </div>
            </form>
        </div>`;
    // The previous account, filled in as the dialog does (not markup defaults)
    document.getElementById('account-id').value = '3';
    document.getElementById('account-name').value = 'Old account';
    document.getElementById('form-institution').value = 'Old bank';
}

const ACCOUNTS = {
    7: { id: 7, name: 'Joint', type: 'savings', currency: 'EUR', balance: 250, openingBalance: 0, institution: 'Bank A' },
    8: { id: 8, name: 'Holiday', type: 'checking', currency: 'GBP', balance: 40, openingBalance: 40, institution: 'Bank B' },
};

/** Requests wait until released, so the test can act while one is in flight. */
let pending;

function serve() {
    pending = {};
    global.fetch = vi.fn((url) => {
        const id = parseInt(url.split('/').pop(), 10);
        return new Promise((resolve, reject) => {
            pending[id] = {
                ok: () => resolve({ ok: true, status: 200, headers: { get: () => null }, json: async () => ACCOUNTS[id] }),
                fail: () => resolve({ ok: false, status: 404, statusText: 'Not Found', json: async () => ({ error: 'Account not found' }) }),
                reject,
            };
        });
    });
}

const flush = () => new Promise(resolve => setTimeout(resolve, 0));
const form = () => document.getElementById('account-form');
const fields = () => form().querySelector('.modal-scroll');
const save = () => form().querySelector('[type="submit"]');
const cancel = () => form().querySelector('.cancel-btn');
const value = (id) => document.getElementById(id).value;

function makeModule() {
    const mod = Object.create(AccountsModule.prototype);
    mod.app = { settings: {}, hideModals: vi.fn() };
    mod.setupAccountTypeConditionals = vi.fn();
    mod.setupBankingFieldValidation = vi.fn();
    return mod;
}

beforeEach(() => {
    global.OC = { generateUrl: (p) => p, requestToken: 'tok' };
    mountDialog();
    serve();
});

afterEach(() => {
    delete global.fetch;
    delete global.OC;
    document.body.innerHTML = '';
});

describe('opening an account to edit', () => {
    it('shows none of the previous account and can\'t be used until this one loads', async () => {
        const mod = makeModule();

        mod.showAccountModal(7);

        expect(value('account-name')).toBe('');
        expect(value('form-institution')).toBe('');
        expect(fields().hasAttribute('inert')).toBe(true);
        expect(save().disabled).toBe(true);
        // Cancel still works while it loads
        expect(cancel().disabled).toBe(false);
        expect(cancel().closest('[inert]')).toBeNull();
        expect(form().getAttribute('aria-busy')).toBe('true');

        pending[7].ok();
        await flush();
        await flush();

        expect(value('account-name')).toBe('Joint');
        expect(value('account-id')).toBe('7');
        expect(fields().hasAttribute('inert')).toBe(false);
        expect(save().disabled).toBe(false);
        expect(form().hasAttribute('aria-busy')).toBe(false);
        expect(mod.setupAccountTypeConditionals).toHaveBeenCalled();
    });

    it('is filled by the account opened last, whichever load finishes first', async () => {
        const mod = makeModule();

        mod.showAccountModal(7);
        mod.showAccountModal(8);
        pending[8].ok();
        await flush();
        await flush();
        pending[7].ok();
        await flush();
        await flush();

        expect(value('account-name')).toBe('Holiday');
        expect(value('account-id')).toBe('8');
        expect(fields().hasAttribute('inert')).toBe(false);
    });

    it('is not filled by an edit load that finishes after Add Account was opened', async () => {
        const mod = makeModule();

        mod.showAccountModal(7);
        mod.showAccountModal(null);
        expect(fields().hasAttribute('inert')).toBe(false);
        pending[7].ok();
        await flush();
        await flush();

        expect(value('account-name')).toBe('');
        expect(value('account-id')).toBe('');
    });

    it('closes when the account can\'t be loaded', async () => {
        const mod = makeModule();

        mod.showAccountModal(7);
        pending[7].fail();
        await flush();
        await flush();

        expect(mod.app.hideModals).toHaveBeenCalled();
    });
});
