/**
 * The Bills Calendar's table gets a second footer row when an account is
 * picked for this year: its projected balance, carried from month to month
 * (#393). The server works the figures out; the row draws them in the
 * account's own currency, leaves the months already gone blank, marks a
 * shortfall, and breaks each month down in its tooltip. With no account
 * picked, or another year, there is nothing to start from, so the footer
 * says why instead.
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
const noFlows = { bills: 0, transfersIn: 0, income: 0 };

function makeModule() {
    const mod = Object.create(ReportsModule.prototype);
    mod.app = { accounts: [], settings: {} };
    mod.getPrimaryCurrency = () => 'CHF';
    mod.formatCurrency = (v, c) => `${Number(v).toFixed(2)} ${c}`;
    return mod;
}

const balanceRow = () => document.querySelector('#bills-calendar-table-footer .balance-after-row');
const hintRow = () => document.querySelector('#bills-calendar-table-footer .balance-hint-row');
const cells = () => balanceRow().querySelectorAll('td:not(.bill-name-col)');

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

describe('Bills Calendar projected balance row', () => {
    it('draws the balance for each month to come, blank for the months gone, and marks a shortfall', () => {
        makeModule().renderBillsCalendarTable([], {}, ACCOUNT,
            { ...blank(), 11: 800, 12: -200 },
            { ...blank(), 11: noFlows, 12: noFlows });

        expect(balanceRow().querySelector('.bill-name-col').textContent).toContain('Projected balance');
        expect(cells()).toHaveLength(12);
        expect(cells()[9].textContent).toBe('');
        expect(cells()[10].textContent).toBe('800.00 EUR');
        expect(cells()[10].classList.contains('negative')).toBe(false);
        expect(cells()[11].textContent).toBe('-200.00 EUR');
        expect(cells()[11].classList.contains('negative')).toBe(true);
    });

    it("breaks each month down in the cell's tooltip", () => {
        makeModule().renderBillsCalendarTable([], {}, ACCOUNT,
            { ...blank(), 12: 1500 },
            { ...blank(), 12: { bills: 700, transfersIn: 300, income: 2000 } });

        const title = cells()[11].getAttribute('title');
        expect(title).toContain('700.00 EUR');
        expect(title).toContain('300.00 EUR');
        expect(title).toContain('2000.00 EUR');
    });

    it('says which account, its balance today, and the lowest point', () => {
        makeModule().renderBillsCalendarTable([], {}, ACCOUNT,
            { ...blank(), 10: 400, 11: -200, 12: 300 },
            { ...blank(), 10: noFlows, 11: noFlows, 12: noFlows });

        const title = balanceRow().getAttribute('title');
        expect(title).toContain('Current');
        expect(title).toContain('1000.00 EUR');
        expect(title).toContain('Lowest: -200.00 EUR in November');
    });

    it('offers a hint instead when no account is picked', () => {
        makeModule().renderBillsCalendarTable([], {}, null, null, null);

        expect(balanceRow()).toBeNull();
        expect(hintRow().textContent).toContain('Pick an account');
    });

    it('says the projection is for this year only when another year is shown', () => {
        makeModule().renderBillsCalendarTable([], {}, ACCOUNT, null, null);

        expect(balanceRow()).toBeNull();
        expect(hintRow().textContent).toContain('current year');
    });
});
