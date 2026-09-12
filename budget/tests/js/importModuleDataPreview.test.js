import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (match, key) => (key in params ? params[key] : match)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

import ImportModule from '../../src/modules/import/ImportModule.js';

function makeApp() {
    return {
        data: {},
        accounts: [],
        categories: [],
        settings: {},
        getPrimaryCurrency: () => 'USD',
        loadTransactions: vi.fn(),
        loadAccounts: vi.fn(),
    };
}

function makeImportDom() {
    document.body.innerHTML = `
        <div class="import-tabs">
            <button class="import-tab-btn active" data-tab="wizard">Wizard</button>
            <button class="import-tab-btn" data-tab="history">History</button>
        </div>
        <div id="import-wizard-tab" class="import-tab-content active"></div>
        <div id="import-history-tab" class="import-tab-content"></div>
        <div id="import-step-2">
            <div class="mapping-container"></div>
            <div class="mapping-options"></div>
        </div>
        <div id="import-preset-group"></div>
        <div id="csv-options">
            <select id="csv-delimiter">
                <option value=",">,</option>
                <option value=";">;</option>
                <option value="&#9;">Tab</option>
            </select>
            <input id="skip-first-row" type="checkbox" checked>
        </div>
        <button id="next-step-btn" type="button"></button>
        <button id="prev-step-btn" type="button"></button>
        <button id="import-btn" type="button"></button>
        <select id="map-date"><option value=""></option></select>
        <select id="map-amount"><option value=""></option></select>
        <select id="map-income"><option value=""></option></select>
        <select id="map-expense"><option value=""></option></select>
        <div id="map-description"></div>
        <div id="map-notes"></div>
        <select id="map-type"><option value=""></option></select>
        <div id="map-vendor"></div>
        <div id="map-reference"></div>
        <select id="map-category"><option value=""></option></select>
        <select id="map-account"><option value=""></option></select>
        <select id="map-currency"><option value=""></option></select>
        <table id="mapping-preview-table">
            <thead></thead>
            <tbody></tbody>
        </table>
        <table id="preview-table">
            <thead><tr><th id="preview-th-notes">Notes</th></tr></thead>
            <tbody></tbody>
        </table>
        <div id="preview-info"></div>
        <div id="import-preview-section"></div>
        <div id="single-account-selection"></div>
        <div id="multi-account-mapping"></div>
        <select id="import-account"></select>
        <div id="import-encoding-options" style="display:none"></div>
        <select id="import-encoding"></select>
        <div class="import-summary"></div>
        <div id="account-mapping-list"></div>
        <input id="import-file-input" type="file">
    `;
}

beforeEach(() => {
    global.OC = { generateUrl: (url) => url, requestToken: 'tok' };
    makeImportDom();
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    vi.restoreAllMocks();
});

