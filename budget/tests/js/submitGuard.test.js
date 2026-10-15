/**
 * Saving twice by double-clicking. The create actions on the Bills,
 * Transfers and Income pages had no guard, so a double click (or Enter
 * pressed twice) created two of everything, each pre-booking its own row.
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

import { once } from '../../src/utils/submitGuard.js';
import IncomeModule from '../../src/modules/income/IncomeModule.js';

describe('once', () => {
    it('runs a second call only after the first has finished', async () => {
        let release;
        const work = vi.fn(() => new Promise(resolve => { release = resolve; }));
        const button = document.createElement('button');

        const first = once('save', button, work);
        const second = await once('save', button, work);
        expect(button.disabled).toBe(true);
        release('done');

        expect(await first).toBe('done');
        expect(second).toBeUndefined();
        expect(work).toHaveBeenCalledTimes(1);
        expect(button.disabled).toBe(false);
    });

    it('lets go after a failure', async () => {
        await expect(once('fail', null, async () => { throw new Error('nope'); })).rejects.toThrow('nope');
        const work = vi.fn(async () => 'again');

        expect(await once('fail', null, work)).toBe('again');
    });
});

describe('saving income twice', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <form id="income-form"><button type="submit">Save</button></form>
            <input id="income-id" value=""><input id="income-name" value="Salary">
            <textarea id="income-description"></textarea><input id="income-amount" value="100">
            <input id="income-source"><select id="income-frequency"><option value="monthly" selected>m</option></select>
            <input id="income-expected-day" value="25"><select id="income-expected-month"><option value=""></option></select>
            <select id="income-category"><option value=""></option></select><select id="income-account"><option value=""></option></select>
            <input id="income-auto-pattern"><textarea id="income-notes"></textarea>
            <input type="checkbox" id="income-auto-create"><input type="checkbox" id="income-excluded-from-forecast">
            <input id="income-start-date">
            <div id="income-modal"></div>
        `;
        global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    });

    afterEach(() => {
        document.body.innerHTML = '';
        delete global.OC;
        delete global.fetch;
    });

    it('creates it once', async () => {
        const mod = Object.create(IncomeModule.prototype);
        mod.app = { settings: {}, recurringIncome: [] };
        mod.loadIncomeView = vi.fn(async () => {});
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ id: 1 }) }));

        await Promise.all([mod.saveIncome(), mod.saveIncome()]);

        expect(global.fetch).toHaveBeenCalledTimes(1);
    });
});
