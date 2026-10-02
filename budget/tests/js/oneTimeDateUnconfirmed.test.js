/**
 * One-time bills whose date was never entered (#399 review, F69).
 *
 * Before the Due Date field, a one-time bill could be saved with no month,
 * and the server filled its date in: 1 January next year, or the invoice's
 * day rolled into the next year. An upgrade, or paying it, then stored that
 * guess as the bill's date, and the edit form opened showing it as if the
 * user had typed it. Such a bill has no due month (saving the form always
 * sets one), so the page says to check the date rather than guess at it.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
    showUndoNotification: vi.fn(),
}));

import { billRowState } from '../../src/utils/billDates.js';
import BillsModule from '../../src/modules/bills/BillsModule.js';

const settings = { date_format: 'Y-m-d' };
const TODAY = '2026-09-22';

const guessed = { id: 7, name: 'Garage', amount: 120, frequency: 'one-time', isActive: true,
    dueDay: null, dueMonth: null, startDate: '2027-01-01', nextDueDate: '2027-01-01' };

describe('billRowState', () => {
    it('flags a one-time bill with no due month', () => {
        expect(billRowState(guessed, TODAY, settings).dateUnconfirmed).toBe(true);
    });

    it('trusts a date entered on the form', () => {
        expect(billRowState({ ...guessed, dueDay: 1, dueMonth: 1 }, TODAY, settings).dateUnconfirmed).toBe(false);
    });

    it('leaves recurring bills alone', () => {
        const rent = { ...guessed, frequency: 'monthly', dueDay: 1 };
        expect(billRowState(rent, TODAY, settings).dateUnconfirmed).toBe(false);
    });

    it('has nothing to check without a date', () => {
        expect(billRowState({ ...guessed, startDate: null, nextDueDate: null }, TODAY, settings).dateUnconfirmed).toBe(false);
    });
});

describe('the Bills page', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 8, 22, 12));
    });

    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    it('marks the row', () => {
        document.body.innerHTML = '<div id="bills-list"></div><div id="empty-bills"></div>';
        const mod = Object.create(BillsModule.prototype);
        mod.app = { settings, bills: [] };

        mod.renderBills([guessed, { ...guessed, id: 8, dueDay: 1, dueMonth: 1 }]);

        const row = (id) => document.querySelector(`.bill-card[data-bill-id="${id}"]`);
        expect(row(7).textContent).toContain('Check date');
        expect(row(8).textContent).not.toContain('Check date');
    });

    it('says so under the date in the edit form, until a date is picked', () => {
        document.body.innerHTML = `
            <div id="bill-modal"><h3 id="bill-modal-title"></h3>
            <form id="bill-form">
                <input type="hidden" id="bill-id"><input id="bill-name"><input id="bill-description"><input id="bill-amount">
                <select id="bill-frequency"><option value="monthly">m</option><option value="one-time">o</option></select>
                <div id="due-day-group"><label>Due Day</label><input id="bill-due-day"><small id="bill-due-day-help"></small></div>
                <div id="due-month-group"><select id="bill-due-month"></select></div>
                <div id="custom-months-group"><span id="bill-custom-months"></span></div>
                <div id="start-date-group"><label>Start</label><input type="date" id="bill-start-date">
                    <small id="bill-start-date-help"></small><small id="bill-start-date-unconfirmed" style="display: none;"></small></div>
                <div id="end-date-group"><input type="date" id="bill-end-date"></div>
                <div id="remaining-payments-group"><input id="bill-remaining-payments"></div>
                <div class="form-group"><select id="bill-category"></select></div><div class="form-group"><select id="bill-account"></select></div>
                <input id="bill-auto-pattern"><textarea id="bill-notes"></textarea>
                <select id="bill-reminder-days"><option value=""></option></select>
                <input type="checkbox" id="bill-create-transaction">
                <div id="transaction-date-group"><input type="date" id="bill-transaction-date"></div>
                <input type="checkbox" id="bill-auto-pay"><div id="auto-pay-failed-warning"></div>
                <input type="checkbox" id="bill-excluded-from-forecast"><div id="bill-tags-container"></div>
                <input type="checkbox" id="bill-split-enabled"><div id="bill-split-container"><div id="bill-split-rows"></div></div>
            </form></div>`;
        global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        const mod = Object.create(BillsModule.prototype);
        mod.app = { settings: {}, bills: [], accounts: [], categories: [], categoryTree: [] };
        mod.loadBillTagSets = vi.fn();
        mod.getSelectedBillTagIds = vi.fn(() => []);
        mod.setupBillsEventListeners();
        const note = document.getElementById('bill-start-date-unconfirmed');

        mod.showBillModal(guessed);
        expect(note.style.display).not.toBe('none');
        expect(note.textContent).not.toBe('');

        const date = document.getElementById('bill-start-date');
        date.value = '2026-09-30';
        date.dispatchEvent(new Event('change'));
        expect(note.style.display).toBe('none');

        mod.showBillModal({ ...guessed, dueDay: 1, dueMonth: 1 });
        expect(note.style.display).toBe('none');
        delete global.OC;
    });
});
