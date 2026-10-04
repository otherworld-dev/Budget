/**
 * The import screen's "Apply import rules for categorization" box was
 * ignored: the import always sent applyRules: true, so rules re-cased and
 * re-categorised rows the user had asked to leave alone (T3-9). The box now
 * decides, for the preview and the import, and starts from the user's
 * "Auto-apply Import Rules" setting (on unless they switched it off).
 */

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

vi.mock('../../src/utils/api.js', () => ({
    apiFetch: vi.fn(),
    ApiError: class ApiError extends Error {},
}));

import ImportModule from '../../src/modules/import/ImportModule.js';
import { apiFetch } from '../../src/utils/api.js';

function makeModule(settings = {}) {
    const mod = Object.create(ImportModule.prototype);
    mod.app = { settings, loadAccounts: vi.fn() };
    mod.multiSelects = {};
    mod.userTemplates = [];
    mod.sourceAccounts = [];
    mod.loadTransactions = vi.fn();
    mod.resetImportWizard = vi.fn();
    mod.currentImportData = { fileId: 'import_user1_x.csv', encoding: null };
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = `
        <input type="checkbox" id="apply-rules">
        <input type="checkbox" id="import-duplicates">
        <select id="csv-delimiter"><option value=",">,</option></select>
        <select id="import-account"><option value="4">Current</option></select>
        <button id="import-btn">Import</button>
    `;
    document.getElementById('import-account').value = '4';
    apiFetch.mockImplementation(async (url) => (url === '/apps/budget/api/import/process'
        ? { status: 200, text: async () => JSON.stringify({ imported: 1, skipped: 0, errors: [], transactionIds: [] }) }
        : { transactions: [], validTransactions: 0 }));
});

afterEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
});

function processBody() {
    const call = apiFetch.mock.calls.find(([url]) => url === '/apps/budget/api/import/process');
    return call?.[1]?.body;
}

describe('the Apply import rules box', () => {
    it('is honoured when unticked', async () => {
        const mod = makeModule();
        document.getElementById('apply-rules').checked = false;

        await mod.executeImport();

        expect(processBody().applyRules).toBe(false);
    });

    it('runs the rules when ticked', async () => {
        const mod = makeModule();
        document.getElementById('apply-rules').checked = true;

        await mod.executeImport();

        expect(processBody().applyRules).toBe(true);
    });

    it('wins over a routing template once the user has changed it', async () => {
        const mod = makeModule();
        mod.selectedTemplate = 9;
        mod.userTemplates = [{ id: 9, format: 'ofx', applyRules: true }];
        document.getElementById('apply-rules').checked = false;

        await mod.executeImport();

        expect(processBody().templateId).toBe(9);
        expect(processBody().applyRules).toBe(false);
    });

    it('goes into the preview request too', async () => {
        const mod = makeModule();
        mod.updateImportSummary = vi.fn();
        mod.showTransactionPreview = vi.fn();
        mod.filterPreviewTransactions = vi.fn();
        document.getElementById('apply-rules').checked = false;

        await mod.processImportData();

        const call = apiFetch.mock.calls.find(([url]) => url === '/apps/budget/api/import/preview');
        expect(call[1].body.applyRules).toBe(false);
    });
});

describe('the box for a new file', () => {
    function initial(settings) {
        const mod = makeModule(settings);
        // Only the start of showImportMapping matters here
        mod.switchImportTab = vi.fn();
        mod.renderEncodingPicker = vi.fn();
        mod.populateColumnMappings = vi.fn();
        mod.applyFormatDefaults = vi.fn();
        mod.applyFormatFieldVisibility = vi.fn();
        mod.showMappingPreview = vi.fn();
        mod.loadUserTemplates = vi.fn();
        mod.setImportStep = vi.fn();
        document.getElementById('apply-rules').checked = !settings.expected;
        return mod.showImportMapping({ fileId: 'f', filename: 'a.ofx', format: 'ofx', sourceAccounts: [] });
    }

    it('starts ticked by default', async () => {
        await initial({ expected: true });
        expect(document.getElementById('apply-rules').checked).toBe(true);
    });

    it('starts unticked when the user switched auto-apply off', async () => {
        await initial({ import_auto_apply_rules: 'false', expected: false });
        expect(document.getElementById('apply-rules').checked).toBe(false);
    });
});
