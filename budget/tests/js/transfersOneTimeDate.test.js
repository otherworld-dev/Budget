/**
 * A one-time transfer takes a date (#395).
 *
 * The transfer form hid the start date for one-time and offered only a day
 * of month, so a one-time transfer had no month at all. The server filled
 * the gap with January: "day 28" entered on 22 September became due on
 * 28 January of the following year, and the Bills Calendar for this year
 * never saw it. One-time bills got a proper Due Date in #375, with the day
 * and month following the date; the transfer form now does the same.
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

vi.mock('../../src/utils/datepicker.js', () => ({
    initSingleDatePicker: vi.fn(),
}));

import TransfersModule from '../../src/modules/transfers/TransfersModule.js';
import { showWarning } from '../../src/utils/notifications.js';

function mountScheduleFields() {
    document.body.innerHTML = `
        <select id="transfer-frequency">
            <option value="one-time"></option>
            <option value="weekly"></option>
            <option value="monthly" selected></option>
        </select>
        <div class="form-group" id="transfer-due-day-group">
            <label for="transfer-due-day" id="transfer-due-day-label"></label>
            <input type="number" id="transfer-due-day" min="1" max="31">
            <small class="form-text" id="transfer-due-day-help"></small>
        </div>
        <div class="form-group" id="transfer-start-date-group" style="display: none;">
            <label for="transfer-start-date" id="transfer-start-date-label">Start Date</label>
            <input type="date" id="transfer-start-date">
            <small class="form-text" id="transfer-start-date-help"></small>
        </div>
    `;
}

function mountSaveForm() {
    document.body.innerHTML += `
        <input type="text" id="transfer-name" value="Rechnung Corner Card">
        <input type="number" id="transfer-amount" value="1000">
        <select id="recurring-transfer-from-account">
            <option value="1" selected></option><option value="2"></option>
        </select>
        <select id="recurring-transfer-to-account">
            <option value="1"></option><option value="2" selected></option>
        </select>
        <input type="text" id="transfer-description-pattern" value="">
        <textarea id="transfer-notes"></textarea>
        <input type="checkbox" id="transfer-auto-pay">
    `;
}

function makeModule() {
    const mod = Object.create(TransfersModule.prototype);
    mod.app = { accounts: [], categories: [], categoryTree: [], settings: {} };
    mod.getSelectedTagIds = () => [];
    mod.loadTransfers = vi.fn(async () => {});
    mod.renderTransfers = vi.fn();
    mod.updateSummary = vi.fn();
    return mod;
}

const setFrequency = (f) => { document.getElementById('transfer-frequency').value = f; };
const dueDayGroup = () => document.getElementById('transfer-due-day-group');
const startDateGroup = () => document.getElementById('transfer-start-date-group');
const startDateInput = () => document.getElementById('transfer-start-date');
const startDateLabel = () => document.getElementById('transfer-start-date-label').textContent;

function captureFetch() {
    const calls = [];
    global.fetch = vi.fn(async (url, options) => {
        calls.push(JSON.parse(options.body));
        return { ok: true, json: async () => ({}) };
    });
    return calls;
}

beforeEach(() => {
    global.OC = { generateUrl: (p) => p, requestToken: 'token' };
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    vi.clearAllMocks();
});

describe('one-time transfer schedule fields', () => {
    it('offers a due date in place of the day of month', () => {
        mountScheduleFields();
        const mod = Object.create(TransfersModule.prototype);

        setFrequency('one-time');
        mod.updateTransferScheduleFields();

        expect(startDateGroup().style.display).toBe('block');
        expect(startDateLabel()).toBe('Due Date');
        expect(startDateInput().required).toBe(true);
        expect(dueDayGroup().style.display).toBe('none');
    });

    it('puts the day of month and optional start date back for a recurring frequency', () => {
        mountScheduleFields();
        const mod = Object.create(TransfersModule.prototype);

        setFrequency('one-time');
        mod.updateTransferScheduleFields();
        setFrequency('monthly');
        mod.updateTransferScheduleFields();

        expect(startDateGroup().style.display).toBe('block');
        expect(startDateLabel()).toBe('Start Date');
        expect(startDateInput().required).toBe(false);
        expect(dueDayGroup().style.display).toBe('block');
    });
});

describe('saving a one-time transfer', () => {
    it('sends the date, with the day and month following it', async () => {
        mountScheduleFields();
        mountSaveForm();
        setFrequency('one-time');
        document.getElementById('transfer-due-day').value = '5'; // stale, from a previous frequency
        startDateInput().value = '2026-09-28';
        const bodies = captureFetch();

        const ok = await makeModule().saveTransfer();

        expect(ok).toBe(true);
        expect(bodies).toHaveLength(1);
        expect(bodies[0].startDate).toBe('2026-09-28');
        expect(bodies[0].dueDay).toBe(28);
        expect(bodies[0].dueMonth).toBe(9);
    });

    it('refuses to save without a date', async () => {
        mountScheduleFields();
        mountSaveForm();
        setFrequency('one-time');
        startDateInput().value = '';
        const bodies = captureFetch();

        const ok = await makeModule().saveTransfer();

        expect(ok).toBe(false);
        expect(bodies).toHaveLength(0);
        expect(showWarning).toHaveBeenCalled();
    });

    it('still sends no month for a recurring transfer', async () => {
        mountScheduleFields();
        mountSaveForm();
        setFrequency('monthly');
        document.getElementById('transfer-due-day').value = '15';
        startDateInput().value = '';
        const bodies = captureFetch();

        await makeModule().saveTransfer();

        expect(bodies[0].dueDay).toBe(15);
        expect(bodies[0].dueMonth).toBeUndefined();
        expect(bodies[0].startDate).toBeNull();
    });
});

describe('editing a one-time transfer saved before it had a date', () => {
    // Its only date is the next_due_date the server worked out, so that is
    // what the form opens on: the wrong year is in plain view, ready to be
    // corrected, instead of an empty required field.
    it('opens on the next due date', () => {
        const mod = makeModule();

        mod.showTransferModal({
            id: 7,
            name: 'Rechnung Corner Card',
            amount: 1000,
            frequency: 'one-time',
            accountId: 1,
            destinationAccountId: 2,
            dueDay: 28,
            startDate: null,
            nextDueDate: '2027-01-28',
            isTransfer: true,
        });

        expect(startDateInput().value).toBe('2027-01-28');
        expect(startDateGroup().style.display).toBe('block');
    });

    it('prefers the stored date once there is one', () => {
        const mod = makeModule();

        mod.showTransferModal({
            id: 7,
            name: 'Rechnung Corner Card',
            amount: 1000,
            frequency: 'one-time',
            accountId: 1,
            destinationAccountId: 2,
            dueDay: 28,
            startDate: '2026-09-28',
            nextDueDate: '2026-09-28',
            isTransfer: true,
        });

        expect(startDateInput().value).toBe('2026-09-28');
    });
});

describe('transfers list', () => {
    // A paid one-time transfer has no next occurrence, but it still has the
    // date it was due, kept as its start date - same as bills (#333, #375)
    it('shows a paid one-time transfer on the date it was due', () => {
        document.body.innerHTML = `
            <div id="transfers-list"></div>
            <div id="empty-transfers"></div>
        `;
        const mod = Object.create(TransfersModule.prototype);
        mod.app = { settings: {} };
        mod.transfers = [{
            id: 7,
            name: 'Rechnung Corner Card',
            amount: 1000,
            frequency: 'one-time',
            startDate: '2026-09-28',
            nextDueDate: null,
            isActive: false,
            isTransfer: true,
        }];

        mod.renderTransfers();

        const due = document.querySelector('.bill-due-date').textContent;
        expect(due).not.toContain('No due date');
        expect(due).toMatch(/2026|28/);
    });
});
