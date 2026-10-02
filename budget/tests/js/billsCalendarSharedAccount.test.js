/**
 * Picking an account shared with you in the Bills Calendar gives no
 * projected balance: the server only projects the user's own accounts, since
 * it only knows their own bills and income, and the other person's bills on
 * that account would be missing. The footer said "Pick an account above"
 * although one was picked; it now says why there is no projection.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import ReportsModule from '../../src/modules/reports/ReportsModule.js';

function makeModule() {
    const mod = Object.create(ReportsModule.prototype);
    mod.app = {
        accounts: [
            { id: 5, name: 'Current', currency: 'GBP' },
            { id: 9, name: 'Joint', currency: 'EUR', _shared: true },
        ],
        settings: {},
    };
    mod.getPrimaryCurrency = () => 'GBP';
    mod.formatCurrency = (v, c) => `${Number(v).toFixed(2)} ${c}`;
    return mod;
}

const hint = () => document.querySelector('#bills-calendar-table-footer .balance-hint-row').textContent;

beforeEach(() => {
    document.body.innerHTML = `
        <select id="bills-calendar-account">
            <option value="">All</option><option value="5">Current</option><option value="9">Joint</option>
        </select>
        <table>
            <tbody id="bills-calendar-table-body"></tbody>
            <tfoot id="bills-calendar-table-footer"></tfoot>
        </table>
    `;
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Bills Calendar with a shared account picked', () => {
    it('says the projection is for your own accounts rather than asking to pick one', () => {
        document.getElementById('bills-calendar-account').value = '9';

        makeModule().renderBillsCalendarTable([], {}, null, null, null);

        expect(hint()).not.toContain('Pick an account');
        expect(hint()).toContain('your own accounts');
    });

    it('still asks for an account when none is picked', () => {
        makeModule().renderBillsCalendarTable([], {}, null, null, null);

        expect(hint()).toContain('Pick an account above');
    });
});
