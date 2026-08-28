/**
 * Recurring transfer schedule fields (#364).
 *
 * The transfer form showed one static 'Day of Month (1-31)' input for EVERY
 * frequency and never sent startDate, so a weekly transfer had a nonsense
 * day-of-month field and a bi-weekly one had no way to say which fortnight
 * it falls in. The form is now frequency-aware exactly like the bills form
 * (weekday 1-7 for weekly/bi-weekly) and carries a start date input whose
 * value anchors the schedule server-side.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import TransfersModule from '../../src/modules/transfers/TransfersModule.js';

function mountScheduleFields() {
    document.body.innerHTML = `
        <select id="transfer-frequency">
            <option value="one-time"></option>
            <option value="weekly"></option>
            <option value="biweekly"></option>
            <option value="semi-monthly"></option>
            <option value="monthly" selected></option>
            <option value="quarterly"></option>
            <option value="yearly"></option>
        </select>
        <div class="form-group" id="transfer-due-day-group">
            <label for="transfer-due-day" id="transfer-due-day-label"></label>
            <input type="number" id="transfer-due-day" min="1" max="31">
            <small class="form-text" id="transfer-due-day-help"></small>
        </div>
        <div class="form-group" id="transfer-start-date-group" style="display: none;">
            <input type="date" id="transfer-start-date">
        </div>
    `;
}

const setFrequency = (f) => { document.getElementById('transfer-frequency').value = f; };
const label = () => document.getElementById('transfer-due-day-label').textContent;
const dueDayMax = () => document.getElementById('transfer-due-day').max;
const startDateGroup = () => document.getElementById('transfer-start-date-group');

beforeEach(() => mountScheduleFields());

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
});

describe('updateTransferScheduleFields', () => {
    it('offers a weekday (1-7) for weekly and biweekly transfers', () => {
        const mod = Object.create(TransfersModule.prototype);

        for (const f of ['weekly', 'biweekly']) {
            setFrequency(f);
            mod.updateTransferScheduleFields();
            expect(label()).toBe('Due Day (1-7)');
            expect(dueDayMax()).toBe('7');
        }
    });

    it('offers a day of month (1-31) for monthly transfers', () => {
        const mod = Object.create(TransfersModule.prototype);

        setFrequency('monthly');
        mod.updateTransferScheduleFields();

        expect(label()).toBe('Day of Month (1-31)');
        expect(dueDayMax()).toBe('31');
    });

    it('shows the start date for recurring frequencies and hides it for one-time', () => {
        const mod = Object.create(TransfersModule.prototype);

        for (const f of ['weekly', 'biweekly', 'monthly']) {
            setFrequency(f);
            mod.updateTransferScheduleFields();
            expect(startDateGroup().style.display).toBe('block');
        }

        setFrequency('one-time');
        mod.updateTransferScheduleFields();
        expect(startDateGroup().style.display).toBe('none');
    });
});

describe('weekday follows the anchor (#364 review)', () => {
    // With a start date set, the server derives the weekday from the anchor
    // and ignores the weekday input entirely — the form must mirror that
    // instead of letting the two contradict each other.
    it('derives and locks the weekday from the start date for anchored frequencies', () => {
        const mod = Object.create(TransfersModule.prototype);
        const dueDay = document.getElementById('transfer-due-day');
        dueDay.value = '2';

        setFrequency('biweekly');
        document.getElementById('transfer-start-date').value = '2026-08-14'; // a Friday
        mod.updateTransferScheduleFields();

        expect(dueDay.value).toBe('5');
        expect(dueDay.disabled).toBe(true);
        expect(document.getElementById('transfer-due-day-help').textContent).toBe('Follows the start date');
    });

    it('re-enables the weekday when the start date is cleared', () => {
        const mod = Object.create(TransfersModule.prototype);
        const dueDay = document.getElementById('transfer-due-day');

        setFrequency('weekly');
        document.getElementById('transfer-start-date').value = '2026-08-14';
        mod.updateTransferScheduleFields();
        expect(dueDay.disabled).toBe(true);

        document.getElementById('transfer-start-date').value = '';
        mod.updateTransferScheduleFields();
        expect(dueDay.disabled).toBe(false);
    });

    it('leaves the day-of-month editable for non-anchored frequencies with a start date', () => {
        const mod = Object.create(TransfersModule.prototype);
        const dueDay = document.getElementById('transfer-due-day');
        dueDay.value = '15';

        setFrequency('monthly');
        document.getElementById('transfer-start-date').value = '2026-08-14';
        mod.updateTransferScheduleFields();

        expect(dueDay.disabled).toBe(false);
        expect(dueDay.value).toBe('15');
    });
});

describe('saveTransfer startDate payload', () => {
    function mountSaveForm() {
        document.body.innerHTML += `
            <input type="text" id="transfer-name" value="Savings sweep">
            <input type="number" id="transfer-amount" value="150">
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
        mod.getSelectedTagIds = () => [];
        mod.loadTransfers = vi.fn(async () => {});
        mod.renderTransfers = vi.fn();
        mod.updateSummary = vi.fn();
        return mod;
    }

    it('sends the chosen start date', async () => {
        mountSaveForm();
        setFrequency('biweekly');
        document.getElementById('transfer-start-date').value = '2026-08-14';
        global.OC = { generateUrl: (p) => p, requestToken: 'token' };

        let body = null;
        global.fetch = vi.fn(async (url, options) => {
            body = JSON.parse(options.body);
            return { ok: true, json: async () => ({}) };
        });

        const ok = await makeModule().saveTransfer();

        expect(ok).toBe(true);
        expect(body).not.toBeNull();
        expect(body.isTransfer).toBe(true);
        expect(body.startDate).toBe('2026-08-14');
    });

    it('sends null when no start date was chosen', async () => {
        mountSaveForm();
        setFrequency('monthly');
        global.OC = { generateUrl: (p) => p, requestToken: 'token' };

        let body = null;
        global.fetch = vi.fn(async (url, options) => {
            body = JSON.parse(options.body);
            return { ok: true, json: async () => ({}) };
        });

        await makeModule().saveTransfer();

        expect(body.startDate).toBeNull();
    });

    it('sends null for one-time even if the hidden field holds a stale value', async () => {
        // The field is hidden for one-time, but a value left over from a
        // previous frequency choice used to be submitted anyway and reach
        // the server's start-date floor.
        mountSaveForm();
        setFrequency('one-time');
        document.getElementById('transfer-start-date').value = '2026-08-14';
        global.OC = { generateUrl: (p) => p, requestToken: 'token' };

        let body = null;
        global.fetch = vi.fn(async (url, options) => {
            body = JSON.parse(options.body);
            return { ok: true, json: async () => ({}) };
        });

        await makeModule().saveTransfer();

        expect(body.startDate).toBeNull();
    });
});
