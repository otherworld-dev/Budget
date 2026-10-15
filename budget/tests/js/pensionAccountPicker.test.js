/**
 * The From / Into account pickers on the pension forms. They listed every
 * shared account, read-only ones included, so a contribution could be set
 * up from an account it could never be posted into.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/chart.js', () => ({ default: vi.fn() }));

import PensionsModule from '../../src/modules/pensions/PensionsModule.js';

beforeEach(() => {
    document.body.innerHTML = '<select id="recurring-source-account"></select>';
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('_populateAccountSelect', () => {
    it('offers own accounts and shared ones that can be written to, not read-only ones', () => {
        const mod = Object.create(PensionsModule.prototype);
        mod.app = {
            accounts: [
                { id: 1, name: 'Current' },
                { id: 2, name: 'Old', closed: true },
                { id: 3, name: 'Joint', _shared: true, _canWrite: true },
                { id: 4, name: 'Parents', _shared: true, _canWrite: false },
            ],
        };

        mod._populateAccountSelect('recurring-source-account', 'None');

        const values = [...document.querySelectorAll('#recurring-source-account option')].map(o => o.value);
        expect(values).toEqual(['', '1', '3']);
    });
});