describe('ImportModule CSV preview refresh', () => {
    it('re-fetches the stored file by fileId and redraws the preview when delimiter or header settings change', async () => {
        const mod = new ImportModule(makeApp());
        mod.importFormat = 'csv';
        mod.currentImportData = {
            fileId: 'file-123',
            filename: 'bank.csv',
            encoding: 'UTF-8',
        };
        mod.currentDelimiter = ',';

        const fetchMock = vi.fn(async (url, options) => {
            if (url === '/apps/budget/api/import/data-preview') {
                const body = JSON.parse(options.body);
                expect(body.fileId).toBe('file-123');
                expect(body.fileName).toBe('bank.csv');
                expect(body.delimiter).toBe(';');
                expect(body.skipFirstRow).toBe(false);
                expect(body.encoding).toBe('');

                return {
                    ok: true,
                    json: async () => ({
                        format: 'csv',
                        columns: ['', ''],
                        preview: [
                            ['2024-01-03', '12.34'],
                        ],
                        recordCount: 1,
                        skipFirstRow: false,
                    }),
                };
            }

            return {
                ok: true,
                json: async () => [],
            };
        });
        global.fetch = fetchMock;

        mod.populateColumnMappings(['Date', 'Amount']);
        mod.initMultiSelects();
        mod.multiSelects.description.setValue(['0']);
        document.getElementById('map-date').value = '0';
        document.getElementById('csv-delimiter').value = ';';
        document.getElementById('skip-first-row').checked = false;

        await mod.reloadDataPreview();

        expect(fetchMock).toHaveBeenCalledWith(
            '/apps/budget/api/import/data-preview',
            expect.objectContaining({
                method: 'POST',
                headers: expect.objectContaining({
                    'Content-Type': 'application/json',
                    'requesttoken': 'tok',
                }),
            }),
        );

        expect(document.getElementById('map-date').value).toBe('0');
        expect(document.querySelector('#mapping-preview-table thead tr').children.length).toBe(2);
        expect(document.querySelector('#mapping-preview-table tbody tr')?.textContent).toContain('2024-01-03');
    });

    it('restores skipFirstRow from the upload result and refreshes with the unchecked header state', async () => {
        const mod = new ImportModule(makeApp());
        mod.importFormat = 'csv';

        const fetchMock = vi.fn(async (url, options) => {
            if (url === '/apps/budget/api/import/data-preview') {
                const body = JSON.parse(options.body);
                expect(body.skipFirstRow).toBe(false);
                return {
                    ok: true,
                    json: async () => ({
                        format: 'csv',
                        columns: ['Date', 'Amount'],
                        preview: [['Date', 'Amount'], ['2024-01-03', '12.34']],
                        recordCount: 1,
                        skipFirstRow: false,
                    }),
                };
            }
            if (url === '/apps/budget/api/import/templates') {
                return { ok: true, json: async () => [] };
            }
            if (url === '/apps/budget/api/import-templates') {
                return { ok: true, json: async () => [] };
            }
            return { ok: true, json: async () => ({}) };
        });
        global.fetch = fetchMock;

        await mod.showImportMapping({
            fileId: 'upload-999',
            filename: 'demo.csv',
            format: 'csv',
            delimiter: ',',
            columns: ['Date', 'Amount'],
            preview: [['Date', 'Amount'], ['2024-01-01', '5.00']],
            recordCount: 2,
            size: 10,
            sourceAccounts: [],
            availableEncodings: {},
            skipFirstRow: false,
        });

        const skipFirstRow = document.getElementById('skip-first-row');
        expect(skipFirstRow.checked).toBe(false);

        skipFirstRow.checked = false;
        skipFirstRow.dispatchEvent(new Event('change'));
        await mod.reloadDataPreview();

        const previewCall = fetchMock.mock.calls.find(([url, options]) =>
            url === '/apps/budget/api/import/data-preview' &&
            typeof options?.body === 'string' &&
            options.body.includes('"skipFirstRow":false')
        );

        expect(previewCall).toBeTruthy();
    });

    it('clears stale encoding when Detect automatically is selected', async () => {
        const mod = new ImportModule(makeApp());
        mod.importFormat = 'csv';
        mod.currentImportData = {
            fileId: 'file-123',
            filename: 'bank.csv',
            delimiter: ',',
            skipFirstRow: true,
            encoding: 'Windows-1252',
        };
        mod.currentDelimiter = ',';

        const fetchMock = vi.fn(async (url, options) => {
            if (url === '/apps/budget/api/import/data-preview') {
                const body = JSON.parse(options.body);
                expect(body.fileId).toBe('file-123');
                expect(body.encoding).toBe('');
                return {
                    ok: true,
                    json: async () => ({
                        format: 'csv',
                        columns: ['Date', 'Amount'],
                        preview: [['2024-01-03', '12.34']],
                        recordCount: 1,
                        skipFirstRow: true,
                    }),
                };
            }
            return { ok: true, json: async () => ({}) };
        });
        global.fetch = fetchMock;

        const encodingSelect = document.getElementById('import-encoding');
        encodingSelect.innerHTML = '<option value="">Detect automatically</option><option value="Windows-1252">Windows-1252</option>';
        encodingSelect.value = '';

        await mod.reloadDataPreview();

        expect(fetchMock).toHaveBeenCalled();
        expect(mod.currentImportData.encoding).toBe('');
    });

    it('renders the first data row when the CSV is treated as header-less', async () => {
        const mod = new ImportModule(makeApp());
        mod.importFormat = 'csv';
        mod.currentImportData = { skipFirstRow: false };

        mod.populateColumnMappings(['Column 1', 'Column 2']);
        mod.showMappingPreview([
            ['2024-01-01', '5.00'],
            ['2024-01-02', '6.00'],
        ]);

        const rows = document.querySelectorAll('#mapping-preview-table tbody tr');
        expect(rows).toHaveLength(2);
        expect(rows[0].textContent).toContain('2024-01-01');
        expect(rows[1].textContent).toContain('2024-01-02');
    });

    it('waits for a refreshed preview before applying a CSV template mapping', async () => {
        const mod = new ImportModule(makeApp());
        mod.importFormat = 'csv';
        mod.currentImportData = { fileId: 'file-123', filename: 'bank.csv' };

        global.fetch = vi.fn(async (_url, options) => {
            const body = JSON.parse(options.body);
            expect(body.delimiter).toBe(';');
            expect(body.skipFirstRow).toBe(false);
            return {
                ok: true,
                json: async () => ({
                    format: 'csv',
                    columns: ['', ''],
                    preview: [['2024-01-03', '12.34']],
                    skipFirstRow: false,
                }),
            };
        });

        const applyMapping = vi.spyOn(mod, 'applyColumnMappingToForm');
        await mod.applyTemplateToForm({
            delimiter: ';',
            mapping: { date: 0, skipFirstRow: false },
        });

        expect(applyMapping).toHaveBeenCalledWith(
            { date: 0, skipFirstRow: false },
            expect.any(Array),
        );
        expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    it('ignores errors from an older preview request', async () => {
        const mod = new ImportModule(makeApp());
        mod.importFormat = 'csv';
        mod.currentImportData = { fileId: 'file-123', filename: 'bank.csv' };

        let rejectFirstRequest;
        const firstRequest = new Promise((resolve, reject) => {
            rejectFirstRequest = reject;
        });
        global.fetch = vi.fn()
            .mockReturnValueOnce(firstRequest)
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    columns: ['Date'],
                    preview: [['2024-01-03']],
                    skipFirstRow: true,
                }),
            });

        const firstPreview = mod.reloadDataPreview({ requestId: 'first' });
        const secondPreview = mod.reloadDataPreview({ requestId: 'second' });
        await secondPreview;
        rejectFirstRequest(new Error('stale failure'));

        await expect(firstPreview).resolves.toBeNull();
    });
});
