/**
 * Import preview warning for a mapping that spawns accounts (#381).
 *
 * Mapping the wrong column to Account creates one account per distinct value,
 * silently — map the date column and you get one account per day. The preview
 * already listed what it was about to create, but as a comma-joined line of
 * prose among the stats, which reads as information rather than as a question
 * about whether to import at all.
 *
 * It now gets the same treatment as the direction warnings: a real warning,
 * ahead of the stats. Above a handful of new accounts it says how many, and
 * when the names look like dates it names the likely mistake instead of
 * leaving the user to work it out.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count, params = {}) =>
        String(count === 1 ? singular : plural)
            .replace(/%n/g, count)
            .replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(() => Promise.resolve(true)),
    promptDialog: vi.fn(() => Promise.resolve(null)),
    alertDialog: vi.fn(() => Promise.resolve()),
}));

import ImportModule from '../../src/modules/import/ImportModule.js';

const newAccounts = (names) => names.map(name => ({ name, type: 'checking', currency: 'GBP', exists: false }));

function makeModule() {
    return Object.create(ImportModule.prototype);
}

const warningEl = () => document.getElementById('import-account-warning');
const text = () => warningEl()?.textContent || '';

beforeEach(() => {
    document.body.innerHTML = '<div class="import-summary"><div id="stats"></div></div>';
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('renderAccountCreationWarning', () => {
    it('says nothing for a handful of accounts', () => {
        makeModule().renderAccountCreationWarning(newAccounts(['Current', 'Savings', 'Visa']));
        expect(warningEl()).toBeNull();
    });

    it('says nothing at the threshold itself', () => {
        makeModule().renderAccountCreationWarning(newAccounts(['a', 'b', 'c', 'd', 'e']));
        expect(warningEl()).toBeNull();
    });

    it('warns once the mapping would create more than five accounts, with the count', () => {
        makeModule().renderAccountCreationWarning(newAccounts(['a', 'b', 'c', 'd', 'e', 'f']));

        expect(warningEl()).not.toBeNull();
        expect(text()).toContain('6');
        expect(text()).toContain('Account');
    });

    it('names the likely mistake when the account names look like dates', () => {
        const dates = ['2026-01-07', '2026-01-08', '2026-01-09', '2026-01-10', '2026-01-11', '2026-01-12'];
        makeModule().renderAccountCreationWarning(newAccounts(dates));

        expect(text()).toContain('date');
    });

    it('recognises slash and dot date formats too', () => {
        const dates = ['07/01/2026', '08/01/2026', '09/01/2026', '10.01.2026', '11.01.2026', '12.01.2026'];
        makeModule().renderAccountCreationWarning(newAccounts(dates));

        expect(text()).toContain('date');
    });

    it('does not cry date over ordinary account names', () => {
        const names = ['Current', 'Savings', 'Visa', 'Amex', 'Joint', 'Holiday Fund'];
        makeModule().renderAccountCreationWarning(newAccounts(names));

        expect(warningEl()).not.toBeNull();
        expect(text()).not.toContain('look like dates');
    });

    it('needs most of the names to be dates, not just one or two', () => {
        const mixed = ['Current', 'Savings', 'Visa', 'Amex', '2026-01-07', '2026-01-08'];
        makeModule().renderAccountCreationWarning(newAccounts(mixed));

        expect(text()).not.toContain('look like dates');
    });

    it('counts only accounts that would actually be created, not matched ones', () => {
        const accounts = [
            ...newAccounts(['a', 'b', 'c']),
            ...['x', 'y', 'z', 'w'].map(name => ({ name, type: 'checking', currency: 'GBP', exists: true })),
        ];
        makeModule().renderAccountCreationWarning(accounts);

        expect(warningEl()).toBeNull();
    });

    it('sits ahead of the stats, where it changes whether to import at all', () => {
        makeModule().renderAccountCreationWarning(newAccounts(['a', 'b', 'c', 'd', 'e', 'f']));

        const summary = document.querySelector('.import-summary');
        expect(summary.firstElementChild.id).toBe('import-account-warning');
    });

    it('clears a previous warning when the mapping is corrected', () => {
        const mod = makeModule();
        mod.renderAccountCreationWarning(newAccounts(['a', 'b', 'c', 'd', 'e', 'f']));
        expect(warningEl()).not.toBeNull();

        mod.renderAccountCreationWarning(newAccounts(['Current']));
        expect(text()).toBe('');
    });

    it('escapes account names rather than rendering them', () => {
        const nasty = ['<img src=x>', 'b', 'c', 'd', 'e', 'f'];
        makeModule().renderAccountCreationWarning(newAccounts(nasty));

        expect(warningEl().querySelector('img')).toBeNull();
    });

    it('tolerates a missing or empty list', () => {
        const mod = makeModule();
        expect(() => mod.renderAccountCreationWarning(undefined)).not.toThrow();
        expect(() => mod.renderAccountCreationWarning([])).not.toThrow();
        expect(warningEl()).toBeNull();
    });
});
