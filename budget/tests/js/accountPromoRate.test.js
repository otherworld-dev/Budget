/**
 * Promotional interest rate on liability accounts: the form group, what the
 * save sends, and the line on the account detail view.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, vars) => (vars
        ? text.replace(/\{(\w+)\}/g, (_m, k) => String(vars[k]))
        : text),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

vi.mock('../../src/utils/api.js', async (importOriginal) => {
    const actual = await importOriginal();
    return { ...actual, apiFetch: vi.fn().mockResolvedValue({}) };
});

import AccountsModule from '../../src/modules/accounts/AccountsModule.js';
import { apiFetch } from '../../src/utils/api.js';
import { showWarning } from '../../src/utils/notifications.js';
import * as formatters from '../../src/utils/formatters.js';

const GROUPS = [
    'interest-rate-group', 'interest-enabled-group', 'compounding-frequency-group',
    'minimum-payment-group', 'statement-day-group', 'credit-limit-group',
    'overdraft-limit-group', 'routing-number-group', 'sort-code-group',
    'iban-group', 'swift-bic-group',
];

function mountForm() {
    document.body.innerHTML = `
        <form id="account-form">
            <input type="hidden" id="account-id" value="">
            <input type="text" id="account-name" value="Card">
            <select id="account-type">
                <option value="savings">Savings</option>
                <option value="credit_card">Credit Card</option>
                <option value="loan">Loan</option>
            </select>
            <input type="text" id="account-currency" value="GBP">
            <input type="text" id="account-balance" value="100">
            ${GROUPS.map(id => `<div class="form-group conditional" id="${id}"></div>`).join('')}
            <div class="form-group conditional" id="promo-rate-group">
                <input type="text" inputmode="decimal" data-amount id="account-promo-rate">
                <input type="date" id="account-promo-start">
                <input type="date" id="account-promo-end">
                <button type="button" id="account-promo-clear">Remove promotional rate</button>
            </div>
            <button type="submit">Save</button>
        </form>
        <div id="promo-rate-info" style="display: none;"><div id="account-promo-display"></div></div>
    `;
}

function makeModule() {
    const mod = Object.create(AccountsModule.prototype);
    mod.app = { settings: {}, accounts: [], loadDashboard: vi.fn() };
    mod.hideModals = vi.fn();
    mod.loadAccounts = vi.fn(async () => {});
    mod.loadInitialData = vi.fn(async () => {});
    return mod;
}

const visible = (id) => document.getElementById(id).style.display !== 'none';
const sent = () => apiFetch.mock.calls.at(-1)[1].body;

describe('promotional rate on the account form', () => {
    let mod;

    beforeEach(() => {
        mountForm();
        apiFetch.mockClear();
        mod = makeModule();
    });

    it('shows promo group for credit cards only', async () => {
        document.getElementById('account-type').value = 'credit_card';
        await mod.setupAccountTypeConditionals();
        expect(visible('promo-rate-group')).toBe(true);

        document.getElementById('account-type').value = 'savings';
        await mod.setupAccountTypeConditionals();
        expect(visible('promo-rate-group')).toBe(false);
    });

    it('submits promo fields when set, defaulting the start to today', async () => {
        document.getElementById('account-type').value = 'credit_card';
        document.getElementById('account-promo-rate').value = '0';
        document.getElementById('account-promo-end').value = '2027-11-01';

        await mod._saveAccount();

        expect(sent().promoRate).toBe(0);
        expect(sent().promoStart).toBe(formatters.getTodayDateString());
        expect(sent().promoEnd).toBe('2027-11-01');
    });

    it('keeps a start date the user entered', async () => {
        document.getElementById('account-type').value = 'loan';
        document.getElementById('account-promo-rate').value = '1.5';
        document.getElementById('account-promo-start').value = '2026-01-01';
        document.getElementById('account-promo-end').value = '2027-01-01';

        await mod._saveAccount();

        expect(sent().promoStart).toBe('2026-01-01');
    });

    it('does not send promo keys for a non-liability', async () => {
        document.getElementById('account-type').value = 'savings';
        document.getElementById('account-promo-rate').value = '0';

        await mod._saveAccount();

        expect(sent()).not.toHaveProperty('promoRate');
        expect(sent()).not.toHaveProperty('promoStart');
        expect(sent()).not.toHaveProperty('promoEnd');
    });

    it('refuses a rate without an end date', async () => {
        showWarning.mockClear();
        document.getElementById('account-type').value = 'credit_card';
        document.getElementById('account-promo-rate').value = '0';

        await mod._saveAccount();

        expect(apiFetch).not.toHaveBeenCalled();
        expect(showWarning).toHaveBeenCalledWith('Please enter the date the promotional rate ends');
    });

    it('refuses a start that is not before the end', async () => {
        showWarning.mockClear();
        document.getElementById('account-type').value = 'credit_card';
        document.getElementById('account-promo-rate').value = '0';
        document.getElementById('account-promo-start').value = '2027-01-01';
        document.getElementById('account-promo-end').value = '2027-01-01';

        await mod._saveAccount();

        expect(apiFetch).not.toHaveBeenCalled();
        expect(showWarning).toHaveBeenCalledWith('The promotional rate must start before it ends.');
    });

    it('clear sends nulls', async () => {
        document.getElementById('account-type').value = 'credit_card';
        document.getElementById('account-promo-rate').value = '0';
        document.getElementById('account-promo-start').value = '2026-01-01';
        document.getElementById('account-promo-end').value = '2027-01-01';

        mod.clearPromoRate();
        await mod._saveAccount();

        expect(sent().promoRate).toBeNull();
        expect(sent().promoStart).toBeNull();
        expect(sent().promoEnd).toBeNull();
    });
});

describe('promotional rate on the account detail view', () => {
    let mod;

    beforeEach(() => {
        mountForm();
        mod = makeModule();
    });

    it('says until before the end date', () => {
        mod.renderPromoInfo({ type: 'credit_card', promoRate: 0, promoStart: '2026-01-01', promoEnd: '2999-01-01' });
        expect(visible('promo-rate-info')).toBe(true);
        expect(document.getElementById('account-promo-display').textContent).toMatch(/^0% until /);
    });

    it('says ended after the end date', () => {
        mod.renderPromoInfo({ type: 'credit_card', promoRate: 0, promoStart: '2020-01-01', promoEnd: '2021-01-01' });
        expect(document.getElementById('account-promo-display').textContent).toMatch(/^0% ended /);
    });

    it('says ended on the end date itself', () => {
        const today = formatters.getTodayDateString();
        mod.renderPromoInfo({ type: 'credit_card', promoRate: 0, promoStart: '2020-01-01', promoEnd: today });
        expect(document.getElementById('account-promo-display').textContent).toMatch(/^0% ended /);
    });

    it('says from and until for a promo still to start', () => {
        mod.renderPromoInfo({ type: 'credit_card', promoRate: 0, promoStart: '2998-01-01', promoEnd: '2999-01-01' });
        expect(document.getElementById('account-promo-display').textContent).toMatch(/^0% from .+ until .+/);
    });

    it('hides without a promo or for a non-liability', () => {
        mod.renderPromoInfo({ type: 'credit_card', promoRate: null, promoEnd: null });
        expect(visible('promo-rate-info')).toBe(false);
        mod.renderPromoInfo({ type: 'savings', promoRate: 0, promoEnd: '2999-01-01' });
        expect(visible('promo-rate-info')).toBe(false);
    });
});
