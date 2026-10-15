/**
 * The new-transaction form's defaults and its Expense / Income / Transfer
 * toggle.
 *
 * The form used to open on an empty "Choose an account" and "Choose
 * transaction type", both required, so every entry started with two picks the
 * app could have made. The type now starts on Expense and the account comes
 * from the list's filter, then the last account saved to, then the only open
 * one. The type is chosen with a segmented toggle kept in step with the
 * (hidden) select the rest of the form still reads.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';

function makeModule(accounts) {
    const mod = Object.create(TransactionsModule.prototype);
    mod.app = { accounts };
    return mod;
}

function mount({ filter = '' } = {}) {
    document.body.innerHTML = `
        <select id="filter-account">
            <option value="">All</option>
            <option value="1">Current</option>
            <option value="3">Old</option>
        </select>
        <div id="transaction-type-toggle">
            <button type="button" data-value="debit">Expense</button>
            <button type="button" data-value="credit">Income</button>
            <button type="button" data-value="transfer">Transfer</button>
        </div>
        <select id="transaction-type">
            <option value="">Choose</option>
            <option value="debit">Expense</option>
            <option value="credit">Income</option>
            <option value="transfer">Transfer</option>
        </select>`;
    document.getElementById('filter-account').value = filter;
}

const ACCOUNTS = [
    { id: 1, name: 'Current' },
    { id: 2, name: 'Savings' },
    { id: 3, name: 'Old', closed: true },
];

beforeEach(() => {
    window.localStorage.clear();
});

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('default account for a new transaction', () => {
    it('uses the account the list is filtered to', () => {
        mount({ filter: '1' });
        window.localStorage.setItem('budget.lastTransactionAccountId', '2');
        expect(String(makeModule(ACCOUNTS)._defaultTransactionAccountId())).toBe('1');
    });

    it('falls back to the last account a transaction was saved to', () => {
        mount();
        window.localStorage.setItem('budget.lastTransactionAccountId', '2');
        expect(String(makeModule(ACCOUNTS)._defaultTransactionAccountId())).toBe('2');
    });

    it('never picks a closed account', () => {
        mount({ filter: '3' });
        window.localStorage.setItem('budget.lastTransactionAccountId', '3');
        expect(makeModule(ACCOUNTS)._defaultTransactionAccountId()).toBeNull();
    });

    it('picks the only open account when there is just one', () => {
        mount();
        expect(makeModule([{ id: 7, name: 'Only' }, { id: 8, closed: true }])._defaultTransactionAccountId()).toBe(7);
    });

    it('leaves the choice to the user otherwise', () => {
        mount();
        expect(makeModule(ACCOUNTS)._defaultTransactionAccountId()).toBeNull();
    });

    it('copes with storage being unavailable', () => {
        mount();
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('blocked'); });
        expect(makeModule(ACCOUNTS)._defaultTransactionAccountId()).toBeNull();
    });
});

describe('type toggle', () => {
    it('mirrors the select and hides options the select no longer offers', () => {
        mount();
        const select = document.getElementById('transaction-type');
        select.value = 'credit';
        select.querySelector('option[value="transfer"]').remove();

        makeModule(ACCOUNTS)._syncTypeToggle();

        const btn = (v) => document.querySelector(`#transaction-type-toggle button[data-value="${v}"]`);
        expect(btn('credit').getAttribute('aria-checked')).toBe('true');
        expect(btn('debit').getAttribute('aria-checked')).toBe('false');
        expect(btn('transfer').hidden).toBe(true);
    });

    it('sets the select and fires its change handler when clicked', () => {
        mount();
        const select = document.getElementById('transaction-type');
        select.value = 'debit';
        const onchange = vi.fn();
        select.onchange = onchange;

        const mod = makeModule(ACCOUNTS);
        mod._setupTypeToggle();
        mod._syncTypeToggle();
        document.querySelector('#transaction-type-toggle button[data-value="transfer"]').click();

        expect(select.value).toBe('transfer');
        expect(onchange).toHaveBeenCalledOnce();
        expect(document.querySelector('#transaction-type-toggle button[data-value="transfer"]')
            .getAttribute('aria-checked')).toBe('true');
    });
});
