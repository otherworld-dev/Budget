/**
 * Bi-weekly income parity anchor (#363).
 *
 * The income form only offered a weekday (1-7) for weekly/bi-weekly
 * frequencies, so the server had nothing to anchor the fortnight to and
 * silently used the creation week. The form now shows an optional
 * "First payment date" for weekly/bi-weekly and sends it as startDate;
 * for other frequencies the anchor is meaningless and null is sent even
 * if the hidden field still holds a stale value.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import IncomeModule from '../../src/modules/income/IncomeModule.js';

function mountIncomeForm() {
    document.body.innerHTML = `
        <input type="hidden" id="income-id">
        <input type="text" id="income-name" value="Wages">
        <input type="text" id="income-description" value="">
        <input type="number" id="income-amount" value="900">
        <input type="text" id="income-source" value="">
        <select id="income-frequency">
            <option value="weekly"></option>
            <option value="biweekly"></option>
            <option value="monthly" selected></option>
            <option value="yearly"></option>
            <option value="one-time"></option>
        </select>
        <div class="form-group" id="expected-day-group">
            <label for="income-expected-day">Expected Day</label>
            <input type="number" id="income-expected-day" min="1" max="31" value="5">
            <small id="income-expected-day-help"></small>
        </div>
        <div class="form-group" id="expected-month-group" style="display: none;">
            <select id="income-expected-month"><option value=""></option></select>
        </div>
        <div class="form-group" id="income-start-date-group" style="display: none;">
            <input type="date" id="income-start-date">
        </div>
        <select id="income-category"><option value="" selected></option></select>
        <select id="income-account"><option value="" selected></option><option value="1"></option></select>
        <input type="checkbox" id="income-auto-create">
        <input type="text" id="income-auto-pattern" value="">
        <textarea id="income-notes"></textarea>
        <input type="checkbox" id="income-excluded-from-forecast">
    `;
}

function makeModule() {
    const mod = Object.create(IncomeModule.prototype);
    mod.hideIncomeModal = vi.fn();
    mod.loadIncomeView = vi.fn(async () => {});
    return mod;
}

const setFrequency = (f) => { document.getElementById('income-frequency').value = f; };
const startDateGroup = () => document.getElementById('income-start-date-group');

beforeEach(() => {
    mountIncomeForm();
    global.OC = { generateUrl: (p) => p, requestToken: 'token' };
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
});

describe('income first payment date field', () => {
    it('is shown for weekly and biweekly frequencies', () => {
        const mod = makeModule();

        setFrequency('weekly');
        mod.updateIncomeFormFields();
        expect(startDateGroup().style.display).toBe('block');

        setFrequency('biweekly');
        mod.updateIncomeFormFields();
        expect(startDateGroup().style.display).toBe('block');
    });

    it('is hidden for monthly and one-time frequencies', () => {
        const mod = makeModule();

        setFrequency('monthly');
        mod.updateIncomeFormFields();
        expect(startDateGroup().style.display).toBe('none');

        setFrequency('one-time');
        mod.updateIncomeFormFields();
        expect(startDateGroup().style.display).toBe('none');
    });
});

describe('weekday follows the anchor (#363 review)', () => {
    // With a first payment date set, the server derives the weekday from the
    // anchor and ignores the weekday input — mirror that in the form.
    it('derives and locks the weekday from the start date', () => {
        const mod = makeModule();
        const expectedDay = document.getElementById('income-expected-day');
        expectedDay.value = '2';

        setFrequency('biweekly');
        document.getElementById('income-start-date').value = '2026-08-14'; // a Friday
        mod.updateIncomeFormFields();

        expect(expectedDay.value).toBe('5');
        expect(expectedDay.disabled).toBe(true);
        expect(document.getElementById('income-expected-day-help').textContent).toBe('Follows the start date');
    });

    it('re-enables the weekday when the date is cleared', () => {
        const mod = makeModule();
        const expectedDay = document.getElementById('income-expected-day');

        setFrequency('weekly');
        document.getElementById('income-start-date').value = '2026-08-14';
        mod.updateIncomeFormFields();
        expect(expectedDay.disabled).toBe(true);

        document.getElementById('income-start-date').value = '';
        mod.updateIncomeFormFields();
        expect(expectedDay.disabled).toBe(false);
    });

    it('leaves the day editable for non-anchored frequencies', () => {
        const mod = makeModule();
        const expectedDay = document.getElementById('income-expected-day');
        expectedDay.value = '5';

        setFrequency('monthly');
        document.getElementById('income-start-date').value = '2026-08-14';
        mod.updateIncomeFormFields();

        expect(expectedDay.disabled).toBe(false);
        expect(expectedDay.value).toBe('5');
    });
});

describe('saveIncome startDate payload', () => {
    it('sends the chosen date for biweekly income', async () => {
        const mod = makeModule();
        setFrequency('biweekly');
        document.getElementById('income-start-date').value = '2026-08-14';

        let body = null;
        global.fetch = vi.fn(async (url, options) => {
            body = JSON.parse(options.body);
            return { ok: true, json: async () => ({}) };
        });

        await mod.saveIncome();

        expect(body).not.toBeNull();
        expect(body.startDate).toBe('2026-08-14');
    });

    it('sends null when the field is empty', async () => {
        const mod = makeModule();
        setFrequency('weekly');

        let body = null;
        global.fetch = vi.fn(async (url, options) => {
            body = JSON.parse(options.body);
            return { ok: true, json: async () => ({}) };
        });

        await mod.saveIncome();

        expect(body.startDate).toBeNull();
    });

    it('sends null for non-anchored frequencies even if the hidden field holds a stale value', async () => {
        const mod = makeModule();
        setFrequency('monthly');
        document.getElementById('income-start-date').value = '2026-08-14';

        let body = null;
        global.fetch = vi.fn(async (url, options) => {
            body = JSON.parse(options.body);
            return { ok: true, json: async () => ({}) };
        });

        await mod.saveIncome();

        expect(body.startDate).toBeNull();
    });
});
