/**
 * The Import History tab read `filename`, `accountName`, `status` and
 * `importDate`, but the server (TransactionMapper::getRecentImports) sends
 * `account_name`, `count`, `min_date`, `max_date` and `imported_at` and no
 * status at all, so `item.status.charAt` threw and the tab stayed empty.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import ImportModule from '../../src/modules/import/ImportModule.js';

// As the history endpoint returns it
const SERVER_ROWS = [
    { account_name: 'Current <b>', account_id: 1, count: '12', min_date: '2026-01-03', max_date: '2026-01-29', imported_at: '2026-02-01 09:15:00' },
    { account_name: 'Savings', account_id: 2, count: 1, min_date: '2026-01-10', max_date: '2026-01-10', imported_at: '2026-01-31 18:00:00' },
];

function makeModule() {
    const mod = Object.create(ImportModule.prototype);
    mod.app = { settings: {} };
    mod.formatDate = (d) => `[${d}]`;
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = '<table id="history-table"><tbody></tbody></table>';
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
});

afterEach(() => {
    delete global.fetch;
    delete global.OC;
});

const cells = (row) => [...document.querySelectorAll('#history-table tbody tr')[row].children].map(td => td.textContent.trim());

describe('import history', () => {
    it('renders the rows the server actually sends', async () => {
        global.fetch = vi.fn(async () => ({ ok: true, status: 200, json: async () => SERVER_ROWS }));
        const mod = makeModule();

        await mod.loadImportHistory();

        expect(global.fetch.mock.calls[0][0]).toBe('/apps/budget/api/import/history');
        expect(cells(0)).toEqual(['[2026-02-01]', 'Current <b>', '12', '[2026-01-03] to [2026-01-29]']);
        expect(cells(1)).toEqual(['[2026-01-31]', 'Savings', '1', '[2026-01-10]']);
        // The account name is text, not markup
        expect(document.querySelector('#history-table b')).toBeNull();
    });

    it('says there is nothing to show instead of an empty table', () => {
        makeModule().renderImportHistory([]);

        expect(document.querySelector('#history-table tbody').textContent).toContain('No recent imports');
    });
});
