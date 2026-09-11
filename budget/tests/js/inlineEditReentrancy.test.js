/**
 * Inline edit must not ask the same question twice (#381).
 *
 * Editing a reconciled transaction's amount raises a warning. That warning is
 * an in-app dialog now, and a dialog is asynchronous where window.confirm()
 * blocked the whole task — which exposes two ways back into saveInlineEdit for
 * a single edit:
 *
 *   1. Opening the dialog moves focus off the editor, firing its blur handler,
 *      which calls back in 100ms later while the first call is still waiting.
 *   2. Closing the dialog hands focus BACK to the editor; the table then
 *      re-renders and detaches it, and that teardown fires blur again — this
 *      time against a cell from a render that no longer exists.
 *
 * The first is caught by an in-flight guard, the second by refusing to act on a
 * detached cell. Both were found in a real browser: the first showed as two
 * dialogs at once, the second as a second dialog appearing after the user had
 * already answered.
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
}));

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(() => Promise.resolve(true)),
    promptDialog: vi.fn(() => Promise.resolve(null)),
    alertDialog: vi.fn(() => Promise.resolve()),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import { confirmDialog } from '../../src/utils/dialogs.js';

const RECONCILED = { id: 1, amount: 50, type: 'debit', date: '2026-02-14', accountId: 3, reconciled: true };

function makeModule() {
    const mod = Object.create(TransactionsModule.prototype);
    // `transactions` is a getter delegating to the app object.
    mod.app = {
        transactions: [{ ...RECONCILED }],
        renderEnhancedTransactionsTable: vi.fn(),
        applyColumnVisibility: vi.fn(),
        loadAccounts: vi.fn(async () => {}),
    };
    mod.cancelInlineEdit = vi.fn();
    return mod;
}

function makeCell() {
    const cell = document.createElement('td');
    cell.className = 'editable-cell editing';
    cell.dataset.transactionId = '1';
    cell.dataset.field = 'amount';
    document.body.appendChild(cell);
    return cell;
}

beforeEach(() => {
    document.body.innerHTML = '';
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    global.fetch = vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ id: 1, amount: 77 }) }));
    confirmDialog.mockResolvedValue(true);
});

afterEach(() => {
    delete global.OC;
    delete global.fetch;
    vi.clearAllMocks();
});

describe('saveInlineEdit re-entrancy', () => {
    it('asks once when the blur handler re-enters while the dialog is open', async () => {
        const mod = makeModule();
        const cell = makeCell();

        // Hold the dialog open, the way a real one waits on the user.
        let answer;
        confirmDialog.mockReturnValue(new Promise(resolve => { answer = resolve; }));

        const first = mod.saveInlineEdit(cell, 'amount', '77', { type: 'debit' });
        await Promise.resolve();

        // What the editor's blur handler does 100ms later.
        const second = mod.saveInlineEdit(cell, 'amount', '77', { type: 'debit' });
        await second;

        expect(confirmDialog).toHaveBeenCalledTimes(1);

        answer(true);
        await first;
        expect(confirmDialog).toHaveBeenCalledTimes(1);
    });

    it('lets a later, genuine edit through once the first has finished', async () => {
        const mod = makeModule();
        const cell = makeCell();

        await mod.saveInlineEdit(cell, 'amount', '77', { type: 'debit' });
        expect(confirmDialog).toHaveBeenCalledTimes(1);

        await mod.saveInlineEdit(cell, 'amount', '88', { type: 'debit' });
        expect(confirmDialog).toHaveBeenCalledTimes(2);
    });

    it('does not act on a cell detached by the re-render', async () => {
        const mod = makeModule();
        const cell = makeCell();

        // The state after the dialog closed and the table re-rendered: the old
        // cell is gone from the document but still carries .editing.
        cell.remove();
        expect(cell.isConnected).toBe(false);
        expect(cell.classList.contains('editing')).toBe(true);

        // setupEditorEvents' blur path, run against that stale cell.
        const input = document.createElement('input');
        input.value = '77';
        mod.setupEditorEvents(input, cell, 'amount');
        input.dispatchEvent(new window.Event('blur'));
        await new Promise(resolve => setTimeout(resolve, 200));

        expect(confirmDialog).not.toHaveBeenCalled();
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('still saves through the blur path while the cell is on the page', async () => {
        const mod = makeModule();
        const cell = makeCell();

        const input = document.createElement('input');
        input.value = '77';
        cell.appendChild(input);
        mod.setupEditorEvents(input, cell, 'amount');
        input.dispatchEvent(new window.Event('blur'));
        await new Promise(resolve => setTimeout(resolve, 200));

        expect(confirmDialog).toHaveBeenCalledTimes(1);
    });
});
