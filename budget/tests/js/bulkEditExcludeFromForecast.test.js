/**
 * "Exclude from forecast" in Bulk Edit (#491).
 *
 * The select defaults to "Don't change", which sends nothing, so each
 * selected row keeps its own setting. Exclude and Include send a real bool.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => String(text),
    translatePlural: (_app, singular, plural, count) =>
        String(count === 1 ? singular : plural).replace(/%n/g, count),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(async () => true),
    alertDialog: vi.fn(async () => {}),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import { showWarning } from '../../src/utils/notifications.js';

let posted;

function makeModule(selected = [1, 2]) {
    const mod = Object.create(TransactionsModule.prototype);
    mod.selectedTransactions = new Set(selected);
    mod.allMatchingSelection = null;
    mod.app = { currentPage: 3, loadTransactions: vi.fn() };

    global.fetch = vi.fn(async (url, init) => {
        posted.push({ url, body: init && init.body ? JSON.parse(init.body) : null });
        return { ok: true, json: async () => ({ success: selected.length, failed: 0, errors: [] }) };
    });
    return mod;
}

const bulkEditRequests = () => posted.filter(p => String(p.url).includes('/bulk-edit'));

beforeEach(() => {
    posted = [];
    vi.clearAllMocks();
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    document.body.innerHTML = `
        <div id="bulk-edit-modal" style="display: none;">
            <span id="bulk-edit-count"></span>
            <select id="bulk-edit-category"><option value="">Don't change</option></select>
            <input id="bulk-edit-vendor">
            <input id="bulk-edit-reference">
            <textarea id="bulk-edit-notes"></textarea>
            <select id="bulk-edit-excluded-from-forecast">
                <option value="">Don't change</option>
                <option value="true">Exclude from forecast</option>
                <option value="false">Include in forecast</option>
            </select>
        </div>`;
});

afterEach(() => {
    delete global.OC;
    delete global.fetch;
});

const forecastSelect = () => document.getElementById('bulk-edit-excluded-from-forecast');

describe('bulk edit: exclude from forecast', () => {
    it('opens on "Don\'t change"', () => {
        const mod = makeModule();
        forecastSelect().value = 'true';

        mod.showBulkEditModal();

        expect(forecastSelect().value).toBe('');
    });

    it('sends excludedFromForecast: true for Exclude', async () => {
        const mod = makeModule();
        forecastSelect().value = 'true';

        await mod.submitBulkEdit();

        expect(bulkEditRequests()).toHaveLength(1);
        expect(bulkEditRequests()[0].body).toEqual({ ids: [1, 2], updates: { excludedFromForecast: true } });
    });

    it('sends excludedFromForecast: false for Include', async () => {
        const mod = makeModule();
        forecastSelect().value = 'false';

        await mod.submitBulkEdit();

        expect(bulkEditRequests()[0].body.updates).toEqual({ excludedFromForecast: false });
    });

    it('leaves the flag out on "Don\'t change"', async () => {
        const mod = makeModule();
        document.getElementById('bulk-edit-notes').value = 'checked';

        await mod.submitBulkEdit();

        const updates = bulkEditRequests()[0].body.updates;
        expect(updates).toEqual({ notes: 'checked' });
        expect('excludedFromForecast' in updates).toBe(false);
    });

    it('still asks for a field when nothing is chosen', async () => {
        const mod = makeModule();

        await mod.submitBulkEdit();

        expect(showWarning).toHaveBeenCalled();
        expect(bulkEditRequests()).toHaveLength(0);
    });
});
