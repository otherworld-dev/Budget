/**
 * Names in translated text that the page escapes itself.
 *
 * Passing `{ escape: false }` to t() left the name raw, but t() still ran
 * the whole text through its HTML sanitiser, which encodes "&" (and keeps
 * tags). The caller then escaped that again, so "Tom & Jerry" showed as
 * "Tom &amp; Jerry". Where the caller escapes the result itself, t() no
 * longer sanitises it too. This uses the real @nextcloud/l10n.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

import { renderTransactionRow } from '../../src/modules/transactions/transactionRow.js';
import SavingsModule from '../../src/modules/savings/SavingsModule.js';
import ImportModule from '../../src/modules/import/ImportModule.js';

const NAME = 'Tom & Jerry <b>';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('text escaped by the page itself', () => {
    it('labels a transaction\'s checkbox with its description as typed', () => {
        const html = renderTransactionRow({ id: 1, accountId: 1, date: '2026-10-01', description: NAME, amount: 5, type: 'debit' }, {
            variant: 'ledger', accounts: [], categories: [], currency: 'GBP',
            formatCurrency: (v) => String(v), formatDate: (d) => d,
        });
        document.body.innerHTML = `<table><tbody>${html}</tbody></table>`;

        expect(document.querySelector('.transaction-checkbox').getAttribute('aria-label')).toBe(`Select ${NAME}`);
    });

    it('names a shared goal\'s owner as written', () => {
        document.body.innerHTML = '<div id="goals-list"></div><div id="empty-goals"></div>';
        const mod = Object.create(SavingsModule.prototype);
        mod.app = { settings: {}, accounts: [] };
        mod.formatCurrency = (v) => String(v);
        mod.renderGoals([{ id: 1, name: 'Holiday', targetAmount: 100, currentAmount: 10, _shared: true, userId: NAME }]);

        const badge = document.querySelector('.goal-shared-badge');
        expect(badge).not.toBeNull();
        expect(badge.getAttribute('title')).toBe(`Shared by ${NAME}`);
    });

    it('names the account in an import direction warning as written', () => {
        document.body.innerHTML = '<div id="import-direction-warnings"></div>';
        const mod = Object.create(ImportModule.prototype);
        const container = document.getElementById('import-direction-warnings');
        mod.renderDirectionWarnings([{ type: 'credit', matching: 3, total: 4, existingOppositePercent: 90, accountName: NAME }]);

        expect(container.textContent).toContain(`already in ${NAME} is an expense`);
    });
});
