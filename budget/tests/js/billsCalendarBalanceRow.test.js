/**
 * The Bills Calendar's table gets a second footer row when an account is
 * picked: what is left in that account after each month's bills (#393).
 * The server works the figures out; the row draws them in the account's
 * own currency, leaves a month blank where there is nothing left to pay,
 * and marks a shortfall. With no account picked there is nothing to start
 * from, so the footer says how to get the row instead.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import ReportsModule from '../../src/modules/reports/ReportsModule.js';

// A euro account on a Swiss-franc instance: the row must follow the account
const ACCOUNT = { id: 5, name: 'Current', currency: 'EUR', balance: 1000 };
const blank = () => Object.fromEntries(Array.from({ length: 12 }, (_, i) => [i + 1, null]));

function makeModule() {
    const mod = Object.create(ReportsModule.prototype);
    mod.app = { accounts: [], settings: {} };
    mod.getPrimaryCurrency = () => 'CHF';
    mod.formatCurrency = (v, c) => `${Number(v).toFixed(2)} ${c}`;
    return mod;
}

const footerRows = () => document.querySelectorAll('#bills-calendar-table-footer tr');
const balanceRow = () => document.querySelector('#bills-calendar-table-footer .balance-after-row');
const hintRow = () => document.querySelector('#bills-calendar-table-footer .balance-hint-row');

beforeEach(() => {
    document.body.innerHTML = `
        <table>
            <tbody id="bills-calendar-table-body"></tbody>
            <tfoot id="bills-calendar-table-footer"></tfoot>
        </table>
    `;
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Bills Calendar balance after bills row', () => {
    it('draws what is left after each month, blank where nothing is due, and marks a shortfall', () => {
        makeModule().renderBillsCalendarTable([], {}, ACCOUNT, { ...blank(), 2: 800, 3: -200 });

        const row = balanceRow();
        expect(row).not.toBeNull();
        expect(row.querySelector('.bill-name-col').textContent).toContain('Balance after bills');
        const cells = row.querySelectorAll('td:not(.bill-name-col)');
        expect(cells).toHaveLength(12);
        expect(cells[0].textContent).toBe('');
        expect(cells[1].textContent).toBe('800.00 EUR');
        expect(cells[1].classList.contains('negative')).toBe(false);
        expect(cells[2].textContent).toBe('-200.00 EUR');
        expect(cells[2].classList.contains('negative')).toBe(true);
    });

    it('says which account and what its balance is today', () => {
        makeModule().renderBillsCalendarTable([], {}, ACCOUNT, { ...blank(), 2: 800 });

        const title = balanceRow().getAttribute('title');
        expect(title).toContain('Current');
        expect(title).toContain('1000.00 EUR');
    });

    it('offers a hint instead when no account is picked', () => {
        makeModule().renderBillsCalendarTable([], {}, null, null);

        expect(balanceRow()).toBeNull();
        expect(hintRow()).not.toBeNull();
        expect(hintRow().textContent).toContain('account');
    });

    it('draws neither row nor hint when nothing is left to pay all year', () => {
        makeModule().renderBillsCalendarTable([], {}, ACCOUNT, blank());

        expect(balanceRow()).toBeNull();
        expect(hintRow()).toBeNull();
        expect(footerRows()).toHaveLength(1);
    });
});
